<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Model;

use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

defined('_JEXEC') or die;

/**
 * Admin model for a single schedule, together with its rules.
 *
 * A schedule and its intervals are edited on one screen, because a schedule
 * without intervals says nothing and two screens would let an operator save
 * half of a change. The intervals are therefore part of this form and are
 * written in the same transaction as the schedule itself.
 *
 * @since  1.0.0
 */
class ScheduleModel extends AdminModel
{
    public function getTable($name = 'Schedule', $prefix = 'Administrator', $options = [])
    {
        return parent::getTable($name, $prefix, $options);
    }

    /**
     * Load the schedule and its rules, so the repeatable fields of the form are
     * filled from the rule table.
     *
     * @param   integer  $pk  The primary key, or null for the state value.
     *
     * @return  mixed  The item object on success, false on failure.
     */
    public function getItem($pk = null)
    {
        $item = parent::getItem($pk);

        if (!$item) {
            return $item;
        }

        if (empty($item->id)) {
            // A new schedule starts in the site's own zone rather than in UTC:
            // that is what the operator almost always means.
            $item->timezone       = $item->timezone ?: (string) Factory::getApplication()->get('offset', 'UTC');
            $item->weekly_rules   = [];
            $item->date_overrides = [];

            return $item;
        }

        $item->weekly_rules   = [];
        $item->date_overrides = [];

        foreach ($this->loadRules((int) $item->id) as $rule) {
            if ($rule->specific_date !== null) {
                $item->date_overrides[] = [
                    'specific_date'  => $rule->specific_date,
                    'start_time'     => substr((string) $rule->start_time, 0, 5),
                    'end_time'       => substr((string) $rule->end_time, 0, 5),
                    'is_unavailable' => (string) (int) $rule->is_unavailable,
                ];

                continue;
            }

            $item->weekly_rules[] = [
                'weekday'    => (string) (int) $rule->weekday,
                'start_time' => substr((string) $rule->start_time, 0, 5),
                'end_time'   => substr((string) $rule->end_time, 0, 5),
            ];
        }

        return $item;
    }

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm(
            'com_appointments.schedule',
            'schedule',
            ['control' => 'jform', 'load_data' => $loadData]
        );

        if (empty($form)) {
            return false;
        }

        // Without core.edit.state the published field must not be changeable
        // from the edit form. Disabling it hides the control and unsetting its
        // filter drops the value from the posted data, so the restriction holds
        // server-side and cannot be bypassed by crafting the request.
        if (!$this->canEditState((object) $data)) {
            $form->setFieldAttribute('published', 'disabled', 'true');
            $form->setFieldAttribute('published', 'filter', 'unset');
        }

        return $form;
    }

    protected function loadFormData()
    {
        $data = Factory::getApplication()->getUserState('com_appointments.edit.schedule.data', []);

        if (empty($data)) {
            $data = $this->getItem();
        }

        return $data;
    }

    /**
     * Save the schedule and replace its rules.
     *
     * @param   array  $data  The posted form data.
     *
     * @return  boolean
     */
    public function save($data)
    {
        $weekly    = (array) ($data['weekly_rules'] ?? []);
        $overrides = (array) ($data['date_overrides'] ?? []);

        unset($data['weekly_rules'], $data['date_overrides']);

        // Everything is validated before anything is written, so a mistake in
        // the last interval cannot leave the schedule half saved.
        $rows = $this->validateRules($weekly, $overrides);

        if ($rows === null) {
            return false;
        }

        $db = $this->getDatabase();
        $db->transactionStart();

        try {
            if (!parent::save($data)) {
                $db->transactionRollback();

                return false;
            }

            $this->replaceRules((int) $this->getState($this->getName() . '.id'), $rows);
            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();

            throw $e;
        }

        return true;
    }

    /**
     * Delete schedules, together with their rules.
     *
     * A schedule an event type still points at is refused: silently deleting it
     * would leave that event type with no availability and no visible reason.
     *
     * @param   array  &$pks  The primary keys to delete.
     *
     * @return  boolean
     */
    public function delete(&$pks)
    {
        $ids = array_values(array_filter(array_map('intval', (array) $pks), static fn ($id) => $id > 0));

        if ($ids === []) {
            return parent::delete($pks);
        }

        $db = $this->getDatabase();

        foreach ($ids as $id) {
            $query = $this->createDbQuery($db)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__appointments_eventtypes'))
                ->where($db->quoteName('schedule_id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER);

            if ((int) $db->setQuery($query)->loadResult() > 0) {
                $this->setError(Text::_('COM_APPOINTMENTS_SCHEDULE_ERROR_IN_USE'));

                return false;
            }
        }

        $db->transactionStart();

        try {
            $query = $this->createDbQuery($db)
                ->delete($db->quoteName('#__appointments_schedule_rules'))
                ->whereIn($db->quoteName('schedule_id'), $ids, ParameterType::INTEGER);
            $db->setQuery($query)->execute();

            if (!parent::delete($pks)) {
                $db->transactionRollback();

                return false;
            }

            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();

            throw $e;
        }

        return true;
    }

    /**
     * The rules of one schedule, weekly ones first.
     *
     * @return  \stdClass[]
     */
    private function loadRules(int $scheduleId): array
    {
        $db    = $this->getDatabase();
        $query = $this->createDbQuery($db)
            ->select(
                $db->quoteName(['id', 'weekday', 'specific_date', 'start_time', 'end_time', 'is_unavailable'])
            )
            ->from($db->quoteName('#__appointments_schedule_rules'))
            ->where($db->quoteName('schedule_id') . ' = :id')
            ->bind(':id', $scheduleId, ParameterType::INTEGER)
            ->order($db->quoteName('specific_date') . ' ASC')
            ->order($db->quoteName('weekday') . ' ASC')
            ->order($db->quoteName('start_time') . ' ASC');

        return $db->setQuery($query)->loadObjectList() ?: [];
    }

    /**
     * Check the posted intervals and return the rows to store.
     *
     * @return  array|null  The rows, or null when something is unusable; the
     *                      error is set in that case.
     */
    private function validateRules(array $weekly, array $overrides): ?array
    {
        $rows = [];

        foreach ($weekly as $rule) {
            $rule = (array) $rule;

            // The repeatable field sends an empty row when the operator adds one
            // and leaves it alone. An empty row is not an error, it is nothing.
            if (trim((string) ($rule['start_time'] ?? '')) === '' && trim((string) ($rule['end_time'] ?? '')) === '') {
                continue;
            }

            $weekday = (int) ($rule['weekday'] ?? 0);

            if ($weekday < 1 || $weekday > 7) {
                $this->setError(Text::_('COM_APPOINTMENTS_SCHEDULE_ERROR_WEEKDAY'));

                return null;
            }

            $times = $this->validateTimes($rule);

            if ($times === null) {
                return null;
            }

            $rows[] = [
                'weekday'        => $weekday,
                'specific_date'  => null,
                'start_time'     => $times[0],
                'end_time'       => $times[1],
                'is_unavailable' => 0,
            ];
        }

        // Per date: whether it carries a closed row, and whether it carries an
        // open interval. A date that carries both is refused below, because the
        // resolver lets the closed row win and the operator's intervals would be
        // stored and then silently ignored.
        $closedDates = [];
        $openDates   = [];

        foreach ($overrides as $rule) {
            $rule = (array) $rule;
            $date = trim((string) ($rule['specific_date'] ?? ''));

            // Only a row in which nothing at all was entered is skipped. A row
            // that carries times or the closed marker but no date is a mistake,
            // not an empty row: discarding it would throw the operator's input
            // away and still report success.
            if (
                $date === ''
                && trim((string) ($rule['start_time'] ?? '')) === ''
                && trim((string) ($rule['end_time'] ?? '')) === ''
                && empty($rule['is_unavailable'])
            ) {
                continue;
            }

            // A calendar field can hand over a full timestamp; only the date
            // part of it describes an override.
            $date = substr($date, 0, 10);

            if (!$this->isRealDate($date)) {
                $this->setError(Text::_('COM_APPOINTMENTS_SCHEDULE_ERROR_DATE'));

                return null;
            }

            $closed = !empty($rule['is_unavailable']);

            if ($closed) {
                // A closed day has no times. Replacing entered ones with
                // midnight would report a successful save while throwing away
                // what the operator typed, and a typo in the availability
                // selector would be invisible.
                if (
                    trim((string) ($rule['start_time'] ?? '')) !== ''
                    || trim((string) ($rule['end_time'] ?? '')) !== ''
                ) {
                    $this->setError(Text::sprintf('COM_APPOINTMENTS_SCHEDULE_ERROR_MIXED_DATE', $date));

                    return null;
                }

                $closedDates[$date] = true;

                $rows[] = [
                    'weekday'        => null,
                    'specific_date'  => $date,
                    'start_time'     => '00:00:00',
                    'end_time'       => '00:00:00',
                    'is_unavailable' => 1,
                ];

                continue;
            }

            $times = $this->validateTimes($rule);

            if ($times === null) {
                return null;
            }

            $openDates[$date] = true;

            $rows[] = [
                'weekday'        => null,
                'specific_date'  => $date,
                'start_time'     => $times[0],
                'end_time'       => $times[1],
                'is_unavailable' => 0,
            ];
        }

        // Several open intervals on one date are fine, a morning and an
        // afternoon for instance. A closed row next to them is not: the
        // resolver closes the day and the intervals would be stored and then
        // never used, so the save would quietly throw availability away.
        foreach (array_keys($closedDates) as $date) {
            if (isset($openDates[$date])) {
                $this->setError(Text::sprintf('COM_APPOINTMENTS_SCHEDULE_ERROR_MIXED_DATE', $date));

                return null;
            }
        }

        return $rows;
    }

    /**
     * The start and end of one interval as `H:i:s`, or null when either is not
     * a time of day.
     *
     * An end that is not after the start is accepted on purpose: it means the
     * interval continues past midnight, which is one window to the slot engine.
     *
     * @return  array{0:string, 1:string}|null
     */
    private function validateTimes(array $rule): ?array
    {
        $start = self::normaliseTime((string) ($rule['start_time'] ?? ''));
        $end   = self::normaliseTime((string) ($rule['end_time'] ?? ''));

        if ($start === null || $end === null) {
            $this->setError(Text::_('COM_APPOINTMENTS_SCHEDULE_ERROR_TIME'));

            return null;
        }

        return [$start, $end];
    }

    /**
     * Replace every rule of one schedule.
     */
    private function replaceRules(int $scheduleId, array $rows): void
    {
        if ($scheduleId <= 0) {
            throw new \RuntimeException('The schedule has no id after being saved.');
        }

        $db    = $this->getDatabase();
        $query = $this->createDbQuery($db)
            ->delete($db->quoteName('#__appointments_schedule_rules'))
            ->where($db->quoteName('schedule_id') . ' = :id')
            ->bind(':id', $scheduleId, ParameterType::INTEGER);
        $db->setQuery($query)->execute();

        foreach ($rows as $row) {
            $weekday = $row['weekday'];
            $date    = $row['specific_date'];

            $query = $this->createDbQuery($db)
                ->insert($db->quoteName('#__appointments_schedule_rules'))
                ->columns(
                    $db->quoteName(
                        ['schedule_id', 'weekday', 'specific_date', 'start_time', 'end_time', 'is_unavailable']
                    )
                )
                ->values(':scheduleId, :weekday, :specificDate, :startTime, :endTime, :unavailable')
                ->bind(':scheduleId', $scheduleId, ParameterType::INTEGER)
                ->bind(
                    ':weekday',
                    $weekday,
                    $weekday === null ? ParameterType::NULL : ParameterType::INTEGER
                )
                ->bind(
                    ':specificDate',
                    $date,
                    $date === null ? ParameterType::NULL : ParameterType::STRING
                )
                ->bind(':startTime', $row['start_time'])
                ->bind(':endTime', $row['end_time'])
                ->bind(':unavailable', $row['is_unavailable'], ParameterType::INTEGER);
            $db->setQuery($query)->execute();
        }
    }

    /**
     * Whether the string is a date the DATE column can hold.
     *
     * The shape alone is not enough, and neither is the calendar. 2026-02-31
     * has the right shape, and the year 0001 is a date that exists but lies
     * below the range the column documents as supported. Either would reach the
     * column and leave the operator with whatever the database decides to do
     * instead of the translated message, so one strict check answers both.
     */
    private function isRealDate(string $date): bool
    {
        return UtcTimestamp::isDate($date);
    }

    /**
     * Accept `H:i` and `H:i:s` and return `H:i:s`.
     */
    private static function normaliseTime(string $time): ?string
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($time), $parts)) {
            return null;
        }

        $hour   = (int) $parts[1];
        $minute = (int) $parts[2];
        $second = (int) ($parts[3] ?? 0);

        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
    }

    /**
     * Creates a query object compatible with Joomla 5 (getQuery) and 6 (createQuery).
     */
    private function createDbQuery(DatabaseInterface $db): QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
