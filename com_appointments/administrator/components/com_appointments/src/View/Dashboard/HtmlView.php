<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\View\Dashboard;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;

defined('_JEXEC') or die;

/**
 * Administrator dashboard.
 *
 * @since  1.0.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * @var  array{eventtypes:int, bookings:int}
     */
    protected $counts = ['eventtypes' => 0, 'bookings' => 0];
    protected $eventCounts = [];
    protected array $closeRows = [];
    protected int $closeStart = 0;
    protected int $closeTotal = 0;
    protected bool $closeEnabled = false;
    protected bool $closeConfigured = false;

    /** @var array{waiting: int, first_forced: ?string, missed: int} */
    protected array $closeWaiting = ['waiting' => 0, 'first_forced' => null, 'missed' => 0];

    public function display($tpl = null)
    {
        // An administrator's visit continues the migration of booking rows (team-calendars.md, 5.10).
        $unverified = \Advans\Component\Appointments\Administrator\Model\DashboardModel::continueMigration();

        if ($unverified > 0) {
            Factory::getApplication()->enqueueMessage(Text::sprintf('COM_APPOINTMENTS_TEAM_E_BOOKINGS_UNVERIFIED_NOTICE', $unverified), 'warning');
        }

        $this->counts = $this->getModel()->getCounts();
        $this->eventCounts = $this->getModel()->getEventCounts();

        // Bookings whose retention waits for the CRM transfer, or that were erased without it: a warning
        // for everybody who manages the bookings, with counts and a date only.
        try {
            $this->closeWaiting = (new \Advans\Component\Appointments\Administrator\Service\SchedulerService(
                Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class),
                \Joomla\CMS\Component\ComponentHelper::getParams('com_appointments')))
                ->closeWaiting(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } catch (\Throwable $e) {
            $this->closeWaiting = ['waiting' => 0, 'first_forced' => null, 'missed' => 0];
        }
        if (Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_appointments')) {
            $params = \Joomla\CMS\Component\ComponentHelper::getParams('com_appointments');
            $this->closeEnabled = (int) $params->get('close_enabled', 0) === 1;
            $queue = new \Advans\Component\Appointments\Administrator\Service\Close\Queue(
                Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class), $params);
            $this->closeTotal = $queue->count();
            $this->closeStart = min(max(0, Factory::getApplication()->getInput()->getInt('close_start')),
                max(0, (int) (ceil($this->closeTotal / 100) - 1) * 100));
            $this->closeStart = intdiv($this->closeStart, 100) * 100;
            $this->closeRows = $queue->rows($this->closeStart);
            try {
                new \Advans\Component\Appointments\Administrator\Service\Close\Settings($params);
                $this->closeConfigured = true;
            } catch (\InvalidArgumentException|\JsonException $e) {
                $this->closeConfigured = false;
            }
        }

        ToolbarHelper::title(Text::_('COM_APPOINTMENTS_DASHBOARD_TITLE'), 'calendar');

        $toolbar = Toolbar::getInstance('toolbar');

        $toolbar->linkButton('schedules')
            ->url('index.php?option=com_appointments&view=schedules')
            ->icon('icon-calendar')
            ->text(Text::_('COM_APPOINTMENTS_TOOLBAR_SCHEDULES'));

        $toolbar->linkButton('blockeds')
            ->url('index.php?option=com_appointments&view=blockeds')
            ->icon('icon-minus-circle')
            ->text(Text::_('COM_APPOINTMENTS_TOOLBAR_BLOCKED'));

        if (Factory::getApplication()->getIdentity()->authorise('core.options', 'com_appointments')) {
            ToolbarHelper::preferences('com_appointments');
        }

        return parent::display($tpl);
    }
}
