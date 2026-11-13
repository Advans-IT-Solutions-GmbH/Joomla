<?php
/**
 * @package     Appointments Privacy Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\Privacy\Appointments\Team;

use Advans\Component\Appointments\Administrator\Helper\PrivacyAddress;
use Advans\Component\Appointments\Administrator\Helper\Team\AuditEvent;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarKeyring;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Advans\Component\Appointments\Administrator\Service\Team\PrivacyReview;
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
 * acted show the event and the time. Removal deletes the matching booking copies as normal
 * changes. Entries of a calendar's own are never changed automatically: they are recorded for a
 * decision per entry ({@see PrivacyReview}), and an address of a team member or of another calendar
 * user changes no entry at all (counted for the administrator to review). For an account it deletes
 * the devices, keys, grants and activation links like a deleted account; owned
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
    private bool $staffRemoved = false;

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

    /** The components whose properties may be kept; anything inside another one is left out. */
    private const KEPT_COMPONENTS = ['VCALENDAR', 'VEVENT', 'VTIMEZONE', 'STANDARD', 'DAYLIGHT'];

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
        $stack = [];

        foreach (self::ownEvents(preg_split('/\r?\n/', $unfolded) ?: [], $email) as $line) {
            // Only the calendar, its events and their time zones: an alarm
            // (VALARM, with its own SUMMARY and DESCRIPTION) or any other
            // component is left out as a whole, with everything inside it.
            if (preg_match('/^(BEGIN|END):([A-Za-z0-9-]+)\s*$/i', $line, $component) === 1) {
                $kind = strtoupper($component[2]);

                if (strtoupper($component[1]) === 'BEGIN') {
                    $stack[] = $kind;
                }

                $inside = array_diff($stack, self::KEPT_COMPONENTS) === [];

                if (strtoupper($component[1]) === 'END') {
                    array_pop($stack);
                }

                if ($inside) {
                    $lines[] = strtoupper($component[1]) . ':' . $kind;
                }

                continue;
            }

            if (array_diff($stack, self::KEPT_COMPONENTS) !== []) {
                continue;
            }

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
            // An escaped line break inside a span separates two texts, so each
            // part is judged on its own and no address rides along with the
            // requester's.
            $redact = static fn (string $text): string => preg_replace_callback('/[^\s:;,<>"()\[\]]*(?:@|%40)[^\s:;,<>"()\[\]]*/iu',
                static fn (array $match): string => implode('', array_map(
                    static fn (string $part): string => preg_match('/@|%40/i', $part) === 1 && self::address($part) !== $email ? '[redacted]' : $part,
                    preg_split('/(\\\\[nN])/', $match[0], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [])), $text) ?? '';
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
     * Only the VEVENTs that mention the requester's address (in any property
     * or parameter): an object can hold a series and exceptions with other
     * people, and those events stay out as a whole. Lines outside events
     * (the calendar, time zones) pass.
     *
     * @param   string[]  $lines  Unfolded lines.
     *
     * @return  string[]
     */
    private static function ownEvents(array $lines, string $email): array
    {
        $kept = [];
        $block = null;
        $depth = 0;

        foreach ($lines as $line) {
            if ($block === null) {
                if (preg_match('/^BEGIN:VEVENT\s*$/i', $line) === 1) {
                    $block = [$line];
                    $depth = 1;
                } else {
                    $kept[] = $line;
                }

                continue;
            }

            $block[] = $line;

            if (preg_match('/^BEGIN:/i', $line) === 1) {
                $depth++;
            } elseif (preg_match('/^END:/i', $line) === 1 && --$depth === 0) {
                $mentioned = false;

                foreach ($block as $candidate) {
                    preg_match_all('/[^\s:;,<>"()\[\]]*(?:@|%40)[^\s:;,<>"()\[\]]*/iu', $candidate, $found);

                    foreach ($found[0] as $span) {
                        foreach (preg_split('/\\\\[nN]/', $span) ?: [$span] as $part) {
                            $mentioned = $mentioned || self::address($part) === $email;
                        }
                    }
                }

                if ($mentioned) {
                    array_push($kept, ...$block);
                }

                $block = null;
            }
        }

        return $kept;
    }

    /**
     * An address as compared: iCalendar TEXT escapes decoded (an escaped line
     * break before it ends the text before it), mailto: removed,
     * percent-decoded, trimmed, lower case.
     */
    private static function address(string $value): string
    {
        $value = str_replace(['\\,', '\\;', '\\\\'], [',', ';', '\\'], $value);
        $value = trim(rawurldecode((string) preg_replace('/^\s*mailto:/i', '', trim($value))));

        return mb_strtolower($value, 'UTF-8');
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

        // Core's database lookup may equate distinct accented mailbox addresses.
        $userId = $user !== null && (int) $user->id > 0
            && PrivacyAddress::matches((string) $user->email, $email) ? (int) $user->id : 0;

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

        $this->record(AuditEvent::PRIVACY_EXPORT, [
            'actor_user_id'   => $this->actorId(),
            'subject_user_id' => $userId > 0 ? $userId : null,
            'details'         => $counts,
        ]);

        return $domains;
    }

    /**
     * Delete the booking copies with this address and the staff data of the account. Entries of
     * a calendar's own are never changed here: they are recorded for the handling person, who
     * decides per entry (remove only the address, the default, or delete the entry) in «Privacy:
     * calendar entries»; until every recorded entry is decided, Joomla's Complete stays blocked
     * (PrivacyOutcome). An address of a team member or another calendar user changes no entry at
     * all. Never throws; messages are queued for the administrator; false means incomplete.
     *
     * @param   int|null   $staffUserId  The staff account retained for the request
     *                                   (PrivacyStaffCleanup); null falls back to $user.
     * @param   int        $requestId    The Joomla privacy request, recorded in the log row.
     * @param   bool|null  $teamMember   The team-member decision the removal check captured for
     *                                   the account (StaffPrivacy::capture()), null to decide now.
     */
    public function remove(string $email, ?User $user, ?int $staffUserId = null, int $requestId = 0, ?bool $teamMember = null): bool
    {
        $this->staffRemoved = false;
        if ($this->services === null) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_TEAM_F_PRIVACY_FAILED'), 'warning');

            return false;
        }

        $deleted = 0;
        $kept = 0;
        $review = [];
        $untouched = 0;
        $failed = 0;
        $unsearched = [];
        $staff = false;
        $userId = $staffUserId ?? ($user !== null ? (int) $user->id : 0);
        // The address of a team member is in many entries of the team: those are never changed automatically.
        $teamMember ??= $userId > 0 && !self::changesEntriesOf($this->audienceOf($userId));

        try {
            // Decided before anything else of the request changes the account.
            $calendarUser = PrivacyReview::calendarUser($this->services->db(), $email, $userId);
            $search = $this->search($email, null);
            $unsearched = $search['unsearched'];

            foreach ($search['matches'] as $match) {
                if ($teamMember) {
                    $kept++;

                    continue;
                }

                if ($calendarUser) {
                    // The address of a calendar user: nothing is changed automatically.
                    $untouched++;

                    continue;
                }

                if ($match['booking_id'] === null) {
                    // An entry of the calendar's own: only after a look at it.
                    $review[] = $match;

                    continue;
                }

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

        if ($userId > 0) {
            try {
                $db = $this->services->db();
                $db->setQuery($this->query()->delete($db->quoteName('#__appointments_invitations'))
                    ->where($db->quoteName('user_id') . ' = ' . $userId))->execute();
                $this->services->cleanup()->onUserDeleted($userId);
                $staff = true;
                $this->staffRemoved = true;
            } catch (\Throwable $e) {
                $failed++;
                Log::add('Team calendars: the privacy removal of staff data failed (' . \get_class($e) . ').', Log::WARNING, 'com_appointments');
            }
        }

        $this->app->enqueueMessage(Text::sprintf('COM_APPOINTMENTS_TEAM_F_PRIVACY_REMOVED', $deleted), 'message');

        if ($kept > 0) {
            $this->app->enqueueMessage(Text::sprintf('COM_APPOINTMENTS_TEAM_F_PRIVACY_TEAM_KEPT', $kept), 'warning');
        }

        if ($untouched > 0) {
            $this->app->enqueueMessage(Text::sprintf('COM_APPOINTMENTS_TEAM_F_PRIVACY_CALENDAR_USER', $untouched), 'warning');
        }

        if ($review !== []) {
            try {
                if ($requestId > 0) {
                    (new PrivacyReview($this->services))->record($requestId, $review, $this->services->now());
                }

                $this->app->enqueueMessage(Text::sprintf($requestId > 0 ? 'COM_APPOINTMENTS_TEAM_F_PRIVACY_REVIEW' : 'COM_APPOINTMENTS_TEAM_F_PRIVACY_REVIEW_NO_REQUEST',
                    \count($review)), 'warning');
            } catch (\Throwable $e) {
                $failed++;
                Log::add('Team calendars: the privacy review list could not be written (' . \get_class($e) . ').', Log::WARNING, 'com_appointments');
            }
        }

        if ($staff) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_TEAM_F_PRIVACY_STAFF_REMOVED'), 'message');
        }

        if ($unsearched !== []) {
            $this->app->enqueueMessage(Text::sprintf('COM_APPOINTMENTS_TEAM_F_PRIVACY_UNSEARCHED', self::titles($unsearched)), 'warning');
        }

        if ($failed > 0) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_TEAM_F_PRIVACY_FAILED'), 'warning');
        }

        $this->record(AuditEvent::PRIVACY_DELETE, [
            'actor_user_id'   => $this->actorId(),
            'subject_user_id' => $userId > 0 ? $userId : null,
            'details'         => ['entries' => $deleted, 'kept' => $kept, 'failed' => $failed, 'not_searched' => \count($unsearched), 'staff' => $staff,
                'request' => max(0, $requestId), 'team_member' => $teamMember, 'review' => \count($review), 'untouched' => $untouched],
        ]);

        return $failed === 0 && $unsearched === [];
    }

    public function staffRemoved(): bool
    {
        return $this->staffRemoved;
    }

    /**
     * Whether a removal request may change the calendar entries that hold the address of an
     * account with this audience. Never for a team member: their address is in many entries of
     * the team, which the administrator reviews instead. Pure.
     */
    public static function changesEntriesOf(string $audience): bool
    {
        return $audience !== TeamStates::AUDIENCE_TEAM;
    }

    /** The audience of an account; a check that cannot be made counts as team member, so nothing is changed. */
    private function audienceOf(int $userId): string
    {
        try {
            return $this->services->grants()->audienceOf($userId);
        } catch (\Throwable $e) {
            return TeamStates::AUDIENCE_TEAM;
        }
    }

    /**
     * Search every calendar the handling person can unlock in this session.
     * One calendar is in memory at a time, and a match keeps only what the
     * request needs: its identity and, for an export, the redacted content.
     *
     * @param   string|null  $otherPerson  The label for other attendees when exporting, null when deleting.
     *
     * @return  array{matches: array<int, array{id: int, calendar_id: int, uri: string, etag: string, modified_utc: string, title: string, content: string, booking_id: int|null}>, unsearched: array<int, string>}
     */
    private function search(string $email, ?string $otherPerson): array
    {
        $address = mb_strtolower(trim($email), 'UTF-8');
        $db = $this->services->db();
        $calendars = $db->setQuery($this->query()->select($db->quoteName(['id', 'title']))
            ->from($db->quoteName('#__appointments_calendars'))->order($db->quoteName('id') . ' ASC'))->loadAssocList();
        // The stored bytes themselves, not the declared size.
        $stats = $db->setQuery($this->query()->select([$db->quoteName('calendar_id'), 'COUNT(*) AS ' . $db->quoteName('objects'),
            'COALESCE(SUM(LENGTH(' . $db->quoteName('payload') . ') + LENGTH(' . $db->quoteName('timing') . ')), 0) AS ' . $db->quoteName('bytes')])
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
                $loaded = 0;

                foreach ($objects as $object) {
                    $loaded += \strlen($object->payload) + \strlen($object->timing);
                }

                // Written to between the count and the fetch: checked again on
                // what was really loaded, and dropped at once when too large.
                if (!self::fits(\count($objects), $loaded, $used - $count)) {
                    unset($objects);
                    $used -= $count;
                    $unsearched[$id] = $title;

                    continue;
                }

                // The budget holds what was really fetched, not the earlier count.
                $used += \count($objects) - $count;

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
                            'content' => $content, 'booking_id' => $object->bookingId];
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

        $this->record(AuditEvent::PRIVACY_SEARCH, [
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
                ['id', 'name', 'client', 'removal_mode', 'state', 'rule_version', 'rule_accepted_utc', 'created_utc', 'last_sync_utc',
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

    /**
     * Log a privacy step. A failing log row (a lock conflict is rethrown by
     * the audit service) never aborts the request or hides its result: the
     * administrator is told that the evidence is missing.
     *
     * @param   array<string, mixed>  $context
     */
    private function record(string $event, array $context): void
    {
        try {
            $this->services->audit()->log($event, $context);
        } catch (\Throwable $e) {
            Log::add('Team calendars: a privacy log row could not be written (' . \get_class($e) . ').', Log::WARNING, 'com_appointments');
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_TEAM_F_PRIVACY_FAILED'), 'warning');
        }
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
