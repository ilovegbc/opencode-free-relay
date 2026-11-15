<?php

declare(strict_types=1);

namespace Relay\Services;

use Relay\Repositories\IpPoolRepository;
use Relay\Support\Ipv6;

final class IpProbe
{
    public function __construct(private IpPoolRepository $pools)
    {
    }

    /**
     * Test that a source address can be bound and that the target has an IPv6
     * route. This is a TCP connect only; it never sends an API prompt.
     *
     * @return array{ok:bool,message:string,address:string,target:string,latency_ms:int}
     */
    public function probe(string $url, array $pool, ?string $address = null): array
    {
        $parsed = Ipv6::parseCidr((string) $pool['cidr']);
        $address ??= Ipv6::randomAddress($parsed['network'], $parsed['prefix']);

        $target = parse_url($url);
        if ($target === false || empty($target['host'])) {
            return ['ok' => false, 'message' => 'Probe URL has no host.', 'address' => $address, 'target' => '', 'latency_ms' => 0];
        }
        $host = $target['host'];
        $port = (int) ($target['port'] ?? 443);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $remote = $host;
        } else {
            $records = @dns_get_record($host, DNS_AAAA);
            if (!is_array($records) || $records === []) {
                return ['ok' => false, 'message' => 'Target has no AAAA record; IPv4 fallback is disabled.', 'address' => $address, 'target' => $host, 'latency_ms' => 0];
            }
            $remote = (string) ($records[0]['ipv6'] ?? '');
        }
        if (!Ipv6::isValidAddress($remote)) {
            return ['ok' => false, 'message' => 'No valid IPv6 target address.', 'address' => $address, 'target' => $host, 'latency_ms' => 0];
        }

        $context = stream_context_create([
            'socket' => [
                'bindto' => '[' . $address . ']:0',
            ],
        ]);
        $start = microtime(true);
        $socket = @stream_socket_client(
            'tcp://[' . $remote . ']:' . $port,
            $errno,
            $errstr,
            8,
            STREAM_CLIENT_CONNECT,
            $context
        );
        $latency = (int) round((microtime(true) - $start) * 1000);
        if ($socket === false) {
            $message = 'Source bind/connect failed: ' . ($errstr !== '' ? $errstr : 'unknown error') . ' (' . $errno . ')';
            $this->pools->markStatus((int) $pool['id'], 'degraded', $message);
            return ['ok' => false, 'message' => $message, 'address' => $address, 'target' => $remote, 'latency_ms' => $latency];
        }
        @fclose($socket);
        $this->pools->markStatus((int) $pool['id'], 'healthy', null);
        return ['ok' => true, 'message' => 'TCP connect succeeded through ' . $address . '.', 'address' => $address, 'target' => $remote, 'latency_ms' => $latency];
    }
}
