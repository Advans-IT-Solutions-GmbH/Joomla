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

class PlgAjaxJoomlaajaxformsInstallerScript extends InstallerScript
{
    protected $minimumJoomla = '5.0';
    protected $minimumPhp    = '8.1';

    public function postflight(string $type, object $parent): void
    {
        if ($type !== 'install' && $type !== 'update') {
            return;
        }

        $this->checkHtaccess();
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
        $app      = Factory::getApplication();

        Factory::getLanguage()->load(
            'plg_ajax_joomlaajaxforms',
            JPATH_ADMINISTRATOR
        );

        if (!file_exists($htaccess)) {
            return;
        }

        $content = file_get_contents($htaccess);

        if ($content === false) {
            return;
        }

        $issues = [];

        // Check 1: If /component/ URLs are blocked, is /component/ajax excluded?
        if (preg_match('/RewriteCond.*\/component\/.*\n.*RewriteRule.*component/ims', $content)) {
            $hasAjaxPathException = (bool) preg_match(
                '/RewriteCond.*component\/ajax/im',
                $content
            );

            if (!$hasAjaxPathException) {
                $issues[] = Text::_('PLG_AJAX_JOOMLAAJAXFORMS_HTACCESS_COMPONENT_BLOCKED');
            }
        }

        // Check 2: If index.php?option=com_* is blocked, is com_ajax excluded?
        if (preg_match('/RewriteCond.*QUERY_STRING.*\^option=com_/im', $content)) {
            $hasAjaxException = (bool) preg_match(
                '/RewriteCond.*QUERY_STRING.*!.*option=com_ajax/im',
                $content
            );

            if (!$hasAjaxException) {
                $issues[] = Text::_('PLG_AJAX_JOOMLAAJAXFORMS_HTACCESS_OPTION_BLOCKED');
            }
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

        $app->enqueueMessage($msg, 'warning');
    }
}
