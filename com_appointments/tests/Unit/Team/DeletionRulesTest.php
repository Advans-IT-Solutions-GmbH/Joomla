<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once __DIR__ . '/DavTestKit.php';

use Advans\Component\Appointments\Administrator\Service\Team\CalendarObjectStore;
use PHPUnit\Framework\TestCase;

/**
 * The retention and deletion rules: the calendar retention never deletes an
 * entry of the calendar's own, and the administrator help states every rule in
 * all three languages, in the house style.
 */
final class DeletionRulesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    public function testCalendarRetentionNeverDeletesAnOwnEntry(): void
    {
        $before = new \DateTimeImmutable('2026-11-01 00:00:00', new \DateTimeZone('UTC'));
        $old = new \DateTimeImmutable('2020-01-01 10:00:00', new \DateTimeZone('UTC'));

        // An entry of its own (no booking id) never expires, however old.
        self::assertFalse(CalendarObjectStore::expires(null, $old, $before));
        self::assertFalse(CalendarObjectStore::expires(null, null, $before));
        // A booking copy expires only once its last end lies before the cutoff.
        self::assertTrue(CalendarObjectStore::expires(42, $old, $before));
        self::assertFalse(CalendarObjectStore::expires(42, $before, $before));
        self::assertFalse(CalendarObjectStore::expires(42, $before->modify('+1 day'), $before));
        // An end that cannot be determined (endless series) keeps the copy.
        self::assertFalse(CalendarObjectStore::expires(42, null, $before));
    }

    public function testTheRetentionSkipsOwnEntriesBeforeReadingThem(): void
    {
        $source = (string) file_get_contents(self::ROOT . '/administrator/components/com_appointments/src/Service/Team/CalendarObjectStore.php');
        $method = substr($source, (int) strpos($source, 'public function deleteEndedBefore('));
        $method = substr($method, 0, (int) strpos($method, 'public function syncToken('));

        // Skipped before the timing is opened, and checked again under the row lock.
        self::assertLessThan(strpos($method, 'openTiming'), strpos($method, '$object->bookingId === null'));
        self::assertStringContainsString("\$o['booking_id'] !== null", $method);
        self::assertStringContainsString('self::expires(', $method);
    }

    /**
     * @return  array<string, array<string, string>>
     */
    private static function language(string $tag): array
    {
        $values = parse_ini_file(self::ROOT . '/administrator/language/' . $tag . '/com_appointments.ini', false, INI_SCANNER_RAW);
        self::assertIsArray($values);

        return $values;
    }

    public function testTheHelpStatesEveryRuleInEveryLanguage(): void
    {
        $template = (string) file_get_contents(self::ROOT . '/administrator/components/com_appointments/tmpl/help/default.php');
        preg_match_all('/COM_APPOINTMENTS_HELP_DEL_[A-Z0-9_]+/', $template, $found);
        $keys = array_values(array_unique($found[0]));

        foreach (['RULE_ORIGIN_DESC', 'RULE_BOOKINGS_DESC', 'RULE_OWNER_DESC', 'RULE_DEVICE_DELETE_DESC', 'RULE_OWN_DESC', 'RULE_PRIVACY_DESC',
            'RULE_CALRET_DESC', 'RULE_CLEANUP_DESC', 'RULE_RESTORE_DESC', 'RULE_BACKUP_DESC', 'TABLE_TITLE',
            'OUTLOOK_TITLE', 'OUTLOOK_WHY', 'NEVER_TITLE', 'BEYOND_TITLE'] as $rule) {
            self::assertContains('COM_APPOINTMENTS_HELP_DEL_' . $rule, $keys, $rule);
        }

        foreach (['de-DE', 'en-GB', 'fr-FR'] as $tag) {
            $values = self::language($tag);

            foreach ($keys as $key) {
                self::assertNotSame('', trim((string) ($values[$key] ?? '')), $tag . ' ' . $key);
            }
        }

        $english = self::language('en-GB');
        self::assertStringContainsString('never deletes entries of your own', $english['COM_APPOINTMENTS_HELP_DEL_RULE_OWN_DESC']);
        self::assertStringContainsString('Block only', $english['COM_APPOINTMENTS_HELP_DEL_OUTLOOK_STOP']);
        self::assertStringContainsString('does not cancel', $english['COM_APPOINTMENTS_HELP_DEL_RULE_DEVICE_DELETE_TITLE']);
    }

    public function testTheGermanRuleTextsKeepTheHouseStyle(): void
    {
        $german = self::language('de-DE');
        $keys = array_filter(array_keys($german), static fn (string $key): bool => str_starts_with($key, 'COM_APPOINTMENTS_HELP_DEL_')
            || str_starts_with($key, 'COM_APPOINTMENTS_TEAM_C_RETENTION_') || $key === 'COM_APPOINTMENTS_TEAM_C_FIELD_RETENTION_DESC');
        self::assertGreaterThan(60, \count($keys));

        foreach ($keys as $key) {
            // Swiss High German, no semicolons, no long dashes.
            self::assertDoesNotMatchRegularExpression('/[;ß\x{2013}\x{2014}]/u', $german[$key], $key);
        }
    }
}
