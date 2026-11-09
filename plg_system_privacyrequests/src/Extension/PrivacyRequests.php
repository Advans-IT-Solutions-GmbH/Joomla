<?php
/**
 * @package     Privacy Requests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\PrivacyRequests\Extension;

use Advans\Plugin\System\PrivacyRequests\Service\Admission;
use Advans\Plugin\System\PrivacyRequests\Service\GuestRequests;
use Advans\Plugin\System\PrivacyRequests\Service\OperationLock;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Privacy\Administrator\Removal\Status;
use Joomla\Component\Privacy\Administrator\Table\RequestTable;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Utilities\IpHelper;

defined('_JEXEC') or die;

final class PrivacyRequests extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    protected $autoloadLanguage = true;
    private int $removing = 0;
    private int $completing = 0;
    private ?OperationLock $operationLock = null;
    private array $initialMessages = [];
    public const ROUTE = 'index.php?option=com_ajax&plugin=privacyrequests&group=system&format=html';

    public static function getSubscribedEvents(): array
    {
        return [
            'onAjaxPrivacyrequests' => 'onAjaxPrivacyrequests',
            'onContentBeforeSave' => 'onContentBeforeSave',
            'onPrivacyCanRemoveData' => 'onPrivacyCanRemoveData',
            'onAfterRoute' => 'onAfterRoute',
            'onBeforeRespond' => 'onBeforeRespond',
            'application.before_respond' => 'onBeforeRespond',
            'onAfterRender' => 'onAfterRender',
            'onBeforeCompileHead' => 'onBeforeCompileHead',
        ];
    }

    public function onAjaxPrivacyrequests(EventInterface $event): void
    {
        $app = $this->getApplication();
        if (!$app->isClient('site')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
        $app->setHeader('Cache-Control', 'no-store, private', true);
        $app->setHeader('Referrer-Policy', 'no-referrer', true);
        $app->getDocument()->setTitle(Text::_('PLG_SYSTEM_PRIVACYREQUESTS_TITLE'));
        $input = $app->getInput();
        if ($input->getCmd('task') === 'capabilities' && strtoupper($input->getMethod()) === 'GET') {
            if ($input->getCmd('format') !== 'json') {
                throw new \RuntimeException(Text::_('JERROR_LAYOUT_REQUESTED_RESOURCE_WAS_NOT_FOUND'), 406);
            }
            $url = rtrim(Uri::root(), '/') . '/' . self::ROUTE;
            $event->addResult([
                'capability' => 'core-privacy-guest-remove',
                'schema' => 1,
                'enabled' => true,
                'requiresAccount' => false,
                'requestUrl' => $url,
                'confirmationUrl' => $url,
                'core' => 'com_privacy',
                'returnRedirects' => false,
            ]);

            return;
        }
        $email = '';
        $token = $input->get->getString('confirm_token', '');
        $message = '';
        if (strtoupper($input->getMethod()) === 'POST') {
            $email = trim($input->post->getString('email', ''));
            $token = trim($input->post->getString('confirm_token', ''));
            if ((string) $input->post->get(Session::getFormToken(), '', 'alnum') === '') {
                $message = Text::_('JINVALID_TOKEN_NOTICE');
            } elseif (!GuestRequests::validAddress($email)) {
                $message = Text::_('PLG_SYSTEM_PRIVACYREQUESTS_INVALID_EMAIL');
            } else {
                $operation = $input->post->getCmd('operation', '') === 'confirm' ? 'confirm' : 'request';
                try {
                    // This is a site-wide availability decision, before admission or
                    // address-dependent shortcuts, not an account/request oracle.
                    if ($operation === 'request' && !(bool) $app->get('mailonline', 1)) {
                        throw new \RuntimeException('Outgoing privacy confirmation mail is disabled.');
                    }
                    $admission = new Admission($this->getDatabase(), (string) $app->get('secret', ''));
                    $admitted = $admission->reserve($operation, $email, (string) IpHelper::getIp());
                    $service = new GuestRequests($this->getDatabase(), $app, $admission);
                    if ($operation === 'request') {
                        if ($admitted) {
                            $service->request($email);
                        }
                        // Existing, new, confirmed and rate-limited addresses receive the same answer.
                        $message = Text::_('PLG_SYSTEM_PRIVACYREQUESTS_RECEIVED');
                    } else {
                        // A refused attempt does the same password work, without a usable token.
                        $result = $service->confirm($email, $admitted ? $token : bin2hex(random_bytes(24)));
                        $message = match ($result) {
                            'confirmed' => Text::_('PLG_SYSTEM_PRIVACYREQUESTS_CONFIRMED'),
                            'expired' => Text::_('PLG_SYSTEM_PRIVACYREQUESTS_EXPIRED'),
                            default => Text::_('PLG_SYSTEM_PRIVACYREQUESTS_CONFIRM_FAILED'),
                        };
                    }
                } catch (\Throwable $e) {
                    // The public response must never include addresses, SQL, tokens or exception details.
                    Log::add('Privacy request operation failed (' . get_class($e) . ').', Log::ERROR, 'plg_system_privacyrequests');
                    $message = Text::_('PLG_SYSTEM_PRIVACYREQUESTS_UNAVAILABLE');
                }
            }
        }
        $event->addResult($this->form($email, $token, $message));
    }

    /**
     * A collation match is not proof of account ownership. This runs before any
     * removal callbacks, independently of plugin ordering or later pseudonymisation.
     */
    public function onPrivacyCanRemoveData(EventInterface $event): void
    {
        $user = $event->getArgument('user');
        $request = $event->getArgument('subject');
        if ($user !== null && Admission::address((string) $user->email) !== Admission::address((string) $request->email)) {
            $status = new Status();
            $status->canRemove = false;
            $status->reason = Text::_('PLG_SYSTEM_PRIVACYREQUESTS_ACCOUNT_MISMATCH');
            $event->addResult($status);
        }
    }

    public function onContentBeforeSave(EventInterface $event): void
    {
        $table = $event->getArgument('subject');
        if (!$table instanceof RequestTable || !(int) $table->id) {
            return;
        }
        $db = $this->getDatabase();
        if ((int) $table->status === 1 && !GuestRequests::$confirming) {
            $managed = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__privacy_guest_subjects')
                . ' WHERE request_id = ' . (int) $table->id)->loadResult();
            $data = $event->getArgument('data', []);
            if ($managed && array_key_exists('status', $data)) {
                $table->setError(Text::_('PLG_SYSTEM_PRIVACYREQUESTS_USE_CONFIRMATION'));
                $event->addResult(false);
            }
        }
        if ($table->request_type !== 'remove' || (int) $table->status !== 2) {
            return;
        }
        $outcome = $db->setQuery('SELECT reviewed_result FROM ' . $db->quoteName('#__privacy_guest_outcomes')
            . ' WHERE request_id = ' . (int) $table->id)->loadResult();
        $form = $this->getApplication()->getInput()->post->get('jform', [], 'array');
        if ($this->operationLock === null || $this->completing !== (int) $table->id
            || (int) $outcome !== 1 || ($form['privacy_scope_reviewed'] ?? '') !== '1') {
            $table->setError(Text::_('PLG_SYSTEM_PRIVACYREQUESTS_COMPLETION_BLOCKED'));
            $event->addResult(false);
        }
    }

    public function onAfterRender(): void
    {
        $app = $this->getApplication();
        $input = $app->getInput();
        if (!$app->isClient('administrator') || $input->getCmd('option') !== 'com_privacy'
            || $input->getCmd('view') !== 'request' || !$app->getIdentity()->authorise('core.admin', 'com_privacy')) {
            return;
        }
        $db = $this->getDatabase();
        $row = $db->setQuery('SELECT status, request_type FROM ' . $db->quoteName('#__privacy_requests')
            . ' WHERE id = ' . $input->getInt('id', 0))->loadObject();
        if (!$row || $row->request_type !== 'remove' || !in_array((int) $row->status, [0, 1], true)) {
            return;
        }
        $field = '';
        if ((int) $row->status === 0) {
            $failed = (int) $db->setQuery('SELECT delivery_failed FROM ' . $db->quoteName('#__privacy_guest_subjects')
                . ' WHERE request_id = ' . $input->getInt('id', 0))->loadResult();
            if ($failed !== 1) {
                return;
            }
            $field = '<div class="alert alert-warning" role="alert" data-privacy-delivery="failed">'
                . htmlspecialchars(Text::_('PLG_SYSTEM_PRIVACYREQUESTS_DELIVERY_WARNING'), ENT_QUOTES, 'UTF-8')
                . $this->contactLink() . '</div>';
        } else {
            // Core's review layout does not load a Form. Add native fields through
            // the supported output hook; no Core template or model is replaced.
            $form = new Form('plg_system_privacyrequests.review', ['control' => 'jform']);
            $form->load('<form><fieldset name="default"><field name="privacy_scope_reviewed" type="checkbox"'
                . ' filter="integer" label="PLG_SYSTEM_PRIVACYREQUESTS_REVIEW_LABEL" description="PLG_SYSTEM_PRIVACYREQUESTS_REVIEW_DESC" />'
                . '<field name="privacy_remediation_reviewed" type="checkbox" filter="integer"'
                . ' label="PLG_SYSTEM_PRIVACYREQUESTS_REMEDIATION_LABEL" description="PLG_SYSTEM_PRIVACYREQUESTS_REMEDIATION_DESC" /></fieldset></form>');
            $field = $form->renderField('privacy_scope_reviewed') . $form->renderField('privacy_remediation_reviewed');
        }
        $body = preg_replace_callback('/<form\b[^>]*\bid="item-form"[^>]*>/i',
            static fn (array $match): string => $match[0] . '<div class="card card-body mt-3" data-privacyrequests-review>' . $field . '</div>',
            $app->getBody(), 1);
        if ($body === null) {
            throw new \RuntimeException('Cannot render privacy scope review.');
        }
        $app->setBody($body);
    }

    public function onBeforeCompileHead(): void
    {
        $app = $this->getApplication();
        $input = $app->getInput();
        if (!$app->isClient('administrator') || $input->getCmd('option') !== 'com_privacy'
            || $input->getCmd('view') !== 'request' || $app->getDocument()->getType() !== 'html'
            || !$app->getIdentity()->authorise('core.admin', 'com_privacy')) {
            return;
        }
        // The marker is added only when this adapter renders its native review
        // fields or delivery warning. Other Core views/templates remain unchanged.
        $scope = 'body.com_privacy.view-request:has([data-privacyrequests-review])';
        $css = <<<CSS
@media (max-width: 991.98px) {
    {$scope} #header .header-inside,
    {$scope} #header .header-title,
    {$scope} #header .header-title > .d-flex {
        block-size: auto;
        min-inline-size: 0;
    }
    {$scope} #header .header-title { flex: 1 1 0%; }
    {$scope} #header .header-title > .d-flex:first-child { flex-shrink: 0; }
    {$scope} #header .container-title { min-inline-size: 0; }
    {$scope} #header .page-title {
        white-space: normal;
        overflow-wrap: anywhere;
        line-height: 1.4;
        padding-block: .5rem;
    }
    {$scope} joomla-alert.js-pstats-alert { flex-direction: column; }
    {$scope} joomla-alert.js-pstats-alert > div {
        inline-size: auto;
        min-inline-size: 0;
    }
    {$scope} joomla-alert.js-pstats-alert .alert-heading { padding-inline-end: 2rem; }
    {$scope} joomla-alert.js-pstats-alert table,
    {$scope} #item-form table {
        table-layout: fixed;
        inline-size: 100%;
    }
    {$scope} joomla-alert.js-pstats-alert th:first-child { inline-size: 45% !important; }
    {$scope} joomla-alert.js-pstats-alert th,
    {$scope} joomla-alert.js-pstats-alert td,
    {$scope} #item-form th,
    {$scope} #item-form td,
    {$scope} #item-form dd {
        white-space: normal;
        overflow-wrap: anywhere;
    }
}
CSS;
        $app->getDocument()->getWebAssetManager()->addInlineStyle($css, ['name' => 'plg_system_privacyrequests.native-review']);
    }

    public function onAfterRoute(): void
    {
        $app = $this->getApplication();
        $input = $app->getInput();
        if (!$app->isClient('administrator') || $input->getCmd('option') !== 'com_privacy'
            || !in_array(strtolower($input->getCmd('task', '')), ['request.remove', 'request.complete'], true)
            || !$app->getIdentity()->authorise('core.admin', 'com_privacy')) {
            return;
        }
        $remove = strtolower($input->getCmd('task', '')) === 'request.remove';
        $id = $input->getInt('id', 0);
        if ($id < 1 || !Session::checkToken($remove ? 'request' : 'post')) {
            return;
        }
        try {
            $this->operationLock = new OperationLock($this->getDatabase(), $id, (string) $app->get('secret'));
        } catch (\Throwable $e) {
            Log::add('Privacy operation admission failed (' . get_class($e) . ').', Log::ERROR, 'plg_system_privacyrequests');
            throw new \RuntimeException(Text::_('PLG_SYSTEM_PRIVACYREQUESTS_OPERATION_BUSY'), 409, $e);
        }
        if ($remove) {
            $previous = $this->getDatabase()->setQuery('SELECT reviewed_result FROM '
                . $this->getDatabase()->quoteName('#__privacy_guest_outcomes') . ' WHERE request_id = ' . $id)->loadResult();
            $form = $input->post->get('jform', [], 'array');
            if ((string) $previous === '0' && ($form['privacy_remediation_reviewed'] ?? '') !== '1') {
                $this->operationLock->release();
                $this->operationLock = null;
                throw new \RuntimeException(Text::_('PLG_SYSTEM_PRIVACYREQUESTS_REMEDIATION_REQUIRED'), 409);
            }
            $this->removing = $id;
            $this->initialMessages = $app->getMessageQueue();
            // Written BEFORE dispatch: an exception or an interrupted callback leaves
            // an incomplete outcome. Core's later Complete remains a separate operation.
            $this->outcome(0);
        } else {
            $this->completing = $id;
        }
    }

    public function onBeforeRespond(): void
    {
        if ($this->operationLock === null) {
            return;
        }
        try {
            if ($this->removing > 0) {
                $success = false;
                $warnings = false;
                $messages = $this->getApplication()->getMessageQueue();
                if (array_slice($messages, 0, count($this->initialMessages)) !== $this->initialMessages) {
                    $messages = [];
                } else {
                    $messages = array_slice($messages, count($this->initialMessages));
                }
                foreach ($messages as $message) {
                    $success = $success || $message['message'] === Text::_('COM_PRIVACY_DATA_REMOVED');
                    $warnings = $warnings || in_array($message['type'], ['warning', 'error'], true);
                }
                $this->outcome($success && !$warnings ? 1 : 0);
            }
        } finally {
            $this->operationLock->release();
            $this->operationLock = null;
        }
    }

    private function outcome(int $result): void
    {
        $db = $this->getDatabase();
        $sql = 'INSERT INTO ' . $db->quoteName('#__privacy_guest_outcomes')
            . ' (request_id, reviewed_result) VALUES (' . $this->removing . ', ' . $result . ')';
        $sql .= strtolower($db->getServerType()) === 'postgresql'
            ? ' ON CONFLICT (request_id) DO UPDATE SET reviewed_result = EXCLUDED.reviewed_result'
            : ' ON DUPLICATE KEY UPDATE reviewed_result = VALUES(reviewed_result)';
        $db->setQuery($sql)->execute();
    }

    private function form(string $email, string $token, string $message): string
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $token = preg_match('/^[A-Za-z0-9]{16,64}$/D', $token) ? $token : '';
        $url = rtrim(Uri::root(), '/') . '/' . self::ROUTE;

        return '<section class="privacy-requests"><h1>' . $escape(Text::_('PLG_SYSTEM_PRIVACYREQUESTS_TITLE')) . '</h1>'
            . ($message === '' ? '' : '<p role="status">' . $escape($message) . '</p>')
            . '<p>' . $escape(Text::_('PLG_SYSTEM_PRIVACYREQUESTS_INTRO')) . '</p>'
            . '<form action="' . $escape($url) . '" method="post"><p><label for="privacy-email">'
            . $escape(Text::_('JGLOBAL_EMAIL')) . '</label> <input type="email" name="email" id="privacy-email" required maxlength="100" autocomplete="email" value="'
            . $escape($email) . '"></p><p><label for="privacy-token">' . $escape(Text::_('PLG_SYSTEM_PRIVACYREQUESTS_TOKEN'))
            . '</label> <input name="confirm_token" id="privacy-token" autocomplete="off" maxlength="64" value="' . $escape($token) . '"></p>'
            . '<input type="hidden" name="' . Session::getFormToken() . '" value="1">'
            . '<button name="operation" value="request" type="submit">' . $escape(Text::_('PLG_SYSTEM_PRIVACYREQUESTS_REQUEST')) . '</button> '
            . '<button name="operation" value="confirm" type="submit">' . $escape(Text::_('PLG_SYSTEM_PRIVACYREQUESTS_CONFIRM')) . '</button>'
            . '</form>' . $this->contactLink()
            . '<p>' . $escape(Text::_('PLG_SYSTEM_PRIVACYREQUESTS_LIMITS')) . '</p></section>';
    }

    private function contactLink(): string
    {
        // A booted but disabled plugin has no params registry; it then offers no contact link.
        $route = trim((string) ($this->params?->get('contact_route', '') ?? ''));
        if ($route === '' || strlen($route) > 2048 || !preg_match('~^index\.php(?:\?[^\x00-\x20\x7f\\\\#]*)?$~D', $route)) {
            if ($route !== '') {
                Log::add('Invalid local privacy contact route configuration.', Log::ERROR, 'plg_system_privacyrequests');
            }

            return '<p role="note">' . htmlspecialchars(Text::_('PLG_SYSTEM_PRIVACYREQUESTS_CONTACT_UNCONFIGURED'), ENT_QUOTES, 'UTF-8') . '</p>';
        }

        return '<p><a data-privacy-contact href="'
            . htmlspecialchars(rtrim(Uri::root(), '/') . '/' . $route, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars(Text::_('PLG_SYSTEM_PRIVACYREQUESTS_CONTACT'), ENT_QUOTES, 'UTF-8') . '</a></p><p>'
            . htmlspecialchars(Text::_('PLG_SYSTEM_PRIVACYREQUESTS_CONTACT_NOTE'), ENT_QUOTES, 'UTF-8') . '</p>';
    }
}
