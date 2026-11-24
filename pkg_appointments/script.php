<?php
/**
 * @package     Appointments Package
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

require_once is_file(__DIR__ . '/advansinstaller/load.php')
    ? __DIR__ . '/advansinstaller/load.php'
    : dirname(__DIR__) . '/shared/installer/load.php';
if (!class_exists(\Advans\Installer\AppointmentsConflict\V2\Guard::class, false)) {
    require_once is_file(__DIR__ . '/advansinstaller/AppointmentsConflict.php')
        ? __DIR__ . '/advansinstaller/AppointmentsConflict.php'
        : dirname(__DIR__) . '/shared/installer/AppointmentsConflict.php';
}

use Advans\Installer\AppointmentsConflict\V2\Guard as AppointmentsConflictGuard;
use Advans\Installer\V3\Destinations;
use Advans\Installer\V3\InstallerScript;
use Advans\Installer\V3\Knowledge;
use Advans\Installer\V3\PackageChildren;
use Advans\Installer\V3\State;
use Advans\Installer\V3\SystemCheck;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\User\CurrentUserInterface;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;

/**
 * Installer script of the Appointments package: the component, the administrator dashboard module
 * and the six plugins, installed, updated and removed together.
 *
 * Joomla installs every bundled extension with its own adapter, the component first, so that a
 * refusal of the component (missing sodium, an unsupported database, inconsistent schedules) stops
 * the package before anything else changed. Before that, this script decides for all of them:
 *
 *  - the file review of Appointments (Appointments Recovery, state below
 *    advans-installer-state/com_appointments) and the shared file guard cover the files of every
 *    bundled extension; their decisions are handed to the component, which installs through them;
 *  - the full-site backup recommendation is shown once for the package, also when the package is
 *    installed over the standalone component (and its plugins) of an earlier version;
 *  - a bundled extension that is installed in a newer version stops the installation.
 *
 * Installed standalone extensions are adopted as they are: Joomla updates each one in place, so
 * its row, parameters, data and enabled state stay; this script assigns it to the package, moves
 * it onto the package's update channel and enables only extensions that were not installed before.
 */
class Pkg_AppointmentsInstallerScript extends InstallerScript
{
    protected $minimumJoomla = '5.4';
    protected $minimumPhp = '8.1';

    /**
     * The bundled extensions as Joomla stores them ([type, element, folder, client_id]) and whether
     * one is enabled when it is installed the first time. The team calendar plugins (CalDAV
     * endpoint, key handling at sign-in) stay disabled until an operator enables them.
     */
    private const CHILDREN = [
        [['component', 'com_appointments', '', 1], false],
        [['module', 'mod_appointments', '', 1], false],
        [['plugin', 'appointments', 'task', 0], true],
        [['plugin', 'appointments', 'privacy', 0], true],
        [['plugin', 'appointmentsprivacy', 'content', 0], true],
        [['plugin', 'appointmentsdav', 'system', 0], false],
        [['plugin', 'appointmentsattribution', 'system', 0], true],
        [['plugin', 'appointments', 'user', 0], false],
    ];

    private const COMPONENT = 'component:com_appointments::1';
    private const MODULE = 'module:mod_appointments::1';
    private const RECOVERY_OPTION = 'com_appointmentsrecovery';
    private const RETAINED_ASSET_NAME = 'com_appointments.retained';

    /**
     * Extensions still to be enabled or published after an interrupted first installation: an
     * attempt that installed them and stopped before this postflight left them as Joomla installs
     * them, and the next attempt finds them installed. Below the state of this package; one day.
     */
    private const PENDING_FILE = '/advans-installer-state/pkg_appointments/enable-pending.php';
    private const PENDING_SECONDS = 86400;

    /** @var list<string> Keys of the extensions this run enables (plugins) or publishes (module). */
    private array $pending = [];

    /** Whether this package is installing its extensions right now (read by the component). */
    private static bool $installing = false;

    /** @var array<string, array{manifest: \SimpleXMLElement, file: string, directory: string}> */
    private array $children = [];

    /** @var array<string, array|null> Installed rows of the bundled extensions before this run. */
    private array $before = [];

    /** @var resource|null */
    private $conflictLock = null;
    private array $conflictReview = [];

    /**
     * True between a successful preflight of this package and its postflight: the bundled component
     * then installs through the decisions of the package and refuses any other installation.
     */
    public static function installing(): bool
    {
        return self::$installing;
    }

    public function preflight($type, $parent)
    {
        if ($type === 'uninstall') {
            return $this->prepareUninstall();
        }

        if (!in_array($type, ['install', 'update'], true)) {
            return parent::preflight($type, $parent);
        }

        $accepted = false;

        try {
            $accepted = $this->preflightPackage($type, $parent);

            return $accepted;
        } finally {
            if (!$accepted) {
                $this->releaseConflictLock();
            } else {
                self::$installing = true;
            }
        }
    }

    public function postflight($type, $parent)
    {
        try {
            return parent::postflight($type, $parent);
        } finally {
            self::$installing = false;
            $this->releaseConflictLock();
        }
    }

    private function preflightPackage(string $type, object $parent): bool
    {
        $source = (string) $parent->getParent()->getPath('source');
        $this->loadLanguage($source);
        SystemCheck::loadLanguage();
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        try {
            $this->children = PackageChildren::extract($parent->getManifest(), $source);
        } catch (\RuntimeException $error) {
            $this->reportConflictError($error);

            return false;
        }

        $this->before = PackageChildren::snapshot($db, array_map(static fn(array $child): array => $child[0], self::CHILDREN));
        $bundled = [];

        foreach ($this->children as $key => $child) {
            $bundled[$key] = (string) $child['manifest']->version;
        }

        $newer = PackageChildren::newer($this->before, $bundled);

        if ($newer !== []) {
            foreach ($newer as $entry) {
                Factory::getApplication()->enqueueMessage(Text::sprintf(
                    'PKG_APPOINTMENTS_INSTALL_CHILD_NEWER',
                    htmlspecialchars(strtolower((string) $this->children[$entry['key']]['manifest']->name), ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($entry['installed'], ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($entry['bundled'], ENT_QUOTES, 'UTF-8')
                ), 'error');
            }

            return false;
        }

        try {
            $this->conflictLock = AppointmentsConflictGuard::lock($this->conflictState());
        } catch (\RuntimeException $error) {
            $this->reportConflictError($error);

            return false;
        }

        if (!$this->checkUnknownFiles($type, $parent)) {
            return false;
        }

        $firstReview = $this->conflictReview['id'] ?? null;

        if (!parent::preflight($type, $parent)) {
            return false;
        }

        // A file that changed while the shared guard ran must not slip through with the earlier review.
        if (!$this->checkUnknownFiles($type, $parent) || $firstReview !== ($this->conflictReview['id'] ?? null)) {
            return false;
        }

        if ($firstReview !== null) {
            try {
                AppointmentsConflictGuard::journal($this->conflictState(), $this->conflictReview);
            } catch (\RuntimeException | \JsonException $error) {
                $this->reportConflictError($error);

                return false;
            }
        }

        // What postflight enables or publishes: the extensions not installed yet, and those an
        // interrupted earlier attempt installed without reaching postflight.
        $pending = $this->readPending();

        foreach (self::CHILDREN as [$child, $enable]) {
            $key = PackageChildren::key($child);

            if (($enable || $key === self::MODULE) && ($this->before[$key] ?? null) === null) {
                $pending[] = $key;
            }
        }

        $this->pending = array_values(array_unique($pending));

        try {
            if ($this->pending !== []) {
                State::write(JPATH_ADMINISTRATOR . self::PENDING_FILE, ['expires' => time() + self::PENDING_SECONDS, 'children' => $this->pending]);
            }
        } catch (\RuntimeException | \JsonException $error) {
            // Only the retry after an interruption depends on it; this run handles them anyway.
            Log::add('Cannot record the extensions to enable: ' . $error->getMessage(), Log::WARNING, 'advans-installer');
        }

        return true;
    }

    /** @return list<string> Keys an earlier, interrupted attempt still had to enable or publish. */
    private function readPending(): array
    {
        $file = JPATH_ADMINISTRATOR . self::PENDING_FILE;

        if (!is_file($file)) {
            return [];
        }

        try {
            $state = State::read($file);
        } catch (\RuntimeException | \JsonException $error) {
            return [];
        }

        if ((int) ($state['expires'] ?? 0) < time() || !is_array($state['children'] ?? null)) {
            return [];
        }

        $known = array_map(static fn(array $child): string => PackageChildren::key($child[0]), self::CHILDREN);

        return array_values(array_intersect(array_map('strval', $state['children']), $known));
    }

    protected function migratesExistingInstallation(string $type, object $parent): bool
    {
        return PackageChildren::anyInstalled($this->before);
    }

    protected function reviewedOwnedFiles(): array
    {
        return array_column($this->conflictReview['rows'] ?? [], null, 'destination');
    }

    /**
     * Destinations the component writes or removes outside its manifest's file lists (see its own
     * guardedFiles()): the package decides them with the rest, so the component finds them decided.
     */
    protected function guardedFiles(string $type, object $parent): array
    {
        $component = $this->children[self::COMPONENT]['directory'] ?? null;

        if ($component === null) {
            return [];
        }

        $root = JPATH_ADMINISTRATOR . '/components/com_appointments';
        $files = [
            $root . '/com_appointments.xml' => $this->removalCandidate($parent, 'Remove obsolete appointments manifest'),
        ];

        foreach (['en-GB', 'de-DE', 'fr-FR'] as $tag) {
            $files[$root . '/language/' . $tag . '/com_appointments.ini'] = $component . '/administrator/language/' . $tag . '/com_appointments.ini';
        }

        // A first installation of the component starts with fresh tables (component guardedFiles()).
        if (($this->before[self::COMPONENT] ?? null) === null) {
            foreach (glob($root . '/data/epochs.php*') ?: [] as $file) {
                $files[$file] = $this->removalCandidate($parent, 'Remove obsolete calendar epoch anchor on fresh installation');
            }
        }

        return $files;
    }

    /**
     * The file review of Appointments over every bundled extension: files that differ from the
     * package and are not known release bytes stop the installation until the operator downloaded
     * each one in Appointments Recovery and approved its replacement.
     */
    private function checkUnknownFiles(string $type, object $parent): bool
    {
        $source = (string) $parent->getParent()->getPath('source');
        $manifest = $parent->getManifest();
        $manifestFile = (string) $parent->getParent()->getPath('manifest');
        $state = $this->conflictState();
        $this->conflictReview = [];
        $conflicts = [];

        try {
            $files = Destinations::files($manifest, $source, $manifestFile);
            $owned = Destinations::owned($manifest, $source);

            foreach ($this->children as $child) {
                $files = Destinations::merge($files, Destinations::files($child['manifest'], $child['directory'], $child['file']));
                $owned = Destinations::mergeOwned($owned, Destinations::owned($child['manifest'], $child['directory']));
            }

            $files = Destinations::merge($files, $this->guardedFiles($type, $parent));
            $knowledge = Knowledge::load(
                $source . '/' . Knowledge::CATALOG,
                JPATH_ADMINISTRATOR . '/advans-installer-state/pkg_appointments/' . Knowledge::RECORD
            );
            $conflicts = AppointmentsConflictGuard::inspect($files, $owned, $knowledge);
            $review = $conflicts === [] ? [] : AppointmentsConflictGuard::review(
                $state, (string) $manifest->version, $conflicts, $files, (string) hash_file('sha256', $manifestFile)
            );
            $complete = $review !== [];

            foreach ($review['rows'] ?? [] as $id => $row) {
                $complete = $complete && AppointmentsConflictGuard::validChoice($row, $review['choices'][$id] ?? null);
            }

            if ($complete) {
                $this->conflictReview = $review;

                return true;
            }
        } catch (\RuntimeException | \JsonException $error) {
            $this->reportConflictError($error);

            return false;
        }

        if ($conflicts === []) {
            return true;
        }

        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $items = '';

        foreach ($conflicts as $conflict) {
            $items .= '<li><code>' . $escape(Knowledge::relative($conflict['destination'])) . '</code><br>'
                . Text::_('PKG_APPOINTMENTS_INSTALL_CONFLICT_EXISTING') . ': <code>' . $escape($conflict['existing'])
                . '</code><br>' . Text::_('PKG_APPOINTMENTS_INSTALL_CONFLICT_INCOMING') . ': <code>'
                . $escape($conflict['incoming']) . '</code></li>';
        }

        Factory::getApplication()->enqueueMessage(
            '<p><strong>' . Text::_('PKG_APPOINTMENTS_INSTALL_CONFLICT_TITLE') . '</strong></p><p>'
            . Text::_('PKG_APPOINTMENTS_INSTALL_CONFLICT_EXPLANATION') . '</p><p>'
            . Text::_('ADVANS_GUARD_BACKUP_RECOMMENDATION_BODY') . '</p><p><a href="index.php?option='
            . self::RECOVERY_OPTION . '">' . Text::_('PKG_APPOINTMENTS_INSTALL_CONFLICT_REVIEW') . '</a></p><ul>' . $items . '</ul>',
            'error'
        );

        return false;
    }

    protected function extensionPostflight(string $type, object $parent): void
    {
        if ($type !== 'install' && $type !== 'update') {
            return;
        }

        $app = Factory::getApplication();
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $this->loadLanguage((string) $parent->getParent()->getPath('source'));
        $children = array_map(static fn(array $child): array => $child[0], self::CHILDREN);

        PackageChildren::adopt($db, 'pkg_appointments', $children);

        // Only an extension that was not installed before is enabled: an update or the takeover of
        // the standalone extensions keeps the state the operator chose.
        foreach (self::CHILDREN as [$child, $enable]) {
            if ($enable && in_array(PackageChildren::key($child), $this->pending, true)) {
                PackageChildren::enable($db, $child);
            }
        }

        if (in_array(self::MODULE, $this->pending, true)) {
            $this->publishDashboardModule($db, 'mod_appointments');
        }

        if (is_file(JPATH_ADMINISTRATOR . self::PENDING_FILE)) {
            @unlink(JPATH_ADMINISTRATOR . self::PENDING_FILE);
        }

        try {
            PackageChildren::removeUpdateSites($db, $children);
        } catch (\Throwable $error) {
            Log::add('Cannot move the Appointments extensions onto the package update channel: ' . $error->getMessage(), Log::WARNING, 'advans-installer');
            $app->enqueueMessage(Text::_('PKG_APPOINTMENTS_UPDATE_CHANNEL_WARNING'), 'warning');
        }

        // The required tasks are created by the shared installer right after this method
        // (ScheduledTasks, catalog key 'tasks' of pkg_appointments); here only the optional one.
        $this->ensureCloseTask();
        AppointmentsConflictGuard::verifyInstalled($this->conflictReview, $this->conflictState());
        $this->installLicense($parent);

        if ($type === 'install' && !PackageChildren::anyInstalled($this->before)) {
            $app->enqueueMessage(Text::_('PKG_APPOINTMENTS_POSTINSTALL_INTRO'), 'message');

            return;
        }

        $manifest = method_exists($parent, 'getManifest') ? $parent->getManifest() : null;
        $version = $manifest instanceof \SimpleXMLElement ? (string) $manifest->version : '';
        $packaged = array_filter($this->before, static fn(?array $row): bool => $row !== null && $row['package_id'] > 0);
        $app->enqueueMessage(Text::sprintf(
            $packaged === [] ? 'PKG_APPOINTMENTS_POSTINSTALL_MIGRATED' : 'PKG_APPOINTMENTS_POSTINSTALL_UPDATED',
            htmlspecialchars($version, ENT_QUOTES, 'UTF-8')
        ), 'message');
    }

    /**
     * The component refuses to be uninstalled when its permission tree cannot be retained; the
     * package refuses before Joomla removes the module and the plugins, so nothing is half removed.
     */
    private function prepareUninstall(): bool
    {
        $app = Factory::getApplication();
        $app->getLanguage()->load('pkg_appointments', JPATH_SITE)
            || $app->getLanguage()->load('pkg_appointments', JPATH_ADMINISTRATOR);
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $this->createDbQuery($db)->select($db->quoteName('params'))->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_appointments'));
        $params = json_decode((string) $db->setQuery($query)->loadResult(), true);

        if ((is_array($params) ? ($params['uninstall_data_action'] ?? '') : '') === 'delete') {
            return true;
        }

        $query = $this->createDbQuery($db)->select('COUNT(*)')->from($db->quoteName('#__assets'))
            ->where($db->quoteName('name') . ' = ' . $db->quote(self::RETAINED_ASSET_NAME));

        if ((int) $db->setQuery($query)->loadResult() > 0) {
            $app->enqueueMessage(Text::_('PKG_APPOINTMENTS_UNINSTALL_RETAINED_ACL_CONFLICT'), 'error');

            return false;
        }

        return true;
    }

    /**
     * The instance Joomla's installer created for a newly installed module (unpublished, without
     * position), or a new one when there is none, published at cpanel for every administrator page
     * (#__modules_menu 0), with a title in the language of the installing administrator. It shows
     * nothing to users who may not see the bookings list. An update leaves the instances alone.
     */
    private function publishDashboardModule(DatabaseInterface $db, string $element): void
    {
        $query = $this->createDbQuery($db)->select($db->quoteName('id'))
            ->from($db->quoteName('#__modules'))
            ->where($db->quoteName('module') . ' = ' . $db->quote($element))
            ->where($db->quoteName('client_id') . ' = 1')
            ->order($db->quoteName('id'));
        $id = (int) $db->setQuery($query)->loadResult();
        $title = $this->text('PKG_APPOINTMENTS_MODULE_DEFAULT_TITLE', 'Next appointments');

        if ($id < 1) {
            $db->setQuery($this->createDbQuery($db)->insert($db->quoteName('#__modules'))
                ->columns($db->quoteName(['title', 'note', 'content', 'ordering', 'position', 'published', 'module', 'access',
                    'showtitle', 'params', 'client_id', 'language']))
                ->values(implode(',', [$db->quote($title), $db->quote(''), $db->quote(''), 0, $db->quote('cpanel'), 1,
                    $db->quote($element), 1, 1, $db->quote('{"count":"5"}'), 1, $db->quote('*')])))->execute();
            $id = (int) $db->insertid();
        } else {
            $db->setQuery($this->createDbQuery($db)->update($db->quoteName('#__modules'))
                ->set($db->quoteName('title') . ' = ' . $db->quote($title))
                ->set($db->quoteName('position') . ' = ' . $db->quote('cpanel'))
                ->set($db->quoteName('published') . ' = 1')
                ->set($db->quoteName('showtitle') . ' = 1')
                ->where($db->quoteName('id') . ' = ' . $id))->execute();
        }

        $assigned = (int) $db->setQuery($this->createDbQuery($db)->select('COUNT(*)')->from($db->quoteName('#__modules_menu'))
            ->where($db->quoteName('moduleid') . ' = ' . $id))->loadResult();

        if ($assigned === 0) {
            $db->setQuery($this->createDbQuery($db)->insert($db->quoteName('#__modules_menu'))
                ->columns($db->quoteName(['moduleid', 'menuid']))->values($id . ', 0'))->execute();
        }
    }

    /**
     * Creates the optional Close delivery task of the bundled task plugin through the Scheduler's
     * own task model, switched off by design (it is needed only once the transfer to Close is set
     * up). It is created only when no task of its routine exists at all, in any state: a task the
     * operator switched on, off or trashed is never changed or created again. Runs after every
     * bundled extension is in place, so the routine of the task plugin is known.
     *
     * The required tasks are created switched on by the shared installer after this postflight
     * (shared/installer/tasks.php). A failure never fails the installation; it is reported, so the
     * operator can create the task by hand.
     */
    private function ensureCloseTask(): void
    {
        $app = Factory::getApplication();
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $lang = $app->getLanguage();
        $lang->load('plg_task_appointments', JPATH_ADMINISTRATOR)
            || $lang->load('plg_task_appointments', JPATH_PLUGINS . '/task/appointments');
        $type = 'plg_task_appointments.close';
        $titleKey = 'PLG_TASK_APPOINTMENTS_CLOSE_TITLE';

        try {
            $query = $this->createDbQuery($db)->select('COUNT(*)')->from($db->quoteName('#__scheduler_tasks'))
                ->where($db->quoteName('type') . ' = :type')->bind(':type', $type);

            if ((int) $db->setQuery($query)->loadResult() > 0) {
                return;
            }

            $model = $app->bootComponent('com_scheduler')->getMVCFactory()
                ->createModel('Task', 'Administrator', ['ignore_request' => true]);
            // From the CLI there is no signed-in user: the task is then created by the guest user
            // (id 0), set explicitly so the model and its table never have to guess.
            $identity = $app->getIdentity()
                ?? Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById(0);

            if ($model instanceof CurrentUserInterface) {
                $model->setCurrentUser($identity);
            }

            $saved = $model && $model->save([
                'title' => Text::_($titleKey),
                'type' => $type,
                'execution_rules' => ['rule-type' => 'interval-minutes', 'interval-minutes' => 5, 'exec-day' => gmdate('d'), 'exec-time' => gmdate('H:i')],
                'state' => 0,
                'params' => ['batch_size' => 100],
                'created_by' => (int) $identity->id,
            ]);

            if (!$saved) {
                throw new \RuntimeException('The Scheduler task could not be saved.');
            }

            $app->enqueueMessage(Text::sprintf('PKG_APPOINTMENTS_INSTALL_TASK_DISABLED', Text::_($titleKey)), 'notice');
        } catch (\Throwable $e) {
            $app->enqueueMessage(Text::sprintf('PKG_APPOINTMENTS_INSTALL_TASK_FAILED', Text::_($titleKey)), 'warning');
        }
    }

    /**
     * Install the package's GPL-3.0 text next to its stored manifest. The package installer copies
     * only the manifest and this script into administrator/manifests/packages/appointments/; it is
     * removed with that folder on uninstall.
     */
    private function installLicense(object $parent): void
    {
        $installer = $parent->getParent();
        $source = (string) $installer->getPath('source');
        $root = (string) $installer->getPath('extension_root');

        if ($source === '' || $root === '') {
            return;
        }

        if (!is_dir($root)) {
            @mkdir($root, 0755, true);
        }

        if (is_file($source . '/LICENSE.txt') && is_dir($root)) {
            copy($source . '/LICENSE.txt', $root . '/LICENSE.txt');
        }
    }

    private function loadLanguage(string $source): void
    {
        $language = Factory::getApplication()->getLanguage();
        $language->load('pkg_appointments', JPATH_SITE);
        $language->load('pkg_appointments', $source, null, true);
    }

    /** The state of the Appointments file review, which Appointments Recovery reads. */
    private function conflictState(): string
    {
        return JPATH_ADMINISTRATOR . '/advans-installer-state/com_appointments';
    }

    private function reportConflictError(\Throwable $error): void
    {
        Log::add($error->getMessage(), Log::ERROR, 'advans-installer');
        Factory::getApplication()->enqueueMessage(Text::_('PKG_APPOINTMENTS_INSTALL_CONFLICT_ERROR'), 'error');
    }

    private function releaseConflictLock(): void
    {
        if (is_resource($this->conflictLock)) {
            fclose($this->conflictLock);
            $this->conflictLock = null;
        }
    }

    private function text(string $key, string $fallback): string
    {
        $text = Text::_($key);

        return $text === $key ? $fallback : $text;
    }

    private function createDbQuery(DatabaseInterface $db): \Joomla\Database\QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
