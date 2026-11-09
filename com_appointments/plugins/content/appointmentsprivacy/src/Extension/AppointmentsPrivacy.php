<?php
/**
 * @package     Appointments Privacy Completion Guard
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\Content\AppointmentsPrivacy\Extension;

use Advans\Component\Appointments\Administrator\Service\PrivacyOutcome;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Privacy\Administrator\Table\RequestTable;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;

defined('_JEXEC') or die;

/** Core AdminModel imports content plugins before its native save event. */
final class AppointmentsPrivacy extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return ['onContentBeforeSave' => 'onContentBeforeSave'];
    }

    public function onContentBeforeSave(EventInterface $event): void
    {
        $table = $event->getArgument('subject');
        if (!$table instanceof RequestTable || $table->request_type !== 'remove' || (int) $table->status !== 2) {
            return;
        }
        $this->getApplication()->bootComponent('com_appointments');
        if (PrivacyOutcome::incomplete($this->getDatabase(), (int) $table->id)) {
            $table->setError(Text::_('PLG_CONTENT_APPOINTMENTSPRIVACY_BLOCKED'));
            $event->addResult(false);
        }
    }
}
