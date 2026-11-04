<?php
/**
 * @package     Appointments Privacy Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\Privacy\Appointments\Team;

use Advans\Component\Appointments\Administrator\Helper\Team\AuditEvent;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarKeyring;
use Advans\Component\Appointments\Administrator\Helper\Team\StoredObject;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\User\User;
use Joomla\Component\Privacy\Administrator\Export\Domain;
use Joomla\Component\Privacy\Administrator\Export\Field;
use Joomla\Component\Privacy\Administrator\Export\Item;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;

defined('_JEXEC') or die;

/**
 * Joomla privacy requests for the internet calendars (work package F, specification 9.1).
 *
 * There is no index of e-mail addresses. Calendar entries are searched only in the session of the
 * person handling the request, and only in the calendars that person can unlock: an effective,
 * key-holding access and a key unlocked in this session (password sign-in with the second
 * factor). The entries are decrypted in memory, their addresses read with
 * {@see \Advans\Component\Appointments\Administrator\Service\Team\TimingIndexInterface::emails()}
 * and compared with the requested address; nothing of this is stored. Every calendar that could
 * not be searched is named in the export and in the removal result: the result is never silently
 * incomplete. Bookings are handled by the booking page's privacy part.
 *
 * The export of an entry keeps the requester's own data: other attendees and other addresses in the
 * text are replaced. Staff data of an account (devices, grants, activation links, log rows) is
 * exported without any hash, salt, key or other person's id; log rows in which the person only
 * acted show the event and the time. Removal deletes the matching entries as normal changes and,
 * for an account, its devices, keys, grants and activation links like a deleted account; owned
 * calendars keep their contents with owner 0. Log rows stay for their retention of twelve months,
 * because they are the evidence of who did what.
 *
 * Search, export and removal are logged (`privacy.search`, `privacy.export`, `privacy.delete`)
 * with counts only, never the address, a hash of it, or content.
 *
 * @since  1.4.0
 */
final class TeamPrivacy
{
    private const LOG_ROWS = 5000;

    public function __construct(
        private readonly TeamServices $services,
        private readonly CMSApplicationInterface $app
    ) {
    }

    /**
     * Null while the internet calendars are switched off, so the plugin
     * behaves exactly as without them.
     */
    public static function forApplication(CMSApplicationInterface $app, ?DatabaseInterface $db = null): ?self
    {
        try {
            $services = TeamServices::fromApplication();

            if (!$services->enabled()) {
                return null;
            }

            $app->getLanguage()->load('com_appointments', JPATH_ADMINISTRATOR)
                || $app->getLanguage()->load('com_appointments', JPATH_ADMINISTRATOR . '/components/com_appointments');

            return new self($services, $app);
        } catch (\Throwable $e) {
            Log::add('Team calendars: the privacy request could not reach the internet calendars (' . \get_class($e) . ').', Log::WARNING, 'com_appointments');

            return null;
        }
    }

    /**
     * The requester's data in one entry: ATTENDEE and ORGANIZER of anybody
     * else and every other e-mail address in the text are replaced.
     */
    public static function redact(string $ics, string $email, string $otherPerson = 'other person'): string
    {
        $email = mb_strtolower(trim($email), 'UTF-8');
        $unfolded = preg_replace('/\r?\n[ \t]/', '', $ics) ?? '';
        $lines = [];

        foreach (preg_split('/\r?\n/', $unfolded) ?: [] as $line) {
            if (preg_match('/^(ATTENDEE|ORGANIZER)[;:]/i', $line, $m) === 1 && !str_contains(mb_strtolower($line, 'UTF-8'), $email)) {
                $line = strtoupper($m[1]) . ':' . $otherPerson;
            }

            $lines[] = preg_replace_callback('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
                static fn (array $match): string => mb_strtolower($match[0], 'UTF-8') === $email ? $match[0] : '[redacted]', $line) ?? '';
        }

        return implode("\r\n", $lines);
    }

    /**
     * @return  Domain[]
     */
    public function export(string $email, ?User $user): array
    {
        $domains = [];
        $counts = ['entries' => 0, 'not_searched' => 0, 'staff' => 0];

        // Entries and staff data are independent: a failure in one never hides the other.
        try {
            $search = $this->search($email);
            $entries = $this->domain('appointments_calendar_entries', 'COM_APPOINTMENTS_TEAM_F_PRIVACY_ENTRIES');
            $other = Text::_('COM_APPOINTMENTS_TEAM_F_PRIVACY_OTHER_PERSON');

            foreach ($search['matches'] as [$object, $ics, $title]) {
                $item = new Item();
                $item->id = $object->id;
                $this->field($item, 'calendar', $title);
                $this->field($item, 'modified_utc', $object->modifiedUtc->format('Y-m-d H:i:s'));
                $this->field($item, 'content', self::redact($ics, $email, $other));
                $entries->addItem($item);
            }

            $domains[] = $entries;
            $counts['entries'] = \count($search['matches']);
            $counts['not_searched'] = \count($search['unsearched']);

            if ($search['unsearched'] !== []) {
                $domains[] = $this->unsearchedDomain($search['unsearched']);
            }
        } catch (\Throwable $e) {
            Log::add('Team calendars: the privacy export of calendar entries failed (' . \get_class($e) . ').', Log::WARNING, 'com_appointments');
            $domains[] = $this->noteDomain('COM_APPOINTMENTS_TEAM_F_PRIVACY_FAILED');
            $counts['not_searched'] = -1;
        }

        $userId = $user !== null ? (int) $user->id : 0;

        if ($userId > 0) {
            try {
                foreach ($this->staffDomains($userId) as $domain) {
                    $domains[] = $domain;
                    $counts['staff'] += \count($domain->getItems());
                }
            } catch (\Throwable $e) {
                Log::add('Team calendars: the privacy export of staff data failed (' . \get_class($e) . ').', Log::WARNING, 'com_appointments');
                $domains[] = $this->noteDomain('COM_APPOINTMENTS_TEAM_F_PRIVACY_FAILED');
            }
        }

        $this->services->audit()->log(AuditEvent::PRIVACY_EXPORT, [
            'actor_user_id'   => $this->actorId(),
            'subject_user_id' => $userId > 0 ? $userId : null,
            'details'         => $counts,
        ]);

        return $domains;
    }

    /**
     * Delete the entries with this address and the staff data of the account.
     * Never throws; messages are queued for the administrator.
     */
    public function remove(string $email, ?User $user): void
    {
        $deleted = 0;
        $failed = 0;
        $unsearched = [];
        $staff = false;

        try {
            $search = $this->search($email);
            $unsearched = $search['unsearched'];

            foreach ($search['matches'] as [$object]) {
                try {
                    $this->services->objects()->delete($object->calendarId, $object->uri, null, null);
                    $deleted++;
                } catch (\Throwable $e) {
                    $failed++;
                }
            }
        } catch (\Throwable $e) {
            $failed++;
            Log::add('Team calendars: the privacy removal of calendar entries failed (' . \get_class($e) . ').', Log::WARNING, 'com_appointments');
        }

        $userId = $user !== null ? (int) $user->id : 0;

        if ($userId > 0) {
            try {
                $db = $this->services->db();
                $db->setQuery($this->query()->delete($db->quoteName('#__appointments_invitations'))
                    ->where($db->quoteName('user_id') . ' = ' . $userId))->execute();
                $staff = true;
                $this->services->cleanup()->onUserDeleted($userId);
            } catch (\Throwable $e) {
                $failed++;
                Log::add('Team calendars: the privacy removal of staff data failed (' . \get_class($e) . ').', Log::WARNING, 'com_appointments');
            }
        }

        $this->app->enqueueMessage(Text::sprintf('COM_APPOINTMENTS_TEAM_F_PRIVACY_REMOVED', $deleted), 'message');

        if ($staff) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_TEAM_F_PRIVACY_STAFF_REMOVED'), 'message');
        }

        if ($unsearched !== []) {
            $this->app->enqueueMessage(Text::sprintf('COM_APPOINTMENTS_TEAM_F_PRIVACY_UNSEARCHED', implode(', ', $unsearched)), 'warning');
        }

        if ($failed > 0) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_TEAM_F_PRIVACY_FAILED'), 'warning');
        }

        $this->services->audit()->log(AuditEvent::PRIVACY_DELETE, [
            'actor_user_id'   => $this->actorId(),
            'subject_user_id' => $userId > 0 ? $userId : null,
            'details'         => ['entries' => $deleted, 'failed' => $failed, 'not_searched' => \count($unsearched), 'staff' => $staff],
        ]);
    }

    /**
     * Search every calendar the handling person can unlock in this session.
     *
     * @return  array{matches: array<int, array{0: StoredObject, 1: string, 2: string}>, unsearched: array<int, string>}
     */
    private function search(string $email): array
    {
        $address = mb_strtolower(trim($email), 'UTF-8');
        $db = $this->services->db();
        $calendars = $db->setQuery($this->query()->select($db->quoteName(['id', 'title']))
            ->from($db->quoteName('#__appointments_calendars'))->order($db->quoteName('id') . ' ASC'))->loadAssocList();
        $matches = [];
        $unsearched = [];
        $searched = 0;

        foreach ($calendars as $calendar) {
            $id = (int) $calendar['id'];
            $title = (string) $calendar['title'] !== '' ? (string) $calendar['title'] : '#' . $id;
            $keyring = $address === '' ? null : $this->keyring($id);

            if ($keyring === null) {
                $unsearched[$id] = $title;

                continue;
            }

            try {
                foreach ($this->services->objects()->all($id) as $object) {
                    $ics = $this->services->keys()->decryptObject($keyring, $object);

                    if ($ics === null) {
                        // One entry it cannot read: the calendar is not completely searched.
                        $unsearched[$id] = $title;

                        continue;
                    }

                    if (\in_array($address, $this->services->timing()->emails($ics), true)) {
                        $matches[] = [$object, $ics, $title];
                    }
                }

                $searched++;
            } catch (\Throwable $e) {
                $unsearched[$id] = $title;
            } finally {
                $keyring->wipe();
            }
        }

        $this->services->audit()->log(AuditEvent::PRIVACY_SEARCH, [
            'actor_user_id' => $this->actorId(),
            'details'       => ['calendars' => \count($calendars), 'searched' => $searched, 'not_searched' => \count($unsearched),
                'matches' => \count($matches)],
        ]);

        return ['matches' => $matches, 'unsearched' => $unsearched];
    }

    /**
     * @return  Domain[]
     */
    private function staffDomains(int $userId): array
    {
        $db = $this->services->db();
        $sets = [
            ['appointments_staff_devices', 'COM_APPOINTMENTS_TEAM_F_PRIVACY_DEVICES', '#__appointments_devices',
                ['id', 'name', 'client', 'state', 'rule_version', 'rule_accepted_utc', 'created_utc', 'last_sync_utc',
                    'cleanup_started_utc', 'revoked_utc', 'revoke_reason']],
            ['appointments_staff_grants', 'COM_APPOINTMENTS_TEAM_F_PRIVACY_GRANTS', '#__appointments_calendar_grants',
                ['id', 'calendar_id', 'role', 'state', 'audience', 'created_utc', 'modified_utc']],
            ['appointments_staff_activations', 'COM_APPOINTMENTS_TEAM_F_PRIVACY_ACTIVATIONS', '#__appointments_invitations',
                ['id', 'state', 'created_utc', 'expires_utc', 'completed_utc']],
        ];
        $domains = [];

        foreach ($sets as [$name, $label, $table, $fields]) {
            $domain = $this->domain($name, $label);
            $rows = $db->setQuery($this->query()->select($db->quoteName($fields))->from($db->quoteName($table))
                ->where($db->quoteName('user_id') . ' = ' . $userId)->order($db->quoteName('id') . ' ASC'))->loadAssocList();

            foreach ($rows as $row) {
                $domain->addItem($this->item($row));
            }

            $domains[] = $domain;
        }

        // Log rows about this person: in full when the row concerns them (no
        // address hash, no chain hashes); event and time only when they acted
        // on somebody or something else.
        $domain = $this->domain('appointments_staff_log', 'COM_APPOINTMENTS_TEAM_F_PRIVACY_LOG');
        $rows = $db->setQuery($this->query()->select($db->quoteName(['id', 'occurred_utc', 'event', 'actor_user_id',
            'actor_device_id', 'subject_user_id', 'calendar_id', 'object_ref', 'details']))
            ->from($db->quoteName('#__appointments_teamlog'))
            ->where('(' . $db->quoteName('actor_user_id') . ' = ' . $userId . ' OR ' . $db->quoteName('subject_user_id') . ' = ' . $userId . ')')
            ->order($db->quoteName('id') . ' ASC')->setLimit(self::LOG_ROWS + 1))->loadAssocList();

        foreach (\array_slice($rows, 0, self::LOG_ROWS) as $row) {
            $own = (int) $row['subject_user_id'] === $userId
                || ($row['subject_user_id'] === null && $row['calendar_id'] === null && $row['object_ref'] === null);
            $domain->addItem($this->item($own ? $row : ['id' => $row['id'], 'occurred_utc' => $row['occurred_utc'], 'event' => $row['event']]));
        }

        if (\count($rows) > self::LOG_ROWS) {
            $item = new Item();
            $item->id = 0;
            $this->field($item, 'note', Text::sprintf('COM_APPOINTMENTS_TEAM_F_PRIVACY_LOG_TRUNCATED', self::LOG_ROWS));
            $domain->addItem($item);
        }

        $domains[] = $domain;

        return $domains;
    }

    /**
     * The calendar keyring of the person handling the request, only with an
     * effective, key-holding access and an unlocked key in this session.
     */
    private function keyring(int $calendarId): ?CalendarKeyring
    {
        try {
            $keys = $this->services->keySession()->current();
            $actor = $this->actorId();

            if ($keys === null || $actor === null) {
                return null;
            }

            $access = $this->services->grants()->accessFor($actor, $calendarId);

            if ($access === null || !$access->readsContent()) {
                return null;
            }

            return $this->services->keys()->openKeyring($calendarId, $keys);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param   array<int, string>  $calendars  Title by id.
     */
    private function unsearchedDomain(array $calendars): Domain
    {
        $domain = $this->domain('appointments_calendar_entries_not_searched', 'COM_APPOINTMENTS_TEAM_F_PRIVACY_INCOMPLETE');

        foreach ($calendars as $id => $title) {
            $item = new Item();
            $item->id = $id;
            $this->field($item, 'calendar', $title);
            $this->field($item, 'note', Text::_('COM_APPOINTMENTS_TEAM_F_PRIVACY_NOT_SEARCHED'));
            $domain->addItem($item);
        }

        return $domain;
    }

    private function actorId(): ?int
    {
        $id = (int) ($this->app->getIdentity()->id ?? 0);

        return $id > 0 ? $id : null;
    }

    private function domain(string $name, string $label): Domain
    {
        $domain = new Domain();
        $domain->name = $name;
        $domain->description = Text::_($label);

        return $domain;
    }

    private function noteDomain(string $label): Domain
    {
        $domain = $this->domain('appointments_calendar_entries_not_searched', 'COM_APPOINTMENTS_TEAM_F_PRIVACY_INCOMPLETE');
        $item = new Item();
        $item->id = 0;
        $this->field($item, 'note', Text::_($label));
        $domain->addItem($item);

        return $domain;
    }

    /**
     * @param   array<string, mixed>  $row
     */
    private function item(array $row): Item
    {
        $item = new Item();
        $item->id = (int) ($row['id'] ?? 0);

        foreach ($row as $name => $value) {
            $this->field($item, (string) $name, $value === null ? '' : (string) $value);
        }

        return $item;
    }

    private function field(Item $item, string $name, string $value): void
    {
        $field = new Field();
        $field->name = $name;
        $field->value = $value;
        $item->addField($field);
    }

    private function query(): QueryInterface
    {
        $db = $this->services->db();

        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
