<?php
namespace Advans\Test\PrivacyRequestsProbe\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Privacy\Administrator\Removal\Status;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;

defined('_JEXEC') or die;

final class Probe extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    public static function getSubscribedEvents(): array
    {
        return ['onPrivacyCanRemoveData' => 'canRemove', 'onPrivacyRemoveData' => 'remove'];
    }

    public function canRemove(EventInterface $event): void
    {
        $this->record($event, 'can-remove');
        $mode = (string) $this->params->get('mode', 'block');
        if (str_starts_with($mode, 'conference-')) {
            $manager = $this->getApplication()->bootComponent('com_appointments')->getConferenceManager();
            if (!in_array('privacy-fixture', $manager->getProviderNames(), true)) {
                $manager->register(new class($this->getDatabase(), $mode) implements
                    \Advans\Component\Appointments\Administrator\Helper\Conference\ConferenceProviderInterface {
                    public function __construct(private \Joomla\Database\DatabaseInterface $db, private string $mode) {}
                    public function getName(): string { return 'privacy-fixture'; }
                    public function getConfigForm(): string { return ''; }
                    public function requiresOutboundAccess(): bool { return false; }
                    public function createMeeting(\Advans\Component\Appointments\Administrator\Helper\Conference\MeetingRequest $request):
                        \Advans\Component\Appointments\Administrator\Helper\Conference\MeetingResult
                    {
                        throw new \RuntimeException('This teardown-only fixture creates no external meetings.');
                    }
                    public function destroyMeeting(string $roomIdentifier): void
                    {
                        if ($this->mode === 'conference-fail') {
                            throw new \RuntimeException('Synthetic resource teardown is unavailable.');
                        }
                        $this->db->setQuery('DELETE FROM #__privacy_test_rooms WHERE room=' . $this->db->quote($roomIdentifier))->execute();
                    }
                });
            }
        }
        $status = new Status();
        if ($this->params->get('mode', 'block') === 'block') {
            $status->canRemove = false;
            $status->reason = 'Synthetic configured legal blocker.';
        }
        $event->addResult($status);
    }

    public function remove(EventInterface $event): void
    {
        $this->record($event, 'remove');
        $mode = $this->params->get('mode', 'block');
        if ($mode === 'throw') {
            throw new \RuntimeException('Synthetic privacy callback failure.');
        }
        if ($mode === 'warning') {
            $this->getApplication()->enqueueMessage('Synthetic unresolved privacy remainder.', 'warning');
        }
    }

    private function record(EventInterface $event, string $stage): void
    {
        if ($this->getApplication()->get('sitename') !== 'Privacy Test Fixture') {
            throw new \RuntimeException('Privacy acceptance probe requires an isolated synthetic fixture.');
        }
        $row = (object) ['request_id' => (int) $event->getArgument('subject')->id, 'stage' => $stage,
            'mode' => (string) $this->params->get('mode', 'block')];
        $this->getDatabase()->insertObject('#__privacy_test_events', $row);
    }
}
