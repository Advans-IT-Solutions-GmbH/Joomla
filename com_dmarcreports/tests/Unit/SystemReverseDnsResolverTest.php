<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\SystemReverseDnsResolver;
use PHPUnit\Framework\TestCase;

final class SystemReverseDnsResolverTest extends TestCase
{
    public function testTimedQueryRestoresSignalStateAndPreviousAlarm(): void
    {
        if (
            !\function_exists('pcntl_async_signals')
            || !\function_exists('pcntl_alarm')
            || !\function_exists('pcntl_getsignal')
            || !\defined('SIGALRM')
        ) {
            $this->markTestSkipped('POSIX pcntl signal controls are unavailable');
        }

        \pcntl_async_signals(false);
        $handler = static function (): void {
        };
        \pcntl_signal(\SIGALRM, $handler);
        \pcntl_alarm(5);

        try {
            $resolver = new SystemReverseDnsResolver();
            $method = new \ReflectionMethod($resolver, 'query');
            $method->setAccessible(true);
            $method->invoke($resolver, static fn (): array => []);

            $this->assertFalse(\pcntl_async_signals());
            $this->assertSame($handler, \pcntl_getsignal(\SIGALRM));
            $this->assertGreaterThan(0, \pcntl_alarm(0), 'The previous alarm must be restored');
        } finally {
            \pcntl_alarm(0);
            \pcntl_signal(\SIGALRM, \SIG_DFL);
            \pcntl_async_signals(false);
        }
    }

    public function testTimedQueryPreservesPreviouslyEnabledAsyncDispatch(): void
    {
        if (
            !\function_exists('pcntl_async_signals')
            || !\function_exists('pcntl_alarm')
            || !\defined('SIGALRM')
        ) {
            $this->markTestSkipped('POSIX pcntl signal controls are unavailable');
        }

        \pcntl_async_signals(true);

        try {
            $resolver = new SystemReverseDnsResolver();
            $method = new \ReflectionMethod($resolver, 'query');
            $method->setAccessible(true);
            $method->invoke($resolver, static fn (): array => []);

            $this->assertTrue(\pcntl_async_signals());
        } finally {
            \pcntl_alarm(0);
            \pcntl_signal(\SIGALRM, \SIG_DFL);
            \pcntl_async_signals(false);
        }
    }
}
