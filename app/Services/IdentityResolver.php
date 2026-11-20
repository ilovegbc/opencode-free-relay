<?php

declare(strict_types=1);

namespace Relay\Services;

use Relay\Http\Request;
use Relay\Support\Crypto;
use Relay\Support\Settings;

/**
 * 复刻官方 OpenCode 客户端在模型请求上携带的身份头。
 *
 * 2026-11 起上游对免费通道做了请求形状准入：`x-opencode-session` 必须是
 * 官方 `Identifier.descending()` 布局（`ses_` + 12 位 hex + 14 位 base62，
 * 共 30 字符，hex 低 3 位恒为 `ffe`，解码时间需在 ±30 天内），否则同样返回
 * `429 FreeUsageLimitError`。这里负责生成/规范化该 id。
 *
 * 官方 1.19.6 CLI 实际发送的头只有：
 *   x-opencode-client, x-opencode-project, x-opencode-request,
 *   x-opencode-session, x-opencode-session-id, User-Agent
 */
final class IdentityResolver
{
    private const BASE62 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

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
        if ($explicitSession !== null && trim($explicitSession) !== '') {
            $sessionId = $this->normalizeSession(trim($explicitSession));
        }

        $project = $request->header('x-opencode-project');
        if ($project === null || !preg_match('/^[A-Za-z0-9_.:-]{4,128}$/', trim($project))) {
            $project = $this->settings->string('upstream.project_id', 'global');
        }

        $promptCacheKey = preg_match('/^ses_(.{26})$/', $affinity, $m) === 1
            ? $m[1]
            : substr(Crypto::hmac($affinity, 'cache'), 0, 64);

        $clientName = $this->settings->string('upstream.client_name', 'cli');
        $userAgent = $this->settings->string('upstream.user_agent', '');
        if (trim($userAgent) === '') {
            $userAgent = 'opencode/1.19.6 ai-sdk/provider-utils/4.0.23 runtime/bun/1.4.4';
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
            $request->header('x-opencode-session'),
            $request->header('x-opencode-session-id'),
            $request->header('x-session-affinity'),
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

    /** 把任意调用方 session 值收敛成官方 30 字符 canonical id。 */
    private function normalizeSession(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^ses_[0-9a-f]{12}[0-9A-Za-z]{14}$/', $value) === 1) {
            return $value;
        }
        if (preg_match('/^ses_([0-9a-f]{12})[0-9A-Za-z]{14}$/i', $value) === 1) {
            return $value;
        }
        return $this->canonicalSession($value);
    }

    /**
     * 生成 canonical session：48 位载荷 = ~((ms << 12) | 1)，低 3 位因此恒为 ffe。
     * 时间位按 UTC 日粒度从种子推导（offset 0..6 天），因此同一天同一会话稳定、
     * 始终落在上游 ±30 天窗口内。
     */
    private function canonicalSession(string $seed): string
    {
        $hash = substr(Crypto::hmac($seed, 'session'), 0, 16);
        $dayMs = 86400000;
        $dayOffset = (int) (hexdec(substr($hash, 0, 4)) % 7);
        $todayMs = (int) (floor(microtime(true) * 1000 / $dayMs)) * $dayMs;
        $ms = $todayMs - $dayOffset * $dayMs;
        $payload = (($ms << 12) | 1) & 0xFFFFFFFFFFFF;
        $value = (~$payload) & 0xFFFFFFFFFFFF;
        $hex = str_pad(dechex($value), 12, '0', STR_PAD_LEFT);

        $slug = '';
        for ($i = 0; $i < 14; $i++) {
            $byte = (int) hexdec(substr($hash, ($i * 2) % 16, 2));
            $slug .= self::BASE62[($byte + $i) % 62];
        }
        return 'ses_' . substr($hex, -12) . $slug;
    }

    /** @param array<string,mixed> $body */
    private function deriveSession(Request $request, array $body, array $apiKey, array $user): string
    {
        $messages = $body['messages'] ?? $body['input'] ?? [];
        $firstUser = '';
        if (is_array($messages)) {
            foreach ($messages as $message) {
                if (is_array($message) && ($message['role'] ?? '') === 'user') {
                    $firstUser = is_string($message['content'] ?? null) ? $message['content'] : '';
                    break;
                }
            }
        }
        $seed = (string) json_encode([
            'key' => (string) ($apiKey['id'] ?? ''),
            'user' => (string) ($user['id'] ?? ''),
            'model' => (string) ($body['model'] ?? ''),
            'first_user' => $firstUser,
            'ua' => $request->userAgent(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($seed === '') {
            $seed = bin2hex(random_bytes(16));
        }
        return $this->canonicalSession($seed);
    }
}
