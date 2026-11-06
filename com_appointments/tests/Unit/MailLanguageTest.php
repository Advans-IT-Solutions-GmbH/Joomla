<?php

use Advans\Component\Appointments\Administrator\Helper\MailLanguage;
use Joomla\Database\DatabaseInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Conference/RuntimeStubs.php';

final class MailLanguageTestQuery
{
    public array $bindings = [];
    public string $condition = '';

    public function select($columns): self { return $this; }
    public function from($table): self { return $this; }
    public function where(string $condition): self { $this->condition = $condition; return $this; }
    public function bind(string $key, mixed &$value, string $type): self
    {
        $this->bindings[$key] = &$value;

        return $this;
    }
}

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

    #[DataProvider('batchSizes')]
    public function testTheWholeRecipientBatchExecutesOnlyOneQuery(int $size, string $server): void
    {
        $addresses = [];
        $rows = [];
        $expected = [];

        for ($i = 0; $i < $size; $i++) {
            $address = "recipient-$i@example.test";
            $language = MailLanguage::SUPPORTED[$i % 3];
            $addresses[] = $address;
            $rows[] = (object) ['email' => strtoupper($address), 'params' => json_encode(['language' => $language])];
            $expected[$address] = $language;
        }

        $query = new MailLanguageTestQuery();
        $db = $this->database($query, $server);
        $db->expects(self::once())->method('setQuery')->with($query)->willReturnSelf();
        $db->expects(self::once())->method('loadObjectList')->willReturn($rows);

        self::assertSame($expected, MailLanguage::forAddresses($db, $addresses, 'fr-FR'));
        self::assertSame($addresses, array_values($query->bindings));
        self::assertStringContainsString(' IN (', $query->condition);
        self::assertStringContainsString($server === 'postgresql' ? " ~ '[[:cntrl:]]'" : " REGEXP '[[:cntrl:]]'", $query->condition);
    }

    public static function batchSizes(): array
    {
        $cases = [];

        foreach (['mysql', 'postgresql'] as $server) {
            foreach ([1, 11, 51] as $size) {
                $cases["$server $size recipients"] = [$size, $server];
            }
        }

        return $cases;
    }

    public function testBatchMatchingPreservesUnicodeIsolationAmbiguityAndGuestFallback(): void
    {
        $query = new MailLanguageTestQuery();
        $db = $this->database($query);
        $db->expects(self::once())->method('setQuery')->willReturnSelf();
        $db->expects(self::once())->method('loadObjectList')->willReturn([
            (object) ['email' => "\t STRA\u{1E9E}E@example.test \r\n", 'params' => '{"language":"fr-FR"}'],
            (object) ['email' => 'strasse@example.test', 'params' => '{"language":"en-GB"}'],
            (object) ['email' => "\u{1C90}@example.test", 'params' => '{"language":"en-GB"}'],
            (object) ['email' => 'ambiguous@example.test', 'params' => '{"language":"fr-FR"}'],
            (object) ['email' => 'AMBIGUOUS@example.test', 'params' => '{"language":"en-GB"}'],
            (object) ['email' => "\tambiguous@example.test", 'params' => '{"language":"fr-FR"}'],
            (object) ['email' => 'unsupported@example.test', 'params' => '{"language":"it-IT"}'],
            (object) ['email' => "\u{00DF}-unrelated@example.test", 'params' => '{"language":"fr-FR"}'],
            (object) ['email' => ' ', 'params' => '{"language":"fr-FR"}'],
        ]);
        $addresses = ["stra\u{00DF}e@example.test", 'strasse@example.test', "\u{10D0}@example.test",
            'ambiguous@example.test', 'unsupported@example.test', 'guest@example.test', " STRA\u{1E9E}E@example.test "];

        self::assertSame([
            $addresses[0] => 'fr-FR', $addresses[1] => 'en-GB', $addresses[2] => 'en-GB',
            $addresses[3] => 'de-DE', $addresses[4] => 'de-DE', $addresses[5] => 'de-DE', $addresses[6] => 'fr-FR',
        ], MailLanguage::forAddresses($db, $addresses, 'de-DE'));
        self::assertCount(6, $query->bindings);
    }

    public function testAnEmptyBatchDoesNotQueryTheDirectory(): void
    {
        $db = $this->getMockBuilder(DatabaseInterface::class)
            ->onlyMethods(get_class_methods(DatabaseInterface::class))
            ->addMethods(['createQuery', 'loadObjectList', 'getServerType'])->getMock();
        $db->expects(self::never())->method('createQuery');
        $db->expects(self::never())->method('setQuery');
        $db->expects(self::never())->method('loadObjectList');

        self::assertSame([], MailLanguage::forAddresses($db, [], 'de-DE'));
    }

    public function testASecondSendReloadsAccountPreferencesInsteadOfReusingCachedValues(): void
    {
        $query = new MailLanguageTestQuery();
        $db = $this->database($query, 'mysql', 2);
        $db->expects(self::exactly(2))->method('setQuery')->willReturnSelf();
        $db->expects(self::exactly(2))->method('loadObjectList')->willReturnOnConsecutiveCalls(
            [(object) ['email' => 'account@example.test', 'params' => '{"language":"fr-FR"}']],
            [(object) ['email' => 'account@example.test', 'params' => '{"language":"en-GB"}']]
        );

        self::assertSame('fr-FR', MailLanguage::forAddress($db, 'account@example.test', 'de-DE'));
        self::assertSame('en-GB', MailLanguage::forAddress($db, 'account@example.test', 'de-DE'));
    }

    private function database(MailLanguageTestQuery $query, string $server = 'mysql', int $queries = 1): DatabaseInterface
    {
        $db = $this->getMockBuilder(DatabaseInterface::class)
            ->onlyMethods(get_class_methods(DatabaseInterface::class))
            ->addMethods(['createQuery', 'loadObjectList', 'getServerType'])->getMock();
        $db->method('quoteName')->willReturnArgument(0);
        $db->method('getServerType')->willReturn($server);
        $db->expects(self::exactly($queries))->method('createQuery')->willReturn($query);

        return $db;
    }
}
