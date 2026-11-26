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

    public function testCapabilitiesInAUrlAreNeverStoredWhateverTheyAreCalled(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl';
        $opaque = 'Zm9vYmFyYmF6cXV4MTIzNDU2Nzg5MDEyMzQ1Njc4OTA';
        $clickId = 'Cj0KCQiA4L67BhDUARIsADWrl7G1a2b3c4d5e6f7g8h9i0';

        self::assertSame('https://other.example.org/reset?page=2', Attribution::url('https://user:secret@other.example.org/reset?page=2'),
            'A user name and password before the host are dropped');
        self::assertSame('/lp?id=1&view=article', Attribution::url('/lp?x=' . $jwt . '&id=1&ref=' . $opaque . '&view=article', true),
            'A JSON Web Token or an opaque token is dropped under any parameter name');
        self::assertSame('/lp?design=keep', Attribution::url('/lp?jwt=a&sig=b&design=keep&invite=c&user_sig=d&reset=e&confirm=f', true),
            'Parameter names that name a capability are dropped, similar ordinary names are kept');
        self::assertSame('/lp?utm_source=linkedin&gclid=' . $clickId . '&q=some+words+longer+than+thirty-two+characters',
            Attribution::url('/lp?utm_source=linkedin&gclid=' . $clickId . '&q=some+words+longer+than+thirty-two+characters', true),
            'Click IDs and ordinary text stay');

        $state = Attribution::visit(null, $this->request('/lp?utm_source=linkedin&gclid=' . $clickId . '&ref=' . $opaque,
            'https://user:secret@other.example.org/x?t=' . $jwt), $this->at('2026-11-16 08:00:00'));
        foreach (['secret', $jwt, $opaque] as $capability) {
            self::assertStringNotContainsString($capability, json_encode($state), 'Never stored: ' . $capability);
        }
        self::assertSame($clickId, $state['first_touch']['parameters']['gclid']);
    }

    public function testOpaqueTokensOfEveryCommonShapeAreDroppedAndAPasswordWithAnAtSignGoesWhole(): void
    {
        $tokens = [
            'hex' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'uuid' => '9f1c2d3e-4b5a-4c6d-8e7f-a1b2c3d4e5f6',
            'base64url' => 'aB3_dE5fG7hJ9kL1mN3pQ5rS7tU9vW1xY3zA5bC7dE9',
            'base64' => 'Zm9vYmFy+YmF6cXV4MTIzNDU2Nzg5MDEy/MzQ1Njc4OTA=',
        ];

        foreach ($tokens as $shape => $token) {
            self::assertSame('/lp?view=article', Attribution::url('/lp?view=article&ref=' . rawurlencode($token), true),
                'A ' . $shape . ' token is dropped under an ordinary parameter name');
        }

        self::assertSame('https://other.example.org/x', Attribution::url('https://user:p@ss@other.example.org/x'),
            'A password with an unencoded «@» is dropped up to the host, nothing of it is left');
        self::assertSame('//other.example.org/x?page=2', Attribution::url('//user:secret@other.example.org/x?page=2'),
            'A scheme-relative address loses its user name and password too');
        self::assertSame('/x?page=2', Attribution::url('//user:secret@other.example.org/x?page=2', true),
            'A scheme-relative address keeps only path and query where only those are stored');
        $state = Attribution::visit(null, $this->request('/lp', '//user:secret@other.example.org/x'), $this->at('2026-11-16 08:00:00'));
        self::assertStringNotContainsString('secret', json_encode($state), 'A scheme-relative referrer keeps no password');
        self::assertSame('https://other.example.org/x?page=a@b', Attribution::url('https://other.example.org/x?page=a@b'),
            'An «@» in the query never counts as user information');
        self::assertSame('https://other.example.org/p@th?page=2', Attribution::url('https://other.example.org/p@th?page=2'),
            'An «@» in the path never counts as user information');
    }

    public function testCampaignParametersAndClickIdsAlwaysStayWithLongOpaqueValues(): void
    {
        $values = [
            'utm_source' => 'linkedin',
            'utm_medium' => 'paid-social',
            'utm_campaign' => '2026q4linkedinretargetingswisspharmalabs',
            'utm_term' => 'laboratory-it-support-2026-basel-zurich-bern',
            'utm_content' => 'video4b2b2026carouselslide3variantb7',
            'utm_id' => '7d3f9a2b1c8e4f6a9b0c2d4e6f8a1b3c',
            'gclid' => 'Cj0KCQiA4L67BhDUARIsADWrl7G1a2b3c4d5e6f7g8h9i0_BwE',
            'gbraid' => '0AAAAADkPq3x9Y2b7K1mN4pR8sT6vW0zA3cE5gH',
            'wbraid' => 'CkEKCQiA4L67BhDUARIsADWrl7G1a2b3c4d5e6f7g8',
            'fbclid' => 'IwAR2xYz9AbC3dEf4GhI5jKl6MnO7pQr8StU9vWx0Yz1AbC2dEf3GhI4jKl',
            'msclkid' => '0c3f1e2d4b5a69788796a5b4c3d2e1f0',
            'li_fat_id' => 'a1b2c3d4-e5f6-7a8b-9c0d-e1f2a3b4c5d6',
            'ttclid' => 'E.C.P.Cs0BkL2a3B4c5D6e7F8g9H0i1J2k3L4m5N6o7P8q9R0s1T2u3V4w5X6y7Z8',
            'twclid' => '2ab3cd4ef5gh6ij7kl8mn9op0qr1st2uv3wx4yz5',
            'mc_cid' => '9a8b7c6d5e',
            'mc_eid' => 'f1e2d3c4b5a6978877665544332211aa',
        ];
        self::assertSame(Attribution::PARAMETERS, array_keys($values), 'Every campaign parameter and click ID is covered');

        $query = http_build_query($values, '', '&', PHP_QUERY_RFC3986);
        self::assertSame('/lp?' . $query, Attribution::url('/lp?' . $query, true),
            'Campaign parameters and click IDs are never dropped, whatever their names or values look like');

        $state = Attribution::visit(null, $this->request('/lp?' . $query), $this->at('2026-11-16 08:00:00'));
        self::assertSame($values, $state['first_touch']['parameters']);
        self::assertSame('/lp?' . $query, $state['first_touch']['landing_url']);
    }

    public function testLongReadableValuesUnderOtherNamesAreKept(): void
    {
        foreach ([
            'campaign=spring-2026-linkedin-retargeting-switzerland',
            'ref=newsletter_2026_11_kmu_it_services_offer_basel',
            'article=it-support-for-laboratories-in-basel-and-zurich-2026',
            'promo=SUMMER2026-SWISS-PHARMA-LABS-RETARGETING-V2',
            'source=partner.example.org~2026~q4~landingpage~variant~b',
            'q=some+words+and+the+year+2026+longer+than+thirty-two+characters',
            'lang=de-CH&Itemid=101&id=42:laboratory-it-services-for-swiss-pharma-2026',
        ] as $query) {
            self::assertSame('/lp?' . $query, Attribution::url('/lp?' . $query, true), 'Kept: ' . $query);
        }
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

        self::assertLessThanOrEqual(Attribution::MAX_RECORD_BYTES, \strlen($json), 'A whole record stays within its budget, so many rows fit in memory');
        self::assertSame($record, Attribution::decode($json), 'The largest record opens again');
        self::assertSame(mb_substr($long, 0, Attribution::FIT_LENGTH), $record['user_agent'], 'Too large a record keeps the start of each value');
        self::assertSame('2001:db8::1', $record['landing_ip']);

        // An ordinary record keeps every value at its full length.
        $ordinary = Attribution::record(null, $this->submit(['user_agent' => str_repeat('a', 2000)]), $this->at('2026-11-16 08:02:00'));
        self::assertSame(2000, \strlen($ordinary['user_agent']));
    }

    public function testDecodeRejectsAnythingButARecord(): void
    {
        foreach ([null, '', 'not json', '[1,2]', '"text"', '{"version":2}', str_repeat(' ', Attribution::MAX_RECORD_BYTES + 1)] as $value) {
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

    public function testTheStatisticsNeverKeepAnAddressOrANumberAVisitorPutIntoALink(): void
    {
        $state = Attribution::visit(null, $this->request('/lp?utm_source=' . rawurlencode('Anna.Muster@example.org') . '&utm_medium=sms'
            . '&utm_campaign=' . rawurlencode('call +41 79 123 45 67') . '&utm_term=kept', 'https://www.linkedin.com/'), $this->at('2026-11-16 08:00:00'));
        $campaign = Attribution::campaign(Attribution::record($state, $this->submit(), $this->at('2026-11-16 08:30:00')));

        self::assertSame(Attribution::REDACTED, $campaign['utm_source'], 'An e-mail address is not kept');
        self::assertSame('sms', $campaign['utm_medium']);
        self::assertSame(Attribution::REDACTED, $campaign['utm_campaign'], 'A telephone number is not kept');
        self::assertSame('www.linkedin.com', $campaign['referrer_host']);

        $ordinary = Attribution::visit(null, $this->request('/lp?utm_source=linkedin&utm_campaign=kmu-it-2026-11', 'https://www.linkedin.com/'),
            $this->at('2026-11-16 08:00:00'));
        self::assertSame('kmu-it-2026-11', Attribution::campaign(Attribution::record($ordinary, $this->submit(), $this->at('2026-11-16 08:30:00')))['utm_campaign'],
            'An ordinary campaign name with a date stays');

        $forms = ['079-123-45-67' => true, '+41-79-123-45-67' => true, '0041 79 123 45 67' => true, '(044) 123.45.67' => true,
            'launch-2026-11-16' => false, 'q4-2026' => false, 'webinar-20261116' => false];
        foreach ($forms as $value => $redacted) {
            $visit = Attribution::visit(null, $this->request('/lp?utm_campaign=' . rawurlencode($value), 'https://www.linkedin.com/'),
                $this->at('2026-11-16 08:00:00'));
            self::assertSame($redacted ? Attribution::REDACTED : $value,
                Attribution::campaign(Attribution::record($visit, $this->submit(), $this->at('2026-11-16 08:30:00')))['utm_campaign'], $value);
        }
    }

    public function testTheCrmReceivesTheFiveParametersOfOneVisitOnly(): void
    {
        $state = Attribution::visit(null, $this->request('/lp?utm_source=linkedin&utm_medium=paid&utm_campaign=c1&utm_content=a&utm_term=t1',
            'https://www.linkedin.com/'), $this->at('2026-11-16 08:00:00'));
        $state = Attribution::visit($state, $this->request('/lp?utm_source=google&utm_campaign=' . str_repeat('c', 300) . '&gclid=CLICK',
            'https://www.google.com/'), $this->at('2026-11-16 09:00:00'));
        $record = Attribution::record($state, $this->submit(), $this->at('2026-11-16 09:30:00'));

        $last = Attribution::utm($record);
        self::assertSame(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'], array_keys($last));
        self::assertSame(['google', '', str_repeat('c', 255), '', ''], array_values($last), 'Never mixed with the first visit, capped at 255');
        self::assertSame(['linkedin', 'paid', 'c1', 'a', 't1'], array_values(Attribution::utm($record, true)));

        $referrerOnly = Attribution::visit($state, $this->request('/lp', 'https://news.example.org/'), $this->at('2026-11-16 09:10:00'));
        self::assertSame('linkedin', Attribution::utm(Attribution::record($referrerOnly, $this->submit(), $this->at('2026-11-16 09:30:00')))['utm_source'],
            'A last visit without the five parameters falls back to the first one');

        $personal = Attribution::visit(null, $this->request('/lp?utm_source=' . rawurlencode('anna@example.org') . '&utm_term='
            . rawurlencode('079 123 45 67') . '&utm_medium=email', 'https://mail.example.org/'), $this->at('2026-11-16 08:00:00'));
        $values = Attribution::utm(Attribution::record($personal, $this->submit(), $this->at('2026-11-16 08:30:00')));
        self::assertSame([Attribution::REDACTED, 'email', Attribution::REDACTED], [$values['utm_source'], $values['utm_medium'], $values['utm_term']]);
        self::assertSame(array_fill_keys(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'], ''), Attribution::utm(null));

        // Cutting to the output length never hides an address or a number beyond the cut.
        foreach ([255, Attribution::AGGREGATE_LENGTH] as $max) {
            self::assertSame(Attribution::REDACTED, Attribution::campaignValue(str_repeat('a', $max - 8) . '0791234567', $max));
            self::assertSame(Attribution::REDACTED, Attribution::campaignValue(str_repeat('a', $max - 2) . 'name@example.org', $max));
            self::assertSame(str_repeat('a', $max), Attribution::campaignValue(str_repeat('a', $max + 20), $max));
        }
        // An oversized record is cut to FIT_LENGTH per value: a campaign value is checked whole first.
        $query = static function (string $term): string {
            $parts = [];
            foreach (Attribution::PARAMETERS as $name) {
                $parts[] = $name . '=' . ($name === 'utm_term' ? rawurlencode($term) : str_repeat('v', 2000));
            }
            return '/lp?' . implode('&', $parts);
        };
        $term = str_repeat('a', Attribution::FIT_LENGTH - 8) . '0791234567' . str_repeat('b', 1500);
        $big = Attribution::visit(null, $this->request($query($term), 'https://www.linkedin.com/'), $this->at('2026-11-16 08:00:00'));
        $big = Attribution::visit($big, $this->request($query($term) . '&x=1', 'https://news.example.org/'), $this->at('2026-11-16 08:10:00'));
        $record = Attribution::record($big, $this->submit(), $this->at('2026-11-16 08:30:00'));
        self::assertSame(Attribution::REDACTED, $record['last_touch']['parameters']['utm_term'], 'Stored redacted, not cut to a partial number');
        self::assertSame(Attribution::REDACTED, Attribution::utm($record)['utm_term']);
        self::assertSame(Attribution::FIT_LENGTH, mb_strlen($record['last_touch']['parameters']['utm_source']), 'The record was cut to fit');

        $long = Attribution::visit(null, $this->request('/lp?utm_campaign=' . str_repeat('x', 95) . rawurlencode('anna@example.org'),
            'https://www.linkedin.com/'), $this->at('2026-11-16 08:00:00'));
        self::assertSame(Attribution::REDACTED, Attribution::campaign(Attribution::record($long, $this->submit(),
            $this->at('2026-11-16 08:30:00')))['utm_campaign'], 'The statistics check the value before cutting it');
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
