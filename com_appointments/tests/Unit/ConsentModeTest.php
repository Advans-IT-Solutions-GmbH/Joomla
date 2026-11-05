<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\ConsentMode;
use PHPUnit\Framework\TestCase;

final class ConsentModeTest extends TestCase
{
    private static function params(array $values): object
    {
        return new class ($values) {
            public function __construct(private array $values)
            {
            }

            public function get(string $name, mixed $default = null): mixed
            {
                return $this->values[$name] ?? $default;
            }
        };
    }

    public function testUnknownAndMissingModesAreTheNotice(): void
    {
        self::assertSame(ConsentMode::NOTICE, ConsentMode::fromParams(self::params([])));
        self::assertSame(ConsentMode::NOTICE, ConsentMode::fromParams(self::params(['consent_mode' => 'opt-in'])));
        self::assertSame(ConsentMode::NOTICE, ConsentMode::fromParams(self::params(['consent_mode' => ['consent']])));
        self::assertSame(ConsentMode::ACKNOWLEDGE, ConsentMode::fromParams(self::params(['consent_mode' => 'acknowledge'])));
        self::assertSame(ConsentMode::CONSENT, ConsentMode::fromParams(self::params(['consent_mode' => 'consent'])));
        self::assertSame(ConsentMode::CONSENT, ConsentMode::fromParams((object) ['consent_mode' => 'consent']));
        self::assertSame(ConsentMode::NOTICE, ConsentMode::fromParams(new \stdClass()));
    }

    public function testOnlyAcknowledgeAndConsentHaveACheckbox(): void
    {
        self::assertFalse(ConsentMode::requiresCheckbox(ConsentMode::NOTICE));
        self::assertTrue(ConsentMode::requiresCheckbox(ConsentMode::ACKNOWLEDGE));
        self::assertTrue(ConsentMode::requiresCheckbox(ConsentMode::CONSENT));
        self::assertFalse(ConsentMode::requiresCheckbox('unknown'));
    }

    public function testLabelTextUsesTheModeKeyWithOrWithoutLink(): void
    {
        $texts = [
            'COM_APPOINTMENTS_BOOKING_ACKNOWLEDGE_LINK' => 'I have taken note of the %s.',
            'COM_APPOINTMENTS_BOOKING_ACKNOWLEDGE' => 'I have taken note.',
            'COM_APPOINTMENTS_BOOKING_CONSENT_LINK' => 'I have read the %s and agree.',
            'COM_APPOINTMENTS_BOOKING_CONSENT' => 'I agree.',
        ];
        $translate = static fn (string $key): string => $texts[$key];

        self::assertSame('I have taken note of the privacy notice.', ConsentMode::labelText(ConsentMode::ACKNOWLEDGE, true, $translate, 'privacy notice'));
        self::assertSame('I have taken note.', ConsentMode::labelText(ConsentMode::ACKNOWLEDGE, false, $translate, 'privacy notice'));
        self::assertSame('I have read the privacy notice and agree.', ConsentMode::labelText(ConsentMode::CONSENT, true, $translate, 'privacy notice'));
        self::assertSame('I agree.', ConsentMode::labelText(ConsentMode::CONSENT, false, $translate, 'privacy notice'));
        self::assertSame('', ConsentMode::labelText(ConsentMode::NOTICE, true, $translate, 'privacy notice'));
    }

    public function testVersionIsA64HexHashOfModeLanguageAndExactLabel(): void
    {
        $version = ConsentMode::version(ConsentMode::CONSENT, 'de-DE', 'Ich willige ein.');

        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $version);
        self::assertSame(hash('sha256', "consent\nde-DE\nIch willige ein."), $version);
        self::assertSame($version, ConsentMode::version(ConsentMode::CONSENT, 'de-DE', 'Ich willige ein.'));
        self::assertNotSame($version, ConsentMode::version(ConsentMode::CONSENT, 'en-GB', 'Ich willige ein.'));
        self::assertNotSame($version, ConsentMode::version(ConsentMode::ACKNOWLEDGE, 'de-DE', 'Ich willige ein.'));
        self::assertNotSame($version, ConsentMode::version(ConsentMode::CONSENT, 'de-DE', 'Ich willige ein'));
    }
}
