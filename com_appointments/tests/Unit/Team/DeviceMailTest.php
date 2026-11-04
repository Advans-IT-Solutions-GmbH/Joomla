<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\DefaultTemplates;
use Advans\Component\Appointments\Administrator\Service\Team\TeamMailer;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Conference/RuntimeStubs.php';
$teamDir = dirname(__DIR__, 3) . '/administrator/components/com_appointments/src/Service/Team';
require_once $teamDir . '/TeamMailerInterface.php';
require_once $teamDir . '/TeamMailer.php';

/**
 * Work package D: the mails of the internet calendars are DB templates without
 * a calendar entry, and a user's text can neither add lines nor headers.
 */
final class DeviceMailTest extends TestCase
{
    private const LANGUAGE_DIR = __DIR__ . '/../../../administrator/language';

    public function testTeamPurposesAreSeededButNeverSentAsBookingMails(): void
    {
        self::assertSame(
            ['team_device_added', 'team_invitation', 'team_invitation_accepted', 'team_grant_changed', 'team_access_suspended'],
            array_keys(TeamMailer::purposes())
        );

        foreach (array_keys(DefaultTemplates::TEAM_KEYS) as $purpose) {
            self::assertArrayHasKey($purpose, DefaultTemplates::all());
            self::assertArrayNotHasKey($purpose, DefaultTemplates::KEYS);
            self::assertNotContains($purpose, DefaultTemplates::SENT);
        }
    }

    public function testEveryTemplateHasItsDefaultTextInEveryLanguage(): void
    {
        foreach (['en-GB', 'de-DE', 'fr-FR'] as $tag) {
            $strings = parse_ini_file(self::LANGUAGE_DIR . '/' . $tag . '/com_appointments.ini', false, INI_SCANNER_RAW);
            self::assertIsArray($strings);

            foreach (DefaultTemplates::TEAM_KEYS as $purpose => [$subject, $body]) {
                self::assertNotSame('', trim((string) ($strings[$subject] ?? '')), "$tag $purpose subject");
                self::assertNotSame('', trim((string) ($strings[$body] ?? '')), "$tag $purpose body");
            }
        }
    }

    public function testTheDeviceNoticeTellsWhatToDoAndCarriesNoSecretPlaceholder(): void
    {
        foreach (['en-GB', 'de-DE', 'fr-FR'] as $tag) {
            $strings = parse_ini_file(self::LANGUAGE_DIR . '/' . $tag . '/com_appointments.ini', false, INI_SCANNER_RAW);
            $body = (string) $strings['COM_APPOINTMENTS_TEAM_D_MAIL_DEVICE_ADDED_BODY'];

            foreach (['{device_name}', '{client}', '{created}', '{mycalendars_url}'] as $placeholder) {
                self::assertStringContainsString($placeholder, $body, $tag);
            }

            self::assertDoesNotMatchRegularExpression('/\{[a-z_]*(password|secret|token|key)[a-z_]*\}/', $body, $tag);
        }
    }

    public function testValuesCannotAddLinesOrHeaders(): void
    {
        $filled = TeamMailer::fill(
            ['subject' => 'Device {device_name}', 'body' => "Device: {device_name}\nProgram: {client}"],
            ['{device_name}' => "Phone\r\nBcc: someone@example.test", '{client}' => "Thunderbird\n\nPassword: x"]
        );

        self::assertStringNotContainsString("\n", $filled['subject']);
        self::assertStringNotContainsString("\r", $filled['subject']);
        self::assertSame("Device: Phone Bcc: someone@example.test\nProgram: Thunderbird Password: x", $filled['body']);
    }

    public function testOnlyTheMailsOwnLinksStayLinks(): void
    {
        $link = 'https://site.example/index.php?option=com_appointments&view=activation&token=abc';
        $filled = TeamMailer::fill(
            ['subject' => 'S', 'body' => "{account_name}\n{activation_url}"],
            ['{account_name}' => 'Please confirm: https://evil.example/login', '{activation_url}' => $link],
            [$link]
        );
        [$name, $url] = explode("\n", $filled['body']);

        self::assertStringNotContainsString('https://', $name);
        self::assertStringContainsString('evil.example', $name);
        self::assertSame($link, $url);
    }

    public function testPlaceholdersAreReplacedOnceAndNotRecursively(): void
    {
        $filled = TeamMailer::fill(
            ['subject' => '{site_name}', 'body' => '{device_name} on {site_name}'],
            ['{site_name}' => 'Site', '{device_name}' => '{site_name}']
        );

        self::assertSame('{site_name} on Site', $filled['body']);
    }
}
