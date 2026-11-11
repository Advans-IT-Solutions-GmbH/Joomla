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

/**
 * A user's own switch for the booking mails of one internet calendar («My calendars»). On by
 * default; the calendars a user switched off are kept as a list of calendar ids in that user's
 * Joomla account parameters, so the setting disappears with the account and needs no table.
 *
 * Only the booking mails of CalendarNotice are affected. Mails that concern the account or its
 * security (new device, activation, grants, suspended access, deleted calendar, locked account)
 * cannot be switched off. The caller decides whose switch changes: the site controller passes
 * the signed-in user only, never an id from the request.
 */
final class NotificationSwitch
{
    /** The key in `#__users.params`. */
    public const PARAM = 'appointments_booking_mail_off';

    /**
     * The calendars switched off in an account's parameters. Pure.
     *
     * @return  int[]
     */
    public static function mutedFrom(?string $params): array
    {
        $decoded = json_decode((string) $params, true);
        $list = \is_array($decoded) && \is_array($decoded[self::PARAM] ?? null) ? $decoded[self::PARAM] : [];
        $ids = array_values(array_unique(array_filter(array_map('intval', array_filter($list, 'is_scalar')), static fn (int $id): bool => $id > 0)));
        sort($ids);

        return $ids;
    }

    /**
     * The parameters with the switch of one calendar set. Every other parameter stays as it is. Pure.
     *
     * @throws  \UnexpectedValueException  When the stored parameters are not a JSON object.
     */
    public static function withSwitch(?string $params, int $calendarId, bool $on): string
    {
        $params = trim((string) $params);
        // Decoded as objects, so every other parameter keeps its JSON type ({} stays {}, an object
        // with numeric keys stays an object); only the value of PARAM is replaced.
        $decoded = $params === '' ? new \stdClass() : json_decode($params);

        if (!$decoded instanceof \stdClass) {
            throw new \UnexpectedValueException('The account parameters are not readable.');
        }

        $muted = array_values(array_diff(self::mutedFrom($params), [$calendarId]));

        if (!$on && $calendarId > 0) {
            $muted[] = $calendarId;
            sort($muted);
        }

        if ($muted === []) {
            unset($decoded->{self::PARAM});
        } else {
            $decoded->{self::PARAM} = $muted;
        }

        return (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return  int[]  The calendars whose booking mails the user switched off.
     */
    public static function muted(DatabaseInterface $db, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName('params'))->from($db->quoteName('#__users'))
            ->where($db->quoteName('id') . ' = :id')->bind(':id', $userId, ParameterType::INTEGER);

        return self::mutedFrom((string) $db->setQuery($query)->loadResult());
    }

    /**
     * The calendars that are the target calendar of an event type the user hosts. A host who
     * switches their mails off learns of new bookings only through the calendar, and is told so.
     *
     * @return  int[]
     */
    public static function hostedCalendars(DatabaseInterface $db, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select('DISTINCT ' . $db->quoteName('calendar_id'))->from($db->quoteName('#__appointments_eventtypes'))
            ->where($db->quoteName('host_user_id') . ' = :id')->where($db->quoteName('calendar_id') . ' > 0')
            ->bind(':id', $userId, ParameterType::INTEGER);

        return array_values(array_map('intval', $db->setQuery($query)->loadColumn()));
    }

    /**
     * Switch the booking mails of one calendar on or off for one account. The row is locked while
     * it is rewritten, so two switches at once never lose each other, and no Joomla user event
     * runs (nothing else of the account changes).
     */
    public static function set(DatabaseInterface $db, int $userId, int $calendarId, bool $on): void
    {
        if ($userId <= 0 || $calendarId <= 0) {
            throw new \InvalidArgumentException('Unknown account or calendar.');
        }

        $db->transactionStart(true);

        try {
            $current = $db->setQuery('SELECT ' . $db->quoteName('params') . ' FROM ' . $db->quoteName('#__users')
                . ' WHERE ' . $db->quoteName('id') . ' = ' . $userId . ' FOR UPDATE')->loadResult();

            if ($current === null) {
                throw new \InvalidArgumentException('Unknown account.');
            }

            $params = self::withSwitch((string) $current, $calendarId, $on);
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->update($db->quoteName('#__users'))->set($db->quoteName('params') . ' = :params')
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':params', $params)->bind(':id', $userId, ParameterType::INTEGER);
            $db->setQuery($query)->execute();
            $db->transactionCommit(true);
        } catch (\Throwable $e) {
            $db->transactionRollback(true);

            throw $e;
        }
    }
}
