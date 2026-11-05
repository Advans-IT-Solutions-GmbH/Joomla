<?php
/**
 * @package     Appointments CalDAV Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\AppointmentsDav\Dav;

use Advans\Component\Appointments\Administrator\Helper\Team\VendorAutoloader;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Advans\Component\Appointments\Vendor\Sabre\HTTP\Sapi;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Utilities\IpHelper;

defined('_JEXEC') or die;

/**
 * Entry point of the CalDAV server in Joomla: matches the request path against
 * the DAV base address ({@see TeamServices::davBaseUrl()}) and `/.well-known/caldav`,
 * drops the Joomla session the application started for this request (no cookie,
 * no session row), and hands the request to {@see DavGateway}, which runs without
 * Joomla. Site offline mode does not stop it.
 *
 * @since  1.4.0
 */
final class DavRequestHandler
{
    private ?DavAddress $address = null;

    public function __construct(
        private readonly CMSApplicationInterface $app,
        private readonly TeamServices $services
    ) {
    }

    public function matches(): bool
    {
        return $this->address()->classify($this->requestPath()) !== null;
    }

    public function handle(): void
    {
        $this->dropSession();

        if (!VendorAutoloader::register()) {
            Log::add('The bundled CalDAV libraries are missing.', Log::ERROR, 'com_appointments');
            http_response_code(503);
            header('Cache-Control: private, no-store');

            return;
        }

        $server = $_SERVER;
        $https = $this->isHttps();
        $request = Sapi::createFromServerArray($server);
        $request->setBody(fopen('php://input', 'r'));
        $request->removeHeader('Authorization');

        // Over plain HTTP the credentials are not even read; the gateway answers 403.
        $authorization = $https ? BasicCredentials::headerFromServer($server) : null;

        if ($authorization !== null) {
            $request->setHeader('Authorization', $authorization);
        }

        $environment = new DavEnvironment(
            $https,
            (string) IpHelper::getIp(),
            (string) $this->app->get('sitename', ''),
            $this->address(),
            fn (int $userId): string => $this->busySummary($userId),
            static function (\Throwable $e): void {
                // Class and place only: a message could quote request data, and credentials are never logged.
                Log::add(
                    'CalDAV: ' . \get_class($e) . ' (' . $e->getCode() . ') at ' . basename($e->getFile()) . ':' . $e->getLine(),
                    Log::ERROR,
                    'com_appointments'
                );
            }
        );

        $response = (new DavGateway(DavServices::fromTeamServices($this->services), $environment))->handle($request);

        $this->dropSession();
        Sapi::sendResponse($response);
    }

    private function address(): DavAddress
    {
        return $this->address ??= DavAddress::fromSetting($this->services->davBaseUrl(), Uri::root());
    }

    private function requestPath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        return \is_string($uri) ? (string) parse_url('http://h' . (str_starts_with($uri, '/') ? '' : '/') . $uri, PHP_URL_PATH) : '';
    }

    /**
     * HTTPS as Joomla sees it, including a TLS-terminating load balancer when the
     * site is configured for one.
     */
    private function isHttps(): bool
    {
        if (method_exists($this->app, 'isSslConnection')) {
            return (bool) $this->app->isSslConnection();
        }

        return Uri::getInstance()->isSsl();
    }

    /**
     * Joomla starts a session before system plugins run. A CalDAV client must
     * get neither its cookie nor a row in the session table: the session is
     * aborted without writing, its row is removed when this request created it,
     * and no Set-Cookie header leaves.
     */
    private function dropSession(): void
    {
        try {
            $session = method_exists($this->app, 'getSession') ? $this->app->getSession() : null;

            if ($session !== null && $session->isActive()) {
                $isNew = $session->isNew();
                $id = (string) $session->getId();
                $session->abort();

                if ($isNew && $id !== '') {
                    $db = $this->services->db();
                    $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
                    $db->setQuery(
                        $query->delete($db->quoteName('#__session'))
                            ->where($db->quoteName('session_id') . ' = :id')
                            ->bind(':id', $id)
                    )->execute();
                }
            }
        } catch (\Throwable $e) {
            Log::add('CalDAV: the session could not be dropped: ' . \get_class($e), Log::WARNING, 'com_appointments');
        }

        if (!headers_sent()) {
            header_remove('Set-Cookie');
        }
    }

    /**
     * «Busy» in the user's own language (Joomla user setting), else the site language.
     */
    private function busySummary(int $userId): string
    {
        $language = $this->app->getLanguage();

        try {
            $tag = (string) Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userId)->getParam('language', '');

            if ($tag !== '' && LanguageHelper::exists($tag)) {
                $language = Factory::getContainer()->get(LanguageFactoryInterface::class)->createLanguage($tag);
            }
        } catch (\Throwable $e) {
            // The site language is the fallback.
        }

        $language->load('plg_system_appointmentsdav', JPATH_ADMINISTRATOR)
            || $language->load('plg_system_appointmentsdav', JPATH_PLUGINS . '/system/appointmentsdav');

        $text = $language->_('PLG_SYSTEM_APPOINTMENTSDAV_BUSY');

        return $text === 'PLG_SYSTEM_APPOINTMENTSDAV_BUSY' ? 'Busy' : $text;
    }
}
