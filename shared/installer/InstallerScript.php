<?php
/**
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Installer\V3;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;

abstract class InstallerScript extends \Joomla\CMS\Installer\InstallerScript
{
    /** A package's decisions for its children are valid for this long. */
    private const HANDOFF_SECONDS = 3600;

    /** A full-site backup recommendation is acknowledged for one hour per extension version. */
    private const BACKUP_RECOMMENDATION_SECONDS = 3600;

    /** @var array<string, mixed>|null State of the current installation between preflight and postflight. */
    private ?array $guard = null;
    /** @var resource|null Shared lock held while Joomla copies files. */
    private $installerLock = null;

    public function preflight($type, $parent)
    {
        if (!parent::preflight($type, $parent)) {
            return false;
        }

        if (!in_array($type, ['install', 'update'], true)) {
            return true;
        }

        SystemCheck::loadLanguage();
        $manifest = $parent->getManifest();
        $id = strtolower((string) $manifest->name);
        $source = (string) $parent->getParent()->getPath('source');
        $manifestFile = (string) $parent->getParent()->getPath('manifest');
        $stateRoot = JPATH_ADMINISTRATOR . '/advans-installer-state';
        $state = $stateRoot . '/' . $id;
        $isPackage = (string) $manifest['type'] === 'package';

        try {
            $this->installerLock = InstallerLock::acquire($stateRoot);

            if (!is_resource($this->installerLock)) {
                Factory::getApplication()->enqueueMessage(Text::_('ADVANS_GUARD_LOCKED'), 'error');

                return false;
            }

            $owned = Destinations::owned($manifest, $source);
            $files = Destinations::merge(
                Destinations::files($manifest, $source, $manifestFile),
                $this->guardedFiles($type, $parent)
            );
            // Bundled extension id => version it brings: one backup recommendation covers the package.
            $bundled = [];

            if ($isPackage) {
                foreach ($manifest->files->children() as $archive) {
                    if ($archive->getName() !== 'file') {
                        continue;
                    }

                    $zip = $source . '/' . (string) $manifest->files['folder'] . '/' . (string) $archive;
                    $directory = $source . '/advans-preflight-' . bin2hex(random_bytes(8));

                    if (!(new \Joomla\Archive\Archive())->extract($zip, $directory)) {
                        throw new \RuntimeException('Cannot inspect a bundled package archive.');
                    }

                    $children = glob($directory . '/*.xml') ?: [];
                    $found = false;

                    foreach ($children as $childManifest) {
                        $child = simplexml_load_file($childManifest);

                        if ($child instanceof \SimpleXMLElement && $child->getName() === 'extension') {
                            $files = Destinations::merge($files, Destinations::files($child, $directory, $childManifest));
                            $owned = Destinations::mergeOwned($owned, Destinations::owned($child, $directory));
                            $bundled[strtolower((string) $child->name)] = (string) $child->version;
                            $found = true;
                        }
                    }

                    if (!$found) {
                        throw new \RuntimeException('Bundled package manifest is missing.');
                    }
                }
            }

            $operations = [];

            foreach ($files as $candidate) {
                if (str_contains(str_replace('\\', '/', $candidate), '/.advans-operations/')
                    && str_starts_with((string) file_get_contents($candidate), "<?php exit; ?>\n")) {
                    $operation = State::read($candidate);
                    $operations[hash_file('sha256', $candidate)] = $operation['operation'];
                }
            }

            $knowledge = Knowledge::load($source . '/' . Knowledge::CATALOG, $state . '/' . Knowledge::RECORD);
            $comparison = FileGuard::compare($files);
            // A package installed over extensions that are already in place (the standalone
            // extensions it replaces) changes an existing installation just like an update.
            $remind = $type === 'update' || $this->migratesExistingInstallation($type, $parent);
            if ($remind && !$this->recommendFullSiteBackup($state, (string) $manifest->version)) {
                $this->releaseInstallerLock();

                return false;
            }
            $reviewed = $this->reviewedOwnedFiles();
            $plan = Planner::plan(
                $comparison['differing'],
                $operations,
                static fn(string $destination): bool => Destinations::isOwned($destination, $owned),
                fn(string $destination, string $existing, bool $known): string => $this->foreignDecision($destination, $existing, $known),
                $knowledge,
                $reviewed + self::readHandoff($stateRoot)
            );

            if ($plan['halts'] !== []) {
                return $this->pause($plan['halts']);
            }

            if ($isPackage) {
                // Keep what an earlier attempt of this package already decided: the planner skips those
                // destinations now, and a handoff rewritten without them would leave the bundled
                // extensions to judge the same files again without the package's catalog.
                // Files the package's own review approved are decisions for the bundled extensions too:
                // the planner skips them here, so they are not among the changes of this plan.
                $decided = self::decisions($reviewed + self::readHandoff($stateRoot), $plan['changes']);

                State::write($stateRoot . '/decided.php', ['expires' => time() + self::HANDOFF_SECONDS, 'files' => $decided]);

                // The operator acknowledged the backup recommendation for the whole package: the
                // bundled extensions do not stop the same installation once more for themselves.
                if ($remind) {
                    foreach ($bundled as $childId => $childVersion) {
                        if (preg_match('/^[a-z0-9_]+$/D', $childId) === 1 && $childVersion !== '') {
                            $this->acknowledgeBackupRecommendation($stateRoot . '/' . $childId, $childVersion);
                        }
                    }
                }
            }

            $this->guard = [
                'id'             => $id,
                'state'          => $state,
                'stateRoot'      => $stateRoot,
                'version'        => (string) $manifest->version,
                'package'        => $isPackage,
                'incoming'       => $comparison['incoming'],
            ];
        } catch (\RuntimeException | \JsonException $error) {
            $this->releaseInstallerLock();
            Log::add($error->getMessage(), Log::ERROR, 'advans-installer');
            Factory::getApplication()->enqueueMessage(Text::_('ADVANS_CHECK_GUARD_ERROR'), 'error');

            return false;
        }

        return true;
    }

    /**
     * Whether an installation of type "install" replaces extensions that are already in place, so
     * that it shows the backup recommendation like an update. A package that adopts the standalone
     * extensions it bundles answers true when one of them is installed.
     */
    protected function migratesExistingInstallation(string $type, object $parent): bool
    {
        return false;
    }

    /**
     * Own writes separately reviewed by this installer script. Planner still requires both
     * hashes to match, and the ledger still uses the original package hashes.
     *
     * @return array<string,array{existing:string,incoming:string}>
     */
    protected function reviewedOwnedFiles(): array
    {
        return [];
    }

    /**
     * Destinations outside the manifest's own file lists that this extension creates, replaces
     * or removes during installation (foreign operations and obsolete files).
     *
     * @return array<string, string> Absolute destination => absolute candidate source.
     */
    protected function guardedFiles(string $type, object $parent): array
    {
        return [];
    }

    /**
     * What to do with an existing file outside the extension's own territory whose bytes differ
     * from the incoming ones. Extensions with foreign operations decide per operation.
     *
     * @param bool $known The current bytes are bytes this extension wrote or shipped.
     */
    protected function foreignDecision(string $destination, string $existing, bool $known): string
    {
        // A file this extension put there itself can be replaced; anything else might belong to
        // another extension, and overwriting it could break that extension.
        return $known ? Planner::REPLACE : Planner::HALT;
    }

    /** Plain-language reason shown for a destination the installation paused on. */
    protected function pauseReason(string $destination): string
    {
        return Text::_('ADVANS_GUARD_PAUSED_REASON');
    }

    protected function removalCandidate(object $parent, string $action): string
    {
        return Destinations::operation($parent->getParent()->getPath('source'), $action);
    }

    protected function replacementCandidate(object $parent, string $bytes): string
    {
        $directory = $parent->getParent()->getPath('source') . '/.advans-operations';

        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new \RuntimeException('Cannot stage installer operation.');
        }

        $path = $directory . '/' . hash('sha256', $bytes) . '.php';

        if (file_put_contents($path, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException('Cannot stage installer replacement.');
        }

        return $path;
    }

    public function postflight($type, $parent)
    {
        try {
            $result = $this->extensionPostflight($type, $parent);

            if ($result === false || !in_array($type, ['install', 'update'], true)) {
                return $result;
            }

            $id = strtolower((string) $parent->getManifest()->name);

            if ((string) $parent->getManifest()['type'] === 'package') {
                $root = SystemCheck::root(SystemCheck::definition($id)) . '/advansinstaller';
                $source = $parent->getParent()->getPath('source') . '/advansinstaller';

                if (!\Joomla\Filesystem\Folder::copy($source, $root, '', true)) {
                    throw new \RuntimeException('Cannot install package system-check support.');
                }
            }

            $this->finishGuard();
            // Required scheduled tasks: create what is missing, report what is switched off.
            ScheduledTasks::ensure($id);
            Factory::getApplication()->enqueueMessage(SystemCheck::render($id), 'message');

            return $result;
        } finally {
            $this->releaseInstallerLock();
        }
    }

    /** Remember the release bytes actually present after installation. */
    private function finishGuard(): void
    {
        $guard = $this->guard;
        $this->guard = null;

        if ($guard === null) {
            return;
        }

        try {
            Knowledge::record($guard['state'] . '/' . Knowledge::RECORD, $guard['version'], $guard['incoming']);
        } catch (\RuntimeException | \JsonException $error) {
            Log::add('Cannot record installed files: ' . $error->getMessage(), Log::WARNING, 'advans-installer');
        }

        if ($guard['package'] && is_file($guard['stateRoot'] . '/decided.php')) {
            unlink($guard['stateRoot'] . '/decided.php');
        }
    }

    /**
     * Stop the first update attempt for this extension version so Joomla can show the full-site
     * backup recommendation before it changes files. Repeating it acknowledges the notice; this
     * records no backup status and does not claim that a backup exists or is restorable.
     */
    private function recommendFullSiteBackup(string $state, string $version): bool
    {
        $path = $state . '/backup-recommendation.php';
        $previous = is_file($path) ? State::read($path) : [];

        if (($previous['version'] ?? '') === $version && (int) ($previous['expires'] ?? 0) >= time()) {
            return true;
        }

        $this->acknowledgeBackupRecommendation($state, $version);
        Factory::getApplication()->enqueueMessage(
            '<p><strong>' . Text::_('ADVANS_GUARD_BACKUP_RECOMMENDATION_TITLE') . '</strong></p>'
                . '<p>' . Text::_('ADVANS_GUARD_BACKUP_RECOMMENDATION_BODY') . '</p>'
                . '<p>' . Text::_('ADVANS_GUARD_BACKUP_RECOMMENDATION_RETRY') . '</p>',
            'warning'
        );

        return false;
    }

    /** Record that the recommendation for $version of the extension at $state was shown. */
    private function acknowledgeBackupRecommendation(string $state, string $version): void
    {
        State::write($state . '/backup-recommendation.php', ['version' => $version, 'expires' => time() + self::BACKUP_RECOMMENDATION_SECONDS]);
    }

    /**
     * No safe automatic choice exists. Persist only the destination and file hashes so a later
     * review can identify the conflict without retaining file contents or approving a bypass.
     *
     * @param list<array<string, mixed>> $halts
     */
    private function pause(array $halts): bool
    {
        $entries = [];

        foreach ($halts as $halt) {
            $entries[] = [
                'destination' => $halt['destination'],
                'existing'    => $halt['existing'],
                'incoming'    => $halt['incoming'],
                'operation'   => $halt['operation'],
            ];
        }

        $app = Factory::getApplication();
        $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $items = '';

        foreach ($entries as $entry) {
            $items .= '<li><code>' . $escape(Knowledge::relative($entry['destination'])) . '</code>: '
                . $this->pauseReason($entry['destination'])
                . '<br>' . Text::_('ADVANS_GUARD_PAUSED_EXISTING') . ': <code>' . $escape($entry['existing'])
                . '</code><br>' . Text::_('ADVANS_GUARD_PAUSED_INCOMING') . ': <code>'
                . $escape($entry['incoming']) . '</code></li>';
        }

        $message = '<p><strong>' . Text::_('ADVANS_GUARD_PAUSED_TITLE') . '</strong></p>'
            . '<p>' . Text::_('ADVANS_GUARD_PAUSED_WHY') . '</p><ul>' . $items . '</ul>'
            . '<p>' . Text::_('ADVANS_GUARD_PAUSED_NEXT_STEPS') . '</p>'
            . '<p>' . Text::_('ADVANS_GUARD_BACKUP_RECOMMENDATION_BODY') . '</p>';
        $app->enqueueMessage($message, 'error');
        $this->releaseInstallerLock();

        return false;
    }

    private function releaseInstallerLock(): void
    {
        InstallerLock::release($this->installerLock);
        $this->installerLock = null;
    }

    /**
     * The decisions handed to the bundled extensions: those of earlier attempts plus the new ones.
     *
     * @param array<string, mixed>            $previous Destination => existing and incoming hash.
     * @param list<array<string, mixed>>      $changes  Changes the plan of this attempt decided.
     *
     * @return array<string, mixed>
     */
    public static function decisions(array $previous, array $changes): array
    {
        foreach ($changes as $change) {
            $previous[$change['destination']] = ['existing' => $change['existing'], 'incoming' => $change['incoming']];
        }

        return $previous;
    }

    /** @return array<string, mixed> */
    private static function readHandoff(string $stateRoot): array
    {
        $path = $stateRoot . '/decided.php';

        if (!is_file($path)) {
            return [];
        }

        $handoff = State::read($path);

        return (int) ($handoff['expires'] ?? 0) >= time() && is_array($handoff['files'] ?? null) ? $handoff['files'] : [];
    }

}
