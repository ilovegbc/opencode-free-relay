<?php

declare(strict_types=1);

namespace Relay\Services;

use Relay\Http\Request;
use Relay\Support\Crypto;
use Relay\Support\Settings;

/**
 * Reproduces the identity headers the official OpenCode client attaches to a
 * model request. It never alters system prompts or messages.
 *
 * Source: OpenCode 2.3.0 `SessionModelRequest.prepare`:
 *   headers: x-opencode-session-id, x-opencode-parent-session-id,
 *            x-session-affinity, X-Session-Id, x-parent-session-id,
 *            User-Agent: opencode/{channel}/{version}/{client},
 *            x-opencode-project, x-opencode-session, x-opencode-client
 *   promptCacheKey: affinity without the "ses_" prefix when it is 64 hex.
 */
final class IdentityResolver
{
    public function __construct(private Settings $settings)
    {
    }

    /** @param array<string,mixed> $body */
    public function resolve(Request $request, array $body, array $apiKey, array $user): RelayIdentity
    {
        $affinityRaw = $this->pickSessionCandidate($request, $body);
        $affinity = $affinityRaw !== null
            ? $this->normalizeSession($affinityRaw)
            : $this->deriveSession($request, $body, $apiKey, $user);

        $parent = $request->header('x-opencode-parent-session-id')
            ?? $request->header('x-parent-session-id');
        $parent = $parent !== null && trim($parent) !== '' ? $this->normalizeSession(trim($parent)) : null;

        $sessionId = $affinity;
        $explicitSession = $request->header('x-opencode-session-id');
        if ($explicitSession !== null) {
            $explicitSession = trim($explicitSession);
            if (preg_match('/^ses_[0-9a-f]{64}$/i', $explicitSession)) {
                $sessionId = strtolower($explicitSession);
            } elseif (preg_match('/^ses_[0-9A-Za-z]{26}$/', $explicitSession)) {
                $sessionId = $explicitSession;
            }
        }

        $project = $request->header('x-opencode-project');
        if ($project === null || !preg_match('/^[A-Za-z0-9_.:-]{4,128}$/', trim($project))) {
            $project = 'prj_' . substr(Crypto::hmac((string) ($apiKey['id'] ?? 'anonymous'), 'project'), 0, 32);
        }

        $bodyKey = $body['prompt_cache_key'] ?? null;
        if (is_string($bodyKey) && preg_match('/^[A-Za-z0-9_-]{8,128}$/', $bodyKey)) {
            $promptCacheKey = $bodyKey;
        } elseif (preg_match('/^ses_([0-9a-f]{64})$/i', $affinity, $m)) {
            $promptCacheKey = strtolower($m[1]);
        } elseif (preg_match('/^ses_[0-9A-Za-z]{26}$/', $affinity)) {
            // Official clients with the short session ID format pass the full
            // `ses_...` value as prompt_cache_key (the 64-hex strip only
            // applies to the longer format).
            $promptCacheKey = $affinity;
        } else {
            $promptCacheKey = substr(Crypto::hmac($affinity, 'cache'), 0, 64);
        }

        $clientName = $this->settings->string('upstream.client_name', 'cli');
        $channel = $this->settings->string('upstream.client_channel', 'latest');
        $version = $this->settings->string('upstream.client_version', '2.3.0');
        $userAgent = $this->settings->string('upstream.user_agent', '');
        if ($userAgent === '') {
            $userAgent = 'opencode/' . $channel . '/' . $version . '/' . $clientName;
        }

        return new RelayIdentity(
            sessionId: $sessionId,
            affinity: $affinity,
            promptCacheKey: $promptCacheKey,
            projectId: $project,
            parentSessionId: $parent,
            clientName: $clientName,
            userAgent: $userAgent,
        );
    }

    private function pickSessionCandidate(Request $request, array $body): ?string
    {
        $headers = [
            $request->header('x-session-affinity'),
            $request->header('x-opencode-session'),
            $request->header('x-opencode-session-id'),
            $request->header('x-session-id'),
            $request->header('x-relay-session'),
        ];
        foreach ($headers as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        $key = $body['prompt_cache_key'] ?? null;
        if (is_string($key) && trim($key) !== '') {
            return trim($key);
        }
        $metadata = $body['metadata'] ?? null;
        if (is_array($metadata)) {
            foreach (['session_id', 'sessionId', 'conversation_id'] as $field) {
                if (isset($metadata[$field]) && is_string($metadata[$field]) && trim($metadata[$field]) !== '') {
                    return trim($metadata[$field]);
                }
            }
        }
        return null;
    }

    private function normalizeSession(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^ses_[0-9a-f]{64}$/i', $value)) {
            return strtolower($value);
        }
        if (preg_match('/^ses_[0-9A-Za-z]{26}$/', $value)) {
            return $value;
        }
        if (preg_match('/^[0-9a-f]{64}$/i', $value)) {
            return 'ses_' . strtolower($value);
        }
        if (preg_match('/^[A-Za-z0-9_.:-]{1,160}$/', $value)) {
            return 'ses_' . Crypto::hmac($value, 'session');
        }
        return 'ses_' . Crypto::hmac($value, 'session');
    }

    /** @param array<string,mixed> $body */
    private function deriveSession(Request $request, array $body, array $apiKey, array $user): string
    {
        $system = $body['system'] ?? $body['instructions'] ?? '';
        $messages = $body['messages'] ?? $body['input'] ?? [];
        $firstUser = '';
        if (is_array($messages)) {
            foreach ($messages as $message) {
                if (is_array($message) && ($message['role'] ?? '') === 'user') {
                    $firstUser = $message['content'] ?? '';
                    break;
                }
            }
        }
        $seed = json_encode([
            'key' => (string) ($apiKey['id'] ?? ''),
            'user' => (string) ($user['id'] ?? ''),
            'model' => (string) ($body['model'] ?? ''),
            'system' => $system,
            'first_user' => $firstUser,
            'ua' => $request->userAgent(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($seed) || $seed === '') {
            $seed = bin2hex(random_bytes(16));
        }
        return 'ses_' . Crypto::hmac($seed, 'session');
    }
}
