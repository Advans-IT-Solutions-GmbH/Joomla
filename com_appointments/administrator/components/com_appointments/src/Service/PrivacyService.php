<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service;

use Advans\Component\Appointments\Administrator\Helper\PrivacyAddress;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

final class PrivacyService
{
    public function __construct(private DatabaseInterface $db, private Registry $params)
    {
    }

    public function bookings(string $address): \Generator
    {
        $normalised = PrivacyAddress::normalise($address);
        $db = $this->db;
        $last = 0;
        $column = $db->quoteName('b.invitee_email');
        $value = 'TRIM(' . $column . ')';
        // Like the shop privacy plugin, SQL only supplies a lossless candidate
        // set; accent-insensitive collations must never decide ownership.
        $candidates = ['LENGTH(' . $value . ') <> CHAR_LENGTH(' . $value . ')',
            $value . " REGEXP '[[:cntrl:]]'"];
        if (preg_match('/[^\x00-\x7F]/', $normalised) !== 1) {
            $candidates[] = 'LOWER(CONVERT(' . $value . ' USING utf8mb4) COLLATE utf8mb4_bin) = ' . $db->quote($normalised);
        }

        do {
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->select($db->quoteName('b') . '.*')->select($db->quoteName('e.title', 'event_title'))
                ->from($db->quoteName('#__appointments_bookings', 'b'))
                ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e') . ' ON '
                    . $db->quoteName('e.id') . ' = ' . $db->quoteName('b.eventtype_id'))
                ->where($db->quoteName('b.id') . ' > ' . $last)
                ->where($column . ' IS NOT NULL')
                ->where('(' . implode(' OR ', $candidates) . ')')
                ->order($db->quoteName('b.id') . ' ASC')->setLimit(200);
            $rows = $db->setQuery($query)->loadAssocList();
            foreach ($rows as $row) {
                $last = (int) $row['id'];
                if (PrivacyAddress::matches($row['invitee_email'], $normalised)) {
                    yield $row;
                }
            }
        } while (count($rows) === 200);
    }

    public function legalReason(): string
    {
        return trim((string) $this->params->get('removal_block_reason', ''));
    }

    public function fieldLabels(int $eventId): array
    {
        $db = $this->db;
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['id', 'label']))->from($db->quoteName('#__appointments_fields'))
            ->where($db->quoteName('eventtype_id') . ' = ' . $eventId);

        return array_column($db->setQuery($query)->loadAssocList(), 'label', 'id');
    }

    public function remove(string $address, \DateTimeImmutable $now): array
    {
        $mode = (string) $this->params->get('erase_mode', 'anonymise');
        if (!in_array($mode, ['anonymise', 'delete'], true)) {
            throw new \InvalidArgumentException('Invalid appointment erasure mode.');
        }
        $store = new BookingStore($this->db);
        $mail = new NotificationService($this->db);
        $result = ['removed' => 0, 'mail_failed' => 0, 'cleanup_failed' => 0];
        foreach ($this->bookings($address) as $booking) {
            if ($this->legalReason() !== '') {
                throw new \RuntimeException('Appointment removal is blocked by the configured legal reason.');
            }
            $erasure = $store->erase((int) $booking['id'], $now, 'privacy_request', $mode === 'delete');
            $result['removed'] += $erasure['changed'] ? 1 : 0;
            $result['cleanup_failed'] += !empty($erasure['_conference_cleanup_error']) ? 1 : 0;
            if ($erasure['notification'] !== null) {
                try {
                    $mail->send($erasure['notification'], 'erasure_notice', $now, true);
                } catch (NotificationException $e) {
                    $result['mail_failed']++;
                }
            }
        }

        return $result;
    }
}
