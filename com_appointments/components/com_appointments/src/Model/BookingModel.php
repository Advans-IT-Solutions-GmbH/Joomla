<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\Model;

use Advans\Component\Appointments\Administrator\Helper\BookingInput;
use Advans\Component\Appointments\Administrator\Service\BookingStore;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

defined('_JEXEC') or die;

class BookingModel extends BaseDatabaseModel
{
    public function create(EventtypeModel $events, int $eventId, array $input, \DateTimeImmutable $start, \DateTimeImmutable $now): array
    {
        $app = Factory::getApplication();
        $event = $events->getItem($eventId);
        $invitee = (new BookingInput())->validate($input, $events->getFields($eventId), $app->getLanguage()->getTag());

        return (new BookingStore($this->getDatabase()))->create(
            (array) $event, $invitee, $start, $now, $app->getIdentity()->getAuthorisedViewLevels(), $app->getLanguage()->getTag()
        );
    }

    public function getItem(): object
    {
        $id = (int) Factory::getApplication()->getUserState('com_appointments.confirmation.id', 0);

        if ($id < 1) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['b.id', 'b.start_utc', 'b.end_utc', 'b.state', 'b.invitee_timezone', 'b.conference_url']))
            ->select($db->quoteName('e.title'))->select($db->quoteName('e.location_text'))
            ->from($db->quoteName('#__appointments_bookings', 'b'))
            ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e'), $db->quoteName('e.id') . ' = ' . $db->quoteName('b.eventtype_id'))
            ->where($db->quoteName('b.id') . ' = ' . $id)
            ->where($db->quoteName('b.anonymised_utc') . ' IS NULL');
        $item = $db->setQuery($query)->loadObject();

        if (!$item) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        return $item;
    }
}
