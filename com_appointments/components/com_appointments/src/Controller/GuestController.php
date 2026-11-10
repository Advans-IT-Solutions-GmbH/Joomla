<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\Controller;

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\PrivacyAddress;
use Advans\Component\Appointments\Administrator\Helper\SignedLink;
use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Advans\Component\Appointments\Administrator\Service\BookingStore;
use Advans\Component\Appointments\Administrator\Service\InvalidBookingLinkException;
use Advans\Component\Appointments\Administrator\Service\RateLimiter;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\MVC\View\HtmlView;
use Advans\Component\Appointments\Site\View\AssetsTrait;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * The signed link in every mail to an additional guest. The guest removes their own address
 * from the booking, nothing else: the booking, the data of the person who booked and the other
 * guests stay.
 *
 * - GET  task=guest.confirm&id=…&signature=…  shows what will happen and a POST form with the
 *   CSRF token. Nothing changes on GET.
 * - POST task=guest.remove                     removes the address, records the audit row and
 *   returns to the confirmation address, which then shows the receipt of this browser.
 *
 * The link carries no address: the signature is bound to booking id, address and row version,
 * and the address is found among the booking's guests. A tampered or used link, or one of a
 * changed or erased booking, answers 404 like the invitee's links. Both tasks are rate limited.
 */
class GuestController extends BaseController
{
    public function confirm(): bool
    {
        $this->limit();
        $id = $this->input->getInt('id', 0);
        $signature = $this->input->getString('signature', '');
        $completed = (bool) $this->app->getUserState($this->receiptKey($id, $signature), false);
        $data = ['completed' => $completed, 'id' => $id, 'signature' => $signature,
            'calendar_pending' => $completed && (bool) $this->app->getUserState($this->receiptKey($id, $signature, 'guest_calendar_pending'), false)];

        if (!$completed) {
            $booking = $this->booking($id);
            $guest = $booking === null ? null : $this->links()->guestFor($id, PrivacyAddress::guestList($booking['guest_emails']),
                (string) $booking['modified_utc'], $signature);

            if ($guest === null) {
                throw new InvalidBookingLinkException();
            }

            // The guest's mails show the appointment in the zone of the booking, so the page does too.
            $zone = new \DateTimeZone(in_array($booking['invitee_timezone'], \DateTimeZone::listIdentifiers(), true)
                ? $booking['invitee_timezone'] : 'UTC');
            $data += [
                'title' => (string) $booking['title'], 'guest' => $guest, 'zone' => $zone,
                'start' => UtcTimestamp::parse((string) $booking['start_utc'])->setTimezone($zone),
                'end' => UtcTimestamp::parse((string) $booking['end_utc'])->setTimezone($zone),
                // Unescaped: the layout escapes the address once for the attribute.
            'action' => Route::_('index.php?option=com_appointments&task=guest.remove', false),
            ];
        }

        $this->render($data);

        return true;
    }

    public function remove(): bool
    {
        $token = Session::getFormToken();

        if ($this->input->getMethod() !== 'POST' || $this->input->post->get($token, '', 'alnum') !== '1') {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }

        $this->limit();
        $id = $this->input->post->getInt('id', 0);
        $signature = $this->input->post->getString('signature', '');
        $receipt = $this->receiptKey($id, $signature);

        // A second submission of the same form (double click, reload) repeats nothing.
        if (!$this->app->getUserState($receipt, false)) {
            $result = (new BookingStore(Factory::getContainer()->get(DatabaseInterface::class)))->removeGuest(
                $id, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 'self_service', null, $this->links(), $signature
            );
            // The signed link cannot be used again once the address is gone, so a calendar copy that
            // could not be rewritten is reported to the guest and logged for the operator, never
            // shown as a complete removal.
            if (!empty($result['_calendar_cleanup_error'])) {
                Log::add('Appointment guest self-service removal left calendar copy cleanup outstanding for booking #'
                    . $id . '.', Log::ERROR, 'com_appointments');
            }
            $this->app->setUserState($this->receiptKey($id, $signature, 'guest_calendar_pending'), !empty($result['_calendar_cleanup_error']));
            $this->app->setUserState($receipt, true);
        }

        $this->setRedirect(Route::_('index.php?option=com_appointments&task=guest.confirm&id=' . $id
            . '&signature=' . urlencode($signature), false));

        return true;
    }

    private function limit(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $limiter = new RateLimiter(Factory::getContainer()->get(DatabaseInterface::class), new KeyProvider((string) $this->app->get('secret')));

        if (!$limiter->allow('guest', $this->input->server->getString('REMOTE_ADDR'), $now, 60, 900)) {
            throw new \RuntimeException(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_RATE'), 429);
        }
    }

    private function booking(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(array_merge(['b.id', 'b.start_utc', 'b.end_utc', 'b.invitee_timezone', 'b.modified_utc'],
            \Advans\Component\Appointments\Administrator\Helper\BookingData::readColumns('b'))))
            ->select($db->quoteName('e.title'))
            ->from($db->quoteName('#__appointments_bookings', 'b'))
            ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e'), $db->quoteName('e.id') . ' = ' . $db->quoteName('b.eventtype_id'))
            ->where($db->quoteName('b.id') . ' = ' . $id)
            ->where($db->quoteName('b.anonymised_utc') . ' IS NULL');
        $row = $db->setQuery($query)->loadAssoc() ?: null;

        // The guest list is encrypted at rest; one that cannot be decrypted matches no link.
        return $row === null ? null : \Advans\Component\Appointments\Administrator\Helper\BookingData::fromApplication()->openLenient($row);
    }

    private function links(): SignedLink
    {
        return new SignedLink(new KeyProvider((string) $this->app->get('secret')));
    }

    /**
     * User state is a dotted registry: the pending flag gets its own branch, because a child of the
     * receipt path would be overwritten when the receipt itself is set.
     */
    private function receiptKey(int $id, string $signature, string $branch = 'guest_removed'): string
    {
        return 'com_appointments.' . $branch . '.' . hash('sha256', $id . ':' . $signature);
    }

    /**
     * Renders tmpl/guest/default.php like a view, so a site template can override it at
     * html/com_appointments/guest/default.php. The page is a capability page: no referrer,
     * not indexed, not cached.
     */
    private function render(array $data): void
    {
        $this->app->setHeader('Referrer-Policy', 'no-referrer', true);
        $this->app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $this->app->setHeader('Cache-Control', 'private, no-store', true);
        // Neither Joomla's page cache may keep it: the page carries a form token and the guest's address.
        $this->app->allowCache(false);
        $document = $this->app->getDocument();
        $title = Text::_('COM_APPOINTMENTS_GUEST_TITLE');
        $document->setTitle(match ((int) $this->app->get('sitename_pagetitles', 0)) {
            1 => Text::sprintf('JPAGETITLE', $this->app->get('sitename'), $title),
            2 => Text::sprintf('JPAGETITLE', $title, $this->app->get('sitename')),
            default => $title,
        });

        $view = new class (['name' => 'guest', 'base_path' => JPATH_SITE . '/components/com_appointments']) extends HtmlView {
            // The stylesheet with the checksum of the served file, like every other site page.
            use AssetsTrait;

            /** @var array<string, mixed> */
            public array $page = [];

            public function useAssets(): void
            {
                $this->useAppointmentsAssets(false);
            }
        };
        $view->useAssets();
        $view->page = $data + ['heading' => $title];
        $view->display();
    }
}
