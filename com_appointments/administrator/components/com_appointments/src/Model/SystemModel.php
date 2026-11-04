<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Model;

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\Team\CipherSuite;
use Advans\Component\Appointments\Administrator\Service\FeedService;
use Advans\Component\Appointments\Administrator\Service\Team\AuditService;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Version;

defined('_JEXEC') or die;

class SystemModel extends BaseDatabaseModel
{
    public function getChecks(): array
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        if (!$user->authorise('core.manage', 'com_appointments') || !$user->authorise('core.options', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
        $db = $this->getDatabase();
        $version = (new Version())->getShortVersion();
        $timezone = (string) $app->get('offset', 'UTC');
        $checks = [
            ['label' => 'COM_APPOINTMENTS_SYSTEM_PHP', 'ok' => version_compare(PHP_VERSION, '8.1', '>='), 'value' => PHP_VERSION],
            ['label' => 'COM_APPOINTMENTS_SYSTEM_JOOMLA', 'ok' => version_compare($version, '5.4', '>='), 'value' => $version],
            ['label' => 'COM_APPOINTMENTS_SYSTEM_TIMEZONE', 'ok' => in_array($timezone, \DateTimeZone::listIdentifiers(), true), 'value' => $timezone],
            ['label' => 'COM_APPOINTMENTS_SYSTEM_MAIL', 'ok' => (bool) $app->get('mailonline', true),
                'value' => (string) $app->get('mailer', 'mail')],
        ];
        foreach (['task' => 'COM_APPOINTMENTS_SYSTEM_TASK', 'privacy' => 'COM_APPOINTMENTS_SYSTEM_PRIVACY'] as $group => $label) {
            $query = $this->query()->select($db->quoteName('enabled'))->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . " = 'plugin'")->where($db->quoteName('element') . " = 'appointments'")
                ->where($db->quoteName('folder') . ' = ' . $db->quote($group));
            $enabled = (int) $db->setQuery($query)->loadResult() === 1;
            $checks[] = ['label' => $label, 'ok' => $enabled,
                'value' => Text::_($enabled ? 'JENABLED' : 'JDISABLED')];
        }
        foreach (['reminders' => 'COM_APPOINTMENTS_SYSTEM_REMINDERS', 'retention' => 'COM_APPOINTMENTS_SYSTEM_RETENTION'] as $routine => $label) {
            $query = $this->query()->select($db->quoteName(['last_execution', 'last_exit_code']))
                ->from($db->quoteName('#__scheduler_tasks'))->where($db->quoteName('type') . ' = '
                    . $db->quote('plg_task_appointments.' . $routine))->where($db->quoteName('state') . ' = 1')
                ->order($db->quoteName('last_execution') . ' DESC')->setLimit(1);
            $task = $db->setQuery($query)->loadAssoc();
            $checks[] = ['label' => $label,
                'ok' => $task && !empty($task['last_execution']) && (int) $task['last_exit_code'] === 0,
                'value' => $task['last_execution'] ?? Text::_('COM_APPOINTMENTS_SYSTEM_NEVER')];
        }
        $invalid = 0;
        foreach (['eventtypes', 'hosts'] as $table) {
            $query = $this->query()->select('COUNT(*)')->from($db->quoteName('#__appointments_' . $table))
                ->where($db->quoteName('feed_token') . " NOT REGEXP '^[a-f0-9]{64}$'");
            $invalid += (int) $db->setQuery($query)->loadResult();
        }
        $checks[] = ['label' => 'COM_APPOINTMENTS_SYSTEM_FEEDS', 'ok' => $invalid === 0, 'value' => (string) $invalid];
        $query = $this->query()->select('MIN(' . $db->quoteName('occurred_utc') . ')')->from($db->quoteName('#__appointments_audit'));
        $oldest = $db->setQuery($query)->loadResult();
        $checks[] = ['label' => 'COM_APPOINTMENTS_SYSTEM_AUDIT', 'ok' => true,
            'value' => $oldest ?? Text::_('COM_APPOINTMENTS_SYSTEM_NONE')];
        return array_merge($checks, $this->teamChecks());
    }

    /**
     * Internet calendars (work package F, specification 8.3): what a host can block, each with
     * its remedy, plus the configuration checks. The checks that send requests to the site
     * itself (HTTPS, DAV methods, Authorization header, well-known) and the Argon2id test run
     * only when asked for (`probe=1`), so the page stays fast. No host name is shown.
     *
     * @return  array<int, array{label: string, ok: bool, value: string, remedy?: string}>
     */
    public function teamChecks(): array
    {
        // The state of keys, MFA enforcement and the log is for administrators only.
        if (!Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_appointments')) {
            return [];
        }

        $services = TeamServices::fromApplication();
        $params = $services->params();
        $enabled = $services->enabled();
        $checks = [
            ['label' => 'COM_APPOINTMENTS_TEAM_F_SYSTEM_ENABLED', 'ok' => true,
                'value' => Text::_((int) $params->get('teamcal_enabled', 0) === 1 ? 'JENABLED' : 'JDISABLED')],
            $this->check('COM_APPOINTMENTS_TEAM_F_SYSTEM_SODIUM', CipherSuite::available(),
                Text::_(CipherSuite::available() ? 'JYES' : 'JNO'), 'COM_APPOINTMENTS_TEAM_F_SYSTEM_SODIUM_FIX'),
        ];
        $memory = self::bytes((string) ini_get('memory_limit'));
        $checks[] = $this->check('COM_APPOINTMENTS_TEAM_F_SYSTEM_MEMORY', $memory < 0 || $memory >= 128 * 1024 * 1024,
            (string) ini_get('memory_limit'), 'COM_APPOINTMENTS_TEAM_F_SYSTEM_MEMORY_FIX');

        if ((int) $params->get('teamcal_enabled', 0) !== 1) {
            return $checks;
        }

        $db = $this->getDatabase();
        $base = $this->siteUrl($params);
        $checks[] = $this->check('COM_APPOINTMENTS_TEAM_F_SYSTEM_HTTPS', str_starts_with($base, 'https://'),
            Text::_(str_starts_with($base, 'https://') ? 'JYES' : 'JNO'), 'COM_APPOINTMENTS_TEAM_F_SYSTEM_HTTPS_FIX');
        // Behind a load balancer Joomla takes the address from X-Forwarded-For,
        // which a client can set unless the proxy overwrites it.
        $proxy = (bool) Factory::getApplication()->get('behind_loadbalancer', false);
        $checks[] = $this->check('COM_APPOINTMENTS_TEAM_F_SYSTEM_PROXY', !$proxy, Text::_($proxy ? 'JYES' : 'JNO'),
            'COM_APPOINTMENTS_TEAM_F_SYSTEM_PROXY_FIX');

        foreach (['system' => 'appointmentsdav', 'user' => 'appointments'] as $folder => $element) {
            $query = $this->query()->select($db->quoteName('enabled'))->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . " = 'plugin'")->where($db->quoteName('element') . ' = ' . $db->quote($element))
                ->where($db->quoteName('folder') . ' = ' . $db->quote($folder));
            $on = (int) $db->setQuery($query)->loadResult() === 1;
            $checks[] = $this->check($folder === 'system' ? 'COM_APPOINTMENTS_TEAM_F_SYSTEM_PLUGIN_DAV' : 'COM_APPOINTMENTS_TEAM_F_SYSTEM_PLUGIN_USER',
                $on, Text::_($on ? 'JENABLED' : 'JDISABLED'), 'COM_APPOINTMENTS_TEAM_F_SYSTEM_PLUGIN_FIX');
        }

        $users = ComponentHelper::getParams('com_users');
        $groups = array_values(array_filter(array_map('intval', array_merge((array) $params->get('team_groups', []),
            [(int) $params->get('external_group', 0)])), static fn (int $id): bool => $id > 0));
        $force = array_map('intval', (array) $users->get('forceMFAUserGroups', []));
        $never = array_map('intval', (array) $users->get('neverMFAUserGroups', []));
        $checks[] = $this->check('COM_APPOINTMENTS_TEAM_F_SYSTEM_MFA_FORCED', $groups !== [] && array_diff($groups, $force) === [],
            Text::_($groups !== [] && array_diff($groups, $force) === [] ? 'JYES' : 'JNO'), 'COM_APPOINTMENTS_TEAM_F_SYSTEM_MFA_FORCED_FIX');
        $checks[] = $this->check('COM_APPOINTMENTS_TEAM_F_SYSTEM_MFA_NEVER', array_intersect($groups, $never) === [],
            Text::_(array_intersect($groups, $never) === [] ? 'JNO' : 'JYES'), 'COM_APPOINTMENTS_TEAM_F_SYSTEM_MFA_NEVER_FIX');

        $recovery = (int) $db->setQuery($this->query()->select('COUNT(*)')->from($db->quoteName('#__appointments_keyring'))
            ->where($db->quoteName('purpose') . " = 'recovery'"))->loadResult() > 0;
        $checks[] = $this->check('COM_APPOINTMENTS_TEAM_F_SYSTEM_RECOVERY', $recovery, Text::_($recovery ? 'JYES' : 'JNO'),
            'COM_APPOINTMENTS_TEAM_F_SYSTEM_RECOVERY_FIX');

        try {
            $anchor = $services->anchor()->writable();
        } catch (\Throwable $e) {
            $anchor = false;
        }

        $checks[] = $this->check('COM_APPOINTMENTS_TEAM_F_SYSTEM_ANCHOR', $anchor, Text::_($anchor ? 'JYES' : 'JNO'),
            'COM_APPOINTMENTS_TEAM_F_SYSTEM_ANCHOR_FIX');

        if (class_exists('Advans\\Component\\Appointments\\Administrator\\Service\\BookingRecords')) {
            // Booking rows still in clear (section 5.10): the retention routine encrypts them.
            $clear = (int) $db->setQuery($this->query()->select('COUNT(*)')->from($db->quoteName('#__appointments_bookings'))
                ->where($db->quoteName('anonymised_utc') . ' IS NULL')
                ->where($db->quoteName('invitee_email') . ' IS NOT NULL')
                ->where($db->quoteName('invitee_email') . " NOT LIKE 'v1:%'"))->loadResult();
            $checks[] = $this->check('COM_APPOINTMENTS_TEAM_F_SYSTEM_BOOKINGS_CLEAR', $clear === 0, (string) $clear,
                'COM_APPOINTMENTS_TEAM_F_SYSTEM_BOOKINGS_CLEAR_FIX');
        }

        $secret = $this->secretUnchanged($services);
        $checks[] = $this->check('COM_APPOINTMENTS_TEAM_F_SYSTEM_SECRET', $secret !== false,
            Text::_($secret === null ? 'COM_APPOINTMENTS_TEAM_F_SYSTEM_SECRET_NO_LOG' : ($secret ? 'COM_APPOINTMENTS_TEAM_F_SYSTEM_SECRET_SAME' : 'COM_APPOINTMENTS_TEAM_F_SYSTEM_SECRET_CHANGED')),
            'COM_APPOINTMENTS_TEAM_F_SYSTEM_SECRET_FIX');

        $legacy = method_exists(FeedService::class, 'legacyEnabled') ? (bool) FeedService::legacyEnabled($params)
            : (int) $params->get('legacy_feeds', 0) === 1;
        $checks[] = $this->check('COM_APPOINTMENTS_TEAM_F_SYSTEM_LEGACY_FEEDS', !$legacy,
            Text::_($legacy ? 'COM_APPOINTMENTS_TEAM_F_SYSTEM_LEGACY_ON' : 'JDISABLED'), 'COM_APPOINTMENTS_TEAM_F_SYSTEM_LEGACY_FIX');

        foreach (['teamcleanup' => 'COM_APPOINTMENTS_TEAM_F_SYSTEM_TASK_CLEANUP', 'teamlog' => 'COM_APPOINTMENTS_TEAM_F_SYSTEM_TASK_LOG',
            'teaminvitations' => 'COM_APPOINTMENTS_TEAM_F_SYSTEM_TASK_INVITATIONS', 'teamretention' => 'COM_APPOINTMENTS_TEAM_F_SYSTEM_TASK_RETENTION'] as $routine => $label) {
            $task = $db->setQuery($this->query()->select($db->quoteName(['last_execution', 'last_exit_code']))
                ->from($db->quoteName('#__scheduler_tasks'))->where($db->quoteName('type') . ' = '
                    . $db->quote('plg_task_appointments.' . $routine))->where($db->quoteName('state') . ' = 1')
                ->order($db->quoteName('last_execution') . ' DESC')->setLimit(1))->loadAssoc();
            $checks[] = $this->check($label, $task && !empty($task['last_execution']) && (int) $task['last_exit_code'] === 0,
                (string) ($task['last_execution'] ?? Text::_('COM_APPOINTMENTS_SYSTEM_NEVER')), 'COM_APPOINTMENTS_TEAM_F_SYSTEM_TASK_FIX');
        }

        if (!$this->getState('probe', false)) {
            $checks[] = ['label' => 'COM_APPOINTMENTS_TEAM_F_SYSTEM_PROBE', 'ok' => true,
                'value' => Text::_('COM_APPOINTMENTS_TEAM_F_SYSTEM_PROBE_OFF')];

            return $checks;
        }

        return array_merge($checks, $this->probes($base, $services->davBaseUrl(), $enabled));
    }

    /**
     * Requests to the site itself: HTTPS with a valid certificate, every DAV method reaching
     * the CalDAV handler (it answers 401 with `WWW-Authenticate: Basic` to an anonymous
     * request; a 405, 501 or a page from the web server means the method was stopped before
     * PHP), the Authorization header arriving (the handler confirms a received header with
     * `X-Appointments-Authorization: received`), the well-known redirect, and Argon2id.
     *
     * @return  array<int, array{label: string, ok: bool, value: string, remedy?: string}>
     */
    private function probes(string $base, string $dav, bool $enabled): array
    {
        $checks = [];
        $argon = false;

        if (CipherSuite::available()) {
            try {
                $argon = \strlen(sodium_crypto_pwhash(32, 'probe', random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES),
                    SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
                    SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13)) === 32;
            } catch (\Throwable $e) {
                $argon = false;
            }
        }

        $checks[] = $this->check('COM_APPOINTMENTS_TEAM_F_SYSTEM_ARGON2', $argon, Text::_($argon ? 'JYES' : 'JNO'), 'COM_APPOINTMENTS_TEAM_F_SYSTEM_MEMORY_FIX');

        if (!\function_exists('curl_init') || !preg_match('#^https?://#i', $base)) {
            $checks[] = $this->check('COM_APPOINTMENTS_TEAM_F_SYSTEM_PROBE', false, Text::_('COM_APPOINTMENTS_TEAM_F_SYSTEM_PROBE_UNAVAILABLE'),
                'COM_APPOINTMENTS_TEAM_F_SYSTEM_PROBE_FIX');

            return $checks;
        }

        $root = $this->request('GET', $base);
        $checks[] = $this->check('COM_APPOINTMENTS_TEAM_F_SYSTEM_TLS', str_starts_with($base, 'https://') && $root['status'] > 0,
            $root['status'] > 0 ? (string) $root['status'] : Text::_('COM_APPOINTMENTS_TEAM_F_SYSTEM_NO_ANSWER'), 'COM_APPOINTMENTS_TEAM_F_SYSTEM_HTTPS_FIX');
        $hsts = stripos($root['headers'], "\nstrict-transport-security:") !== false;
        $checks[] = $this->check('COM_APPOINTMENTS_TEAM_F_SYSTEM_HSTS', $hsts, Text::_($hsts ? 'JYES' : 'JNO'), 'COM_APPOINTMENTS_TEAM_F_SYSTEM_HSTS_FIX');

        if (!$enabled) {
            return $checks;
        }

        $blocked = [];

        foreach (['OPTIONS', 'PROPFIND', 'REPORT', 'PUT', 'DELETE', 'MKCALENDAR'] as $method) {
            $answer = $this->request($method, $dav);

            if ($answer['status'] !== 401 || stripos($answer['headers'], "\nwww-authenticate: basic") === false) {
                $blocked[] = $method . ' ' . ($answer['status'] ?: '-');
            }
        }

        $checks[] = $this->check('COM_APPOINTMENTS_TEAM_F_SYSTEM_METHODS', $blocked === [],
            $blocked === [] ? Text::_('JYES') : implode(', ', $blocked), 'COM_APPOINTMENTS_TEAM_F_SYSTEM_METHODS_FIX');
        $answer = $this->request('PROPFIND', $dav, ['Authorization: Basic ' . base64_encode('probe:' . bin2hex(random_bytes(8)))]);
        $received = stripos($answer['headers'], "\nx-appointments-authorization: received") !== false;
        $checks[] = $this->check('COM_APPOINTMENTS_TEAM_F_SYSTEM_AUTH_HEADER', $received,
            Text::_($received ? 'JYES' : 'COM_APPOINTMENTS_TEAM_F_SYSTEM_NOT_CONFIRMED'), 'COM_APPOINTMENTS_TEAM_F_SYSTEM_AUTH_HEADER_FIX');
        $answer = $this->request('PROPFIND', $base . '.well-known/caldav');
        $location = preg_match('/\nlocation:\s*(\S+)/i', $answer['headers'], $m) === 1 ? $m[1] : '';
        $wellKnown = \in_array($answer['status'], [301, 308], true) && rtrim($location, '/') === rtrim($dav, '/');
        $checks[] = $this->check('COM_APPOINTMENTS_TEAM_F_SYSTEM_WELL_KNOWN', $wellKnown,
            $answer['status'] > 0 ? (string) $answer['status'] : Text::_('COM_APPOINTMENTS_TEAM_F_SYSTEM_NO_ANSWER'), 'COM_APPOINTMENTS_TEAM_F_SYSTEM_WELL_KNOWN_FIX');

        return $checks;
    }

    /**
     * @param   string[]  $headers
     *
     * @return  array{status: int, headers: string}
     */
    private function request(string $method, string $url, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST   => $method,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_HEADER          => true,
            CURLOPT_NOBODY          => false,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_CONNECTTIMEOUT  => 3,
            CURLOPT_TIMEOUT         => 5,
            CURLOPT_HTTPHEADER      => array_merge(['Depth: 0', 'Content-Type: application/xml; charset=utf-8'], $headers),
            CURLOPT_POSTFIELDS      => \in_array($method, ['PROPFIND', 'REPORT', 'PUT', 'MKCALENDAR'], true) ? '' : null,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
        ]);
        $raw = curl_exec($ch);
        $status = \is_string($raw) ? (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) : 0;
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return ['status' => $status, 'headers' => "\n" . str_replace("\r", '', \is_string($raw) ? substr($raw, 0, $size) : '')];
    }

    /**
     * The site's own address: the configured public address, else Joomla's root URL. Ends with a slash.
     */
    private function siteUrl(\Joomla\Registry\Registry $params): string
    {
        $url = trim((string) $params->get('public_site_url', ''));

        if ($url === '') {
            $url = Uri::root();
        }

        return rtrim($url, '/') . '/';
    }

    /**
     * Whether the log head still verifies with the current site secret: true, false, or null
     * while nothing was logged yet. Every server key comes from the site secret, so a changed
     * secret shows here first.
     */
    private function secretUnchanged(TeamServices $services): ?bool
    {
        $db = $this->getDatabase();
        $head = $db->setQuery($this->query()->select($db->quoteName(['last_id', 'last_hash']))
            ->from($db->quoteName('#__appointments_teamlog_head'))->where($db->quoteName('id') . ' = ' . AuditService::HEAD_ID))->loadAssoc();

        if (!$head) {
            return null;
        }

        $key = $services->keyProvider()->key(KeyProvider::PURPOSE_TEAM_LOG);
        $lastId = (int) $head['last_id'];

        if ($lastId === 0) {
            return hash_equals(AuditService::emptyHead($key), (string) $head['last_hash']);
        }

        $rowHash = $db->setQuery($this->query()->select($db->quoteName('row_hash'))->from($db->quoteName('#__appointments_teamlog'))
            ->where($db->quoteName('id') . ' = ' . $lastId))->loadResult();

        return \is_string($rowHash) && hash_equals(AuditService::seal($key, $lastId, $rowHash), (string) $head['last_hash']);
    }

    /**
     * @return  array{label: string, ok: bool, value: string, remedy: string}
     */
    private function check(string $label, bool $ok, string $value, string $remedy): array
    {
        return ['label' => $label, 'ok' => $ok, 'value' => $value, 'remedy' => $remedy];
    }

    /**
     * `memory_limit` in bytes, -1 for unlimited.
     */
    public static function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    protected function populateState()
    {
        parent::populateState();
        // The probes send requests and hash with 64 MiB: only on the form's
        // POST with its token, never from a link.
        $input = Factory::getApplication()->getInput();
        $this->setState('probe', $input->getMethod() === 'POST' && $input->post->getInt('probe', 0) === 1
            && \Joomla\CMS\Session\Session::checkToken('post'));
    }

    private function query(): \Joomla\Database\QueryInterface
    {
        $db = $this->getDatabase();
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
