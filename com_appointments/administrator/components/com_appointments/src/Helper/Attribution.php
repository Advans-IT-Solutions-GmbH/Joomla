<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * The origin of a booking (campaign attribution): how the visitor reached the site and the
 * booking page, kept in the Joomla session while they browse and stored with the booking.
 *
 * The session state holds the first landing (time, path and query, address), the number of
 * page views, the last page and two touches. A touch is a page view that carries a campaign
 * parameter of {@see self::PARAMETERS} or arrives from another host: the first touch is kept,
 * the last touch is replaced only by a touch with other parameters or by an arrival from another
 * external host, never by internal navigation (also with the same parameters), a direct visit or a
 * reload of the same touch. A touch keeps a referrer host only when it came from another site.
 *
 * The record of a booking ({@see self::record()}) adds what the submit itself tells: the
 * booking page, the address, Accept-Language and the User-Agent with the device class, browser
 * family and operating system derived from it. It is stored as JSON in the encrypted column
 * `attribution` of the booking ({@see BookingData::COLUMNS}) and erased with the other personal
 * fields ({@see Anonymiser::ERASED_FIELDS}).
 *
 * Every value is text without control or format characters, valid UTF-8, at most
 * {@see self::MAX_LENGTH} characters. Parameter names come from a fixed list, URLs lose their
 * fragment, a user name and password before the host, and every query parameter that may carry a
 * credential by its name (a token, a signature, a code, a Joomla form token) or by its value (a
 * JSON Web Token or another opaque token, {@see self::tokenLike()}). Output is plain text: pages
 * and mails escape it.
 *
 * Pure: no database and no Joomla runtime, so it is tested on its own.
 *
 * @since  1.6.0
 */
final class Attribution
{
    public const VERSION = 1;

    /** The longest stored value, in characters. */
    public const MAX_LENGTH = 2048;

    /** What the statistics keep instead of a value that looks like an e-mail address or a telephone number. */
    public const REDACTED = '(redacted)';

    /** The longest campaign value of the non-personal aggregate, in characters. */
    public const AGGREGATE_LENGTH = 100;

    /** Where the state lives in the Joomla session. */
    public const SESSION_KEY = 'com_appointments.attribution';

    public const UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id'];

    public const CLICK_IDS = ['gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'li_fat_id', 'ttclid', 'twclid', 'mc_cid', 'mc_eid'];

    /** Every query parameter that is kept, in this order. */
    public const PARAMETERS = [...self::UTM, ...self::CLICK_IDS];

    private const MAX_VIEWS = 1000000;

    /**
     * The budget of a whole record as JSON, in bytes: {@see self::record()} keeps every record
     * within it ({@see self::fit()}) and {@see self::decode()} reads nothing larger. About 50 text
     * values of {@see self::FIT_LENGTH} characters of up to four bytes each fit.
     */
    public const MAX_RECORD_BYTES = 65536;

    /** The length every text value of a record over budget is cut to, in characters. */
    public const FIT_LENGTH = 256;

    /** Query parameters that are never stored with a URL. */
    private const SECRET = '/token|signature|secret|passw|pwd|key|auth|session|sid|code|nonce|hash|otp|ticket|mail|jwt|bearer|credential|activat|invit|reset|verif|confirm|magic|\Asig\z|_sig\z/i';

    /** The shortest query value that is checked for a token by its value ({@see self::tokenLike()}). */
    private const TOKEN_LENGTH = 32;

    /** The shortest run between separators that makes such a value a token when it mixes letters and digits. */
    private const TOKEN_RUN = 12;

    private const TOUCH_FIELDS =['time_utc', 'landing_url', 'referrer', 'referrer_host'];

    /**
     * Text without control or format characters (also bidirectional overrides), valid UTF-8,
     * trimmed and cut to $max characters; anything that is no text is ''.
     */
    public static function clean(mixed $value, int $max = self::MAX_LENGTH): string
    {
        if (!\is_string($value) && !\is_int($value)) {
            return '';
        }

        $value = (string) $value;

        if (preg_match('//u', $value) !== 1) {
            return '';
        }

        $value = trim((string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', '', $value));

        return mb_substr($value, 0, $max);
    }

    /**
     * The campaign parameters of a query, by their names in lower case, in the order of
     * {@see self::PARAMETERS}. Empty values and values that are no text are left out.
     *
     * @param   array<mixed>  $query
     *
     * @return  array<string, string>
     */
    public static function parameters(array $query): array
    {
        $found = [];

        foreach ($query as $name => $value) {
            $name = strtolower((string) $name);

            if (\in_array($name, self::PARAMETERS, true) && !isset($found[$name])) {
                $value = self::clean($value);

                if ($value !== '') {
                    $found[$name] = $value;
                }
            }
        }

        $ordered = [];

        foreach (self::PARAMETERS as $name) {
            if (isset($found[$name])) {
                $ordered[$name] = $found[$name];
            }
        }

        return $ordered;
    }

    /**
     * A URL as stored: without fragment, without user name and password before the host, and
     * without any query parameter that may carry a credential (by its name, or by a value that
     * looks like a token), otherwise as it was sent. `$pathOnly` keeps path and query only (a URL
     * of the site itself).
     */
    public static function url(mixed $url, bool $pathOnly = false): string
    {
        $url = self::clean($url, 8192);

        if ($url === '') {
            return '';
        }

        $url = explode('#', $url, 2)[0];
        $parts = explode('?', $url, 2);
        // A user name and password before the host («https://user:secret@host/», also scheme-relative
        // «//user:secret@host/») are never kept, up to the last «@» of the authority, so an
        // unencoded «@» in the password goes too.
        $base = preg_replace('#\A((?:[a-z][a-z0-9+.-]*:)?//)[^/?]*@#i', '$1', $parts[0]) ?? '';

        if ($pathOnly && preg_match('#\A(?:[a-z][a-z0-9+.-]*:)?//[^/?]*#i', $base, $origin) === 1) {
            $base = substr($base, \strlen($origin[0]));
            $base = $base === '' ? '/' : $base;
        }

        $kept = [];

        foreach (explode('&', $parts[1] ?? '') as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_map(static fn (string $part): string => rawurldecode(str_replace('+', ' ', $part)), explode('=', $pair, 2) + [1 => '']);

            if (preg_match(self::SECRET, $name) === 1 || preg_match('/\A[a-f0-9]{32}\z/i', $name) === 1
                || (!\in_array(strtolower($name), self::PARAMETERS, true) && self::tokenLike($value))) {
                continue;
            }

            $kept[] = $pair;
        }

        return self::clean($base . ($kept === [] ? '' : '?' . implode('&', $kept)));
    }

    /**
     * Whether a query value looks like a credential whatever its parameter is called: a JSON Web
     * Token, or an opaque string of at least {@see self::TOKEN_LENGTH} characters without spaces
     * (a reset or invitation token, a signed link, a session ID, a UUID) in which a run of at
     * least {@see self::TOKEN_RUN} characters between the separators «-», «_», «.» and «~» mixes
     * letters and digits. Readable values such as «spring-2026-linkedin-retargeting-switzerland»
     * keep letters and digits in separate words and stay. The campaign parameters and click IDs
     * (self::PARAMETERS) are not checked: a click ID is such a string by design.
     */
    private static function tokenLike(string $value): bool
    {
        if (preg_match('/\AeyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', $value) === 1) {
            return true;
        }

        if (\strlen($value) < self::TOKEN_LENGTH || preg_match('#\A[A-Za-z0-9_.~+/=-]+\z#', $value) !== 1) {
            return false;
        }

        foreach (preg_split('/[-_.~]+/', $value) ?: [] as $run) {
            if (\strlen($run) >= self::TOKEN_RUN && preg_match('/[A-Za-z]/', $run) === 1 && preg_match('/[0-9]/', $run) === 1) {
                return true;
            }
        }

        return false;
    }

    /** The host of a URL in lower case, '' for none. */
    public static function host(mixed $url): string
    {
        $host = parse_url(self::clean($url), PHP_URL_HOST);

        return \is_string($host) ? self::clean(strtolower($host), 255) : '';
    }

    /** Whether $referrer is on another host than the site ($siteHost); «www.» is the same site. */
    public static function external(string $referrer, string $siteHost): bool
    {
        $host = self::host($referrer);
        $site = strtolower(trim($siteHost));
        $strip = static fn (string $name): string => str_starts_with($name, 'www.') ? substr($name, 4) : $name;

        return $host !== '' && $strip($host) !== $strip((string) preg_replace('/:\d+\z/', '', $site));
    }

    /**
     * The session state after one page view.
     *
     * @param   array<string, mixed>|null  $state    The state so far, null at the first page view.
     * @param   array<string, mixed>       $request  `uri` (path and query), `referrer` (the Referer header),
     *                                               `host` (the site's host), `query` (the query parameters)
     *                                               and `ip` (the visitor's address).
     *
     * @return  array<string, mixed>
     */
    public static function visit(?array $state, array $request, \DateTimeImmutable $now): array
    {
        $time = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $uri = self::url($request['uri'] ?? '', true);
        $state = self::state($state);

        if ($state['first_seen_utc'] === '') {
            $state['first_seen_utc'] = $time;
            $state['landing_url'] = $uri;
            $state['landing_ip'] = self::ip($request['ip'] ?? '');
        }

        $state['views'] = min(self::MAX_VIEWS, $state['views'] + 1);
        $state['page_url'] = $uri;
        $referrer = \is_string($request['referrer'] ?? null) ? $request['referrer'] : '';
        $parameters = self::parameters(\is_array($request['query'] ?? null) ? $request['query'] : []);
        $external = self::external($referrer, \is_string($request['host'] ?? null) ? $request['host'] : '');

        if ($parameters === [] && !$external) {
            return $state;
        }

        // The referrer host of a touch is another site's only: a campaign link inside the site keeps none.
        $touch = ['time_utc' => $time, 'landing_url' => $uri, 'referrer' => self::url($referrer),
            'referrer_host' => $external ? self::host($referrer) : '', 'parameters' => $parameters];
        $state['first_touch'] ??= $touch;
        $last = $state['last_touch'];

        // A reload of the same arrival and an internal link that carries the same parameters keep it;
        // other parameters, or an arrival from another external host, replace it.
        if ($last === null || $last['parameters'] !== $touch['parameters'] || ($external && $last['referrer_host'] !== $touch['referrer_host'])) {
            $state['last_touch'] = $touch;
        }

        return $state;
    }

    /**
     * The record stored with a booking: the session state (null without one) and the submit.
     *
     * @param   array<string, mixed>  $submit  `user_agent`, `accept_language`, `ip`, `referrer` and `host` of the submit.
     *
     * @return  array<string, mixed>
     */
    public static function record(?array $state, array $submit, \DateTimeImmutable $now): array
    {
        $state = self::state($state);
        $referrer = \is_string($submit['referrer'] ?? null) ? $submit['referrer'] : '';
        // The page the form was sent from (its Referer on the site itself), else the last page of the
        // session: another tab may have opened a page after the booking form.
        $page = $referrer !== '' && self::host($referrer) !== '' && !self::external($referrer, \is_string($submit['host'] ?? null) ? $submit['host'] : '')
            ? self::url($referrer, true) : $state['page_url'];

        $agent = self::clean($submit['user_agent'] ?? '');

        return self::fit([
            'version' => self::VERSION,
            'first_seen_utc' => $state['first_seen_utc'],
            'landing_url' => $state['landing_url'],
            'landing_ip' => $state['landing_ip'],
            'first_touch' => $state['first_touch'],
            'last_touch' => $state['last_touch'],
            'page_views' => $state['views'],
            'booking_page_url' => $page,
            'booking_ip' => self::ip($submit['ip'] ?? ''),
            'accept_language' => self::clean($submit['accept_language'] ?? '', 512),
            'user_agent' => $agent,
        ] + self::client($agent));
    }

    /**
     * The record within {@see self::MAX_RECORD_BYTES} of JSON: an ordinary record is unchanged;
     * a larger one (only crafted values reach that) keeps the first {@see self::FIT_LENGTH}
     * characters of each text, so it always fits. Readers of many bookings then never hold
     * more than this per row.
     *
     * @param   array<string, mixed>  $record
     *
     * @return  array<string, mixed>
     */
    private static function fit(array $record): array
    {
        if (\strlen(self::encode($record)) <= self::MAX_RECORD_BYTES) {
            return $record;
        }

        array_walk_recursive($record, static function (mixed &$value): void {
            if (\is_string($value)) {
                $value = self::clean($value, self::FIT_LENGTH);
            }
        });

        return $record;
    }

    /**
     * Device class (desktop, mobile, tablet, bot, unknown), browser family and operating system
     * of a User-Agent. A heuristic: an iPad that presents itself as a Mac counts as a desktop.
     *
     * @return  array{device: string, browser: string, os: string}
     */
    public static function client(mixed $agent): array
    {
        $agent = self::clean($agent);

        if ($agent === '') {
            return ['device' => 'unknown', 'browser' => '', 'os' => ''];
        }

        $match = static fn (string $pattern): bool => preg_match($pattern, $agent) === 1;

        $device = match (true) {
            $match('/bot\b|crawl|spider|slurp|headless|lighthouse|preview/i') => 'bot',
            $match('/iPad|Tablet|Kindle|Silk|PlayBook/i'), $match('/Android/i') && !$match('/Mobile/i') => 'tablet',
            $match('/Mobi|iPhone|iPod|Windows Phone|BlackBerry|Opera Mini/i') => 'mobile',
            default => 'desktop',
        };

        $browser = match (true) {
            $device === 'bot' => 'Other',
            $match('/Edg(e|A|iOS)?\//') => 'Edge',
            $match('/OPR\/|Opera/') => 'Opera',
            $match('/SamsungBrowser\//') => 'Samsung Internet',
            $match('/Firefox\/|FxiOS\//') => 'Firefox',
            $match('/Chrome\/|CriOS\//') && !$match('/Chromium\//') => 'Chrome',
            $match('/Chromium\//') => 'Chromium',
            $match('/Safari\//') && $match('/Version\//') => 'Safari',
            $match('/MSIE |Trident\//') => 'Internet Explorer',
            default => 'Other',
        };

        $os = match (true) {
            $device === 'bot' => 'Other',
            $match('/Windows NT|Windows Phone/') => 'Windows',
            $match('/iPhone|iPod/') => 'iOS',
            $match('/iPad/') => 'iPadOS',
            $match('/CrOS/') => 'ChromeOS',
            $match('/Android/') => 'Android',
            $match('/Mac OS X|Macintosh/') => 'macOS',
            $match('/Linux|X11/') => 'Linux',
            default => 'Other',
        };

        return ['device' => $device, 'browser' => $browser, 'os' => $os];
    }

    /** @param  array<string, mixed>  $record */
    public static function encode(array $record): string
    {
        return json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * A stored record, cleaned again on reading, or null for anything that is not a record of
     * this version.
     *
     * @return  array<string, mixed>|null
     */
    public static function decode(mixed $json): ?array
    {
        if (!\is_string($json) || $json === '' || \strlen($json) > self::MAX_RECORD_BYTES) {
            return null;
        }

        try {
            $record = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($record) || ($record['version'] ?? null) !== self::VERSION) {
            return null;
        }

        $state = self::state(['first_seen_utc' => $record['first_seen_utc'] ?? '', 'landing_url' => $record['landing_url'] ?? '',
            'landing_ip' => $record['landing_ip'] ?? '', 'views' => $record['page_views'] ?? 0, 'page_url' => $record['booking_page_url'] ?? '',
            'first_touch' => $record['first_touch'] ?? null, 'last_touch' => $record['last_touch'] ?? null]);
        $agent = self::clean($record['user_agent'] ?? '');

        return [
            'version' => self::VERSION,
            'first_seen_utc' => $state['first_seen_utc'],
            'landing_url' => $state['landing_url'],
            'landing_ip' => $state['landing_ip'],
            'first_touch' => $state['first_touch'],
            'last_touch' => $state['last_touch'],
            'page_views' => $state['views'],
            'booking_page_url' => $state['page_url'],
            'booking_ip' => self::ip($record['booking_ip'] ?? ''),
            'accept_language' => self::clean($record['accept_language'] ?? '', 512),
            'user_agent' => $agent,
            'device' => self::clean($record['device'] ?? '', 32),
            'browser' => self::clean($record['browser'] ?? '', 32),
            'os' => self::clean($record['os'] ?? '', 32),
        ];
    }

    /**
     * The flat columns of a record, each field its own column (CSV export, privacy export).
     *
     * @return  list<string>
     */
    public static function columns(): array
    {
        $columns = ['first_seen_utc', 'landing_url', 'landing_ip', 'page_views', 'booking_page_url', 'booking_ip',
            'accept_language', 'device', 'browser', 'os', 'user_agent'];

        foreach (['first', 'last'] as $touch) {
            foreach ([...self::TOUCH_FIELDS, ...self::PARAMETERS] as $field) {
                $columns[] = $touch . '_' . $field;
            }
        }

        return $columns;
    }

    /**
     * The record as one value per column of {@see self::columns()}, '' where nothing is known.
     *
     * @param   array<string, mixed>|null  $record
     *
     * @return  array<string, string>
     */
    public static function flatten(?array $record): array
    {
        $flat = array_fill_keys(self::columns(), '');

        if ($record === null) {
            return $flat;
        }

        foreach (['first_seen_utc', 'landing_url', 'landing_ip', 'booking_page_url', 'booking_ip', 'accept_language', 'device', 'browser', 'os', 'user_agent'] as $field) {
            $flat[$field] = (string) ($record[$field] ?? '');
        }

        $flat['page_views'] = (int) ($record['page_views'] ?? 0) > 0 ? (string) (int) $record['page_views'] : '';

        foreach (['first', 'last'] as $name) {
            $touch = $record[$name . '_touch'] ?? null;

            if (!\is_array($touch)) {
                continue;
            }

            foreach (self::TOUCH_FIELDS as $field) {
                $flat[$name . '_' . $field] = (string) ($touch[$field] ?? '');
            }

            foreach (($touch['parameters'] ?? []) as $parameter => $value) {
                $flat[$name . '_' . $parameter] = (string) $value;
            }
        }

        return $flat;
    }

    /**
     * The campaign a booking counts for in the non-personal aggregate: the parameters of the last
     * touch (else the first), its referrer host, each cut to {@see self::AGGREGATE_LENGTH}. Never
     * a click ID, a URL or anything of the visitor's device.
     *
     * @param   array<string, mixed>|null  $record
     *
     * @return  array{utm_source: string, utm_medium: string, utm_campaign: string, referrer_host: string}
     */
    public static function campaign(?array $record): array
    {
        $touch = $record['last_touch'] ?? $record['first_touch'] ?? null;
        $parameters = \is_array($touch) && \is_array($touch['parameters'] ?? null) ? $touch['parameters'] : [];
        $cut = static fn (mixed $value): string => self::campaignValue($value, self::AGGREGATE_LENGTH);

        return [
            'utm_source' => $cut($parameters['utm_source'] ?? ''),
            'utm_medium' => $cut($parameters['utm_medium'] ?? ''),
            'utm_campaign' => $cut($parameters['utm_campaign'] ?? ''),
            'referrer_host' => $cut(\is_array($touch) ? ($touch['referrer_host'] ?? '') : ''),
        ];
    }

    /**
     * One campaign parameter value as it may leave the encrypted record: cleaned, at most $max
     * characters, and {@see self::REDACTED} when it looks like an e-mail address or a telephone
     * number. A visitor can put anything into a link: an e-mail address or a telephone number (nine
     * or more digits, also with spaces, hyphens, dots, slashes or brackets between them) never
     * reaches the statistics, which are kept after the booking is anonymised, nor a CRM. A date such
     * as 2026-11-16 has eight digits and stays. The whole stored value is checked before it is cut to
     * $max, so cutting can never hide an address or number at the end.
     */
    public static function campaignValue(mixed $value, int $max): string
    {
        $value = self::clean($value);

        return preg_match('/@|\d(?:[ ().\/-]{0,2}\d){8,}/u', $value) === 1 ? self::REDACTED : self::clean($value, $max);
    }

    /**
     * The five campaign parameters `utm_source`, `utm_medium`, `utm_campaign`, `utm_content` and
     * `utm_term` of one touch, for a CRM: by default the last campaign visit, with $first the first
     * one. When the preferred touch carries none of the five, the other touch is used. All values
     * come from the same touch, never mixed. Each value passes {@see self::campaignValue()} with
     * at most 255 characters; no click ID, URL, address or device ever appears here.
     *
     * @param   array<string, mixed>|null  $record
     *
     * @return  array{utm_source: string, utm_medium: string, utm_campaign: string, utm_content: string, utm_term: string}
     */
    public static function utm(?array $record, bool $first = false): array
    {
        $names = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
        $order = $first ? ['first_touch', 'last_touch'] : ['last_touch', 'first_touch'];

        foreach ($order as $touch) {
            $parameters = \is_array($record[$touch]['parameters'] ?? null) ? $record[$touch]['parameters'] : [];
            $values = [];

            foreach ($names as $name) {
                $values[$name] = self::campaignValue($parameters[$name] ?? '', 255);
            }

            if (array_filter($values, static fn (string $value): bool => $value !== '') !== []) {
                return $values;
            }
        }

        return array_fill_keys($names, '');
    }

    /**
     * Labelled lines for the host (booking page in the administrator, host mail): language key
     * translated with $translate, value. Empty values are left out, and the last touch while it
     * is the first one.
     *
     * @param   array<string, mixed>|null  $record
     *
     * @return  list<array{0: string, 1: string}>
     */
    public static function lines(?array $record, callable $translate): array
    {
        if ($record === null) {
            return [];
        }

        $rows = [
            ['COM_APPOINTMENTS_ATTRIBUTION_FIRST_SEEN', ($record['first_seen_utc'] ?? '') === '' ? '' : $record['first_seen_utc'] . ' UTC'],
            ['COM_APPOINTMENTS_ATTRIBUTION_LANDING', $record['landing_url'] ?? ''],
            ['COM_APPOINTMENTS_ATTRIBUTION_LANDING_IP', $record['landing_ip'] ?? ''],
        ];
        $first = $record['first_touch'] ?? null;
        $last = $record['last_touch'] ?? null;
        $touches = [
            [$first, ['COM_APPOINTMENTS_ATTRIBUTION_FIRST_TIME', 'COM_APPOINTMENTS_ATTRIBUTION_FIRST_LANDING',
                'COM_APPOINTMENTS_ATTRIBUTION_FIRST_REFERRER', 'COM_APPOINTMENTS_ATTRIBUTION_FIRST_PARAMETERS']],
            [$last === $first ? null : $last, ['COM_APPOINTMENTS_ATTRIBUTION_LAST_TIME', 'COM_APPOINTMENTS_ATTRIBUTION_LAST_LANDING',
                'COM_APPOINTMENTS_ATTRIBUTION_LAST_REFERRER', 'COM_APPOINTMENTS_ATTRIBUTION_LAST_PARAMETERS']],
        ];

        foreach ($touches as [$touch, $keys]) {
            if (!\is_array($touch)) {
                continue;
            }

            $parameters = [];

            foreach (($touch['parameters'] ?? []) as $name => $value) {
                $parameters[] = $name . '=' . $value;
            }

            $rows[] = [$keys[0], ($touch['time_utc'] ?? '') === '' ? '' : $touch['time_utc'] . ' UTC'];
            $rows[] = [$keys[1], $touch['landing_url'] ?? ''];
            $rows[] = [$keys[2], $touch['referrer'] ?? ''];
            $rows[] = [$keys[3], implode(', ', $parameters)];
        }

        if (!\is_array($first)) {
            $rows[] = ['COM_APPOINTMENTS_ATTRIBUTION_DIRECT', $translate('COM_APPOINTMENTS_ATTRIBUTION_DIRECT_VALUE')];
        }

        $rows[] = ['COM_APPOINTMENTS_ATTRIBUTION_PAGE_VIEWS', (int) ($record['page_views'] ?? 0) > 0 ? (string) (int) $record['page_views'] : ''];
        $rows[] = ['COM_APPOINTMENTS_ATTRIBUTION_BOOKING_PAGE', $record['booking_page_url'] ?? ''];
        $rows[] = ['COM_APPOINTMENTS_ATTRIBUTION_BOOKING_IP', $record['booking_ip'] ?? ''];
        $rows[] = ['COM_APPOINTMENTS_ATTRIBUTION_LANGUAGE', $record['accept_language'] ?? ''];
        $rows[] = ['COM_APPOINTMENTS_ATTRIBUTION_DEVICE', implode(', ', array_filter(
            [(string) ($record['device'] ?? ''), (string) ($record['browser'] ?? ''), (string) ($record['os'] ?? '')],
            static fn (string $value): bool => $value !== ''))];
        $rows[] = ['COM_APPOINTMENTS_ATTRIBUTION_USER_AGENT', $record['user_agent'] ?? ''];
        $lines = [];

        foreach ($rows as [$key, $value]) {
            if (\is_string($value) && $value !== '') {
                $lines[] = [$translate($key), $value];
            }
        }

        return $lines;
    }

    /**
     * The campaign name of a booking: `utm_campaign` of the last touch, else of the first touch,
     * '' without one.
     *
     * @param   array<string, mixed>|null  $record
     */
    public static function campaignName(?array $record): string
    {
        foreach (['last_touch', 'first_touch'] as $touch) {
            $value = $record[$touch]['parameters']['utm_campaign'] ?? '';

            if (\is_string($value) && self::clean($value) !== '') {
                return self::clean($value);
            }
        }

        return '';
    }

    /**
     * What a mail and the calendar entry of the host show of the origin (the {attribution} block
     * and the entry's DESCRIPTION): one line with the campaign name ({@see self::campaignName()}),
     * nothing else, and '' without a campaign, so no label remains. Mails and calendar entries
     * are copies outside the website: they are neither encrypted nor anonymised with the booking,
     * so they never carry addresses, the device, referrers or pages. The full record is shown in
     * the administrator only. $labelFormat is the label format of the mail (`%s:`).
     *
     * @param   array<string, mixed>|null  $record
     */
    public static function text(?array $record, callable $translate, string $labelFormat): string
    {
        $campaign = self::campaignName($record);

        if ($campaign === '') {
            return '';
        }

        $labelFormat = str_contains($labelFormat, '%s') ? $labelFormat : '%s:';

        return sprintf($labelFormat, $translate('COM_APPOINTMENTS_ATTRIBUTION_CAMPAIGN')) . ' ' . $campaign;
    }

    /** An IP address as text, '' for anything else. */
    private static function ip(mixed $value): string
    {
        $value = self::clean($value, 64);

        return filter_var($value, FILTER_VALIDATE_IP) === false ? '' : $value;
    }

    /**
     * A state with every key, each value cleaned (also one read from the session).
     *
     * @return  array{first_seen_utc: string, landing_url: string, landing_ip: string, views: int, page_url: string,
     *                first_touch: array<string, mixed>|null, last_touch: array<string, mixed>|null}
     */
    private static function state(?array $state): array
    {
        $state ??= [];
        $time = self::clean($state['first_seen_utc'] ?? '', 19);
        $views = $state['views'] ?? 0;

        return [
            'first_seen_utc' => preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $time) === 1 ? $time : '',
            'landing_url' => self::clean($state['landing_url'] ?? ''),
            'landing_ip' => self::ip($state['landing_ip'] ?? ''),
            'views' => \is_int($views) && $views > 0 ? min(self::MAX_VIEWS, $views) : 0,
            'page_url' => self::clean($state['page_url'] ?? ''),
            'first_touch' => self::touch($state['first_touch'] ?? null),
            'last_touch' => self::touch($state['last_touch'] ?? null),
        ];
    }

    /** @return  array<string, mixed>|null */
    private static function touch(mixed $touch): ?array
    {
        if (!\is_array($touch)) {
            return null;
        }

        $time = self::clean($touch['time_utc'] ?? '', 19);

        return [
            'time_utc' => preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $time) === 1 ? $time : '',
            'landing_url' => self::clean($touch['landing_url'] ?? ''),
            'referrer' => self::clean($touch['referrer'] ?? ''),
            'referrer_host' => self::clean($touch['referrer_host'] ?? '', 255),
            'parameters' => self::parameters(\is_array($touch['parameters'] ?? null) ? $touch['parameters'] : []),
        ];
    }
}
