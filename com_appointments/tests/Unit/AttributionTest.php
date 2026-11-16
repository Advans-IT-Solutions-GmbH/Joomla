<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\Attribution;
use PHPUnit\Framework\TestCase;

final class AttributionTest extends TestCase
{
    private const HOST = 'www.example.test';

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    /** A page view as the system plugin passes it: path and query, Referer header, host, query parameters, address. */
    private function request(string $uri, string $referrer = '', string $ip = '192.0.2.10'): array
    {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return ['uri' => $uri, 'referrer' => $referrer, 'host' => self::HOST, 'query' => $query, 'ip' => $ip];
    }

    private function submit(array $overrides = []): array
    {
        return array_replace([
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'accept_language' => 'de-CH,de;q=0.9,en;q=0.8',
            'ip' => '198.51.100.7',
            'referrer' => 'https://www.example.test/de/erstgespraech-kmu-it',
            'host' => self::HOST,
        ], $overrides);
    }

    public function testLandingArticleWithCampaignAndClickIdsIsTheFirstAndLastTouch(): void
    {
        $state = Attribution::visit(null, $this->request(
            '/de/it-services?utm_source=linkedin&utm_medium=paid-social&utm_campaign=kmu-it-2026&utm_term=it%20support'
            . '&utm_content=video-a&utm_id=42&li_fat_id=LI-123&gclid=G-1&gbraid=GB&wbraid=WB&fbclid=FB&msclkid=MS'
            . '&ttclid=TT&twclid=TW&mc_cid=MC&mc_eid=ME&unrelated=dropped',
            'https://www.linkedin.com/feed/'
        ), $this->at('2026-11-16 08:00:00'));

        self::assertSame('2026-11-16 08:00:00', $state['first_seen_utc']);
        self::assertSame('192.0.2.10', $state['landing_ip']);
        self::assertStringStartsWith('/de/it-services?utm_source=linkedin', $state['landing_url']);
        self::assertSame(1, $state['views']);
        $touch = $state['first_touch'];
        self::assertSame($touch, $state['last_touch']);
        self::assertSame('https://www.linkedin.com/feed/', $touch['referrer']);
        self::assertSame('www.linkedin.com', $touch['referrer_host']);
        self::assertSame([
            'utm_source' => 'linkedin', 'utm_medium' => 'paid-social', 'utm_campaign' => 'kmu-it-2026', 'utm_term' => 'it support',
            'utm_content' => 'video-a', 'utm_id' => '42', 'gclid' => 'G-1', 'gbraid' => 'GB', 'wbraid' => 'WB', 'fbclid' => 'FB',
            'msclkid' => 'MS', 'li_fat_id' => 'LI-123', 'ttclid' => 'TT', 'twclid' => 'TW', 'mc_cid' => 'MC', 'mc_eid' => 'ME',
        ], $touch['parameters'], 'Every whitelisted parameter, in a fixed order, nothing else');
    }

    public function testReferrerFromAnotherHostIsATouchButTheOwnSiteIsNot(): void
    {
        $now = $this->at('2026-11-16 08:00:00');
        $external = Attribution::visit(null, $this->request('/de/it-services', 'https://www.google.com/'), $now);
        self::assertSame('www.google.com', $external['first_touch']['referrer_host']);
        self::assertSame([], $external['first_touch']['parameters']);

        foreach (['https://www.example.test/de/', 'https://example.test/de/', 'http://WWW.EXAMPLE.TEST/x', ''] as $referrer) {
            $state = Attribution::visit(null, $this->request('/de/it-services', $referrer), $now);
            self::assertNull($state['first_touch'], 'No touch for ' . ($referrer === '' ? 'a direct visit' : $referrer));
            self::assertSame('/de/it-services', $state['landing_url'], 'The first landing URL is kept even without a touch');
        }
    }

    public function testLastTouchIsOverwrittenOnlyByANewCampaignOrExternalReferrer(): void
    {
        $state = Attribution::visit(null, $this->request('/de/it-services?utm_source=linkedin&utm_campaign=a', 'https://www.linkedin.com/'),
            $this->at('2026-11-16 08:00:00'));
        // Internal navigation and a direct visit keep the touches.
        $state = Attribution::visit($state, $this->request('/de/team', 'https://www.example.test/de/it-services'), $this->at('2026-11-16 08:01:00'));
        $state = Attribution::visit($state, $this->request('/de/kontakt'), $this->at('2026-11-16 08:02:00'));
        self::assertSame('a', $state['last_touch']['parameters']['utm_campaign']);
        self::assertSame('2026-11-16 08:00:00', $state['last_touch']['time_utc']);
        // A reload of the same campaign keeps the time of the first arrival.
        $state = Attribution::visit($state, $this->request('/de/it-services?utm_source=linkedin&utm_campaign=a', 'https://www.linkedin.com/'),
            $this->at('2026-11-16 08:03:00'));
        self::assertSame('2026-11-16 08:00:00', $state['last_touch']['time_utc']);
        // An internal link that carries the same parameters keeps the external arrival and its referrer host.
        $state = Attribution::visit($state, $this->request('/de/erstgespraech?utm_source=linkedin&utm_campaign=a', 'https://www.example.test/de/it-services'),
            $this->at('2026-11-16 08:03:30'));
        self::assertSame('2026-11-16 08:00:00', $state['last_touch']['time_utc']);
        self::assertSame('www.linkedin.com', $state['last_touch']['referrer_host']);
        self::assertSame('www.linkedin.com', Attribution::campaign(['last_touch' => $state['last_touch']])['referrer_host']);
        // Another campaign replaces the last touch, the first stays.
        $state = Attribution::visit($state, $this->request('/de/it-services?utm_source=newsletter&utm_campaign=b&mc_cid=x', 'https://mail.example.org/'),
            $this->at('2026-11-16 08:04:00'));
        self::assertSame('a', $state['first_touch']['parameters']['utm_campaign']);
        self::assertSame('b', $state['last_touch']['parameters']['utm_campaign']);
        self::assertSame('2026-11-16 08:04:00', $state['last_touch']['time_utc']);
        self::assertSame('2026-11-16 08:00:00', $state['first_seen_utc']);
        self::assertSame('/de/it-services?utm_source=linkedin&utm_campaign=a', $state['landing_url'], 'The first landing URL never changes');
        self::assertSame(6, $state['views']);
        self::assertSame('/de/it-services?utm_source=newsletter&utm_campaign=b&mc_cid=x', $state['page_url']);
        // An internal link with other parameters is a new touch, but names no referrer host of the site itself.
        $internal = Attribution::visit($state, $this->request('/de/x?utm_source=site&utm_campaign=banner', 'https://www.example.test/de/'),
            $this->at('2026-11-16 08:05:00'));
        self::assertSame('banner', $internal['last_touch']['parameters']['utm_campaign']);
        self::assertSame('', $internal['last_touch']['referrer_host']);
    }

    public function testValuesAreCleanedCappedAndCredentialsLeaveTheUrls(): void
    {
        self::assertSame('ab', Attribution::clean("a\x00\x7f\u{202E}\tb\r\n"), 'Control and format characters are stripped');
        self::assertSame('', Attribution::clean("\xC3\x28"), 'Invalid UTF-8 is dropped');
        self::assertSame('', Attribution::clean(['array']));
        self::assertSame(Attribution::MAX_LENGTH, mb_strlen(Attribution::clean(str_repeat('ä', 5000))));

        $state = Attribution::visit(null, $this->request(
            '/index.php?option=com_appointments&view=manage&id=4&action=erase&signature=SECRETSIG&token=SECRETTOKEN'
            . '&0123456789abcdef0123456789abcdef=1&UTM_SOURCE=Upper&utm_campaign[]=array&utm_medium=' . str_repeat('m', 3000),
            "https://other.example.org/reset?code=SECRETCODE&page=2#frag\n"
        ), $this->at('2026-11-16 08:00:00'));

        $stored = json_encode($state);
        foreach (['SECRETSIG', 'SECRETTOKEN', '0123456789abcdef0123456789abcdef', 'SECRETCODE', '#frag'] as $secret) {
            self::assertStringNotContainsString($secret, $stored, 'Never stored: ' . $secret);
        }
        self::assertSame('Upper', $state['first_touch']['parameters']['utm_source'], 'Parameter names are matched case-insensitively');
        self::assertArrayNotHasKey('utm_campaign', $state['first_touch']['parameters'], 'An array value is ignored');
        self::assertSame(Attribution::MAX_LENGTH, strlen($state['first_touch']['parameters']['utm_medium']));
        self::assertSame('https://other.example.org/reset?page=2', $state['first_touch']['referrer']);
        self::assertSame('', Attribution::visit(null, $this->request('/', '', 'not-an-ip'), $this->at('2026-11-16 08:00:00'))['landing_ip']);
    }

    public function testRecordForTheBookingCombinesTheSessionAndTheSubmit(): void
    {
        $state = Attribution::visit(null, $this->request('/de/it-services?utm_source=linkedin', 'https://www.linkedin.com/'), $this->at('2026-11-16 08:00:00'));
        $state = Attribution::visit($state, $this->request('/de/erstgespraech-kmu-it', 'https://www.example.test/de/it-services?utm_source=linkedin'),
            $this->at('2026-11-16 08:05:00'));
        $record = Attribution::record($state, $this->submit(), $this->at('2026-11-16 08:10:00'));

        self::assertSame(Attribution::VERSION, $record['version']);
        self::assertSame('2026-11-16 08:00:00', $record['first_seen_utc']);
        self::assertSame('/de/it-services?utm_source=linkedin', $record['landing_url']);
        self::assertSame('192.0.2.10', $record['landing_ip']);
        self::assertSame(2, $record['page_views']);
        self::assertSame('/de/erstgespraech-kmu-it', $record['booking_page_url']);
        self::assertSame('198.51.100.7', $record['booking_ip']);
        self::assertSame('de-CH,de;q=0.9,en;q=0.8', $record['accept_language']);
        self::assertSame(['desktop', 'Chrome', 'Windows'], [$record['device'], $record['browser'], $record['os']]);
        self::assertStringContainsString('Chrome/131', $record['user_agent']);
        self::assertSame('linkedin', $record['first_touch']['parameters']['utm_source']);
        self::assertSame($record, Attribution::decode(Attribution::encode($record)), 'Stored as JSON and read back unchanged');

        // Another tab opened an article after the booking form: the Referer of the submit names the booking page.
        $tabs = Attribution::visit($state, $this->request('/de/blog/artikel', 'https://www.example.test/de/'), $this->at('2026-11-16 08:06:00'));
        self::assertSame('/de/blog/artikel', $tabs['page_url']);
        self::assertSame('/de/erstgespraech-kmu-it', Attribution::record($tabs, $this->submit(), $this->at('2026-11-16 08:10:00'))['booking_page_url']);
        self::assertSame('/de/blog/artikel', Attribution::record($tabs, $this->submit(['referrer' => '']), $this->at('2026-11-16 08:10:00'))['booking_page_url'],
            'Without a Referer of the site the last page of the session counts');
        self::assertSame('/de/blog/artikel', Attribution::record($tabs, $this->submit(['referrer' => 'https://evil.example.org/x']),
            $this->at('2026-11-16 08:10:00'))['booking_page_url'], 'A Referer of another host is never the booking page');

        // Without a session (plugin off, session expired): what the submit itself tells, the page from its Referer.
        $bare = Attribution::record(null, $this->submit(['referrer' => 'https://www.example.test/de/erstgespraech-kmu-it?x=1']), $this->at('2026-11-16 08:10:00'));
        self::assertNull($bare['first_touch']);
        self::assertSame('', $bare['landing_url']);
        self::assertSame('/de/erstgespraech-kmu-it?x=1', $bare['booking_page_url']);
        $foreign = Attribution::record(null, $this->submit(['referrer' => 'https://evil.example.org/x']), $this->at('2026-11-16 08:10:00'));
        self::assertSame('', $foreign['booking_page_url'], 'A booking page on another host is not taken');
    }

    public function testTheRecordIsSealedWithThePersonalFieldsOfTheBooking(): void
    {
        self::assertContains('attribution', \Advans\Component\Appointments\Administrator\Helper\BookingData::COLUMNS);
        self::assertContains('attribution', \Advans\Component\Appointments\Administrator\Helper\Anonymiser::ERASED_FIELDS);
        $data = new \Advans\Component\Appointments\Administrator\Helper\BookingData(
            new \Advans\Component\Appointments\Administrator\Helper\KeyProvider('unit-site-secret'));
        $json = Attribution::encode(Attribution::record(null, $this->submit(), $this->at('2026-11-16 08:10:00')));
        $row = ['id' => 7, 'eventtype_id' => 3, 'host_user_id' => 11, 'uid' => str_repeat('ab', 32), 'created_utc' => '2026-11-04 09:00:00',
            'attribution' => $json];
        $sealed = $data->seal($row);

        self::assertStringStartsWith('v2:', $sealed['attribution']);
        self::assertStringNotContainsString('198.51.100.7', $sealed['attribution']);
        self::assertSame($json, $data->open($sealed)['attribution']);
    }

    public function testTheLargestRecordTheLimitsAllowOpensAgain(): void
    {
        $long = str_repeat("\u{1F600}", 5000);
        $query = array_fill_keys(Attribution::PARAMETERS, $long);
        $uri = '/' . str_repeat('p', 9000) . '?' . http_build_query($query);
        $state = Attribution::visit(null, ['uri' => $uri, 'referrer' => 'https://a.example.org/' . $long, 'host' => self::HOST, 'query' => $query,
            'ip' => '2001:db8::1'], $this->at('2026-11-16 08:00:00'));
        $query['utm_source'] = 'x' . $long;
        $state = Attribution::visit($state, ['uri' => $uri, 'referrer' => 'https://b.example.org/' . $long, 'host' => self::HOST, 'query' => $query,
            'ip' => '2001:db8::1'], $this->at('2026-11-16 08:01:00'));
        $record = Attribution::record($state, $this->submit(['user_agent' => $long, 'accept_language' => $long,
            'referrer' => 'https://www.example.test/' . $long]), $this->at('2026-11-16 08:02:00'));
        $json = Attribution::encode($record);

        self::assertGreaterThan(262144, \strlen($json), 'Larger than the earlier limit');
        self::assertSame($record, Attribution::decode($json), 'The largest record opens again');
    }

    public function testDecodeRejectsAnythingButARecord(): void
    {
        foreach ([null, '', 'not json', '[1,2]', '"text"', '{"version":2}', str_repeat(' ', 1048577)] as $value) {
            self::assertNull(Attribution::decode($value));
        }
    }

    public function testClientIsDerivedFromTheUserAgent(): void
    {
        $cases = [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1' => ['mobile', 'Safari', 'iOS'],
            'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' => ['tablet', 'Safari', 'iPadOS'],
            'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36' => ['mobile', 'Samsung Internet', 'Android'],
            'Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36' => ['tablet', 'Chrome', 'Android'],
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0' => ['desktop', 'Edge', 'Windows'],
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.6; rv:132.0) Gecko/20100101 Firefox/132.0' => ['desktop', 'Firefox', 'macOS'],
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 OPR/114.0.0.0' => ['desktop', 'Opera', 'Linux'],
            'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36' => ['desktop', 'Chrome', 'ChromeOS'],
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' => ['bot', 'Other', 'Other'],
            '' => ['unknown', '', ''],
        ];

        foreach ($cases as $agent => $expected) {
            $client = Attribution::client($agent);
            self::assertSame($expected, [$client['device'], $client['browser'], $client['os']], $agent);
        }
    }

    public function testEveryFieldIsItsOwnCsvColumn(): void
    {
        $state = Attribution::visit(null, $this->request('/lp?utm_source=linkedin&utm_medium=paid&utm_campaign=c1&gclid=G', 'https://www.linkedin.com/x'),
            $this->at('2026-11-16 08:00:00'));
        $state = Attribution::visit($state, $this->request('/lp?utm_source=newsletter&utm_campaign=c2', 'https://mail.example.org/'), $this->at('2026-11-16 09:00:00'));
        $flat = Attribution::flatten(Attribution::record($state, $this->submit(), $this->at('2026-11-16 09:30:00')));

        self::assertSame(Attribution::columns(), array_keys($flat));
        self::assertSame('linkedin', $flat['first_utm_source']);
        self::assertSame('paid', $flat['first_utm_medium']);
        self::assertSame('c1', $flat['first_utm_campaign']);
        self::assertSame('G', $flat['first_gclid']);
        self::assertSame('www.linkedin.com', $flat['first_referrer_host']);
        self::assertSame('https://www.linkedin.com/x', $flat['first_referrer']);
        self::assertSame('newsletter', $flat['last_utm_source']);
        self::assertSame('c2', $flat['last_utm_campaign']);
        self::assertSame('198.51.100.7', $flat['booking_ip']);
        self::assertSame('192.0.2.10', $flat['landing_ip']);
        self::assertSame('Windows', $flat['os']);
        self::assertSame(array_fill_keys(Attribution::columns(), ''), Attribution::flatten(null), 'A booking without a record has empty columns');
    }

    public function testCampaignOfTheAggregateIsTheLastTouchCappedAndHasNoPersonalField(): void
    {
        $state = Attribution::visit(null, $this->request('/lp?utm_source=linkedin&utm_medium=paid&utm_campaign=c1', 'https://www.linkedin.com/'),
            $this->at('2026-11-16 08:00:00'));
        $state = Attribution::visit($state, $this->request('/lp?utm_source=' . str_repeat('s', 300) . '&utm_campaign=c2&mc_eid=PERSON', 'https://mail.example.org/'),
            $this->at('2026-11-16 09:00:00'));
        $campaign = Attribution::campaign(Attribution::record($state, $this->submit(), $this->at('2026-11-16 09:30:00')));

        self::assertSame(['utm_source', 'utm_medium', 'utm_campaign', 'referrer_host'], array_keys($campaign));
        self::assertSame(str_repeat('s', Attribution::AGGREGATE_LENGTH), $campaign['utm_source']);
        self::assertSame('', $campaign['utm_medium']);
        self::assertSame('c2', $campaign['utm_campaign']);
        self::assertSame('mail.example.org', $campaign['referrer_host']);
        self::assertSame(['utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '', 'referrer_host' => ''], Attribution::campaign(null));
    }

    public function testLinesForTheHostAreLabelledAndLeaveOutEmptyValues(): void
    {
        $state = Attribution::visit(null, $this->request('/lp?utm_source=linkedin&utm_campaign=c1', 'https://www.linkedin.com/'), $this->at('2026-11-16 08:00:00'));
        $lines = Attribution::lines(Attribution::record($state, $this->submit(), $this->at('2026-11-16 08:30:00')), static fn (string $key): string => '[' . $key . ']');
        $labels = array_column($lines, 0);
        $values = array_column($lines, 1, 0);

        self::assertContains('[COM_APPOINTMENTS_ATTRIBUTION_FIRST_SEEN]', $labels);
        self::assertSame('utm_source=linkedin, utm_campaign=c1', $values['[COM_APPOINTMENTS_ATTRIBUTION_FIRST_PARAMETERS]']);
        self::assertSame('https://www.linkedin.com/', $values['[COM_APPOINTMENTS_ATTRIBUTION_FIRST_REFERRER]']);
        self::assertSame('desktop, Chrome, Windows', $values['[COM_APPOINTMENTS_ATTRIBUTION_DEVICE]']);
        self::assertSame('198.51.100.7', $values['[COM_APPOINTMENTS_ATTRIBUTION_BOOKING_IP]']);
        self::assertNotContains('[COM_APPOINTMENTS_ATTRIBUTION_LAST_PARAMETERS]', $labels, 'The last touch is left out while it is the first');
        self::assertSame([], Attribution::lines(null, static fn (string $key): string => $key));
    }

    public function testMailsAndTheCalendarEntryShowOnlyTheCampaignName(): void
    {
        $translate = static fn (string $key): string => $key === 'COM_APPOINTMENTS_ATTRIBUTION_CAMPAIGN' ? 'Campaign' : $key;
        $state = Attribution::visit(null, $this->request('/lp?utm_source=linkedin&utm_campaign=first-campaign&gclid=G-1', 'https://www.linkedin.com/feed/'),
            $this->at('2026-11-16 08:00:00'));
        $state = Attribution::visit($state, $this->request('/lp?utm_source=newsletter&utm_campaign=last-campaign&mc_cid=MC-1', 'https://mail.example.org/'),
            $this->at('2026-11-16 08:10:00'));
        $record = Attribution::record($state, $this->submit(), $this->at('2026-11-16 08:30:00'));

        // The last touch, else the first.
        self::assertSame('last-campaign', Attribution::campaignName($record));
        $text = Attribution::text($record, $translate, '%s:');
        self::assertSame('Campaign: last-campaign', $text, 'One line with the campaign name and nothing else');
        foreach (['198.51.100.7', '192.0.2.10', 'Chrome', 'Windows', 'desktop', 'linkedin', 'mail.example.org', 'G-1', 'MC-1', 'de-CH', '/lp', 'newsletter'] as $hidden) {
            self::assertStringNotContainsString($hidden, $text, 'Never in a mail or calendar entry: ' . $hidden);
        }

        $noLastCampaign = $record;
        $noLastCampaign['last_touch']['parameters'] = ['utm_source' => 'newsletter'];
        self::assertSame('first-campaign', Attribution::campaignName($noLastCampaign), 'A last touch without a campaign falls back to the first');
        self::assertSame('Campagne : first-campaign', Attribution::text($noLastCampaign, static fn (string $key): string => 'Campagne', '%s :'));

        // No campaign: no line at all, not even a label.
        $direct = Attribution::record(Attribution::visit(null, $this->request('/lp', 'https://www.google.com/'), $this->at('2026-11-16 08:00:00')),
            $this->submit(), $this->at('2026-11-16 08:30:00'));
        self::assertSame('', Attribution::campaignName($direct));
        self::assertSame('', Attribution::text($direct, $translate, '%s:'));
        self::assertSame('', Attribution::text(null, $translate, '%s:'));
    }
}
