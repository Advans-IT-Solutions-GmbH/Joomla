<?php
/**
 * @package     Appointments Scheduler Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\Task\Appointments\Extension;

use Advans\Component\Appointments\Administrator\Service\PrivacyCleanup;
use Advans\Component\Appointments\Administrator\Service\SchedulerService;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

final class Appointments extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;
    use DatabaseAwareTrait;

    protected $autoloadLanguage = true;

    protected const TASKS_MAP = [
        'plg_task_appointments.close' => [
            'langConstPrefix' => 'PLG_TASK_APPOINTMENTS_CLOSE',
            'method' => 'closeDelivery',
        ],
        'plg_task_appointments.reminders' => [
            'langConstPrefix' => 'PLG_TASK_APPOINTMENTS_REMINDERS',
            'form' => 'batch',
            'method' => 'reminders',
        ],
        'plg_task_appointments.retention' => [
            'langConstPrefix' => 'PLG_TASK_APPOINTMENTS_RETENTION',
            'form' => 'batch',
            'method' => 'retention',
        ],
        // Internet calendars. Each does nothing while they are switched off.
        'plg_task_appointments.teamcleanup' => [
            'langConstPrefix' => 'PLG_TASK_APPOINTMENTS_TEAMCLEANUP',
            'method' => 'teamCleanup',
        ],
        'plg_task_appointments.teamlog' => [
            'langConstPrefix' => 'PLG_TASK_APPOINTMENTS_TEAMLOG',
            'method' => 'teamLog',
        ],
        'plg_task_appointments.teaminvitations' => [
            'langConstPrefix' => 'PLG_TASK_APPOINTMENTS_TEAMINVITATIONS',
            'method' => 'teamInvitations',
        ],
        'plg_task_appointments.teamretention' => [
            'langConstPrefix' => 'PLG_TASK_APPOINTMENTS_TEAMRETENTION',
            'method' => 'teamRetention',
        ],
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList' => 'advertiseRoutines',
            'onExecuteTask' => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }

    protected function reminders(ExecuteTaskEvent $event): int
    {
        return $this->execute('reminders', $event);
    }

    protected function closeDelivery(ExecuteTaskEvent $event): int
    {
        try {
            $this->getApplication()->bootComponent('com_appointments');
            $result = (new \Advans\Component\Appointments\Administrator\Service\Close\Queue(
                $this->getDatabase(), ComponentHelper::getParams('com_appointments')))->run();
            $this->logTask('Appointments Close delivery: ' . json_encode($result, JSON_THROW_ON_ERROR));
            return $result['failed'] > 0 ? Status::KNOCKOUT : Status::OK;
        } catch (\Throwable $e) {
            $this->logTask('Appointments Close delivery failed; check component configuration and delivery status.', 'error');
            return Status::KNOCKOUT;
        }
    }

    protected function retention(ExecuteTaskEvent $event): int
    {
        return $this->execute('retention', $event);
    }

    /**
     * Advance the clean-up of blocked devices by its timeouts and delete
     * lock-out counters without a failure for 24 hours.
     */
    protected function teamCleanup(ExecuteTaskEvent $event): int
    {
        return $this->executeTeam('teamcleanup', static fn (TeamServices $services, \DateTimeImmutable $now): array => [
            'devices_advanced' => $services->cleanup()->advance($now),
            'counters_deleted' => $services->lockout()->purge($now),
        ]);
    }

    /**
     * Prune log rows older than twelve months; the chain stays verifiable.
     */
    protected function teamLog(ExecuteTaskEvent $event): int
    {
        return $this->executeTeam('teamlog', static fn (TeamServices $services, \DateTimeImmutable $now): array => [
            'rows_pruned' => $services->audit()->prune($now),
        ]);
    }

    /**
     * Close expired invitations of external users and clear their addresses.
     */
    protected function teamInvitations(ExecuteTaskEvent $event): int
    {
        return $this->executeTeam('teaminvitations', static fn (TeamServices $services, \DateTimeImmutable $now): array => [
            'invitations_closed' => $services->invitations()->purgeExpired($now),
        ]);
    }

    /**
     * Retention per calendar: delete the booking copies that ended longer ago
     * than the calendar's `retention_days`; calendars with 0 keep everything,
     * and an entry of the calendar's own (no booking) is never deleted by age.
     * Also, whether the internet calendars are on or off: remove calendar copies
     * of cancelled, anonymised or deleted bookings, and encrypt booking rows that
     * are still in clear (once the booking record class exists). While they are
     * on: write the copies of future bookings anew where that was requested
     * (target calendar changed, switched on again).
     */
    protected function teamRetention(ExecuteTaskEvent $event): int
    {
        return $this->executeTeam('teamretention', static function (TeamServices $services, \DateTimeImmutable $now): array {
            $result = self::bookingUpkeep($services);

            if (!$services->enabled()) {
                return $result;
            }

            $result['copies_restored'] = $services->bookings()->restoreCopies(200);
            // The explicit completion signal: 0 once every restore request is done.
            $result['restore_pending'] = $services->bookings()->restorePending();
            $result['restore_failed'] = $services->bookings()->restoreFailed();

            $db = $services->db();
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $calendars = $db->setQuery($query->select($db->quoteName(['id', 'retention_days']))
                ->from($db->quoteName('#__appointments_calendars'))
                ->where($db->quoteName('retention_days') . ' > 0')
                ->order($db->quoteName('id') . ' ASC'))->loadAssocList();
            $deleted = 0;

            foreach ($calendars as $calendar) {
                // The retention is taken from the calendar service, which checks the
                // row MAC: a value written into the database alone deletes nothing.
                $verified = $services->calendars()->get((int) $calendar['id']);
                $days = $verified !== null ? (int) ($verified->retention_days ?? 0) : 0;

                if ($days > 0) {
                    $deleted += $services->objects()->deleteEndedBefore((int) $calendar['id'], $now->modify('-' . $days . ' days'));
                }
            }

            return $result + ['calendars' => \count($calendars), 'entries_deleted' => $deleted];
        }, true);
    }

    /**
     * Work that needs no key and runs with the internet calendars switched off too.
     *
     * @return  array<string, int>
     */
    private static function bookingUpkeep(TeamServices $services): array
    {
        $result = ['orphans_removed' => $services->bookings()->removeOrphans(500)]
            + PrivacyCleanup::retryCopies($services->db(), $services->bookings(), 200);
        $records = 'Advans\\Component\\Appointments\\Administrator\\Service\\BookingRecords';

        if (class_exists($records) && method_exists($records, 'resume')) {
            // The booking record class resumes its own migration within the time given.
            $result['bookings_encrypted'] = (int) $records::resume($services->db(), $services->keyProvider(), 20);
        } elseif (class_exists($records) && method_exists($records, 'migrate')) {
            $encrypted = 0;

            // Batches of 200 until nothing is left, within 20 seconds per run.
            $until = microtime(true) + 20;

            while (microtime(true) < $until) {
                $done = (int) $records::migrate($services->db(), $services->keyProvider(), 200);
                $encrypted += $done;

                if ($done === 0) {
                    break;
                }
            }

            $result['bookings_encrypted'] = $encrypted;
        }

        return $result;
    }

    /**
     * @param   callable(TeamServices, \DateTimeImmutable): array<string, int>  $routine
     * @param   bool  $alsoWhenOff  The routine decides itself what runs while the internet calendars are off.
     */
    private function executeTeam(string $name, callable $routine, bool $alsoWhenOff = false): int
    {
        try {
            $this->getApplication()->bootComponent('com_appointments');
            $services = TeamServices::fromApplication();

            if (!$alsoWhenOff && !$services->enabled()) {
                $this->logTask('Appointments ' . $name . ': internet calendars are switched off, nothing to do.');

                return Status::OK;
            }

            $result = $routine($services, $services->now());
            $this->logTask('Appointments ' . $name . ': ' . json_encode($result, JSON_THROW_ON_ERROR));

            // Copies that could not be written after every pass, or a clean-up that could not run:
            // the run fails, so it is seen.
            if (($result['restore_failed'] ?? 0) !== 0 || ($result['orphans_removed'] ?? 0) < 0) {
                $this->logTask('Appointments ' . $name . ': calendar copies could not be restored.', 'error');

                return Status::KNOCKOUT;
            }

            return Status::OK;
        } catch (\Throwable $e) {
            // Counts only; an exception text could name a user or a value.
            $this->logTask('The appointments ' . $name . ' task failed. ' . get_class($e) . ', code ' . (int) $e->getCode()
                . ', ' . basename($e->getFile()) . ':' . $e->getLine(), 'error');

            return Status::KNOCKOUT;
        }
    }

    private function execute(string $method, ExecuteTaskEvent $event): int
    {
        try {
            $this->getApplication()->bootComponent('com_appointments');
            $params = $event->getArgument('subject')->get('params');
            $params = $params instanceof Registry ? $params : new Registry($params);
            $size = filter_var($params->get('batch_size', 100), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
            if ($size === false) {
                throw new \InvalidArgumentException('Invalid Scheduler batch size.');
            }
            $service = new SchedulerService($this->getDatabase(), ComponentHelper::getParams('com_appointments'));
            $result = $service->$method(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $size);
            $this->logTask('Appointments ' . $method . ': ' . json_encode($result, JSON_THROW_ON_ERROR));

            return !empty($result['failed']) ? Status::KNOCKOUT : Status::OK;
        } catch (\Throwable $e) {
            // Database and transport exception messages can include personal data.
            // Report the failed task without copying those messages into its log.
            $this->logTask('The appointments ' . $method . ' task failed. Check configuration and mail delivery. '
                . get_class($e) . ', code ' . (int) $e->getCode() . ', ' . basename($e->getFile()) . ':' . $e->getLine(), 'error');

            return Status::KNOCKOUT;
        }
    }
}
