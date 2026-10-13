<?php
/**
 * @package     Appointments Scheduler Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\Task\Appointments\Extension;

use Advans\Component\Appointments\Administrator\Service\SchedulerService;
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

    protected function retention(ExecuteTaskEvent $event): int
    {
        return $this->execute('retention', $event);
    }

    private function execute(string $method, ExecuteTaskEvent $event): int
    {
        try {
            $this->getApplication()->bootComponent('com_appointments');
            $params = $event->getArgument('params');
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
            $this->logTask('The appointments ' . $method . ' task failed. Check configuration and mail delivery.', 'error');

            return Status::KNOCKOUT;
        }
    }
}
