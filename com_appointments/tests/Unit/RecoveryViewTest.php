<?php

namespace Joomla\CMS\Language {
    final class Text
    {
        public static function _(string $key): string
        {
            return $key;
        }
    }
}

namespace Joomla\CMS\HTML {
    final class HTMLHelper
    {
        public static function _(string $key): string
        {
            return '';
        }
    }
}

namespace Advans\Installer\V3 {
    final class Knowledge
    {
        public static function relative(string $path): string
        {
            return $path;
        }
    }
}

namespace Advans\Installer\AppointmentsConflict\V2 {
    final class Guard
    {
        /** @var array<string, string|null> */
        public static array $liveHashes = [];

        public static function liveHash(array $row): ?string
        {
            return self::$liveHashes[$row['destination']] ?? null;
        }
    }
}

namespace Advans\Component\Appointments\Tests\Unit {
    use Advans\Installer\AppointmentsConflict\V2\Guard;
    use PHPUnit\Framework\TestCase;

    final class RecoveryViewTest extends TestCase
    {
        public function testReviewUsesDistinctConfirmationForReplaceAndRemoveOperations(): void
        {
            defined('_JEXEC') || define('_JEXEC', 1);

            $review = [
                'id'      => str_repeat('a', 64),
                'version' => '1.0.0',
                'rows'    => [
                    'remove-id' => [
                        'destination' => '/site/plugins/example/obsolete.php',
                        'existing'    => str_repeat('b', 64),
                        'incoming'    => str_repeat('c', 64),
                        'remove'      => true,
                        'downloaded'  => true,
                    ],
                    'replace-id' => [
                        'destination' => '/site/plugins/example/current.php',
                        'existing'    => str_repeat('d', 64),
                        'incoming'    => str_repeat('e', 64),
                        'remove'      => false,
                        'downloaded'  => true,
                    ],
                ],
            ];
            $attempt = [];
            $page = 'index.php?option=com_appointmentsrecovery';
            $template = dirname(__DIR__, 2) . '/recovery/tmpl/review/default.php';

            ob_start();
            require $template;
            $html = (string) ob_get_clean();
            $sections = explode('<section', $html);

            self::assertCount(3, $sections);
            self::assertStringContainsString('COM_APPOINTMENTSRECOVERY_CONFIRM_REMOVE', $sections[1]);
            self::assertStringNotContainsString('COM_APPOINTMENTSRECOVERY_CONFIRM_REPLACE', $sections[1]);
            self::assertStringContainsString('COM_APPOINTMENTSRECOVERY_CONFIRM_REPLACE', $sections[2]);
            self::assertStringNotContainsString('COM_APPOINTMENTSRECOVERY_CONFIRM_REMOVE', $sections[2]);
            self::assertSame(2, substr_count($html, 'value="apply"'));
            self::assertSame(2, substr_count($html, 'COM_APPOINTMENTSRECOVERY_APPROVE_OPERATION'));
        }

        public function testEnglishConfirmationNamesTheActualPackageOperation(): void
        {
            $strings = parse_ini_file(dirname(__DIR__, 2) . '/recovery/language/en-GB/com_appointmentsrecovery.ini', false, INI_SCANNER_RAW);

            self::assertStringContainsString('replace it', $strings['COM_APPOINTMENTSRECOVERY_CONFIRM_REPLACE']);
            self::assertStringContainsString('remove it', $strings['COM_APPOINTMENTSRECOVERY_CONFIRM_REMOVE']);
            self::assertNotSame(
                $strings['COM_APPOINTMENTSRECOVERY_CONFIRM_REPLACE'],
                $strings['COM_APPOINTMENTSRECOVERY_CONFIRM_REMOVE']
            );
        }

        public function testHashVerifiedRowsRemainRecoverableAfterAPartialInstall(): void
        {
            defined('_JEXEC') || define('_JEXEC', 1);
            $incoming = str_repeat('a', 64);
            $attempt = [
                'id'        => str_repeat('b', 64),
                'version'   => '1.0.0',
                'installed' => false,
                'rows'      => [
                    'incoming-row' => [
                        'destination' => '/site/administrator/components/com_appointments/incoming.php',
                        'existing'    => str_repeat('c', 64),
                        'incoming'    => $incoming,
                        'keep'        => true,
                    ],
                    'absent-row' => [
                        'destination' => '/site/administrator/components/com_appointments/absent.php',
                        'existing'    => str_repeat('d', 64),
                        'incoming'    => str_repeat('e', 64),
                        'keep'        => true,
                    ],
                ],
            ];
            Guard::$liveHashes = [
                $attempt['rows']['incoming-row']['destination'] => $incoming,
                $attempt['rows']['absent-row']['destination'] => null,
            ];
            $review = [];
            $page = 'index.php?option=com_appointmentsrecovery';
            $template = dirname(__DIR__, 2) . '/recovery/tmpl/review/default.php';

            ob_start();
            require $template;
            $html = (string) ob_get_clean();

            self::assertSame(2, substr_count($html, 'type="file"'));
            self::assertStringNotContainsString('COM_APPOINTMENTSRECOVERY_UPDATE_NOT_VERIFIED', $html);
        }
    }
}
