<?php

declare(strict_types=1);

namespace Relay\Tests;

use Relay\Support\Ipv6;

final class Ipv6Test extends TestCase
{
    public function run(): void
    {
        $cases = [
            ['2001:db8::/32', 32],
            ['2001:db8:1234::/48', 48],
            ['2001:db8:1234:5600::/56', 56],
            ['2001:db8:1234:5678::/64', 64],
            ['2001:db8:1234:5678:9abc:def0::/96', 96],
            ['2001:db8:1234:5678:9abc:def0:1234:5678/128', 128],
        ];

        foreach ($cases as [$cidr, $prefix]) {
            $parsed = Ipv6::parseCidr($cidr);
            $this->assertSame($prefix, $parsed['prefix'], 'Prefix length must not be hardcoded: ' . $cidr);
            $this->assertSame(16, strlen($parsed['network']));
            $this->assertSame(128 - $prefix, Ipv6::hostBits($prefix));
            for ($i = 0; $i < 30; $i++) {
                $address = Ipv6::randomAddress($parsed['network'], $parsed['prefix']);
                $this->assertTrue(Ipv6::isValidAddress($address), 'Generated address must be IPv6: ' . $address);
                $binary = Ipv6::binary($address);
                $this->assertSame(
                    Ipv6::format(Ipv6::mask($binary, $parsed['prefix'])),
                    Ipv6::format($parsed['network']),
                    'Address must stay inside the prefix: ' . $cidr . ' -> ' . $address
                );
            }
        }

        $parsed128 = Ipv6::parseCidr('2001:db8::1/128');
        $this->assertSame('2001:db8::1', Ipv6::randomAddress($parsed128['network'], 128));
        $this->assertThrows(\RuntimeException::class, static fn () => Ipv6::randomAddress($parsed128['network'], 128, ['2001:db8::1']));

        $a = Ipv6::parseCidr('2001:db8:1234::/48');
        $b = Ipv6::parseCidr('2001:db8:1234:5678::/64');
        $c = Ipv6::parseCidr('2001:db8:9999::/48');
        $this->assertTrue(Ipv6::overlaps($a['cidr'], $b['cidr']), 'Nested ranges must overlap');
        $this->assertFalse(Ipv6::overlaps($a['cidr'], $c['cidr']), 'Different /48s must not overlap');

        $this->assertSame('4294967296', Ipv6::capacity(96));
        $this->assertSame('18446744073709551616', Ipv6::capacity(64));

        $this->assertThrows(\InvalidArgumentException::class, static fn () => Ipv6::parseCidr('192.0.2.0/24'));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => Ipv6::parseCidr('2001:db8::/129'));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => Ipv6::parseCidr('2001:db8::/0'));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => Ipv6::parseCidr('::ffff:192.0.2.1/128'));
    }
}
