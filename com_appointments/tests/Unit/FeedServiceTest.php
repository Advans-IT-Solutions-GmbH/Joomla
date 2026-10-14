<?php

use Advans\Component\Appointments\Administrator\Service\FeedService;
use Joomla\Database\DatabaseInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Conference/RuntimeStubs.php';
require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Service/FeedService.php';

final class FeedServiceTest extends TestCase
{
    #[DataProvider('invalidCredentials')]
    public function testInvalidCredentialsNeverLoadEventsOrBookings(string $scope, int $id, string $token, ?array $credential): void
    {
        $db = $this->getMockBuilder(DatabaseInterface::class)
            ->onlyMethods(get_class_methods(DatabaseInterface::class))
            ->addMethods(['createQuery', 'loadAssocList'])->getMock();
        $db->method('quoteName')->willReturnArgument(0);
        $db->expects(self::once())->method('createQuery')->willReturn(new class {
            public function select($value): self { return $this; }
            public function from($value): self { return $this; }
            public function where($value): self { return $this; }
            public function join(...$values): self { return $this; }
        });
        $db->expects(self::once())->method('setQuery')->willReturnSelf();
        $db->expects(self::once())->method('loadAssoc')->willReturn($credential);
        $db->expects(self::never())->method('loadAssocList');
        self::assertNull((new FeedService($db))->download($scope, $id, $token, new DateTimeImmutable('2026-10-01T12:00:00Z')));
    }

    public static function invalidCredentials(): array
    {
        $stored = ['feed_token' => str_repeat('a', 64), 'title' => 'Fixture'];

        return [
            'wrong' => ['event', 1, str_repeat('b', 64), $stored],
            'malformed' => ['event', 1, 'bad', $stored],
            'unknown' => ['event', 1, str_repeat('a', 64), null],
            'invalid scope' => ['invalid', 1, str_repeat('a', 64), $stored],
            'missing ID' => ['event', 0, str_repeat('a', 64), $stored],
            'wrong host' => ['host', 1, str_repeat('b', 64), $stored],
        ];
    }
}
