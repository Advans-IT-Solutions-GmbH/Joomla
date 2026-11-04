<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Advans\Component\Appointments\Administrator\Helper\EventScope;
use Advans\Component\Appointments\Administrator\Helper\BookingData;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Service\BookingRecords;

defined('_JEXEC') or die;

class BookingsModel extends ListModel
{
    /** @var array<string, int[]|null> Ids found in memory for a search or a name order, per state. */
    private array $matched = [];

    /** While the CSV export builds its query. */
    private bool $exporting = false;

    public function __construct($config = [], $factory = null)
    {
        $config['filter_fields'] ??= ['b.id', 'b.start_utc', 'b.state', 'e.title', 'b.invitee_last_name', 'b.invitee_first_name'];
        parent::__construct($config, $factory);
    }

    public function authorisedEvents(): array
    {
        return EventScope::events($this->getDatabase(), Factory::getApplication()->getIdentity());
    }

    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['b.id', 'b.uid', 'b.created_utc', 'b.eventtype_id', 'b.start_utc', 'b.end_utc', 'b.state',
            'b.invitee_name', 'b.invitee_first_name', 'b.invitee_last_name', 'b.invitee_email', 'b.invitee_phone', 'b.guest_emails', 'b.custom_fields', 'b.cancel_reason', 'b.conference_url',
            'b.consent_utc', 'b.consent_version',
            'b.sequence', 'b.conference_state', 'b.conference_error', 'b.anonymised_utc']))
            ->select($db->quoteName('e.title', 'eventtype_title'))
            ->from($db->quoteName('#__appointments_bookings', 'b'))
            ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e') . ' ON ' . $db->quoteName('e.id') . ' = ' . $db->quoteName('b.eventtype_id'));
        $ids = array_map(static fn ($row) => (int) $row['id'], $this->authorisedEvents());

        if ($ids === [] || !Factory::getApplication()->getIdentity()->authorise('core.manage', 'com_appointments')) {
            $query->where('1 = 0');
        } else {
            $query->where($db->quoteName('b.eventtype_id') . ' IN (' . implode(',', $ids) . ')');
        }
        $event = (string) $this->getState('filter.eventtype_id', '');
        if ($event !== '') {
            $query->where($db->quoteName('b.eventtype_id') . ' = ' . (int) $event);
        }
        $state = (string) $this->getState('filter.state', '');
        if ($state !== '') {
            $query->where($db->quoteName('b.state') . ' = ' . (int) $state);
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $comparison) {
            $day = (string) $this->getState('filter.' . $key, '');
            if ($day !== '') {
                $query->where($db->quoteName('b.start_utc') . ' ' . $comparison . ' '
                    . $db->quote($day . ($key === 'from' ? ' 00:00:00' : ' 23:59:59')));
            }
        }
        $order = (string) $this->getState('list.ordering', 'b.start_utc');
        $order = in_array($order, $this->filter_fields, true) ? $order : 'b.start_utc';
        $direction = strtoupper((string) $this->getState('list.direction')) === 'ASC' ? 'ASC' : 'DESC';
        // The CSV export pages by id: the name order of the page, which covers the newest bookings only, is left out.
        $byName = !$this->exporting && in_array($order, ['b.invitee_last_name', 'b.invitee_first_name'], true);
        $query->order($db->quoteName($byName ? 'b.start_utc' : $order) . ' ' . $direction);
        $search = (string) $this->getState('filter.search', '');

        if ($search === '' && !$byName) {
            return $query;
        }

        // Names and addresses are encrypted at rest (team-calendars.md, 5.10): the search (the full
        // name finds "first last", first and last name each one of them, the address a part of it)
        // and the sorting by name (last name, then first name) are done in memory on the candidates
        // of the other filters, and the page is then cut from that list of ids.
        $key = $this->getStoreId('encrypted') . ':' . $order . ':' . $direction . ':' . (int) $byName;

        if (!isset($this->matched[$key])) {
            $records = new BookingRecords($db, new KeyProvider((string) Factory::getApplication()->get('secret', '')));
            $found = $search === '' ? null : $records->search($query, $search);
            $ids = $found['ids'] ?? null;
            $candidates = clone $query;

            if ($ids !== null) {
                $candidates->where($ids === [] ? '1 = 0' : $db->quoteName('b.id') . ' IN (' . implode(',', $ids) . ')');
            }

            $this->matched[$key] = $byName ? $records->orderedByName($candidates, $direction === 'DESC') : $ids;

            // Never silently incomplete: the search and the name order cover the newest bookings only.
            if (!empty($found['cut']) || ($byName && count($this->matched[$key]) >= BookingRecords::SEARCH_LIMIT)) {
                Factory::getApplication()->enqueueMessage(Text::sprintf('COM_APPOINTMENTS_TEAM_E_BOOKINGS_SEARCH_CUT', BookingRecords::SEARCH_LIMIT), 'warning');
            }
        }

        $ids = $this->matched[$key];
        $query->where($ids === [] ? '1 = 0' : $db->quoteName('b.id') . ' IN (' . implode(',', $ids) . ')');

        if ($byName && $ids !== []) {
            $query->clear('order')->order('FIELD(' . $db->quoteName('b.id') . ', ' . implode(',', $ids) . ')');
        }

        return $query;
    }

    /**
     * Items with their personal fields opened (a field that cannot be decrypted is blank).
     */
    public function getItems()
    {
        $items = parent::getItems();

        if (!is_array($items)) {
            return $items;
        }

        $data = BookingData::fromApplication();

        foreach ($items as $item) {
            foreach ($data->openLenient((array) $item) as $field => $value) {
                $item->$field = $value;
            }
        }

        return $items;
    }

    protected function populateState($ordering = 'b.start_utc', $direction = 'DESC')
    {
        parent::populateState($ordering, $direction);
        $app = Factory::getApplication();
        foreach (['eventtype_id', 'state', 'from', 'to', 'search'] as $key) {
            $value = trim((string) $app->getUserStateFromRequest($this->context . '.filter.' . $key, 'filter_' . $key, '', 'string'));
            if (($key === 'eventtype_id' && $value !== '' && (!ctype_digit($value) || (int) $value < 1 || strlen($value) > 10))
                || ($key === 'state' && !in_array($value, ['', '0', '1', '2'], true))
                || (in_array($key, ['from', 'to'], true) && $value !== '' && !UtcTimestamp::isDate($value))
                || ($key === 'search' && (preg_match('//u', $value) !== 1 || mb_strlen($value) > 200))) {
                throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_BOOKINGS_FILTER_INVALID'), 400);
            }
            $this->setState('filter.' . $key, $value);
        }
        $from = $this->state->get('filter.from', '');
        $to = $this->state->get('filter.to', '');
        if ($from !== '' && $to !== '' && $from > $to) {
            throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_BOOKINGS_FILTER_INVALID'), 400);
        }
    }

    protected function getStoreId($id = '')
    {
        foreach (['eventtype_id', 'state', 'from', 'to', 'search'] as $key) {
            $id .= ':' . $this->getState('filter.' . $key);
        }

        return parent::getStoreId($id);
    }

    public function exportRows(): \Generator
    {
        $db = $this->getDatabase();
        $data = BookingData::fromApplication();
        $last = 0;
        do {
            $this->exporting = true;

            try {
                $query = $this->getListQuery();
            } finally {
                $this->exporting = false;
            }

            $query = $query->clear('order')->order($db->quoteName('b.id') . ' ASC')
                ->where($db->quoteName('b.id') . ' > ' . $last)->setLimit(200);
            $rows = $db->setQuery($query)->loadAssocList();
            foreach ($rows as $row) {
                $last = (int) $row['id'];
                // Opened right before the output; a field that cannot be decrypted is exported empty.
                $failed = [];
                $row = $data->openLenient($row, $failed);

                if ($failed !== []) {
                    Log::add('Appointment export: booking ' . $last . ' has fields that could not be decrypted (' . implode(', ', $failed) . ').', Log::WARNING, 'com_appointments');
                }

                yield $row;
            }
        } while (count($rows) === 200);
    }
}
