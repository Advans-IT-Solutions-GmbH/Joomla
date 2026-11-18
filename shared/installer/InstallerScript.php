<?php
/**
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Installer;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;

abstract class InstallerScript extends \Joomla\CMS\Installer\InstallerScript
{
    /**
     * Backups older than this are removed by the next successful installation of the same
     * extension. A changed file that an update replaced is noticed within weeks; half a year
     * leaves room for seasonal checks without letting backups pile up forever.
     */
    public const BACKUP_RETENTION_DAYS = 180;

    /** A package's decisions for its children are valid for this long. */
    private const HANDOFF_SECONDS = 3600;

    /** @var array<string, mixed>|null State of the current installation between preflight and postflight. */
    private ?array $guard = null;

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
            $owned = Destinations::owned($manifest, $source);
            $files = Destinations::merge(
                Destinations::files($manifest, $source, $manifestFile),
                $this->guardedFiles($type, $parent)
            );

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

            State::directory($state . '/backups');
            $approved = is_file($stateRoot . '/approved.php') ? State::read($stateRoot . '/approved.php') : [];
            [$approved, $fromPausedCopy] = $this->continuePausedInstallation($source, $state, $approved);
            $knowledge = Knowledge::load($source . '/advansinstaller/' . Knowledge::CATALOG, $state . '/' . Knowledge::RECORD);
            $comparison = FileGuard::compare($files);
            $plan = Planner::plan(
                $comparison['differing'],
                $operations,
                static fn(string $destination): bool => Destinations::isOwned($destination, $owned),
                fn(string $destination, string $existing, bool $known): string => $this->foreignDecision($destination, $existing, $known),
                $knowledge,
                $approved,
                self::readHandoff($stateRoot)
            );

            if ($plan['halts'] !== []) {
                return $this->pause($plan['halts'], $state, $source, $fromPausedCopy);
            }

            // Every backup is written and verified before Joomla changes a single file.
            $kept = [];

            foreach ($plan['changes'] as $index => $change) {
                if ($change['backup']) {
                    $kept[] = $plan['changes'][$index]['backupPath'] = FileGuard::backup($change['destination'], $change['existing'], $state . '/backups');
                }
            }

            if ($isPackage) {
                $decided = [];

                foreach ($plan['changes'] as $change) {
                    $decided[$change['destination']] = ['existing' => $change['existing'], 'incoming' => $change['incoming']];
                }

                State::write($stateRoot . '/decided.php', ['expires' => time() + self::HANDOFF_SECONDS, 'files' => $decided]);
            }

            $this->guard = [
                'id'             => $id,
                'state'          => $state,
                'stateRoot'      => $stateRoot,
                'version'        => (string) $manifest->version,
                'package'        => $isPackage,
                'incoming'       => $comparison['incoming'],
                'changes'        => $plan['changes'],
                'kept'           => $kept,
                'fromPausedCopy' => $fromPausedCopy,
            ];
        } catch (BackupFailure $failure) {
            Log::add($failure->getMessage(), Log::ERROR, 'advans-installer');
            Factory::getApplication()->enqueueMessage(
                Text::sprintf(
                    'ADVANS_GUARD_BACKUP_FAILED',
                    htmlspecialchars(Knowledge::relative($failure->destination), ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars(Knowledge::relative($state . '/backups'), ENT_QUOTES, 'UTF-8')
                ),
                'error'
            );

            return false;
        } catch (\RuntimeException | \JsonException $error) {
            Log::add($error->getMessage(), Log::ERROR, 'advans-installer');
            Factory::getApplication()->enqueueMessage(Text::_('ADVANS_CHECK_GUARD_ERROR'), 'error');

            return false;
        }

        return true;
    }

    /**
     * Destinations outside the manifest's own file lists that this extension creates, replaces
     * or removes during installation (foreign operations and retirement of obsolete files).
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

            // The catalog of released files is only needed while installing; it is not installed.
            if (is_file($root . '/' . Knowledge::CATALOG)) {
                unlink($root . '/' . Knowledge::CATALOG);
            }
        }

        $this->finishGuard();
        Factory::getApplication()->enqueueMessage(SystemCheck::render($id), 'message');

        return $result;
    }

    /**
     * Remember what this installation wrote, report what it backed up and apply the retention rule.
     * The files are already installed: a failure here is logged and never fails the installation.
     */
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

        $this->report($guard['changes'], $guard['state'] . '/backups');

        try {
            FileGuard::prune($guard['state'] . '/backups', self::BACKUP_RETENTION_DAYS * 86400, $guard['kept']);

            foreach (['/plan.php', '/pending.php'] as $stale) {
                if (is_file($guard['state'] . $stale)) {
                    unlink($guard['state'] . $stale);
                }
            }

            if ($guard['package'] && is_file($guard['stateRoot'] . '/decided.php')) {
                unlink($guard['stateRoot'] . '/decided.php');
            }
        } catch (\RuntimeException $error) {
            Log::add('Cannot apply the backup retention: ' . $error->getMessage(), Log::WARNING, 'advans-installer');
        }

        if ($guard['fromPausedCopy']) {
            $pending = $guard['state'] . '/pending';
            // The installation still runs from this copy; remove it when the request ends.
            register_shutdown_function(static function () use ($pending): void {
                self::removeTree($pending);
            });
        }
    }

    /** @param list<array<string, mixed>> $changes */
    private function report(array $changes, string $backups): void
    {
        $lists = [Planner::REPORT_REPLACED => [], Planner::REPORT_REMOVED => [], Planner::REPORT_FOREIGN => []];

        foreach ($changes as $change) {
            if ($change['report'] !== null) {
                $lists[$change['report']][] = Knowledge::relative($change['destination']);
            }
        }

        $location = htmlspecialchars(Knowledge::relative($backups), ENT_QUOTES, 'UTF-8');
        $app = Factory::getApplication();

        if ($lists[Planner::REPORT_REPLACED] !== []) {
            $app->enqueueMessage(Text::sprintf('ADVANS_GUARD_REPORT_REPLACED', self::fileList($lists[Planner::REPORT_REPLACED]), $location), 'notice');
        }

        if ($lists[Planner::REPORT_REMOVED] !== []) {
            $app->enqueueMessage(Text::sprintf('ADVANS_GUARD_REPORT_REMOVED', self::fileList($lists[Planner::REPORT_REMOVED]), $location), 'notice');
        }

        if ($lists[Planner::REPORT_FOREIGN] !== []) {
            $app->enqueueMessage(Text::sprintf('ADVANS_GUARD_REPORT_FOREIGN', self::fileList($lists[Planner::REPORT_FOREIGN]), $location), 'notice');
        }
    }

    /** @param list<string> $paths */
    private static function fileList(array $paths): string
    {
        sort($paths, SORT_STRING);
        $shown = array_slice($paths, 0, 10);
        $text = implode(', ', array_map(static fn(string $path): string => htmlspecialchars($path, ENT_QUOTES, 'UTF-8'), $shown));

        if (count($paths) > count($shown)) {
            $text .= ' ' . Text::sprintf('ADVANS_GUARD_REPORT_MORE', count($paths) - count($shown));
        }

        return $text;
    }

    /**
     * No safe automatic choice exists: keep verified backups, explain in plain language and let a
     * Super User continue in the backend (or the server administrator on the command line).
     *
     * @param list<array<string, mixed>> $halts
     */
    private function pause(array $halts, string $state, string $source, bool $fromPausedCopy): bool
    {
        $entries = [];

        foreach ($halts as $halt) {
            $entries[] = [
                'destination' => $halt['destination'],
                'existing'    => $halt['existing'],
                'incoming'    => $halt['incoming'],
                'backup'      => FileGuard::backup($halt['destination'], $halt['existing'], $state . '/backups'),
                'operation'   => $halt['operation'],
            ];
        }

        $planPath = $state . '/plan.php';
        State::write($planPath, $entries);
        $digest = (string) hash_file('sha256', $planPath);
        $app = Factory::getApplication();
        $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $items = '';

        foreach ($entries as $entry) {
            $items .= '<li><code>' . $escape(Knowledge::relative($entry['destination'])) . '</code>: '
                . $this->pauseReason($entry['destination']) . '</li>';
        }

        $message = '<p><strong>' . Text::_('ADVANS_GUARD_PAUSED_TITLE') . '</strong></p>'
            . '<p>' . Text::_('ADVANS_GUARD_PAUSED_WHY') . '</p><ul>' . $items . '</ul>';

        if (!self::isCli()) {
            $pending = $state . '/pending';

            if (!$fromPausedCopy) {
                self::removeTree($pending);
                self::copyPackage($source, $pending);
            }

            State::write($state . '/pending.php', ['plan' => $digest]);
            $app->setUserState('com_installer.install.install_directory', $pending);

            if (self::isSuperUser()) {
                $message .= '<p>' . Text::sprintf('ADVANS_GUARD_PAUSED_APPROVE', $escape(Knowledge::relative($state . '/backups'))) . '</p>'
                    . '<p><a class="btn btn-primary" href="index.php?option=com_installer&amp;view=install&amp;install_directory='
                    . $escape(rawurlencode($pending)) . '">' . Text::_('ADVANS_GUARD_PAUSED_BUTTON') . '</a></p>';
            } else {
                $message .= '<p>' . Text::_('ADVANS_GUARD_PAUSED_SUPERUSER') . '</p>';
            }
        }

        $message .= '<p>' . Text::sprintf('ADVANS_GUARD_PAUSED_CLI', $escape($planPath . ' (SHA-256: ' . $digest . ')')) . '</p>';
        $app->enqueueMessage($message, 'error');

        return false;
    }

    /**
     * A Super User continues a paused installation by installing its preserved copy
     * (System → Install → Extensions → Install from Folder). That request is the approval of
     * exactly the reviewed plan: changed bytes produce a new plan and a new pause.
     *
     * @param array<string, mixed> $approved
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function continuePausedInstallation(string $source, string $state, array $approved): array
    {
        $pending = $state . '/pending';
        $marker = $state . '/pending.php';
        $real = is_dir($pending) ? realpath($pending) : false;
        $fromPausedCopy = $real !== false && realpath($source) === $real;

        if (!$fromPausedCopy) {
            // Another package was started: an earlier paused copy is no longer needed.
            self::removeTree($pending);

            if (is_file($marker)) {
                unlink($marker);
            }

            return [$approved, false];
        }

        if (self::isCli() || !self::isSuperUser() || !is_file($marker) || !is_file($state . '/plan.php')
            || (class_exists(\Joomla\CMS\Session\Session::class) && !\Joomla\CMS\Session\Session::checkToken('post'))) {
            return [$approved, true];
        }

        $paused = State::read($marker);

        if (!is_string($paused['plan'] ?? null) || !hash_equals($paused['plan'], (string) hash_file('sha256', $state . '/plan.php'))) {
            return [$approved, true];
        }

        try {
            return [Approval::approve($state . '/plan.php'), true];
        } catch (\RuntimeException $stale) {
            // The site changed since the pause: plan again, which pauses again with current bytes.
            Log::add($stale->getMessage(), Log::WARNING, 'advans-installer');

            return [$approved, true];
        }
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

    private static function isCli(): bool
    {
        $app = Factory::getApplication();

        return $app instanceof \Joomla\CMS\Application\ConsoleApplication || $app->isClient('cli');
    }

    private static function isSuperUser(): bool
    {
        $app = Factory::getApplication();
        $user = method_exists($app, 'getIdentity') ? $app->getIdentity() : null;

        return $user !== null && $user->authorise('core.admin') === true;
    }

    private static function copyPackage(string $source, string $target): void
    {
        $source = rtrim(str_replace('\\', '/', $source), '/');
        State::directory($target);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $entry) {
            $relative = substr(str_replace('\\', '/', $entry->getPathname()), strlen($source) + 1);
            $first = explode('/', $relative)[0];

            if ($first === '.advans-operations' || str_starts_with($first, 'advans-preflight-')) {
                continue;
            }

            if ($entry->isLink()) {
                throw new \RuntimeException('The package contains a symbolic link.');
            }

            $destination = $target . '/' . $relative;

            if ($entry->isDir()) {
                if (!is_dir($destination) && !mkdir($destination, 0700, true)) {
                    throw new \RuntimeException('Cannot preserve the paused package.');
                }
            } elseif (!copy($entry->getPathname(), $destination)) {
                throw new \RuntimeException('Cannot preserve the paused package.');
            }
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($path);
    }
}
