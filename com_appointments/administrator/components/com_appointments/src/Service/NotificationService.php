<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service;

use Advans\Component\Appointments\Administrator\Helper\DefaultTemplates;
use Advans\Component\Appointments\Administrator\Helper\IcsWriter;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\MailComposer;
use Advans\Component\Appointments\Administrator\Helper\MailHtml;
use Advans\Component\Appointments\Administrator\Helper\MailPresentation;
use Advans\Component\Appointments\Administrator\Helper\PrivacyAddress;
use Advans\Component\Appointments\Administrator\Helper\SignedLink;
use Joomla\CMS\Factory;
use Joomla\CMS\Application\ConsoleApplication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Mail\Exception\MailDisabledException;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

final class NotificationService
{
    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * The guest list of booking $id as stored now. A copy without an id keeps its own list; a
     * booking that is no longer stored has no guests to write to.
     */
    private function currentGuests(int $id, mixed $copy): ?string
    {
        if ($id < 1) {
            return is_string($copy) ? $copy : null;
        }

        $query = method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
        $query->select($this->db->quoteName('guest_emails'))->from($this->db->quoteName('#__appointments_bookings'))
            ->where($this->db->quoteName('id') . ' = ' . $id);
        $rows = $this->db->setQuery($query)->loadColumn();

        return is_string($rows[0] ?? null) ? $rows[0] : null;
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
        $event = $this->event((int) $booking['eventtype_id']);
        $hostParams = json_decode((string) $event['host_params'], true) ?: [];
        $hostLanguage = (string) ($hostParams['language'] ?? 'en-GB');
        $hostLanguage = in_array($hostLanguage, ['en-GB', 'de-DE', 'fr-FR'], true) ? $hostLanguage : 'en-GB';
        $inviteeLanguage = (string) ($booking['invitee_language'] ?? 'en-GB');
        $recipients = [];
        // The guests of the stored booking now, not of the copy the caller loaded earlier: a guest
        // who removed their address in the meantime gets no further mail and is no attendee of the
        // calendar entry.
        $booking['guest_emails'] = $this->currentGuests((int) $booking['id'], $booking['guest_emails'] ?? null);

        if (!$hostOnly && !empty($booking['invitee_email'])) {
            $recipients[] = [$booking['invitee_email'], $inviteeLanguage, false, true, false];
            // A guest gets the guest template of the purpose, with a neutral salutation and the
            // link that removes only their own address. A purpose without one is not sent to guests.
            if (isset(DefaultTemplates::GUEST[$purpose])) {
                foreach (PrivacyAddress::guestList($booking['guest_emails'] ?? null) as $guest) {
                    $recipients[] = [$guest, $inviteeLanguage, false, false, true];
                }
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
                $links = $canManage ? $this->links($booking) : ($isGuest ? $this->guestLinks($booking, $address) : []);
                $recipientBooking = $booking;
                if ($isHost && !empty($booking['conference_url'])) {
                    $recipientBooking['conference_url'] = $conferences->hostUrl($booking) ?? $booking['conference_url'];
                }
                $message = (new MailComposer(new IcsWriter()))->compose($template, $recipientBooking, $event, $links, $now);
                // Each URL on a line of its own: a signed URL is long and has no break opportunity. The HTML
                // part shows the label as link text instead (MailHtml), the text part keeps the full URL.
                if (!empty($recipientBooking['conference_url']) && !str_contains($message['body'], $recipientBooking['conference_url'])) {
                    $message['body'] .= "\n\n" . $language->_('COM_APPOINTMENTS_MAIL_JOIN') . ":\n" . $recipientBooking['conference_url'];
                }

                if ($canManage) {
                    $message['body'] .= "\n\n" . $language->_('COM_APPOINTMENTS_MAIL_RESCHEDULE') . ":\n" . $links['reschedule_url']
                        . "\n" . $language->_('COM_APPOINTMENTS_MAIL_CANCEL') . ":\n" . $links['cancel_url']
                        . "\n" . $language->_('COM_APPOINTMENTS_MAIL_DELETE') . ":\n" . $links['erase_url'];
                }

                if ($isGuest) {
                    $message['body'] .= "\n\n" . $language->_('COM_APPOINTMENTS_MAIL_GUEST_REMOVE') . ":\n" . $links['guest_remove_url'];
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
        foreach (array_unique([$language, 'en-GB']) as $tag) {
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->select($db->quoteName(['subject', 'body']))->from($db->quoteName('#__appointments_templates'))
                ->where($db->quoteName('eventtype_id') . ' IN (0,' . $eventId . ')')
                ->where($db->quoteName('purpose') . ' = ' . $db->quote($purpose))
                ->where($db->quoteName('language') . ' = ' . $db->quote($tag))
                ->where($db->quoteName('published') . ' = 1')
                ->order($db->quoteName('eventtype_id') . ' DESC')->setLimit(1);
            $row = $db->setQuery($query)->loadAssoc();
            if ($row) {
                return $row;
            }
        }

        throw new NotificationException('No published template is available for this notification.');
    }

    /**
     * Public site URL for HTML mail layouts: the component's public_site_url, then live_site, then the
     * request root outside the CLI. Null when none is known (CLI without configuration).
     */
    private function publicBase(): ?string
    {
        $app  = Factory::getApplication();
        $base = (string) ComponentHelper::getParams('com_appointments')->get('public_site_url', '');

        if ($base === '') {
            $base = (string) $app->get('live_site', '');
        }

        if ($base === '' && !($app instanceof ConsoleApplication)) {
            $base = Uri::root();
        }

        return filter_var($base, FILTER_VALIDATE_URL) && preg_match('#\Ahttps?://#i', $base) === 1 ? $base : null;
    }

    public function links(array $booking): array
    {
        $base = $this->linkBase();
        $signed = new SignedLink(new KeyProvider((string) Factory::getApplication()->get('secret')));
        $result = [];
        $names = ['reschedule' => 'reschedule_url', 'cancel' => 'cancel_url', 'erase' => 'erase_url'];

        foreach ($names as $action => $key) {
            $result[$key] = $base . '/index.php?' . http_build_query([
                'option' => 'com_appointments', 'view' => 'manage', 'id' => (int) $booking['id'], 'action' => $action,
                'signature' => $signed->sign((int) $booking['id'], $action, $booking['modified_utc']),
            ]);
        }

        return $result;
    }

    /**
     * The guest's own address and the signed link that removes only that address from the
     * booking. The address is bound into the signature, never written into the URL.
     */
    public function guestLinks(array $booking, string $guest): array
    {
        $signed = new SignedLink(new KeyProvider((string) Factory::getApplication()->get('secret')));

        return [
            'guest_email' => $guest,
            'guest_remove_url' => $this->linkBase() . '/index.php?' . http_build_query([
                'option' => 'com_appointments', 'task' => 'guest.confirm', 'id' => (int) $booking['id'],
                'signature' => $signed->signGuest((int) $booking['id'], $guest, $booking['modified_utc']),
            ]),
        ];
    }

    /** Public site root for signed links, without a trailing slash. */
    private function linkBase(): string
    {
        $app = Factory::getApplication();
        $base = (string) ComponentHelper::getParams('com_appointments')->get('public_site_url', '');
        if ($base === '') {
            $base = (string) $app->get('live_site', '');
        }
        if ($base === '') {
            if ($app instanceof ConsoleApplication) {
                throw new NotificationException('Configure the public site URL before running reminders from the CLI.');
            }
            $base = Uri::root();
        }
        $parts = parse_url($base);
        if (!filter_var($base, FILTER_VALIDATE_URL) || !is_array($parts)
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new NotificationException('The appointment public site URL is invalid.');
        }

        return rtrim($base, '/');
    }
}
