<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\PrivacyAddress;
use PHPUnit\Framework\TestCase;

final class PrivacyAddressTest extends TestCase
{
    public function testAddressComparisonIsTrimmedUnicodeCaseInsensitiveButAccentExact(): void
    {
        self::assertTrue(PrivacyAddress::matches(' Invitee@Example.test ', 'invitee@example.test'));
        self::assertTrue(PrivacyAddress::matches('MÜLLER@example.test', 'müller@example.test'));
        self::assertFalse(PrivacyAddress::matches('muller@example.test', 'müller@example.test'));
    }

    public function testGuestAddressesMatchExactlyPerLineNeverAsSubstring(): void
    {
        $stored = "Second@Example.test\r\nguest@example.test\n\nmüller@example.test\n";
        self::assertSame(['Second@Example.test', 'guest@example.test', 'müller@example.test'], PrivacyAddress::guestList($stored));
        self::assertSame([], PrivacyAddress::guestList(null));
        self::assertTrue(PrivacyAddress::isGuest($stored, ' GUEST@example.test '));
        self::assertTrue(PrivacyAddress::isGuest($stored, 'MÜLLER@example.test'));
        self::assertFalse(PrivacyAddress::isGuest($stored, 'muller@example.test'));
        self::assertFalse(PrivacyAddress::isGuest($stored, 'est@example.test'));
        self::assertFalse(PrivacyAddress::isGuest($stored, 'guest@example.te'));
        self::assertFalse(PrivacyAddress::isGuest("other-guest@example.test", 'guest@example.test'));
        self::assertSame("Second@Example.test\nmüller@example.test", PrivacyAddress::withoutGuest($stored, 'guest@EXAMPLE.test'));
        self::assertSame("Second@Example.test\nguest@example.test\nmüller@example.test", PrivacyAddress::withoutGuest($stored, 'est@example.test'));
        self::assertSame('', PrivacyAddress::withoutGuest('guest@example.test', 'guest@example.test'));
    }

    public function testAnswersRemainReadableAndTyped(): void
    {
        self::assertSame([], PrivacyAddress::answers(null));
        self::assertSame([12 => ['A', 'B'], 13 => 'Note'], PrivacyAddress::answers('{"12":["A","B"],"13":"Note"}'));
    }

    public function testInvalidAddressIsNotAnEmptySuccessfulExport(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PrivacyAddress::normalise(' ');
    }

    public function testScalarAnswersFailExplicitly(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        PrivacyAddress::answers('"Note"');
    }
}
