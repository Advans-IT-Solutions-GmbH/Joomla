<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once __DIR__ . '/DavTestKit.php';

use Advans\Component\Appointments\Administrator\Helper\Team\AuditEvent;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Advans\Component\Appointments\Administrator\Service\Team\CleanupServiceInterface;
use Advans\Component\Appointments\Vendor\Sabre\HTTP\Request;
use Advans\Component\Appointments\Vendor\Sabre\HTTP\ResponseInterface;
use PHPUnit\Framework\TestCase;

/**
 * The CalDAV server end to end with the real sabre/dav copy and in-memory
 * services: transport and authentication rules, discovery, default deny, the
 * roles of specification 7, sync, ETag preconditions and the clean-up views.
 */
final class DavServerTest extends TestCase
{
    private DavWorld $world;
    private string $alice;
    private string $teamUri;
    private string $otherUri;

    protected function setUp(): void
    {
        $this->world = new DavWorld($this);
        $this->teamUri = $this->world->calendar(1, 'Team');
        $this->otherUri = $this->world->calendar(2, 'Board');
        $this->alice = $this->world->device(5, 'Alice');
    }

    private static function ics(string $uid, string $summary = 'Planning with client', string $start = '20261110T090000Z', string $end = '20261110T100000Z'): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTAMP:20261104T100000Z\r\n"
            . "DTSTART:{$start}\r\nDTEND:{$end}\r\nSUMMARY:{$summary}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /**
     * @param   array<string, string>  $headers
     */
    private function send(string $method, string $path, array $headers = [], string $body = '', ?string $password = null, string $user = 'alice', bool $https = true): ResponseInterface
    {
        if (!array_key_exists('Authorization', $headers)) {
            $headers['Authorization'] = 'Basic ' . base64_encode($user . ':' . ($password ?? $this->alice));
        }

        if ($headers['Authorization'] === null) {
            unset($headers['Authorization']);
        }

        return $this->world->gateway($https)->handle(new Request($method, $path, $headers, $body));
    }

    private function propfind(string $path, string $depth, string $props = '<d:resourcetype/><d:getetag/>'): ResponseInterface
    {
        return $this->send('PROPFIND', $path, ['Depth' => $depth, 'Content-Type' => 'application/xml'], '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/"><d:prop>' . $props . '</d:prop></d:propfind>');
    }

    private function sync(string $calendarUri, string $token = ''): ResponseInterface
    {
        return $this->send('REPORT', '/dav/calendars/u5/' . $calendarUri . '/', ['Depth' => '1', 'Content-Type' => 'application/xml'], '<?xml version="1.0"?><d:sync-collection xmlns:d="DAV:"><d:sync-token>' . htmlspecialchars($token) . '</d:sync-token><d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>');
    }

    private static function body(ResponseInterface $response): string
    {
        return $response->getBodyAsString();
    }

    private static function assertNoStore(ResponseInterface $response): void
    {
        self::assertSame('private, no-store', $response->getHeader('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->getHeader('X-Robots-Tag'));
        self::assertNull($response->getHeader('Set-Cookie'));

        foreach (array_keys($response->getHeaders()) as $name) {
            self::assertStringStartsNotWith('x-sabre', strtolower((string) $name));
        }
    }

    // Transport and authentication.

    public function testPlainHttpIsRefusedWithoutBodyOrRedirect(): void
    {
        foreach (['/dav/', '/.well-known/caldav'] as $path) {
            $response = $this->send('PROPFIND', $path, [], '', null, 'alice', false);

            self::assertSame(403, $response->getStatus());
            self::assertSame('', self::body($response));
            self::assertNull($response->getHeader('Location'));
            self::assertNoStore($response);
        }

        self::assertSame([], $this->world->lockout->calls);
    }

    public function testWellKnownRedirectsEveryMethodToTheDavAddress(): void
    {
        foreach (['GET', 'PROPFIND', 'OPTIONS', 'PUT'] as $method) {
            $response = $this->send($method, '/.well-known/caldav', ['Authorization' => null]);

            self::assertSame(301, $response->getStatus());
            self::assertSame('https://www.example.org/dav/', $response->getHeader('Location'));
            self::assertNoStore($response);
        }

        $response = $this->world->gateway(true, 'https://cal.example.org/calendars/')->handle(new Request('PROPFIND', '/.well-known/caldav/'));
        self::assertSame('https://cal.example.org/calendars/', $response->getHeader('Location'));

        // On the same host only the path, so a Host header never comes back in the answer.
        $response = $this->world->gateway()->handle(new Request('GET', '/.well-known/caldav', ['Host' => 'WWW.example.org']));
        self::assertSame('/dav/', $response->getHeader('Location'));
        $response = $this->world->gateway()->handle(new Request('GET', '/.well-known/caldav', ['Host' => 'evil.example']));
        self::assertSame('https://www.example.org/dav/', $response->getHeader('Location'));
    }

    public function testEveryMethodIsAuthenticatedFirst(): void
    {
        foreach (['OPTIONS', 'PROPFIND', 'REPORT', 'PUT', 'DELETE', 'MKCALENDAR'] as $method) {
            $response = $this->send($method, '/dav/', ['Authorization' => null]);

            self::assertSame(401, $response->getStatus(), $method);
            self::assertSame('Basic realm="Test site", charset="UTF-8"', $response->getHeader('WWW-Authenticate'));
            self::assertNull($response->getHeader('X-Appointments-Authorization'));
            self::assertNoStore($response);
        }

        self::assertSame([], $this->world->lockout->calls, 'A request without credentials is no failed sign-in.');
    }

    public function testEveryRefusedCredentialGetsTheSameAnswer(): void
    {
        $answers = [
            'Joomla password'        => $this->send('PROPFIND', '/dav/', ['Depth' => '0'], '', 'correct horse battery staple'),
            'wrong app password'     => $this->send('PROPFIND', '/dav/', ['Depth' => '0'], '', 'ABCD-EFGH-IJKL-MNOP-QRST-UVWX-YZ23-4567'),
            'unknown user'           => $this->send('PROPFIND', '/dav/', ['Depth' => '0'], '', null, 'mallory'),
            'other scheme'           => $this->send('PROPFIND', '/dav/', ['Depth' => '0', 'Authorization' => 'Bearer abc']),
            'broken Base64'          => $this->send('PROPFIND', '/dav/', ['Depth' => '0', 'Authorization' => 'Basic ***']),
        ];

        foreach ($answers as $case => $response) {
            self::assertSame(401, $response->getStatus(), $case);
            self::assertSame('', self::body($response), $case);
            self::assertSame('received', $response->getHeader('X-Appointments-Authorization'), $case);
            self::assertSame(
                ['Cache-Control', 'Pragma', 'X-Robots-Tag', 'X-Content-Type-Options', 'WWW-Authenticate', 'X-Appointments-Authorization'],
                array_keys($response->getHeaders()),
                $case
            );
        }

        // The Joomla password never reached a lookup; the other two well-formed attempts did.
        self::assertSame(2, $this->world->authenticateCalls);
        $alice = 'dav:alice@192.0.2.10';
        self::assertSame(['retry:' . $alice, 'failure:' . $alice, 'retry:' . $alice, 'failure:' . $alice, 'retry:dav:mallory@192.0.2.10', 'failure:dav:mallory@192.0.2.10'], $this->world->lockout->calls);
        self::assertSame(['malformed', 'invalid', 'invalid'], array_map(static fn (array $e) => $e[1]['details']['reason'], $this->world->audit->events));
    }

    public function testALockIsAnsweredWith429AlsoForTheCorrectPassword(): void
    {
        $this->world->lockout->retryAfter = 240;
        $response = $this->propfind('/dav/', '0');

        self::assertSame(429, $response->getStatus());
        self::assertSame('240', $response->getHeader('Retry-After'));
        self::assertSame(0, $this->world->authenticateCalls);
        self::assertSame([AuditEvent::DAV_LOCKED], $this->world->audit->names());
    }

    public function testSuccessIsRecordedAndTheUserNameIsCaseInsensitiveForTheLock(): void
    {
        $response = $this->send('PROPFIND', '/dav/', ['Depth' => '0'], '', null, 'ALICE');

        self::assertSame(207, $response->getStatus());
        self::assertSame(['retry:dav:alice@192.0.2.10', 'success:dav:alice@192.0.2.10'], $this->world->lockout->calls);
    }

    public function testAnActiveDeviceWithoutSecondFactorIsRefused(): void
    {
        $password = $this->world->device(6, 'bob', TeamStates::DEVICE_ACTIVE, false);
        $this->world->grant(6, 1, CalendarRole::Write);

        $response = $this->send('PROPFIND', '/dav/calendars/u6/', ['Depth' => '1'], '', $password, 'bob');

        self::assertSame(403, $response->getStatus());
        self::assertSame('', self::body($response));
        self::assertSame('no_mfa', $this->world->audit->events[0][1]['details']['reason']);
        // The secret was right: the attempt counts as a success, not as a failure.
        self::assertSame(['retry:dav:bob@192.0.2.10', 'success:dav:bob@192.0.2.10'], $this->world->lockout->calls);
    }

    public function testBodiesAboveOneMebibyteAndXmlEntitiesAreRefused(): void
    {
        self::assertSame(413, $this->send('PUT', '/dav/calendars/u5/' . $this->teamUri . '/a.ics', ['Content-Length' => '1048577'])->getStatus());
        self::assertSame(413, $this->send('PUT', '/dav/calendars/u5/' . $this->teamUri . '/a.ics', [], str_repeat('x', 1048577))->getStatus());

        $xxe = '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/passwd">]><d:propfind xmlns:d="DAV:"><d:prop><d:displayname>&x;</d:displayname></d:prop></d:propfind>';
        self::assertSame(400, $this->send('PROPFIND', '/dav/', ['Depth' => '0'], $xxe)->getStatus());

        // The same declaration in UTF-16 would pass a byte search; only plain UTF-8 is accepted.
        $utf16 = "\xFF\xFE" . mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?>' . substr($xxe, 21), 'UTF-16LE', 'UTF-8');
        self::assertSame(400, $this->send('PROPFIND', '/dav/', ['Depth' => '0'], $utf16)->getStatus());
        self::assertSame(400, $this->send('REPORT', '/dav/', ['Depth' => '0'], "<?xml version=\"1.0\"?>\0<x/>")->getStatus());
        self::assertSame(400, $this->send('PROPFIND', '/dav/', ['Depth' => '0'], '<?xml version="1.0" encoding="UTF-7"?>+ADw-!DOCTYPE d+AD4-<d:propfind xmlns:d="DAV:"/>')->getStatus());
        self::assertSame(400, $this->send('PROPFIND', '/dav/', ['Depth' => '0'], "\xEF\xBB\xBF" . '<?xml version="1.0" encoding="ISO-8859-1"?><d:propfind xmlns:d="DAV:"/>')->getStatus());
        self::assertSame(400, $this->send('REPORT', '/dav/', ['Depth' => '0'], '')->getStatus(), 'An empty REPORT is the client\'s fault.');
        $broken = $this->send('REPORT', '/dav/', ['Depth' => '0'], '<d:sync-collection xmlns:d="DAV:"><unclosed>');
        self::assertSame(400, $broken->getStatus());
        self::assertStringNotContainsString('unclosed', self::body($broken));
        self::assertSame(207, $this->send('PROPFIND', '/dav/', ['Depth' => '0'], '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>')->getStatus());
    }

    public function testMethodsOutsideTheCalDavSubsetAreForbidden(): void
    {
        foreach (['MKCALENDAR', 'MKCOL', 'ACL', 'POST', 'MOVE', 'COPY', 'LOCK', 'UNLOCK', 'TRACE'] as $method) {
            $response = $this->send($method, '/dav/calendars/u5/x/');

            self::assertSame(403, $response->getStatus(), $method);
            self::assertNoStore($response);
        }
    }

    public function testAFailingDeviceLookupCountsAndTellsTheClientToRetry(): void
    {
        $this->world->notReady = true;
        $response = $this->propfind('/dav/', '0');

        self::assertSame(503, $response->getStatus());
        self::assertSame('', self::body($response));
        self::assertSame(['retry:dav:alice@192.0.2.10', 'failure:dav:alice@192.0.2.10'], $this->world->lockout->calls);
    }

    public function testAFailingLockOutGivesNoInternalDetail(): void
    {
        $this->world->lockout->fail = true;
        $response = $this->propfind('/dav/', '0');

        self::assertSame(503, $response->getStatus());
        self::assertSame('', self::body($response));
        self::assertSame(0, $this->world->authenticateCalls);
    }

    // Discovery and default deny.

    public function testDiscoveryLeadsToTheOwnPrincipalAndHome(): void
    {
        $root = $this->propfind('/dav/', '0', '<d:current-user-principal/>');
        self::assertSame(207, $root->getStatus());
        self::assertStringContainsString('<d:href>/dav/principals/u5/</d:href>', self::body($root));

        $principal = $this->propfind('/dav/principals/u5/', '0', '<c:calendar-home-set/><d:displayname/>');
        self::assertStringContainsString('<d:href>/dav/calendars/u5/</d:href>', self::body($principal));
        self::assertNoStore($principal);
    }

    public function testNoOtherPrincipalOrHomeExists(): void
    {
        self::assertSame(404, $this->propfind('/dav/principals/u6/', '0')->getStatus());
        self::assertSame(404, $this->propfind('/dav/calendars/u6/', '0')->getStatus());

        $list = $this->propfind('/dav/principals/', '1');
        self::assertSame(1, substr_count(self::body($list), '/dav/principals/u'));

        $search = $this->send('REPORT', '/dav/principals/', ['Depth' => '0', 'Content-Type' => 'application/xml'], '<?xml version="1.0"?><d:principal-property-search xmlns:d="DAV:"><d:property-search><d:prop><d:displayname/></d:prop><d:match>a</d:match></d:property-search><d:prop><d:displayname/></d:prop></d:principal-property-search>');
        self::assertSame(403, $search->getStatus());
    }

    public function testWithoutAGrantTheHomeIsEmptyAndACalendarIsNotFound(): void
    {
        $home = $this->propfind('/dav/calendars/u5/', '1');

        self::assertSame(207, $home->getStatus());
        self::assertStringNotContainsString($this->teamUri, self::body($home));
        self::assertSame(404, $this->propfind('/dav/calendars/u5/' . $this->teamUri . '/', '0')->getStatus());
    }

    public function testTheHomeListsExactlyTheGrantedCalendars(): void
    {
        $this->world->grant(5, 1, CalendarRole::Read);
        $home = self::body($this->propfind('/dav/calendars/u5/', '1', '<d:displayname/><cs:getctag/><d:sync-token/><c:supported-calendar-component-set/>'));

        self::assertStringContainsString($this->teamUri, $home);
        self::assertStringNotContainsString($this->otherUri, $home);
        self::assertStringContainsString('<cs:getctag>1</cs:getctag>', $home);
        self::assertStringContainsString('http://sabre.io/ns/sync/1', $home);
        self::assertStringContainsString('<cal:comp name="VEVENT"/>', $home);
        self::assertStringNotContainsString('VTODO', $home);
        self::assertSame(404, $this->propfind('/dav/calendars/u5/' . $this->otherUri . '/', '0')->getStatus());
    }

    // Roles.

    public function testReadRoleReadsButNeverWrites(): void
    {
        $this->world->grant(5, 1, CalendarRole::Read);
        $this->world->store->seed(1, 'one.ics', self::ics('one'));
        $base = '/dav/calendars/u5/' . $this->teamUri . '/';

        $get = $this->send('GET', $base . 'one.ics');
        self::assertSame(200, $get->getStatus());
        self::assertStringContainsString('Planning with client', self::body($get));
        self::assertNoStore($get);

        self::assertSame(403, $this->send('PUT', $base . 'two.ics', ['Content-Type' => 'text/calendar'], self::ics('two'))->getStatus());
        self::assertSame(403, $this->send('DELETE', $base . 'one.ics')->getStatus());
        self::assertCount(1, $this->world->store->objects[1]);
    }

    public function testWriteRoleCreatesChangesAndDeletesWithEtagPreconditions(): void
    {
        $this->world->grant(5, 1, CalendarRole::Write);
        $base = '/dav/calendars/u5/' . $this->teamUri . '/';

        $created = $this->send('PUT', $base . 'new.ics', ['Content-Type' => 'text/calendar', 'If-None-Match' => '*'], self::ics('new'));
        self::assertSame(201, $created->getStatus());
        $etag = (string) $created->getHeader('ETag');
        self::assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $etag);

        self::assertSame(412, $this->send('PUT', $base . 'new.ics', ['Content-Type' => 'text/calendar', 'If-None-Match' => '*'], self::ics('new'))->getStatus());
        self::assertSame(412, $this->send('PUT', $base . 'new.ics', ['Content-Type' => 'text/calendar', 'If-Match' => '"0000"'], self::ics('new', 'Changed'))->getStatus());

        $changed = $this->send('PUT', $base . 'new.ics', ['Content-Type' => 'text/calendar', 'If-Match' => $etag], self::ics('new', 'Changed'));
        self::assertSame(204, $changed->getStatus());
        self::assertStringContainsString('Changed', $this->world->store->objects[1]['new.ics']['ics']);

        $conflict = $this->send('PUT', $base . 'copy.ics', ['Content-Type' => 'text/calendar'], self::ics('new'));
        self::assertSame(403, $conflict->getStatus());
        self::assertStringContainsString('no-uid-conflict', self::body($conflict));
        self::assertStringNotContainsString('new.ics', self::body($conflict));

        $etag = (string) $changed->getHeader('ETag') ?: '"' . $this->world->store->objects[1]['new.ics']['etag'] . '"';
        self::assertSame(412, $this->send('PUT', $base . 'new.ics', ['Content-Type' => 'text/calendar', 'If-Match' => '"aaaa", "bbbb"'], self::ics('new', 'Lost'))->getStatus());
        self::assertSame(204, $this->send('PUT', $base . 'new.ics', ['Content-Type' => 'text/calendar', 'If-Match' => '"aaaa", ' . $etag], self::ics('new', 'Listed'))->getStatus());
        self::assertStringContainsString('Listed', $this->world->store->objects[1]['new.ics']['ics']);

        $otherUid = $this->send('PUT', $base . 'new.ics', ['Content-Type' => 'text/calendar'], self::ics('another-uid'));
        self::assertSame(403, $otherUid->getStatus(), 'An object keeps its UID.');
        self::assertStringContainsString('no-uid-conflict', self::body($otherUid));

        self::assertSame(412, $this->send('DELETE', $base . 'new.ics', ['If-Match' => '"0000"'])->getStatus());
        self::assertSame(204, $this->send('DELETE', $base . 'new.ics')->getStatus());
        self::assertSame([], $this->world->store->objects[1]);
    }

    public function testObjectRulesAreEnforced(): void
    {
        $this->world->grant(5, 1, CalendarRole::Write);
        $base = '/dav/calendars/u5/' . $this->teamUri . '/';

        $todo = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//EN\r\nBEGIN:VTODO\r\nUID:t1\r\nDTSTAMP:20261104T100000Z\r\nSUMMARY:x\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
        self::assertSame(403, $this->send('PUT', $base . 'todo.ics', ['Content-Type' => 'text/calendar'], $todo)->getStatus());
        self::assertSame(400, $this->send('PUT', $base . 'bad.ics', ['Content-Type' => 'text/calendar'], 'not a calendar')->getStatus());
        self::assertSame(400, $this->send('PUT', $base . 'bad%20name.ics', ['Content-Type' => 'text/calendar'], self::ics('n'))->getStatus());
        // sabre would repair the first calendar (no PRODID) and drop the rest; the original is checked first.
        $repairable = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:r1\r\nDTSTAMP:20261104T100000Z\r\nDTSTART:20261110T090000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        self::assertSame(400, $this->send('PUT', $base . 'r.ics', ['Content-Type' => 'text/calendar'], $repairable . self::ics('hidden'))->getStatus());
        self::assertSame([], $this->world->store->objects[1]);
    }

    public function testFreeBusyServesRedactedBlocksWithoutAKeyAndWithoutTheOriginalAddresses(): void
    {
        $this->world->grant(5, 1, CalendarRole::FreeBusy);
        $this->world->store->seed(1, 'secret-uid-123.ics', self::ics('secret-uid-123', 'Salary review with Eve'));
        $base = '/dav/calendars/u5/' . $this->teamUri . '/';

        $list = self::body($this->propfind($base, '1'));
        self::assertStringNotContainsString('secret-uid-123', $list);
        self::assertSame(1, preg_match('#' . preg_quote($base, '#') . '(fb-[0-9a-f]{40}\.ics)#', $list, $match));

        $get = self::body($this->send('GET', $base . $match[1]));
        self::assertStringContainsString('SUMMARY:Busy', $get);
        self::assertStringContainsString('CLASS:CONFIDENTIAL', $get);
        self::assertStringContainsString('DTSTART:20261110T090000Z', $get);
        self::assertStringNotContainsString('Salary', $get);
        self::assertStringNotContainsString('secret-uid-123', $get);

        $query = self::body($this->send('REPORT', $base, ['Depth' => '1', 'Content-Type' => 'application/xml'], '<?xml version="1.0"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"/></c:comp-filter></c:filter></c:calendar-query>'));
        self::assertStringNotContainsString('Salary', $query);

        self::assertSame(403, $this->send('PUT', $base . 'x.ics', ['Content-Type' => 'text/calendar'], self::ics('x'))->getStatus());
        self::assertSame(0, $this->world->keyringOpened);
    }

    public function testACalendarQueryDecryptsEachObjectOnce(): void
    {
        $this->world->grant(5, 1, CalendarRole::Read);
        $this->world->store->seed(1, 'a.ics', self::ics('a'));
        $this->world->store->seed(1, 'b.ics', self::ics('b'));

        $query = $this->send('REPORT', '/dav/calendars/u5/' . $this->teamUri . '/', ['Depth' => '1', 'Content-Type' => 'application/xml'], '<?xml version="1.0"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"><c:time-range start="20261101T000000Z" end="20261201T000000Z"/></c:comp-filter></c:comp-filter></c:filter></c:calendar-query>');

        self::assertSame(207, $query->getStatus());
        self::assertSame(2, substr_count(self::body($query), 'Planning with client'));
        self::assertSame(0, $this->world->store->gets, 'No object is read a second time.');
    }

    public function testOnlyTheOwnDisplayPropertiesCanBeChanged(): void
    {
        $this->world->grant(5, 1, CalendarRole::Read);
        $base = '/dav/calendars/u5/' . $this->teamUri . '/';
        $patch = static fn (string $set): string => '<?xml version="1.0"?><d:propertyupdate xmlns:d="DAV:" xmlns:a="http://apple.com/ns/ical/"><d:set><d:prop>' . $set . '</d:prop></d:set></d:propertyupdate>';

        $ok = $this->send('PROPPATCH', $base, ['Content-Type' => 'application/xml'], $patch('<a:calendar-color>#ff0000</a:calendar-color><a:calendar-order>3</a:calendar-order>'));
        self::assertSame(207, $ok->getStatus());
        self::assertStringContainsString('200 OK', self::body($ok));
        self::assertSame(['colour' => '#FF0000', 'order' => 3], $this->world->clientProps[1]);

        $refused = $this->send('PROPPATCH', $base, ['Content-Type' => 'application/xml'], $patch('<d:displayname>Mine</d:displayname>'));
        self::assertStringContainsString('403 Forbidden', self::body($refused));
        self::assertSame('Team', $this->world->calendars[1]['title']);

        $bad = $this->send('PROPPATCH', $base, ['Content-Type' => 'application/xml'], $patch('<a:calendar-color>red;drop</a:calendar-color>'));
        self::assertStringContainsString('403 Forbidden', self::body($bad));

        $home = self::body($this->propfind('/dav/calendars/u5/', '1', '<a:calendar-color xmlns:a="http://apple.com/ns/ical/"/>'));
        self::assertStringContainsString('#FF0000', $home);
    }

    public function testCalendarsCannotBeCreatedOrDeletedOverCalDav(): void
    {
        $this->world->grant(5, 1, CalendarRole::Manage);

        self::assertSame(403, $this->send('DELETE', '/dav/calendars/u5/' . $this->teamUri . '/')->getStatus());
        self::assertSame(403, $this->send('MKCALENDAR', '/dav/calendars/u5/new/')->getStatus());
        self::assertArrayHasKey(1, $this->world->calendars);
    }

    // Sync.

    public function testSyncCollectionIsIncrementalAndTheCtagFollowsTheToken(): void
    {
        $this->world->grant(5, 1, CalendarRole::Write);
        $this->world->store->seed(1, 'a.ics', self::ics('a'));
        $this->world->store->seed(1, 'b.ics', self::ics('b'));

        $first = self::body($this->sync($this->teamUri));
        self::assertStringContainsString('a.ics', $first);
        self::assertStringContainsString('b.ics', $first);
        self::assertStringContainsString('<d:sync-token>http://sabre.io/ns/sync/3</d:sync-token>', $first);

        $this->world->store->delete(1, 'a.ics', null, null);
        $this->world->store->seed(1, 'c.ics', self::ics('c'));

        $next = self::body($this->sync($this->teamUri, 'http://sabre.io/ns/sync/3'));
        self::assertStringContainsString('c.ics', $next);
        self::assertStringNotContainsString('b.ics', $next);
        self::assertMatchesRegularExpression('#a\.ics</d:href>\s*<d:status>HTTP/1\.1 404 Not Found</d:status>#', $next);
        self::assertStringContainsString('http://sabre.io/ns/sync/5', $next);

        // A sync-collection for ETags opens no key.
        self::assertSame(0, $this->world->keyringOpened);

        $page = self::body($this->send('REPORT', '/dav/calendars/u5/' . $this->teamUri . '/', ['Depth' => '1', 'Content-Type' => 'application/xml'], '<?xml version="1.0"?><d:sync-collection xmlns:d="DAV:"><d:sync-token>http://sabre.io/ns/sync/1</d:sync-token><d:sync-level>1</d:sync-level><d:limit><d:nresults>1</d:nresults></d:limit><d:prop><d:getetag/></d:prop></d:sync-collection>'));
        self::assertStringContainsString('http://sabre.io/ns/sync/2', $page);
        self::assertStringContainsString('507', $page, 'A limited page says that more changes follow.');

        $this->world->store->oldest[1] = 4;
        $expired = $this->sync($this->teamUri, 'http://sabre.io/ns/sync/2');
        self::assertSame(403, $expired->getStatus());
        self::assertStringContainsString('valid-sync-token', self::body($expired));

        self::assertSame(403, $this->sync($this->teamUri, 'urn:other:1')->getStatus());
    }

    public function testAListingWhoseTokenCannotBeReadIsNotDelivered(): void
    {
        $this->world->grant(5, 1, CalendarRole::Read);
        $this->world->store->seed(1, 'a.ics', self::ics('a'));
        $this->world->store->tokenFails = true;

        self::assertSame(503, $this->propfind('/dav/calendars/u5/' . $this->teamUri . '/', '1')->getStatus());
    }

    public function testNothingIsDeliveredWhenTheSynchronisationCannotBeRecorded(): void
    {
        $this->world->grant(5, 1, CalendarRole::Read);
        $this->world->store->seed(1, 'a.ics', self::ics('a'));
        $this->world->touchFails = true;

        $response = $this->propfind('/dav/calendars/u5/' . $this->teamUri . '/', '1');

        self::assertSame(503, $response->getStatus());
        self::assertSame('', self::body($response));
    }

    public function testARoleChangeForcesAFullResynchronisation(): void
    {
        $this->world->grant(5, 1, CalendarRole::Read);
        $this->world->store->seed(1, 'plain.ics', self::ics('plain', 'Board meeting'));

        $first = self::body($this->sync($this->teamUri));
        self::assertStringContainsString('http://sabre.io/ns/sync/2<', $first);

        // Downgraded to free/busy: CTag and token change, the old token is refused.
        $this->world->grant(5, 1, CalendarRole::FreeBusy);
        $home = self::body($this->propfind('/dav/calendars/u5/', '1', '<cs:getctag/>'));
        self::assertStringContainsString('<cs:getctag>fb2</cs:getctag>', $home);

        $old = $this->sync($this->teamUri, 'http://sabre.io/ns/sync/2');
        self::assertSame(403, $old->getStatus());
        self::assertStringContainsString('valid-sync-token', self::body($old));

        $fresh = self::body($this->sync($this->teamUri));
        self::assertStringContainsString('http://sabre.io/ns/sync/fb2', $fresh);
        self::assertStringNotContainsString('plain.ics', $fresh);
        self::assertSame(207, $this->sync($this->teamUri, 'http://sabre.io/ns/sync/fb2')->getStatus());

        // Back to read: the free/busy token is refused in turn.
        $this->world->grant(5, 1, CalendarRole::Read);
        self::assertSame(403, $this->sync($this->teamUri, 'http://sabre.io/ns/sync/fb2')->getStatus());
    }

    public function testASuccessfulRequestTouchesTheDeviceAndCalendar(): void
    {
        $this->world->grant(5, 1, CalendarRole::Read);
        $this->propfind('/dav/calendars/u5/' . $this->teamUri . '/', '1');
        $this->propfind('/dav/principals/u5/', '0');

        self::assertSame([1, null], $this->world->touched);
        self::assertSame([CalendarRole::Read->value, null], $this->world->touchedRoles);
        self::assertSame([1, null], $this->world->touchedTokens, 'The PROPFIND handed out the CTag of token 1.');
    }

    // Clean-up.

    public function testCleanupEmptyServesTheKnownCalendarsWithoutObjectsAndReportsEverythingDeleted(): void
    {
        $this->world->grant(5, 1, CalendarRole::Write);
        $this->world->store->seed(1, 'a.ics', self::ics('a'));
        $password = $this->world->device(7, 'carol', TeamStates::DEVICE_CLEANUP_EMPTY, false);
        $this->world->grant(7, 1, CalendarRole::Read);
        $this->world->inCleanup = [1 => ['role' => CalendarRole::Read->value, 'synctoken' => 2]];
        $base = '/dav/calendars/u7/' . $this->teamUri . '/';

        $home = self::body($this->send('PROPFIND', '/dav/calendars/u7/', ['Depth' => '1'], '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/"><d:prop><cs:getctag/></d:prop></d:propfind>', $password, 'carol'));
        self::assertStringContainsString('<cs:getctag>2-c</cs:getctag>', $home);

        $list = $this->send('PROPFIND', $base, ['Depth' => '1'], '', $password, 'carol');
        self::assertSame(207, $list->getStatus());
        self::assertStringNotContainsString('a.ics', self::body($list));
        self::assertSame([1], $this->world->delivered);

        $sync = self::body($this->send('REPORT', $base, ['Depth' => '1', 'Content-Type' => 'application/xml'], '<?xml version="1.0"?><d:sync-collection xmlns:d="DAV:"><d:sync-token>http://sabre.io/ns/sync/2</d:sync-token><d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>', $password, 'carol'));
        self::assertMatchesRegularExpression('#a\.ics</d:href>\s*<d:status>HTTP/1\.1 404 Not Found</d:status>#', $sync);
        self::assertStringContainsString('http://sabre.io/ns/sync/2-c', $sync);

        // A client behind the recorded token still gets the deletions since its own token.
        $this->world->store->seed(1, 'gone.ics', self::ics('gone'));
        $this->world->store->delete(1, 'gone.ics', null, null);
        $this->world->inCleanup = [1 => ['role' => CalendarRole::Read->value, 'synctoken' => 4]];
        $behind = self::body($this->send('REPORT', $base, ['Depth' => '1', 'Content-Type' => 'application/xml'], '<?xml version="1.0"?><d:sync-collection xmlns:d="DAV:"><d:sync-token>http://sabre.io/ns/sync/2</d:sync-token><d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>', $password, 'carol'));
        self::assertStringNotContainsString('gone.ics', $behind, 'Added and deleted after its token: the client never had it.');
        self::assertStringContainsString('a.ics', $behind);

        $this->world->store->seed(1, 'kept.ics', self::ics('kept'));
        $this->world->store->delete(1, 'kept.ics', null, null);
        $this->world->inCleanup = [1 => ['role' => CalendarRole::Read->value, 'synctoken' => 6]];
        $tombstone = self::body($this->send('REPORT', $base, ['Depth' => '1', 'Content-Type' => 'application/xml'], '<?xml version="1.0"?><d:sync-collection xmlns:d="DAV:"><d:sync-token>http://sabre.io/ns/sync/5</d:sync-token><d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>', $password, 'carol'));
        self::assertMatchesRegularExpression('#kept\.ics</d:href>\s*<d:status>HTTP/1\.1 404 Not Found</d:status>#', $tombstone, 'Deleted after the token of the client but before the recorded one.');
        $this->world->inCleanup = [1 => ['role' => CalendarRole::Read->value, 'synctoken' => 2]];

        // Created after the clean-up started: a made-up newer token reveals nothing about it.
        $this->world->store->seed(1, 'after-cleanup.ics', self::ics('after-cleanup'));
        $guess = self::body($this->send('REPORT', $base, ['Depth' => '1', 'Content-Type' => 'application/xml'], '<?xml version="1.0"?><d:sync-collection xmlns:d="DAV:"><d:sync-token>http://sabre.io/ns/sync/3</d:sync-token><d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>', $password, 'carol'));
        self::assertStringContainsString('a.ics', $guess);
        self::assertStringNotContainsString('after-cleanup', $guess);

        self::assertSame(403, $this->send('PUT', $base . 'n.ics', ['Content-Type' => 'text/calendar'], self::ics('n'), $password, 'carol')->getStatus());

        // A filtered query says nothing about deleting everything: no delivery mark.
        $this->world->delivered = [];
        $this->send('REPORT', $base, ['Depth' => '1', 'Content-Type' => 'application/xml'], '<?xml version="1.0"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/></d:prop><c:filter><c:comp-filter name="VCALENDAR"/></c:filter></c:calendar-query>', $password, 'carol');
        self::assertSame([], $this->world->delivered);
        self::assertSame(404, $this->send('GET', $base . 'a.ics', [], '', $password, 'carol')->getStatus());
        self::assertSame(0, $this->world->keyringOpened);
    }

    public function testCleanupWithoutAKnownRoleReportsOnlyOpaqueAddresses(): void
    {
        $this->world->grant(5, 1, CalendarRole::Write);
        $this->world->store->seed(1, 'uid-of-a-meeting.ics', self::ics('uid-of-a-meeting'));
        $password = $this->world->device(9, 'erin', TeamStates::DEVICE_CLEANUP_EMPTY, false);
        $this->world->inCleanup = [1 => ['role' => 0, 'synctoken' => 2]];
        $this->world->store->seed(1, 'later.ics', self::ics('later'));

        $sync = self::body($this->send('REPORT', '/dav/calendars/u9/' . $this->teamUri . '/', ['Depth' => '1', 'Content-Type' => 'application/xml'], '<?xml version="1.0"?><d:sync-collection xmlns:d="DAV:"><d:sync-token>http://sabre.io/ns/sync/2</d:sync-token><d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>', $password, 'erin'));

        self::assertStringNotContainsString('uid-of-a-meeting', $sync);
        self::assertSame(1, preg_match_all('#/fb-[0-9a-f]{40}\.ics</d:href>#', $sync), 'Only the object that existed at the token, never the later one.');
    }

    public function testCleanupHiddenListsNothingAndMarksTheHomeSeen(): void
    {
        $password = $this->world->device(8, 'dave', TeamStates::DEVICE_CLEANUP_HIDDEN, false);
        $this->world->grant(8, 1, CalendarRole::Read);

        $home = $this->send('PROPFIND', '/dav/calendars/u8/', ['Depth' => '1'], '', $password, 'dave');
        self::assertSame(207, $home->getStatus());
        self::assertStringNotContainsString($this->teamUri, self::body($home));
        self::assertSame(1, $this->world->homeSeen);
        self::assertSame(404, $this->send('PROPFIND', '/dav/calendars/u8/' . $this->teamUri . '/', ['Depth' => '0'], '', $password, 'dave')->getStatus());
    }

    public function testAViewOfEmptyForOneCalendarOfAnActiveDevice(): void
    {
        $this->world->grant(5, 1, CalendarRole::Write);
        $this->world->store->seed(1, 'a.ics', self::ics('a'));
        $this->world->views[1] = CleanupServiceInterface::VIEW_EMPTY;

        $list = self::body($this->propfind('/dav/calendars/u5/' . $this->teamUri . '/', '1'));
        self::assertStringNotContainsString('a.ics', $list);
        self::assertSame(403, $this->send('PUT', '/dav/calendars/u5/' . $this->teamUri . '/b.ics', ['Content-Type' => 'text/calendar'], self::ics('b'))->getStatus());
    }
}
