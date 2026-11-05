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

    /**
     * The end of this request's time budget (microtime), shared by search and removal.
     *
     * @var  float
     */
    private $deadline = 0.0;

    /** Hard limits of one request, so a large site never exhausts memory or time. */
    public const MAX_CALENDAR_BYTES = 16777216;
    public const MAX_OBJECTS = 20000;
    public const MAX_EXPORT_BYTES = 4194304;
    public const TIME_BUDGET_SECONDS = 20;

    /**
     * @param   TeamServices|null  $services  Null when the internet calendars could not be reached
     *                                         although they are on: every request is then reported incomplete.
     */
    public function __construct(
        private readonly ?TeamServices $services,
        private readonly CMSApplicationInterface $app
    ) {
    }

    /**
     * Null while the internet calendars are switched off, so the plugin
     * behaves exactly as without them. When they are on but cannot be reached,
     * an instance that reports every request as incomplete, never silently
     * complete.
     */
    public static function forApplication(CMSApplicationInterface $app, ?DatabaseInterface $db = null): ?self
    {
        $app->getLanguage()->load('com_appointments', JPATH_ADMINISTRATOR)
            || $app->getLanguage()->load('com_appointments', JPATH_ADMINISTRATOR . '/components/com_appointments');

        try {
            $services = TeamServices::fromApplication();

            if ($services->enabled()) {
                return new self($services, $app);
            }

            // Switched on, but the cryptography is missing: the data exists and
            // cannot be read, so the request is incomplete, not empty.
            return (int) $services->params()->get('teamcal_enabled', 0) === 1 ? new self(null, $app) : null;
        } catch (\Throwable $e) {
            Log::add('Team calendars: the privacy request could not reach the internet calendars (' . \get_class($e) . ').', Log::WARNING, 'com_appointments');

            return new self(null, $app);
        }
    }

    /**
     * Booking rows the booking privacy part could not decrypt during this
     * request (`PrivacyService::unreadable()`, work package E): the request is
     * reported as incomplete with the number only, never with content. Works
     * whether the internet calendars are on or off. Returns the export domain,
     * or null when every row could be read; the administrator gets a warning.
     */
    public static function unreadableBookings(object $service, CMSApplicationInterface $app): ?Domain
    {
        $count = method_exists($service, 'unreadable') ? (int) $service->unreadable() : 0;

        if ($count <= 0) {
            return null;
        }

        $app->getLanguage()->load('com_appointments', JPATH_ADMINISTRATOR)
            || $app->getLanguage()->load('com_appointments', JPATH_ADMINISTRATOR . '/components/com_appointments');
        $app->enqueueMessage(Text::sprintf('COM_APPOINTMENTS_TEAM_F_PRIVACY_BOOKINGS_UNREADABLE_ADMIN', $count), 'warning');
        $domain = new Domain();
        $domain->name = 'appointments_bookings_incomplete';
        $domain->description = Text::_('COM_APPOINTMENTS_TEAM_F_PRIVACY_BOOKINGS_UNREADABLE');
        $item = new Item();
        $item->id = 0;
        $field = new Field();
        $field->name = 'bookings';
        $field->value = (string) $count;
        $item->addField($field);
        $domain->addItem($item);

        return $domain;
    }

    /** Properties of an entry that the export keeps: its times and its title and place. */
    private const KEPT = ['BEGIN', 'END', 'VERSION', 'DTSTART', 'DTEND', 'DURATION', 'RRULE', 'RDATE', 'EXDATE',
        'RECURRENCE-ID', 'STATUS', 'TRANSP', 'TZID', 'TZOFFSETFROM', 'TZOFFSETTO', 'TZNAME', 'SUMMARY', 'LOCATION'];

    /** The only parameters kept: time zone and value type of times, and the requester's own participation. */
    private const KEPT_PARAMETERS = ['TZID', 'VALUE', 'RANGE', 'PARTSTAT', 'ROLE', 'CUTYPE', 'RSVP'];

    /**
     * The requester's part of one entry, as a whitelist of properties and of
     * their parameters: the times, the title and the place (other addresses in
     * them replaced), and the requester's own ATTENDEE or ORGANIZER line,
     * matched exactly by its decoded mailto address. Everything else
     * (description, notes, contacts, attachments, other attendees, UID,
     * extensions, every other parameter such as SENT-BY or X-*) stays out,
     * because it may be about other people.
     */
    public static function redact(string $ics, string $email, string $otherPerson = 'other person'): string
    {
        $email = mb_strtolower(trim($email), 'UTF-8');
        $unfolded = preg_replace('/\r?\n[ \t]/', '', $ics) ?? '';
        $lines = [];

        foreach (preg_split('/\r?\n/', $unfolded) ?: [] as $line) {
            // NAME *(;PARAM=value) : value, colons and semicolons inside quotes do not count.
            if (preg_match('/^([A-Za-z0-9-]+)((?:;[A-Za-z0-9-]+=(?:"[^"]*"|[^;:"]*)(?:,(?:"[^"]*"|[^;:",]*))*)*):(.*)$/s', $line, $m) !== 1) {
                continue;
            }

            $name = strtoupper($m[1]);
            $value = $m[3];
            preg_match_all('/;([A-Za-z0-9-]+)=((?:"[^"]*"|[^;:"]*)(?:,(?:"[^"]*"|[^;:",]*))*)/', $m[2], $params, PREG_SET_ORDER);

            if ($name === 'ATTENDEE' || $name === 'ORGANIZER') {
                if (self::address($value) !== $email) {
                    $lines[] = $name . ':' . $otherPerson;

                    continue;
                }
            } elseif (!\in_array($name, self::KEPT, true)) {
                continue;
            }

            // Every complete span around an «@» (also percent-encoded), up to the
            // next separator, is one mailbox: decoded and compared as a whole,
            // so neither encoding nor a matching suffix lets another one through.
            $redact = static fn (string $text): string => preg_replace_callback('/[^\s:;,<>"()\[\]]*(?:@|%40)[^\s:;,<>"()\[\]]*/iu',
                static fn (array $match): string => self::address($match[0]) === $email ? $match[0] : '[redacted]', $text) ?? '';
            $kept = '';

            foreach ($params as [, $param, $paramValue]) {
                // A whitelisted name does not make its value safe (TZID=someone@...).
                if (\in_array(strtoupper($param), self::KEPT_PARAMETERS, true)) {
                    $kept .= ';' . strtoupper($param) . '=' . $redact($paramValue);
                }
            }

            $lines[] = $name . $kept . ':' . $redact($value);
        }

        return implode("\r\n", $lines);
    }

    /**
     * Whether one more calendar fits into a request: a calendar whose entries
     * exceed MAX_CALENDAR_BYTES is never loaded, and the searched calendars
     * together stay below MAX_OBJECTS entries. Only calendars this session
     * can open are charged. Pure.
     */
    public static function fits(int $count, int $bytes, int $used, int $maxCalendarBytes = self::MAX_CALENDAR_BYTES,
        int $maxObjects = self::MAX_OBJECTS): bool
    {
        return $bytes <= $maxCalendarBytes && $used + $count <= $maxObjects;
    }

    /**
     * Calendar titles for a message, escaped: messages are rendered as HTML.
     *
     * @param   array<int, string>  $titles
     */
    private static function titles(array $titles): string
    {
        return implode(', ', array_map(static fn (string $title): string => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'), $titles));
    }

    /**
     * An address as compared: mailto: removed, percent-decoded, trimmed, lower case.
     */
    private static function address(string $value): string
    {
        return mb_strtolower(trim(rawurldecode((string) preg_replace('/^\s*mailto:/i', '', $value))), 'UTF-8');
    }

    /**
     * @return  Domain[]
     */
    public function export(string $email, ?User $user): array
    {
        if ($this->services === null) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_TEAM_F_PRIVACY_FAILED'), 'warning');

            return [$this->noteDomain('COM_APPOINTMENTS_TEAM_F_PRIVACY_FAILED')];
        }

        $domains = [];
        $counts = ['entries' => 0, 'not_searched' => 0, 'staff' => 0];

        // Entries and staff data are independent: a failure in one never hides the other.
        try {
            $search = $this->search($email, Text::_('COM_APPOINTMENTS_TEAM_F_PRIVACY_OTHER_PERSON'));
            $entries = $this->domain('appointments_calendar_entries', 'COM_APPOINTMENTS_TEAM_F_PRIVACY_ENTRIES');

            foreach ($search['matches'] as $match) {
                $item = new Item();
                $item->id = $match['id'];
                $this->field($item, 'calendar', $match['title']);
                $this->field($item, 'modified_utc', $match['modified_utc']);
                $this->field($item, 'content', $match['content']);
                $this->field($item, 'note', Text::_('COM_APPOINTMENTS_TEAM_F_PRIVACY_CONTENT_NOTE'));
                $entries->addItem($item);
            }

            $domains[] = $entries;
            $counts['entries'] = \count($search['matches']);
            $counts['not_searched'] = \count($search['unsearched']);

            if ($search['unsearched'] !== []) {
                // The export goes to the requester: a count, never the titles of
                // team calendars. The administrator sees the titles.
                $domains[] = $this->unsearchedDomain(\count($search['unsearched']));
                $this->app->enqueueMessage(Text::sprintf('COM_APPOINTMENTS_TEAM_F_PRIVACY_UNSEARCHED', self::titles($search['unsearched'])), 'warning');
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
        if ($this->services === null) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_TEAM_F_PRIVACY_FAILED'), 'warning');

            return;
        }

        $deleted = 0;
        $failed = 0;
        $unsearched = [];
        $staff = false;

        try {
            $search = $this->search($email, null);
            $unsearched = $search['unsearched'];

            foreach ($search['matches'] as $match) {
                if (microtime(true) > $this->deadline) {
                    // Out of time: what is left is reported, not dropped.
                    $unsearched[$match['calendar_id']] = $match['title'];

                    continue;
                }

                try {
                    // Only the version that was searched: a client may have
                    // replaced the entry since, which then fails instead of
                    // being deleted without a match.
                    $this->services->objects()->delete($match['calendar_id'], $match['uri'], $match['etag'], null);
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
                $this->services->cleanup()->onUserDeleted($userId);
                $staff = true;
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
            $this->app->enqueueMessage(Text::sprintf('COM_APPOINTMENTS_TEAM_F_PRIVACY_UNSEARCHED', self::titles($unsearched)), 'warning');
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
     * One calendar is in memory at a time, and a match keeps only what the
     * request needs: its identity and, for an export, the redacted content.
     *
     * @param   string|null  $otherPerson  The label for other attendees when exporting, null when deleting.
     *
     * @return  array{matches: array<int, array{id: int, calendar_id: int, uri: string, etag: string, modified_utc: string, title: string, content: string}>, unsearched: array<int, string>}
     */
    private function search(string $email, ?string $otherPerson): array
    {
        $address = mb_strtolower(trim($email), 'UTF-8');
        $db = $this->services->db();
        $calendars = $db->setQuery($this->query()->select($db->quoteName(['id', 'title']))
            ->from($db->quoteName('#__appointments_calendars'))->order($db->quoteName('id') . ' ASC'))->loadAssocList();
        $stats = $db->setQuery($this->query()->select([$db->quoteName('calendar_id'), 'COUNT(*) AS ' . $db->quoteName('objects'),
            'COALESCE(SUM(' . $db->quoteName('size') . '), 0) AS ' . $db->quoteName('bytes')])
            ->from($db->quoteName('#__appointments_calendar_objects'))->group($db->quoteName('calendar_id')))->loadAssocList('calendar_id') ?: [];
        // One deadline for the whole request: the removal after the search
        // stops at it too.
        $deadline = $this->deadline = microtime(true) + self::TIME_BUDGET_SECONDS;
        $used = 0;
        $exported = 0;
        $matches = [];
        $unsearched = [];
        $searched = 0;

        foreach ($calendars as $calendar) {
            $id = (int) $calendar['id'];
            $title = (string) $calendar['title'] !== '' ? (string) $calendar['title'] : '#' . $id;

            $count = (int) ($stats[$id]['objects'] ?? 0);
            $bytes = (int) ($stats[$id]['bytes'] ?? 0);

            // Beyond the limits of one request (too large, too many entries,
            // out of time): named as not searched, never a silent stop.
            if (microtime(true) > $deadline || !self::fits($count, $bytes, $used)) {
                $unsearched[$id] = $title;

                continue;
            }

            $keyring = $address === '' ? null : $this->keyring($id);

            if ($keyring === null) {
                // Not charged: a calendar this session cannot open uses none of the budget.
                $unsearched[$id] = $title;

                continue;
            }

            $used += $count;

            try {
                $objects = $this->services->objects()->all($id);

                foreach ($objects as $index => $object) {
                    if (microtime(true) > $deadline) {
                        $unsearched[$id] = $title;

                        break;
                    }

                    $ics = $this->services->keys()->decryptObject($keyring, $object);
                    unset($objects[$index]);

                    if ($ics === null) {
                        // One entry it cannot read: the calendar is not completely searched.
                        $unsearched[$id] = $title;

                        continue;
                    }

                    if (\in_array($address, $this->services->timing()->emails($ics), true)) {
                        $content = $otherPerson === null ? '' : self::redact($ics, $address, $otherPerson);
                        $exported += \strlen($content);

                        if ($exported > self::MAX_EXPORT_BYTES) {
                            // The export holds no more: this calendar counts as not searched.
                            $unsearched[$id] = $title;

                            break;
                        }

                        $matches[] = ['id' => $object->id, 'calendar_id' => $object->calendarId, 'uri' => $object->uri,
                            'etag' => $object->etag, 'modified_utc' => $object->modifiedUtc->format('Y-m-d H:i:s'), 'title' => $title,
                            'content' => $content];
                    }

                    unset($ics);
                }

                unset($objects);
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
            if ($own && (int) $row['actor_user_id'] !== $userId) {
                // Who acted on the person is staff accountability data, not theirs.
                unset($row['actor_user_id'], $row['actor_device_id']);
            }

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

    private function unsearchedDomain(int $count): Domain
    {
        $domain = $this->domain('appointments_calendar_entries_not_searched', 'COM_APPOINTMENTS_TEAM_F_PRIVACY_INCOMPLETE');
        $item = new Item();
        $item->id = 0;
        $this->field($item, 'calendars', (string) $count);
        $this->field($item, 'note', Text::_('COM_APPOINTMENTS_TEAM_F_PRIVACY_NOT_SEARCHED'));
        $domain->addItem($item);

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
