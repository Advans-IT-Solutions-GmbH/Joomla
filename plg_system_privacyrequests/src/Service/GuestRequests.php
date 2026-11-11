<?php
/**
 * @package     Privacy Requests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\PrivacyRequests\Service;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Mail\MailTemplate;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserHelper;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

final class GuestRequests
{
    /** In-memory authority for the onContentBeforeSave guard, never a request parameter. */
    public static bool $confirming = false;

    public function __construct(private DatabaseInterface $db, private CMSApplicationInterface $app, private Admission $admission)
    {
    }

    /**
     * Dot-atom addresses only. FILTER_VALIDATE_EMAIL also accepts quoted local parts and
     * address literals, for example "<svg onload=...>"@example.org, and Core's administrator
     * request view prints the stored address without escaping. Quotes, angle brackets,
     * backslashes and the other RFC 5322 specials are refused before Core stores anything;
     * Unicode letters in the local part remain allowed.
     */
    public static function validAddress(string $email): bool
    {
        return strlen($email) <= 100 && preg_match('//u', $email) === 1
            && substr_count($email, '@') === 1
            && !preg_match('/[\x00-\x20\x7f"<>\\\\()\[\],;:]/', $email)
            && (bool) filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE);
    }

    /**
     * Create, or renew the token on the same pending Core request. The subject row
     * is a mutex, not another request system: Core retains the address/date/status.
     */
    public function request(string $email): bool
    {
        $email = trim($email);
        if (!self::validAddress($email)) {
            return false;
        }
        $db = $this->db;
        $hash = $db->quote($this->admission->hash('subject', Admission::address($email)));
        $table = $db->quoteName('#__privacy_guest_subjects');
        $db->transactionStart();
        try {
            $sql = "INSERT INTO $table (subject_hash, request_id) VALUES ($hash, 0)";
            $sql .= strtolower($db->getServerType()) === 'postgresql'
                ? ' ON CONFLICT (subject_hash) DO NOTHING'
                : ' ON DUPLICATE KEY UPDATE subject_hash = subject_hash';
            $db->setQuery($sql)->execute();
            $subject = $db->setQuery("SELECT request_id, delivery_failed FROM $table WHERE subject_hash = $hash FOR UPDATE")->loadAssoc();
            $id = (int) $subject['request_id'];
            $row = $id > 0 ? $this->locked($id) : null;
            $factory = $this->app->bootComponent('com_privacy')->getMVCFactory();
            if ($row !== null && (Admission::address($row->email) !== Admission::address($email) || $row->request_type !== 'remove')) {
                throw new \RuntimeException('The privacy receipt address does not match.');
            }
            if ($row !== null && (int) $row->status === 1) {
                $db->transactionCommit();

                return true;
            }
            $renew = $row !== null && (int) $row->status === 0;
            if (!$renew) {
                // SQL narrows candidates, PHP decides ownership: accent-insensitive
                // collations must neither disclose nor block a different address.
                $existing = $db->setQuery('SELECT email FROM ' . $db->quoteName('#__privacy_requests')
                    . ' WHERE request_type = ' . $db->quote('remove') . ' AND status IN (0,1)'
                    . ' AND LOWER(email) = LOWER(' . $db->quote($email) . ')')->loadColumn();
                foreach ($existing as $address) {
                    if (Admission::address($address) === Admission::address($email)) {
                        // Native/other-adapter requests are not adopted or altered.
                        $db->transactionCommit();

                        return true;
                    }
                }
            }
            $model = $factory->createModel('Request', 'Administrator', ['ignore_request' => true]);
            $model->setCurrentUser($this->app->getIdentity() ?? new User());
            $token = ApplicationHelper::getHash(UserHelper::genRandomPassword());
            $data = ['confirm_token' => UserHelper::hashPassword($token),
                'confirm_token_created_at' => gmdate('Y-m-d H:i:s')];
            $data += $renew ? ['id' => $id] : ['email' => $email, 'request_type' => 'remove', 'status' => 0];
            // Public Core model/table API, including its native save events. The adapter
            // only creates pending requests; ConfirmModel alone authors confirmation.
            if (!$model->save($data)) {
                throw new \RuntimeException('Core did not save the pending privacy receipt.');
            }
            $id = (int) $model->getState('request.id');
            $db->setQuery("UPDATE $table SET request_id = $id WHERE subject_hash = $hash")->execute();
            if (!$renew) {
                $audit = $this->app->bootComponent('com_actionlogs')->getMVCFactory()
                    ->createModel('Actionlog', 'Administrator', ['ignore_request' => true]);
                $audit->setCurrentUser(new User());
                $audit->addLog([['action' => 'request-created', 'requesttype' => 'remove', 'subjectemail' => $email,
                        'id' => $id, 'itemlink' => 'index.php?option=com_privacy&view=request&id=' . $id]],
                        'COM_PRIVACY_ACTION_LOG_CREATED_REQUEST', 'com_privacy.request', 0);
                if (!(int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__action_logs')
                    . ' WHERE item_id = ' . $id . ' AND user_id = 0 AND message_language_key = '
                    . $db->quote('COM_PRIVACY_ACTION_LOG_CREATED_REQUEST')
                    . ' AND extension = ' . $db->quote('com_privacy.request'))->loadResult()) {
                    throw new \RuntimeException('Core did not persist the privacy receipt audit.');
                }
            }
            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();
            throw $e;
        }

        // Receipt acceptance is already committed. Public output must not turn
        // transport failure into an oracle for existing/confirmed/native receipts.
        // Persist and expose the delivery problem to the operator, never claim sent.
        $deliveryFailed = false;
        try {
            $this->loadCoreFormsAndLanguage();
            $container = Factory::getContainer();
            $mailer = new MailTemplate('com_privacy.notification.remove', $this->mailLanguage($email),
                $container->get(MailerFactoryInterface::class)->createMailer(),
                $container->get(LanguageFactoryInterface::class), $db);
            $url = rtrim(Uri::root(), '/') . '/' . \Advans\Plugin\System\PrivacyRequests\Extension\PrivacyRequests::ROUTE;
            $mailer->addTemplateData(['sitename' => $this->app->get('sitename'), 'url' => Uri::root(),
                'tokenurl' => $url . '&confirm_token=' . rawurlencode($token), 'formurl' => $url, 'token' => $token]);
            $mailer->addRecipient($email);
            if ($mailer->send() === false) {
                throw new \RuntimeException('Core mail delivery returned false.');
            }
        } catch (\Throwable $error) {
            $deliveryFailed = true;
            \Joomla\CMS\Log\Log::add('Privacy receipt retained; confirmation delivery failed (' . get_class($error) . ').',
                \Joomla\CMS\Log\Log::ERROR, 'plg_system_privacyrequests');
        }
        // A slower, superseded delivery must not overwrite the latest token's result.
        $db->setQuery("UPDATE $table SET delivery_failed = " . ($deliveryFailed ? 1 : 0)
            . " WHERE request_id = $id AND EXISTS (SELECT 1 FROM " . $db->quoteName('#__privacy_requests')
            . " WHERE id = $id AND confirm_token = " . $db->quote($data['confirm_token']) . ')')->execute();
        if (!$renew || $deliveryFailed) {
            try {
                $this->notifyAdministrators($deliveryFailed, $id, $email);
            } catch (\Throwable $error) {
                \Joomla\CMS\Log\Log::add('Privacy receipt administrator notification failed (' . get_class($error) . ').',
                    \Joomla\CMS\Log\Log::ERROR, 'plg_system_privacyrequests');
            }
        }

        return true;
    }

    public function confirm(string $email, string $token): string
    {
        $email = trim($email);
        $valid = self::validAddress($email) && preg_match('/^[A-Za-z0-9]{16,64}$/D', $token);
        $db = $this->db;
        $hash = $db->quote($this->admission->hash('subject', Admission::address($email)));
        $id = $valid ? (int) $db->setQuery('SELECT request_id FROM ' . $db->quoteName('#__privacy_guest_subjects')
            . " WHERE subject_hash = $hash")->loadResult() : 0;
        // Same expensive hash verification for unknown, used, malformed and valid tokens.
        $dummy = UserHelper::hashPassword(bin2hex(random_bytes(24)));
        $db->transactionStart();
        try {
            $row = $id > 0 ? $this->locked($id) : null;
            $candidate = $row !== null && (int) $row->status === 0 && $row->request_type === 'remove'
                && Admission::address($row->email) === Admission::address($email)
                && (string) $row->confirm_token !== '' && $row->confirm_token_created_at !== null;
            $matches = UserHelper::verifyPassword($token, $candidate ? $row->confirm_token : $dummy);
            if (!$candidate || !$matches) {
                $db->transactionCommit();

                return 'failed';
            }
            if (strtotime($row->confirm_token_created_at . ' UTC') + 86400 < time()) {
                // Do not call Core's expiry branch: the right/request does not expire
                // with its token. A new request submission renews this same receipt.
                $db->transactionCommit();

                return 'expired';
            }
            $factory = $this->app->bootComponent('com_privacy')->getMVCFactory();
            $this->loadCoreFormsAndLanguage();
            $model = $factory->createModel('Confirm', 'Site', ['ignore_request' => true]);
            $model->setMVCFactory(new RequestFactory($factory, $db, $email, $id, $row->confirm_token));
            $principal = new User();
            $principal->email = $email;
            $model->setCurrentUser($principal);
            self::$confirming = true;
            $ok = $model->confirmRequest(['confirm_token' => $token]);
            if ($ok !== true) {
                throw new \RuntimeException('Core did not confirm the privacy request.');
            }
            $db->transactionCommit();

            return 'confirmed';
        } catch (\Throwable $e) {
            $db->transactionRollback();
            throw $e;
        } finally {
            self::$confirming = false;
        }
    }

    private function locked(int $id): ?object
    {
        return $this->db->setQuery('SELECT * FROM ' . $this->db->quoteName('#__privacy_requests')
            . ' WHERE id = ' . $id . ' FOR UPDATE')->loadObject() ?: null;
    }

    private function mailLanguage(string $email): string
    {
        $default = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
        $default = in_array($default, ['de-DE', 'en-GB', 'fr-FR'], true) ? $default : 'en-GB';
        $language = $default;
        $rows = $this->db->setQuery('SELECT email, params FROM ' . $this->db->quoteName('#__users')
            . ' WHERE LOWER(email) = LOWER(' . $this->db->quote($email) . ')')->loadObjectList();
        foreach ($rows as $row) {
            if (Admission::address($row->email) === Admission::address($email)) {
                $language = (string) (new Registry($row->params))->get('language', $language);
                break;
            }
        }

        return in_array($language, ['de-DE', 'en-GB', 'fr-FR'], true) ? $language : $default;
    }

    private function notifyAdministrators(bool $failed, int $requestId, string $email): void
    {
        $db = $this->db;
        // Match Core notifySuperUsers' root groups and opt-in filters. Its string
        // arguments cannot translate per recipient; native save retains its prefs/mail.
        $asset = new \Joomla\CMS\Table\Asset($db);
        $rules = \Joomla\CMS\Access\Access::getAssetRules($asset->getRootId())->getData();
        $groups = isset($rules['core.admin']) ? array_keys(array_filter($rules['core.admin']->getData())) : [];
        if (!$groups) {
            throw new \RuntimeException('No native administrator notification groups are configured.');
        }
        $recipients = $db->setQuery('SELECT DISTINCT u.id,u.params FROM ' . $db->quoteName('#__users', 'u')
            . ' INNER JOIN ' . $db->quoteName('#__user_usergroup_map', 'm') . ' ON m.user_id=u.id'
            . ' WHERE u.block=0 AND u.sendEmail=1 AND m.group_id IN (' . implode(',', array_map('intval', $groups)) . ')')->loadObjectList();
        if (!$recipients) {
            throw new \RuntimeException('No native administrator notification recipient is available.');
        }
        $default = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
        $default = in_array($default, ['de-DE', 'en-GB', 'fr-FR'], true) ? $default : 'en-GB';
        $model = $this->app->bootComponent('com_messages')->getMVCFactory()->createModel('Message', 'Administrator');
        foreach ($recipients as $recipient) {
            $tag = (string) (new Registry($recipient->params))->get('admin_language', '');
            $tag = in_array($tag, ['de-DE', 'en-GB', 'fr-FR'], true) ? $tag : $default;
            $language = Factory::getContainer()->get(LanguageFactoryInterface::class)->createLanguage($tag);
            if (!$language->load('plg_system_privacyrequests', JPATH_PLUGINS . '/system/privacyrequests')
                || (!$failed && !$language->load('com_privacy', JPATH_ADMINISTRATOR))) {
                throw new \RuntimeException('Cannot load the native privacy notification language.');
            }
            $subject = $language->_($failed ? 'PLG_SYSTEM_PRIVACYREQUESTS_DELIVERY_SUBJECT'
                : 'COM_PRIVACY_ADMIN_NOTIFICATION_USER_CREATED_REQUEST_SUBJECT');
            $message = sprintf($language->_($failed ? 'PLG_SYSTEM_PRIVACYREQUESTS_DELIVERY_MESSAGE'
                : 'COM_PRIVACY_ADMIN_NOTIFICATION_USER_CREATED_REQUEST_MESSAGE'), $failed ? $requestId : $email);
            if (!$model->save(['user_id_from' => (int) $recipient->id, 'user_id_to' => (int) $recipient->id,
                'subject' => $subject, 'message' => $message])) {
                throw new \RuntimeException('Native administrator notification returned false.');
            }
        }
    }

    private function loadCoreFormsAndLanguage(): void
    {
        Form::addFormPath(JPATH_SITE . '/components/com_privacy/forms');
        $this->app->getLanguage()->load('com_privacy', JPATH_SITE);
        $this->app->getLanguage()->load('com_privacy', JPATH_ADMINISTRATOR);
    }
}
