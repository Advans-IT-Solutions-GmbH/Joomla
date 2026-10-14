<?php
/**
 * @package     DMARC Reports Ingestion Task Plugin
 * @subpackage  Extension
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\Task\DmarcReports\Extension;

defined('_JEXEC') or die;

use Advans\Component\DmarcReports\Administrator\Service\SourcePoller;
use Advans\Plugin\Task\DmarcReports\Support\IngestSummary;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * Automatic DMARC/TLS-RPT mailbox ingestion for the DMARC Reports component.
 *
 * The plugin reuses the component's {@see SourcePoller} service so an automatic
 * run and a concurrent on-demand import share one cursor- and lock-safe path
 * and never process the same message twice.
 *
 * @since  1.0.0
 */
final class DmarcReports extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;
    use DatabaseAwareTrait;

    /**
     * @var array<string, array<string, string>>
     * @since 1.0.0
     */
    protected const TASKS_MAP = [
        'plg_task_dmarcreports.ingest' => [
            'langConstPrefix' => 'PLG_TASK_DMARCREPORTS_TASK_INGEST',
            'form'            => 'ingest',
            'method'          => 'runIngest',
        ],
    ];

    /**
     * @var boolean
     * @since 1.0.0
     */
    protected $autoloadLanguage = true;

    /**
     * @return string[]
     *
     * @since 1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList'    => 'advertiseRoutines',
            'onExecuteTask'        => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }

    /**
     * Poll every active source through the component's SourcePoller.
     *
     * @param   ExecuteTaskEvent  $event  The scheduler event.
     *
     * @return  integer  A scheduler Status constant.
     *
     * @since 1.0.0
     */
    protected function runIngest(ExecuteTaskEvent $event): int
    {
        $this->logTask('Starting automatic DMARC/TLS-RPT ingestion...');

        if (!class_exists(SourcePoller::class)) {
            $this->logTask('The DMARC Reports component is not installed; nothing to poll', 'error');

            return Status::KNOCKOUT;
        }

        // Component strings are used in the per-source run messages.
        Factory::getApplication()->getLanguage()->load('com_dmarcreports', JPATH_ADMINISTRATOR);

        $params              = $event->getArgument('params');
        $taskWantsAutoCreate = (int) ($params->auto_create_domains ?? 0) === 1;

        // Auto-creation of monitored domains is a privileged action guarded in
        // the component by core.create. A Scheduler task parameter must not
        // bypass that, so the capability only takes effect when it is also
        // enabled in the component options, which require administrator rights
        // to change.
        $componentAllowsAutoCreate = (int) ComponentHelper::getParams('com_dmarcreports')
            ->get('task_auto_create', 0) === 1;

        $allowAutoCreate = $taskWantsAutoCreate && $componentAllowsAutoCreate;

        if ($taskWantsAutoCreate && !$componentAllowsAutoCreate) {
            $this->logTask(
                'Automatic domain creation is requested by the task but disabled in the component options; ignoring it',
                'warning'
            );
        }

        try {
            $poller  = new SourcePoller($this->getDatabase());
            $sources = $poller->activeSources();
        } catch (\Throwable $e) {
            $this->logTask('Could not load the source list: ' . $e->getMessage(), 'error');

            return Status::KNOCKOUT;
        }

        if ($sources === []) {
            $this->logTask('No active IMAP sources configured; nothing to poll');

            return Status::OK;
        }

        $results = [];

        foreach ($sources as $source) {
            $result    = $poller->poll($source, $allowAutoCreate);
            $results[] = $result;

            if (($result['status'] ?? '') === SourcePoller::STATUS_ERROR) {
                $this->logTask('Source #' . (int) $source->id . ': ' . $result['message'], 'warning');

                continue;
            }

            $this->logTask('Source #' . (int) $source->id . ': ' . $result['message']);
        }

        ['imported' => $imported, 'duplicates' => $duplicates, 'failed' => $failed, 'blocked' => $blocked, 'errors' => $errors]
            = IngestSummary::aggregate($results);

        $this->logTask(
            "Ingestion complete: {$imported} imported, {$duplicates} duplicate(s), "
            . "{$failed} failed, {$blocked} blocked, {$errors} source error(s)"
        );

        return $errors > 0 ? Status::KNOCKOUT : Status::OK;
    }
}
