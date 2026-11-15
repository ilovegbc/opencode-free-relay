<?php

declare(strict_types=1);

namespace Relay\Services;

use Relay\Support\Hook;
use Relay\Support\Ipv6;
use Relay\Support\Settings;

/**
 * HTTP client for OpenCode's inference gateway.
 *
 * Hard guarantees:
 *  - CURLOPT_IPRESOLVE is forced to CURL_IPRESOLVE_V6. There is no code path
 *    that attempts IPv4 after an IPv6 failure.
 *  - The local endpoint is bound to the allocated IPv6 address.
 *  - Redirects are disabled (a redirect cannot silently move us to IPv4).
 *  - Environment HTTP proxies are disabled.
 */
final class OpenCodeClient
{
    public function __construct(private Settings $settings)
    {
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $model
     * @param callable(int,array<string,string>):void|null $onStart
     * @param callable(string):bool|null $onChunk
     */
    public function send(
        array $body,
        array $model,
        string $sourceIp,
        RelayIdentity $identity,
        bool $stream,
        ?callable $onStart = null,
        ?callable $onChunk = null,
    ): UpstreamResult {
        if (!Ipv6::isValidAddress($sourceIp)) {
            return new UpstreamResult(0, [], '', 0, 0, 0, $sourceIp, 'ipv6_bind_failed', 'Source address is not a valid IPv6 address.');
        }

        $url = $this->upstreamUrl($model);
        $headers = $this->headers($identity, $stream);
        $payload = $this->body($body, $model, $identity, $stream);
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            return new UpstreamResult(0, [], '', 0, 0, 0, $sourceIp, 'invalid_request', 'Could not encode JSON request.');
        }

        $started = false;
        $status = 0;
        $responseHeaders = [];
        $responseBody = '';
        $bytes = 0;
        $firstByteAt = null;
        $start = microtime(true);
        $bindError = null;

        $ch = curl_init($url);
        if ($ch === false) {
            return new UpstreamResult(0, [], '', 0, 0, 0, $sourceIp, 'curl_init_failed', 'Could not initialise cURL.');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            if ($value === '' || preg_match('/[\r\n]/', $value)) {
                continue;
            }
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encoded,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V6,
            CURLOPT_CONNECTTIMEOUT => max(1, $this->settings->int('upstream.connect_timeout', 15)),
            CURLOPT_TIMEOUT => max(30, $this->settings->int('upstream.timeout', 900)),
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => $this->settings->bool('upstream.verify_tls', true),
            CURLOPT_SSL_VERIFYHOST => $this->settings->bool('upstream.verify_tls', true) ? 2 : 0,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$status, &$responseHeaders): int {
                $length = strlen($line);
                if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $m)) {
                    $status = (int) $m[1];
                    $responseHeaders = [];
                    return $length;
                }
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $name = strtolower(trim(substr($line, 0, $pos)));
                    $value = trim(substr($line, $pos + 1));
                    if ($name !== '') {
                        $responseHeaders[$name] = $value;
                    }
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (
                $stream,
                $onStart,
                $onChunk,
                &$started,
                &$status,
                &$responseHeaders,
                &$responseBody,
                &$bytes,
                &$firstByteAt
            ): int {
                $length = strlen($chunk);
                $bytes += $length;
                $firstByteAt ??= microtime(true);

                if ($status >= 200 && $status < 300 && $stream) {
                    if (!$started) {
                        $started = true;
                        if ($onStart !== null) {
                            $onStart($status, $responseHeaders);
                        }
                    }
                    if ($onChunk !== null && $onChunk($chunk) === false) {
                        return 0;
                    }
                    return $length;
                }

                // Error bodies and non-streaming responses are buffered.
                $responseBody .= $chunk;
                return $length;
            },
        ]);

        // Bind the local endpoint. CURLOPT_INTERFACE accepts an IP address and
        // applies bind(2) before connect(2). The opensocket method is offered
        // for kernels where the interface route needs an explicit socket.
        $bindMethod = $this->settings->string('ipv6.bind_method', 'interface');
        if ($bindMethod === 'opensocket' && defined('CURLOPT_OPENSOCKETFUNCTION') && function_exists('socket_create')) {
            curl_setopt($ch, CURLOPT_OPENSOCKETFUNCTION, static function ($address, $socket) use ($sourceIp, &$bindError) {
                $sock = @socket_create(AF_INET6, SOCK_STREAM, SOL_TCP);
                if ($sock === false) {
                    $bindError = 'socket_create failed';
                    return false;
                }
                @socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
                if (!@socket_bind($sock, $sourceIp, 0)) {
                    $bindError = socket_strerror(socket_last_error($sock));
                    @socket_close($sock);
                    return false;
                }
                return $sock;
            });
        } else {
            curl_setopt($ch, CURLOPT_INTERFACE, $sourceIp);
        }

        $payloadHook = Hook::filter('relay.upstream.request', [
            'url' => $url,
            'headers' => $headers,
            'body' => $payload,
            'source_ip' => $sourceIp,
            'stream' => $stream,
        ]);
        if (isset($payloadHook['url']) && is_string($payloadHook['url']) && $payloadHook['url'] !== $url) {
            // Plugins may route to an allowed mirror. Still force IPv6 below.
            $url = $payloadHook['url'];
            curl_setopt($ch, CURLOPT_URL, $url);
        }
        if (isset($payloadHook['body']) && is_array($payloadHook['body'])) {
            $encoded = (string) json_encode($payloadHook['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
        }

        $ok = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $info = curl_getinfo($ch);
        if (isset($info['http_code']) && (int) $info['http_code'] > 0) {
            $status = (int) $info['http_code'];
        }
        curl_close($ch);

        $latencyMs = (int) round((microtime(true) - $start) * 1000);
        $firstByteMs = $firstByteAt !== null ? (int) round(($firstByteAt - $start) * 1000) : 0;

        if ($ok === false || $curlErrno !== 0 || $bindError !== null) {
            [$code, $message, $retryable] = $this->mapCurlError($curlErrno, $curlError, $bindError);
            return new UpstreamResult(0, $responseHeaders, $responseBody, $latencyMs, $firstByteMs, $bytes, $sourceIp, $code, $message, $curlErrno);
        }
        if ($status === 0) {
            return new UpstreamResult(0, $responseHeaders, $responseBody, $latencyMs, $firstByteMs, $bytes, $sourceIp, 'upstream_no_status', 'Upstream returned no HTTP status.');
        }

        return new UpstreamResult($status, $responseHeaders, $responseBody, $latencyMs, $firstByteMs, $bytes, $sourceIp);
    }

    /** @param array<string,mixed> $model */
    private function upstreamUrl(array $model): string
    {
        $kind = (string) ($model['upstream_kind'] ?? 'chat');
        $base = trim((string) ($model['upstream_base_url'] ?? ''));
        if ($base === '') {
            $base = rtrim($this->settings->string('upstream.base_url', 'https://opencode.ai/inference'), '/');
            $base .= match ($kind) {
                'messages' => '/anthropic/v1',
                'responses' => '/openai/v1',
                default => '/openai/v1',
            };
        }
        $path = match ($kind) {
            'messages' => '/messages',
            'responses' => '/responses',
            default => '/chat/completions',
        };
        $url = rtrim($base, '/') . $path;

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            throw new \RuntimeException('Invalid upstream URL for model ' . ($model['id'] ?? 'unknown'));
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if ($scheme !== 'https' && !($scheme === 'http' && $this->settings->bool('upstream.allow_http', false))) {
            throw new \RuntimeException('Only HTTPS upstreams are allowed unless upstream.allow_http is enabled.');
        }
        $host = strtolower((string) $parts['host']);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        $allowed = array_map('trim', explode(',', $this->settings->string('upstream.allowed_hosts', 'opencode.ai')));
        $allowed = array_filter($allowed);
        if ($allowed !== []) {
            $matched = false;
            foreach ($allowed as $entry) {
                $entry = strtolower($entry);
                if ($host === $entry || str_ends_with($host, '.' . $entry)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                throw new \RuntimeException('Upstream host is not in OPENCODE_ALLOWED_HOSTS: ' . $host);
            }
        }
        return $url;
    }

    /** @return array<string,string> */
    private function headers(RelayIdentity $identity, bool $stream): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => $stream ? 'text/event-stream' : 'application/json',
            'Accept-Encoding' => 'identity',
        ];
        // Free models require no API key. The official client may send the
        // literal "public" internally, but the endpoint accepts no auth at
        // all. Only add the header when an operator explicitly configured one.
        $upstreamKey = trim($this->settings->string('upstream.key', ''));
        if ($upstreamKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $upstreamKey;
        }
        if ($this->settings->bool('upstream.mirror_client_headers', true)) {
            $headers += $identity->headers();
        } else {
            $headers['User-Agent'] = $identity->userAgent;
        }
        $headers = Hook::filter('relay.upstream.headers', $headers, $identity);
        return $headers;
    }

    /** @param array<string,mixed> $body @param array<string,mixed> $model */
    private function body(array $body, array $model, RelayIdentity $identity, bool $stream): array
    {
        $body['model'] = (string) $model['id'];
        $kind = (string) ($model['upstream_kind'] ?? 'chat');

        if ($kind === 'chat') {
            if ($stream && $this->settings->bool('upstream.stream_include_usage', true)) {
                $options = $body['stream_options'] ?? [];
                if (!is_array($options)) {
                    $options = [];
                }
                $options['include_usage'] = true;
                $body['stream_options'] = $options;
            }
            if ($this->settings->bool('upstream.prompt_cache_key', true) && !isset($body['prompt_cache_key'])) {
                $body['prompt_cache_key'] = $identity->promptCacheKey;
            }
        }

        // A transparent relay must never touch the system prompt or messages.
        return Hook::filter('relay.upstream.body', $body, $model);
    }

    /** @return array{0:string,1:string,2:bool} */
    private function mapCurlError(int $errno, string $error, ?string $bindError): array
    {
        if ($bindError !== null && $bindError !== '') {
            return ['ipv6_bind_failed', 'Could not bind the allocated IPv6 source address: ' . $bindError, true];
        }
        return match ($errno) {
            CURLE_COULDNT_RESOLVE_HOST => ['ipv6_dns_failed', 'Upstream IPv6 DNS resolution failed. IPv4 fallback is disabled.', true],
            CURLE_COULDNT_CONNECT => ['ipv6_connect_failed', 'Could not connect to the upstream over IPv6: ' . $error, true],
            CURLE_OPERATION_TIMEDOUT => ['upstream_timeout', 'Upstream timed out: ' . $error, true],
            CURLE_INTERFACE_FAILED => ['ipv6_bind_failed', 'Could not bind the allocated IPv6 source address: ' . $error, true],
            CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CERTPROBLEM, CURLE_SSL_CIPHER, CURLE_PEER_FAILED_VERIFICATION => ['tls_error', 'TLS error: ' . $error, true],
            default => ['upstream_transport_error', 'Upstream transport error (' . $errno . '): ' . $error, true],
        };
    }
}
