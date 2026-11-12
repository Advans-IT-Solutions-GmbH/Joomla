<?php
/**
 * @package     Appointments Component
 * @subpackage  Site
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\Controller;

use Advans\Component\Appointments\Administrator\Helper\NotificationSwitch;
use Advans\Component\Appointments\Site\View\Mycalendars\HtmlView;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

defined('_JEXEC') or die;

/**
 * «My calendars»: a user switches the booking mails of one of their calendars on or off.
 *
 * Only for themselves: the account is always the signed-in user of this session, never an id
 * from the request, so nobody (a calendar owner or an administrator in the frontend included)
 * can change the setting of another person. The task takes POST only with the form token (403
 * otherwise), the same gate as the page (404 for a guest or a disabled feature, 403 for an
 * account outside the internet calendar groups) and a calendar the user has an effective access
 * to (404 otherwise, like a calendar that does not exist).
 */
class NotificationsController extends BaseController
{
    protected $default_view = 'mycalendars';

    public function save(): bool
    {
        // Checked here, not with Session::checkToken(): that one redirects a new session instead of refusing.
        if ($this->input->getMethod() !== 'POST' || $this->input->post->get(Session::getFormToken(), '', 'alnum') !== '1') {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }

        $services = HtmlView::requireAccess();
        HtmlView::privateHeaders();
        $userId = (int) $this->app->getIdentity()->id;
        $calendarId = $this->input->post->getInt('calendar', 0);
        $enabled = $this->input->post->getString('enabled', '');
        $back = Route::_('index.php?option=com_appointments&view=mycalendars', false);

        if ($enabled !== '0' && $enabled !== '1') {
            $this->setRedirect($back, Text::_('COM_APPOINTMENTS_NOTIFY_ERROR'), 'error');

            return false;
        }

        try {
            $access = $calendarId > 0 ? $services->grants()->accessFor($userId, $calendarId) : null;
        } catch (\Throwable $e) {
            return $this->failed($back, $e);
        }

        // Another account's calendar, a revoked grant and an unknown id are «not found» alike.
        if ($access === null) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        try {
            NotificationSwitch::set($services->db(), $userId, $calendarId, $enabled === '1');
            $hosts = $enabled === '0' && \in_array($calendarId, NotificationSwitch::hostedCalendars($services->db(), $userId), true);
        } catch (\Throwable $e) {
            return $this->failed($back, $e);
        }

        $title = htmlspecialchars($access->title, ENT_QUOTES, 'UTF-8');

        if ($enabled === '1') {
            $this->setRedirect($back, Text::sprintf('COM_APPOINTMENTS_NOTIFY_SAVED_ON', $title));
        } elseif ($hosts) {
            // The host learns of new bookings only through the calendar from now on: a warning, not a plain message.
            $this->setRedirect($back, Text::sprintf('COM_APPOINTMENTS_NOTIFY_SAVED_OFF_HOST', $title), 'warning');
        } else {
            $this->setRedirect($back, Text::sprintf('COM_APPOINTMENTS_NOTIFY_SAVED_OFF', $title));
        }

        return true;
    }

    /** The setting is unchanged: a general message, the log only the class of the error, never its message. */
    private function failed(string $back, \Throwable $e): bool
    {
        Log::add('Internet calendar notification setting not saved: ' . \get_class($e), Log::ERROR, 'com_appointments');
        $this->setRedirect($back, Text::_('COM_APPOINTMENTS_NOTIFY_ERROR'), 'error');

        return false;
    }
}
