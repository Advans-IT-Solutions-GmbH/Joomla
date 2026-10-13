<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Helper;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * Resolves a policy domain to its monitored-domain row id, creating the row on
 * first sight when the caller is permitted to. DMARC aggregate reports and
 * TLS-RPT reports arrive through the same mailbox and share the one
 * `#__dmarc_domains` list, so both ingestors resolve domains the same way; the
 * logic lives here once instead of being copied into each.
 *
 * @since  1.1.0
 */
final class DomainRegistry
{
    /**
     * Find the configured domain by name, creating a monitored entry on first
     * sight so a shared reporting mailbox populates the domain list by itself.
     * Returns 0 when the domain is empty, or unknown and the caller may not
     * auto-create, so the caller skips the report rather than storing an orphan.
     *
     * @param   bool  $allowAutoCreate  Whether an unknown domain may be
     *                                   registered (requires core.create).
     */
    public static function resolve(DatabaseInterface $db, string $domain, bool $allowAutoCreate): int
    {
        $domain = strtolower(trim($domain));

        if ($domain === '') {
            return 0;
        }

        $query = self::query($db)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__dmarc_domains'))
            ->where($db->quoteName('domain') . ' = :domain')
            ->bind(':domain', $domain);
        $id = (int) $db->setQuery($query)->loadResult();

        if ($id > 0) {
            return $id;
        }

        if (!$allowAutoCreate) {
            return 0;
        }

        $insert = (object) [
            'domain'          => $domain,
            'title'           => $domain,
            'expected_policy' => self::defaultPolicyTarget(),
            'published'       => 1,
            'created'         => Factory::getDate()->toSql(),
        ];

        try {
            $db->insertObject('#__dmarc_domains', $insert, 'id');

            return (int) $insert->id;
        } catch (\RuntimeException $e) {
            // The lookup above and this insert are not atomic: a concurrent
            // import of the same previously unknown domain can create the row in
            // between, tripping the unique domain index. Recover by re-reading
            // the row the other request created; only a genuine failure re-throws.
            $existing = (int) $db->setQuery($query)->loadResult();

            if ($existing > 0) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * The enforcement target assigned to domains discovered automatically from
     * incoming reports, taken from the component configuration (default reject).
     */
    private static function defaultPolicyTarget(): string
    {
        $target = strtolower(
            (string) ComponentHelper::getParams('com_dmarcreports')->get('default_policy_target', 'reject')
        );

        return \in_array($target, ['none', 'quarantine', 'reject'], true) ? $target : 'reject';
    }

    private static function query(DatabaseInterface $db): \Joomla\Database\QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
