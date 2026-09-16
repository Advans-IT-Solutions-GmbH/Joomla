<?php
/**
 * @package     Advans.Plugin
 * @subpackage  Ajax.JoomlaAjaxForms
 *
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary License
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

class PlgAjaxJoomlaajaxformsInstallerScript extends InstallerScript
{
    protected $minimumJoomla = '5.4';
    protected $minimumPhp    = '8.1';

    public function postflight(string $type, object $parent): void
    {
        if ($type !== 'install' && $type !== 'update') {
            return;
        }

        $language = Factory::getApplication()->getLanguage();
        $language->load('plg_ajax_joomlaajaxforms', JPATH_ADMINISTRATOR);
        $language->load('plg_ajax_joomlaajaxforms', JPATH_PLUGINS . '/ajax/joomlaajaxforms');

        $this->removeLegacyUpdateSites();
        $this->checkHtaccess();

        // Joomla installs plugins disabled and an update keeps the state. The
        // only setup step is enabling the plugin, so it is mentioned only while
        // the plugin is still disabled.
        if (!$this->isPluginEnabled()) {
            Factory::getApplication()->enqueueMessage(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_ENABLE_HINT'), 'message');
        }
    }

    /**
     * Whether this plugin is enabled in #__extensions.
     */
    private function isPluginEnabled(): bool
    {
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->select($db->quoteName('enabled'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('ajax'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('joomlaajaxforms'));

            return (int) $db->setQuery($query)->loadResult() === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Check if .htaccess allows com_ajax requests.
     *
     * If the server uses rewrite rules that block /component/ or
     * index.php?option=com_* URLs, com_ajax must be whitelisted.
     * This check is generic and works on any Joomla site.
     */
    private function checkHtaccess(): void
    {
        $htaccess = JPATH_ROOT . '/.htaccess';

        if (!file_exists($htaccess)) {
            return;
        }

        $content = file_get_contents($htaccess);

        if ($content === false) {
            return;
        }

        $issues = [];

        foreach ($this->analyseHtaccess($content) as $issue) {
            $issues[] = Text::_(
                $issue === 'component'
                    ? 'PLG_AJAX_JOOMLAAJAXFORMS_HTACCESS_COMPONENT_BLOCKED'
                    : 'PLG_AJAX_JOOMLAAJAXFORMS_HTACCESS_OPTION_BLOCKED'
            );
        }

        if (empty($issues)) {
            return;
        }

        $msg  = '<strong>' . Text::_('PLG_AJAX_JOOMLAAJAXFORMS_HTACCESS_WARNING_TITLE') . '</strong><ul>';
        foreach ($issues as $issue) {
            $msg .= '<li>' . $issue . '</li>';
        }
        $msg .= '</ul>';
        $msg .= '<p>' . Text::_('PLG_AJAX_JOOMLAAJAXFORMS_HTACCESS_WARNING_ACTION') . '</p>';

        Factory::getApplication()->enqueueMessage($msg, 'warning');
    }

    /**
     * Sample com_ajax requests of this plugin, per issue type. Each variant
     * holds the values mod_rewrite would see in a .htaccess context: the
     * RewriteRule path (no leading slash) and the server variables.
     */
    private const AJAX_REQUESTS = [
        'component' => [
            [
                'path'            => 'component/ajax/',
                '%{REQUEST_URI}'  => '/component/ajax/',
                '%{QUERY_STRING}' => 'plugin=joomlaajaxforms&group=ajax&format=json',
                '%{THE_REQUEST}'  => 'POST /component/ajax/?plugin=joomlaajaxforms&group=ajax&format=json HTTP/1.1',
            ],
            [
                'path'            => 'en/component/ajax/',
                '%{REQUEST_URI}'  => '/en/component/ajax/',
                '%{QUERY_STRING}' => 'plugin=joomlaajaxforms&group=ajax&format=json',
                '%{THE_REQUEST}'  => 'POST /en/component/ajax/?plugin=joomlaajaxforms&group=ajax&format=json HTTP/1.1',
            ],
        ],
        'option' => [
            [
                'path'            => 'index.php',
                '%{REQUEST_URI}'  => '/index.php',
                '%{QUERY_STRING}' => 'option=com_ajax&plugin=joomlaajaxforms&format=json',
                '%{THE_REQUEST}'  => 'POST /index.php?option=com_ajax&plugin=joomlaajaxforms&format=json HTTP/1.1',
            ],
            [
                'path'            => '',
                '%{REQUEST_URI}'  => '/',
                '%{QUERY_STRING}' => 'option=com_ajax&plugin=joomlaajaxforms&format=json',
                '%{THE_REQUEST}'  => 'POST /?option=com_ajax&plugin=joomlaajaxforms&format=json HTTP/1.1',
            ],
        ],
    ];

    /**
     * Server variables whose conditions can mark a rule as aimed at the
     * request type, together with the word the pattern has to contain.
     */
    private const TARGET_VARIABLES = [
        'component' => ['word' => 'component', 'variables' => ['%{REQUEST_URI}', '%{THE_REQUEST}']],
        'option'    => ['word' => 'option', 'variables' => ['%{QUERY_STRING}', '%{THE_REQUEST}']],
    ];

    /**
     * Analyse rewrite rules block by block.
     *
     * mod_rewrite applies a group of RewriteCond lines only to the RewriteRule
     * that directly follows them. Consecutive conditions joined with [OR]
     * form one group; all groups must be true (AND) for the rule to fire.
     *
     * A redirecting or forbidding rule is reported when it is aimed at
     * /component/ URLs ("component") or at index.php?option=com_* URLs
     * ("option") and would still fire for the com_ajax request of this
     * plugin. A rule is aimed at a type when its pattern or one of its
     * positive conditions mentions the type and matches the sample request;
     * a generic rule (for example an HTTP to HTTPS redirect) is ignored.
     * A condition only protects com_ajax when it is false for the sample
     * request and not joined with another condition that may be true, e.g.
     * `RewriteCond %{QUERY_STRING} !plugin= [NC]` for /component/ rules and
     * `RewriteCond %{QUERY_STRING} !^option=com_ajax [NC]` for option rules.
     * Conditions on other variables or with special patterns (-f, =, <, ...)
     * count as possibly true.
     *
     * @return string[] Unique issue identifiers ("component", "option").
     */
    private function analyseHtaccess(string $content): array
    {
        $issues     = [];
        $conditions = [];

        foreach (preg_split('/\R/', $content) as $rawLine) {
            $line = trim($rawLine);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (preg_match('/^RewriteCond\s+(\S+)\s+(\S+)(?:\s+\[([^\]]*)\])?/i', $line, $match)) {
                $flags        = $this->ruleFlags($match[3] ?? '');
                $conditions[] = [
                    'variable' => strtoupper($match[1]),
                    'pattern'  => $match[2],
                    'nocase'   => isset($flags['NC']) || isset($flags['NOCASE']),
                    'or'       => isset($flags['OR']) || isset($flags['ORNEXT']),
                ];
                continue;
            }

            if (!preg_match('/^RewriteRule\s+(\S+)\s+(\S+)(?:\s+\[([^\]]*)\])?/i', $line, $match)) {
                continue;
            }

            $rulePattern = $match[1];
            $target      = $match[2];
            $flags       = $this->ruleFlags($match[3] ?? '');
            $blockConds  = $conditions;
            $conditions  = [];

            $redirects = isset($flags['R']) || isset($flags['REDIRECT'])
                || isset($flags['F']) || isset($flags['FORBIDDEN'])
                || isset($flags['G']) || isset($flags['GONE'])
                || preg_match('#^https?://#i', $target);

            if (!$redirects) {
                continue;
            }

            $ruleNocase = isset($flags['NC']) || isset($flags['NOCASE']);
            $groups     = $this->conditionGroups($blockConds);

            foreach (self::AJAX_REQUESTS as $type => $variants) {
                foreach ($variants as $request) {
                    if (!$this->ruleAimsAt($type, $rulePattern, $ruleNocase, $blockConds, $request)) {
                        continue;
                    }

                    if ($this->patternMayMatch($rulePattern, $request['path'], $ruleNocase) === false) {
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

    /**
     * Parse a flag list like "NC,R=301,L" into upper-case flag names.
     *
     * @return array<string, true>
     */
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

    /**
     * Split conditions into OR groups; the groups are combined with AND.
     *
     * @return array<int, array<int, array>>
     */
    private function conditionGroups(array $conditions): array
    {
        $groups  = [];
        $current = [];

        foreach ($conditions as $condition) {
            $current[] = $condition;

            if (!$condition['or']) {
                $groups[] = $current;
                $current  = [];
            }
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * Whether the rule is aimed at the given request type.
     */
    private function ruleAimsAt(string $type, string $rulePattern, bool $ruleNocase, array $conditions, array $request): bool
    {
        $word = self::TARGET_VARIABLES[$type]['word'];

        if ($type === 'component'
            && !str_starts_with($rulePattern, '!')
            && stripos($rulePattern, $word) !== false
            && $this->patternMayMatch($rulePattern, $request['path'], $ruleNocase, true)
        ) {
            return true;
        }

        foreach ($conditions as $condition) {
            if (str_starts_with($condition['pattern'], '!')
                || !in_array($condition['variable'], self::TARGET_VARIABLES[$type]['variables'], true)
                || stripos($condition['pattern'], $word) === false
            ) {
                continue;
            }

            if ($this->patternMayMatch($condition['pattern'], $request[$condition['variable']], $condition['nocase'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a condition may be true for the sample request. Unknown
     * variables and special patterns are treated as possibly true.
     */
    private function conditionMayBeTrue(array $condition, array $request): bool
    {
        if (!str_starts_with($condition['variable'], '%{') || !array_key_exists($condition['variable'], $request)) {
            return true;
        }

        $pattern = $condition['pattern'];

        if (preg_match('/^!?(-[a-zA-Z]+$|[<>=])/', $pattern)) {
            return true;
        }

        return $this->patternMayMatch($pattern, $request[$condition['variable']], $condition['nocase']) !== false;
    }

    /**
     * Evaluate a mod_rewrite pattern (optionally negated with "!") against a
     * subject. Returns null when the pattern cannot be evaluated in PHP.
     *
     * With $strict, a pattern that cannot be evaluated falls back to a plain
     * text check for "component/" or "option=com_" and never returns null.
     */
    private function patternMayMatch(string $pattern, string $subject, bool $nocase, bool $strict = false): ?bool
    {
        $negated = str_starts_with($pattern, '!');
        $regex   = $negated ? substr($pattern, 1) : $pattern;

        if (strlen($regex) > 1 && $regex[0] === '"' && substr($regex, -1) === '"') {
            $regex = substr($regex, 1, -1);
        }

        $result = @preg_match("\x01" . $regex . "\x01" . ($nocase ? 'i' : ''), $subject);

        if ($result === false) {
            if (!$strict) {
                return null;
            }

            return !$negated && preg_match('#(^|[^a-z])(component/|option=com_)#i', $regex) === 1;
        }

        return $negated ? $result === 0 : $result === 1;
    }

    /**
     * Remove update sites of this plugin that still point to the repository's
     * former organisation name. The current update server is registered by
     * Joomla from the manifest.
     */
    private function removeLegacyUpdateSites(): void
    {
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $this->dbQuery($db)
                ->select($db->quoteName('extension_id'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('ajax'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('joomlaajaxforms'));
            $extensionId = (int) $db->setQuery($query)->loadResult();

            if ($extensionId === 0) {
                return;
            }

            $legacyPattern = '%/advansit/Joomla/%';

            $query = $this->dbQuery($db)
                ->select($db->quoteName('s.update_site_id'))
                ->from($db->quoteName('#__update_sites', 's'))
                ->join(
                    'INNER',
                    $db->quoteName('#__update_sites_extensions', 'map'),
                    $db->quoteName('map.update_site_id') . ' = ' . $db->quoteName('s.update_site_id')
                )
                ->where($db->quoteName('map.extension_id') . ' = :extId')
                ->where($db->quoteName('s.location') . ' LIKE :legacy')
                ->bind(':extId', $extensionId, ParameterType::INTEGER)
                ->bind(':legacy', $legacyPattern);
            $siteIds = array_map('intval', $db->setQuery($query)->loadColumn() ?: []);

            foreach ($siteIds as $siteId) {
                $query = $this->dbQuery($db)
                    ->delete($db->quoteName('#__update_sites_extensions'))
                    ->where($db->quoteName('update_site_id') . ' = :siteId')
                    ->where($db->quoteName('extension_id') . ' = :extId')
                    ->bind(':siteId', $siteId, ParameterType::INTEGER)
                    ->bind(':extId', $extensionId, ParameterType::INTEGER);
                $db->setQuery($query)->execute();

                $query = $this->dbQuery($db)
                    ->select('COUNT(*)')
                    ->from($db->quoteName('#__update_sites_extensions'))
                    ->where($db->quoteName('update_site_id') . ' = :siteId')
                    ->bind(':siteId', $siteId, ParameterType::INTEGER);

                if ((int) $db->setQuery($query)->loadResult() > 0) {
                    continue;
                }

                foreach (['#__updates', '#__update_sites'] as $table) {
                    $query = $this->dbQuery($db)
                        ->delete($db->quoteName($table))
                        ->where($db->quoteName('update_site_id') . ' = :siteId')
                        ->bind(':siteId', $siteId, ParameterType::INTEGER);
                    $db->setQuery($query)->execute();
                }
            }
        } catch (\Throwable $e) {
            // Cleaning up outdated update sites must never block the installation.
        }
    }

    /**
     * Returns a fresh query object compatible with Joomla 5 and 6.
     */
    private function dbQuery(DatabaseInterface $db): \Joomla\Database\QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
