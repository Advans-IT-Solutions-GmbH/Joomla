<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service;

use Advans\Component\Appointments\Administrator\Helper\BookingData;
use Advans\Component\Appointments\Administrator\Helper\DefaultTemplates;
use Advans\Component\Appointments\Administrator\Helper\IcsWriter;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\MailComposer;
use Advans\Component\Appointments\Administrator\Helper\MailHtml;
use Advans\Component\Appointments\Administrator\Helper\MailLanguage;
use Advans\Component\Appointments\Administrator\Helper\MailPresentation;
use Advans\Component\Appointments\Administrator\Helper\ManageMenuItem;
use Advans\Component\Appointments\Administrator\Helper\PrivacyAddress;
use Advans\Component\Appointments\Administrator\Helper\PublicSiteUrl;
use Advans\Component\Appointments\Administrator\Helper\SignedLink;
use Joomla\CMS\Factory;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Mail\Exception\MailDisabledException;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

final class NotificationService
{
    private ?BookingData $data = null;

    public function __construct(private DatabaseInterface $db)
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
            $recipients[] = [$event['host_email'], $hostLanguage, true, false, false];
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

    public function event(int $eventId): array
    {
        $db = $this->db;
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['e.id', 'e.title', 'e.description', 'e.duration_minutes', 'e.location_type', 'e.location_text']))
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
