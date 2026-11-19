<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

defined('_JEXEC') or die;

final class BundledModuleGuard
{
    /**
     * @param list<string> $elements
     * @return array<string, string> Absolute installed target => packaged source
     */
    public static function destinations(string $source, string $administratorRoot, array $elements): array
    {
        $files = [];
        foreach ($elements as $element) {
            $moduleSource = $source . '/modules/' . $element;
            $moduleTarget = $administratorRoot . '/modules/' . $element;
            if (!is_dir($moduleSource)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($moduleSource, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($moduleSource) + 1));
                if (str_starts_with($relative, '.advans-operations/')) {
                    continue;
                }
                $files[$moduleTarget . '/' . $relative] = $file->getPathname();
            }
            foreach (['en-GB', 'de-DE', 'fr-FR'] as $tag) {
                foreach (['ini', 'sys.ini'] as $suffix) {
                    $name = $element . '.' . $suffix;
                    $candidate = $moduleSource . '/language/' . $tag . '/' . $name;
                    if (is_file($candidate)) {
                        $files[$administratorRoot . '/language/' . $tag . '/' . $name] = $candidate;
                    }
                }
            }
        }
        return $files;
    }
}
