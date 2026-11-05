<?php
/**
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Installer;

defined('_JEXEC') or die;

final class RoutingCheck
{
    private const TARGET_VARIABLES = [
        'component' => ['word' => 'component', 'variables' => ['%{REQUEST_URI}', '%{THE_REQUEST}']],
        'option' => ['word' => 'option', 'variables' => ['%{QUERY_STRING}', '%{THE_REQUEST}']],
    ];

    /** Static evidence only; this does not execute Apache rules or send HTTP requests. */
    public function analyse(string $content, string $component = 'com_ajax'): array
    {
        $slug = substr($component, 4);
        $extra = $component === 'com_ajax' ? '&plugin=joomlaajaxforms&group=ajax&format=json' : '';
        $requests = ['component' => [], 'option' => []];

        foreach (['component/' . $slug . '/', 'en/component/' . $slug . '/'] as $path) {
            $query = ltrim($extra, '&');
            $requests['component'][] = [
                'path' => $path,
                '%{REQUEST_URI}' => '/' . $path,
                '%{QUERY_STRING}' => $query,
                '%{THE_REQUEST}' => 'POST /' . $path . ($query !== '' ? '?' . $query : '') . ' HTTP/1.1',
            ];
        }

        foreach (['index.php', ''] as $path) {
            $query = 'option=' . $component . ($component === 'com_ajax' ? '&plugin=joomlaajaxforms&format=json' : '');
            $requests['option'][] = [
                'path' => $path,
                '%{REQUEST_URI}' => '/' . $path,
                '%{QUERY_STRING}' => $query,
                '%{THE_REQUEST}' => 'POST /' . $path . '?' . $query . ' HTTP/1.1',
            ];
        }

        $issues = [];
        $conditions = [];

        foreach (preg_split('/\R/', $content) as $rawLine) {
            $line = trim($rawLine);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (preg_match('/^RewriteCond\s+(\S+)\s+(\S+)(?:\s+\[([^\]]*)\])?/i', $line, $match)) {
                $flags = $this->ruleFlags($match[3] ?? '');
                $conditions[] = [
                    'variable' => strtoupper($match[1]),
                    'pattern' => $match[2],
                    'nocase' => isset($flags['NC']) || isset($flags['NOCASE']),
                    'or' => isset($flags['OR']) || isset($flags['ORNEXT']),
                ];
                continue;
            }

            if (!preg_match('/^RewriteRule\s+(\S+)\s+(\S+)(?:\s+\[([^\]]*)\])?/i', $line, $match)) {
                continue;
            }

            $rulePattern = $match[1];
            $target = $match[2];
            $flags = $this->ruleFlags($match[3] ?? '');
            $blockConds = $conditions;
            $conditions = [];
            $redirects = isset($flags['R']) || isset($flags['REDIRECT'])
                || isset($flags['F']) || isset($flags['FORBIDDEN'])
                || isset($flags['G']) || isset($flags['GONE'])
                || preg_match('#^https?://#i', $target);

            if (!$redirects) {
                continue;
            }

            $ruleNocase = isset($flags['NC']) || isset($flags['NOCASE']);
            $groups = $this->conditionGroups($blockConds);

            foreach ($requests as $type => $variants) {
                foreach ($variants as $request) {
                    if (!$this->ruleAimsAt($type, $rulePattern, $ruleNocase, $blockConds, $request)
                        || $this->patternMayMatch($rulePattern, $request['path'], $ruleNocase) === false) {
                        continue;
                    }

                    foreach ($groups as $group) {
                        $groupMayBeTrue = false;

                        foreach ($group as $condition) {
                            if ($this->conditionMayBeTrue($condition, $request)) {
                                $groupMayBeTrue = true;
                                break;
                            }
                        }

                        if (!$groupMayBeTrue) {
                            continue 2;
                        }
                    }

                    $issues[$type] = $type;
                    break;
                }
            }
        }

        return array_values($issues);
    }

    private function ruleFlags(string $flags): array
    {
        $result = [];

        foreach (explode(',', $flags) as $flag) {
            $name = strtoupper(trim(explode('=', $flag, 2)[0]));

            if ($name !== '') {
                $result[$name] = true;
            }
        }

        return $result;
    }

    private function conditionGroups(array $conditions): array
    {
        $groups = [];
        $current = [];

        foreach ($conditions as $condition) {
            $current[] = $condition;

            if (!$condition['or']) {
                $groups[] = $current;
                $current = [];
            }
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    private function ruleAimsAt(string $type, string $rulePattern, bool $ruleNocase, array $conditions, array $request): bool
    {
        $word = self::TARGET_VARIABLES[$type]['word'];

        if ($type === 'component'
            && !str_starts_with($rulePattern, '!')
            && stripos($rulePattern, $word) !== false
            && $this->patternMayMatch($rulePattern, $request['path'], $ruleNocase, true)) {
            return true;
        }

        foreach ($conditions as $condition) {
            if (str_starts_with($condition['pattern'], '!')
                || !in_array($condition['variable'], self::TARGET_VARIABLES[$type]['variables'], true)
                || stripos($condition['pattern'], $word) === false) {
                continue;
            }

            if ($this->patternMayMatch($condition['pattern'], $request[$condition['variable']], $condition['nocase'], true)) {
                return true;
            }
        }

        return false;
    }

    private function conditionMayBeTrue(array $condition, array $request): bool
    {
        if (!str_starts_with($condition['variable'], '%{') || !array_key_exists($condition['variable'], $request)) {
            return true;
        }

        if (preg_match('/^!?(-[a-zA-Z]+$|[<>=])/', $condition['pattern'])) {
            return true;
        }

        return $this->patternMayMatch($condition['pattern'], $request[$condition['variable']], $condition['nocase']) !== false;
    }

    private function patternMayMatch(string $pattern, string $subject, bool $nocase, bool $strict = false): ?bool
    {
        $negated = str_starts_with($pattern, '!');
        $regex = $negated ? substr($pattern, 1) : $pattern;

        if (strlen($regex) > 1 && $regex[0] === '"' && substr($regex, -1) === '"') {
            $regex = substr($regex, 1, -1);
        }

        $result = @preg_match("\x01" . $regex . "\x01" . ($nocase ? 'i' : ''), $subject);

        if ($result === false) {
            return $strict ? !$negated && preg_match('#(^|[^a-z])(component/|option=com_)#i', $regex) === 1 : null;
        }

        return $negated ? $result === 0 : $result === 1;
    }
}
