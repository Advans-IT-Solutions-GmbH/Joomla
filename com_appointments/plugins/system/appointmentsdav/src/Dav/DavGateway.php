<?php
/**
 * @package     Appointments CalDAV Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\AppointmentsDav\Dav;

use Advans\Component\Appointments\Administrator\Helper\Team\AppPassword;
use Advans\Component\Appointments\Administrator\Helper\Team\AuditEvent;
use Advans\Component\Appointments\Administrator\Helper\Team\DeviceSession;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Advans\Component\Appointments\Vendor\Sabre\DAV\Exception as DavException;
use Advans\Component\Appointments\Vendor\Sabre\DAV\Server;
use Advans\Component\Appointments\Vendor\Sabre\HTTP\RequestInterface;
use Advans\Component\Appointments\Vendor\Sabre\HTTP\Response;
use Advans\Component\Appointments\Vendor\Sabre\HTTP\ResponseInterface;

defined('_JEXEC') or die;

/**
 * One CalDAV request from the first byte to the response, without Joomla:
 *
 * 1. Plain HTTP: 403 without a body, no redirect.
 * 2. `/.well-known/caldav`: 301 to the DAV base address, for every method.
 * 3. A body above 1 MiB: 413.
 * 4. HTTP Basic with an app password. Every refusal of the credentials is the
 *    same 401; the lock-out is asked first (429 with Retry-After, also for a
 *    correct password), and every check of a secret ends in exactly one
 *    recordFailure() or recordSuccess(), in a bucket of its own per user name
 *    and address ({@see bucket()}). A Joomla password is refused before
 *    any lookup, because it is not an app password.
 * 5. An active device whose account has no second factor: 403.
 * 6. Only OPTIONS, GET, HEAD, PROPFIND, PROPPATCH, REPORT, PUT and DELETE reach
 *    sabre/dav; MKCALENDAR, MKCOL, ACL, POST (sharing), COPY, MOVE, LOCK and
 *    everything else get 403. XML bodies other than plain UTF-8, or with a
 *    DOCTYPE or an entity: 400.
 * 7. The sabre/dav server ({@see DavServerFactory}); afterwards the throttled
 *    «last synchronised» and the clean-up delivery marks.
 *
 * Every response carries `Cache-Control: private, no-store` and
 * `X-Robots-Tag: noindex, nofollow`. An error never shows an internal message.
 *
 * @since  1.4.0
 */
final class DavGateway
{
    /** Largest request body and calendar object. */
    public const MAX_BODY = 1048576;

    private const METHODS = ['OPTIONS', 'GET', 'HEAD', 'PROPFIND', 'PROPPATCH', 'REPORT', 'PUT', 'DELETE'];

    private const XML_METHODS = ['PROPFIND', 'PROPPATCH', 'REPORT'];

    public function __construct(
        private readonly DavServices $services,
        private readonly DavEnvironment $env
    ) {
    }

    public function handle(RequestInterface $request): ResponseInterface
    {
        try {
            $response = $this->process($request);
        } catch (TeamException $e) {
            // A service refused outside of sabre (for example a part that is not delivered yet).
            $this->report($e);
            $response = new Response(503);
        } catch (\Throwable $e) {
            $this->report($e);
            $response = new Response(500);
        }

        return $this->finish($response, $request);
    }

    private function process(RequestInterface $request): ResponseInterface
    {
        $kind = $this->env->address->classify((string) parse_url($request->getUrl(), PHP_URL_PATH));

        if ($kind === null) {
            return new Response(404);
        }

        if (!$this->env->https) {
            return new Response(403);
        }

        if ($kind === DavAddress::KIND_WELL_KNOWN) {
            // A path on the same host, so the answer never repeats a Host header; another host as configured.
            $sameHost = strcasecmp((string) $request->getHeader('Host'), $this->env->address->host) === 0;

            return new Response(301, ['Location' => $sameHost ? $this->env->address->path : $this->env->address->url]);
        }

        $length = $request->getHeader('Content-Length');

        if ($length !== null && (preg_match('/^\d{1,12}$/', trim($length)) !== 1 || (int) $length > self::MAX_BODY)) {
            return new Response(preg_match('/^\d{1,12}$/', trim($length)) === 1 ? 413 : 400);
        }

        $credentials = BasicCredentials::parse($request->getHeader('Authorization'));

        if ($credentials === null) {
            return new Response(401);
        }

        $session = $this->authenticate($credentials);

        if (!$session instanceof DeviceSession) {
            return $session;
        }

        $method = strtoupper($request->getMethod());

        if (!\in_array($method, self::METHODS, true)) {
            return new Response(403);
        }

        $body = $this->readBody($request);

        if ($body === null) {
            return new Response(413);
        }

        if (\in_array($method, self::XML_METHODS, true) && !self::plainXml($body)) {
            return new Response(400);
        }

        $request->setBody($body);
        $context = new DavContext($this->services, $session, $this->env->busySummary, $this->env->onError);
        $context->setRequest($request);

        try {
            $server = DavServerFactory::create($context, $this->env->address->path);
            $response = $this->run($server, $request);

            if ($response->getStatus() < 400) {
                $this->afterSuccess($context, $request);
            }
        } finally {
            $context->wipe();
        }

        return $response;
    }

    /**
     * @return  DeviceSession|ResponseInterface  The session, or the refusal to send.
     */
    private function authenticate(BasicCredentials $credentials): DeviceSession|ResponseInterface
    {
        $lockout = $this->services->lockout;
        $bucket = self::bucket($credentials->username, $this->env->ip);
        $retryAfter = $lockout->retryAfter($bucket, $this->env->ip);

        if ($retryAfter > 0) {
            $this->audit(AuditEvent::DAV_LOCKED, ['ip' => $this->env->ip]);

            return new Response(429, ['Retry-After' => (string) $retryAfter]);
        }

        // Anything that is not an app password (a Joomla password) is refused before any lookup.
        $wellFormed = AppPassword::parse($credentials->password) !== null;
        $session = null;

        if ($wellFormed) {
            try {
                $session = $this->services->devices->authenticate($credentials->username, $credentials->password);
            } catch (\Throwable $e) {
                // A failing lookup counts and answers like any other refusal.
                $this->report($e);
            }
        }

        if ($session === null) {
            $lockout->recordFailure($bucket, $this->env->ip);
            $this->audit(AuditEvent::DAV_AUTH_FAILURE, ['ip' => $this->env->ip, 'details' => ['reason' => $wellFormed ? 'invalid' : 'malformed']]);

            return new Response(401);
        }

        $lockout->recordSuccess($bucket, $this->env->ip);

        if ($session->isActive() && !$this->services->grants->hasSecondFactor($session->userId)) {
            $this->audit(AuditEvent::DAV_AUTH_FAILURE, [
                'actor_user_id'   => $session->userId,
                'actor_device_id' => $session->deviceId,
                'ip'              => $this->env->ip,
                'details'         => ['reason' => 'no_mfa'],
            ]);

            return new Response(403);
        }

        return $session;
    }

    /**
     * The user-name bucket of the lock-out for CalDAV. It is bound to the
     * address and kept apart from the website sign-in: app passwords have 160
     * random bits and cannot be guessed, so the lock only has to stop one
     * client, and nobody can lock another person's devices or website sign-in
     * by sending their user name with wrong passwords.
     */
    public static function bucket(string $username, string $ip): string
    {
        return 'dav:' . mb_strtolower($username, 'UTF-8') . '@' . $ip;
    }

    /**
     * An XML body sabre may parse: UTF-8 without NUL and without a document
     * type declaration or entity, so no other encoding hides one from the check.
     */
    public static function plainXml(string $body): bool
    {
        return mb_check_encoding($body, 'UTF-8')
            && !str_contains($body, "\0")
            && preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $body) !== 1;
    }

    private function run(Server $server, RequestInterface $request): ResponseInterface
    {
        $response = new Response();
        $server->httpRequest = $request;
        $server->httpResponse = $response;
        $request->setBaseUrl($server->getBaseUri());

        try {
            $server->invokeMethod($request, $response, false);
        } catch (DavException $e) {
            $dom = new \DOMDocument('1.0', 'utf-8');
            $error = $dom->createElementNS('DAV:', 'd:error');
            $dom->appendChild($error);
            $e->serialize($server, $error);

            $headers = $e->getHTTPHeaders($server);
            $headers['Content-Type'] = 'application/xml; charset=utf-8';

            return new Response($e->getHTTPCode(), $headers, $dom->saveXML());
        }

        return $response;
    }

    /**
     * The throttled «last synchronised» of the device and calendar, and the
     * clean-up marks: the empty view of a calendar was delivered, or the device
     * listed its home while calendars are hidden.
     */
    private function afterSuccess(DavContext $context, RequestInterface $request): void
    {
        $session = $context->session;
        $segments = array_values(array_filter(explode('/', trim($request->getPath(), '/')), 'strlen'));
        $inHome = \count($segments) >= 2 && $segments[0] === 'calendars' && $segments[1] === $session->principal();
        $calendar = $inHome && isset($segments[2]) ? $context->calendarByUri($segments[2]) : null;
        $method = strtoupper($request->getMethod());
        $listing = ($method === 'PROPFIND' && trim((string) $request->getHeader('Depth')) !== '0') || $method === 'REPORT';

        $context->quietly(fn () => $this->services->devices->touch($session, $calendar?->id));

        if ($listing) {
            foreach ($context->emptyServed() as $calendarId) {
                $context->quietly(fn () => $this->services->cleanup->markDelivered($session, $calendarId));
            }
        }

        if ($method === 'PROPFIND' && $listing && $inHome && \count($segments) === 2 && ($context->homeHidden() || $session->state === TeamStates::DEVICE_CLEANUP_HIDDEN)) {
            $context->quietly(fn () => $this->services->cleanup->markHomeSeen($session));
        }
    }

    /**
     * @return  string|null  Null when the body exceeds the limit.
     */
    private function readBody(RequestInterface $request): ?string
    {
        $body = $request->getBody();

        if (\is_resource($body)) {
            $body = stream_get_contents($body, self::MAX_BODY + 1);
        }

        $body = \is_string($body) ? $body : '';

        return \strlen($body) > self::MAX_BODY ? null : $body;
    }

    private function finish(ResponseInterface $response, RequestInterface $request): ResponseInterface
    {
        foreach (array_keys($response->getHeaders()) as $name) {
            if (stripos((string) $name, 'X-Sabre-') === 0) {
                $response->removeHeader((string) $name);
            }
        }

        $response->setHeader('Cache-Control', 'private, no-store');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('X-Robots-Tag', 'noindex, nofollow');
        $response->setHeader('X-Content-Type-Options', 'nosniff');

        if ($response->getStatus() === 401) {
            $realm = trim((string) preg_replace('/["\\\\\x00-\x1f\x7f]/', '', $this->env->realm));
            $response->setHeader('WWW-Authenticate', 'Basic realm="' . ($realm === '' ? 'CalDAV' : $realm) . '", charset="UTF-8"');

            // For the system check: the web server passed the header on to PHP (no value, no verdict).
            if ($request->getHeader('Authorization') !== null) {
                $response->setHeader('X-Appointments-Authorization', 'received');
            }
        }

        return $response;
    }

    /**
     * @param   array<string, mixed>  $context
     */
    private function audit(string $event, array $context): void
    {
        try {
            $this->services->audit->log($event, $context);
        } catch (\Throwable $e) {
            $this->report($e);
        }
    }

    private function report(\Throwable $e): void
    {
        if ($this->env->onError !== null) {
            try {
                ($this->env->onError)($e);
            } catch (\Throwable $ignored) {
                // Reporting must never change the answer.
            }
        }
    }
}
