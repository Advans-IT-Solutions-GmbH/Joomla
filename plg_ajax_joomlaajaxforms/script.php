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
     * Analyse rewrite rules block by block.
     *
     * mod_rewrite applies a group of RewriteCond lines only to the RewriteRule
     * that directly follows them. Each rule is therefore evaluated together
     * with its own conditions:
     *
     * - "component": a redirecting/forbidding rule that targets /component/
     *   URLs without a condition that exempts com_ajax
     *   (e.g. `RewriteCond %{QUERY_STRING} !plugin= [NC]`).
     * - "option": a redirecting/forbidding rule for index.php?option=com_*
     *   without a condition that exempts com_ajax
     *   (e.g. `RewriteCond %{QUERY_STRING} !^option=com_ajax [NC]`).
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

            if (preg_match('/^RewriteCond\s+(\S+)\s+(\S+)/i', $line, $match)) {
                $conditions[] = [
                    'variable' => strtoupper($match[1]),
                    'pattern'  => $match[2],
                ];
                continue;
            }

            if (!preg_match('/^RewriteRule\s+(\S+)\s+(\S+)(?:\s+\[([^\]]*)\])?/i', $line, $match)) {
                continue;
            }

            $rulePattern = $match[1];
            $flags       = strtoupper($match[3] ?? '');
            $blockConds  = $conditions;
            $conditions  = [];

            if (!preg_match('/(^|,)\s*(R(=\d+)?|REDIRECT(=\d+)?|F|FORBIDDEN|G|GONE)\s*(,|$)/', $flags)) {
                continue;
            }

            $targetsComponent = stripos($rulePattern, 'component') !== false;
            $targetsOption    = false;
            $exemptsAjax      = false;

            foreach ($blockConds as $condition) {
                $negated = str_starts_with($condition['pattern'], '!');
                $pattern = ltrim($condition['pattern'], '!');
                $isUri   = in_array($condition['variable'], ['%{REQUEST_URI}', '%{THE_REQUEST}'], true);
                $isQuery = $condition['variable'] === '%{QUERY_STRING}';

                if ($negated) {
                    if ($isQuery && (stripos($pattern, 'plugin=') !== false || stripos($pattern, 'option=com_ajax') !== false)) {
                        $exemptsAjax = true;
                    }

                    if ($isUri && stripos($pattern, 'component/ajax') !== false) {
                        $exemptsAjax = true;
                    }

                    continue;
                }

                if ($isUri && stripos($pattern, 'component') !== false) {
                    $targetsComponent = true;
                }

                if ($isQuery && stripos($pattern, 'option=com_') !== false) {
                    $targetsOption = true;
                }
            }

            if ($exemptsAjax) {
                continue;
            }

            if ($targetsComponent) {
                $issues['component'] = 'component';
            }

            if ($targetsOption) {
                $issues['option'] = 'option';
            }
        }

        return array_values($issues);
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
