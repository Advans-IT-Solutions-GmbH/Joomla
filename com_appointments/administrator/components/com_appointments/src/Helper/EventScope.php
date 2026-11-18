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

    /**
     * Whether $user may run $action on at least one of the event types $ids (each against its own
     * asset, as the controllers check it per booking). False for no event type.
     *
     * @param   object     $user  A Joomla user (anything with authorise($action, $asset)).
     * @param   list<int>  $ids
     */
    public static function allowsAny(object $user, string $action, array $ids): bool
    {
        foreach ($ids as $id) {
            if ($user->authorise($action, 'com_appointments.eventtype.' . (int) $id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Campaign aggregates outlive individual bookings and deleted event types. They stay visible
     * for a component administrator, or for an editor with at least one authorized event type,
     * even when the current filtered booking result is empty.
     *
     * @param list<array<string,mixed>> $authorizedEvents
     */
    public static function canExportCampaigns(object $user, array $authorizedEvents): bool
    {
        return $user->authorise('core.manage', 'com_appointments')
            && ($user->authorise('core.admin', 'com_appointments') || $authorizedEvents !== []);
    }
}
