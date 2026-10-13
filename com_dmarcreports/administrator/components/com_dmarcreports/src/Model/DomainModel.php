<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Model;

use Advans\Component\DmarcReports\Administrator\Helper\DomainLock;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

/**
 * Admin model for a single monitored domain.
 *
 * @since  1.0.0
 */
class DomainModel extends AdminModel
{
    public function getTable($name = 'Domain', $prefix = 'Administrator', $options = [])
    {
        return parent::getTable($name, $prefix, $options);
    }

    /**
     * Load a domain, seeding a new record's rollout target from the component's
     * default_policy_target option so a manually created domain follows the same
     * configured target as one auto-created during ingestion, rather than a
     * value hard-coded in the form.
     *
     * @param   integer  $pk  The primary key, or null for the state value.
     *
     * @return  mixed  The item object on success, false on failure.
     */
    public function getItem($pk = null)
    {
        $item = parent::getItem($pk);

        if ($item && empty($item->id)) {
            $item->expected_policy = (string) ComponentHelper::getParams('com_dmarcreports')
                ->get('default_policy_target', 'reject');
        }

        return $item;
    }

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm(
            'com_dmarcreports.domain',
            'domain',
            ['control' => 'jform', 'load_data' => $loadData]
        );

        if (empty($form)) {
            return false;
        }

        // Without core.edit.state the published field must not be changeable from
        // the edit form. Disabling it hides the control, and unsetting its filter
        // drops the value from the posted data on save, so the restriction is
        // enforced server-side and cannot be bypassed by crafting the request.
        if (!$this->canEditState((object) $data)) {
            $form->setFieldAttribute('published', 'disabled', 'true');
            $form->setFieldAttribute('published', 'filter', 'unset');
        }

        return $form;
    }

    protected function loadFormData()
    {
        $data = Factory::getApplication()->getUserState('com_dmarcreports.edit.domain.data', []);

        if (empty($data)) {
            $data = $this->getItem();
        }

        return $data;
    }

    /**
     * Delete the given domains together with every child row that belongs to
     * them. Without this cascade the reports, records, actions and sources of a
     * removed domain would be orphaned (there are no database-level foreign
     * keys), so they are cleaned up transactionally before the domains go.
     *
     * @param   array  &$pks  The primary keys of the domains to delete.
     *
     * @return  boolean
     */
    public function delete(&$pks)
    {
        $ids = array_values(array_filter(array_map('intval', (array) $pks), static fn ($id) => $id > 0));

        if ($ids === []) {
            return parent::delete($pks);
        }

        $db = $this->getDatabase();
        $db->transactionStart();

        try {
            // Lock the parent domain rows before clearing children so a
            // concurrent child writer (ingestion, an Action Center status change
            // or a source save) that locks the same row cannot commit a child
            // after these tables are cleared, which would orphan it. See
            // {@see DomainLock} for the shared protocol.
            DomainLock::lockMany($db, $ids);

            foreach (['#__dmarc_records', '#__dmarc_reports', '#__dmarc_actions', '#__dmarc_sources'] as $table) {
                $query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
                    ->delete($db->quoteName($table))
                    ->whereIn($db->quoteName('domain_id'), $ids, ParameterType::INTEGER);
                $db->setQuery($query)->execute();
            }

            // Remove the domains inside the same transaction so children are
            // only ever committed together with their parent rows.
            if (!parent::delete($pks)) {
                $db->transactionRollback();

                return false;
            }

            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();

            throw $e;
        }

        return true;
    }
}
