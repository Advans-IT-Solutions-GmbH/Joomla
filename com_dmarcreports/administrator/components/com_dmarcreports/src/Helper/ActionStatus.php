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
    /**
     * The longest snooze accepted. The dashboard offers 7 days; a crafted
     * request with a huge value would otherwise overflow the date calculation.
     */
    public const MAX_SNOOZE_DAYS = 365;

    /**
     * Status of an item the software resolved itself, recorded with
     * modified_by = 0 so it is told apart from an administrator's decision.
     */
    public const AUTO_DONE = 'auto_done';

    private DatabaseInterface $db;

    /**
     * Clamp a requested snooze length to 1 .. MAX_SNOOZE_DAYS days.
     */
    public static function snoozeDays(int $requested): int
    {
        return min(self::MAX_SNOOZE_DAYS, max(1, $requested));
    }

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
     * Resolve the stored "new source" items whose source the software now
     * confirms through DKIM.
     *
     * Up to 1.3 every new sending address with failing mail became a "new source"
     * item the administrator had to judge. Sources are now judged automatically
     * ({@see SourceClassifier}) and that item is no longer generated. A "new
     * source" item the administrator had postponed or reopened is closed here as
     * soon as its source sends DKIM-aligned mail, with the status AUTO_DONE and
     * modified_by = 0, so the dashboard can show that the software, not a person,
     * resolved it. Items the administrator ignored stay as they are.
     *
     * @param   \Closure  $classify  fn (int $domainId, string[] $ips): array<string, string>
     *                               returning the SourceClassifier class per address.
     *
     * @return  int  The number of items resolved.
     */
    public function resolveConfirmedNewSources(\Closure $classify): int
    {
        $query = $this->query()
            ->select([$this->db->quoteName('id'), $this->db->quoteName('domain_id'), $this->db->quoteName('action_key')])
            ->from($this->db->quoteName('#__dmarc_actions'))
            ->where($this->db->quoteName('action_key') . ' LIKE ' . $this->db->quote('new_source:%'))
            ->where($this->db->quoteName('status') . ' IN (' . $this->db->quote('open') . ', ' . $this->db->quote('snoozed') . ')');

        $byDomain = [];

        foreach ($this->db->setQuery($query)->loadObjectList() ?: [] as $row) {
            // LIKE treats "_" as a wildcard; keep only the exact prefix.
            $key = (string) $row->action_key;
            $ip  = str_starts_with($key, 'new_source:') ? self::sourceOf($key, (int) $row->domain_id) : '';

            if ($ip !== '') {
                $byDomain[(int) $row->domain_id][(int) $row->id] = $ip;
            }
        }

        $resolved = 0;
        $now      = gmdate('Y-m-d H:i:s');

        foreach ($byDomain as $domainId => $rows) {
            $classes = (array) $classify($domainId, array_values(array_unique($rows)));

            foreach ($rows as $id => $ip) {
                if (($classes[$ip] ?? '') !== SourceClassifier::DKIM) {
                    continue;
                }

                $update = (object) [
                    'id'           => $id,
                    'status'       => self::AUTO_DONE,
                    'snooze_until' => null,
                    'modified'     => $now,
                    'modified_by'  => 0,
                ];
                $this->db->updateObject('#__dmarc_actions', $update, 'id', true);
                $resolved++;
            }
        }

        if ($resolved > 0) {
            $this->statuses = null;
        }

        return $resolved;
    }

    /**
     * The items the software resolved itself (AUTO_DONE), shaped like Action
     * Center items so the dashboard lists them among the handled ones.
     *
     * @return  array<int, array<string, mixed>>
     */
    public function autoResolved(): array
    {
        $query = $this->query()
            ->select([$this->db->quoteName('domain_id'), $this->db->quoteName('action_key')])
            ->from($this->db->quoteName('#__dmarc_actions'))
            ->where($this->db->quoteName('status') . ' = ' . $this->db->quote(self::AUTO_DONE))
            ->order($this->db->quoteName('modified') . ' DESC');

        $items = [];

        foreach ($this->db->setQuery($query)->loadObjectList() ?: [] as $row) {
            $domainId = (int) $row->domain_id;
            $key      = (string) $row->action_key;
            $code     = strstr($key, ':', true);
            $ip       = self::sourceOf($key, $domainId);

            if ($code === false || $code === '' || $ip === '') {
                continue;
            }

            $items[] = [
                'action_key'   => $key,
                'code'         => $code,
                'category'     => 'source',
                'severity'     => 0,
                'domain_id'    => $domainId,
                'params'       => [$ip],
                'status'       => self::AUTO_DONE,
                'snooze_until' => 0,
            ];
        }

        return $items;
    }

    /**
     * The source address a per-source action key (code:domainId:ip) is about, or
     * '' when the key belongs to another domain or names no valid address. IPv6
     * addresses contain colons, so everything after the second colon is the
     * address.
     */
    public static function sourceOf(string $actionKey, int $domainId): string
    {
        $parts = explode(':', $actionKey, 3);

        if (\count($parts) !== 3 || (int) $parts[1] !== $domainId || $domainId <= 0) {
            return '';
        }

        return @inet_pton($parts[2]) === false ? '' : $parts[2];
    }

    private function query(): \Joomla\Database\QueryInterface
    {
        return method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
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
