<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\Model;

use Advans\Component\Appointments\Administrator\Service\AvailabilityService;
use Advans\Component\Appointments\Administrator\Service\RateLimiter;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

class EventtypeModel extends BaseDatabaseModel
{
    public function getItem(?int $id = null): object
    {
        $app = Factory::getApplication();
        $id ??= $app->getInput()->getInt('id', 0);
        $levels = $app->getIdentity()->getAuthorisedViewLevels();

        if ($id < 1 || $levels === []) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName([
            'e.id', 'e.title', 'e.alias', 'e.description', 'e.duration_minutes', 'e.slot_step_minutes',
            'e.buffer_before_minutes', 'e.buffer_after_minutes', 'e.min_notice_minutes', 'e.horizon_days',
            'e.horizon_start_utc', 'e.horizon_end_utc', 'e.max_per_day', 'e.max_per_week', 'e.schedule_id',
            'e.host_user_id', 'e.location_type', 'e.location_text', 'e.requires_approval', 'e.colour',
        ]))->select($db->quoteName('s.timezone'))
            ->from($db->quoteName('#__appointments_eventtypes', 'e'))
            ->join('INNER', $db->quoteName('#__appointments_schedules', 's'), $db->quoteName('s.id') . ' = ' . $db->quoteName('e.schedule_id'))
            ->where($db->quoteName('e.id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER)
            ->where($db->quoteName('e.published') . ' = 1')
            ->where($db->quoteName('s.published') . ' = 1')
            ->whereIn($db->quoteName('e.access'), $levels)
            ->whereIn($db->quoteName('e.language'), ['*', $app->getLanguage()->getTag()], ParameterType::STRING);
        $item = $db->setQuery($query)->loadObject();

        if (!$item) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        return $item;
    }

    public function getSlots(\DateTimeImmutable $now, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $app = Factory::getApplication();
        $keys = new KeyProvider((string) $app->get('secret'));
        if (!(new RateLimiter($this->getDatabase(), $keys))->allow('slots', $app->getInput()->server->getString('REMOTE_ADDR'), $now, 60, 60)) {
            throw new \RuntimeException(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_RATE'), 429);
        }

        return (new AvailabilityService($this->getDatabase()))->slots((array) $this->getItem(), $now, $from, $to);
    }

    public function getFields(int $eventId): array
    {
        $this->getItem($eventId);
        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['id', 'type', 'label', 'description', 'options', 'required']))
            ->from($db->quoteName('#__appointments_fields'))
            ->where($db->quoteName('eventtype_id') . ' = :id')->bind(':id', $eventId, ParameterType::INTEGER)
            ->where($db->quoteName('published') . ' = 1')
            ->order($db->quoteName('ordering'))->order($db->quoteName('id'));

        return $db->setQuery($query)->loadAssocList();
    }
}
