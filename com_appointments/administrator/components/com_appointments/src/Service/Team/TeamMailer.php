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
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
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
 * the booking mails, in the recipient's language with English as fallback.
 * Without a published template the default text of the language file applies:
 * these mails are security notices and always go out. They carry no calendar
 * entry, no app password, no key and no calendar content; the only secret is the
 * one-time link of an invitation. A failed delivery is logged without address.
 *
 * @since  1.4.0
 */
final class TeamMailer implements TeamMailerInterface
{
    /** Languages the component ships; any other recipient language falls back to English. */
    private const LANGUAGES = ['en-GB', 'de-DE', 'fr-FR'];

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
                '{mycalendars_url}' => $this->siteUrl('index.php?option=com_appointments&view=mycalendars'),
            ];
        }, [$this->siteUrl('index.php?option=com_appointments&view=mycalendars') => 'COM_APPOINTMENTS_TEAM_D_MAIL_LINK_MYCALENDARS']);
    }

    public function invitation(string $email, string $link, \DateTimeImmutable $expiresUtc): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('#\Ahttps://#i', $link) !== 1) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_MAIL', 503);
        }

        // The account exists (an administrator created it): its language applies, else the site's.
        $recipient = $this->userByEmail($email) ?? (object) ['id' => 0, 'name' => '', 'email' => $email, 'params' => '{}'];
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

        $this->deliver('team_invitation_accepted', $this->externalManagers(), static fn (): array => [
            '{account_name}' => (string) $account->name,
            '{account_username}' => $username,
            '{revoke_url}' => $revokeUrl,
        ], [$revokeUrl => 'COM_APPOINTMENTS_TEAM_D_MAIL_LINK_REVOKE']);
    }
    public function grantChanged(int $calendarId, int $granteeUserId, string $roleLabelKey, int $actorUserId): void
    {
        $calendar = $this->services->calendars()->get($calendarId);

        if ($calendar === null) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_MAIL', 503);
        }

        // Owner and managers, so that no grant, a self-grant of an administrator included,
        // happens unseen. The actor knows what they did and gets no copy.
        $ids = [(int) $calendar->owner_user_id];

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
                '{mycalendars_url}' => $this->siteUrl('index.php?option=com_appointments&view=mycalendars'),
            ];
        }, [$this->siteUrl('index.php?option=com_appointments&view=mycalendars') => 'COM_APPOINTMENTS_TEAM_D_MAIL_LINK_MYCALENDARS']);
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
     * add lines to the mail either. Pure, for the tests.
     *
     * @param   array{subject: string, body: string}  $template
     * @param   array<string, string>                 $values
     *
     * @return  array{subject: string, body: string}
     */
    public static function fill(array $template, array $values): array
    {
        $values = array_map(static fn (string $value): string => trim((string) preg_replace('/[\r\n\t]+/', ' ', $value)), $values);

        return [
            'subject' => trim((string) preg_replace('/[\r\n]+/', ' ', strtr($template['subject'], $values))),
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
        $app = Factory::getApplication();

        if (!$app->get('mailonline', true)) {
            Log::add('Internet calendar mail disabled: purpose ' . $purpose, Log::WARNING, 'com_appointments');

            throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_MAIL', 503);
        }

        $failures = 0;
        $siteName = (string) $app->get('sitename');

        foreach ($recipients as $recipient) {
            if (!filter_var((string) $recipient->email, FILTER_VALIDATE_EMAIL)) {
                $failures++;

                continue;
            }

            try {
                $tag = $this->languageOf($recipient);
                $language = Factory::getContainer()->get(LanguageFactoryInterface::class)->createLanguage($tag);
                $language->load('com_appointments', JPATH_ADMINISTRATOR, $tag, true);
                $language->load('com_appointments', JPATH_ADMINISTRATOR . '/components/com_appointments', $tag, true);
                $message = self::fill($this->template($purpose, $tag, $language), [
                    '{site_name}' => $siteName,
                    '{user_name}' => (string) $recipient->name,
                ] + $values($language, $recipient));

                $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
                $mailer->setSender([(string) $app->get('mailfrom'), (string) $app->get('fromname')]);
                $mailer->addRecipient((string) $recipient->email);
                $mailer->setSubject($message['subject']);
                $known = [];

                foreach ($links as $url => $labelKey) {
                    $known[$url] = $language->_($labelKey);
                }

                $html = MailHtml::render($message['body'], $tag, $siteName, null, $mailer, $known, $this->publicBase());

                if ($html !== null) {
                    $mailer->isHtml(true);
                    $mailer->setBody($html);
                    $mailer->AltBody = $message['body'];
                } else {
                    $mailer->isHtml(false);
                    $mailer->setBody($message['body']);
                }

                if (!$mailer->send()) {
                    throw new \RuntimeException('Mail delivery was rejected.');
                }
            } catch (\Throwable $e) {
                // A transport error can carry addresses or credentials: purpose only.
                Log::add('Internet calendar mail failed: purpose ' . $purpose, Log::ERROR, 'com_appointments');
                $failures++;
            }
        }

        if ($failures > 0) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_D_ERROR_MAIL', 503);
        }
    }

    /**
     * The published DB template of the purpose in the language, then in English,
     * then the default text of the language file.
     *
     * @return  array{subject: string, body: string}
     */
    private function template(string $purpose, string $tag, Language $language): array
    {
        $db = $this->services->db();

        foreach (array_unique([$tag, 'en-GB']) as $candidate) {
            $query = $this->query()
                ->select($db->quoteName(['subject', 'body']))
                ->from($db->quoteName('#__appointments_templates'))
                ->where($db->quoteName('eventtype_id') . ' = 0')
                ->where($db->quoteName('purpose') . ' = :purpose')
                ->where($db->quoteName('language') . ' = :language')
                ->where($db->quoteName('published') . ' = 1')
                ->bind(':purpose', $purpose)
                ->bind(':language', $candidate);
            $row = $db->setQuery($query)->loadAssoc();

            if ($row && trim((string) $row['subject']) !== '' && trim((string) $row['body']) !== '') {
                return ['subject' => (string) $row['subject'], 'body' => (string) $row['body']];
            }
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

        if ($tag === '') {
            $tag = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
        }

        return \in_array($tag, self::LANGUAGES, true) ? $tag : 'en-GB';
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
     * Every active account with the permission to share with external users
     * (Super Users included), each once.
     *
     * @return  object[]
     */
    private function externalManagers(): array
    {
        $db = $this->services->db();
        $query = $this->query()->select($db->quoteName('id'))->from($db->quoteName('#__usergroups'));
        $candidates = [];

        foreach ($db->setQuery($query)->loadColumn() as $groupId) {
            $groupId = (int) $groupId;

            if (Access::checkGroup($groupId, 'core.admin') || Access::checkGroup($groupId, 'appointments.team.external', 'com_appointments')) {
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
                && $account->authorise('appointments.team.external', 'com_appointments')
                && ($row = $this->user($userId)) !== null
            ) {
                $recipients[] = $row;
            }
        }

        return $recipients;
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
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function calendarDeleted(string $calendarTitle, array $recipientUserIds, int $actorUserId, \DateTimeImmutable $deletedUtc): void
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }
}
