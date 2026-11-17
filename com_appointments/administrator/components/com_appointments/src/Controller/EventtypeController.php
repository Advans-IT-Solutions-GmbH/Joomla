<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Controller;

use Advans\Component\Appointments\Administrator\Service\Close\CredentialStore;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

class EventtypeController extends EditController
{
    protected $view_list = 'eventtypes';

    /**
     * The Close API key and the delete flag of this request, taken out of the form data before the
     * save can keep that data in the session (a refused save) or echo it into the form again.
     *
     * @var array{key: string, delete: bool}|null
     */
    private ?array $closeKey = null;

    public function save($key = null, $urlVar = null)
    {
        $this->closeKey = $this->takeCloseKey();

        try {
            return parent::save($key, $urlVar);
        } finally {
            $this->closeKey = null;
        }
    }

    /**
     * Show the form again with the entered values, for the calendar pickers
     * after a host change. Saves nothing; an existing record must be held
     * through the edit task, like a save.
     */
    public function reload($key = null, $urlVar = null)
    {
        // A reload keeps the entered values in the session: never the Close API key.
        $this->takeCloseKey();

        if (!$this->checkEditRequest($key, $urlVar)) {
            return false;
        }

        $id = $this->input->getInt($urlVar ?: ($key ?: 'id'));

        if (!($id > 0 ? $this->allowEdit(['id' => $id]) : $this->allowAdd())) {
            throw new \Exception(\Joomla\CMS\Language\Text::_('JLIB_APPLICATION_ERROR_EDIT_NOT_PERMITTED'), 403);
        }

        return parent::reload($key, $urlVar);
    }

    protected function allowEdit($data = [], $key = 'id')
    {
        return $this->app->getIdentity()->authorise('core.edit', 'com_appointments.eventtype.' . (int) ($data[$key] ?? 0));
    }

    /**
     * Applies the key change once the event type is saved, so a new event type has its ID. Only a user
     * with core.admin on the component sets or deletes the key. An empty value keeps the stored key.
     * Messages never contain the key.
     */
    protected function postSaveHook(BaseDatabaseModel $model, $validData = [])
    {
        parent::postSaveHook($model, $validData);
        $change = $this->closeKey;
        $this->closeKey = null;

        if ($change === null || ($change['key'] === '' && !$change['delete'])) {
            return;
        }

        if (!$this->app->getIdentity()->authorise('core.admin', 'com_appointments')) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_CLOSE_KEY_NOT_PERMITTED'), 'warning');

            return;
        }

        $eventType = (int) $model->getState($model->getName() . '.id');

        try {
            $store = new CredentialStore(\Joomla\CMS\Factory::getContainer()->get(DatabaseInterface::class));

            if ($change['key'] !== '') {
                $store->put($eventType, $change['key'], (int) $this->app->getIdentity()->id);
                $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_CLOSE_KEY_SAVED'));
            } else {
                $store->delete($eventType);
                $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_CLOSE_KEY_DELETED'));
            }
        } catch (\InvalidArgumentException $e) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_CLOSE_KEY_INVALID'), 'warning');
        } catch (\Throwable $e) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_CLOSE_KEY_FAILED'), 'error');
        }
    }

    /**
     * Removes the Close API key fields from every copy of the request data and returns them.
     *
     * @return array{key: string, delete: bool}
     */
    private function takeCloseKey(): array
    {
        $data = $this->input->post->get('jform', [], 'array');
        $key = $data['close_api_secret'] ?? '';
        $delete = !empty($data['close_api_secret_delete']);
        unset($data['close_api_secret'], $data['close_api_secret_delete']);
        $this->input->post->set('jform', $data);

        $request = $this->input->get('jform', null, 'array');

        if (is_array($request)) {
            unset($request['close_api_secret'], $request['close_api_secret_delete']);
            $this->input->set('jform', $request);
        }

        foreach (['_POST', '_REQUEST'] as $global) {
            if (isset($GLOBALS[$global]['jform']) && is_array($GLOBALS[$global]['jform'])) {
                unset($GLOBALS[$global]['jform']['close_api_secret'], $GLOBALS[$global]['jform']['close_api_secret_delete']);
            }
        }

        return ['key' => is_string($key) ? trim($key) : '', 'delete' => $delete];
    }
}
