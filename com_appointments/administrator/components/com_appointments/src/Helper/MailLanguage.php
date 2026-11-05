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
        $normalised = PrivacyAddress::normalise($address);
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['email', 'params']))
            ->from($db->quoteName('#__users'))
            ->where('LOWER(' . $db->quoteName('email') . ') = :email')
            ->bind(':email', $normalised, ParameterType::STRING);
        $matches = array_values(array_filter($db->setQuery($query)->loadObjectList(),
            static fn (object $user): bool => PrivacyAddress::matches((string) $user->email, $address)));
        $params = count($matches) === 1 ? json_decode((string) $matches[0]->params, true) : [];

        return self::resolve((string) ($params['language'] ?? ''), $siteLanguage);
    }
}
