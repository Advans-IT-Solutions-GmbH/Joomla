<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

final class EventScope
{
    public static function events(DatabaseInterface $db, User $user): array
    {
        if (!$user->authorise('core.manage', 'com_appointments')) {
            return [];
        }
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['id', 'title']))->from($db->quoteName('#__appointments_eventtypes'))
            ->order($db->quoteName('title'));

        return array_values(array_filter($db->setQuery($query)->loadAssocList(), static fn ($row) =>
            $user->authorise('core.edit', 'com_appointments.eventtype.' . (int) $row['id'])));
    }
}
