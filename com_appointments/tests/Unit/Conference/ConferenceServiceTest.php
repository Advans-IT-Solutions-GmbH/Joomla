<?php

namespace Advans\Component\Appointments\Tests\Unit\Conference;

use Advans\Component\Appointments\Administrator\Helper\Conference\ConferenceManager;
use Advans\Component\Appointments\Administrator\Helper\Conference\ConferenceProviderInterface;
use Advans\Component\Appointments\Administrator\Helper\Conference\MeetingRequest;
use Advans\Component\Appointments\Administrator\Helper\Conference\MeetingResult;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Service\ConferenceService;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RuntimeStubs.php';
require_once dirname(__DIR__, 3) . '/administrator/components/com_appointments/src/Service/ConferenceService.php';

final class ConferenceServiceTest extends TestCase
{
    private ?array $row;
    private ?array $event;
    private string $query = '';
    private array $operations = [];
    private array $writes = [];
    private array $executed = [];
    private ?array $snapshot;
    private bool $failWrite = false;
    private bool $failRead = false;

    protected function setUp(): void
    {
        $this->row = [
            'id' => 7, 'eventtype_id' => 3, 'host_user_id' => 8, 'uid' => str_repeat('a', 64),
            'start_utc' => '2026-11-10 10:00:00', 'end_utc' => '2026-11-10 10:30:00',
            'state' => 1, 'anonymised_utc' => null, 'sequence' => 2, 'modified_utc' => '2026-11-01 10:00:00',
            'invitee_name' => 'Fixture Invitee', 'invitee_email' => 'invitee@example.test',
            'guest_emails' => 'guest@example.test', 'custom_fields' => '{"answer":"private answer"}',
            'conference_provider' => null, 'conference_url' => null, 'conference_room' => null,
            'conference_state' => null, 'conference_error' => null,
        ];
        $this->event = [
            'id' => 3, 'title' => 'Consultation', 'alias' => 'consultation',
            'location_type' => 'conference', 'conference_params' => null,
        ];
        $this->operations = $this->writes = $this->executed = [];
        $this->failWrite = $this->failRead = false;
    }

    private function database(): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('quoteName')->willReturnCallback(static fn ($name) => '`' . $name . '`');
        $db->method('quote')->willReturnCallback(static fn ($text) => "'" . addslashes((string) $text) . "'");
        $db->method('setQuery')->willReturnCallback(function ($query) use ($db) {
            $this->query = (string) $query;
            $this->operations[] = $this->query;

            return $db;
        });
        $db->method('execute')->willReturnCallback(function () {
            $this->executed[] = $this->query;

            return true;
        });
        $db->method('loadResult')->willReturn(8);
        $db->method('loadAssoc')->willReturnCallback(function () {
            if ($this->failRead) {
                throw new \RuntimeException('SQL password=credential https://example.com/private');
            }

            return str_contains($this->query, 'appointments_eventtypes') ? $this->event : $this->row;
        });
        $db->method('updateObject')->willReturnCallback(function ($table, $object, $key, $nulls) {
            if ($this->failWrite) {
                throw new \RuntimeException('SQL password=credential https://example.com/private');
            }

            $this->writes[] = (array) $object;
            $this->row = array_replace($this->row, (array) $object);

            return true;
        });
        $db->method('transactionStart')->willReturnCallback(function () {
            $this->operations[] = 'start';
            $this->snapshot = $this->row;
        });
        $db->method('transactionCommit')->willReturnCallback(function () {
            $this->operations[] = 'commit';
        });
        $db->method('transactionRollback')->willReturnCallback(function () {
            $this->operations[] = 'rollback';
            $this->row = $this->snapshot;
        });

        return $db;
    }

    private function service(array $params = [], ?ConferenceManager $manager = null): ConferenceService
    {
        return new ConferenceService($this->database(), new Registry($params), $manager, new KeyProvider('unit-site-secret'));
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-01T10:00:00Z');
    }

    public function testProvisionLocksHostBeforeBookingAndStoresGuestDataOnly(): void
    {
        $provider = new RecordingConferenceProvider();
        $service = $this->service(['conference_provider' => 'jitsi'], new ConferenceManager([$provider]));
        $saved = $this->row;
        $result = $service->provision($saved, $this->now());
        self::assertSame('start', $this->operations[0]);
        self::assertStringContainsString('appointments_hosts', $this->operations[1]);
        self::assertStringEndsWith('FOR UPDATE', $this->operations[1]);
        self::assertStringContainsString('appointments_bookings', $this->operations[2]);
        self::assertStringEndsWith('FOR UPDATE', $this->operations[2]);
        self::assertSame('ok', $result['conference_state']);
        self::assertTrue($result['_conference_changed']);
        self::assertSame('https://meet.example.com/guest', $result['conference_url']);
        self::assertSame(2, $result['sequence']);
        self::assertSame($saved['modified_utc'], $result['modified_utc']);
        self::assertSame(1, $result['state']);
        self::assertArrayNotHasKey('host_url', $result);
        self::assertStringNotContainsString('moderator', json_encode($this->writes));
        self::assertSame('Consultation', $provider->request->title);
        self::assertSame(30, $provider->request->duration);
        self::assertSame(hash('sha256', 'com_appointments:conference-seed:' . $saved['uid']), $provider->request->roomSeed);
        self::assertSame(['roomSeed', 'startUtc', 'endUtc', 'title', 'duration'], array_keys(get_object_vars($provider->request)));
        self::assertStringNotContainsString('Fixture Invitee', json_encode(get_object_vars($provider->request)));
        self::assertStringNotContainsString('private answer', json_encode(get_object_vars($provider->request)));
    }

    public function testProviderFailureKeepsCommittedBookingAndPersistsOnlyRedactedDiagnostic(): void
    {
        $provider = new RecordingConferenceProvider();
        $provider->failCreate = true;
        $saved = $this->row;
        $result = $this->service(['conference_provider' => 'jitsi'], new ConferenceManager([$provider]))
            ->provision($saved, $this->now());
        self::assertSame(1, $result['state']);
        self::assertSame($saved['uid'], $result['uid']);
        self::assertSame($saved['invitee_email'], $result['invitee_email']);
        self::assertSame('failed', $this->row['conference_state']);
        self::assertSame('Conference link creation failed. Please retry.', $this->row['conference_error']);
        self::assertNull($this->row['conference_url']);
        self::assertFalse($result['_conference_changed']);
        self::assertSame('commit', end($this->operations));
    }

    public function testConfigurationFailureIsPersistedWithoutLosingBooking(): void
    {
        $result = $this->service(['conference_provider' => 'jitsi', 'conference_jitsi_base_url' => 'javascript:secret'])
            ->provision($this->row, $this->now());
        self::assertSame(1, $result['state']);
        self::assertSame('failed', $result['conference_state']);
        self::assertSame('Conference configuration is invalid.', $result['conference_error']);
        self::assertStringNotContainsString('secret', $result['conference_error']);
    }

    public function testOnlyTheSelectedProviderIsInstantiated(): void
    {
        $result = $this->service([
            'conference_provider' => 'fixed', 'conference_fixed_url' => 'https://meet.example.com/permanent',
            'conference_jitsi_base_url' => 'javascript:secret', 'conference_jitsi_auth' => 'invalid',
            'conference_generic_template' => 'javascript:secret',
        ])->provision($this->row, $this->now());
        self::assertSame('ok', $result['conference_state']);
        self::assertSame('https://meet.example.com/permanent', $result['conference_url']);
    }

    public function testEmptySharedRegistryFallsBackToNativePerEventConstruction(): void
    {
        $service = $this->service([
            'conference_provider' => 'fixed', 'conference_fixed_url' => 'https://meet.example.com/global',
        ], new ConferenceManager());
        $result = $service->provision($this->row, $this->now());
        self::assertSame('ok', $result['conference_state']);
        self::assertSame('https://meet.example.com/global', $result['conference_url']);
        $this->event['conference_params'] = json_encode(['conference_fixed_url' => 'https://meet.example.com/event-a']);
        $result = $service->provision($this->row, $this->now());
        self::assertSame('https://meet.example.com/event-a', $result['conference_url']);
        $this->event['conference_params'] = json_encode(['conference_fixed_url' => 'https://meet.example.com/event-b']);
        $result = $service->provision($this->row, $this->now());
        self::assertSame('https://meet.example.com/event-b', $result['conference_url']);
    }

    public function testSharedRegistrySupportsNativeFallbackThenPluginRegistrationWithoutRebuildingService(): void
    {
        $registry = new ConferenceManager();
        $service = $this->service([
            'conference_provider' => 'fixed', 'conference_fixed_url' => 'https://meet.example.com/native',
        ], $registry);
        self::assertSame([], $registry->getProviderNames());
        $native = $service->provision($this->row, $this->now());
        self::assertSame('ok', $native['conference_state']);
        self::assertSame('fixed', $native['conference_provider']);
        self::assertSame('https://meet.example.com/native', $native['conference_url']);
        $plugin = new RecordingConferenceProvider();
        $plugin->name = 'plugin-local';
        $registry->register($plugin);
        self::assertSame(['plugin-local'], $registry->getProviderNames());
        $this->event['conference_params'] = json_encode(['conference_provider' => 'plugin-local']);
        $custom = $service->provision($this->row, $this->now());
        self::assertSame('ok', $custom['conference_state']);
        self::assertSame('plugin-local', $custom['conference_provider']);
        self::assertSame('https://meet.example.com/guest', $custom['conference_url']);
        self::assertSame(1, $plugin->createCalls);
        $this->event['conference_params'] = json_encode([
            'conference_provider' => 'inherit', 'conference_fixed_url' => 'https://meet.example.com/event-native',
        ]);
        $nativeAgain = $service->provision($this->row, $this->now());
        self::assertSame('fixed', $nativeAgain['conference_provider']);
        self::assertSame('https://meet.example.com/event-native', $nativeAgain['conference_url']);
        self::assertSame(1, $plugin->createCalls);
    }

    public function testUnrelatedPluginRegistrationDoesNotPreventNativeFallbackOrPrefixOverrides(): void
    {
        $plugin = new RecordingConferenceProvider();
        $plugin->name = 'custom';
        $service = $this->service([
            'conference_provider' => 'jitsi', 'conference_jitsi_base_url' => 'https://meet.example.com',
        ], new ConferenceManager([$plugin]));
        $this->event['conference_params'] = json_encode(['conference_room_prefix' => 'team-a']);
        $result = $service->provision($this->row, $this->now());
        self::assertSame('ok', $result['conference_state']);
        self::assertStringStartsWith('team-a-consultation-', $result['conference_room']);
        $this->event['conference_params'] = json_encode(['conference_room_prefix' => 'team-b']);
        $result = $service->provision($this->row, $this->now());
        self::assertStringStartsWith('team-b-consultation-', $result['conference_room']);
        self::assertSame(0, $plugin->createCalls);
    }

    public function testRegisteredCustomProviderCanBeSelectedGloballyAndPerEventAndTornDown(): void
    {
        $plugin = new RecordingConferenceProvider();
        $plugin->name = 'custom';
        $registry = new ConferenceManager([$plugin]);
        $result = $this->service(['conference_provider' => 'custom'], $registry)
            ->provision($this->row, $this->now());
        self::assertSame('ok', $result['conference_state']);
        self::assertSame('custom', $result['conference_provider']);
        self::assertSame(1, $plugin->createCalls);
        $this->event['conference_params'] = json_encode(['conference_provider' => 'custom']);
        $service = $this->service(['conference_provider' => 'none'], $registry);
        $result = $service->provision($this->row, $this->now());
        self::assertSame('custom', $result['conference_provider']);
        self::assertSame(2, $plugin->createCalls);
        $this->row['state'] = 2;
        $result = $service->destroy($result);
        self::assertSame(['room'], $plugin->destroyedRooms);
        self::assertNull($result['conference_room']);
    }

    public function testUnregisteredCustomProviderFailsSafelyInsteadOfSilentlyUsingANativeProvider(): void
    {
        $result = $this->service(['conference_provider' => 'custom'], new ConferenceManager())
            ->provision($this->row, $this->now());
        self::assertSame(1, $result['state']);
        self::assertSame('failed', $result['conference_state']);
        self::assertSame('Conference configuration is invalid.', $result['conference_error']);
    }

    public function testRegisteredRemoteProviderRemainsInertWithoutRegistryOutboundOptIn(): void
    {
        $plugin = new RecordingConferenceProvider();
        $plugin->name = 'remote';
        $plugin->outbound = true;
        $result = $this->service([
            'conference_provider' => 'remote', 'conference_allow_outbound' => false,
        ], new ConferenceManager([$plugin], false))->provision($this->row, $this->now());
        self::assertSame(1, $result['state']);
        self::assertSame('failed', $result['conference_state']);
        self::assertSame('Outbound conference access is disabled.', $result['conference_error']);
        self::assertSame(0, $plugin->createCalls);
    }

    public function testNonConferenceEventsIgnoreUnrelatedSettings(): void
    {
        $this->event['location_type'] = 'physical';
        $this->event['conference_params'] = 'invalid json';
        $this->event['conference_provider'] = 'jitsi';
        $result = $this->service(['conference_provider' => 'invalid'])->provision($this->row, $this->now());
        self::assertNull($result['conference_state']);
        self::assertNull($result['conference_provider']);
        self::assertFalse($result['_conference_changed']);
    }

    public function testConferenceEventWithoutAnyProviderIsPersistedAsFailed(): void
    {
        foreach ([null, json_encode(['conference_provider' => 'none']), json_encode(['conference_provider' => 'inherit'])] as $settings) {
            $this->setUp();
            $this->event['conference_params'] = $settings;
            $result = $this->service(['conference_provider' => 'none', 'conference_jitsi_base_url' => 'https://meet.example.com'])
                ->provision($this->row, $this->now());
            self::assertSame(1, $result['state']);
            self::assertSame('failed', $result['conference_state']);
            self::assertSame('No conference provider is selected for this event type.', $result['conference_error']);
            self::assertNull($result['conference_provider']);
            self::assertNull($result['conference_url']);
            self::assertFalse($result['_conference_changed']);
            self::assertCount(1, $this->writes);
            self::assertSame('commit', end($this->operations));
        }
    }

    public function testEventTypeProviderColumnSelectsJitsiWhenTheGlobalProviderIsDisabled(): void
    {
        // The dev-test configuration: only the event type's provider column names Jitsi.
        $this->event['conference_provider'] = 'jitsi';
        $result = $this->service(['conference_provider' => 'none', 'conference_jitsi_base_url' => 'https://meet.example.com'])
            ->provision($this->row, $this->now());
        self::assertSame('ok', $result['conference_state']);
        self::assertSame('jitsi', $result['conference_provider']);
        self::assertNull($result['conference_error']);
        self::assertStringStartsWith('https://meet.example.com/appointment-consultation-', $result['conference_url']);
        self::assertTrue($result['_conference_changed']);
        // Saved settings decide, also when they inherit or are empty: the column is only the
        // fallback for an event type without any saved settings.
        foreach ([json_encode(['conference_provider' => 'inherit', 'conference_fixed_url' => '']),
            json_encode(['conference_provider' => '']), '[]'] as $settings) {
            $this->event['conference_params'] = $settings;
            $result = $this->service(['conference_provider' => 'fixed', 'conference_fixed_url' => 'https://meet.example.com/global'])
                ->provision($this->row, $this->now());
            self::assertSame('fixed', $result['conference_provider']);
            self::assertSame('https://meet.example.com/global', $result['conference_url']);
        }
    }

    public function testExplicitEventSettingWinsOverTheProviderColumn(): void
    {
        $this->event['conference_provider'] = 'jitsi';
        $this->event['conference_params'] = json_encode([
            'conference_provider' => 'fixed', 'conference_fixed_url' => 'https://meet.example.com/event-room',
        ]);
        $result = $this->service(['conference_provider' => 'none'])->provision($this->row, $this->now());
        self::assertSame('fixed', $result['conference_provider']);
        self::assertSame('https://meet.example.com/event-room', $result['conference_url']);
        $this->event['conference_params'] = json_encode(['conference_provider' => 'none']);
        $result = $this->service(['conference_provider' => 'fixed', 'conference_fixed_url' => 'https://meet.example.com/global'])
            ->provision($this->row, $this->now());
        self::assertSame('failed', $result['conference_state']);
        self::assertSame('No conference provider is selected for this event type.', $result['conference_error']);
    }

    public function testFixedAndPrefixEventOverridesAndInheritance(): void
    {
        $this->event['conference_params'] = json_encode([
            'conference_provider' => 'fixed', 'conference_fixed_url' => 'https://meet.example.com/event-room',
        ]);
        $result = $this->service(['conference_provider' => 'none'])->provision($this->row, $this->now());
        self::assertSame('fixed', $result['conference_provider']);
        self::assertSame('https://meet.example.com/event-room', $result['conference_url']);

        $this->event['conference_params'] = json_encode([
            'conference_provider' => 'inherit', 'conference_fixed_url' => '',
            'conference_room_prefix' => 'team',
        ]);
        $result = $this->service([
            'conference_provider' => 'generic', 'conference_generic_template' => 'https://meet.example.com/{room}',
        ])->provision($this->row, $this->now());
        self::assertStringStartsWith('team-consultation-', $result['conference_room']);
        self::assertSame($result['conference_url'], $this->service()->hostUrl($result));
    }

    public function testUnicodeOrLongJoomlaAliasesDoNotBreakRoomCreation(): void
    {
        $this->event['alias'] = str_repeat('é', 80);
        $result = $this->service([
            'conference_provider' => 'generic', 'conference_generic_template' => 'https://meet.example.com/{room}',
        ])->provision($this->row, $this->now());
        self::assertSame('ok', $result['conference_state']);
        self::assertStringStartsWith('meeting-event-', $result['conference_room']);
        $this->event['alias'] = str_repeat('a', 100);
        $result = $this->service([
            'conference_provider' => 'jitsi', 'conference_jitsi_base_url' => 'https://meet.example.com',
        ])->provision($this->row, $this->now());
        self::assertSame('ok', $result['conference_state']);
        self::assertStringStartsWith('appointment-' . str_repeat('a', 64) . '-', $result['conference_room']);
    }

    public function testRetryAdvancesSequenceAndModifiedStrictlyOnlyOnSuccess(): void
    {
        $this->row['conference_state'] = 'failed';
        $provider = new RecordingConferenceProvider();
        $provider->failCreate = true;
        $service = $this->service(['conference_provider' => 'jitsi'], new ConferenceManager([$provider]));
        $result = $service->provision($this->row, $this->now(), true);
        self::assertSame(2, $result['sequence']);
        self::assertSame('2026-11-01 10:00:00', $result['modified_utc']);
        $provider->failCreate = false;
        $result = $service->provision($this->row, $this->now(), true);
        self::assertSame(3, $result['sequence']);
        self::assertSame('2026-11-01 10:00:01', $result['modified_utc']);
        self::assertTrue($result['_conference_changed']);
        $again = $service->provision($result, $this->now(), true);
        self::assertFalse($again['_conference_changed']);
        self::assertSame(3, $again['sequence']);
        self::assertSame(2, $provider->createCalls);
    }

    public static function staleFields(): array
    {
        return [
            ['uid', str_repeat('b', 64)], ['host_user_id', 9], ['eventtype_id', 4],
            ['start_utc', '2026-11-10 11:00:00'], ['end_utc', '2026-11-10 11:30:00'],
            ['state', 0], ['sequence', 3], ['modified_utc', '2026-11-01 10:00:01'],
        ];
    }

    #[DataProvider('staleFields')]
    public function testStaleSnapshotsNeverProvision(string $field, $value): void
    {
        $saved = $this->row;
        $this->row[$field] = $value;
        $provider = new RecordingConferenceProvider();
        $result = $this->service(['conference_provider' => 'jitsi'], new ConferenceManager([$provider]))
            ->provision($saved, $this->now());
        self::assertFalse($result['_conference_changed']);
        self::assertTrue($result['_conference_stale']);
        self::assertFalse($result['_conference_missing']);
        self::assertSame($this->row['state'], $result['state']);
        self::assertSame($this->row['start_utc'], $result['start_utc']);
        self::assertSame($this->row['end_utc'], $result['end_utc']);
        self::assertSame(0, $provider->createCalls);
        self::assertSame([], $this->writes);
        self::assertSame([], $this->executed);
    }

    public function testFailedRetryStorageNeverReflagsOrTouchesANewerRevision(): void
    {
        $this->row['conference_state'] = 'failed';
        $this->failWrite = true;
        $provider = new RecordingConferenceProvider();
        $result = $this->service(['conference_provider' => 'jitsi'], new ConferenceManager([$provider]))
            ->provision($this->row, $this->now(), true);
        self::assertSame('Conference status could not be saved. Please retry.', $result['_conference_error']);
        // The guarded UPDATE only matches a row without conference state, so a failed
        // booking keeps its first diagnostic and a newer revision is never matched.
        self::assertCount(1, $this->executed);
        self::assertStringContainsString('`conference_state` IS NULL', $this->executed[0]);
        self::assertStringContainsString('`sequence` = 2', $this->executed[0]);
    }

    public function testCancelledAndAnonymisedBookingsNeverRetry(): void
    {
        $provider = new RecordingConferenceProvider();
        $service = $this->service(['conference_provider' => 'jitsi'], new ConferenceManager([$provider]));
        $this->row['conference_state'] = 'failed';
        $this->row['state'] = 2;
        self::assertFalse($service->provision($this->row, $this->now(), true)['_conference_changed']);
        $this->row['state'] = 1;
        $this->row['anonymised_utc'] = '2026-11-01 09:00:00';
        $this->row['invitee_email'] = null;
        $result = $service->provision($this->row, $this->now(), true);
        self::assertNull($result['invitee_email']);
        self::assertSame(0, $provider->createCalls);
    }

    public function testJitsiModeratorUrlRegenerationDoesNotUseInjectedManagerOrExposeItInRow(): void
    {
        $params = [
            'conference_provider' => 'jitsi', 'conference_jitsi_base_url' => 'https://meet.example.com',
            'conference_jitsi_auth' => 'jwt', 'conference_jitsi_jwt_secret' => 'signing-test-secret',
            'conference_jitsi_jwt_app_id' => 'test-app',
        ];
        $result = $this->service($params)->provision($this->row, $this->now());
        self::assertSame('ok', $result['conference_state']);
        parse_str(parse_url($result['conference_url'], PHP_URL_QUERY), $guestQuery);
        $guest = $this->claims($guestQuery['jwt']);
        self::assertFalse($guest['context']['user']['moderator']);
        $failingProvider = new RecordingConferenceProvider();
        $failingProvider->failCreate = true;
        $service = $this->service($params, new ConferenceManager([$failingProvider]));
        $hostUrl = $service->hostUrl($result);
        self::assertNotNull($hostUrl);
        parse_str(parse_url($hostUrl, PHP_URL_QUERY), $hostQuery);
        self::assertTrue($this->claims($hostQuery['jwt'])['context']['user']['moderator']);
        self::assertSame(0, $failingProvider->createCalls);
        self::assertStringNotContainsString($hostQuery['jwt'], json_encode($result));
        $this->row['conference_room'] = 'wrong-room';
        self::assertNull($service->hostUrl($this->row));
    }

    private function claims(string $jwt): array
    {
        return json_decode(base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testTeardownFailureStillClearsFieldsAndReturnsOnlySafeWarning(): void
    {
        $provider = new RecordingConferenceProvider();
        $service = $this->service(['conference_provider' => 'jitsi'], new ConferenceManager([$provider]));
        $saved = $service->provision($this->row, $this->now());
        $this->row['state'] = 2;
        $provider->failDestroy = true;
        $result = $service->destroy($saved);
        self::assertSame(1, $provider->destroyCalls);
        self::assertSame('Conference room cleanup failed. Please retry.', $result['_conference_cleanup_error']);

        foreach (['conference_url', 'conference_room', 'conference_provider', 'conference_state', 'conference_error'] as $field) {
            self::assertNull($result[$field]);
            self::assertNull($this->row[$field]);
        }
    }

    public function testPreErasureSnapshotCanCleanRoomWithoutReturningErasedData(): void
    {
        $provider = new RecordingConferenceProvider();
        $service = $this->service(['conference_provider' => 'jitsi'], new ConferenceManager([$provider]));
        $saved = $service->provision($this->row, $this->now());
        $this->row['invitee_name'] = $this->row['invitee_email'] = $this->row['guest_emails'] = $this->row['custom_fields'] = null;
        $this->row['anonymised_utc'] = '2026-11-01 10:00:01';
        $this->row['conference_room'] = $this->row['conference_url'] = null;
        $result = $service->destroy($saved);
        self::assertNull($result['invitee_name']);
        self::assertNull($result['invitee_email']);
        self::assertSame(1, $provider->destroyCalls);
        $this->row = null;
        $result = $service->destroy($saved);
        self::assertSame(['_conference_missing' => true, '_conference_cleanup_error' => null], $result);
        self::assertSame(2, $provider->destroyCalls);
    }

    public function testDestroyCleansOnlyOldRoomWithoutClearingANewerInstallation(): void
    {
        $this->row['conference_provider'] = 'jitsi';
        $this->row['conference_room'] = 'old-room';
        $saved = $this->row;
        $this->row['conference_room'] = 'new-room';
        $provider = new RecordingConferenceProvider();
        $result = $this->service([], new ConferenceManager([$provider]))->destroy($saved);
        self::assertSame('new-room', $result['conference_room']);
        self::assertSame(['old-room'], $provider->destroyedRooms);
        self::assertSame([], $this->writes);
    }

    public function testOldSnapshotCannotClearRenewedUrlOrFailedNewProvision(): void
    {
        $this->row['conference_provider'] = 'jitsi';
        $this->row['conference_room'] = 'old-room';
        $this->row['conference_url'] = 'https://meet.example.com/old';
        $this->row['conference_state'] = 'ok';
        $saved = $this->row;
        $provider = new RecordingConferenceProvider();
        $service = $this->service([], new ConferenceManager([$provider]));
        $this->row['conference_url'] = 'https://meet.example.com/renewed';
        $result = $service->destroy($saved);
        self::assertSame('https://meet.example.com/renewed', $result['conference_url']);
        self::assertSame([], $provider->destroyedRooms);
        self::assertSame([], $this->writes);
        $this->row['conference_room'] = $this->row['conference_url'] = null;
        $this->row['conference_state'] = 'failed';
        $this->row['conference_error'] = 'Conference link creation failed. Please retry.';
        $result = $service->destroy($saved);
        self::assertSame('failed', $result['conference_state']);
        self::assertSame('Conference link creation failed. Please retry.', $result['conference_error']);
        self::assertSame(['old-room'], $provider->destroyedRooms);
        self::assertSame([], $this->writes);
    }

    public function testOldSnapshotCannotClearAConferenceForRescheduledTimesEvenWithTheSameRoomAndUrl(): void
    {
        $this->row['conference_provider'] = 'jitsi';
        $this->row['conference_room'] = 'old-room';
        $this->row['conference_url'] = 'https://meet.example.com/old-room';
        $this->row['conference_state'] = 'ok';
        $saved = $this->row;
        $this->row['start_utc'] = '2026-11-10 11:00:00';
        $this->row['end_utc'] = '2026-11-10 11:30:00';
        $this->row['sequence']++;
        $this->row['modified_utc'] = '2026-11-01 10:00:01';
        $provider = new RecordingConferenceProvider();
        $result = $this->service([], new ConferenceManager([$provider]))->destroy($saved);
        self::assertSame('2026-11-10 11:00:00', $result['start_utc']);
        self::assertSame('old-room', $result['conference_room']);
        self::assertSame([], $provider->destroyedRooms);
        self::assertSame([], $this->writes);
    }

    public function testDeletedProvisionReturnsMissingFlagsWithoutOldPersonalData(): void
    {
        $saved = $this->row;
        $this->row = null;
        $provider = new RecordingConferenceProvider();
        $result = $this->service([], new ConferenceManager([$provider]))->provision($saved, $this->now());
        self::assertSame([
            '_conference_missing' => true, '_conference_changed' => false, '_conference_stale' => true,
        ], $result);
        self::assertSame(0, $provider->createCalls);
        self::assertSame([], $this->writes);
    }

    public function testOldSnapshotCannotClearANewerActiveRevisionWithIdenticalConferenceMetadata(): void
    {
        $this->row['conference_provider'] = 'jitsi';
        $this->row['conference_room'] = 'old-room';
        $this->row['conference_url'] = 'https://meet.example.com/old-room';
        $this->row['conference_state'] = 'ok';
        $saved = $this->row;
        $this->row['sequence']++;
        $this->row['modified_utc'] = '2026-11-01 10:00:01';
        $provider = new RecordingConferenceProvider();
        $provider->failDestroy = true;
        $result = $this->service([], new ConferenceManager([$provider]))->destroy($saved);
        self::assertSame('ok', $result['conference_state']);
        self::assertSame('old-room', $result['conference_room']);
        self::assertNull($result['_conference_cleanup_error']);
        self::assertSame([], $provider->destroyedRooms);
        self::assertSame([], $this->writes);
    }

    public function testDifferentProviderWithSameRoomIdentifierDoesNotPreventOldProviderTeardown(): void
    {
        $this->row['conference_provider'] = 'jitsi';
        $this->row['conference_room'] = 'old-room';
        $this->row['conference_url'] = 'https://meet.example.com/old-room';
        $this->row['conference_state'] = 'ok';
        $saved = $this->row;
        $this->row['conference_provider'] = 'generic';
        $this->row['conference_url'] = 'https://rooms.example.com/old-room';
        $this->row['sequence']++;
        $provider = new RecordingConferenceProvider();
        $result = $this->service([], new ConferenceManager([$provider]))->destroy($saved);
        self::assertSame(['old-room'], $provider->destroyedRooms);
        self::assertSame('generic', $result['conference_provider']);
        self::assertSame('https://rooms.example.com/old-room', $result['conference_url']);
        self::assertSame([], $this->writes);
    }

    public function testRescheduleClearedMetadataStillTearsDownOnlyTheCapturedOldRoom(): void
    {
        $this->row['conference_provider'] = 'jitsi';
        $this->row['conference_room'] = 'old-room';
        $this->row['conference_url'] = 'https://meet.example.com/old-room';
        $this->row['conference_state'] = 'ok';
        $saved = $this->row;
        $this->row['sequence']++;
        $this->row['modified_utc'] = '2026-11-01 10:00:01';
        $this->row['start_utc'] = '2026-11-10 11:00:00';
        $this->row['end_utc'] = '2026-11-10 11:30:00';

        foreach (['conference_provider', 'conference_room', 'conference_url', 'conference_state', 'conference_error'] as $field) {
            $this->row[$field] = null;
        }

        $provider = new RecordingConferenceProvider();
        $result = $this->service([], new ConferenceManager([$provider]))->destroy($saved);
        self::assertSame(['old-room'], $provider->destroyedRooms);
        self::assertSame('2026-11-10 11:00:00', $result['start_utc']);
        self::assertNull($result['conference_room']);
        self::assertNull($result['conference_provider']);
        self::assertSame([], $this->writes);
    }

    public function testErasedProvisionReturnsOnlyCurrentAnonymisedState(): void
    {
        $saved = $this->row;
        $this->row['invitee_name'] = $this->row['invitee_email'] = $this->row['guest_emails'] = $this->row['custom_fields'] = null;
        $this->row['state'] = 2;
        $this->row['anonymised_utc'] = '2026-11-01 10:00:01';
        $this->row['modified_utc'] = '2026-11-01 10:00:01';
        $result = $this->service()->provision($saved, $this->now());
        self::assertFalse($result['_conference_missing']);
        self::assertTrue($result['_conference_stale']);
        self::assertFalse($result['_conference_changed']);
        self::assertSame(2, $result['state']);
        self::assertNull($result['invitee_name']);
        self::assertNull($result['invitee_email']);
        self::assertStringNotContainsString('invitee@example.test', json_encode($result));
    }

    public function testDatabaseFailuresCannotPropagateSensitiveDiagnosticsOrStaleData(): void
    {
        $service = $this->service(['conference_provider' => 'fixed', 'conference_fixed_url' => 'https://meet.example.com/permanent']);
        $saved = $this->row;
        $this->failWrite = true;
        $result = $service->provision($saved, $this->now());
        self::assertSame('Conference status could not be saved. Please retry.', $result['_conference_error']);
        self::assertSame(1, $result['state']);
        $rollback = array_search('rollback', $this->operations, true);
        self::assertIsInt($rollback);
        // After the rollback, one guarded UPDATE flags the unchanged snapshot as failed.
        self::assertCount(1, $this->executed);
        $mark = $this->executed[0];
        self::assertSame($mark, $this->operations[$rollback + 1]);
        self::assertStringContainsString("`conference_state` = 'failed'", $mark);
        self::assertStringContainsString("`conference_error` = 'Conference status could not be saved. Please retry.'", $mark);
        self::assertStringContainsString('`id` = 7 AND `uid` = \'' . $saved['uid'] . '\' AND `sequence` = 2', $mark);
        self::assertStringContainsString("`modified_utc` = '2026-11-01 10:00:00'", $mark);
        self::assertStringContainsString('`state` IN (0, 1) AND `anonymised_utc` IS NULL AND `conference_state` IS NULL', $mark);
        self::assertStringContainsString("`location_type` = 'conference'", $mark);
        self::assertStringNotContainsString('credential', $mark);
        $this->failRead = true;
        $result = $service->destroy($saved);
        self::assertArrayNotHasKey('invitee_email', $result);
        self::assertSame('Conference room cleanup could not be saved. Please retry.', $result['_conference_cleanup_error']);
        self::assertStringNotContainsString('credential', json_encode($result));
    }
}

final class RecordingConferenceProvider implements ConferenceProviderInterface
{
    public string $name = 'jitsi';
    public bool $outbound = false;
    public bool $failCreate = false;
    public bool $failDestroy = false;
    public int $createCalls = 0;
    public int $destroyCalls = 0;
    public array $destroyedRooms = [];
    public ?MeetingRequest $request = null;

    public function getName(): string { return $this->name; }
    public function getConfigForm(): string { return '<fieldset />'; }
    public function requiresOutboundAccess(): bool { return $this->outbound; }

    public function createMeeting(MeetingRequest $request): MeetingResult
    {
        $this->request = $request;
        $this->createCalls++;

        if ($this->failCreate) {
            throw new \RuntimeException('https://meet.example.com/?jwt=private-token password=credential');
        }

        return new MeetingResult('https://meet.example.com/guest', 'room', 'https://meet.example.com/moderator');
    }

    public function destroyMeeting(string $roomIdentifier): void
    {
        $this->destroyCalls++;
        $this->destroyedRooms[] = $roomIdentifier;

        if ($this->failDestroy) {
            throw new \RuntimeException('https://meet.example.com/?jwt=private-token password=credential');
        }
    }
}
