<?php
namespace Advans\Test\PrivacyRequestsMailProbe\Extension;

defined('_JEXEC') or die;

final class MailProbe extends \Joomla\CMS\Plugin\CMSPlugin implements \Joomla\Event\SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onMailBeforeRendering' => 'beforeMail'];
    }

    public function beforeMail(\Joomla\Event\EventInterface $event): void
    {
        if ($this->getApplication()->get('sitename') !== 'Privacy Test Fixture') {
            throw new \RuntimeException('Refusing to alter non-synthetic mail.');
        }
        if ($event->getArgument('templateId') === 'com_privacy.notification.remove') {
            throw new \RuntimeException('Synthetic installed mail-rendering callback failure.');
        }
    }
}
