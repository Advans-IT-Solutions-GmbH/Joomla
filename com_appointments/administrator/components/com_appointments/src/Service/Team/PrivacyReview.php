<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\Team\AuditEvent;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarKeyring;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * Entries of a calendar's own that a Joomla privacy request found: never changed
 * automatically. The removal records only where they are (request, calendar,
 * address of the object), never the requested address, a hash of it or content.
 * The person handling the request then sees each entry (calendar, start, title,
 * role of the address) in their own session and decides per entry: remove only
 * the address (the default), delete the entry, or leave it unchanged.
 *
 * The address comes from Joomla's privacy request (`#__privacy_requests.email`)
 * at the time of the decision. Opening and changing an entry needs the handling
 * person's own effective access with a key (read to see it, write to change it)
 * and the personal key unlocked in this session, exactly like the search.
 *
 * @since  1.5.0
 */
final class PrivacyReview
{
    public const TABLE = '#__appointments_privacy_review';

    public const ACTION_ADDRESS = 'address';
    public const ACTION_DELETE = 'delete';
    public const ACTION_KEEP = 'keep';

    public const ROLE_ORGANIZER = 'organizer';
    public const ROLE_ATTENDEE = 'attendee';
    public const ROLE_TEXT = 'text';

    public function __construct(private readonly TeamServices $services)
    {
    }

    /**
     * Whether an address belongs to a user of the internet calendars (a grant,
     * a device, a personal key, an owned calendar or an activation link, or a
     * row of the internet calendar log about the account, which outlives the
     * staff clean-up of a privacy request for twelve months): such an address
     * is never handled automatically by a privacy request, also not on a retry
     * after the account's devices, keys and grants were removed.
     */
    public static function calendarUser(DatabaseInterface $db, string $email, int $userId = 0): bool
    {
        $email = mb_strtolower(trim($email), 'UTF-8');
        // The request's account by its id as well: a privacy plugin that ran before may already
        // have replaced the account's address.
        $ids = $userId > 0 ? [$userId] : [];

        if ($email !== '') {
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $ids = array_merge($ids, array_map('intval', $db->setQuery($query->select($db->quoteName('id'))->from($db->quoteName('#__users'))
                ->where('LOWER(' . $db->quoteName('email') . ') = :email')->bind(':email', $email))->loadColumn() ?: []));
        }

        foreach (array_unique($ids) as $id) {
            foreach ([['#__appointments_calendar_grants', 'user_id'], ['#__appointments_devices', 'user_id'],
                ['#__appointments_user_keys', 'user_id'], ['#__appointments_calendars', 'owner_user_id'],
                ['#__appointments_invitations', 'user_id'], ['#__appointments_teamlog', 'actor_user_id'],
                ['#__appointments_teamlog', 'subject_user_id']] as [$table, $column]) {
                $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);

                $query->select('COUNT(*)')->from($db->quoteName($table))->where($db->quoteName($column) . ' = ' . $id);

                // The privacy steps themselves write log rows about any requester: they do not count.
                if ($table === '#__appointments_teamlog') {
                    $query->where($db->quoteName('event') . ' NOT LIKE ' . $db->quote('privacy.%'));
                }

                if ((int) $db->setQuery($query)->loadResult() > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Record entries of a calendar's own for the decision. An entry already
     * recorded for the request stays as it is.
     *
     * @param   array<int, array{calendar_id: int, uri: string}>  $matches
     *
     * @return  int  Entries newly recorded.
     */
    public function record(int $requestId, array $matches, \DateTimeImmutable $now): int
    {
        if ($requestId < 1) {
            return 0;
        }

        $db = $this->services->db();
        $time = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $added = 0;

        foreach ($matches as $match) {
            $db->setQuery('INSERT IGNORE INTO ' . $db->quoteName(self::TABLE)
                . ' (' . implode(', ', $db->quoteName(['request_id', 'calendar_id', 'uri', 'created_utc'])) . ')'
                . ' VALUES (' . $requestId . ', ' . (int) $match['calendar_id'] . ', ' . $db->quote((string) $match['uri']) . ', ' . $db->quote($time) . ')')
                ->execute();
            $added += $db->getAffectedRows() > 0 ? 1 : 0;
        }

        return $added;
    }

    /** Entries waiting for a decision. */
    public function count(): int
    {
        $db = $this->services->db();

        return (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName(self::TABLE))->loadResult();
    }

    /**
     * The entries waiting for a decision, oldest request first, each opened in
     * the session of `$actor` where possible. An entry that cannot be opened is
     * listed without content (`readable` false), never skipped.
     *
     * `etag` is the version shown: a decision applies only to that version.
     *
     * @return  array<int, array{id: int, request_id: int, request_found: bool, calendar_id: int, calendar: string, readable: bool, writable: bool, etag: string, start: string, summary: string, roles: string[]}>
     */
    public function pending(int $actor, int $limit = 100, int $offset = 0): array
    {
        $db = $this->services->db();
        $query = $this->query()
            ->select($db->quoteName(['r.id', 'r.request_id', 'r.calendar_id', 'r.uri', 'c.title']))
            ->select($db->quoteName('q.email', 'email'))
            ->from($db->quoteName(self::TABLE, 'r'))
            ->join('LEFT', $db->quoteName('#__appointments_calendars', 'c'), $db->quoteName('c.id') . ' = ' . $db->quoteName('r.calendar_id'))
            ->join('LEFT', $db->quoteName('#__privacy_requests', 'q'), $db->quoteName('q.id') . ' = ' . $db->quoteName('r.request_id'))
            ->order($db->quoteName('r.request_id') . ' ASC')
            ->order($db->quoteName('r.id') . ' ASC')
            ->setLimit(max(1, min(500, $limit)), max(0, $offset));
        $rows = [];
        $keyrings = [];

        try {
            foreach ($db->setQuery($query)->loadAssocList() ?: [] as $row) {
                $calendarId = (int) $row['calendar_id'];
                $email = (string) ($row['email'] ?? '');
                $item = ['id' => (int) $row['id'], 'request_id' => (int) $row['request_id'], 'request_found' => $email !== '',
                    'calendar_id' => $calendarId, 'calendar' => (string) ($row['title'] ?? ''), 'readable' => false, 'writable' => false,
                    'etag' => '', 'start' => '', 'summary' => '', 'roles' => []];

                if (!\array_key_exists($calendarId, $keyrings)) {
                    $keyrings[$calendarId] = $this->keyring($actor, $calendarId, false);
                }

                [$keyring, $writable] = $keyrings[$calendarId] ?? [null, false];
                $object = $keyring !== null ? $this->services->objects()->get($calendarId, (string) $row['uri']) : null;
                $ics = $object !== null ? $this->services->keys()->decryptObject($keyring, $object) : null;

                if ($ics !== null) {
                    $item = array_replace($item, ['readable' => true, 'writable' => $writable, 'etag' => $object->etag] + self::overview($ics),
                        ['roles' => $email !== '' ? self::roles($ics, $email) : []]);
                }

                $rows[] = $item;
            }
        } finally {
            foreach ($keyrings as $entry) {
                if ($entry !== null) {
                    $entry[0]->wipe();
                }
            }
        }

        return $rows;
    }

    /**
     * Carry out one decision on the version the handler saw (`$etag`, from
     * {@see self::pending()}). Returns a language key describing the result; on
     * success the entry leaves the list.
     */
    public function apply(int $id, string $action, int $actor, string $placeholder, string $etag = ''): string
    {
        if (!\in_array($action, [self::ACTION_ADDRESS, self::ACTION_DELETE, self::ACTION_KEEP], true)) {
            return 'COM_APPOINTMENTS_PRIVACY_REVIEW_ERROR';
        }

        $db = $this->services->db();
        $row = $db->setQuery($this->query()
            ->select($db->quoteName(['r.id', 'r.calendar_id', 'r.uri']))
            ->select($db->quoteName('q.email', 'email'))
            ->from($db->quoteName(self::TABLE, 'r'))
            ->join('LEFT', $db->quoteName('#__privacy_requests', 'q'), $db->quoteName('q.id') . ' = ' . $db->quoteName('r.request_id'))
            ->where($db->quoteName('r.id') . ' = ' . $id))->loadAssoc();

        if (!$row) {
            return 'COM_APPOINTMENTS_PRIVACY_REVIEW_GONE';
        }

        $calendarId = (int) $row['calendar_id'];
        $uri = (string) $row['uri'];

        if ($action === self::ACTION_KEEP) {
            $this->forget($id);
            $this->log($actor, $calendarId, $action);

            return 'COM_APPOINTMENTS_PRIVACY_REVIEW_KEPT';
        }

        $email = mb_strtolower(trim((string) ($row['email'] ?? '')), 'UTF-8');

        // Removing the address needs it, deleting the entry does not.
        if ($action === self::ACTION_ADDRESS && $email === '') {
            return 'COM_APPOINTMENTS_PRIVACY_REVIEW_REQUEST_MISSING';
        }

        [$keyring, $writable] = $this->keyring($actor, $calendarId, true) ?? [null, false];

        if ($keyring === null || !$writable) {
            $keyring?->wipe();

            return 'COM_APPOINTMENTS_PRIVACY_REVIEW_NO_ACCESS';
        }

        try {
            $object = $this->services->objects()->get($calendarId, $uri);

            if ($object === null) {
                // Deleted in the meantime, for example on a device: nothing left to decide.
                $this->forget($id);

                return 'COM_APPOINTMENTS_PRIVACY_REVIEW_GONE';
            }

            // A device changed the entry since the page was opened: decided again on the new version.
            if ($etag === '' || !hash_equals($object->etag, $etag)) {
                return 'COM_APPOINTMENTS_PRIVACY_REVIEW_CHANGED';
            }

            // Checked again right before the change, so an access revoked while the page was
            // open never writes (the same contract as a CalDAV write).
            $access = $this->services->grants()->accessFor($actor, $calendarId);

            if ($access === null || !$access->readsContent() || !$access->role->canWrite()) {
                return 'COM_APPOINTMENTS_PRIVACY_REVIEW_NO_ACCESS';
            }

            if ($action === self::ACTION_DELETE) {
                $this->services->objects()->delete($calendarId, $uri, $etag, null);
            } else {
                $ics = $this->services->keys()->decryptObject($keyring, $object);

                if ($ics === null) {
                    return 'COM_APPOINTMENTS_PRIVACY_REVIEW_NO_ACCESS';
                }

                $changed = self::withoutAddress($ics, $email, $placeholder);

                // Written only when the address is really gone: as the search sees it, and in
                // every property and parameter (a second pass would change nothing).
                if (\in_array($email, $this->services->timing()->emails($changed), true)
                    || self::withoutAddress($changed, $email, $placeholder) !== $changed) {
                    return 'COM_APPOINTMENTS_PRIVACY_REVIEW_NOT_REMOVABLE';
                }

                if ($changed !== $ics) {
                    $this->services->objects()->put($keyring, $uri, $changed, $etag, false, null);
                }
            }
        } catch (\Throwable $e) {
            return 'COM_APPOINTMENTS_PRIVACY_REVIEW_ERROR';
        } finally {
            $keyring->wipe();
        }

        $this->forget($id);
        $this->log($actor, $calendarId, $action);

        return $action === self::ACTION_DELETE ? 'COM_APPOINTMENTS_PRIVACY_REVIEW_DELETED' : 'COM_APPOINTMENTS_PRIVACY_REVIEW_ADDRESS_REMOVED';
    }

    /**
     * The entry without one address: an ATTENDEE or ORGANIZER line with that
     * address goes, in every component (alarms included), and every other
     * occurrence of the address in a line (title, place, description,
     * parameters) is replaced by the placeholder. Everything else stays as it
     * is. Lines are read unfolded and folded again at 75 octets. Pure.
     */
    public static function withoutAddress(string $ics, string $email, string $placeholder): string
    {
        $email = mb_strtolower(trim($email), 'UTF-8');

        if ($email === '' || !str_contains($email, '@')) {
            return $ics;
        }

        // Inserted literally by a callback, so only the iCalendar escaping applies.
        $replacement = self::text($placeholder);
        $lines = [];

        foreach (preg_split('/\r?\n/', (string) preg_replace('/\r?\n[ \t]/', '', $ics)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            if (preg_match('/^(ATTENDEE|ORGANIZER)(?:;(?:[^:"]|"[^"]*")*)?:(.*)$/is', $line, $match) === 1
                && self::address($match[2]) === $email) {
                continue;
            }

            $lines[] = self::replaceInLine($line, $email, $replacement);
        }

        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    /**
     * Where the address appears in the entry: as organiser, as attendee, in the
     * text. Pure.
     *
     * @return  string[]
     */
    public static function roles(string $ics, string $email): array
    {
        $email = mb_strtolower(trim($email), 'UTF-8');
        $roles = [];

        foreach (preg_split('/\r?\n/', (string) preg_replace('/\r?\n[ \t]/', '', $ics)) ?: [] as $line) {
            if (preg_match('/^(ATTENDEE|ORGANIZER)(?:;(?:[^:"]|"[^"]*")*)?:(.*)$/is', $line, $match) === 1
                && self::address($match[2]) === $email) {
                $roles[strtoupper($match[1]) === 'ORGANIZER' ? self::ROLE_ORGANIZER : self::ROLE_ATTENDEE] = true;
            } elseif ($email !== '' && str_contains($email, '@') && self::replaceInLine($line, $email, '') !== $line) {
                $roles[self::ROLE_TEXT] = true;
            }
        }

        return array_keys($roles);
    }

    /**
     * Title and start of the first event, as written (no time zone conversion). Pure.
     *
     * @return  array{summary: string, start: string}
     */
    public static function overview(string $ics): array
    {
        $summary = '';
        $start = '';
        $inEvent = false;

        foreach (preg_split('/\r?\n/', (string) preg_replace('/\r?\n[ \t]/', '', $ics)) ?: [] as $line) {
            if (preg_match('/^BEGIN:VEVENT\s*$/i', $line) === 1) {
                $inEvent = true;
            } elseif (preg_match('/^BEGIN:/i', $line) === 1 && $inEvent) {
                // An alarm or another component inside the event: its lines are not the event's.
                break;
            } elseif ($inEvent && $summary === '' && preg_match('/^SUMMARY(?:;[^:]*)?:(.*)$/i', $line, $match) === 1) {
                $summary = str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], [' ', ' ', ',', ';', '\\'], $match[1]);
            } elseif ($inEvent && $start === '' && preg_match('/^DTSTART(?:;[^:]*)?:(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2}))?/i', $line, $match) === 1) {
                $start = $match[1] . '-' . $match[2] . '-' . $match[3] . (isset($match[4]) && $match[4] !== '' ? ' ' . $match[4] . ':' . $match[5] : '');
            } elseif (preg_match('/^END:VEVENT\s*$/i', $line) === 1) {
                break;
            }
        }

        return ['summary' => $summary, 'start' => $start];
    }

    /**
     * One content line with the address replaced in its parameter values (each value on its
     * own, so `CN=anna@…` is not read as the address `CN=anna@…`) and in its value. A quoted
     * parameter value stays quoted; an unquoted one is quoted when the replacement needs it.
     */
    private static function replaceInLine(string $line, string $email, string $replacement): string
    {
        if (preg_match('/^([A-Za-z0-9-]+)((?:;(?:[^:"]|"[^"]*")*)?):(.*)$/s', $line, $parts) !== 1) {
            return self::replaceAddress($line, $email, $replacement);
        }

        $params = preg_replace_callback('/([=,])("[^"]*"|[^;:,"]*)/', static function (array $item) use ($email, $replacement): string {
            if (str_starts_with($item[2], '"')) {
                return $item[1] . '"' . self::replaceAddress(substr($item[2], 1, -1), $email, $replacement) . '"';
            }

            $value = self::replaceAddress($item[2], $email, $replacement);

            return $item[1] . ($value !== $item[2] && preg_match('/[;:,]/', $value) === 1 ? '"' . $value . '"' : $value);
        }, $parts[2]);

        return $parts[1] . ($params ?? $parts[2]) . ':' . self::replaceAddress($parts[3], $email, $replacement);
    }

    /**
     * Every complete address in a text that is the requested one, replaced. Addresses are
     * found with the character rules of the timing index's text search, also with a
     * percent-encoded «@», and compared as a whole: another address that merely contains
     * the requested one (`x!anna@…`, `anna@….com`) stays untouched. A line that is not
     * valid UTF-8 stays as it is (the final check then refuses the change).
     */
    private static function replaceAddress(string $line, string $email, string $replacement): string
    {
        // A character of a domain label may also be percent-encoded, and so may a dot.
        $char = '(?:[\p{L}\p{N}]|%[0-9A-Fa-f]{2})';
        $label = $char . '(?:(?:[\p{L}\p{N}-]|%[0-9A-Fa-f]{2}){0,61}' . $char . ')?';
        $pattern = '/[\p{L}\p{N}.!#$%&\'*+\/=?^_`{|}~-]{1,192}(?:@|%40)' . $label . '(?:(?:\.|%2[Ee])' . $label . ')+/iu';
        $result = preg_replace_callback($pattern, static function (array $match) use ($email, $replacement, $line): string {
            [$text, $offset] = $match[0];
            $found = rtrim(ltrim($text, '.'), '.');
            $lead = substr($text, 0, strpos($text, $found));
            $tail = substr($text, \strlen($lead) + \strlen($found));
            $inUri = false;
            // Query syntax counts only inside a URI (after a scheme such as mailto: or https:, without a space in
            // between): in an address itself ? = & are ordinary mailbox characters.
            $uri = preg_match('~(?<![A-Za-z0-9+.-])[A-Za-z][A-Za-z0-9+.-]*:\S*$~', substr($line, 0, $offset)) === 1;

            // An escaped line break (\n, \N) before the address is TEXT, not part of it.
            // Only an odd number of backslashes is an escape: \\\\n is a backslash and the letter n.
            $slashes = \strlen($line) - \strlen(rtrim(substr($line, 0, $offset), '\\')) - (\strlen($line) - $offset);
            if ($lead === '' && $slashes % 2 === 1 && preg_match('/^[nN]/', $found) === 1) {
                $lead = $found[0];
                $found = substr($found, 1);
            }

            // A value in a URI query (?cc=…, &to=…) is the address, not the key before it.
            if ($uri && mb_strtolower(rawurldecode($found), 'UTF-8') !== $email
                && preg_match('/^(.*[?&][^=?&]*=)(.+)$/s', $found, $query) === 1) {
                $lead .= $query[1];
                $found = $query[2];
                $inUri = true;
            }

            if (mb_strtolower(rawurldecode($found), 'UTF-8') !== $email) {
                // Percent-encoded text around the address (%20, %26 …): decoded, the address is
                // replaced as a whole word there and the rest is encoded again.
                // The decoded text is split at query separators (?, &) and keys (key=), and the
                // address must stand as a whole word with the full local-part character set
                // around it, so another address such as x!anna@… is never touched.
                if (!str_contains($found, '%')) {
                    return $text;
                }

                $word = '/(?<![\p{L}\p{N}.!#$%&\'*+\/=?^_`{|}~\-])' . preg_quote($email, '/') . '(?![\p{L}\p{N}\-]|\.[\p{L}\p{N}])/iu';
                $hit = false;
                // The query is split at its literal separators (?, &, =) while still encoded, so
                // an encoded %3F or %3D belongs to a mailbox, never to the query syntax. Only a
                // value that contains the address is decoded, changed and encoded again.
                $rebuilt = (string) preg_replace_callback($uri ? '/([?&]?)([^?&]*)/' : '/()(.+)/s', static function (array $part) use ($word, $replacement, $uri, &$hit): string {
                    $at = $uri ? strpos($part[2], '=') : false;
                    $key = $at === false ? '' : substr($part[2], 0, $at + 1);
                    $value = $at === false ? $part[2] : substr($part[2], $at + 1);
                    $changed = (string) preg_replace($word, $replacement, rawurldecode($value), -1, $count);

                    if ($count === 0) {
                        return $part[0];
                    }

                    $hit = true;

                    return $part[1] . $key . rawurlencode($changed);
                }, $found);

                return $hit ? $lead . $rebuilt . $tail : $text;
            }

            // Inside a URI the placeholder is percent-encoded, so the URI stays valid.
            return $lead . ($inUri ? rawurlencode($replacement) : $replacement) . $tail;
        }, $line, -1, $count, PREG_OFFSET_CAPTURE);

        return $result ?? $line;
    }

    /** An address as compared: mailto: and a query removed, percent-decoded, lower case. */
    private static function address(string $value): string
    {
        $value = trim(str_replace(['\\,', '\\;', '\\\\'], [',', ';', '\\'], $value));
        $value = (string) preg_replace('/^mailto:/i', '', $value);

        return mb_strtolower(trim(rawurldecode(explode('?', $value, 2)[0])), 'UTF-8');
    }

    /** A placeholder as iCalendar TEXT. */
    private static function text(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r", "\n"], ['\\\\', '\\;', '\\,', '', ''], $value);
    }

    /** Folds one line at 75 octets without splitting a UTF-8 character (RFC 5545, 3.1). */
    private static function fold(string $line): string
    {
        $out = '';
        $width = 75;

        while (\strlen($line) > $width) {
            $cut = $width;

            while ($cut > 1 && (\ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }

            $out .= substr($line, 0, $cut) . "\r\n ";
            $line = substr($line, $cut);
            $width = 74;
        }

        return $out . $line;
    }

    /**
     * The actor's keyring for a calendar with whether they may write, or null.
     *
     * @return  array{0: CalendarKeyring, 1: bool}|null
     */
    private function keyring(int $actor, int $calendarId, bool $write): ?array
    {
        try {
            $keys = $this->services->keySession()->current();

            if ($keys === null || $actor < 1) {
                return null;
            }

            $access = $this->services->grants()->accessFor($actor, $calendarId);

            if ($access === null || !$access->readsContent() || ($write && !$access->role->canWrite())) {
                return null;
            }

            $keyring = $this->services->keys()->openKeyring($calendarId, $keys);

            return $keyring === null ? null : [$keyring, $access->role->canWrite()];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function forget(int $id): void
    {
        $db = $this->services->db();
        $db->setQuery('DELETE FROM ' . $db->quoteName(self::TABLE) . ' WHERE ' . $db->quoteName('id') . ' = ' . $id)->execute();
    }

    private function log(int $actor, int $calendarId, string $action): void
    {
        try {
            $this->services->audit()->log(AuditEvent::PRIVACY_DELETE, ['actor_user_id' => $actor > 0 ? $actor : null,
                'calendar_id' => $calendarId, 'details' => ['review' => $action]]);
        } catch (\Throwable $e) {
            // The decision is carried out; a missing log row must not undo it.
        }
    }

    private function query()
    {
        $db = $this->services->db();

        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
