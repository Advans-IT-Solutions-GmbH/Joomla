<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service;

use Advans\Component\Appointments\Administrator\Helper\BookingData;
use Advans\Component\Appointments\Administrator\Helper\CalendarNotice;
use Advans\Component\Appointments\Administrator\Helper\DefaultTemplates;
use Advans\Component\Appointments\Administrator\Helper\IcsWriter;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\MailComposer;
use Advans\Component\Appointments\Administrator\Helper\MailHtml;
use Advans\Component\Appointments\Administrator\Helper\MailLanguage;
use Advans\Component\Appointments\Administrator\Helper\MailPresentation;
use Advans\Component\Appointments\Administrator\Helper\ManageMenuItem;
use Advans\Component\Appointments\Administrator\Helper\MenuViews;
use Advans\Component\Appointments\Administrator\Helper\NotificationSwitch;
use Advans\Component\Appointments\Administrator\Helper\PrivacyAddress;
use Advans\Component\Appointments\Administrator\Helper\PublicSiteUrl;
use Advans\Component\Appointments\Administrator\Helper\SignedLink;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Factory;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Mail\Exception\MailDisabledException;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

final class NotificationService
{
    private ?BookingData $data = null;

    /** Menu item of «My calendars» (MenuViews::itemFor()), null until first needed, 0 without one. */
    private ?int $myCalendarsItem = null;

    /**
     * @param   TeamServices|null  $team  The internet calendars; null reads them from the application when needed.
     */
    public function __construct(private DatabaseInterface $db, private ?TeamServices $team = null)
    {
    }

    /**
     * The guest list of booking $id as stored now. A copy without an id keeps its own list; a
     * booking that is no longer stored has no guests to write to.
     *
     * A locking read: it sees the latest committed list even inside a transaction of the caller
     * that started earlier (the Scheduler's reminder run), and a guest removal that is still running
     * finishes first (BookingStore::removeGuest locks the row FOR UPDATE). A removal that starts
     * afterwards waits for the caller's transaction, so a removed address never gets a later mail.
     */
    private function currentGuests(int $id, mixed $copy): ?string
    {
        if ($id < 1) {
            return is_string($copy) ? $copy : null;
        }

        $row = $this->db->setQuery('SELECT ' . implode(', ', $this->db->quoteName(BookingData::readColumns())) . ' FROM '
            . $this->db->quoteName('#__appointments_bookings')
            . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $id . ' LOCK IN SHARE MODE')->loadAssoc();
        // Stored encrypted; a list that cannot be opened has no guest to write to.
        $guests = $row ? $this->data()->openLenient($row)['guest_emails'] : null;

        return is_string($guests) ? $guests : null;
    }

    private function data(): BookingData
    {
        return $this->data ??= BookingData::fromApplication();
    }

    public function send(array $booking, string $purpose, \DateTimeImmutable $now, bool $hostOnly = false): void
    {
        if (!in_array($purpose, DefaultTemplates::SENT, true)) {
            throw new \InvalidArgumentException('Unsupported notification purpose.');
        }

        $app = Factory::getApplication();
        // Convert global mail disablement into the component's recoverable
        // notification failure, not a page-level Joomla exception after saving.
        if (!$app->get('mailonline', true)) {
            Log::add('Appointment notification disabled: booking ' . (int) $booking['id'] . ', purpose ' . $purpose, Log::ERROR, 'com_appointments');
            throw new NotificationException('Appointment mail delivery is disabled.');
        }
        // Fail closed: without a configured public site URL no mail goes out, so no link is ever
        // built from the Host header of a request. The system checks show this as an error.
        if (PublicSiteUrl::current() === null) {
            Log::add('Appointment notification refused: no valid public site URL is configured (booking ' . (int) $booking['id']
                . ', purpose ' . $purpose . ').', Log::ERROR, 'com_appointments');
            throw new NotificationException('No valid public site URL is configured (component option or live_site).');
        }
        // Callers pass a stored row or a row opened before: opening is idempotent. A field that
        // cannot be decrypted is left blank (no mail to that address), only its column is logged.
        $failed = [];
        $booking = $this->data()->openLenient($booking, $failed);

        if ($failed !== []) {
            Log::add('Appointment notification: booking ' . (int) $booking['id'] . ' has fields that could not be decrypted (' . implode(', ', $failed) . ').', Log::WARNING, 'com_appointments');
        }

        $event = $this->event((int) $booking['eventtype_id']);
        $hostParams = json_decode((string) $event['host_params'], true) ?: [];
        // Every recipient uses their own account language, else the site's default.
        $siteLanguage = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
        $hostLanguage = MailLanguage::resolve((string) ($hostParams['language'] ?? ''), $siteLanguage);
        // With a target calendar in use, the host and every other user of that calendar get a mail
        // without invitation (CalendarNotice); without one, the host keeps the mail with invitation.
        // A host without write access to the target keeps the mail with invitation (no copy is
        // written), while every other user of the calendar still gets theirs.
        $calendar = $this->calendarAudience($booking, $event);
        $recipients = [];
        // The guests of the stored booking now, not of the copy the caller loaded earlier: a guest
        // who removed their address in the meantime gets no further mail and is no attendee of the
        // calendar entry. The list is read with a shared lock inside a transaction (a savepoint when the
        // caller, such as the Scheduler, already holds one) that lasts until every mail is out, so a
        // guest removal (BookingStore::removeGuest, FOR UPDATE on the same row) waits for the delivery
        // and a removed address is never written to afterwards.
        $stored = (int) $booking['id'] > 0;

        if ($stored) {
            $this->db->transactionStart(true);
        }

        try {
            $booking['guest_emails'] = $this->currentGuests((int) $booking['id'], $booking['guest_emails'] ?? null);

            if (!$hostOnly && !empty($booking['invitee_email'])) {
                $guests = isset(DefaultTemplates::GUEST[$purpose]) ? PrivacyAddress::guestList($booking['guest_emails'] ?? null) : [];
                $languages = MailLanguage::forAddresses($this->db, [$booking['invitee_email'], ...$guests], $siteLanguage);
                $recipients[] = [$booking['invitee_email'], $languages[$booking['invitee_email']], false, true, false];
                // A guest gets the guest template of the purpose, with a neutral salutation and the
                // link that removes only their own address. A purpose without one is not sent to guests.
                foreach ($guests as $guest) {
                    $recipients[] = [$guest, $languages[$guest], false, false, true];
                }
            }
            if ($calendar === null || $calendar['host_fallback']) {
                $recipients[] = [$event['host_email'], $hostLanguage, true, false, false];
            }

            $failures = 0;
            $conferences = ConferenceService::forComponent($this->db);

            foreach ($recipients as [$address, $tag, $isHost, $canManage, $isGuest]) {
                try {
                    $recipientPurpose = $isHost ? (DefaultTemplates::HOST[$purpose] ?? $purpose)
                        : ($isGuest ? DefaultTemplates::GUEST[$purpose] : $purpose);
                    $template = $this->template((int) $booking['eventtype_id'], $recipientPurpose, $tag);
                    $language = new Language($tag);
                    $language->load('com_appointments', JPATH_ADMINISTRATOR . '/components/com_appointments', $tag, true);
                    // «Label:» before a link on its own line, «Label :» in French.
                    $labelFormat = $language->_('COM_APPOINTMENTS_MAIL_LINK_LABEL');
                    $labelFormat = str_contains($labelFormat, '%s') ? $labelFormat : '%s:';
                    $timezone = $isHost ? (string) (!empty($hostParams['timezone']) ? $hostParams['timezone'] : $app->get('offset', 'UTC'))
                        : (string) $booking['invitee_timezone'];
                    $presentation = (new MailPresentation())->placeholders($booking, $tag, $timezone);
                    $stateKey = match ((int) $booking['state']) {
                        0 => 'COM_APPOINTMENTS_MAIL_STATE_PENDING',
                        1 => 'COM_APPOINTMENTS_MAIL_STATE_CONFIRMED',
                        2 => 'COM_APPOINTMENTS_MAIL_STATE_CANCELLED',
                        default => throw new \InvalidArgumentException('Invalid notification booking state.'),
                    };
                    $presentation['{state_label}'] = $language->_($stateKey);
                    $template['subject'] = strtr($template['subject'], $presentation);
                    $template['body'] = strtr($template['body'], $presentation);
                    $links = $canManage ? $this->links($booking, $tag) : ($isGuest ? $this->guestLinks($booking, $address, $tag) : []);
                    $recipientBooking = $booking;
                    if ($isHost && !empty($booking['conference_url'])) {
                        $recipientBooking['conference_url'] = $conferences->hostUrl($booking) ?? $booking['conference_url'];
                    }
                    $message = (new MailComposer(new IcsWriter()))->compose($template, $recipientBooking, $event, $links, $now);
                    // Each URL on a line of its own: a signed URL is long and has no break opportunity. The HTML
                    // part shows the label as link text instead (MailHtml), the text part keeps the full URL.
                    if (!empty($recipientBooking['conference_url']) && !str_contains($message['body'], $recipientBooking['conference_url'])) {
                        $message['body'] .= "\n\n" . sprintf($labelFormat, $language->_('COM_APPOINTMENTS_MAIL_JOIN')) . "\n" . $recipientBooking['conference_url'];
                    }

                    if ($canManage) {
                        $message['body'] .= "\n\n" . sprintf($labelFormat, $language->_('COM_APPOINTMENTS_MAIL_RESCHEDULE')) . "\n" . $links['reschedule_url']
                            . "\n" . sprintf($labelFormat, $language->_('COM_APPOINTMENTS_MAIL_CANCEL')) . "\n" . $links['cancel_url']
                            . "\n" . sprintf($labelFormat, $language->_('COM_APPOINTMENTS_MAIL_DELETE')) . "\n" . $links['erase_url'];
                    }

                    if ($isGuest) {
                        $message['body'] .= "\n\n" . sprintf($labelFormat, $language->_('COM_APPOINTMENTS_MAIL_GUEST_REMOVE')) . "\n" . $links['guest_remove_url'];
                    }

                    $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
                    $mailer->setSender([(string) $app->get('mailfrom'), (string) $app->get('fromname')]);
                    $mailer->addRecipient($address);
                    $mailer->setSubject($message['subject']);
                    // HTML in the site's mail layout when com_mails sends HTML, the text stays as
                    // alternative part. A site with plain-text mails keeps the text mail.
                    // Known URLs with the label they are shown with in the HTML part.
                    $known = [];
                    if (!empty($recipientBooking['conference_url'])) {
                        $known[(string) $recipientBooking['conference_url']] = $language->_('COM_APPOINTMENTS_MAIL_JOIN');
                    }
                    foreach (['reschedule_url' => 'COM_APPOINTMENTS_MAIL_RESCHEDULE', 'cancel_url' => 'COM_APPOINTMENTS_MAIL_CANCEL',
                        'erase_url' => 'COM_APPOINTMENTS_MAIL_DELETE', 'guest_remove_url' => 'COM_APPOINTMENTS_MAIL_GUEST_REMOVE'] as $field => $key) {
                        if (!empty($links[$field])) {
                            $known[(string) $links[$field]] = $language->_($key);
                        }
                    }
                    $html = MailHtml::render($message['body'], $tag, (string) $app->get('sitename'), null, $mailer, $known, $this->publicBase());

                    if ($html !== null) {
                        $mailer->isHtml(true);
                        $mailer->setBody($html);
                        $mailer->AltBody = $message['body'];
                    } else {
                        $mailer->setBody($message['body']);
                        $mailer->isHtml(false);
                    }

                    foreach ($message['attachments'] as $attachment) {
                        $mailer->addStringAttachment($attachment['content'], $attachment['filename'], 'base64', $attachment['content_type']);
                    }

                    if (!$mailer->send()) {
                        throw new NotificationException('Mail delivery was rejected.');
                    }
                } catch (NotificationException | MailDisabledException | \PHPMailer\PHPMailer\Exception | \InvalidArgumentException $e) {
                    // Transport exceptions can contain recipients, URLs or SMTP credentials.
                    // Keep diagnostics deliberately limited to an internal booking id and purpose.
                    Log::add('Appointment notification failed: booking ' . (int) $booking['id'] . ', purpose ' . $purpose, Log::ERROR, 'com_appointments');
                    $failures++;
                }
            }

            if ($calendar !== null) {
                $taken = array_map(static fn (array $recipient): string => (string) $recipient[0], $recipients);
                $accounts = [];

                foreach ($calendar['members'] as $member) {
                    $accounts[(int) $member['user_id']] = $member['account'];
                }

                foreach (CalendarNotice::plan($purpose, (int) $event['host_user_id'], $calendar['members'], $taken) as $entry) {
                    try {
                        $this->sendToCalendarUser($entry, $accounts[$entry['user_id']], $booking, $event, $calendar, $siteLanguage, $now, $conferences);
                    } catch (NotificationException | MailDisabledException | \PHPMailer\PHPMailer\Exception | \InvalidArgumentException | \JsonException $e) {
                        Log::add('Appointment calendar notification failed: booking ' . (int) $booking['id'] . ', purpose ' . $purpose, Log::ERROR, 'com_appointments');
                        $failures++;
                    }
                }
            }
        } finally {
            // Nothing is written here: committing only releases the lock (or the savepoint).
            if ($stored) {
                $this->db->transactionCommit(true);
            }
        }

        if ($failures) {
            throw new NotificationException('One or more appointment notifications could not be delivered.');
        }
    }

    /**
     * The users of the target calendar of the event type, or null when no target calendar is in
     * use: none set, internet calendars off or the calendar inactive. Null keeps the host's mail
     * with invitation, so the host always learns of a booking. A calendar that cannot be read
     * counts as none, for the same reason. Every user with access to the calendar is notified: when
     * the host has no effective write access (BookingCalendarBridge then writes no copy),
     * `host_fallback` is true, the host keeps the mail with invitation and the other users still
     * get theirs.
     *
     * @return  array{id: int, title: string, host_fallback: bool, members: list<array{user_id: int, email: string, role: int, muted: bool, backend: bool, account: object}>}|null
     */
    private function calendarAudience(array $booking, array $event): ?array
    {
        $calendarId = (int) ($event['calendar_id'] ?? 0);
        $host = (int) ($event['host_user_id'] ?? 0);

        if ($calendarId < 1 || $host < 1 || (int) ($booking['host_user_id'] ?? $host) !== $host) {
            return null;
        }

        try {
            $team = $this->team ??= TeamServices::fromApplication();

            if (!$team->enabled()) {
                return null;
            }

            $calendar = $team->calendars()->get($calendarId);

            if ($calendar === null || (int) $calendar->state !== 1) {
                return null;
            }

            $grants = $team->grants();
            $hostAccess = $grants->accessFor($host, $calendarId);
            $hostFallback = $hostAccess === null || !$hostAccess->role->canWrite();

            $members = [];

            foreach ($grants->grantsOf($calendarId) as $grant) {
                $userId = (int) $grant->user_id;
                // Effective only: active grant, valid row, active account in a calendar group, second factor.
                // A host who falls back to the mail with invitation gets no second mail as a calendar user.
                if ($userId === $host && $hostFallback) {
                    continue;
                }

                $access = $userId === $host ? $hostAccess : $grants->accessFor($userId, $calendarId);
                $account = $access === null ? null : $this->account($userId);

                if ($account === null || (int) $account->block !== 0) {
                    continue;
                }

                $members[] = [
                    'user_id' => $userId,
                    'email' => (string) $account->email,
                    'role' => $access->role->value,
                    'muted' => \in_array($calendarId, NotificationSwitch::mutedFrom((string) $account->params), true),
                    'backend' => $access->role->holdsKey() && $this->opensBackend($userId),
                    'account' => $account,
                ];
            }

            return ['id' => $calendarId, 'title' => (string) $calendar->title, 'host_fallback' => $hostFallback, 'members' => $members];
        } catch (\Throwable $e) {
            Log::add('Appointment notification: the target calendar of event type ' . (int) $event['id'] . ' could not be read (' . $e::class . ').', Log::WARNING, 'com_appointments');

            return null;
        }
    }

    /**
     * One booking mail to one user of the target calendar, in that user's account language and
     * time zone, without calendar invitation. A role that reads contents gets the details of the
     * calendar copy (the host also the telephone and the moderator link); free/busy gets the time
     * only. Links: the booking in the administrator for whoever may open it, and «My calendars»,
     * where the mails of the calendar are switched off.
     *
     * @param   array{user_id: int, email: string, purpose: string, content: bool, host: bool, backend: bool}  $entry
     * @param   array{id: int, title: string}  $calendar
     */
    private function sendToCalendarUser(array $entry, object $account, array $booking, array $event, array $calendar, string $siteLanguage,
        \DateTimeImmutable $now, ConferenceService $conferences): void
    {
        $app = Factory::getApplication();
        $params = json_decode((string) $account->params, true) ?: [];
        $tag = MailLanguage::resolve((string) ($params['language'] ?? ''), $siteLanguage);
        $timezone = (string) ($params['timezone'] ?? '');

        if (!\in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $timezone = (string) $app->get('offset', 'UTC');
        }

        $template = $this->template((int) $booking['eventtype_id'], $entry['purpose'], $tag);
        $language = new Language($tag);
        $language->load('com_appointments', JPATH_ADMINISTRATOR . '/components/com_appointments', $tag, true);
        $labelFormat = $language->_('COM_APPOINTMENTS_MAIL_LINK_LABEL');
        $labelFormat = str_contains($labelFormat, '%s') ? $labelFormat : '%s:';
        $presentation = (new MailPresentation())->placeholders($booking, $tag, $timezone);
        $title = trim((string) preg_replace('/[\r\n\t]+/', ' ', $calendar['title']));
        $base = $this->publicBase();
        $links = [];

        if ($entry['content']) {
            $presentation['{state_label}'] = $language->_(match ((int) $booking['state']) {
                0 => 'COM_APPOINTMENTS_MAIL_STATE_PENDING',
                1 => 'COM_APPOINTMENTS_MAIL_STATE_CONFIRMED',
                2 => 'COM_APPOINTMENTS_MAIL_STATE_CANCELLED',
                default => throw new \InvalidArgumentException('Invalid notification booking state.'),
            });
            $template['subject'] = strtr($template['subject'], $presentation);
            $template['body'] = strtr($template['body'], $presentation);
            // The host gets the whole booking with the moderator link; every other calendar user only
            // what the calendar copy shows (an allowlist), so no edited template reaches the telephone,
            // the answers or the cancellation reason.
            $recipientBooking = $entry['host'] ? $booking : CalendarNotice::contentBooking($booking);

            if ($entry['host'] && !empty($booking['conference_url'])) {
                $recipientBooking['conference_url'] = $conferences->hostUrl($booking) ?? $booking['conference_url'];
            }

            $message = (new MailComposer(new IcsWriter()))->compose($template, $recipientBooking, $event, [], $now);
            $message = ['subject' => strtr($message['subject'], ['{calendar_title}' => $title]), 'body' => strtr($message['body'], ['{calendar_title}' => $title])];
            $conference = (string) ($recipientBooking['conference_url'] ?? '');

            if ($conference !== '' && (int) $booking['state'] !== 2) {
                $links[$conference] = 'COM_APPOINTMENTS_MAIL_JOIN';
            }

            if ($entry['backend'] && $base !== null) {
                $links[rtrim($base, '/') . '/administrator/index.php?option=com_appointments&view=booking&id=' . (int) $booking['id']] = 'COM_APPOINTMENTS_MAIL_CALENDAR_LINK_BOOKING';
            }
        } else {
            $message = CalendarNotice::freeBusyText($template, $presentation + ['{calendar_title}' => $title]);
        }

        if ($base !== null) {
            // «My calendars» opens only through its menu item (MenuViews): without the Itemid the link answers 404.
            $this->myCalendarsItem ??= MenuViews::itemFor($this->db, 'mycalendars');
            $links[rtrim($base, '/') . '/index.php?option=com_appointments&view=mycalendars'
                . ($this->myCalendarsItem > 0 ? '&Itemid=' . $this->myCalendarsItem : '')] = 'COM_APPOINTMENTS_MAIL_CALENDAR_LINK_SETTINGS';
        }

        $known = [];

        foreach ($links as $url => $key) {
            $known[(string) $url] = $language->_($key);

            if (!str_contains($message['body'], (string) $url)) {
                $message['body'] .= "\n\n" . sprintf($labelFormat, $language->_($key)) . "\n" . $url;
            }
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $message['subject'])) {
            throw new \InvalidArgumentException('Invalid mail subject.');
        }

        $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
        $mailer->setSender([(string) $app->get('mailfrom'), (string) $app->get('fromname')]);
        $mailer->addRecipient($entry['email']);
        $mailer->setSubject($message['subject']);
        $html = MailHtml::render($message['body'], $tag, (string) $app->get('sitename'), null, $mailer, $known, $base);

        if ($html !== null) {
            $mailer->isHtml(true);
            $mailer->setBody($html);
            $mailer->AltBody = $message['body'];
        } else {
            $mailer->setBody($message['body']);
            $mailer->isHtml(false);
        }

        // No attachment: the entry arrives through the calendar synchronisation.
        if (!$mailer->send()) {
            throw new NotificationException('Mail delivery was rejected.');
        }
    }

    /** Name, address, parameters and state of an account, or null. */
    private function account(int $userId): ?object
    {
        $db = $this->db;
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['id', 'name', 'email', 'params', 'block']))->from($db->quoteName('#__users'))
            ->where($db->quoteName('id') . ' = :id')->bind(':id', $userId, ParameterType::INTEGER);

        return $db->setQuery($query)->loadObject() ?: null;
    }

    /** Whether the account may open the booking in the administrator. */
    private function opensBackend(int $userId): bool
    {
        $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userId);

        return (int) $user->id === $userId && (int) $user->block === 0
            && $user->authorise('core.login.admin') && $user->authorise('core.manage', 'com_appointments');
    }

    public function event(int $eventId): array
    {
        $db = $this->db;
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['e.id', 'e.title', 'e.description', 'e.duration_minutes', 'e.location_type', 'e.location_text',
            'e.host_user_id', 'e.calendar_id']))
            ->select($db->quoteName('u.email', 'host_email'))->select($db->quoteName('u.name', 'host_name'))
            ->select($db->quoteName('u.params', 'host_params'))
            ->from($db->quoteName('#__appointments_eventtypes', 'e'))
            ->join('INNER', $db->quoteName('#__users', 'u'), $db->quoteName('u.id') . ' = ' . $db->quoteName('e.host_user_id'))
            ->where($db->quoteName('e.id') . ' = ' . $eventId);
        $event = $db->setQuery($query)->loadAssoc();

        if (!$event || !filter_var($event['host_email'], FILTER_VALIDATE_EMAIL)) {
            throw new NotificationException('The appointment host has no valid mail address.');
        }

        $event['description'] = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", (string) $event['description'])), ENT_QUOTES, 'UTF-8');

        return $event;
    }

    public function template(int $eventId, string $purpose, string $language): array
    {
        $db = $this->db;
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['subject', 'body']))->from($db->quoteName('#__appointments_templates'))
            ->where($db->quoteName('eventtype_id') . ' IN (0,' . $eventId . ')')
            ->where($db->quoteName('purpose') . ' = ' . $db->quote($purpose))
            ->where($db->quoteName('language') . ' = ' . $db->quote($language))
            ->where($db->quoteName('published') . ' = 1')
            ->order($db->quoteName('eventtype_id') . ' DESC')->setLimit(1);
        $row = $db->setQuery($query)->loadAssoc();
        if ($row) {
            return $row;
        }

        [$subject, $body] = DefaultTemplates::all()[$purpose]
            ?? throw new NotificationException('No template is available for this notification.');
        $strings = new Language($language);
        $strings->load('com_appointments', JPATH_ADMINISTRATOR . '/components/com_appointments', $language, true);

        return ['subject' => $strings->_($subject), 'body' => str_replace('\n', "\n", $strings->_($body))];
    }

    /**
     * Public site URL for HTML mail layouts: the component's public_site_url, then live_site
     * (PublicSiteUrl), never the address of the request. Null when none is configured.
     */
    private function publicBase(): ?string
    {
        return PublicSiteUrl::current();
    }

    /**
     * The signed reschedule, cancel and erase addresses of $booking (id, modified_utc and, when
     * known, eventtype_id). $language is the language of the reader (a tag such as de-DE), which
     * chooses the menu item that frames the pages (ManageMenuItem); '' uses the site default.
     */
    public function links(array $booking, string $language = ''): array
    {
        $base = $this->linkBase();
        $signed = new SignedLink(new KeyProvider((string) Factory::getApplication()->get('secret')));
        $result = [];
        $names = ['reschedule' => 'reschedule_url', 'cancel' => 'cancel_url', 'erase' => 'erase_url'];
        $frame = $this->frame($booking, $language);

        foreach ($names as $action => $key) {
            $result[$key] = $base . '/index.php?' . http_build_query([
                'option' => 'com_appointments', 'view' => 'manage', 'id' => (int) $booking['id'], 'action' => $action,
                'signature' => $signed->sign((int) $booking['id'], $action, $booking['modified_utc']),
            ] + $frame);
        }

        return $result;
    }

    /**
     * The guest's own address and the signed link that removes only that address from the
     * booking. The address is bound into the signature, never written into the URL.
     */
    public function guestLinks(array $booking, string $guest, string $language = ''): array
    {
        $signed = new SignedLink(new KeyProvider((string) Factory::getApplication()->get('secret')));

        return [
            'guest_email' => $guest,
            'guest_remove_url' => $this->linkBase() . '/index.php?' . http_build_query([
                'option' => 'com_appointments', 'task' => 'guest.confirm', 'id' => (int) $booking['id'],
                'signature' => $signed->signGuest((int) $booking['id'], $guest, $booking['modified_utc']),
            ] + $this->frame($booking, $language)),
        ];
    }

    /**
     * ['Itemid' => …] of the menu item that frames the signed pages of $booking, or [] for none.
     * The parameter only picks the page frame; the signature does not cover it and needs not to.
     *
     * @return array<string, int>
     */
    private function frame(array $booking, string $language): array
    {
        $eventtypeId = (int) ($booking['eventtype_id'] ?? 0);

        if ($eventtypeId < 1 && (int) ($booking['id'] ?? 0) > 0) {
            $eventtypeId = (int) $this->db->setQuery('SELECT ' . $this->db->quoteName('eventtype_id') . ' FROM '
                . $this->db->quoteName('#__appointments_bookings') . ' WHERE ' . $this->db->quoteName('id') . ' = '
                . (int) $booking['id'])->loadResult();
        }

        if ($language === '') {
            $language = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
        }

        $itemId = ManageMenuItem::find($this->db, $eventtypeId, $language);

        return $itemId > 0 ? ['Itemid' => $itemId] : [];
    }

    /**
     * Public site root for signed links, without a trailing slash: the configured address only
     * (PublicSiteUrl). Never the address of the request, whose Host header a visitor chooses.
     */
    private function linkBase(): string
    {
        return PublicSiteUrl::current()
            ?? throw new NotificationException('No valid public site URL is configured (component option or live_site).');
    }
}
