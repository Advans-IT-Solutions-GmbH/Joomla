<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * The default reverse resolver, backed by PHP's dns_get_record() and the
 * system resolver. Read-only: it never writes to DNS. Each lookup discloses the
 * queried sender address or host name to the configured resolver, which is why
 * the component only performs it with the "dns_diagnostics" option switched on.
 *
 * @since  1.4.0
 */
final class SystemReverseDnsResolver implements ReverseDnsResolverInterface
{
    private const QUERY_TIMEOUT_SECONDS = 1;

    public function ptr(string $ip): ?array
    {
        $name = self::reverseName($ip);

        if ($name === null) {
            return [];
        }

        if (!\function_exists('dns_get_record')) {
            return null;
        }

        $records = $this->query(static fn (): array|false => @dns_get_record($name, DNS_PTR));

        // dns_get_record() returns false for a missing PTR (NXDOMAIN) as well as
        // for a resolver failure; both mean "no confirmed name" here.
        if (!\is_array($records)) {
            return null;
        }

        $names = [];

        foreach ($records as $record) {
            $target = strtolower(rtrim((string) ($record['target'] ?? ''), '.'));

            if ($target !== '' && !\in_array($target, $names, true)) {
                $names[] = $target;
            }
        }

        return $names;
    }

    public function addresses(string $host): ?array
    {
        if (!\function_exists('dns_get_record')) {
            return null;
        }

        $records = $this->query(static fn (): array|false => @dns_get_record($host, DNS_A + DNS_AAAA));

        if (!\is_array($records)) {
            return null;
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = (string) ($record['ip'] ?? $record['ipv6'] ?? '');

            if ($address !== '') {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    private function query(\Closure $lookup): array|false
    {
        if (
            !\function_exists('pcntl_async_signals')
            || !\function_exists('pcntl_alarm')
            || !\function_exists('pcntl_getsignal')
            || !\defined('SIGALRM')
        ) {
            return $lookup();
        }

        $previousAsync = pcntl_async_signals();
        $previousHandler = pcntl_getsignal(SIGALRM);
        $previousAlarm = pcntl_alarm(0);
        $started = microtime(true);

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (): never {
            throw new \RuntimeException('DNS lookup timeout');
        });
        pcntl_alarm(self::QUERY_TIMEOUT_SECONDS);

        try {
            return $lookup();
        } catch (\Throwable $e) {
            return false;
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousHandler);
            pcntl_async_signals($previousAsync);

            if ($previousAlarm > 0) {
                $elapsed = (int) ceil(microtime(true) - $started);
                $remaining = max(1, $previousAlarm - $elapsed);
                pcntl_alarm($remaining);
            }
        }
    }

    /**
     * The in-addr.arpa / ip6.arpa name of an address, or null when it is not a
     * valid IP address.
     */
    public static function reverseName(string $ip): ?string
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        if (\strlen($packed) === 4) {
            return implode('.', array_reverse(array_map('ord', str_split($packed)))) . '.in-addr.arpa';
        }

        return implode('.', str_split(strrev(bin2hex($packed)))) . '.ip6.arpa';
    }
}
