<?php

use Advans\Component\Appointments\Administrator\Rule\RangeRule;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Conference/RuntimeStubs.php';
require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Rule/RangeRule.php';

final class ConfigurationTest extends TestCase
{
    public function testInstalledConfigurationHasUnnestedFieldsAndSafeDefaults(): void
    {
        $root = dirname(__DIR__, 2);
        $manifest = simplexml_load_file($root . '/com_appointments.xml');
        self::assertContains('config.xml', array_map('strval', iterator_to_array($manifest->administration->files->filename, false)));
        self::assertFalse(isset($manifest->config));
        $config = simplexml_load_file($root . '/administrator/components/com_appointments/config.xml');
        self::assertFalse(isset($config->fields));

        foreach (['erase_mode' => 'anonymise', 'retention_days' => '365', 'audit_retention_years' => '3',
            'conference_provider' => 'none', 'conference_allow_outbound' => '0', 'reminder_offsets' => '1440,60'] as $name => $default) {
            self::assertSame($default, (string) $config->xpath('//field[@name="' . $name . '"]')[0]['default']);
        }
    }

    public function testEveryNumericOptionRejectsMalformedAndOutOfRangeRawInput(): void
    {
        $config = simplexml_load_file(dirname(__DIR__, 2) . '/administrator/components/com_appointments/config.xml');
        $rule = new RangeRule();
        $fields = $config->xpath('//field[@validate="range"]');
        self::assertCount(5, $fields);

        foreach ($fields as $field) {
            self::assertSame('raw', (string) $field['filter']);
            self::assertTrue($rule->test($field, (string) $field['default']));
            self::assertTrue($rule->test($field, (string) $field['min']));
            self::assertTrue($rule->test($field, (int) $field['max']));

            foreach (['invalid', '', '1e2', '3.5', null, [], true, (int) $field['min'] - 1,
                (int) $field['max'] + 1, str_repeat('9', 100)] as $invalid) {
                self::assertFalse($rule->test($field, $invalid), (string) $field['name']);
            }
        }
    }
}
