<?php

declare(strict_types=1);

namespace Relay\Services;

use Relay\Http\HttpException;
use Relay\Http\Request;
use Relay\Http\Response;
use Relay\Repositories\LogRepository;
use Relay\Services\IpAllocator;
use Relay\Services\Ipv6UnavailableException;
use Relay\Support\Logger;
use Relay\Support\Settings;

/**
 * Orchestrates one client request:
 *   auth/quota -> fresh IPv6 lease -> OpenCode request -> stream/JSON relay
 *   -> usage accounting -> audit log.
 *
 * A retry always acquires a NEW address; addresses are never reused for the
 * retry of the same request.
 */
final class RelayService
{
    public function __construct(
        private Settings $settings,
        private IdentityResolver $identityResolver,
        private IpAllocator $allocator,
        private OpenCodeClient $client,
        private QuotaService $quota,
        private BillingService $billing,
        private LogRepository $logs,
    ) {
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $key
     * @param array<string,mixed> $model
     * @param array<string,mixed> $body
     */
    public function handle(Request $request, array $user, array $key, array $model, string $kind, string $endpoint, array $body): never
    {
        $requestId = uuid_v4();
        $isStream = (bool) ($body['stream'] ?? false);
        $identity = $this->identityResolver->resolve($request, $body, $key, $user);
        $startedAt = microtime(true);
        $requestBytes = strlen($request->rawBody());

        $reservation = $this->quota->begin($user, $key, $requestId, $endpoint);

        $maxAttempts = max(1, $this->settings->int('upstream.max_ip_retries', 2) + 1);
        $excludeAddresses = [];
        $excludePoolIds = [];
        $attempts = [];
        $lastLease = null;
        $lastResult = null;
        $responseStarted = false;
        $parser = new UsageParser();
        $parser->estimateInput($body);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $lease = $this->allocator->allocate($requestId, $excludeAddresses, $excludePoolIds);
            } catch (Ipv6UnavailableException $e) {
                $this->quota->fail($reservation);
                $this->logFailure($request, $user, $key, $model, $endpoint, $isStream, $requestId, 503, 'ipv6_unavailable', $e->getMessage(), $attempts, $lastLease, $startedAt, $requestBytes, $identity);
                throw new HttpException(503, $e->getMessage(), $e->reason);
            }

            $lastLease = $lease;
            $excludeAddresses[] = $lease->address;

            $onStart = null;
            $onChunk = null;
            if ($isStream) {
                $onStart = function (int $status, array $headers) use (&$responseStarted): void {
                    if ($responseStarted) {
                        return;
                    }
                    $responseStarted = true;
                    $this->beginClientStream($status, $headers);
                };
                $onChunk = function (string $chunk) use ($parser, &$responseStarted): bool {
                    $parser->feed($chunk);
                    echo $chunk;
                    flush();
                    return !connection_aborted();
                };
            }

            try {
                $result = $this->client->send($body, $model, $lease->address, $identity, $isStream, $onStart, $onChunk);
            } catch (\Throwable $e) {
                Logger::error('Upstream client exception', [
                    'request_id' => $requestId,
                    'model' => $model['id'] ?? null,
                    'message' => $e->getMessage(),
                    'ip' => $lease->address,
                ]);
                $result = new UpstreamResult(0, [], '', 0, 0, 0, $lease->address, 'upstream_client_error', $e->getMessage());
            }

            $lastResult = $result;
            $attemptRecord = [
                'request_id' => $requestId,
                'user_id' => $user['id'],
                'attempt' => $attempt,
                'pool_id' => $lease->poolId,
                'ip_address' => $lease->address,
                'status_code' => $result->status,
                'error_code' => $result->errorCode,
                'error_message' => $result->errorMessage,
                'latency_ms' => $result->latencyMs,
            ];
            $attempts[] = $attemptRecord;
            try {
                $this->logs->insertAttempt($attemptRecord);
            } catch (\Throwable $e) {
                Logger::warning('Could not persist upstream attempt', ['message' => $e->getMessage()]);
            }

            if ($result->ok()) {
                $this->allocator->markSuccess($lease);

                if (!$isStream) {
                    $usage = $parser->finish($result->body);
                    $credits = $this->billing->calculate($model, $usage, $user);
                    $this->quota->commit($reservation, $usage, $credits);
                    $this->logSuccess($request, $user, $key, $model, $endpoint, false, $requestId, $result, $usage, $credits, count($attempts), $lease, $startedAt, $requestBytes, $identity);
                    $status = $result->status >= 200 && $result->status < 300 ? 200 : $result->status;
                    Response::raw($result->body, $status, [
                        'Content-Type' => $result->headers['content-type'] ?? 'application/json; charset=utf-8',
                        'Cache-Control' => 'no-cache',
                    ]);
                }

                if (!$responseStarted) {
                    $responseStarted = true;
                    $this->beginClientStream($result->status, $result->headers);
                    if ($result->body !== '') {
                        $parser->feed($result->body);
                        echo $result->body;
                        flush();
                    }
                }
                $usage = $parser->finish();
                $credits = $this->billing->calculate($model, $usage, $user);
                try {
                    $this->quota->commit($reservation, $usage, $credits);
                } catch (\Throwable $e) {
                    // Headers are already sent. Do not corrupt the SSE body
                    // with an error; the reservation expires automatically.
                    Logger::error('Could not commit streaming quota', ['message' => $e->getMessage(), 'request_id' => $requestId]);
                }
                $this->logSuccess($request, $user, $key, $model, $endpoint, true, $requestId, $result, $usage, $credits, count($attempts), $lease, $startedAt, $requestBytes, $identity);
                exit;
            }

            $transportFailure = $result->errorCode !== null;
            if ($transportFailure) {
                $this->allocator->markBindFailure($lease, (string) $result->errorMessage);
                if ($result->errorCode === 'ipv6_bind_failed') {
                    $excludePoolIds[] = $lease->poolId;
                }
            }

            if ($responseStarted) {
                // The client already received part of an SSE response. We
                // cannot retry transparently; emit an SSE error and stop.
                $this->quota->fail($reservation);
                $this->logFailure($request, $user, $key, $model, $endpoint, true, $requestId, 502, $result->errorCode ?? 'upstream_stream_failed', $result->errorMessage ?? 'Upstream stream failed.', $attempts, $lease, $startedAt, $requestBytes, $identity);
                $error = ['error' => ['message' => 'Upstream stream failed. No IPv4 fallback was attempted.', 'type' => 'upstream_error', 'code' => $result->errorCode ?? 'upstream_stream_failed']];
                echo 'data: ' . json_encode($error, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
                flush();
                exit;
            }

            if ($result->retryable() && $attempt < $maxAttempts) {
                Logger::warning('Retrying with a fresh IPv6 address', [
                    'request_id' => $requestId,
                    'attempt' => $attempt,
                    'status' => $result->status,
                    'error' => $result->errorCode,
                    'ip' => $lease->address,
                ]);
                continue;
            }

            $this->quota->fail($reservation);
            [$clientStatus, $code, $message] = $this->mapUpstreamFailure($result);
            $this->logFailure($request, $user, $key, $model, $endpoint, $isStream, $requestId, $clientStatus, $code, $message, $attempts, $lease, $startedAt, $requestBytes, $identity);
            throw new UpstreamException($message, $result->status, $code, $result->retryable(), $result->body, $attempts);
        }

        $this->quota->fail($reservation);
        $this->logFailure($request, $user, $key, $model, $endpoint, $isStream, $requestId, 502, 'upstream_error', 'All IPv6 attempts failed.', $attempts, $lastLease, $startedAt, $requestBytes, $identity);
        throw new UpstreamException('All IPv6 attempts failed. No IPv4 fallback was attempted.', $lastResult?->status ?? 0, 'ipv6_all_attempts_failed', true, $lastResult?->body ?? '', $attempts);
    }

    /** @param array<string,string> $headers */
    private function beginClientStream(int $status, array $headers): void
    {
        $contentType = $headers['content-type'] ?? 'text/event-stream; charset=utf-8';
        if (!str_contains(strtolower($contentType), 'text/event-stream')) {
            $contentType = 'text/event-stream; charset=utf-8';
        }
        Response::beginStream($status >= 200 && $status < 300 ? 200 : $status, [
            'Content-Type' => $contentType,
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $key
     * @param array<string,mixed> $model
     * @param list<array<string,mixed>> $attempts
     */
    private function logFailure(
        Request $request,
        array $user,
        array $key,
        array $model,
        string $endpoint,
        bool $isStream,
        string $requestId,
        int $status,
        string $code,
        string $message,
        array $attempts,
        ?IpLease $lease,
        float $startedAt,
        int $requestBytes,
        RelayIdentity $identity,
    ): void {
        try {
            $this->logs->insertRequest([
                'request_id' => $requestId,
                'user_id' => $user['id'],
                'api_key_id' => $key['id'],
                'model_id' => $model['id'] ?? null,
                'endpoint' => $endpoint,
                'is_stream' => $isStream,
                'status_code' => $status,
                'upstream_status' => $attempts !== [] ? (int) end($attempts)['status_code'] : 0,
                'error_code' => $code,
                'error_message' => mb_substr($message, 0, 1000),
                'ip_pool_id' => $lease?->poolId,
                'ip_address' => $lease?->address,
                'attempt_count' => max(1, count($attempts)),
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'first_byte_ms' => 0,
                'client_ip' => filter_var($request->ip(), FILTER_VALIDATE_IP) ? $request->ip() : null,
                'user_agent' => $request->userAgent(),
                'prompt_cache_key' => $identity->promptCacheKey,
                'request_bytes' => $requestBytes,
                'response_bytes' => 0,
            ]);
        } catch (\Throwable $e) {
            Logger::error('Could not persist failure log', ['message' => $e->getMessage(), 'request_id' => $requestId]);
        }
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $key
     * @param array<string,mixed> $model
     * @param array<string,mixed> $usage
     */
    private function logSuccess(
        Request $request,
        array $user,
        array $key,
        array $model,
        string $endpoint,
        bool $isStream,
        string $requestId,
        UpstreamResult $result,
        array $usage,
        float $credits,
        int $attemptCount,
        IpLease $lease,
        float $startedAt,
        int $requestBytes,
        RelayIdentity $identity,
    ): void {
        try {
            $this->logs->insertRequest([
                'request_id' => $requestId,
                'user_id' => $user['id'],
                'api_key_id' => $key['id'],
                'model_id' => $model['id'] ?? null,
                'endpoint' => $endpoint,
                'is_stream' => $isStream,
                'status_code' => 200,
                'upstream_status' => $result->status,
                'error_code' => null,
                'error_message' => null,
                'ip_pool_id' => $lease->poolId,
                'ip_address' => $lease->address,
                'attempt_count' => max(1, $attemptCount),
                'input_tokens' => $usage['input_tokens'] ?? 0,
                'output_tokens' => $usage['output_tokens'] ?? 0,
                'total_tokens' => $usage['total_tokens'] ?? 0,
                'cached_tokens' => $usage['cached_tokens'] ?? 0,
                'credits' => $credits,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'first_byte_ms' => $result->firstByteMs,
                'client_ip' => filter_var($request->ip(), FILTER_VALIDATE_IP) ? $request->ip() : null,
                'user_agent' => $request->userAgent(),
                'prompt_cache_key' => $identity->promptCacheKey,
                'request_bytes' => $requestBytes,
                'response_bytes' => $result->bytes,
            ]);
        } catch (\Throwable $e) {
            Logger::error('Could not persist request log', ['message' => $e->getMessage(), 'request_id' => $requestId]);
        }
    }

    /** @return array{0:int,1:string,2:string} */
    private function mapUpstreamFailure(UpstreamResult $result): array
    {
        $message = $result->errorMessage ?? '';
        $code = $result->errorCode ?? 'upstream_error';
        $decoded = json_decode($result->body, true);
        if (is_array($decoded)) {
            $error = $decoded['error'] ?? $decoded;
            if (is_array($error)) {
                $message = (string) ($error['message'] ?? $message);
                $type = (string) ($error['type'] ?? '');
                if ($type !== '') {
                    $code = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $type) ?: $type);
                }
            } elseif (is_string($error)) {
                $message = $error;
            }
        }
        if ($message === '') {
            $message = 'Upstream returned HTTP ' . $result->status . '.';
        }
        if (str_contains(strtolower($message), 'free usage limit')) {
            $code = 'free_usage_limit';
        }
        if (str_starts_with($code, 'ipv6_')) {
            return [503, $code, $message];
        }
        if ($result->status === 429) {
            return [429, $code, $message];
        }
        if ($result->status === 401 || $result->status === 403) {
            return [502, 'upstream_auth_error', $message];
        }
        if ($result->status === 400 || $result->status === 404 || $result->status === 422) {
            return [$result->status, $code, $message];
        }
        return [502, $code, $message];
    }
}
