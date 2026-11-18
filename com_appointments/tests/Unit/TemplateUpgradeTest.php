<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\MailComposer;
use Advans\Component\Appointments\Administrator\Helper\TemplateUpgrade;
use PHPUnit\Framework\TestCase;

/**
 * What an update does with stored mail templates (script.php, seedMailTemplates()): an unchanged
 * site-wide default follows the new default, a changed one stays and is reported when the new
 * default adds a placeholder it lacks. And the shipped host cancellation now names the reason.
 */
final class TemplateUpgradeTest extends TestCase
{
    private const OLD = "A cancellation.\nStatus: {state_label}\nFrom: {start}";
    private const NEW = "A cancellation.\nStatus: {state_label}\nReason for cancellation: {cancel_reason}\nFrom: {start}";

    public function testAnUnchangedDefaultFollowsTheNewDefault(): void
    {
        self::assertSame(TemplateUpgrade::FOLLOW, TemplateUpgrade::decide('Cancelled', self::OLD, 'Cancelled', self::OLD, 'Cancelled', self::NEW));
    }

    public function testAnEditedTemplateIsKept(): void
    {
        self::assertSame(TemplateUpgrade::KEEP, TemplateUpgrade::decide('Cancelled', self::OLD . ' ', 'Cancelled', self::OLD, 'Cancelled', self::NEW),
            'A trailing space is a change of the operator');
        self::assertSame(TemplateUpgrade::KEEP, TemplateUpgrade::decide('cancelled', self::OLD, 'Cancelled', self::OLD, 'Cancelled', self::NEW),
            'A change of case is a change of the operator');
        self::assertSame(TemplateUpgrade::KEEP, TemplateUpgrade::decide('Cancelled', self::OLD, 'Cancelled', self::OLD, 'Cancelled', self::OLD),
            'An unchanged default changes nothing');
    }

    public function testAddedAndMissingPlaceholders(): void
    {
        self::assertSame(['{cancel_reason}'], TemplateUpgrade::added(self::OLD, self::NEW));
        self::assertSame([], TemplateUpgrade::added(self::NEW, self::OLD));
        self::assertSame(['{cancel_reason}'], TemplateUpgrade::missing("Edited\n{start}", ['{cancel_reason}']));
        self::assertSame([], TemplateUpgrade::missing("Edited\nWhy: {cancel_reason}", ['{cancel_reason}']));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function languages(): array
    {
        return ['en-GB' => ['en-GB'], 'de-DE' => ['de-DE'], 'fr-FR' => ['fr-FR']];
    }

    /**
     * Audit 18.11.2026, B8: the host's cancellation mail names the reason the booking person gave,
     * on a line that disappears when there is none. The booking person's cancellation and the
     * guest's do not get it.
     *
     * @dataProvider languages
     */
    public function testShippedHostCancellationNamesTheReason(string $tag): void
    {
        $strings = parse_ini_file(dirname(__DIR__, 2) . '/administrator/language/' . $tag . '/com_appointments.ini', false, INI_SCANNER_RAW);
        $host = str_replace('\n', "\n", $strings['COM_APPOINTMENTS_MAIL_HOST_CANCELLATION_BODY']);

        self::assertSame(1, substr_count($host, '{cancel_reason}'));
        self::assertStringNotContainsString('{cancel_reason}', $strings['COM_APPOINTMENTS_MAIL_CANCELLATION_BODY']);
        self::assertStringNotContainsString('{cancel_reason}', $strings['COM_APPOINTMENTS_MAIL_GUEST_CANCELLATION_BODY']);
        self::assertStringNotContainsString('{cancel_reason}', MailComposer::withoutEmptyDetails($host, ['{cancel_reason}' => '']),
            'Without a reason the line is left out');
        self::assertStringContainsString('{cancel_reason}', MailComposer::withoutEmptyDetails($host, ['{cancel_reason}' => 'Ill']));
    }
}
