<?php
/**
 * Installer script for com_appointments.
 *
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

require_once is_file(__DIR__ . '/advansinstaller/load.php')
    ? __DIR__ . '/advansinstaller/load.php'
    : dirname(__DIR__) . '/shared/installer/load.php';

use Joomla\CMS\Factory;
use Advans\Installer\InstallerScript;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Asset;
use Joomla\CMS\User\CurrentUserInterface;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;
use Advans\Component\Appointments\Administrator\Service\Team\EpochAnchor;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;

class Com_appointmentsInstallerScript extends InstallerScript
{
    protected $minimumJoomla = '5.4';
    protected $minimumPhp = '8.1';

    private bool $hasExistingAppointmentsTables = false;

    /**
     * The plugins this package bundles: group => [element, enabled on first install].
     * The team calendar plugins (CalDAV endpoint, key handling at sign-in) stay
     * disabled until an operator enables them: nothing is reachable by default.
     */
    private const BUNDLED_PLUGINS = [
        'task'    => ['appointments', true],
        'privacy' => ['appointments', true],
        'content' => ['appointmentsprivacy', true],
        'system'  => ['appointmentsdav', false],
        'user'    => ['appointments', false],
    ];

    private const APPOINTMENT_TABLES = [
        'privacy_cleanup',
        'privacy_outcomes',
        'eventtype_calendars',
        'auth_failures',
        'teamlog_head',
        'teamlog',
        'invitations',
        'device_calendars',
        'devices',
        'calendar_busy',
        'calendar_changes',
        'calendar_objects',
        'calendar_key_wraps',
        'calendar_grants',
        'calendar_keys',
        'calendars',
        'user_keys',
        'keyring',
        'ratelimit',
        'audit',
        'templates',
        'fields',
        'bookings',
        'blocked',
        'eventtypes',
        'hosts',
        'schedule_rules',
        'schedules',
    ];

    private const SCHEDULER_TASK_TYPES = [
        'plg_task_appointments.reminders',
        'plg_task_appointments.retention',
        'plg_task_appointments.teamcleanup',
        'plg_task_appointments.teamlog',
        'plg_task_appointments.teaminvitations',
        'plg_task_appointments.teamretention',
    ];

    private const RETAINED_ASSET_NAME = 'com_appointments.retained';

    /**
     * Hard requirement checks before the files are copied.
     *
     * @param   string  $type    install|update|discover_install
     * @param   mixed   $parent  The installer parent.
     *
     * @return  bool
     */
    public function preflight($type, $parent)
    {
        if ($type === 'uninstall') {
            return $this->prepareUninstall();
        }

        $language = Factory::getApplication()->getLanguage();
        $language->load('com_appointments', JPATH_ADMINISTRATOR);
        $language->load('com_appointments', __DIR__ . '/administrator', null, true);

        if (!parent::preflight($type, $parent)) {
            return false;
        }

        // The component ships its schema for the MySQL/MariaDB driver only, and
        // the booking table relies on a generated column to guarantee that one
        // slot can be held by one booking. On any other database server the
        // tables cannot be created and every query would fail, so refuse the
        // install with a clear message instead of leaving a half-installed
        // component behind. An update keeps whatever driver the existing tables
        // were created on.
        // Bookings are stored encrypted (team-calendars.md, 5.10), so libsodium is
        // required for every installation, not only for the internet calendars.
        if (!\function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt') || !\function_exists('sodium_crypto_box_seal')) {
            Factory::getApplication()->enqueueMessage(Text::_('COM_APPOINTMENTS_INSTALL_SODIUM_MISSING'), 'error');

            return false;
        }

        if ($type === 'install' || $type === 'discover_install') {
            $db         = Factory::getContainer()->get(DatabaseInterface::class);
            $serverType = method_exists($db, 'getServerType') ? $db->getServerType() : 'mysql';
            $prefix = $db->getPrefix() . 'appointments_';
            foreach ($db->getTableList() as $table) {
                if (str_starts_with($table, $prefix)) {
                    $this->hasExistingAppointmentsTables = true;
                    break;
                }
            }

            if ($serverType !== 'mysql') {
                Factory::getApplication()->enqueueMessage(
                    Text::sprintf('COM_APPOINTMENTS_INSTALL_UNSUPPORTED_DB', $serverType),
                    'error'
                );

                return false;
            }
        }

        if ($type === 'update') {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $this->createDbQuery($db)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__appointments_eventtypes', 'e'))
                ->leftJoin(
                    $db->quoteName('#__appointments_schedules', 's')
                    . ' ON ' . $db->quoteName('s.id') . ' = ' . $db->quoteName('e.schedule_id')
                )
                ->where($db->quoteName('s.id') . ' IS NULL');

            if ((int) $db->setQuery($query)->loadResult() > 0) {
                Factory::getApplication()->enqueueMessage(
                    Text::_('COM_APPOINTMENTS_INSTALL_ORPHAN_SCHEDULES'),
                    'error'
                );

                return false;
            }
        }

        if (($type === 'install' || $type === 'discover_install') && !$this->restoreRetainedAssets()) {
            return false;
        }

        return true;
    }

    protected function guardedFiles(string $type, object $parent): array
    {
        $root = JPATH_ADMINISTRATOR . '/components/com_appointments';
        $files = [
            $root . '/com_appointments.xml' => $this->removalCandidate($parent, 'Remove obsolete appointments manifest'),
        ];

        foreach (['en-GB', 'de-DE', 'fr-FR'] as $tag) {
            $files[$root . '/language/' . $tag . '/com_appointments.ini']
                = $parent->getParent()->getPath('source') . '/administrator/language/' . $tag . '/com_appointments.ini';
        }

        if ($type === 'install') {
            foreach (glob($root . '/data/epochs.php*') ?: [] as $file) {
                $files[$file] = $this->removalCandidate($parent, 'Remove obsolete calendar epoch anchor on fresh installation');
            }
        }

        return $files;
    }

    protected function extensionPostflight($type, $parent)
    {
        if ($type !== 'install' && $type !== 'update') {
            return true;
        }

        // A fresh installation has fresh tables, so an epoch anchor left behind by
        // an earlier installation would close the new calendars and users.
        if ($type === 'install') {
            if ($this->hasExistingAppointmentsTables) {
                $this->migrateLegacyEpochAnchor();
            } else {
                $this->removeEpochAnchor();
            }
        } elseif ($type === 'update') {
            $this->migrateLegacyEpochAnchor();
        }

        $this->ensureUpdateSite();
        $db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $db->setQuery('CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__appointments_privacy_outcomes')
            . ' (request_id INT UNSIGNED NOT NULL PRIMARY KEY, complete TINYINT NOT NULL DEFAULT 0)'
            . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();
        $db->setQuery('CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__appointments_privacy_cleanup')
            . ' (request_id INT UNSIGNED NOT NULL, booking_id INT UNSIGNED NOT NULL,'
            . ' cleanup_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, guest TINYINT NOT NULL, payload TEXT NOT NULL,'
            . ' PRIMARY KEY (request_id, booking_id, cleanup_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();
        $this->seedMailTemplates($parent);
        $this->installBundledPlugins($parent);
        $this->initialiseLegacyFeedSetting($type);
        $this->encryptExistingBookings($type);

        $app  = Factory::getApplication();
        $lang = $app->getLanguage();
        $lang->load('com_appointments', JPATH_ADMINISTRATOR);
        $this->ensureSchedulerTasks($type === 'install' && !$this->hasExistingAppointmentsTables);
        $this->removeLegacyManifest();
        $this->ensureTeamGroups();

        // An update only gets a short confirmation; the first installation shows
        // where to find the component and how to begin.
        if ($type === 'update') {
            $manifest = method_exists($parent, 'getManifest') ? $parent->getManifest() : null;
            $version  = $manifest instanceof \SimpleXMLElement ? (string) $manifest->version : '';

            $app->enqueueMessage(
                Text::sprintf('COM_APPOINTMENTS_POSTINSTALL_UPDATED', htmlspecialchars($version)),
                'message'
            );

            return true;
        }

        $box = 'padding:16px 20px;margin:16px 0;border-radius:4px;border-left:4px solid;background:#eff6ff;border-color:#2563eb';

        $message  = '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:860px">';
        $message .= '<h2 style="margin-bottom:16px">' . Text::_('COM_APPOINTMENTS_POSTINSTALL_TITLE') . '</h2>';
        $message .= '<div style="' . $box . '">';
        $message .= '<p>' . Text::_('COM_APPOINTMENTS_POSTINSTALL_DESC') . '</p>';
        $message .= '</div>';
        $message .= '<p style="margin-top:12px;color:#6b7280;font-size:13px">' . Text::_('COM_APPOINTMENTS_POSTINSTALL_DOCS') . '</p>';
        $message .= '</div>';

        $app->enqueueMessage($message, 'message');

        return true;
    }

    private function prepareUninstall(): bool
    {
        $app = Factory::getApplication();
        $app->getLanguage()->load('com_appointments', JPATH_ADMINISTRATOR);
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $params = $this->componentParams($db, 'com_appointments') ?? [];

        if (($params['uninstall_data_action'] ?? '') === 'delete') {
            return true;
        }

        if ($this->hasRetainedComponentAsset($db)) {
            $app->enqueueMessage(Text::_('COM_APPOINTMENTS_UNINSTALL_RETAINED_ACL_CONFLICT'), 'error');

            return false;
        }

        // Joomla ignores failures in uninstall(), but honours preflight refusals.
        $this->migrateLegacyEpochAnchor();

        return $this->retainComponentAsset($db);
    }

    public function uninstall($parent)
    {
        $app = Factory::getApplication();
        $app->getLanguage()->load('com_appointments', JPATH_ADMINISTRATOR);
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $params = $this->componentParams($db, 'com_appointments') ?? [];
        $deleteData = ($params['uninstall_data_action'] ?? '') === 'delete';

        foreach (self::BUNDLED_PLUGINS as $group => [$element]) {
            $query = $this->createDbQuery($db)->select($db->quoteName('extension_id'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote($group))
                ->where($db->quoteName('element') . ' = ' . $db->quote($element));
            $id = (int) $db->setQuery($query)->loadResult();
            if ($id > 0) {
                foreach (['#__schemas', '#__extensions'] as $table) {
                    $db->setQuery($this->createDbQuery($db)->delete($db->quoteName($table))
                        ->where($db->quoteName('extension_id') . ' = ' . $id))->execute();
                }
            }
            $folder = JPATH_PLUGINS . '/' . $group . '/' . $element;
            if (is_dir($folder) && !Folder::delete($folder)) {
                throw new \RuntimeException('The bundled appointments plugin could not be removed.');
            }
            foreach (['en-GB', 'de-DE', 'fr-FR'] as $tag) {
                foreach (['ini', 'sys.ini'] as $suffix) {
                    $file = JPATH_ADMINISTRATOR . '/language/' . $tag . '/plg_' . $group . '_' . $element . '.' . $suffix;
                    if (is_file($file) && !File::delete($file)) {
                        throw new \RuntimeException('The bundled appointments plugin language could not be removed.');
                    }
                }
            }
        }
        $db->setQuery($this->createDbQuery($db)->delete($db->quoteName('#__scheduler_tasks'))
            ->whereIn($db->quoteName('type'), self::SCHEDULER_TASK_TYPES, ParameterType::STRING))->execute();

        if ($deleteData) {
            foreach (self::APPOINTMENT_TABLES as $table) {
                $db->setQuery('DROP TABLE IF EXISTS ' . $db->quoteName('#__appointments_' . $table))->execute();
            }
            $this->removeEpochAnchor();
        } else {
            $app->enqueueMessage(Text::_('COM_APPOINTMENTS_UNINSTALL_DATA_RETAINED'), 'warning');
        }

        return true;
    }

    private function hasRetainedComponentAsset(DatabaseInterface $db): bool
    {
        $query = $this->createDbQuery($db)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__assets'))
            ->where($db->quoteName('name') . ' = ' . $db->quote(self::RETAINED_ASSET_NAME));

        return (int) $db->setQuery($query)->loadResult() > 0;
    }

    /**
     * Joomla removes the component's asset subtree during uninstall, including per-event-type ACL.
     * Rename its root so Joomla leaves the subtree intact; preflight restores it on reinstall.
     */
    private function retainComponentAsset(DatabaseInterface $db): bool
    {
        $query = $this->createDbQuery($db)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__assets'))
            ->where($db->quoteName('name') . ' = ' . $db->quote('com_appointments'));
        $assetId = (int) $db->setQuery($query)->loadResult();

        if ($assetId === 0) {
            return true;
        }

        $query = $this->createDbQuery($db)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__assets'))
            ->where($db->quoteName('name') . ' = ' . $db->quote(self::RETAINED_ASSET_NAME));

        if ((int) $db->setQuery($query)->loadResult() > 0) {
            Factory::getApplication()->enqueueMessage(
                Text::_('COM_APPOINTMENTS_UNINSTALL_RETAINED_ACL_CONFLICT'),
                'error'
            );

            return false;
        }

        $query = $this->createDbQuery($db)
            ->update($db->quoteName('#__assets'))
            ->set($db->quoteName('name') . ' = ' . $db->quote(self::RETAINED_ASSET_NAME))
            ->where($db->quoteName('id') . ' = ' . $assetId);
        $db->setQuery($query)->execute();

        return true;
    }

    /**
     * Restore retained ACL before Joomla finalises a fresh component installation.
     */
    private function restoreRetainedAssets(): bool
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $this->createDbQuery($db)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__assets'))
            ->where($db->quoteName('name') . ' = ' . $db->quote(self::RETAINED_ASSET_NAME));
        $assetId = (int) $db->setQuery($query)->loadResult();

        if ($assetId === 0) {
            return true;
        }

        if (!$this->hasExistingAppointmentsTables) {
            $asset = new Asset($db);

            if (!$asset->load($assetId) || !$asset->delete()) {
                throw new \RuntimeException('Retained Appointments permissions could not be removed.');
            }

            return true;
        }

        $query = $this->createDbQuery($db)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__assets'))
            ->where($db->quoteName('name') . ' = ' . $db->quote('com_appointments'));

        if ((int) $db->setQuery($query)->loadResult() > 0) {
            Factory::getApplication()->enqueueMessage(
                Text::_('COM_APPOINTMENTS_INSTALL_RETAINED_ACL_CONFLICT'),
                'error'
            );

            return false;
        }

        $query = $this->createDbQuery($db)
            ->update($db->quoteName('#__assets'))
            ->set($db->quoteName('name') . ' = ' . $db->quote('com_appointments'))
            ->where($db->quoteName('id') . ' = ' . $assetId);
        $db->setQuery($query)->execute();

        return true;
    }

    /**
     * The replay-protection anchor of the internet calendars belongs to the
     * tables of one installation (team-calendars.md, 5.11).
     */
    private function removeEpochAnchor(): void
    {
        foreach ([$this->epochAnchorPath(), $this->legacyEpochAnchorPath()] as $path) {
            if (!is_file($path) && (glob($path . '.*.tmp') ?: []) === []) {
                continue;
            }

            try {
                $this->withEpochAnchorLocks([$path], function () use ($path): void {
                    foreach (glob($path . '.*.tmp') ?: [] as $temporary) {
                        if (is_file($temporary) && !File::delete($temporary)) {
                            Factory::getApplication()->enqueueMessage(Text::_('COM_APPOINTMENTS_INSTALL_ANCHOR_NOT_REMOVED'), 'warning');
                        }
                    }

                    if (is_file($path) && !File::delete($path)) {
                        Factory::getApplication()->enqueueMessage(Text::_('COM_APPOINTMENTS_INSTALL_ANCHOR_NOT_REMOVED'), 'warning');
                    }
                });
            } catch (\Throwable $e) {
                Factory::getApplication()->enqueueMessage(Text::_('COM_APPOINTMENTS_INSTALL_ANCHOR_NOT_REMOVED'), 'warning');
            }
        }
    }

    private function epochAnchorPath(): string
    {
        return JPATH_ADMINISTRATOR . '/components/com_appointments-data/epochs.php';
    }

    private function legacyEpochAnchorPath(): string
    {
        return JPATH_ADMINISTRATOR . '/components/com_appointments/data/epochs.php';
    }

    private function migrateLegacyEpochAnchor(): void
    {
        $source = $this->legacyEpochAnchorPath();

        if (!is_file($source)) {
            return;
        }

        Factory::getApplication()->bootComponent('com_appointments');
        (new EpochAnchor(TeamServices::fromApplication()))->migrateLegacy();
    }

    /**
     * Lock anchor paths in the same order used by migrations to avoid deadlocks.
     *
     * @template T
     *
     * @param   string[]       $paths
     * @param   callable():T   $operation
     *
     * @return  T
     */
    private function withEpochAnchorLocks(array $paths, callable $operation): mixed
    {
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);
        $handles = [];

        try {
            foreach ($paths as $path) {
                $folder = dirname($path);

                if (!is_dir($folder) && !Folder::create($folder)) {
                    throw new \RuntimeException('The Appointments epoch anchor folder could not be created.');
                }

                $handle = @fopen($path . '.lock', 'c');

                if ($handle === false || !flock($handle, LOCK_EX)) {
                    if ($handle !== false) {
                        fclose($handle);
                    }

                    throw new \RuntimeException('The Appointments epoch anchor could not be locked.');
                }

                $handles[] = $handle;
            }

            return $operation();
        } finally {
            foreach (array_reverse($handles) as $handle) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    private function installBundledPlugins($parent): void
    {
        $source = $parent->getParent()->getPath('source');
        $manifest = $parent->getManifest();
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        foreach ($manifest->plugins->plugin as $entry) {
            $group = (string) $entry['group'];
            $element = (string) $entry['element'];
            $folder = (string) $entry['folder'];
            if (!isset(self::BUNDLED_PLUGINS[$group]) || $element !== self::BUNDLED_PLUGINS[$group][0]
                || $folder !== 'plugins/' . $group . '/' . $element
                || !is_file($source . '/' . $folder . '/' . $element . '.xml')) {
                throw new \RuntimeException('The bundled appointments plugin package is invalid.');
            }
            $query = $this->createDbQuery($db)->select($db->quoteName('extension_id'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote($group))
                ->where($db->quoteName('element') . ' = ' . $db->quote($element));
            $existed = (int) $db->setQuery($query)->loadResult() > 0;
            $installer = new Installer();
            $installer->setDatabase($db);
            if (!$installer->install($source . '/' . $folder)) {
                throw new \RuntimeException('The bundled appointments plugin could not be installed.');
            }
            if (!$existed && self::BUNDLED_PLUGINS[$group][1]) {
                $db->setQuery($this->createDbQuery($db)->update($db->quoteName('#__extensions'))
                    ->set($db->quoteName('enabled') . ' = 1')
                    ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                    ->where($db->quoteName('folder') . ' = ' . $db->quote($group))
                    ->where($db->quoteName('element') . ' = ' . $db->quote($element)))->execute();
            }
        }
    }

    /**

     * The token feeds are a secret address and are replaced by the team calendars
     * (removed in 2.0.0). A new installation starts with them switched off, an
     * existing installation keeps them on until the operator switches them off.
     * An explicit choice of the operator is never overwritten.
     */
    private function initialiseLegacyFeedSetting(string $type): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $element = 'com_appointments';
        $query = $this->createDbQuery($db)
            ->select([$db->quoteName('extension_id'), $db->quoteName('params')])
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = :element')
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->bind(':element', $element);
        $row = $db->setQuery($query)->loadObject();

        if (!$row) {
            return;
        }

        $params = json_decode((string) $row->params, true);
        $params = \is_array($params) ? $params : [];

        if (\array_key_exists('legacy_feeds', $params)) {
            return;
        }

        $params['legacy_feeds'] = $type === 'install' ? '0' : '1';
        $json = json_encode($params);
        $id = (int) $row->extension_id;
        $query = $this->createDbQuery($db)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = :params')
            ->where($db->quoteName('extension_id') . ' = :id')
            ->bind(':params', $json)
            ->bind(':id', $id, ParameterType::INTEGER);
        $db->setQuery($query)->execute();
    }

    /**
     * Encrypt the personal fields of existing bookings (team-calendars.md, 5.10)
     * in batches during the installation itself (booking tables are small); what
     * is left after the time budget is finished at the next administrator visit
     * of the component, never depending on the Scheduler.
     * Idempotent, and a no-op until the booking record class exists.
     */
    private function encryptExistingBookings(string $type): void
    {
        $class = '\\Advans\\Component\\Appointments\\Administrator\\Service\\BookingRecords';

        if (!class_exists($class) || !method_exists($class, 'migrate')) {
            return;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $keys = new \Advans\Component\Appointments\Administrator\Helper\KeyProvider((string) Factory::getApplication()->get('secret', ''));
        $until = microtime(true) + 120;

        try {
            // An update starts the time-limited migration window: only while its
            // keyed token is valid may clear text be read and encrypted.
            if ($type === 'update' && method_exists($class, 'begin')) {
                $class::begin($db, $keys);
            }

            while (microtime(true) < $until && $class::migrate($db, $keys, 200) > 0) {
                // Next batch.
            }
        } catch (\Throwable $e) {
            $app = Factory::getApplication();
            $app->getLanguage()->load('com_appointments', JPATH_ADMINISTRATOR);
            $app->enqueueMessage(Text::_('COM_APPOINTMENTS_INSTALL_BOOKING_ENCRYPTION_PENDING'), 'warning');
        }
    }

    /**
     * The user groups of the team calendars: one for team members, one for
     * external users, both below the registration group (normally «Registered»)
     * and without any further permission, plus a view level holding both for the
     * «My calendars» menu item. Membership means «may receive calendar grants and
     * must use a second factor»; the role per calendar is in the grants table.
     *
     * Created only when the option `team_create_groups` is not switched off and
     * the configured group is missing. Both groups are added to Joomla's own
     * «enforce multi-factor authentication» list (com_users, forceMFAUserGroups);
     * nothing is ever removed from that list. Uninstalling leaves the groups,
     * because they may hold accounts.
     */
    private function ensureTeamGroups(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $params = $this->componentParams($db, 'com_appointments');

        if ($params === null || (string) ($params['team_create_groups'] ?? '1') === '0') {
            return;
        }

        $users = $this->componentParams($db, 'com_users') ?? [];
        $parent = (int) ($users['new_usertype'] ?? 2) ?: 2;
        $internal = array_values(array_filter(array_map('intval', (array) ($params['team_groups'] ?? []))));
        $external = (int) ($params['external_group'] ?? 0);

        if ($internal === [] || !$this->groupExists($db, $internal[0])) {
            $internal = [$this->findOrCreateGroup($db, $parent, $this->text('COM_APPOINTMENTS_TEAM_GROUP_INTERNAL', 'Internet calendars internal'))];
        }

        if ($external <= 0 || !$this->groupExists($db, $external)) {
            $external = $this->findOrCreateGroup($db, $parent, $this->text('COM_APPOINTMENTS_TEAM_GROUP_EXTERNAL', 'Internet calendars external'));
        }

        $level = (int) ($params['team_viewlevel'] ?? 0);

        if ($level <= 0 || !$this->viewLevelExists($db, $level)) {
            $level = $this->findOrCreateViewLevel($db, $this->text('COM_APPOINTMENTS_TEAM_VIEWLEVEL', 'Internet calendars'), array_merge($internal, [$external]));
        }

        $params['team_groups'] = $internal;
        $params['external_group'] = $external;
        $params['team_viewlevel'] = $level;
        $this->saveComponentParams($db, 'com_appointments', $params);

        $forced = array_values(array_unique(array_map('intval', (array) ($users['forceMFAUserGroups'] ?? []))));
        $missing = array_diff(array_merge($internal, [$external]), $forced);

        if ($missing !== []) {
            $users['forceMFAUserGroups'] = array_map('strval', array_values(array_merge($forced, $missing)));
            $this->saveComponentParams($db, 'com_users', $users);
        }
    }

    private function text(string $key, string $fallback): string
    {
        $text = Text::_($key);

        return $text === $key ? $fallback : $text;
    }

    private function groupExists(DatabaseInterface $db, int $id): bool
    {
        $query = $this->createDbQuery($db)->select('COUNT(*)')->from($db->quoteName('#__usergroups'))
            ->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);

        return (int) $db->setQuery($query)->loadResult() === 1;
    }

    private function findOrCreateGroup(DatabaseInterface $db, int $parent, string $title): int
    {
        $query = $this->createDbQuery($db)->select($db->quoteName('id'))->from($db->quoteName('#__usergroups'))
            ->where($db->quoteName('parent_id') . ' = :parent')->where($db->quoteName('title') . ' = :title')
            ->bind(':parent', $parent, ParameterType::INTEGER)->bind(':title', $title);
        $id = (int) $db->setQuery($query)->loadResult();

        if ($id > 0) {
            return $id;
        }

        $table = new \Joomla\CMS\Table\Usergroup($db);

        if (!$table->save(['parent_id' => $parent, 'title' => $title])) {
            throw new \RuntimeException('The team calendar user group could not be created.');
        }

        return (int) $table->id;
    }

    private function viewLevelExists(DatabaseInterface $db, int $id): bool
    {
        $query = $this->createDbQuery($db)->select('COUNT(*)')->from($db->quoteName('#__viewlevels'))
            ->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);

        return (int) $db->setQuery($query)->loadResult() === 1;
    }

    /**
     * @param   int[]  $groups
     */
    private function findOrCreateViewLevel(DatabaseInterface $db, string $title, array $groups): int
    {
        $query = $this->createDbQuery($db)->select($db->quoteName('id'))->from($db->quoteName('#__viewlevels'))
            ->where($db->quoteName('title') . ' = :title')->bind(':title', $title);
        $id = (int) $db->setQuery($query)->loadResult();

        if ($id > 0) {
            return $id;
        }

        $table = new \Joomla\CMS\Table\ViewLevel($db);

        if (!$table->save(['title' => $title, 'rules' => json_encode(array_values(array_map('intval', $groups)))])) {
            throw new \RuntimeException('The team calendar access level could not be created.');
        }

        return (int) $table->id;
    }

    /**
     * @return  array<string, mixed>|null  Null when the component is not registered.
     */
    private function componentParams(DatabaseInterface $db, string $element): ?array
    {
        $query = $this->createDbQuery($db)->select($db->quoteName('params'))->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = :element')->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->bind(':element', $element);
        $raw = $db->setQuery($query)->loadResult();

        if ($raw === null) {
            return null;
        }

        $params = json_decode((string) $raw, true);

        return \is_array($params) ? $params : [];
    }

    /**
     * @param   array<string, mixed>  $params
     */
    private function saveComponentParams(DatabaseInterface $db, string $element, array $params): void
    {
        $json = json_encode($params);
        $query = $this->createDbQuery($db)->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = :params')
            ->where($db->quoteName('element') . ' = :element')->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->bind(':params', $json)->bind(':element', $element);
        $db->setQuery($query)->execute();
    }

    /**
     * Creates the two Scheduler tasks of the bundled task plugin through the Scheduler's own
     * task model, the way Joomla's core update creates the tasks of its own plugins. A routine
     * gets a task only when no task of its type exists at all, in any state: a task the operator
     * disabled or trashed is never enabled or created again.
     *
     * A first installation creates them enabled. An update of a site that had no such task
     * creates them disabled and says so: the retention task would otherwise start anonymising
     * the site's existing bookings, and the reminder task start writing to their invitees,
     * without the operator having decided it. The operator enables them under
     * System > Scheduled Tasks. A failure never fails the installation; it is reported, so the
     * operator can create the task by hand there.
     */
    private function ensureSchedulerTasks(bool $enabled): void
    {
        $app = Factory::getApplication();
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $lang = $app->getLanguage();
        $lang->load('plg_task_appointments', JPATH_ADMINISTRATOR)
            || $lang->load('plg_task_appointments', JPATH_PLUGINS . '/task/appointments');
        $routines = [
            'plg_task_appointments.reminders' => ['PLG_TASK_APPOINTMENTS_REMINDERS_TITLE', ['rule-type' => 'interval-minutes', 'interval-minutes' => 5]],
            'plg_task_appointments.retention' => ['PLG_TASK_APPOINTMENTS_RETENTION_TITLE', ['rule-type' => 'interval-hours', 'interval-hours' => 24]],
        ];

        foreach ($routines as $type => [$titleKey, $rules]) {
            try {
                $query = $this->createDbQuery($db)->select('COUNT(*)')->from($db->quoteName('#__scheduler_tasks'))
                    ->where($db->quoteName('type') . ' = :type')->bind(':type', $type);

                if ((int) $db->setQuery($query)->loadResult() > 0) {
                    continue;
                }

                $model = $app->bootComponent('com_scheduler')->getMVCFactory()
                    ->createModel('Task', 'Administrator', ['ignore_request' => true]);
                // From the CLI there is no signed-in user: the task is then created by the guest
                // user (id 0), set explicitly so the model and its table never have to guess.
                $identity = $app->getIdentity()
                    ?? Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById(0);
                if ($model instanceof CurrentUserInterface) {
                    $model->setCurrentUser($identity);
                }
                $saved = $model && $model->save([
                    'title' => Text::_($titleKey),
                    'type' => $type,
                    'execution_rules' => $rules + ['exec-day' => gmdate('d'), 'exec-time' => gmdate('H:i')],
                    'state' => $enabled ? 1 : 0,
                    'params' => ['batch_size' => 100],
                    'created_by' => (int) $identity->id,
                ]);

                if (!$saved) {
                    throw new \RuntimeException('The Scheduler task could not be saved.');
                }

                if (!$enabled) {
                    $app->enqueueMessage(Text::sprintf('COM_APPOINTMENTS_INSTALL_TASK_DISABLED', Text::_($titleKey)), 'notice');
                }
            } catch (\Throwable $e) {
                $app->enqueueMessage(Text::sprintf('COM_APPOINTMENTS_INSTALL_TASK_FAILED', Text::_($titleKey)), 'warning');
            }
        }
    }

    /**
     * Earlier versions named the manifest com_appointments.xml. Joomla's database check
     * (System > Maintenance > Database) only reads appointments.xml and otherwise reports
     * the component as not up to date. An update leaves the old file behind, and Joomla
     * could still pick that older manifest when the component is uninstalled, so it is
     * removed once the current manifest is in place.
     */
    private function removeLegacyManifest(): void
    {
        $folder = JPATH_ADMINISTRATOR . '/components/com_appointments';

        if (!is_file($folder . '/appointments.xml') || !is_file($folder . '/com_appointments.xml')) {
            return;
        }

        try {
            File::delete($folder . '/com_appointments.xml');
        } catch (\Throwable $e) {
            Factory::getApplication()->enqueueMessage(
                Text::sprintf('COM_APPOINTMENTS_INSTALL_LEGACY_MANIFEST', 'com_appointments.xml'),
                'warning'
            );
        }
    }

    /**
     * Register the update site of this component, so Joomla offers its updates
     * without the administrator adding a URL by hand.
     */
    private function ensureUpdateSite(): void
    {
        $db        = Factory::getContainer()->get(DatabaseInterface::class);
        $updateUrl = 'https://raw.githubusercontent.com/Advans-IT-Solutions-GmbH/Joomla/main/com_appointments/updates/update.xml';
        $element   = 'com_appointments';

        $query = $this->createDbQuery($db)
            ->select($db->quoteName('extension_id'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = :element')
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->bind(':element', $element);
        $db->setQuery($query);
        $extensionId = (int) $db->loadResult();

        if (!$extensionId) {
            return;
        }

        $query = $this->createDbQuery($db)
            ->select($db->quoteName('update_site_id'))
            ->from($db->quoteName('#__update_sites'))
            ->where($db->quoteName('location') . ' = :url')
            ->bind(':url', $updateUrl);
        $db->setQuery($query);
        $siteId = (int) $db->loadResult();

        if ($siteId) {
            $this->linkUpdateSite($db, $siteId, $extensionId);

            return;
        }

        $name  = 'Appointments Updates';
        $sType = 'extension';
        $query = $this->createDbQuery($db)
            ->insert($db->quoteName('#__update_sites'))
            ->columns([
                $db->quoteName('name'),
                $db->quoteName('type'),
                $db->quoteName('location'),
                $db->quoteName('enabled'),
            ])
            ->values(':name, :type, :url, 1')
            ->bind(':name', $name)
            ->bind(':type', $sType)
            ->bind(':url', $updateUrl);
        $db->setQuery($query);
        $db->execute();

        $this->linkUpdateSite($db, (int) $db->insertid(), $extensionId);
    }

    private function seedMailTemplates($parent): void
    {
        $source = $parent->getParent()->getPath('source');
        require_once JPATH_ADMINISTRATOR . '/components/com_appointments/src/Helper/DefaultTemplates.php';
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        foreach (['en-GB', 'de-DE', 'fr-FR'] as $tag) {
            $file = $source . '/administrator/language/' . $tag . '/com_appointments.ini';
            // Recipient languages must work even without the corresponding Joomla language pack.
            $localLanguage = JPATH_ADMINISTRATOR . '/components/com_appointments/language/' . $tag;
            if (!Folder::create($localLanguage) || !File::copy($file, $localLanguage . '/com_appointments.ini')) {
                throw new \RuntimeException('The appointment notification language could not be installed.');
            }
            $strings = parse_ini_file($file, false, INI_SCANNER_RAW);

            if (!is_array($strings)) {
                throw new \RuntimeException('The appointment template language source is unreadable.');
            }

            $defaults = \Advans\Component\Appointments\Administrator\Helper\DefaultTemplates::class;
            // all() adds the internet calendar mails (TEAM_KEYS) to the booking mails.
            $purposes = method_exists($defaults, 'all') ? $defaults::all() : $defaults::KEYS;

            foreach ($purposes as $purpose => [$subjectKey, $bodyKey]) {
                if (!isset($strings[$subjectKey], $strings[$bodyKey])) {
                    throw new \RuntimeException('A required appointment template translation is missing.');
                }

                $subject = $strings[$subjectKey];
                $body = str_replace('\\n', "\n", $strings[$bodyKey]);
                $sql = 'INSERT INTO ' . $db->quoteName('#__appointments_templates')
                    . ' (' . implode(',', $db->quoteName(['eventtype_id', 'purpose', 'language', 'subject', 'body', 'published'])) . ')'
                    . ' VALUES (0,' . $db->quote($purpose) . ',' . $db->quote($tag) . ',' . $db->quote($subject) . ',' . $db->quote($body) . ',1)'
                    . ' ON DUPLICATE KEY UPDATE ' . $db->quoteName('id') . ' = ' . $db->quoteName('id');
                $db->setQuery($sql)->execute();
            }
        }
    }

    /**
     * Connect an update site with the component's extension row once.
     */
    private function linkUpdateSite(DatabaseInterface $db, int $siteId, int $extensionId): void
    {
        if ($siteId <= 0) {
            return;
        }

        $query = $this->createDbQuery($db)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__update_sites_extensions'))
            ->where($db->quoteName('update_site_id') . ' = :siteId')
            ->where($db->quoteName('extension_id') . ' = :extId')
            ->bind(':siteId', $siteId, ParameterType::INTEGER)
            ->bind(':extId', $extensionId, ParameterType::INTEGER);
        $db->setQuery($query);

        if ((int) $db->loadResult() > 0) {
            return;
        }

        $query = $this->createDbQuery($db)
            ->insert($db->quoteName('#__update_sites_extensions'))
            ->columns([$db->quoteName('update_site_id'), $db->quoteName('extension_id')])
            ->values(':siteId, :extId')
            ->bind(':siteId', $siteId, ParameterType::INTEGER)
            ->bind(':extId', $extensionId, ParameterType::INTEGER);
        $db->setQuery($query);
        $db->execute();
    }

    /**
     * Creates a query object compatible with Joomla 5 (getQuery) and 6 (createQuery).
     */
    private function createDbQuery(DatabaseInterface $db): \Joomla\Database\QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
