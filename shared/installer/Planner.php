<?php
/**
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Installer\V2;

defined('_JEXEC') or die;

/**
 * Decides, for every existing destination whose bytes differ from the incoming file, what the
 * installation does with it. The decision never asks the administrator unless continuing could
 * cause damage that a backup cannot undo.
 *
 * Own territory (the extension's own folders, media and language files):
 *  - untouched (bytes this extension wrote or shipped): replaced, no backup needed;
 *  - changed on this site: backed up, then replaced or removed, and reported.
 *
 * Foreign territory (files of other extensions, site templates): the extension script decides per
 * operation through InstallerScript::foreignDecision():
 *  - REPLACE: the file is this extension's own copy (for example a mirror it maintains): backed up
 *    and replaced; reported only when it had been changed;
 *  - ADJUST: a deliberate, minimal change to another extension's file: backed up, changed, reported;
 *  - SKIP: the file is left as it is and the extension adapts its operation;
 *  - HALT: no safe automatic choice exists; the installation pauses for a Super User.
 */
final class Planner
{
    public const REPLACE = 'replace';
    public const ADJUST = 'adjust';
    public const SKIP = 'skip';
    public const HALT = 'halt';

    /** Report categories. */
    public const REPORT_REPLACED = 'replaced';
    public const REPORT_REMOVED = 'removed';
    public const REPORT_FOREIGN = 'foreign';

    /**
     * @param array<string, array{existing: string, incoming: string}> $differing Normalized destination => hashes.
     * @param array<string, string> $operations Incoming hash of a staged operation marker => operation name.
     * @param callable(string): bool $owned Whether a destination is in the extension's own territory.
     * @param callable(string, string, bool): string $foreign Decision for a foreign destination
     *        (destination, existing hash, known) => REPLACE, ADJUST, SKIP or HALT.
     * @param array<string, mixed> $approved Explicit approvals: destination => {existing, incoming}.
     * @param array<string, mixed> $decided  Destinations a package already decided for its children.
     * @return array{changes: list<array<string, mixed>>, halts: list<array<string, mixed>>, skipped: list<string>}
     */
    public static function plan(
        array $differing,
        array $operations,
        callable $owned,
        callable $foreign,
        Knowledge $knowledge,
        array $approved = [],
        array $decided = []
    ): array {
        $changes = [];
        $halts = [];
        $skipped = [];

        foreach ($differing as $destination => $hashes) {
            $existing = $hashes['existing'];
            $incoming = $hashes['incoming'];
            $operation = $operations[$incoming] ?? 'replace';
            $removal = $operation !== 'replace';

            if (self::matches($decided, $destination, $existing, $incoming)) {
                continue;
            }

            $known = $knowledge->isKnown($destination, $existing);
            $entry = [
                'destination' => $destination,
                'existing'    => $existing,
                'incoming'    => $incoming,
                'operation'   => $operation,
                'known'       => $known,
            ];

            if ($owned($destination)) {
                $changes[] = $entry + [
                    'territory' => 'own',
                    'backup'    => !$known,
                    'report'    => $known ? null : ($removal ? self::REPORT_REMOVED : self::REPORT_REPLACED),
                ];

                continue;
            }

            $decision = $foreign($destination, $existing, $known);

            if ($decision === self::SKIP) {
                $skipped[] = $destination;

                continue;
            }

            if ($decision === self::HALT && !self::matches($approved, $destination, $existing, $incoming)) {
                $halts[] = $entry + ['territory' => 'foreign'];

                continue;
            }

            if (!in_array($decision, [self::REPLACE, self::ADJUST, self::HALT], true)) {
                throw new \RuntimeException('Unknown installer decision for a foreign destination.');
            }

            $report = $decision === self::REPLACE
                ? ($known ? null : ($removal ? self::REPORT_REMOVED : self::REPORT_REPLACED))
                : self::REPORT_FOREIGN;
            // Foreign territory is always backed up, even when its bytes are known.
            $changes[] = $entry + ['territory' => 'foreign', 'backup' => true, 'report' => $report];
        }

        return ['changes' => $changes, 'halts' => $halts, 'skipped' => $skipped];
    }

    /** @param array<string, mixed> $entries */
    private static function matches(array $entries, string $destination, string $existing, string $incoming): bool
    {
        $entry = $entries[$destination] ?? null;

        return is_array($entry)
            && isset($entry['existing'], $entry['incoming'])
            && is_string($entry['existing'])
            && is_string($entry['incoming'])
            && hash_equals($existing, $entry['existing'])
            && hash_equals($incoming, $entry['incoming']);
    }
}
