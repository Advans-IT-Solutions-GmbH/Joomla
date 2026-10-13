<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Controller;

use Advans\Component\DmarcReports\Administrator\Helper\DomainLock;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

/**
 * Persists the administrator's decision on an Action Center item. Items are
 * regenerated live from the data on every dashboard load; this controller only
 * stores the status override (done, snoozed, ignored, reopened) keyed by domain
 * and a stable action key, so progress survives regeneration.
 *
 * @since  1.0.0
 */
class ActionsController extends BaseController
{
    public function done()
    {
        return $this->setStatus('done');
    }

    public function ignore()
    {
        return $this->setStatus('ignored');
    }

    public function reopen()
    {
        return $this->setStatus('open');
    }

    public function snooze()
    {
        $days = max(1, (int) Factory::getApplication()->getInput()->getInt('days', 7));

        return $this->setStatus('snoozed', $days);
    }

    private function setStatus(string $status, int $snoozeDays = 0): bool
    {
        $this->checkToken();

        $app = Factory::getApplication();

        if (!$app->getIdentity()->authorise('core.edit.state', 'com_dmarcreports')) {
            throw new \Joomla\CMS\Access\Exception\NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $input     = $app->getInput();
        $domainId  = (int) $input->getInt('domain_id', 0);
        $actionKey = (string) $input->getString('action_key', '');
        $category  = (string) $input->getCmd('category', 'general');
        $redirect  = Route::_('index.php?option=com_dmarcreports&view=dashboard', false);

        if ($domainId <= 0 || $actionKey === '') {
            $this->setRedirect($redirect, Text::_('COM_DMARCREPORTS_ACTION_INVALID'), 'error');

            return false;
        }

        // The Action Center embeds the owning domain id as the second
        // ':'-separated segment of every key (code:domainId[:suffix]). Reject a
        // submission whose key does not belong to the posted domain so a forged
        // or mismatched request cannot write an override that suppresses a
        // different domain's recommendation: overrides are keyed by action_key,
        // and the key is globally unique only because it carries its domain id.
        $keyParts = explode(':', $actionKey);

        if (!isset($keyParts[1]) || (int) $keyParts[1] !== $domainId) {
            $this->setRedirect($redirect, Text::_('COM_DMARCREPORTS_ACTION_INVALID'), 'error');

            return false;
        }

        $db          = Factory::getContainer()->get(DatabaseInterface::class);
        $now         = Factory::getDate()->toSql();
        $snoozeUntil = $snoozeDays > 0 ? Factory::getDate('+' . $snoozeDays . ' days')->toSql() : null;
        $userId      = (int) $app->getIdentity()->id;

        $db->transactionStart();

        try {
            // Take the shared parent-domain lock before writing the action so a
            // concurrent domain delete cannot clear the actions table and then
            // remove the domain while this row is being written, which would
            // leave it orphaned. If the domain is already gone, abandon the
            // write rather than store an action for a domain that no longer
            // exists. See {@see DomainLock} for the protocol.
            if (!DomainLock::lock($db, $domainId)) {
                $db->transactionRollback();
                $this->setRedirect($redirect, Text::_('COM_DMARCREPORTS_ACTION_INVALID'), 'error');

                return false;
            }

            // Look up any existing override under the lock so a concurrent
            // writer cannot slip a row in between the read and the write.
            $existingId = $this->findActionId($db, $domainId, $actionKey);

            if ($existingId > 0) {
                $this->applyStatus($db, $existingId, $status, $snoozeUntil, $now, $userId);
            } else {
                $row = (object) [
                    'domain_id'    => $domainId,
                    'action_key'   => $actionKey,
                    'category'     => $category,
                    'severity'     => 0,
                    'status'       => $status,
                    'params'       => '',
                    'snooze_until' => $snoozeUntil,
                    'first_seen'   => $now,
                    'last_seen'    => $now,
                    'modified'     => $now,
                    'modified_by'  => $userId,
                ];

                try {
                    $db->insertObject('#__dmarc_actions', $row);
                } catch (\RuntimeException $e) {
                    // The read-then-insert is not atomic: a concurrent request
                    // can create the same (domain_id, action_key) row between the
                    // lookup above and this insert, so the unique index rejects
                    // it. Recover the race by re-reading the row the other
                    // request created and applying the requested status as an
                    // update instead of failing.
                    $raceId = $this->findActionId($db, $domainId, $actionKey);

                    if ($raceId <= 0) {
                        throw $e;
                    }

                    $this->applyStatus($db, $raceId, $status, $snoozeUntil, $now, $userId);
                }
            }

            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();

            throw $e;
        }

        $this->setRedirect($redirect, Text::_('COM_DMARCREPORTS_ACTION_UPDATED'), 'message');

        return true;
    }

    private function findActionId(DatabaseInterface $db, int $domainId, string $actionKey): int
    {
        return (int) $db->setQuery(
            $this->createDbQuery($db)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__dmarc_actions'))
                ->where($db->quoteName('domain_id') . ' = :domainId')
                ->where($db->quoteName('action_key') . ' = :actionKey')
                ->bind(':domainId', $domainId, ParameterType::INTEGER)
                ->bind(':actionKey', $actionKey)
        )->loadResult();
    }

    private function applyStatus(
        DatabaseInterface $db,
        int $id,
        string $status,
        ?string $snoozeUntil,
        string $now,
        int $userId
    ): void {
        $row = (object) [
            'id'           => $id,
            'status'       => $status,
            'snooze_until' => $snoozeUntil,
            'last_seen'    => $now,
            'modified'     => $now,
            'modified_by'  => $userId,
        ];
        $db->updateObject('#__dmarc_actions', $row, 'id');
    }

    private function createDbQuery(DatabaseInterface $db): \Joomla\Database\QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
