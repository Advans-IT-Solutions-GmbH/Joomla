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
