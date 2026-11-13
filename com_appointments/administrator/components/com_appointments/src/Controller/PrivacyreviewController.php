<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Controller;

use Advans\Component\Appointments\Administrator\Service\Team\PrivacyReview;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

defined('_JEXEC') or die;

/**
 * The decision on one entry of a calendar's own found by a privacy request. POST with the form
 * token, `core.manage` on the component and on com_privacy; the review service checks the
 * calendar access and the unlocked key itself.
 *
 * @since  1.5.0
 */
class PrivacyreviewController extends BaseController
{
    public function apply(): bool
    {
        if ($this->input->getMethod() !== 'POST' || $this->input->post->get(Session::getFormToken(), '', 'alnum') !== '1') {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }

        $user = $this->app->getIdentity();

        if (!$user->authorise('core.manage', 'com_appointments') || !$user->authorise('core.manage', 'com_privacy')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $this->setRedirect(Route::_('index.php?option=com_appointments&view=privacyreview&start=' . max(0, $this->input->post->getInt('start', 0)), false));
        $result = 'COM_APPOINTMENTS_PRIVACY_REVIEW_ERROR';

        try {
            $services = TeamServices::fromApplication();

            if ($services->enabled()) {
                $result = (new PrivacyReview($services))->apply($this->input->post->getInt('id', 0),
                    $this->input->post->getCmd('decision', ''), (int) $user->id, Text::_('COM_APPOINTMENTS_PRIVACY_REVIEW_PLACEHOLDER'),
                    $this->input->post->getAlnum('etag', ''));
            }
        } catch (\Throwable $e) {
            $result = 'COM_APPOINTMENTS_PRIVACY_REVIEW_ERROR';
        }

        $done = \in_array($result, ['COM_APPOINTMENTS_PRIVACY_REVIEW_ADDRESS_REMOVED', 'COM_APPOINTMENTS_PRIVACY_REVIEW_DELETED',
            'COM_APPOINTMENTS_PRIVACY_REVIEW_KEPT', 'COM_APPOINTMENTS_PRIVACY_REVIEW_GONE'], true);
        $this->app->enqueueMessage(Text::_($result), $done ? 'message' : 'warning');

        return $done;
    }
}