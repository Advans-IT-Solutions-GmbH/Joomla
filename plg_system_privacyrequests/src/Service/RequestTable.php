<?php
/**
 * @package     Privacy Requests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\PrivacyRequests\Service;

use Joomla\Component\Privacy\Administrator\Table\RequestTable as CoreRequestTable;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

final class RequestTable extends CoreRequestTable
{
    public function __construct(DatabaseInterface $db, private string $address, private int $requestId, private string $tokenHash)
    {
        parent::__construct($db);
    }

    public function load($keys = null, $reset = true)
    {
        if ($this->requestId > 0) {
            // Both the Core email/status lookup and AdminModel's later id lookup are
            // pinned to the same exact-address row, held locked by the service transaction.
            if (!is_array($keys) && (int) $keys !== $this->requestId) {
                return false;
            }
            if (!parent::load($this->requestId, $reset)) {
                return false;
            }
            if (Admission::address((string) $this->email) !== Admission::address($this->address)
                || (int) $this->status !== 0 || !hash_equals($this->tokenHash, (string) $this->confirm_token)) {
                throw new \RuntimeException('The privacy request changed during confirmation.');
            }

            return true;
        }

        return parent::load($keys, $reset);
    }

    public function store($updateNulls = true)
    {
        if (Admission::address((string) $this->email) !== Admission::address($this->address)
            || $this->request_type !== 'remove') {
            throw new \RuntimeException('Privacy request scope mismatch.');
        }

        // The Core model remains the sole author of the confirmation transition.
        // An expired request never reaches it; renewal keeps its original requested_at.
        if ($this->requestId > 0 && ((int) $this->id !== $this->requestId || (int) $this->status !== 1)) {
            throw new \RuntimeException('Unexpected privacy request transition.');
        }

        return parent::store($updateNulls);
    }
}
