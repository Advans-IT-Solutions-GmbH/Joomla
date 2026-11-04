<?php
/**
 * @package     Appointments Privacy Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\Privacy\Appointments\Team;

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
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
 * Calendar entries are found through the blind index of their e-mail addresses
 * (`#__appointments_calendar_object_emails`, key(PURPOSE_EMAIL_INDEX)), so no user key is needed
 * to find or delete them. The export contains an entry's content only when the person handling
 * the request holds an effective, key-holding access to its calendar and has unlocked their key
 * in this session; otherwise it lists the calendar and the start from the time skeleton, with a
 * note. Calendars that could not be searched completely (entries without an index) or not be
 * decrypted are named in their own domain: the result is never silently incomplete.
 *
 * Staff data of an account (devices, grants, activation links, log rows) is exported without any
 * hash, salt or key. Removal deletes the found entries and, for an account, its devices, keys,
 * grants and activation links like a deleted account; owned calendars keep their contents with
 * owner 0. Log rows stay for their retention of twelve months, because they are the evidence of
 * who did what; the export lists them.
 *
 * Both requests are logged (`privacy.export`, `privacy.delete`) with counts only; the address
 * appears there only as its keyed hash.
 *
 * The plugin's event handlers belong to the booking page's privacy part; they call
 * {@see self::export()} and {@see self::remove()}.
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
     * Keyed hash of an address in the blind index. The same normalisation as
     * the store: trimmed, lower case.
     */
    public static function emailHash(string $key, string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email), 'UTF-8'), $key, true);
    }

    /**
     * @return  Domain[]
     */
    public function export(string $email, ?User $user): array
    {
        $domains = [];
        $counts = ['entries' => 0, 'content' => 0, 'incomplete' => 0, 'staff' => 0];

        try {
            [$entries, $incomplete] = $this->entryDomains($email);
            $domains[] = $entries;
            $counts['entries'] = \count($entries->getItems());
            $counts['content'] = \count(array_filter($entries->getItems(), static fn (Item $item): bool => self::hasField($item, 'content')));

            if ($incomplete !== null) {
                $domains[] = $incomplete;
                $counts['incomplete'] = \count($incomplete->getItems());
            }

            $userId = $user !== null ? (int) $user->id : 0;

            if ($userId > 0) {
                foreach ($this->staffDomains($userId) as $domain) {
                    $domains[] = $domain;
                    $counts['staff'] += \count($domain->getItems());
                }
            }
        } catch (\Throwable $e) {
            Log::add('Team calendars: the privacy export failed (' . \get_class($e) . ').', Log::WARNING, 'com_appointments');
            $domains[] = $this->noteDomain('COM_APPOINTMENTS_TEAM_F_PRIVACY_FAILED');
        }

        $this->services->audit()->log(AuditEvent::PRIVACY_EXPORT, [
            'actor_user_id'   => $this->actorId(),
            'subject_user_id' => $user !== null ? (int) $user->id : null,
            'details'         => $counts + ['email_hash' => $this->emailHashHex($email)],
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
            $hash = $this->emailHashRaw($email);

            if ($hash !== null) {
                foreach ($this->services->objects()->findByEmailHash($hash) as $object) {
                    try {
                        $this->services->objects()->delete($object->calendarId, $object->uri, null, null);
                        $deleted++;
                    } catch (\Throwable $e) {
                        $failed++;
                    }
                }
            }

            $unsearched = $this->calendarTitles($this->services->objects()->calendarsWithUnindexedObjects());
            $userId = $user !== null ? (int) $user->id : 0;

            if ($userId > 0) {
                $this->services->cleanup()->onUserDeleted($userId);
                $db = $this->services->db();
                $db->setQuery($this->query()->delete($db->quoteName('#__appointments_invitations'))
                    ->where($db->quoteName('user_id') . ' = ' . $userId))->execute();
                $staff = true;
            }
        } catch (\Throwable $e) {
            $failed++;
            Log::add('Team calendars: the privacy removal failed (' . \get_class($e) . ').', Log::WARNING, 'com_appointments');
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
            'subject_user_id' => $user !== null ? (int) $user->id : null,
            'details'         => [
                'entries'    => $deleted,
                'failed'     => $failed,
                'unsearched' => \count($unsearched),
                'staff'      => $staff,
                'email_hash' => $this->emailHashHex($email),
            ],
        ]);
    }

    /**
     * @return  array{0: Domain, 1: Domain|null}  The entries and, if any, the calendars that were not searched or not decrypted.
     */
    private function entryDomains(string $email): array
    {
        $entries = $this->domain('appointments_calendar_entries', 'COM_APPOINTMENTS_TEAM_F_PRIVACY_ENTRIES');
        $hash = $this->emailHashRaw($email);
        $objects = $hash === null ? [] : $this->services->objects()->findByEmailHash($hash);
        $titles = $this->calendarTitles(array_map(static fn (StoredObject $object): int => $object->calendarId, $objects));
        $keyrings = [];
        $undecrypted = [];

        foreach ($objects as $object) {
            $item = new Item();
            $item->id = $object->id;
            $this->field($item, 'calendar', $titles[$object->calendarId] ?? '#' . $object->calendarId);
            $this->field($item, 'modified_utc', $object->modifiedUtc->format('Y-m-d H:i:s'));
            $keyrings[$object->calendarId] ??= $this->keyring($object->calendarId);
            $content = $keyrings[$object->calendarId] !== null
                ? $this->services->keys()->decryptObject($keyrings[$object->calendarId], $object) : null;

            if ($content !== null) {
                $this->field($item, 'content', $content);
            } else {
                $undecrypted[$object->calendarId] = true;
                $this->field($item, 'start', $this->start($object));
                $this->field($item, 'note', Text::_('COM_APPOINTMENTS_TEAM_F_PRIVACY_METADATA_ONLY'));
            }

            $entries->addItem($item);
        }

        foreach ($keyrings as $keyring) {
            if ($keyring !== null) {
                $keyring->wipe();
            }
        }

        $unsearched = $this->calendarTitles($this->services->objects()->calendarsWithUnindexedObjects());
        $undecrypted = array_intersect_key($titles, $undecrypted);

        if ($unsearched === [] && $undecrypted === []) {
            return [$entries, null];
        }

        $incomplete = $this->domain('appointments_calendar_entries_incomplete', 'COM_APPOINTMENTS_TEAM_F_PRIVACY_INCOMPLETE');

        foreach ([[$unsearched, 'COM_APPOINTMENTS_TEAM_F_PRIVACY_NOT_SEARCHED'], [$undecrypted, 'COM_APPOINTMENTS_TEAM_F_PRIVACY_NOT_DECRYPTED']] as [$calendars, $reason]) {
            foreach ($calendars as $id => $title) {
                $item = new Item();
                $item->id = $id;
                $this->field($item, 'calendar', $title);
                $this->field($item, 'reason', Text::_($reason));
                $incomplete->addItem($item);
            }
        }

        return [$entries, $incomplete];
    }

    /**
     * @return  Domain[]
     */
    private function staffDomains(int $userId): array
    {
        $db = $this->services->db();
        $sets = [
            ['appointments_staff_devices', 'COM_APPOINTMENTS_TEAM_F_PRIVACY_DEVICES', '#__appointments_devices', 'user_id',
                ['id', 'name', 'client', 'state', 'rule_version', 'rule_accepted_utc', 'created_utc', 'last_sync_utc',
                    'cleanup_started_utc', 'revoked_utc', 'revoke_reason']],
            ['appointments_staff_grants', 'COM_APPOINTMENTS_TEAM_F_PRIVACY_GRANTS', '#__appointments_calendar_grants', 'user_id',
                ['id', 'calendar_id', 'role', 'state', 'audience', 'granted_by', 'created_utc', 'modified_utc']],
            ['appointments_staff_activations', 'COM_APPOINTMENTS_TEAM_F_PRIVACY_ACTIVATIONS', '#__appointments_invitations', 'user_id',
                ['id', 'state', 'invited_by', 'created_utc', 'expires_utc', 'completed_utc']],
        ];
        $domains = [];

        foreach ($sets as [$name, $label, $table, $column, $fields]) {
            $domain = $this->domain($name, $label);
            $rows = $db->setQuery($this->query()->select($db->quoteName($fields))->from($db->quoteName($table))
                ->where($db->quoteName($column) . ' = ' . $userId)->order($db->quoteName('id') . ' ASC'))->loadAssocList();

            foreach ($rows as $row) {
                $domain->addItem($this->item($row));
            }

            $domains[] = $domain;
        }

        // The log rows about this person, as actor or as subject: no address hash, no chain hashes.
        $domain = $this->domain('appointments_staff_log', 'COM_APPOINTMENTS_TEAM_F_PRIVACY_LOG');
        $rows = $db->setQuery($this->query()->select($db->quoteName(['id', 'occurred_utc', 'event', 'actor_user_id',
            'actor_device_id', 'subject_user_id', 'calendar_id', 'object_ref', 'details']))
            ->from($db->quoteName('#__appointments_teamlog'))
            ->where('(' . $db->quoteName('actor_user_id') . ' = ' . $userId . ' OR ' . $db->quoteName('subject_user_id') . ' = ' . $userId . ')')
            ->order($db->quoteName('id') . ' ASC')->setLimit(self::LOG_ROWS))->loadAssocList();

        foreach ($rows as $row) {
            $domain->addItem($this->item($row));
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

    private function start(StoredObject $object): string
    {
        try {
            $skeleton = $this->services->keys()->openTiming($object->calendarId, $object->uri, $object->timing);
        } catch (\Throwable $e) {
            $skeleton = null;
        }

        if ($skeleton !== null && preg_match('/^DTSTART[^:\r\n]*:([0-9TZ]{8,16})\r?$/m', $skeleton, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    /**
     * @param   int[]  $ids
     *
     * @return  array<int, string>  Title by calendar id.
     */
    private function calendarTitles(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $db = $this->services->db();
        $rows = $db->setQuery($this->query()->select($db->quoteName(['id', 'title']))->from($db->quoteName('#__appointments_calendars'))
            ->where($db->quoteName('id') . ' IN (' . implode(',', $ids) . ')'))->loadAssocList('id');
        $titles = [];

        foreach ($ids as $id) {
            $titles[$id] = isset($rows[$id]) && (string) $rows[$id]['title'] !== '' ? (string) $rows[$id]['title'] : '#' . $id;
        }

        return $titles;
    }

    private function emailHashRaw(string $email): ?string
    {
        if (trim($email) === '') {
            return null;
        }

        return self::emailHash($this->services->keyProvider()->key(KeyProvider::PURPOSE_EMAIL_INDEX), $email);
    }

    private function emailHashHex(string $email): ?string
    {
        $hash = $this->emailHashRaw($email);

        return $hash === null ? null : bin2hex($hash);
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
        $domain = $this->domain('appointments_calendar_entries_incomplete', 'COM_APPOINTMENTS_TEAM_F_PRIVACY_INCOMPLETE');
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

    private static function hasField(Item $item, string $name): bool
    {
        foreach ($item->getFields() as $field) {
            if ($field->name === $name) {
                return true;
            }
        }

        return false;
    }

    private function query(): QueryInterface
    {
        $db = $this->services->db();

        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
