<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Controller;

use Joomla\CMS\MVC\Controller\FormController;

defined('_JEXEC') or die;

/**
 * Edit controller for a single report source (mailbox).
 *
 * @since  1.0.0
 */
class SourceController extends FormController
{
    protected $view_list = 'sources';

    /**
     * {@inheritDoc}
     *
     * When validation fails the base controller stores the raw submitted form in
     * user state so the edit screen can be redisplayed. That payload contains the
     * plaintext mailbox password, which must never be retained in the session, so
     * it is stripped here before control returns to the browser.
     */
    public function save($key = null, $urlVar = null)
    {
        $result  = parent::save($key, $urlVar);
        $context = $this->option . '.edit.' . $this->context . '.data';
        $data    = $this->app->getUserState($context);

        if (\is_array($data) && \array_key_exists('password', $data)) {
            unset($data['password']);
            $this->app->setUserState($context, $data);
        }

        return $result;
    }
}
