<?php

declare(strict_types=1);

namespace Relay\Support;

use InvalidArgumentException;

/**
 * IPv6-only address arithmetic. Prefix lengths are never hard-coded: every
 * routine reads the length from the parsed CIDR, so /32, /48, /56, /64, /96,
 * /112 or even /128 custom ranges all work the same way.
 */
final class Ipv6
{
    /** @return array{network:string,prefix:int,cidr:string} */
    public static function parseCidr(string $cidr): array
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            throw new InvalidArgumentException('CIDR is empty.');
        }
        $parts = explode('/', $cidr, 2);
        $address = $parts[0];
        $prefix = isset($parts[1]) ? (int) $parts[1] : 128;

        $binary = @inet_pton($address);
        if ($binary === false || strlen($binary) !== 16) {
            throw new InvalidArgumentException('Only IPv6 CIDRs are supported: ' . $cidr);
        }
        if ($prefix < 1 || $prefix > 128) {
            throw new InvalidArgumentException('IPv6 prefix length must be between 1 and 128.');
        }
        if (str_contains(strtolower($address), '::ffff:')) {
            throw new InvalidArgumentException('IPv4-mapped IPv6 addresses are not allowed.');
        }

        $network = self::mask($binary, $prefix);
        return [
            'network' => $network,
            'prefix' => $prefix,
            'cidr' => self::format($network) . '/' . $prefix,
        ];
    }

    public static function isValidAddress(string $address): bool
    {
        if (str_contains($address, '%')) {
            return false;
        }
        $binary = @inet_pton($address);
        return $binary !== false && strlen($binary) === 16;
    }

    public static function isLoopback(string $address): bool
    {
        $binary = @inet_pton($address);
        return $binary !== false && $binary === inet_pton('::1');
    }

    public static function mask(string $binary, int $prefix): string
    {
        if (strlen($binary) !== 16) {
            throw new InvalidArgumentException('IPv6 address must be 16 bytes.');
        }
        $out = $binary;
        $full = intdiv($prefix, 8);
        $rem = $prefix % 8;
        for ($i = 0; $i < 16; $i++) {
            if ($i < $full) {
                continue;
            }
            if ($i === $full && $rem > 0) {
                $mask = (0xff << (8 - $rem)) & 0xff;
                $out[$i] = chr(ord($binary[$i]) & $mask);
                continue;
            }
            $out[$i] = "\0";
        }
        return $out;
    }

    /**
     * @param array<int,string> $exclude
     */
    public static function randomAddress(string $network, int $prefix, array $exclude = []): string
    {
        if (strlen($network) !== 16) {
            throw new InvalidArgumentException('Network must be 16 bytes.');
        }
        if ($prefix === 128) {
            $only = self::format($network);
            if (in_array($only, $exclude, true)) {
                throw new \RuntimeException('The /128 pool has no remaining address.');
            }
            return $only;
        }

        $exclude = array_fill_keys($exclude, true);
        $networkAddress = self::format(self::mask($network, $prefix));
        $allOnes = self::format(self::mask(str_repeat("\xff", 16), $prefix));

        for ($attempt = 0; $attempt < 64; $attempt++) {
            $random = random_bytes(16);
            $bytes = '';
            $full = intdiv($prefix, 8);
            $rem = $prefix % 8;
            for ($i = 0; $i < 16; $i++) {
                if ($i < $full) {
                    $bytes .= $network[$i];
                } elseif ($i === $full && $rem > 0) {
                    $mask = (0xff << (8 - $rem)) & 0xff;
                    $bytes .= chr((ord($network[$i]) & $mask) | (ord($random[$i]) & (0xff >> $rem)));
                } else {
                    $bytes .= $random[$i];
                }
            }
            $address = self::format($bytes);
            // The subnet-router anycast address and the all-ones address are
            // not useful as source addresses. For small pools this also makes
            // exhaustion explicit rather than silently using a special value.
            if ($address === $networkAddress || $address === $allOnes) {
                continue;
            }
            if (!isset($exclude[$address])) {
                return $address;
            }
        }
        throw new \RuntimeException('Unable to generate a fresh IPv6 address in the selected pool.');
    }

    public static function format(string $binary): string
    {
        $formatted = @inet_ntop($binary);
        if ($formatted === false) {
            throw new InvalidArgumentException('Cannot format IPv6 address.');
        }
        return strtolower($formatted);
    }

    public static function binary(string $address): string
    {
        $binary = @inet_pton($address);
        if ($binary === false || strlen($binary) !== 16) {
            throw new InvalidArgumentException('Invalid IPv6 address: ' . $address);
        }
        return $binary;
    }

    public static function hostBits(int $prefix): int
    {
        return 128 - $prefix;
    }

    /**
     * Return the address-space size as a decimal string. bcmath is optional;
     * for very large prefixes we only need a comparable value, so floats are
     * avoided.
     */
    public static function capacity(int $prefix): string
    {
        $bits = 128 - $prefix;
        if ($bits <= 0) {
            return '1';
        }
        if (function_exists('bcpow')) {
            return bcpow('2', (string) $bits);
        }
        if ($bits <= 62) {
            return (string) (2 ** $bits);
        }
        return '18446744073709551616'; // 2^64, lower bound for huge pools
    }

    public static function overlaps(string $cidrA, string $cidrB): bool
    {
        $a = self::parseCidr($cidrA);
        $b = self::parseCidr($cidrB);
        $shortest = min($a['prefix'], $b['prefix']);
        return self::mask($a['network'], $shortest) === self::mask($b['network'], $shortest);
    }
}
