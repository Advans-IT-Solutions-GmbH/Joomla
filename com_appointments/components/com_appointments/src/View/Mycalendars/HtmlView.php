<?php
/**
 * @package     Appointments Component
 * @subpackage  Site
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\View\Mycalendars;

use Advans\Component\Appointments\Administrator\Helper\Team\CalendarAccess;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Advans\Component\Appointments\Administrator\Service\Team\DeviceService;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Advans\Component\Appointments\Site\View\PageTitleTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

defined('_JEXEC') or die;

/**
 * «My calendars»: the signed-in user's calendars with their roles, the user's
 * devices with «last synchronised», and «Add device». Layouts: `default` (the
 * page), `device` (rename or remove one device) and `created` (the result of
 * «Add device», rendered only by the controller in the response to the POST
 * that created the device, because the app password exists nowhere else).
 *
 * Reachable only signed in and only with the internet calendars switched on; a
 * guest and a disabled feature both get 404, so the page does not reveal
 * whether the feature exists. Every response is `no-store`.
 *
 * @since  1.4.0
 */
class HtmlView extends BaseHtmlView
{
    use PageTitleTrait;

    /** Session key of the one-time value of the «Add device» form against a second submission. */
    public const FORM_NONCE = 'com_appointments.team.device_nonce';

    /** User state of the last refused «Add device» or device form: field values and messages. */
    public const FORM_STATE = 'com_appointments.team.device_form';

    /** @var CalendarAccess[] */
    protected $calendars = [];

    /** @var object[] */
    protected $devices = [];

    /** Language key of the reason why «Add device» is not offered, empty when it is. */
    protected $addBlocked = '';

    /** Whether a part of the internet calendars this page needs is not available yet. */
    protected $unavailable = false;

    /** @var array{name: string, client: string, errors: array<string, string>, device: int} */
    protected $form = ['name' => '', 'client' => '', 'errors' => [], 'device' => 0];

    protected $formNonce = '';

    /** @var object|null The device of the `device` layout. */
    protected $device;

    /** @var array{device_id: int, app_password: string, name: string, client: string, mail_sent: bool}|null */
    protected $created;

    protected $davUrl = '';

    protected $siteHost = '';

    protected $username = '';

    /** Reason set by the controller for this response (e.g. 423 Locked); it wins over the checks. */
    private string $forcedBlock = '';

    /**
     * Called by the controller when it refuses «Add device» in this response: the
     * page then shows the reason and no form, whatever the checks would say now.
     */
    public function blockAdd(string $reason): void
    {
        $this->forcedBlock = $reason;
    }

    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        $services = TeamServices::fromApplication();

        if ($user === null || $user->guest || !$services->enabled()) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        self::privateHeaders();
        $assets = $this->getDocument()->getWebAssetManager();
        $assets->getRegistry()->addExtensionRegistryFile('com_appointments');
        $assets->useStyle('com_appointments.site');
        $assets->registerAndUseStyle('com_appointments.mycalendars', 'com_appointments/mycalendars.css', [], [], ['com_appointments.site']);
        $assets->registerAndUseScript('com_appointments.mycalendars', 'com_appointments/mycalendars.js', [], ['defer' => true], []);

        $userId = (int) $user->id;
        $this->username = (string) $user->username;
        $this->addresses($services);
        $layout = $this->getLayout();

        if ($layout === 'created' && $this->created !== null) {
            $this->applyPageTitle('mycalendars', Text::_('COM_APPOINTMENTS_TEAM_D_CREATED_TITLE'));

            return parent::display($tpl);
        }

        $state = (array) $app->getUserState(self::FORM_STATE, []);
        $app->setUserState(self::FORM_STATE, null);
        $this->form = [
            'name' => \is_string($state['name'] ?? null) ? $state['name'] : '',
            'client' => \is_string($state['client'] ?? null) ? $state['client'] : '',
            'errors' => array_filter((array) ($state['errors'] ?? []), 'is_string'),
            'device' => (int) ($state['device'] ?? 0),
        ];

        try {
            $this->devices = $services->devices()->listForUser($userId);
        } catch (\Throwable $e) {
            $this->unavailable = true;
        }

        if ($layout === 'device') {
            $id = $app->getInput()->getInt('id', 0);

            foreach ($this->devices as $device) {
                if ($device->id === $id && $device->state === TeamStates::DEVICE_ACTIVE) {
                    $this->device = $device;
                }
            }

            // Another account's device and a device already in clean-up are «not found» alike.
            if ($this->device === null) {
                throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
            }

            $this->applyPageTitle('mycalendars', Text::_('COM_APPOINTMENTS_TEAM_D_DEVICE_TITLE'));

            return parent::display($tpl);
        }

        $this->setLayout('default');
        $this->calendars = $this->calendarsOf($services, $userId);
        $this->addBlocked = $this->forcedBlock !== '' ? $this->forcedBlock
            : ($this->unavailable ? 'COM_APPOINTMENTS_MYCALENDARS_NOT_READY' : $this->addBlockedReason($services, $userId));

        if ($this->addBlocked === '') {
            // A new value per rendering: the form can be sent once, a reload or a second
            // click on the result page does not create a second device.
            $this->formNonce = bin2hex(random_bytes(16));
            $app->getSession()->set(self::FORM_NONCE, $this->formNonce);
        }

        $this->applyPageTitle('mycalendars', Text::_('COM_APPOINTMENTS_MYCALENDARS_TITLE'));

        return parent::display($tpl);
    }

    /**
     * Called by the controller in the response to «Add device». The app password
     * is held by this object for this response only.
     *
     * @param   array{device_id: int, app_password: string, mail_sent?: bool}  $result
     */
    public function setCreated(array $result, string $name, string $client): void
    {
        $this->created = [
            'device_id' => (int) $result['device_id'],
            'app_password' => (string) $result['app_password'],
            'name' => $name,
            'client' => DeviceService::isClient($client) ? $client : 'other',
            'mail_sent' => (bool) ($result['mail_sent'] ?? true),
        ];
        $this->setLayout('created');
    }

    /**
     * Headers of every page that shows an app password, an invitation or account
     * data: not stored by a browser or a proxy, not indexed, no referrer.
     */
    public static function privateHeaders(): void
    {
        $app = Factory::getApplication();

        if (method_exists($app, 'allowCache')) {
            $app->allowCache(false);
        }

        $app->setHeader('Cache-Control', 'private, no-store, max-age=0', true);
        $app->setHeader('Pragma', 'no-cache', true);
        $app->setHeader('Expires', '0', true);
        $app->setHeader('Referrer-Policy', 'no-referrer', true);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
    }

    /**
     * @return  CalendarAccess[]
     */
    private function calendarsOf(TeamServices $services, int $userId): array
    {
        try {
            $calendars = $services->grants()->calendarsFor($userId);
        } catch (\Throwable $e) {
            $this->unavailable = true;

            return [];
        }

        usort($calendars, static fn (CalendarAccess $a, CalendarAccess $b): int => strcasecmp($a->title, $b->title));

        return $calendars;
    }

    /**
     * Why «Add device» cannot be offered now, in the order the user can fix it.
     * A check that cannot be made (e.g. a database error) blocks the form with the
     * general text; its message never reaches the page.
     */
    private function addBlockedReason(TeamServices $services, int $userId): string
    {
        try {
            if (!$services->grants()->hasSecondFactor($userId)) {
                return 'COM_APPOINTMENTS_TEAM_D_ADD_NEEDS_MFA';
            }

            // Release the first key after MFA even before a calendar is granted:
            // a grant cannot wrap its calendar key without this public key.
            $keys = $services->keySession()->current();

            if ($this->calendars === []) {
                return 'COM_APPOINTMENTS_TEAM_D_ADD_NEEDS_GRANT';
            }
        } catch (\Throwable $e) {
            return 'COM_APPOINTMENTS_MYCALENDARS_NOT_READY';
        }

        if ($keys === null || $keys->userId() !== $userId) {
            return 'COM_APPOINTMENTS_TEAM_D_ADD_NEEDS_PASSWORD';
        }

        return '';
    }

    /**
     * The addresses a client is set up with, all from the one CalDAV base address:
     * the full address, and for the iOS «Server» field host and path without scheme.
     */
    private function addresses(TeamServices $services): void
    {
        $this->davUrl = $services->davBaseUrl();
        $host = (string) (parse_url($this->davUrl, PHP_URL_HOST) ?? '');
        $port = parse_url($this->davUrl, PHP_URL_PORT);
        $this->siteHost = $host . ($port ? ':' . $port : '') . (string) (parse_url($this->davUrl, PHP_URL_PATH) ?? '/');
    }
}