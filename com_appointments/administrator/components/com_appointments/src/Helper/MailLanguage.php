<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

final class MailLanguage
{
    public const SUPPORTED = ['de-DE', 'en-GB', 'fr-FR'];

    public static function resolve(string $accountLanguage, string $siteLanguage): string
    {
        foreach ([$accountLanguage, $siteLanguage, 'en-GB'] as $tag) {
            if (in_array($tag, self::SUPPORTED, true)) {
                return $tag;
            }
        }

        throw new \LogicException('No supported mail language.');
    }

    public static function forAddress(DatabaseInterface $db, string $address, string $siteLanguage): string
    {
        return self::forAddresses($db, [$address], $siteLanguage)[$address];
    }

    /**
     * @param list<string> $addresses
     * @return array<string, string> Languages keyed by the original recipient addresses.
     */
    public static function forAddresses(DatabaseInterface $db, array $addresses, string $siteLanguage): array
    {
        $requested = [];

        foreach ($addresses as $address) {
            $requested[$address] = PrivacyAddress::normalise($address);
        }

        if ($requested === []) {
            return [];
        }

        $normalised = array_values(array_unique($requested));
        $column = $db->quoteName('email');
        $postgres = strtolower((string) $db->getServerType()) === 'postgresql';
        $value = 'TRIM(' . $column . ')';
        $folded = $postgres
            ? 'LOWER(' . $value . ' COLLATE "C")'
            : 'LOWER(CONVERT(' . $value . ' USING utf8mb4) COLLATE utf8mb4_bin)';
        $controls = $postgres
            ? $column . " ~ '[[:cntrl:]]'"
            : $column . " REGEXP '[[:cntrl:]]'";
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $placeholders = [];

        foreach (array_keys($normalised) as $index) {
            $placeholder = ':email' . $index;
            $placeholders[] = $placeholder;
            $query->bind($placeholder, $normalised[$index], ParameterType::STRING);
        }

        // SQL casing can lag behind PHP; non-ASCII and trimmed controls remain candidates.
        $query->select($db->quoteName(['email', 'params']))
            ->from($db->quoteName('#__users'))
            ->where('(' . $folded . ' IN (' . implode(', ', $placeholders) . ') OR OCTET_LENGTH(' . $column
                . ') <> CHAR_LENGTH(' . $column . ') OR ' . $controls . ')');
        $wanted = array_fill_keys($normalised, true);
        $counts = [];
        $languages = [];

        foreach ($db->setQuery($query)->loadObjectList() as $user) {
            if (trim((string) $user->email) === '') {
                continue;
            }

            $key = PrivacyAddress::normalise((string) $user->email);

            if (!isset($wanted[$key])) {
                continue;
            }

            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $params = $counts[$key] === 1 ? json_decode((string) $user->params, true) : [];
            $languages[$key] = (string) ($params['language'] ?? '');
        }

        $result = [];

        foreach ($requested as $address => $key) {
            $result[$address] = self::resolve($languages[$key] ?? '', $siteLanguage);
        }

        return $result;
    }
}
