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
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

/**
 * Edit controller for a single blocked period.
 *
 * @since  1.0.0
 */
class BlockedController extends EditController
{
    protected $view_list = 'blockeds';

    /**
     * The fields the calendar hands over in the operator's own time zone.
     *
     * @var  string[]
     */
    private const LOCAL_TIMESTAMPS = ['start_utc', 'end_utc'];

    /**
     * Refuse a wall clock that is not one instant before Joomla converts it.
     *
     * The two bounds are calendar fields with `filter="user_utc"`, so Joomla
     * reads the operator's local time in the operator's zone and stores
     * universal time. That reading is forgiving in three ways, and each one
     * stores an instant nobody entered:
     *
     * - an impossible calendar date such as 2026-02-31 becomes the 3rd of March;
     * - a wall clock in the hour the clocks skip forward is moved past the
     *   transition, so 02:30 becomes 03:30;
     * - a wall clock in the hour the clocks repeat stands for two instants, and
     *   one of them is picked silently.
     *
     * The check therefore has to happen here, on the submitted text, because
     * this is the last point at which the operator's own input still exists.
     *
     * @param   string|null  $key     The name of the primary key of the URL parameter.
     * @param   string|null  $urlVar  The name of the URL parameter the id is in.
     *
     * @return  boolean
     */
    public function save($key = null, $urlVar = null)
    {
        // Early timestamp refusals must not bypass the token or held-edit
        // checks, or write an unheld record's payload into form state.
        if (!$this->checkSaveRequest($key, $urlVar)) {
            return false;
        }

        $this->app->setUserState('com_appointments.edit.blocked.local_timestamps', false);
        $data = (array) $this->input->post->get('jform', [], 'array');
        $zone = $this->operatorZone();

        foreach (self::LOCAL_TIMESTAMPS as $field) {
            $value = trim((string) ($data[$field] ?? ''));

            if ($value === '') {
                continue;
            }

            if (!UtcTimestamp::isTimestamp($value)) {
                return $this->refuse($data, Text::sprintf('COM_APPOINTMENTS_BLOCKED_ERROR_NOT_A_DATE', $value));
            }

            $instants = UtcTimestamp::localInstants($value, $zone);

            if ($instants === []) {
                return $this->refuse(
                    $data,
                    Text::sprintf('COM_APPOINTMENTS_BLOCKED_ERROR_SKIPPED_TIME', $value, $zone->getName())
                );
            }

            if (\count($instants) > 1) {
                return $this->refuse(
                    $data,
                    Text::sprintf(
                        'COM_APPOINTMENTS_BLOCKED_ERROR_AMBIGUOUS_TIME',
                        $value,
                        $zone->getName(),
                        $instants[0]->format('H:i') . ' UTC',
                        $instants[1]->format('H:i') . ' UTC'
                    )
                );
            }
        }

        return parent::save($key, $urlVar);
    }

    /**
     * The zone Joomla's `user_utc` filter will read the two bounds in: the
     * logged-in account's own zone, falling back to the site's.
     *
     * The fallback to UTC is for a site whose offset is unusable, which would
     * otherwise make the check throw on a value the filter still converts.
     */
    private function operatorZone(): \DateTimeZone
    {
        $app      = $this->app;
        $fallback = (string) $app->get('offset', 'UTC');
        $identity = $app->getIdentity();
        $name     = $identity !== null ? (string) $identity->getParam('timezone', $fallback) : $fallback;

        try {
            return new \DateTimeZone($name !== '' ? $name : 'UTC');
        } catch (\Exception $e) {
            return new \DateTimeZone('UTC');
        }
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
        $app->setUserState($context . '.local_timestamps', true);

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
