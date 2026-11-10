<?php
/**
 * @package     Appointments Privacy Completion Guard
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\Content\AppointmentsPrivacy\Extension;

use Advans\Component\Appointments\Administrator\Service\PrivacyOperationLock;
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

    /** @var array<int, PrivacyOperationLock> held from the veto check through the native save */
    private array $locks = [];

    public static function getSubscribedEvents(): array
    {
        return ['onContentBeforeSave' => 'onContentBeforeSave', 'onContentAfterSave' => 'onContentAfterSave'];
    }

    public function onContentBeforeSave(EventInterface $event): void
    {
        $table = $event->getArgument('subject');
        if (!$table instanceof RequestTable || $table->request_type !== 'remove' || (int) $table->status !== 2) {
            return;
        }
        $id = (int) $table->id;
        $this->getApplication()->bootComponent('com_appointments');
        $db = $this->getDatabase();
        if (!isset($this->locks[$id])) {
            try {
                $lock = PrivacyOperationLock::acquire($db, $id, (string) $this->getApplication()->get('secret'));
            } catch (\Throwable) {
                $lock = null;
            }
            if ($lock === null) {
                $table->setError(Text::_('PLG_CONTENT_APPOINTMENTSPRIVACY_BUSY'));
                $event->addResult(false);
                return;
            }
            $this->locks[$id] = $lock;
        }
        $error = PrivacyOperationLock::state($db, $id) !== ['status' => 1, 'type' => 'remove']
            ? 'PLG_CONTENT_APPOINTMENTSPRIVACY_CHANGED'
            : (PrivacyOutcome::incomplete($db, $id) ? 'PLG_CONTENT_APPOINTMENTSPRIVACY_BLOCKED' : '');
        if ($error !== '') {
            $this->release($id);
            $table->setError(Text::_($error));
            $event->addResult(false);
        }
    }

    public function onContentAfterSave(EventInterface $event): void
    {
        $table = $event->getArgument('subject');
        if ($table instanceof RequestTable) {
            $this->release((int) $table->id);
        }
    }

    private function release(int $id): void
    {
        $lock = $this->locks[$id] ?? null;
        unset($this->locks[$id]);
        $lock?->release();
    }
}
