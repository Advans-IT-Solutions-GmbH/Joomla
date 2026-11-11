<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\DefaultTemplates;
use Advans\Component\Appointments\Administrator\Helper\MailHtml;
use Advans\Component\Appointments\Administrator\Helper\MailLanguage;
use Advans\Component\Appointments\Administrator\Helper\MenuViews;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Table\TemplateTable;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Application\ConsoleApplication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

defined('_JEXEC') or die;

/**
 * Mails of the internet calendars. Contract: {@see TeamMailerInterface}.
 *
 * Every mail is a DB template of `#__appointments_templates` (event type 0, the
 * purposes of {@see DefaultTemplates::TEAM_KEYS}), editable in the backend like
 * the booking mails, in the recipient's account language, else the site's default.
 * Without a published template the default text of the language file applies:
 * these mails are security notices and always go out. They carry no calendar
 * entry, no app password, no key and no calendar content; the only secret is the
 * one-time link of an invitation. A failed delivery is logged without address.
 *
 * @since  1.4.0
 */
final class TeamMailer implements TeamMailerInterface
{
    /** Reasons of {@see accessSuspended()} with their text; any other reason gets the general text. */
    public const REASONS = [
        'password_reset' => 'COM_APPOINTMENTS_TEAM_D_MAIL_REASON_PASSWORD_RESET',
        'blocked' => 'COM_APPOINTMENTS_TEAM_D_MAIL_REASON_BLOCKED',
        'admin' => 'COM_APPOINTMENTS_TEAM_D_MAIL_REASON_ADMIN',
    ];

    /** Role names by the stored role number, for a grant mail whose role key is a number. */
    public const ROLE_LABELS = [
        1 => 'COM_APPOINTMENTS_TEAM_D_ROLE_FREEBUSY',
        2 => 'COM_APPOINTMENTS_TEAM_D_ROLE_READ',
        3 => 'COM_APPOINTMENTS_TEAM_D_ROLE_WRITE',
        4 => 'COM_APPOINTMENTS_TEAM_D_ROLE_MANAGE',
    ];

    /** Address of «My calendars» (myCalendarsUrl()), null until first needed. */
    private ?string $myCalendarsUrl = null;

    public function __construct(private readonly TeamServices $services)
    {
    }

    public function deviceAdded(int $userId, string $deviceName, string $client, \DateTimeImmutable $createdUtc): void
    {
        $user = $this->user($userId);

        if ($user === null) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_MAIL', 503);
        }

        $this->deliver('team_device_added', [$user], function (Language $language, object $recipient) use ($deviceName, $client, $createdUtc): array {
            return [
                '{device_name}' => $deviceName,
                '{client}' => $language->_(DeviceService::CLIENT_LABELS[$client] ?? DeviceService::CLIENT_LABELS['other']),
                '{created}' => $this->when($createdUtc, $language, $recipient),
                '{mycalendars_url}' => $this->myCalendarsUrl(),
            ];
        }, [$this->myCalendarsUrl() => 'COM_APPOINTMENTS_TEAM_D_MAIL_LINK_MYCALENDARS']);
    }

    public function invitation(string $email, #[\SensitiveParameter] string $link, \DateTimeImmutable $expiresUtc): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_MAIL', 503);
            $this->logFailure('invitation-email-validation', $error);

            throw $error;
        }

        if (preg_match('#\Ahttps://#i', $link) !== 1) {
            $error = new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_MAIL', 503);
            $this->logFailure('invitation-url-scheme-validation', $error);

            throw $error;
        }

        // The account exists (an administrator created it): its language applies, else the site's.
        try {
            $recipient = $this->userByEmail($email) ?? (object) ['id' => 0, 'name' => '', 'email' => $email, 'params' => '{}'];
        } catch (\Throwable $e) {
            $this->logFailure('invitation-recipient-lookup', $e);

            throw $e;
        }

        $recipient->email = $email;

        $this->deliver('team_invitation', [$recipient], function (Language $language, object $recipient) use ($expiresUtc, $link): array {
            return [
                '{expires}' => $this->when($expiresUtc, $language, $recipient),
                '{activation_url}' => $link,
            ];
        }, [$link => 'COM_APPOINTMENTS_TEAM_D_MAIL_LINK_ACTIVATION']);
    }

    public function activationCompleted(int $invitationId, int $userId, string $revokeUrl): void
    {
        $account = $this->user($userId);

        if ($account === null || preg_match('#\Ahttps?://#i', $revokeUrl) !== 1) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_MAIL', 503);
        }

        $username = $this->username($userId);

        // Whoever may revoke this account receives the link, and only with an active second
        // factor: for a team account the administrators (core.admin), for an external account
        // everybody who shares with externals.
        $grants = $this->services->grants();
        $action = $grants->audienceOf($userId) === TeamStates::AUDIENCE_EXTERNAL ? 'appointments.team.external' : 'core.admin';
        $recipients = array_values(array_filter($this->usersWith($action), static fn (object $user): bool => $grants->hasSecondFactor((int) $user->id)));

        if ($recipients === []) {
            Log::add('Internet calendar activation notice has no recipient with a second factor: account ' . $userId, Log::WARNING, 'com_appointments');
        }

        $this->deliver('team_invitation_accepted', $recipients, static fn (): array => [
            '{account_name}' => (string) $account->name,
            '{account_username}' => $username,
            '{revoke_url}' => $revokeUrl,
        ], [$revokeUrl => 'COM_APPOINTMENTS_TEAM_D_MAIL_LINK_REVOKE']);
    }
    public function grantChanged(int $calendarId, int $granteeUserId, string $roleLabelKey, int $actorUserId, array $priorRecipientUserIds = []): void
    {
        $calendar = $this->services->calendars()->get($calendarId);

        if ($calendar === null) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_MAIL', 503);
        }

        // Owner and managers, so that no grant, a self-grant of an administrator included,
        // happens unseen. The actor knows what they did and gets no copy.
        $ids = [...$priorRecipientUserIds, (int) $calendar->owner_user_id];

        foreach ($this->services->grants()->grantsOf($calendarId) as $grant) {
            if ((int) $grant->role === CalendarRole::Manage->value
                && \in_array((int) $grant->state, [TeamStates::GRANT_ACTIVE, TeamStates::GRANT_PENDING_KEY], true)
            ) {
                $ids[] = (int) $grant->user_id;
            }
        }

        $recipients = [];

        foreach (array_unique($ids) as $id) {
            if ($id > 0 && $id !== $actorUserId && ($user = $this->user($id)) !== null) {
                $recipients[] = $user;
            }
        }

        $grantee = $this->user($granteeUserId);
        $actor = $this->user($actorUserId);
        $title = (string) $calendar->title;

        $this->deliver('team_grant_changed', $recipients, function (Language $language) use ($title, $grantee, $actor, $roleLabelKey): array {
            return [
                '{calendar_title}' => $title,
                '{grantee_name}' => $grantee !== null ? (string) $grantee->name : '',
                '{actor_name}' => $actor !== null ? (string) $actor->name : '',
                '{role}' => $this->role($language, $roleLabelKey),
                '{mycalendars_url}' => $this->myCalendarsUrl(),
            ];
        }, [$this->myCalendarsUrl() => 'COM_APPOINTMENTS_TEAM_D_MAIL_LINK_MYCALENDARS']);
    }

    public function accessSuspended(int $userId, string $reason): void
    {
        $user = $this->user($userId);

        if ($user === null) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_MAIL', 503);
        }

        $reasonKey = self::REASONS[$reason] ?? 'COM_APPOINTMENTS_TEAM_D_MAIL_REASON_OTHER';

        $this->deliver('team_access_suspended', [$user], static fn (Language $language): array => [
            '{reason}' => $language->_($reasonKey),
        ]);
    }

    /**
     * The purposes this class sends, with their language keys. Pure, for the tests.
     *
     * @return  array<string, array{0: string, 1: string}>
     */
    public static function purposes(): array
    {
        return DefaultTemplates::TEAM_KEYS;
    }

    /**
     * Replace the placeholders of a template. Values are inserted as plain text; the
     * HTML part escapes the whole body afterwards, so a device name or calendar title
     * can never add markup. Line breaks in a value are flattened, so a value cannot
     * add lines to the mail either. A value that is not one of the mail's own links
     * gets a word joiner after the scheme, so a name such as «https://example» never
     * becomes a link in the HTML part. Pure, for the tests.
     *
     * @param   array{subject: string, body: string}  $template
     * @param   array<string, string>                 $values
     * @param   string[]                              $links  The URLs the mail itself carries, inserted unchanged.
     *
     * @return  array{subject: string, body: string}
     */
    public static function fill(array $template, array $values, array $links = []): array
    {
        $values = array_map(static function (string $value) use ($links): string {
            $value = trim((string) preg_replace('/[\r\n\t\x{0B}\x{0C}\x{85}\x{2028}\x{2029}]+/u', ' ', $value));

            return \in_array($value, $links, true) ? $value : str_replace('://', ":\u{2060}//", $value);
        }, $values);

        return [
            'subject' => trim((string) preg_replace('/[\r\n\x{0B}\x{0C}\x{85}\x{2028}\x{2029}]+/u', ' ', strtr($template['subject'], $values))),
            'body' => strtr($template['body'], $values),
        ];
    }

    /**
     * @param   object[]                                          $recipients  Rows with id, name, email, params.
     * @param   callable(Language, object): array<string,string>  $values
     * @param   array<string, string>                             $links       URL => language key of its label in the HTML part.
     */
    private function deliver(string $purpose, array $recipients, callable $values, array $links = []): void
    {
        try {
            $app = Factory::getApplication();
        } catch (\Throwable $e) {
            $this->logFailure('application-bootstrap', $e);

            throw $e;
        }

        if (!$app->get('mailonline', true)) {
            $error = new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_MAIL', 503);
            $this->logFailure('mail-disabled', $error, Log::WARNING);

            throw $error;
        }

        $failures = 0;
        $siteName = (string) $app->get('sitename');

        foreach ($recipients as $recipient) {
            if (!filter_var((string) $recipient->email, FILTER_VALIDATE_EMAIL)) {
                $this->logFailure('recipient-email-validation', new \InvalidArgumentException());
                $failures++;

                continue;
            }

            $phase = 'language';

            try {
                $tag = $this->languageOf($recipient);
                $language = Factory::getContainer()->get(LanguageFactoryInterface::class)->createLanguage($tag);
                $language->load('com_appointments', JPATH_ADMINISTRATOR, $tag, true);
                $language->load('com_appointments', JPATH_ADMINISTRATOR . '/components/com_appointments', $tag, true);
                $phase = 'template';
                $template = $this->template($purpose, $tag, $language);
                $phase = 'message-compose';
                $message = self::fill($template, [
                    '{site_name}' => $siteName,
                    '{user_name}' => (string) $recipient->name,
                ] + $values($language, $recipient), array_keys($links));

                $phase = 'mailer-setup';
                $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
                $mailer->setSender([(string) $app->get('mailfrom'), (string) $app->get('fromname')]);
                $mailer->addRecipient((string) $recipient->email);
                $mailer->setSubject($message['subject']);
                $known = [];

                foreach ($links as $url => $labelKey) {
                    $known[$url] = $language->_($labelKey);
                }

                $phase = 'html-render';
                $html = MailHtml::render($message['body'], $tag, $siteName, null, $mailer, $known, $this->publicBase());

                if ($html !== null) {
                    $mailer->isHtml(true);
                    $mailer->setBody($html);
                    $mailer->AltBody = $message['body'];
                } else {
                    $mailer->isHtml(false);
                    $mailer->setBody($message['body']);
                }

                $phase = 'transport-send';
                if (!$mailer->send()) {
                    throw new \RuntimeException('Mail delivery was rejected.');
                }
            } catch (\Throwable $e) {
                $this->logFailure($phase, $e);
                $failures++;
            }
        }

        if ($failures > 0) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_MAIL', 503);
        }
    }

    private function logFailure(string $phase, \Throwable $error, int $priority = Log::ERROR): void
    {
        Log::add('Internet calendar mail failed: phase=' . $phase . ', exception=' . $error::class, $priority, 'com_appointments');
    }

    /**
     * The published DB template of the purpose in the language, then the
     * default text of that same language file.
     *
     * @return  array{subject: string, body: string}
     */
    private function template(string $purpose, string $tag, Language $language): array
    {
        $db = $this->services->db();

        $query = $this->query()
            ->select($db->quoteName(['subject', 'body']))
            ->from($db->quoteName('#__appointments_templates'))
            ->where($db->quoteName('eventtype_id') . ' = 0')
            ->where($db->quoteName('purpose') . ' = :purpose')
            ->where($db->quoteName('language') . ' = :language')
            ->where($db->quoteName('published') . ' = 1')
            ->bind(':purpose', $purpose)
            ->bind(':language', $tag);
        $row = $db->setQuery($query)->loadAssoc();

        if ($row && trim((string) $row['subject']) !== '' && trim((string) $row['body']) !== ''
            && TemplateTable::linksSafe($purpose, (string) $row['subject'], (string) $row['body'])
        ) {
            return ['subject' => (string) $row['subject'], 'body' => (string) $row['body']];
        }

        [$subjectKey, $bodyKey] = DefaultTemplates::TEAM_KEYS[$purpose];

        return ['subject' => $language->_($subjectKey), 'body' => str_replace('\n', "\n", $language->_($bodyKey))];
    }

    private function role(Language $language, string $roleLabelKey): string
    {
        // A role key of the grant service, or the number of a role.
        if (ctype_digit($roleLabelKey) && isset(self::ROLE_LABELS[(int) $roleLabelKey])) {
            $roleLabelKey = self::ROLE_LABELS[(int) $roleLabelKey];
        }

        return preg_match('/\A[A-Z0-9_]+\z/', $roleLabelKey) === 1 ? $language->_($roleLabelKey) : '';
    }

    private function when(\DateTimeImmutable $utc, Language $language, object $recipient): string
    {
        $params = json_decode((string) ($recipient->params ?? '{}'), true) ?: [];
        $zone = (string) ($params['timezone'] ?? '');

        if (!\in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
            $zone = (string) Factory::getApplication()->get('offset', 'UTC');
        }

        try {
            $local = $utc->setTimezone(new \DateTimeZone($zone));
        } catch (\Throwable $e) {
            $local = $utc->setTimezone(new \DateTimeZone('UTC'));
        }

        $format = $language->_('COM_APPOINTMENTS_TEAM_D_MAIL_DATE_FORMAT');

        return $local->format($format === 'COM_APPOINTMENTS_TEAM_D_MAIL_DATE_FORMAT' ? 'Y-m-d H:i' : $format) . ' (' . $local->getTimezone()->getName() . ')';
    }

    private function languageOf(object $recipient): string
    {
        $params = json_decode((string) ($recipient->params ?? '{}'), true) ?: [];
        $tag = (string) ($params['language'] ?? '');

        return MailLanguage::resolve($tag, (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB'));
    }

    private function userByEmail(string $email): ?object
    {
        $db = $this->services->db();
        $lower = mb_strtolower(trim($email));
        $query = $this->query()
            ->select($db->quoteName(['id', 'name', 'email', 'params', 'block']))
            ->from($db->quoteName('#__users'))
            ->where('LOWER(' . $db->quoteName('email') . ') = :email')
            ->bind(':email', $lower)
            ->setLimit(2);
        $rows = $db->setQuery($query)->loadObjectList();

        // Only an unambiguous account says which language to use.
        return \count($rows) === 1 ? $rows[0] : null;
    }

    private function username(int $userId): string
    {
        $db = $this->services->db();
        $query = $this->query()
            ->select($db->quoteName('username'))
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $userId, ParameterType::INTEGER);

        return (string) $db->setQuery($query)->loadResult();
    }

    private function user(int $userId): ?object
    {
        if ($userId <= 0) {
            return null;
        }

        $db = $this->services->db();
        $query = $this->query()
            ->select($db->quoteName(['id', 'name', 'email', 'params', 'block']))
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $userId, ParameterType::INTEGER);
        $row = $db->setQuery($query)->loadObject();

        return $row ?: null;
    }

    /**
     * Every active account with a permission on the component (Super Users
     * included), each once.
     *
     * @return  object[]
     */
    private function usersWith(string $action): array
    {
        $db = $this->services->db();
        $query = $this->query()->select($db->quoteName('id'))->from($db->quoteName('#__usergroups'));
        $candidates = [];

        foreach ($db->setQuery($query)->loadColumn() as $groupId) {
            $groupId = (int) $groupId;

            if (Access::checkGroup($groupId, 'core.admin') || Access::checkGroup($groupId, $action, 'com_appointments')) {
                foreach (Access::getUsersByGroup($groupId) as $userId) {
                    $candidates[(int) $userId] = true;
                }
            }
        }

        $users = Factory::getContainer()->get(UserFactoryInterface::class);
        $recipients = [];

        foreach (array_keys($candidates) as $userId) {
            $account = $users->loadUserById($userId);

            if ((int) $account->id === $userId && (int) $account->block === 0
                && $account->authorise($action, 'com_appointments')
                && ($row = $this->user($userId)) !== null
            ) {
                $recipients[] = $row;
            }
        }

        return $recipients;
    }

    /**
     * Address of «My calendars» through its menu item: the page opens only through a published menu
     * item of its type (MenuViews), so without one the link has no Itemid and the page answers 404.
     * Looked up once per mailer, not once per recipient.
     */
    private function myCalendarsUrl(): string
    {
        if ($this->myCalendarsUrl === null) {
            $itemId = MenuViews::itemFor($this->services->db(), 'mycalendars');
            $this->myCalendarsUrl = $this->siteUrl('index.php?option=com_appointments&view=mycalendars' . ($itemId > 0 ? '&Itemid=' . $itemId : ''));
        }

        return $this->myCalendarsUrl;
    }

    private function siteUrl(string $path): string
    {
        return rtrim($this->publicBase() ?? '', '/') . '/' . $path;
    }

    /**
     * The public site URL: the component's public_site_url, then live_site, then the request root.
     */
    private function publicBase(): ?string
    {
        $app = Factory::getApplication();
        $base = (string) ComponentHelper::getParams('com_appointments')->get('public_site_url', '');

        if ($base === '') {
            $base = (string) $app->get('live_site', '');
        }

        if ($base === '' && !($app instanceof ConsoleApplication)) {
            $base = Uri::root();
        }

        return filter_var($base, FILTER_VALIDATE_URL) && preg_match('#\Ahttps?://#i', $base) === 1 ? $base : null;
    }

    private function query(): QueryInterface
    {
        $db = $this->services->db();

        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }

    public function accountLocked(int $userId, int $retryAfterSeconds): void
    {
        $account = $this->user($userId);

        if ($account === null) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_MAIL', 503);
        }

        // To the administrators, so that a lock caused by somebody else's attempts is seen.
        // Account and duration only: no address, no attempt detail.
        $username = $this->username($userId);
        $minutes = (string) max(1, (int) ceil($retryAfterSeconds / 60));
        $url = $this->siteUrl('administrator/index.php?option=com_appointments&view=devices');

        $this->deliver('team_account_locked', $this->usersWith('core.admin'), static fn (): array => [
            '{account_name}' => (string) $account->name,
            '{account_username}' => $username,
            '{minutes}' => $minutes,
            '{devices_url}' => $url,
        ], [$url => 'COM_APPOINTMENTS_TEAM_D_MAIL_LINK_DEVICES']);
    }
    public function calendarDeleted(string $calendarTitle, array $recipientUserIds, int $actorUserId, \DateTimeImmutable $deletedUtc): void
    {
        $recipients = [];

        // Like a grant change, so that no deletion happens unseen; the actor gets no copy.
        foreach (array_unique(array_map('intval', $recipientUserIds)) as $id) {
            if ($id > 0 && $id !== $actorUserId && ($user = $this->user($id)) !== null) {
                $recipients[] = $user;
            }
        }

        $actor = $this->user($actorUserId);

        $this->deliver('team_calendar_deleted', $recipients, function (Language $language, object $recipient) use ($calendarTitle, $actor, $deletedUtc): array {
            return [
                '{calendar_title}' => $calendarTitle,
                '{actor_name}' => $actor !== null ? (string) $actor->name : '',
                '{deleted}' => $this->when($deletedUtc, $language, $recipient),
            ];
        });
    }}
