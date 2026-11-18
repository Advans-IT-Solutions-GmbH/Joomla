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
 * Forward-confirmed reverse DNS names of sending sources, for information only.
 *
 * A PTR record is set by whoever controls the address block, so a name is only
 * shown when it resolves back to the same address (forward-confirmed). The name
 * never influences the classification of a source, which rests on the DKIM and
 * SPF results alone ({@see SourceClassifier}); without DNS the classification
 * still works and the name is simply left out.
 *
 * Lookups are cached (a confirmed name for seven days, no name for one day) and
 * best-effort time-limited: one call starts no new lookup once its budget of
 * lookups or seconds is spent, and checks the shared deadline before PTR and
 * forward DNS calls. The system resolver adds a one-second alarm where POSIX
 * pcntl controls are available; platforms without pcntl cannot guarantee a hard
 * in-flight timeout.
 * Ingestion never calls this class.
 *
 * @since  1.4.0
 */
final class SourceHostNames
{
    /** Cache lifetime of a forward-confirmed name, in seconds. */
    public const TTL_CONFIRMED = 7 * 86400;

    /** Cache lifetime of "no confirmed name" (none, mismatch or lookup failure), in seconds. */
    public const TTL_UNCONFIRMED = 86400;

    /** At most this many PTR names of one address are forward-checked. */
    private const MAX_NAMES = 3;

    private ?ReverseDnsResolverInterface $resolver;

    private HostNameStoreInterface $store;

    private float $budgetSeconds;

    private int $maxLookups;

    /** @var \Closure(): float */
    private \Closure $clock;

    /**
     * @param   ReverseDnsResolverInterface|null  $resolver       Null reads the cache only (no lookups).
     * @param   HostNameStoreInterface            $store          The name cache.
     * @param   float                             $budgetSeconds  Wall-clock budget for new lookups per call.
     * @param   int                               $maxLookups     Most new lookups per call.
     * @param   \Closure|null                     $clock          Returns the time in seconds (tests).
     */
    public function __construct(
        ?ReverseDnsResolverInterface $resolver,
        HostNameStoreInterface $store,
        float $budgetSeconds = 2.0,
        int $maxLookups = 10,
        ?\Closure $clock = null
    ) {
        $this->resolver      = $resolver;
        $this->store         = $store;
        $this->budgetSeconds = max(0.0, $budgetSeconds);
        $this->maxLookups    = max(0, $maxLookups);
        $this->clock         = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * The confirmed names of the given addresses: cached names first, then new
     * lookups in the given order while the budget lasts. Addresses without a
     * confirmed name, or not resolved yet, map to ''.
     *
     * @param   string[]  $ips  Addresses, most important first.
     *
     * @return  array<string, string>
     */
    public function names(array $ips): array
    {
        $now     = ($this->clock)();
        $start   = $now;
        $lookups = 0;
        $names   = [];

        foreach ($ips as $ip) {
            $ip = (string) $ip;

            if ($ip === '' || \array_key_exists($ip, $names)) {
                continue;
            }

            $cached = $this->store->get($ip);

            if ($cached !== null && $cached['expires'] > (int) $now) {
                $names[$ip] = self::isHostName($cached['host']) ? $cached['host'] : '';

                continue;
            }

            $names[$ip] = '';

            if (
                $this->resolver === null
                || $lookups >= $this->maxLookups
                || (($this->clock)() - $start) >= $this->budgetSeconds
            ) {
                continue;
            }

            $lookups++;
            $host       = $this->lookupWithin($ip, $start + $this->budgetSeconds);
            $names[$ip] = $host;
            $ttl        = $host !== '' ? self::TTL_CONFIRMED : self::TTL_UNCONFIRMED;
            $this->store->set($ip, $host, (int) ($this->clock)() + $ttl);
        }

        return $names;
    }

    /**
     * The first PTR name of an address that resolves back to that address, or
     * '' when there is none or a lookup failed.
     */
    public function lookup(string $ip): string
    {
        return $this->lookupWithin($ip, ($this->clock)() + $this->budgetSeconds);
    }

    private function lookupWithin(string $ip, float $deadline): string
    {
        $packed = @inet_pton($ip);

        if ($packed === false || $this->resolver === null) {
            return '';
        }

        if (($this->clock)() >= $deadline) {
            return '';
        }

        try {
            $ptr = $this->resolver->ptr($ip);
        } catch (\Throwable $e) {
            return '';
        }

        if (($this->clock)() >= $deadline) {
            return '';
        }

        foreach (\array_slice((array) $ptr, 0, self::MAX_NAMES) as $name) {
            $name = strtolower(rtrim((string) $name, '.'));

            if (!self::isHostName($name)) {
                continue;
            }

            if (($this->clock)() >= $deadline) {
                return '';
            }

            try {
                $addresses = $this->resolver->addresses($name);
            } catch (\Throwable $e) {
                continue;
            }

            foreach ((array) $addresses as $address) {
                if (@inet_pton((string) $address) === $packed) {
                    return $name;
                }
            }
        }

        return '';
    }

    /**
     * Whether a value is a syntactically valid DNS host name: letters, digits and
     * hyphens in labels of 1-63 characters, at least two labels, at most 253
     * characters. Anything else from a PTR answer is never shown.
     */
    public static function isHostName(string $name): bool
    {
        if ($name === '' || \strlen($name) > 253) {
            return false;
        }

        return (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $name);
    }
}
