<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Field;

use Advans\Component\Appointments\Administrator\Helper\CloseKeyCipher;
use Advans\Component\Appointments\Administrator\Service\Close\CredentialStore;
use Advans\Component\Appointments\Administrator\Service\Close\Settings;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * The Close API key of one event type: a masked, always empty input plus the status of the stored key.
 * The stored key is never rendered, only "saved" with its last four characters. An empty submit keeps
 * it, a new value replaces it, the checkbox deletes it (EventtypeController takes both out of the
 * request before anything can store or echo the form data). The field name contains "secret", so
 * Joomla's debug console redacts the submitted value as well.
 */
class ClosekeyField extends FormField
{
    protected $type = 'Closekey';

    protected function getInput()
    {
        $eventType = (int) $this->form->getValue('id');
        $status = ['stored' => false, 'readable' => false, 'hint' => ''];
        $profile = null;
        $routed = false;

        if ($eventType > 0) {
            try {
                $status = (new CredentialStore(Factory::getContainer()->get(DatabaseInterface::class)))->status($eventType);
            } catch (\Throwable $e) {
                $status = ['stored' => false, 'readable' => false, 'hint' => '', 'failed' => true];
            }

            try {
                $profile = (new Settings(ComponentHelper::getParams('com_appointments')))->profileFor($eventType);
                $routed = true;
            } catch (\Throwable $e) {
                // No or an invalid profile configuration: the dashboard reports it.
                $routed = false;
            }
        }

        if (!empty($status['failed'])) {
            $state = '<div class="alert alert-danger">' . Text::_('COM_APPOINTMENTS_CLOSE_KEY_STATUS_FAILED') . '</div>';
        } elseif ($status['stored'] && $status['readable']) {
            $state = '<div class="alert alert-success">' . ($status['hint'] !== ''
                ? Text::sprintf('COM_APPOINTMENTS_CLOSE_KEY_STORED_HINT', htmlspecialchars($status['hint'], ENT_QUOTES, 'UTF-8'))
                : Text::_('COM_APPOINTMENTS_CLOSE_KEY_STORED')) . '</div>';
        } elseif ($status['stored']) {
            $state = '<div class="alert alert-warning">' . Text::_('COM_APPOINTMENTS_CLOSE_KEY_UNREADABLE') . '</div>';
        } else {
            $state = '<div class="alert alert-info">' . Text::_('COM_APPOINTMENTS_CLOSE_KEY_NONE') . '</div>';
        }

        if ($eventType > 0 && $routed) {
            $state .= '<p>' . ($profile !== null
                ? Text::sprintf('COM_APPOINTMENTS_CLOSE_KEY_PROFILE', '<code>' . htmlspecialchars($profile->key, ENT_QUOTES, 'UTF-8') . '</code>')
                : Text::_('COM_APPOINTMENTS_CLOSE_KEY_NO_PROFILE')) . '</p>';
        }

        $id = htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8');
        // The value attribute is always empty: the stored key never reaches the browser.
        $html = '<div class="com-appointments-close-key" id="' . $id . '-state" role="status">' . $state . '</div>'
            . '<input type="password" name="' . htmlspecialchars($this->name, ENT_QUOTES, 'UTF-8') . '" id="' . $id . '" value=""'
            . ' class="form-control" autocomplete="new-password" spellcheck="false" autocapitalize="off" autocorrect="off"'
            . ' maxlength="' . CloseKeyCipher::MAX_LENGTH . '" aria-describedby="' . $id . '-state ' . $id . '-desc">';

        if ($status['stored']) {
            $html .= '<div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="'
                . htmlspecialchars($this->formControl, ENT_QUOTES, 'UTF-8') . '[close_api_secret_delete]" id="' . $id . '_delete" value="1">'
                . '<label class="form-check-label" for="' . $id . '_delete">' . Text::_('COM_APPOINTMENTS_CLOSE_KEY_DELETE') . '</label></div>';
        }

        return $html;
    }
}
