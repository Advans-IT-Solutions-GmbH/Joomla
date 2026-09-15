<?php
/**
 * @package     J2Commerce Privacy Plugin
 * @subpackage  Consent
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

namespace Advans\Plugin\Privacy\J2Commerce\Consent;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Checkout consent records in Joomla's core #__privacy_consents table.
 *
 * Data model (Joomla core, unchanged): id, user_id, state, created, subject, body, remind, token.
 * - One record per J2Commerce order, written only when the shopper ticked the consent checkbox.
 * - user_id is the order's user_id (0 for guest orders), exactly as J2Commerce stored it.
 * - The order is referenced by a language-independent marker in body. No e-mail address is copied:
 *   guests are traced through the order's own user_email column.
 * - state follows core semantics: 1 = valid, 0 = obsolete, -1 = invalidated. Only state 1 counts.
 */
final class ConsentRepository
{
    /** Subject language key; translated in com_privacy by the bundled system plugin language file. */
    public const SUBJECT = 'PLG_SYSTEM_J2COMMERCEPRIVACY_CONSENT_SUBJECT';

    /** Body language key (order number, IP address, user agent). */
    public const BODY_KEY = 'PLG_SYSTEM_J2COMMERCEPRIVACY_CONSENT_BODY';

    /** Subject written by Joomla's own privacy consent plugin (registration / profile). */
    public const CORE_SUBJECT = 'PLG_SYSTEM_PRIVACYCONSENT_SUBJECT';

    private const MARKER_PREFIX = '<!-- j2commerce-order:';
    private const MARKER_SUFFIX = ' -->';

    /** Upper bound of guest orders inspected for one status lookup. */
    private const MAX_ORDERS = 500;

    private DatabaseInterface $db;

    private ?bool $isJ2Commerce4 = null;

    public function __construct(?DatabaseInterface $db = null)
    {
        $this->db = $db ?? Factory::getContainer()->get(DatabaseInterface::class);
    }

    /**
     * Whether an order number can be referenced safely. J2Commerce generates numeric order IDs;
     * the character set is limited so the marker can never break out of its HTML comment.
     */
    public static function isValidOrderId(string $orderId): bool
    {
        return $orderId !== '' && \strlen($orderId) <= 100 && preg_match('/^[A-Za-z0-9_.\-]+$/', $orderId) === 1;
    }

    public static function orderMarker(string $orderId): string
    {
        return self::MARKER_PREFIX . $orderId . self::MARKER_SUFFIX;
    }

    /**
     * Extract the referenced order number from a consent body, or null if there is none.
     */
    public static function extractOrderId(string $body): ?string
    {
        if (preg_match('/<!-- j2commerce-order:([A-Za-z0-9_.\-]+) -->/', $body, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Build the consent body: the same evidence Joomla core records (IP address, user agent),
     * plus the order number. Values are escaped because com_privacy renders the body as HTML.
     */
    public function buildBody(string $orderId, string $ipAddress, string $userAgent): string
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        return Text::sprintf(self::BODY_KEY, $escape($orderId), $escape($ipAddress), $escape($userAgent))
            . self::orderMarker($orderId);
    }

    /**
     * Return the valid consent record for an order, or null.
     */
    public function findOrderConsent(string $orderId): ?object
    {
        if (!self::isValidOrderId($orderId)) {
            return null;
        }

        $query = $this->createQuery()
            ->select($this->db->quoteName(['id', 'user_id', 'state', 'created', 'subject', 'body']))
            ->from($this->db->quoteName('#__privacy_consents'))
            ->where($this->db->quoteName('state') . ' = 1')
            ->where($this->db->quoteName('subject') . ' = ' . $this->db->quote(self::SUBJECT))
            ->where($this->db->quoteName('body') . ' LIKE ' . $this->likeMarker($orderId))
            ->order($this->db->quoteName('id') . ' ASC');

        $this->db->setQuery($query, 0, 1);

        return $this->db->loadObject() ?: null;
    }

    /**
     * Record consent for an order unless a valid record already exists (no duplicates).
     *
     * @return  array{id: int, created: bool}|null  Null when the order number is not usable.
     */
    public function ensureOrderConsent(string $orderId, int $userId, string $body): ?array
    {
        if (!self::isValidOrderId($orderId) || !str_contains($body, self::orderMarker($orderId))) {
            return null;
        }

        $existing = $this->findOrderConsent($orderId);

        if ($existing !== null) {
            return ['id' => (int) $existing->id, 'created' => false];
        }

        $record = (object) [
            'user_id' => max(0, $userId),
            'state'   => 1,
            'created' => Factory::getDate()->toSql(),
            'subject' => self::SUBJECT,
            'body'    => $body,
            'remind'  => 0,
            'token'   => '',
        ];

        $this->db->insertObject('#__privacy_consents', $record, 'id');

        return ['id' => (int) $record->id, 'created' => true];
    }

    /**
     * Consent status for the MyProfile privacy tab.
     *
     * - Logged-in user: every valid record with the user's user_id (checkout consents of the user's
     *   own orders and Joomla's registration/profile consent).
     * - Guest (user_id 0): valid checkout consents of guest orders placed with the given e-mail
     *   address. The caller must have verified the guest session (order token + e-mail) first.
     *
     * @return  array{consented: bool, latest: ?string, records: list<object>}
     */
    public function getStatus(int $userId, string $guestEmail = ''): array
    {
        $records = $userId > 0 ? $this->loadUserConsents($userId) : $this->loadGuestConsents($guestEmail);

        usort($records, static fn (object $a, object $b): int => strcmp((string) $b->created, (string) $a->created));

        foreach ($records as $record) {
            $record->order_id = $record->subject === self::SUBJECT ? self::extractOrderId((string) $record->body) : null;
            $record->source   = $record->order_id !== null ? 'checkout' : 'account';
            unset($record->body);
        }

        return [
            'consented' => $records !== [],
            'latest'    => $records !== [] ? (string) $records[0]->created : null,
            'records'   => $records,
        ];
    }

    /** @return list<object> */
    private function loadUserConsents(int $userId): array
    {
        $query = $this->createQuery()
            ->select($this->db->quoteName(['id', 'user_id', 'created', 'subject', 'body']))
            ->from($this->db->quoteName('#__privacy_consents'))
            ->where($this->db->quoteName('state') . ' = 1')
            ->where($this->db->quoteName('user_id') . ' = :userid')
            ->bind(':userid', $userId, ParameterType::INTEGER);

        $this->db->setQuery($query);

        return $this->db->loadObjectList() ?: [];
    }

    /** @return list<object> */
    private function loadGuestConsents(string $guestEmail): array
    {
        $guestEmail = trim($guestEmail);

        if ($guestEmail === '') {
            return [];
        }

        $query = $this->createQuery()
            ->select($this->db->quoteName('order_id'))
            ->from($this->db->quoteName($this->ordersTable()))
            ->where($this->db->quoteName('user_id') . ' = 0')
            ->where($this->db->quoteName('user_email') . ' = :email')
            ->bind(':email', $guestEmail)
            ->order($this->db->quoteName('created_on') . ' DESC');

        $this->db->setQuery($query, 0, self::MAX_ORDERS);

        $orderIds = array_values(array_filter(
            array_map('strval', $this->db->loadColumn() ?: []),
            [self::class, 'isValidOrderId']
        ));

        if ($orderIds === []) {
            return [];
        }

        $markers = array_map(
            fn (string $orderId): string => $this->db->quoteName('body') . ' LIKE ' . $this->likeMarker($orderId),
            $orderIds
        );

        $query = $this->createQuery()
            ->select($this->db->quoteName(['id', 'user_id', 'created', 'subject', 'body']))
            ->from($this->db->quoteName('#__privacy_consents'))
            ->where($this->db->quoteName('state') . ' = 1')
            ->where($this->db->quoteName('user_id') . ' = 0')
            ->where($this->db->quoteName('subject') . ' = ' . $this->db->quote(self::SUBJECT))
            ->where('(' . implode(' OR ', $markers) . ')');

        $this->db->setQuery($query);

        return $this->db->loadObjectList() ?: [];
    }

    private function likeMarker(string $orderId): string
    {
        return $this->db->quote('%' . $this->db->escape(self::orderMarker($orderId), true) . '%', false);
    }

    private function ordersTable(): string
    {
        if ($this->isJ2Commerce4 === null) {
            $this->isJ2Commerce4 = \in_array($this->db->getPrefix() . 'j2store_orders', $this->db->getTableList(), true);
        }

        return $this->isJ2Commerce4 ? '#__j2store_orders' : '#__j2commerce_orders';
    }

    /**
     * @return  \Joomla\Database\QueryInterface
     */
    private function createQuery()
    {
        return method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
    }
}
