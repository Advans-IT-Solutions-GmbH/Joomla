<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\HostNameStoreInterface;
use Advans\Component\DmarcReports\Administrator\Helper\ReverseDnsResolverInterface;
use Advans\Component\DmarcReports\Administrator\Helper\SourceHostNames;
use PHPUnit\Framework\TestCase;

/**
 * Sender host names: only forward-confirmed names are shown, lookups are cached
 * and time-limited, and a failing resolver never breaks anything.
 *
 * @since  1.4.0
 */
class SourceHostNamesTest extends TestCase
{
    private function store(): HostNameStoreInterface
    {
        return new class implements HostNameStoreInterface {
            /** @var array<string, array{host: string, expires: int}> */
            public array $entries = [];

            public function get(string $ip): ?array
            {
                return $this->entries[$ip] ?? null;
            }

            public function set(string $ip, string $host, int $expires): void
            {
                $this->entries[$ip] = ['host' => $host, 'expires' => $expires];
            }
        };
    }

    /**
     * @param   array<string, string[]|null>  $ptr
     * @param   array<string, string[]|null>  $forward
     */
    private function resolver(array $ptr, array $forward, ?\Closure $onLookup = null): ReverseDnsResolverInterface
    {
        return new class ($ptr, $forward, $onLookup) implements ReverseDnsResolverInterface {
            public int $ptrCalls = 0;

            public function __construct(private array $ptr, private array $forward, private ?\Closure $onLookup)
            {
            }

            public function ptr(string $ip): ?array
            {
                $this->ptrCalls++;

                if ($this->onLookup !== null) {
                    ($this->onLookup)();
                }

                return \array_key_exists($ip, $this->ptr) ? $this->ptr[$ip] : [];
            }

            public function addresses(string $host): ?array
            {
                return \array_key_exists($host, $this->forward) ? $this->forward[$host] : [];
            }
        };
    }

    public function testForwardConfirmedNameIsShown(): void
    {
        $names = new SourceHostNames(
            $this->resolver(['192.0.2.61' => ['Gateway.Example.NET.']], ['gateway.example.net' => ['192.0.2.61']]),
            $this->store()
        );

        $this->assertSame(['192.0.2.61' => 'gateway.example.net'], $names->names(['192.0.2.61']));
    }

    public function testNameThatDoesNotResolveBackIsNotShown(): void
    {
        // Whoever controls an address block can set any PTR name, for example the
        // name of a well-known provider; without the forward match it is not shown.
        $names = new SourceHostNames(
            $this->resolver(['198.51.100.7' => ['gateway.example.net']], ['gateway.example.net' => ['192.0.2.61']]),
            $this->store()
        );

        $this->assertSame(['198.51.100.7' => ''], $names->names(['198.51.100.7']));
    }

    public function testIpv6IsComparedInPackedForm(): void
    {
        $names = new SourceHostNames(
            $this->resolver(['2001:db8::1' => ['mx.example.net']], ['mx.example.net' => ['2001:0DB8:0:0:0:0:0:1']]),
            $this->store()
        );

        $this->assertSame('mx.example.net', $names->lookup('2001:db8::1'));
    }

    public function testInvalidPtrNamesAreIgnored(): void
    {
        $names = new SourceHostNames(
            $this->resolver(
                ['192.0.2.9' => ['<script>.example.net', 'ok.example.net']],
                ['<script>.example.net' => ['192.0.2.9'], 'ok.example.net' => ['192.0.2.9']]
            ),
            $this->store()
        );

        $this->assertSame('ok.example.net', $names->lookup('192.0.2.9'));
        $this->assertFalse(SourceHostNames::isHostName('localhost'));
        $this->assertFalse(SourceHostNames::isHostName('-bad.example.net'));
        $this->assertTrue(SourceHostNames::isHostName('mail-1.example.net'));
    }

    public function testResultsAreCachedIncludingMisses(): void
    {
        $store    = $this->store();
        $resolver = $this->resolver(['192.0.2.61' => ['gateway.example.net']], ['gateway.example.net' => ['192.0.2.61']]);
        $names    = new SourceHostNames($resolver, $store);

        $names->names(['192.0.2.61', '198.51.100.7']);
        $names->names(['192.0.2.61', '198.51.100.7']);

        $this->assertSame(2, $resolver->ptrCalls, 'Each address is looked up once, a miss is cached too');
        $this->assertSame('gateway.example.net', $store->entries['192.0.2.61']['host']);
        $this->assertSame('', $store->entries['198.51.100.7']['host']);
        $this->assertGreaterThan($store->entries['198.51.100.7']['expires'], $store->entries['192.0.2.61']['expires']);
    }

    public function testExpiredEntriesAreLookedUpAgain(): void
    {
        $store = $this->store();
        $store->set('192.0.2.61', 'old.example.net', 100);
        $resolver = $this->resolver(['192.0.2.61' => ['new.example.net']], ['new.example.net' => ['192.0.2.61']]);
        $names    = new SourceHostNames($resolver, $store, 2.0, 10, static fn (): float => 1000.0);

        $this->assertSame(['192.0.2.61' => 'new.example.net'], $names->names(['192.0.2.61']));
    }

    public function testLookupCountIsLimited(): void
    {
        $resolver = $this->resolver([], []);
        $names    = new SourceHostNames($resolver, $this->store(), 60.0, 2);

        $result = $names->names(['192.0.2.1', '192.0.2.2', '192.0.2.3', '192.0.2.4']);

        $this->assertSame(2, $resolver->ptrCalls);
        $this->assertSame(['192.0.2.1' => '', '192.0.2.2' => '', '192.0.2.3' => '', '192.0.2.4' => ''], $result);
    }

    public function testTimeBudgetStopsFurtherLookups(): void
    {
        // Every lookup takes 1.5 s on the fake clock; with a 2 s budget the third
        // address is no longer looked up (it is resolved on a later page view).
        $now      = 0.0;
        $clock    = static function () use (&$now): float {
            return $now;
        };
        $resolver = $this->resolver([], [], static function () use (&$now): void {
            $now += 1.5;
        });
        $store = $this->store();
        $names = new SourceHostNames($resolver, $store, 2.0, 10, \Closure::fromCallable($clock));

        $names->names(['192.0.2.1', '192.0.2.2', '192.0.2.3']);

        $this->assertSame(2, $resolver->ptrCalls);
        $this->assertArrayNotHasKey('192.0.2.3', $store->entries, 'An address not looked up is not cached as a miss');
    }

    public function testWithoutResolverOnlyTheCacheIsRead(): void
    {
        $store = $this->store();
        $store->set('192.0.2.61', 'gateway.example.net', PHP_INT_MAX);
        $names = new SourceHostNames(null, $store);

        $this->assertSame(
            ['192.0.2.61' => 'gateway.example.net', '198.51.100.7' => ''],
            $names->names(['192.0.2.61', '198.51.100.7'])
        );
        $this->assertArrayNotHasKey('198.51.100.7', $store->entries);
    }

    public function testFailingResolverYieldsNoName(): void
    {
        $resolver = new class implements ReverseDnsResolverInterface {
            public function ptr(string $ip): ?array
            {
                throw new \RuntimeException('resolver down');
            }

            public function addresses(string $host): ?array
            {
                return null;
            }
        };

        $this->assertSame('', (new SourceHostNames($resolver, $this->store()))->lookup('192.0.2.61'));
    }
}
