<?php
/**
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Installer;

/**
 * Approve the exact old and new hashes of a paused installation's review plan.
 * Used by the backend "Approve" step and by the CLI command approve.php.
 */
final class Approval
{
    /**
     * Verify every plan entry against the current destination and its backup, then add the
     * exact hashes to approved.php next to the extension state directories.
     *
     * @return array<string, array{existing: string, incoming: string}> The updated approvals.
     */
    public static function approve(string $planPath): array
    {
        $plan = State::read($planPath);
        $approvalPath = dirname($planPath, 2) . '/approved.php';
        $approved = is_file($approvalPath) ? State::read($approvalPath) : [];
        $count = 0;

        foreach ($plan as $entry) {
            if (!is_array($entry)
                || !isset($entry['destination'], $entry['existing'], $entry['incoming'], $entry['backup'])
                || !is_string($entry['destination']) || !is_string($entry['backup'])
                || !is_string($entry['existing']) || !is_string($entry['incoming'])
                || !preg_match('/^[a-f0-9]{64}$/D', $entry['existing'])
                || !preg_match('/^[a-f0-9]{64}$/D', $entry['incoming'])
                || !is_file($entry['backup'])
                || is_link($entry['destination'])
                || !is_file($entry['destination'])
                || !hash_equals($entry['existing'], (string) hash_file('sha256', $entry['destination']))) {
                throw new \RuntimeException('The destination or backup no longer matches the review plan.');
            }

            FileGuard::verifyBackup($entry['backup'], $entry['destination'], $entry['existing']);
            $approved[$entry['destination']] = ['existing' => $entry['existing'], 'incoming' => $entry['incoming']];
            $count++;
        }

        if ($count === 0) {
            throw new \RuntimeException('The review plan has no replacements to approve.');
        }

        State::write($approvalPath, $approved);

        return $approved;
    }
}
