<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\View\Privacyreview;

use Advans\Component\Appointments\Administrator\Service\Team\PrivacyReview;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;

defined('_JEXEC') or die;

/**
 * Entries of a calendar's own found by a Joomla privacy request, each with the decision of the
 * person handling it: remove only the address (default), delete the entry or leave it.
 * For whoever handles privacy requests (`core.manage` on com_privacy); the contents open only
 * with their own access and their personal key unlocked in this session.
 *
 * @since  1.5.0
 */
class HtmlView extends BaseHtmlView
{
    protected bool $enabled = false;

    protected bool $unlocked = false;

    /** @var array<int, array<string, mixed>> */
    protected array $rows = [];

    protected int $start = 0;

    protected int $total = 0;

    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();

        if (!$user->authorise('core.manage', 'com_privacy')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $app->setHeader('Cache-Control', 'private, no-store', true);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);

        try {
            $services = TeamServices::fromApplication();
            $this->enabled = $services->enabled();

            if ($this->enabled) {
                $this->unlocked = $services->keySession()->current() !== null;
                $review = new PrivacyReview($services);
                $this->total = $review->count();
                $this->start = min(max(0, $app->getInput()->getInt('start', 0)), max(0, (int) (ceil($this->total / 100) - 1) * 100));
                $this->start = intdiv($this->start, 100) * 100;
                $this->rows = $review->pending((int) $user->id, 100, $this->start);
            }
        } catch (\Throwable $e) {
            $this->enabled = false;
        }

        ToolbarHelper::title(Text::_('COM_APPOINTMENTS_PRIVACY_REVIEW_TITLE'), 'lock');
        Toolbar::getInstance('toolbar')->linkButton('back')
            ->url('index.php?option=com_appointments')
            ->text(Text::_('JTOOLBAR_BACK'));

        return parent::display($tpl);
    }
}