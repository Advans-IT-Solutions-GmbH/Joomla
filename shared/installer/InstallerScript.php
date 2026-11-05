<?php
/**
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Installer;

defined('_JEXEC') or die;

require_once __DIR__ . '/SystemCheck.php';
require_once __DIR__ . '/Destinations.php';
require_once __DIR__ . '/FileGuard.php';
require_once __DIR__ . '/State.php';

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;

abstract class InstallerScript extends \Joomla\CMS\Installer\InstallerScript
{
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

        try {
            $files = Destinations::merge(
                Destinations::files($manifest, $source, $manifestFile),
                $this->guardedFiles($type, $parent)
            );

            if ((string) $manifest['type'] === 'package') {
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

            $stateRoot = JPATH_ADMINISTRATOR . '/advans-installer-state';
            $state = $stateRoot . '/' . $id;
            State::directory($state . '/backups');
            $approved = is_file($stateRoot . '/approved.php') ? State::read($stateRoot . '/approved.php') : [];
            $conflicts = FileGuard::inspect($files, $state . '/backups', $approved);
            $pending = array_filter($conflicts, static fn(array $entry): bool => !$entry['approved']);

            if ($pending !== []) {
                foreach ($pending as &$entry) {
                    $entry['operation'] = $operations[$entry['incoming']] ?? 'replace';
                }
                unset($entry);
                State::write($state . '/plan.php', array_values($pending));
                $plan = $state . '/plan.php';
                $detail = htmlspecialchars($plan . ' (SHA-256: ' . hash_file('sha256', $plan) . ')', ENT_QUOTES, 'UTF-8');
                Factory::getApplication()->enqueueMessage(Text::sprintf('ADVANS_CHECK_CONFLICT', $detail), 'error');

                return false;
            }
        } catch (\RuntimeException | \JsonException $error) {
            Log::add($error->getMessage(), Log::ERROR, 'advans-installer');
            Factory::getApplication()->enqueueMessage(Text::_('ADVANS_CHECK_GUARD_ERROR'), 'error');

            return false;
        }

        return true;
    }

    protected function guardedFiles(string $type, object $parent): array
    {
        return [];
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
        }

        Factory::getApplication()->enqueueMessage(SystemCheck::render($id), 'message');

        return $result;
    }
}
