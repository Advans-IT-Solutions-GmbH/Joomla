<?php
/**
 * @package     J2Commerce Privacy Package
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

require_once is_file(__DIR__ . '/advansinstaller/load.php')
    ? __DIR__ . '/advansinstaller/load.php'
    : dirname(__DIR__, 2) . '/shared/installer/load.php';

use Advans\Installer\V3\InstallerScript;
use Advans\Installer\V3\PackageChildren;
use Advans\Installer\V3\State;
use Advans\Installer\V3\SystemCheck;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;

/**
 * Installer script of the J2Commerce Privacy package: the privacy plugin, the consent system
 * plugin and the cleanup task plugin, installed, updated and removed together.
 *
 * Joomla installs every bundled plugin with its own adapter, the privacy plugin first, so that a
 * refusal of the privacy plugin (requirements, a checkout override of an earlier release that has
 * to be reviewed) stops the package before anything else changed. Before that, this script decides
 * for all of them:
 *
 *  - the shared file guard covers the files of every bundled plugin; its decisions are handed to
 *    the plugins, which install through them;
 *  - the full-site backup recommendation is shown once for the package, also when the package is
 *    installed over the standalone privacy plugin of an earlier version (which installed the two
 *    other plugins itself);
 *  - a bundled plugin that is installed in a newer version stops the installation.
 *
 * Installed plugins are adopted as they are: Joomla updates each one in place, so its row,
 * parameters, data and enabled state stay; this script assigns it to the package, moves it onto
 * the package's update channel and enables only the consent and task plugins, and only when they
 * were not installed before. The privacy plugin keeps the state Joomla gives it (disabled on a
 * first installation) or the operator chose.
 */
class Pkg_Privacy_j2commerceInstallerScript extends InstallerScript
{
    protected $minimumJoomla = '5.4';
    protected $minimumPhp = '8.1';

    /**
     * The bundled plugins as Joomla stores them ([type, element, folder, client_id]) and whether
     * one is enabled when it is installed the first time.
     */
    private const CHILDREN = [
        [['plugin', 'j2commerce', 'privacy', 0], false],
        [['plugin', 'j2commerceprivacy', 'system', 0], true],
        [['plugin', 'j2commerceprivacy', 'task', 0], true],
    ];

    /**
     * Plugins still to be enabled after an interrupted first installation: an attempt that installed
     * a plugin and stopped before this postflight left it disabled, and the next attempt finds it
     * installed. Below the state of this package; valid for one day.
     */
    private const PENDING_FILE = '/advans-installer-state/pkg_privacy_j2commerce/enable-pending.php';
    private const PENDING_SECONDS = 86400;

    /** Whether this package is installing its plugins right now (read by the privacy plugin). */
    private static bool $installing = false;

    /** @var array<string, array{manifest: \SimpleXMLElement, file: string, directory: string}> */
    private array $children = [];

    /** @var array<string, array|null> Installed rows of the bundled plugins before this run. */
    private array $before = [];

    /** @var list<string> Keys of the plugins this run enables in postflight. */
    private array $pending = [];

    /**
     * True between a successful preflight of this package and its postflight: the bundled privacy
     * plugin then installs through the decisions of the package and refuses any other installation.
     */
    public static function installing(): bool
    {
        return self::$installing;
    }

    public function preflight($type, $parent)
    {
        if (!in_array($type, ['install', 'update'], true)) {
            return parent::preflight($type, $parent);
        }

        $accepted = $this->preflightPackage($type, $parent);
        self::$installing = $accepted;

        return $accepted;
    }

    public function postflight($type, $parent)
    {
        try {
            return parent::postflight($type, $parent);
        } finally {
            self::$installing = false;
        }
    }

    private function preflightPackage(string $type, object $parent): bool
    {
        $source = (string) $parent->getParent()->getPath('source');
        $this->loadLanguage($source);
        SystemCheck::loadLanguage();
        $app = Factory::getApplication();
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        try {
            $this->children = PackageChildren::extract($parent->getManifest(), $source);
        } catch (\RuntimeException $error) {
            Log::add($error->getMessage(), Log::ERROR, 'advans-installer');
            $app->enqueueMessage(Text::_('ADVANS_CHECK_GUARD_ERROR'), 'error');

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
                // The plugin's name as the operator knows it, from the bundled plugin's own language.
                $child = $this->children[$entry['key']];
                $name = strtoupper((string) $child['manifest']->name);
                $app->getLanguage()->load(strtolower($name) . '.sys', $child['directory']);
                $app->enqueueMessage(Text::sprintf(
                    'PKG_PRIVACY_J2COMMERCE_INSTALL_CHILD_NEWER',
                    htmlspecialchars(Text::_($name), ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($entry['installed'], ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($entry['bundled'], ENT_QUOTES, 'UTF-8')
                ), 'error');
            }

            return false;
        }

        if (!parent::preflight($type, $parent)) {
            return false;
        }

        // Plugins to enable in postflight: the ones not installed yet, and the ones an interrupted
        // earlier attempt installed without reaching postflight.
        $pending = $this->readPending();

        foreach (self::CHILDREN as [$child, $enable]) {
            $key = PackageChildren::key($child);

            if ($enable && ($this->before[$key] ?? null) === null) {
                $pending[] = $key;
            }
        }

        $this->pending = array_values(array_unique($pending));

        try {
            if ($this->pending !== []) {
                State::write(JPATH_ADMINISTRATOR . self::PENDING_FILE, ['expires' => time() + self::PENDING_SECONDS, 'children' => $this->pending]);
            }
        } catch (\RuntimeException | \JsonException $error) {
            // Only the retry after an interruption depends on it; this run enables them anyway.
            Log::add('Cannot record the plugins to enable: ' . $error->getMessage(), Log::WARNING, 'advans-installer');
        }

        return true;
    }

    protected function migratesExistingInstallation(string $type, object $parent): bool
    {
        return PackageChildren::anyInstalled($this->before);
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

        PackageChildren::adopt($db, 'pkg_privacy_j2commerce', $children);

        // Only a plugin that was not installed before is enabled: an update or the takeover of the
        // standalone plugins keeps the state the operator chose.
        foreach (self::CHILDREN as [$child, $enable]) {
            if ($enable && in_array(PackageChildren::key($child), $this->pending, true)) {
                PackageChildren::enable($db, $child);
            }
        }

        if (is_file(JPATH_ADMINISTRATOR . self::PENDING_FILE)) {
            @unlink(JPATH_ADMINISTRATOR . self::PENDING_FILE);
        }

        try {
            PackageChildren::removeUpdateSites($db, $children);
        } catch (\Throwable $error) {
            Log::add('Cannot move the J2Commerce Privacy plugins onto the package update channel: ' . $error->getMessage(), Log::WARNING, 'advans-installer');
            $app->enqueueMessage(Text::_('PKG_PRIVACY_J2COMMERCE_UPDATE_CHANNEL_WARNING'), 'warning');
        }

        // The required cleanup task is created by the shared installer right after this method
        // (ScheduledTasks, catalog key 'tasks' of pkg_privacy_j2commerce), once the task plugin is
        // in place.
        $this->installLicense($parent);

        if ($type === 'install' && !PackageChildren::anyInstalled($this->before)) {
            $app->enqueueMessage(Text::_('PKG_PRIVACY_J2COMMERCE_POSTINSTALL_INTRO'), 'message');

            return;
        }

        $manifest = method_exists($parent, 'getManifest') ? $parent->getManifest() : null;
        $version = $manifest instanceof \SimpleXMLElement ? (string) $manifest->version : '';
        $packaged = array_filter($this->before, static fn(?array $row): bool => $row !== null && $row['package_id'] > 0);
        $app->enqueueMessage(Text::sprintf(
            $packaged === [] ? 'PKG_PRIVACY_J2COMMERCE_POSTINSTALL_MIGRATED' : 'PKG_PRIVACY_J2COMMERCE_POSTINSTALL_UPDATED',
            htmlspecialchars($version, ENT_QUOTES, 'UTF-8')
        ), 'message');
    }

    /** @return list<string> Plugin keys an earlier, interrupted attempt still had to enable. */
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

    /**
     * Install the package's GPL-3.0 text next to its stored manifest. The package installer copies
     * only the manifest and this script into administrator/manifests/packages/privacy_j2commerce/;
     * it is removed with that folder on uninstall.
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
        $language->load('pkg_privacy_j2commerce', JPATH_SITE);
        $language->load('pkg_privacy_j2commerce', $source, null, true);
    }
}
