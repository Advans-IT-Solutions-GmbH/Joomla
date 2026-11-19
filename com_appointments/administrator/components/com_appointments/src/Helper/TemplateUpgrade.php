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

/**
 * What an update does with a stored mail template whose shipped default changed.
 *
 * A site-wide template that still holds exactly the default of the installed version follows the
 * new default (script.php, followDefault()). A template the operator changed, and every template of
 * a single event type, stays as it is. Such a kept template is reported after the update when the
 * new default adds a placeholder it lacks, so the operator can add it by hand.
 */
final class TemplateUpgrade
{
    /** What follows the template: the shipped default replaces it. */
    public const FOLLOW = 'follow';

    /** What keeps the template: the operator changed it (or the default did not change). */
    public const KEEP = 'keep';

    /**
     * FOLLOW when the stored template ($subject, $body) is exactly the old default, byte for byte
     * (the table's collation would treat a change of case or trailing spaces as equal), and the
     * default changed; KEEP otherwise.
     */
    public static function decide(string $subject, string $body, string $oldSubject, string $oldBody, string $newSubject, string $newBody): string
    {
        if ($oldSubject === $newSubject && $oldBody === $newBody) {
            return self::KEEP;
        }

        return $subject === $oldSubject && $body === $oldBody ? self::FOLLOW : self::KEEP;
    }

    /**
     * The placeholders ({name}) the new default has and the old one had not, in order.
     *
     * @return  list<string>
     */
    public static function added(string $oldText, string $newText): array
    {
        return array_values(array_diff(self::placeholders($newText), self::placeholders($oldText)));
    }

    /**
     * The placeholders newly introduced across both subject and body, in subject-then-body order.
     *
     * @return  list<string>
     */
    public static function addedPair(string $oldSubject, string $oldBody, string $newSubject, string $newBody): array
    {
        $old = array_unique(array_merge(self::placeholders($oldSubject), self::placeholders($oldBody)));
        $new = array_values(array_unique(array_merge(self::placeholders($newSubject), self::placeholders($newBody))));

        return array_values(array_diff($new, $old));
    }

    /**
     * Of $placeholders those $text does not contain, in order.
     *
     * @param   list<string>  $placeholders
     *
     * @return  list<string>
     */
    public static function missing(string $text, array $placeholders): array
    {
        return array_values(array_filter($placeholders, static fn (string $placeholder): bool => !str_contains($text, $placeholder)));
    }

    /**
     * Of $placeholders those occur in neither the kept subject nor the kept body.
     *
     * @param   list<string>  $placeholders
     *
     * @return  list<string>
     */
    public static function missingPair(string $subject, string $body, array $placeholders): array
    {
        return array_values(array_filter($placeholders, static fn (string $placeholder): bool =>
            !str_contains($subject, $placeholder) && !str_contains($body, $placeholder)));
    }

    /** @return list<string> */
    private static function placeholders(string $text): array
    {
        preg_match_all('/\{[a-z_.0-9]+\}/', $text, $matches);

        return array_values(array_unique($matches[0]));
    }
}
