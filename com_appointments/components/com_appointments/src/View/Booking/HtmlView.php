<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\View\Booking;

use Advans\Component\Appointments\Site\Helper\NoPageCache;
use Advans\Component\Appointments\Administrator\Service\NotificationException;
use Advans\Component\Appointments\Administrator\Service\NotificationService;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Advans\Component\Appointments\Site\View\AssetsTrait;
use Advans\Component\Appointments\Site\View\PageTitleTrait;
use Advans\Component\Appointments\Site\View\ResultNoticesTrait;

defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    use PageTitleTrait;
    use AssetsTrait;
    use ResultNoticesTrait;

    protected $item;
    /** Address of the .ics download of the appointment, or '' when there is nothing to add. */
    protected string $calendarLink = '';
    /** Signed reschedule and cancel addresses, as in the confirmation email; empty when unavailable. */
    protected array $manageLinks = [];

    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $app->setHeader('Cache-Control', 'private, no-store', true);
        // Nor Joomla's page cache: the page carries signed links and tokens of one browser session.
        NoPageCache::apply($app);
        $this->item = $this->getModel()->getItem();

        // A cancelled appointment has nothing to add to a calendar and nothing left to change.
        if ((int) $this->item->state !== 2) {
            // The id binds the download to the appointment shown: after a second booking in another
            // tab, this page's link must not return the newer appointment (the controller checks it).
            $this->calendarLink = Route::_('index.php?option=com_appointments&task=booking.ics&id=' . (int) $this->item->id);

            try {
                $links = (new NotificationService(Factory::getContainer()->get(DatabaseInterface::class)))
                    ->links(['id' => (int) $this->item->id, 'eventtype_id' => (int) $this->item->eventtype_id,
                        'modified_utc' => (string) $this->item->modified_utc], $app->getLanguage()->getTag());
                $this->manageLinks = array_intersect_key($links, array_flip(['reschedule_url', 'cancel_url']));
            } catch (NotificationException $e) {
                // Without a valid public site address the links are only in the email.
                $this->manageLinks = [];
            }
        }

        // The script focuses the outcome after the redirect to #appointment-confirmation.
        $this->useAppointmentsAssets(true);
        $this->takeResultNotices();
        $this->getDocument()->setMetaData('robots', 'noindex,nofollow');
        // The heading says what happened: confirmed, requested (held for the host's approval) or cancelled.
        $this->applyPageTitle('booking', Text::_(match ((int) $this->item->state) {
            1 => 'COM_APPOINTMENTS_BOOKING_CONFIRMED_TITLE',
            2 => 'COM_APPOINTMENTS_BOOKING_CANCELLED_TITLE',
            default => 'COM_APPOINTMENTS_BOOKING_CONFIRMED_HEADING',
        }));

        return parent::display($tpl);
    }
}
