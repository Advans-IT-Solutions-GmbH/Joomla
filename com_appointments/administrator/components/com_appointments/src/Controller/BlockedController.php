<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Controller;

use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

/**
 * Edit controller for a single blocked period.
 *
 * @since  1.0.0
 */
class BlockedController extends FormController
{
    protected $view_list = 'blockeds';

    /**
     * The fields the calendar hands over in the operator's own time zone.
     *
     * @var  string[]
     */
    private const LOCAL_TIMESTAMPS = ['start_utc', 'end_utc'];

    /**
     * Refuse a date that does not exist before Joomla converts it.
     *
     * The two bounds are calendar fields with `filter="user_utc"`, so Joomla
     * turns the operator's local time into universal time. That filter is also
     * forgiving: it reads an impossible 2026-02-31 as the 3rd of March, and by
     * the time the table sees the value it is a perfectly good instant that
     * nobody entered. The strict check therefore has to happen here, on the
     * submitted text, because this is the last point at which the operator's
     * own input still exists.
     *
     * @param   string|null  $key     The name of the primary key of the URL parameter.
     * @param   string|null  $urlVar  The name of the URL parameter the id is in.
     *
     * @return  boolean
     */
    public function save($key = null, $urlVar = null)
    {
        $data = (array) $this->input->post->get('jform', [], 'array');

        foreach (self::LOCAL_TIMESTAMPS as $field) {
            $value = trim((string) ($data[$field] ?? ''));

            if ($value !== '' && !UtcTimestamp::isTimestamp($value)) {
                return $this->refuse($data, Text::sprintf('COM_APPOINTMENTS_BLOCKED_ERROR_NOT_A_DATE', $value));
            }
        }

        return parent::save($key, $urlVar);
    }

    /**
     * Send the operator back to the form with what they entered and why it was
     * refused, the way Joomla does it for a failed validation.
     *
     * @param   array   $data     The submitted form data.
     * @param   string  $message  The message to show.
     */
    private function refuse(array $data, string $message): bool
    {
        $app     = $this->app;
        $context = 'com_appointments.edit.blocked';
        $id      = (int) ($data['id'] ?? 0);

        $app->enqueueMessage($message, 'error');
        $app->setUserState($context . '.data', $data);

        $this->setRedirect(
            Route::_(
                'index.php?option=com_appointments&view=blocked&layout=edit'
                . ($id > 0 ? '&id=' . $id : ''),
                false
            )
        );

        return false;
    }
}
