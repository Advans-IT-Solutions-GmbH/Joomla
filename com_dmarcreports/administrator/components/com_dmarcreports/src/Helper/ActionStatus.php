<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Helper;

use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * Merges the live Action Center recommendations with the status an operator has
 * persisted for them (done, snoozed, ignored). The same merge logic is shared by
 * the dashboard and the per-domain drill-down so a handled recommendation stays
 * handled in both places rather than reappearing in one of them.
 *
 * @since  1.0.0
 */
final class ActionStatus
{
    private DatabaseInterface $db;

    /** @var array<string, array{status: string, snooze_until: int}>|null */
    private ?array $statuses = null;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Split generated action items into the active ones (merged with their
     * persisted status) and the suppressed ones (done, ignored or currently
     * snoozed). Active items drop any that are done, ignored or snoozed in the
     * future; suppressed items carry the status and snooze timestamp for display.
     *
     * @param   array<int, array<string, mixed>>  $items
     *
     * @return  array{active: array<int, array<string, mixed>>, suppressed: array<int, array<string, mixed>>}
     */
    public function partition(array $items): array
    {
        $statuses = $this->statuses();
        $now      = time();
        $active   = [];
        $suppress = [];

        foreach ($items as $item) {
            $state = $statuses[$item['action_key']] ?? ['status' => 'open', 'snooze_until' => 0];

            if ($state['status'] === 'snoozed' && $state['snooze_until'] <= $now) {
                $state['status'] = 'open';
            }

            $item['status']       = $state['status'];
            $item['snooze_until'] = $state['snooze_until'];

            if ($state['status'] === 'open') {
                $active[] = $item;
            } else {
                $suppress[] = $item;
            }
        }

        return ['active' => $active, 'suppressed' => $suppress];
    }

    /**
     * The persisted status map keyed by action key, loaded once per instance.
     *
     * @return array<string, array{status: string, snooze_until: int}>
     */
    public function statuses(): array
    {
        if ($this->statuses !== null) {
            return $this->statuses;
        }

        $query = (method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true))
            ->select(
                [
                    $this->db->quoteName('action_key'),
                    $this->db->quoteName('status'),
                    $this->db->quoteName('snooze_until'),
                ]
            )
            ->from($this->db->quoteName('#__dmarc_actions'));

        $rows           = $this->db->setQuery($query)->loadObjectList() ?: [];
        $this->statuses = [];

        foreach ($rows as $row) {
            $this->statuses[(string) $row->action_key] = [
                'status'       => (string) $row->status,
                'snooze_until' => $row->snooze_until ? (int) strtotime((string) $row->snooze_until) : 0,
            ];
        }

        return $this->statuses;
    }
}
