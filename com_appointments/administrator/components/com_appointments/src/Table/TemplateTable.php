<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Table;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;

defined('_JEXEC') or die;

class TemplateTable extends Table
{
    public const PURPOSES = ['confirmation', 'pending', 'approved', 'reschedule', 'cancellation', 'reminder', 'host_notice', 'erasure_notice',
        'guest_confirmation', 'guest_pending', 'guest_approved', 'guest_reschedule', 'guest_cancellation', 'guest_reminder',
        'host_approved', 'host_reschedule', 'host_cancellation', 'host_reminder',
        'team_device_added', 'team_invitation', 'team_invitation_accepted', 'team_grant_changed', 'team_access_suspended'];

    /** Mails of the internet calendars: site-wide only, an event type has no say in them. */
    public const SITE_WIDE_PURPOSES = ['team_device_added', 'team_invitation', 'team_invitation_accepted', 'team_grant_changed', 'team_access_suspended'];

    /** The link each of these mails must carry, alone on its line. */
    private const LINK_PLACEHOLDERS = ['team_invitation' => '{activation_url}', 'team_invitation_accepted' => '{revoke_url}'];

    public function __construct(DatabaseDriver $db)
    {
        parent::__construct('#__appointments_templates', 'id', $db);
    }

    public function check(): bool
    {
        if (!in_array($this->purpose, self::PURPOSES, true)
            || preg_match('/^(0|[1-9][0-9]*)$/D', (string) $this->eventtype_id) !== 1
            || !is_string($this->language)
            || preg_match('/^[a-z]{2,3}-[A-Z]{2}$/D', $this->language) !== 1
            || trim((string) $this->subject) === '' || mb_strlen((string) $this->subject) > 255
            || preg_match('/[\x00-\x1f\x7f]/', (string) $this->subject)
            || trim((string) $this->body) === ''
            || !in_array((int) $this->published, [0, 1], true)) {
            $this->setError(Text::_('COM_APPOINTMENTS_TEMPLATE_ERROR_REQUIRED'));

            return false;
        }

        if (in_array($this->purpose, self::SITE_WIDE_PURPOSES, true) && (int) $this->eventtype_id !== 0) {
            $this->setError(Text::_('COM_APPOINTMENTS_TEAM_D_TEMPLATE_ERROR_SITE_WIDE'));

            return false;
        }

        if (in_array($this->purpose, self::SITE_WIDE_PURPOSES, true) && !$this->teamTemplateAllowed()) {
            return false;
        }

        if ($this->duplicate()) {
            $this->setError(Text::_('COM_APPOINTMENTS_TEMPLATE_ERROR_DUPLICATE'));

            return false;
        }

        return true;
    }

    /**
     * The internet calendar mails carry an activation or a revoke link: only a user with
     * core.admin on the component edits them in the backend, and the link stays on a line
     * of its own, so a template can neither drop it nor wrap it into another address.
     */
    private function teamTemplateAllowed(): bool
    {
        $app = Factory::getApplication();

        if ($app->isClient('administrator') && !$app->getIdentity()?->authorise('core.admin', 'com_appointments')) {
            $this->setError(Text::_('COM_APPOINTMENTS_TEAM_D_TEMPLATE_ERROR_ADMIN'));

            return false;
        }

        $link = self::LINK_PLACEHOLDERS[$this->purpose] ?? null;
        $lines = array_map('trim', preg_split('/\R/', (string) $this->body) ?: []);

        if ($link !== null && (!in_array($link, $lines, true) || substr_count((string) $this->body, $link) !== 1)) {
            $this->setError(Text::sprintf('COM_APPOINTMENTS_TEAM_D_TEMPLATE_ERROR_LINK', $link));

            return false;
        }

        return true;
    }

    private function duplicate(): bool
    {
        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName('id'))->from($db->quoteName('#__appointments_templates'))
            ->where($db->quoteName('eventtype_id') . ' = ' . (int) $this->eventtype_id)
            ->where($db->quoteName('purpose') . ' = ' . $db->quote($this->purpose))
            ->where($db->quoteName('language') . ' = ' . $db->quote($this->language))
            ->where($db->quoteName('id') . ' <> ' . (int) $this->id);

        return (bool) $db->setQuery($query)->loadResult();
    }

    public function store($updateNulls = false): bool
    {
        try {
            $result = parent::store($updateNulls);

            if (!$result && $this->duplicate()) {
                $this->setError(Text::_('COM_APPOINTMENTS_TEMPLATE_ERROR_DUPLICATE'));
            }

            return $result;
        } catch (\RuntimeException $e) {
            // The unique index also protects concurrent saves after check().
            if (!$this->duplicate()) {
                throw $e;
            }

            $this->setError(Text::_('COM_APPOINTMENTS_TEMPLATE_ERROR_DUPLICATE'));

            return false;
        }
    }
}
