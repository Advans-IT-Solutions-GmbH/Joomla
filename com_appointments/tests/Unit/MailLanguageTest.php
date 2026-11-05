<?php

use Advans\Component\Appointments\Administrator\Helper\MailLanguage;
use PHPUnit\Framework\TestCase;

final class MailLanguageTest extends TestCase
{
    public function testEachSupportedAccountLanguageWinsOverEverySiteLanguage(): void
    {
        foreach (MailLanguage::SUPPORTED as $account) {
            foreach (MailLanguage::SUPPORTED as $site) {
                self::assertSame($account, MailLanguage::resolve($account, $site));
            }
        }
    }

    public function testGuestsAndUnsupportedAccountLanguagesUseTheSiteDefault(): void
    {
        foreach (MailLanguage::SUPPORTED as $site) {
            foreach (['', 'de-CH', 'it-IT', 'invalid'] as $account) {
                self::assertSame($site, MailLanguage::resolve($account, $site));
            }
        }

        self::assertSame('en-GB', MailLanguage::resolve('', 'invalid'));
    }
}
