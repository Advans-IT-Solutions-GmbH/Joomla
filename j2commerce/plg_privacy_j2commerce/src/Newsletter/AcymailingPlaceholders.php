<?php
/**
 * @package     J2Commerce Privacy Plugin
 * @subpackage  Newsletter
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\Privacy\J2Commerce\Newsletter;

defined('_JEXEC') or die;

use Advans\Plugin\Privacy\J2Commerce\Consent\ConsentRepository;
use Joomla\Database\DatabaseInterface;

/**
 * AcyMailing contacts that only exist because Joomla pseudonymised an account.
 *
 * With AcyMailing's user synchronisation on, saving a Joomla account creates or updates an
 * AcyMailing contact linked by cms_id. Joomla's privacy plugin for user accounts pseudonymises the
 * account of a removal request (name "User ID n", e-mail "UserIDnremoved@email.invalid") and saves
 * it, so AcyMailing creates a contact with that placeholder address. remove() deletes such a
 * contact only when every guard holds:
 *
 * - its cms_id names an existing Joomla account whose e-mail is exactly the placeholder address
 *   of that account ID (the account is pseudonymised),
 * - its own e-mail is exactly the same placeholder address,
 * - it has no list rows (subscribed, unsubscribed or pending): a contact with list rows is kept.
 *
 * Addresses are compared after ConsentRepository::normaliseAddress(), never by the database.
 * Without AcyMailing, without its cms_id column or without its list table nothing is deleted.
 */
final class AcymailingPlaceholders
{
    /** Tables referencing the contact (acym_user.id) through user_id, deleted before the contact. */
    public const RELATED_TABLES = ['user_has_field', 'user_stat', 'url_click', 'history', 'queue'];

    /** Contacts per batch. */
    private const BATCH_SIZE = 200;

    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;

        // Scripts that load this file only (cleanup task, CLI tests) need the address rules too.
        if (!class_exists(ConsentRepository::class)) {
            require_once \dirname(__DIR__) . '/Consent/ConsentRepository.php';
        }
    }

    /**
     * AcyMailing table prefix (with "acym_"), or null if AcyMailing is not installed (no
     * acym_configuration table). Relies on the tables only, so every AcyMailing version works.
     */
    public static function tablePrefix(DatabaseInterface $db): ?string
    {
        $joomlaPrefix = $db->getPrefix();

        try {
            $tables = $db->getTableList();
        } catch (\Exception $e) {
            return null;
        }

        if (\in_array($joomlaPrefix . 'acym_configuration', $tables, true)) {
            return $joomlaPrefix . 'acym_';
        }

        foreach ($tables as $table) {
            if (str_ends_with($table, 'acym_configuration')) {
                return substr($table, 0, -\strlen('configuration'));
            }
        }

        return null;
    }

    /** E-mail address Joomla gives an account when it pseudonymises it, as compared (lower case). */
    public static function placeholderAddress(int $userId): string
    {
        return 'userid' . $userId . 'removed@email.invalid';
    }

    /**
     * Delete the placeholder contacts of pseudonymised accounts.
     *
     * @param   int[]|null  $userIds  Only contacts of these Joomla account IDs (null: all)
     *
     * @return  array{removed: int, kept: int}  Contacts deleted; placeholder contacts kept because they have list rows
     */
    public function remove(?array $userIds = null): array
    {
        $result = ['removed' => 0, 'kept' => 0];
        $prefix = self::tablePrefix($this->db);

        if ($userIds !== null) {
            $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));

            if ($userIds === []) {
                return $result;
            }
        }

        if ($prefix === null) {
            return $result;
        }

        $tables = $this->db->getTableList();

        if (!\in_array($prefix . 'user', $tables, true) || !\in_array($prefix . 'user_has_list', $tables, true)) {
            return $result;
        }

        $columns = array_change_key_case($this->db->getTableColumns($prefix . 'user'), CASE_LOWER);

        if (!isset($columns['cms_id'], $columns['email'])) {
            return $result;
        }

        $related = array_values(array_filter(self::RELATED_TABLES, static fn (string $table): bool => \in_array($prefix . $table, $tables, true)));
        $lastId  = 0;

        do {
            $query = $this->createQuery()
                ->select($this->db->quoteName(['id', 'email', 'cms_id']))
                ->from($this->db->quoteName($prefix . 'user'))
                ->where($this->db->quoteName('cms_id') . ' > 0')
                ->where('LOWER(' . $this->db->quoteName('email') . ') LIKE ' . $this->db->quote('%userid%removed@email.invalid%'))
                ->where($this->db->quoteName('id') . ' > ' . $lastId)
                ->order($this->db->quoteName('id') . ' ASC')
                ->setLimit(self::BATCH_SIZE);

            if ($userIds !== null) {
                $query->whereIn($this->db->quoteName('cms_id'), $userIds);
            }

            $contacts = $this->db->setQuery($query)->loadObjectList() ?: [];

            if ($contacts === []) {
                break;
            }

            $lastId     = (int) end($contacts)->id;
            $candidates = [];

            foreach ($contacts as $contact) {
                $cmsId = (int) $contact->cms_id;

                if (ConsentRepository::normaliseAddress((string) $contact->email) === self::placeholderAddress($cmsId)) {
                    $candidates[(int) $contact->id] = $cmsId;
                }
            }

            $pseudonymised = $this->pseudonymisedAccounts(array_values(array_unique($candidates)));

            foreach ($candidates as $contactId => $cmsId) {
                if (!isset($pseudonymised[$cmsId])) {
                    continue;
                }

                if ($this->deleteContact($prefix, $contactId, $cmsId, $related)) {
                    $result['removed']++;
                } else {
                    $result['kept']++;
                }
            }
        } while (\count($contacts) === self::BATCH_SIZE);

        return $result;
    }

    /**
     * Delete one placeholder contact in a transaction. Every guard is checked again on locked
     * rows (contact, account, list rows) before anything is deleted, and the contact row itself
     * is deleted only while it still has no list row. If a guard no longer holds or the contact
     * is not deleted, the transaction is rolled back, so its related rows stay too.
     *
     * @param   string[]  $related  Existing RELATED_TABLES
     *
     * @return  bool  Whether the contact was deleted (false: kept)
     */
    private function deleteContact(string $prefix, int $contactId, int $cmsId, array $related): bool
    {
        $this->db->transactionStart();

        try {
            $contact = $this->db->setQuery(
                'SELECT ' . $this->db->quoteName('email') . ', ' . $this->db->quoteName('cms_id')
                . ' FROM ' . $this->db->quoteName($prefix . 'user')
                . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $contactId . ' FOR UPDATE'
            )->loadObject();
            $account = $this->db->setQuery(
                'SELECT ' . $this->db->quoteName('email') . ' FROM ' . $this->db->quoteName('#__users')
                . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $cmsId . ' FOR UPDATE'
            )->loadResult();
            $lists = $this->db->setQuery(
                'SELECT ' . $this->db->quoteName('user_id') . ' FROM ' . $this->db->quoteName($prefix . 'user_has_list')
                . ' WHERE ' . $this->db->quoteName('user_id') . ' = ' . $contactId . ' FOR UPDATE'
            )->loadColumn() ?: [];

            $placeholder = self::placeholderAddress($cmsId);

            if (!\is_object($contact) || (int) $contact->cms_id !== $cmsId || $lists !== []
                || ConsentRepository::normaliseAddress((string) $contact->email) !== $placeholder
                || ConsentRepository::normaliseAddress((string) $account) !== $placeholder) {
                $this->db->transactionRollback();

                return false;
            }

            foreach ($related as $table) {
                $this->db->setQuery(
                    $this->createQuery()
                        ->delete($this->db->quoteName($prefix . $table))
                        ->where($this->db->quoteName('user_id') . ' = ' . $contactId)
                )->execute();
            }

            // The list check is repeated in the statement itself.
            $this->db->setQuery(
                'DELETE FROM ' . $this->db->quoteName($prefix . 'user')
                . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $contactId
                . ' AND NOT EXISTS (SELECT 1 FROM ' . $this->db->quoteName($prefix . 'user_has_list', 'l')
                . ' WHERE ' . $this->db->quoteName('l.user_id') . ' = ' . $contactId . ')'
            )->execute();

            if ($this->db->getAffectedRows() < 1) {
                $this->db->transactionRollback();

                return false;
            }

            $this->db->transactionCommit();

            return true;
        } catch (\Throwable $e) {
            $this->db->transactionRollback();

            throw $e;
        }
    }

    /**
     * IDs among $userIds whose Joomla account exists and carries exactly its placeholder address.
     *
     * @param   int[]  $userIds
     *
     * @return  array<int, true>
     */
    private function pseudonymisedAccounts(array $userIds): array
    {
        $found = [];

        foreach (array_chunk($userIds, self::BATCH_SIZE) as $chunk) {
            $rows = $this->db->setQuery(
                $this->createQuery()
                    ->select($this->db->quoteName(['id', 'email']))
                    ->from($this->db->quoteName('#__users'))
                    ->whereIn($this->db->quoteName('id'), $chunk)
            )->loadObjectList() ?: [];

            foreach ($rows as $row) {
                $id = (int) $row->id;

                if (ConsentRepository::normaliseAddress((string) $row->email) === self::placeholderAddress($id)) {
                    $found[$id] = true;
                }
            }
        }

        return $found;
    }

    /**
     * @return  \Joomla\Database\QueryInterface
     */
    private function createQuery()
    {
        return method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
    }
}
