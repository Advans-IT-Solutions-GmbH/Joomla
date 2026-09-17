<?php
/**
 * @package     J2Commerce Privacy Plugin
 * @subpackage  Consent
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

namespace Advans\Plugin\Privacy\J2Commerce\Consent;

defined('_JEXEC') or die;

use Advans\Plugin\Privacy\J2Commerce\Support\J2CommerceStack;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Checkout consent records in Joomla's core #__privacy_consents table.
 *
 * Data model (Joomla core, unchanged): id, user_id, state, created, subject, body, remind, token.
 * - One record per J2Commerce order, written only when the shopper ticked the consent checkbox.
 * - user_id is the order's user_id (0 for guest orders), exactly as J2Commerce stored it.
 * - body holds order number, IP address and user agent, plus a language-independent order marker.
 *   No e-mail address is copied; guests are traced through the order (token + user_email).
 *   IP address and user agent are removed when the plugin anonymizes the order (removeOrderEvidence).
 * - state follows core semantics: 1 = valid, 0 = obsolete, -1 = invalidated. Only state 1 counts.
 * - Legacy records of an earlier template override (LEGACY_SUBJECT) are only anonymized by
 *   anonymizeLegacyConsents() (installer, removal request, cleanup task) and never counted as consent.
 */
final class ConsentRepository
{
    /** Subject language key; translated in com_privacy by the bundled system plugin language file. */
    public const SUBJECT = 'PLG_SYSTEM_J2COMMERCEPRIVACY_CONSENT_SUBJECT';

    /** Body language key (order number, IP address, user agent). */
    public const BODY_KEY = 'PLG_SYSTEM_J2COMMERCEPRIVACY_CONSENT_BODY';

    /** Subject written by Joomla's own privacy consent plugin (registration / profile). */
    public const CORE_SUBJECT = 'PLG_SYSTEM_PRIVACYCONSENT_SUBJECT';

    /** Body language key after anonymization (order number only). */
    public const BODY_REMOVED_KEY = 'PLG_SYSTEM_J2COMMERCEPRIVACY_CONSENT_BODY_EVIDENCE_REMOVED';

    /** Marks a body whose IP address and user agent were removed. */
    public const EVIDENCE_REMOVED_MARKER = '<!-- j2commerce-evidence-removed -->';

    /**
     * Subject of consent records written by an earlier advans template override (checkout confirm
     * step and MyProfile tab). Their body holds the e-mail address, IP address and user agent but
     * no order reference; anonymizeLegacyConsents() anonymizes them.
     */
    public const LEGACY_SUBJECT = 'PLG_PRIVACY_J2COMMERCE';

    /** Subject of an anonymized legacy record (translated in the system plugin language file). */
    public const LEGACY_DONE_SUBJECT = 'PLG_SYSTEM_J2COMMERCEPRIVACY_CONSENT_SUBJECT_LEGACY';

    /** Body language key of an anonymized legacy record. */
    public const LEGACY_BODY_KEY = 'PLG_SYSTEM_J2COMMERCEPRIVACY_CONSENT_BODY_LEGACY';

    /** Fallback body when the language key is missing during an update request. */
    private const LEGACY_BODY_FALLBACK = '<p>Legacy consent entry from an earlier site template. Personal data (e-mail address, IP address, user agent) was removed.</p>';

    /** Marks an anonymized legacy record. */
    public const LEGACY_MARKER = '<!-- j2commerce-legacy-consent -->';

    /** Rows per batch in the bulk operations. */
    public const BATCH_SIZE = 200;

    private const MARKER_PREFIX = '<!-- j2commerce-order:';
    private const MARKER_SUFFIX = ' -->';

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
        $language = self::loadBodyLanguage();

        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        return sprintf($language->_(self::BODY_KEY), $escape($orderId), $escape($ipAddress), $escape($userAgent))
            . self::orderMarker($orderId);
    }

    /**
     * Body of a consent record after its order was anonymized: consent and order number stay as
     * evidence, IP address and user agent are gone.
     */
    public function buildEvidenceRemovedBody(string $orderId): string
    {
        $language = self::loadBodyLanguage();

        return sprintf($language->_(self::BODY_REMOVED_KEY), htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8'))
            . self::orderMarker($orderId) . self::EVIDENCE_REMOVED_MARKER;
    }

    /**
     * Remove IP address and user agent from the checkout consent records of these orders (any
     * state). Called when the plugin anonymizes the orders (retention period expired, removal
     * request or cleanup task): the personal evidence is kept exactly as long as the order.
     * The record itself (user_id, state, created, subject, order number) is kept.
     *
     * @param   string[]  $orderIds
     *
     * @return  int  Number of records changed
     */
    public function removeOrderEvidence(array $orderIds): int
    {
        $changed = 0;

        foreach (array_unique(array_map('strval', $orderIds)) as $orderId) {
            if (!self::isValidOrderId($orderId)) {
                continue;
            }

            $query = $this->createQuery()
                ->select($this->db->quoteName(['id', 'body']))
                ->from($this->db->quoteName('#__privacy_consents'))
                ->where($this->db->quoteName('subject') . ' = ' . $this->db->quote(self::SUBJECT))
                ->where($this->db->quoteName('body') . ' LIKE ' . $this->likeMarker($orderId));
            $this->db->setQuery($query);

            foreach ($this->db->loadObjectList() ?: [] as $record) {
                if (str_contains((string) $record->body, self::EVIDENCE_REMOVED_MARKER)) {
                    continue;
                }

                $body   = $this->buildEvidenceRemovedBody($orderId);
                $id     = (int) $record->id;
                $update = $this->createQuery()
                    ->update($this->db->quoteName('#__privacy_consents'))
                    ->set($this->db->quoteName('body') . ' = :body')
                    ->where($this->db->quoteName('id') . ' = :id')
                    ->bind(':body', $body)
                    ->bind(':id', $id, ParameterType::INTEGER);
                $this->db->setQuery($update)->execute();
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Update already anonymized legacy records that still store the raw language key.
     */
    private function repairLegacyAnonymizedBodies(string $body): int
    {
        $changed = 0;
        $lastId  = 0;
        $like    = $this->db->quote($this->db->escape(self::LEGACY_BODY_KEY, true) . '%', false);

        do {
            $query = $this->createQuery()
                ->select($this->db->quoteName('id'))
                ->from($this->db->quoteName('#__privacy_consents'))
                ->where($this->db->quoteName('subject') . ' = ' . $this->db->quote(self::LEGACY_DONE_SUBJECT))
                ->where($this->db->quoteName('body') . ' LIKE ' . $like)
                ->where($this->db->quoteName('id') . ' > ' . $lastId)
                ->order($this->db->quoteName('id') . ' ASC')
                ->setLimit(self::BATCH_SIZE);
            $this->db->setQuery($query);
            $ids = array_map('intval', $this->db->loadColumn() ?: []);

            if ($ids === []) {
                break;
            }

            $this->db->setQuery(
                $this->createQuery()
                    ->update($this->db->quoteName('#__privacy_consents'))
                    ->set($this->db->quoteName('body') . ' = :body')
                    ->whereIn($this->db->quoteName('id'), $ids)
                    ->bind(':body', $body)
            )->execute();

            $changed += \count($ids);
            $lastId   = max($ids);
        } while (\count($ids) === self::BATCH_SIZE);

        return $changed;
    }

    private static function loadBodyLanguage(): Language
    {
        $language = self::currentLanguage();
        $language->load('plg_system_j2commerceprivacy', JPATH_ADMINISTRATOR)
            || $language->load('plg_system_j2commerceprivacy', JPATH_PLUGINS . '/system/j2commerceprivacy');

        if (!$language->hasKey(self::LEGACY_BODY_KEY)) {
            $language->load('plg_system_j2commerceprivacy', JPATH_PLUGINS . '/system/j2commerceprivacy', null, true)
                || $language->load('plg_system_j2commerceprivacy', JPATH_ADMINISTRATOR, null, true);
        }

        return $language;
    }

    private static function legacyBodyText(Language $language): string
    {
        $text = $language->_(self::LEGACY_BODY_KEY);

        if ($text === '' || $text === self::LEGACY_BODY_KEY) {
            return self::LEGACY_BODY_FALLBACK;
        }

        return $text;
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
            ->order($this->db->quoteName('id') . ' ASC')
            ->setLimit(1);

        $this->db->setQuery($query);

        return $this->db->loadObject() ?: null;
    }

    /**
     * Record consent for an order unless a valid record already exists (no duplicates). Two
     * parallel requests (double submit) can both insert; the later rows are removed again, so one
     * record per order remains.
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
        $insertedId = (int) $record->id;
        $keep       = $this->removeDuplicateConsents($orderId) ?? $insertedId;

        return ['id' => $keep, 'created' => $keep === $insertedId];
    }

    /**
     * Keep only the oldest valid checkout consent record of an order (two parallel requests can
     * both pass the check in ensureOrderConsent() and insert).
     *
     * @return  int|null  ID of the record kept, null if there is none
     */
    public function removeDuplicateConsents(string $orderId): ?int
    {
        if (!self::isValidOrderId($orderId)) {
            return null;
        }

        $query = $this->createQuery()
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__privacy_consents'))
            ->where($this->db->quoteName('state') . ' = 1')
            ->where($this->db->quoteName('subject') . ' = ' . $this->db->quote(self::SUBJECT))
            ->where($this->db->quoteName('body') . ' LIKE ' . $this->likeMarker($orderId))
            ->order($this->db->quoteName('id') . ' ASC');
        $this->db->setQuery($query);
        $ids = array_map('intval', $this->db->loadColumn() ?: []);

        if ($ids === []) {
            return null;
        }

        $keep  = min($ids);
        $extra = array_values(array_filter($ids, static fn (int $id): bool => $id !== $keep));

        if ($extra !== []) {
            $this->db->setQuery(
                $this->createQuery()
                    ->delete($this->db->quoteName('#__privacy_consents'))
                    ->whereIn($this->db->quoteName('id'), $extra)
            )->execute();
        }

        return $keep;
    }

    /**
     * Anonymize consent records of an earlier template override (LEGACY_SUBJECT). Part of them was
     * created afterwards (when the MyProfile tab was opened, dated with the newest order), so they
     * are no evidence of a checkout consent and are never assigned to an order. The e-mail address,
     * IP address and user agent are removed; the body gets a neutral note and the subject
     * LEGACY_DONE_SUBJECT. Such records are not counted as consent.
     *
     * @param   int|null  $userId  Only this user's records (null: all)
     * @param   string[]  $emails  With $userId: also guest records (user_id 0) mentioning one of these addresses
     *
     * @return  int  Number of records anonymized
     */
    public function anonymizeLegacyConsents(?int $userId = null, array $emails = []): int
    {
        $body    = self::legacyBodyText(self::loadBodyLanguage()) . self::LEGACY_MARKER . self::EVIDENCE_REMOVED_MARKER;
        $emails  = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $emails)), 'strlen')));
        $changed = $this->repairLegacyAnonymizedBodies($body);
        $lastId  = 0;

        do {
            $query = $this->createQuery()
                ->select($this->db->quoteName('id'))
                ->from($this->db->quoteName('#__privacy_consents'))
                ->where($this->db->quoteName('subject') . ' = ' . $this->db->quote(self::LEGACY_SUBJECT))
                ->where($this->db->quoteName('id') . ' > ' . $lastId)
                ->order($this->db->quoteName('id') . ' ASC')
                ->setLimit(self::BATCH_SIZE);

            if ($userId !== null) {
                $scope = [$this->db->quoteName('user_id') . ' = ' . (int) $userId];

                foreach ($emails as $email) {
                    $scope[] = '(' . $this->db->quoteName('user_id') . ' = 0 AND ' . $this->db->quoteName('body') . ' LIKE '
                        . $this->db->quote('%' . $this->db->escape(htmlspecialchars($email, ENT_QUOTES, 'UTF-8'), true) . '%', false) . ')';
                }

                $query->where('(' . implode(' OR ', $scope) . ')');
            }

            $this->db->setQuery($query);
            $ids = array_map('intval', $this->db->loadColumn() ?: []);

            if ($ids === []) {
                break;
            }

            $update = $this->createQuery()
                ->update($this->db->quoteName('#__privacy_consents'))
                ->set($this->db->quoteName('subject') . ' = ' . $this->db->quote(self::LEGACY_DONE_SUBJECT))
                ->set($this->db->quoteName('body') . ' = :body')
                ->whereIn($this->db->quoteName('id'), $ids)
                ->bind(':body', $body);
            $this->db->setQuery($update)->execute();

            $changed += \count($ids);
            $lastId   = max($ids);
        } while (\count($ids) === self::BATCH_SIZE);

        return $changed;
    }

    /**
     * Remove IP address and user agent from checkout consent records whose order no longer needs
     * them: the record is older than the retention cutoff, its order no longer exists in any
     * J2Commerce data set, or the order was already anonymized.
     *
     * @param   string    $cutoff  created <= cutoff (UTC) is outside the retention period
     * @param   string[]  $tables  Order tables to look in (for example #__j2commerce_orders, #__j2store_orders)
     *
     * @return  int  Number of records changed
     */
    public function removeStaleEvidence(string $cutoff, array $tables): int
    {
        $changed = 0;
        $lastId  = 0;

        do {
            $query = $this->createQuery()
                ->select($this->db->quoteName(['id', 'created', 'body']))
                ->from($this->db->quoteName('#__privacy_consents'))
                ->where($this->db->quoteName('subject') . ' = ' . $this->db->quote(self::SUBJECT))
                ->where($this->db->quoteName('body') . ' NOT LIKE ' . $this->db->quote('%' . $this->db->escape(self::EVIDENCE_REMOVED_MARKER, true) . '%', false))
                ->where($this->db->quoteName('id') . ' > ' . $lastId)
                ->order($this->db->quoteName('id') . ' ASC')
                ->setLimit(self::BATCH_SIZE);
            $this->db->setQuery($query);
            $records = $this->db->loadObjectList() ?: [];

            if ($records === []) {
                break;
            }

            $orders = [];

            foreach ($records as $record) {
                $orderId = self::extractOrderId((string) $record->body);

                if ($orderId !== null) {
                    $orders[$orderId] = true;
                }
            }

            $live = $this->ordersWithPersonalData(array_keys($orders), $tables);
            $done = [];

            foreach ($records as $record) {
                $orderId = self::extractOrderId((string) $record->body);

                if ($orderId === null) {
                    continue;
                }

                if ((string) $record->created <= $cutoff || !isset($live[$orderId])) {
                    $done[$orderId] = true;
                }
            }

            $changed += $this->removeOrderEvidence(array_keys($done));
            $lastId   = (int) end($records)->id;
        } while (\count($records) === self::BATCH_SIZE);

        return $changed;
    }

    /**
     * Order numbers among $orderIds that still exist with personal data in at least one table
     * (an anonymized order has an empty IP address and the billing name "Anonymized").
     *
     * @param   string[]  $orderIds
     * @param   string[]  $tables  Order tables (#__*_orders); the order info table is derived
     *
     * @return  array<string, true>
     */
    private function ordersWithPersonalData(array $orderIds, array $tables): array
    {
        $orderIds = array_values(array_filter(array_map('strval', $orderIds), [self::class, 'isValidOrderId']));
        $live     = [];

        if ($orderIds === []) {
            return $live;
        }

        foreach ($tables as $table) {
            $infos = str_replace('_orders', '_orderinfos', $table);
            $query = $this->createQuery()
                ->select($this->db->quoteName('o.order_id'))
                ->from($this->db->quoteName($table, 'o'))
                ->join('LEFT', $this->db->quoteName($infos, 'oi') . ' ON ' . $this->db->quoteName('oi.order_id') . ' = ' . $this->db->quoteName('o.order_id'))
                ->whereIn($this->db->quoteName('o.order_id'), $orderIds, ParameterType::STRING)
                ->where('(' . $this->db->quoteName('o.ip_address') . ' <> ' . $this->db->quote('')
                    . ' OR ' . $this->db->quoteName('oi.billing_first_name') . ' IS NULL'
                    . ' OR ' . $this->db->quoteName('oi.billing_first_name') . ' <> ' . $this->db->quote('Anonymized') . ')');
            $this->db->setQuery($query);

            foreach ($this->db->loadColumn() ?: [] as $orderId) {
                $live[(string) $orderId] = true;
            }
        }

        return $live;
    }
    /**
     * Consent status for the MyProfile privacy tab.
     *
     * - Logged-in user: valid records with the user's user_id and either the checkout subject or
     *   Joomla's registration/profile consent subject.
     * - Guest: the valid checkout consent of exactly the one guest order identified by order token
     *   and e-mail address, the same scope J2Commerce grants the guest session.
     *
     * @return  array{consented: bool, latest: ?string, records: list<object>}
     */
    public function getStatus(int $userId, string $guestEmail = '', string $guestToken = ''): array
    {
        $records = $userId > 0 ? $this->loadUserConsents($userId) : $this->loadGuestConsent($guestEmail, $guestToken);

        usort($records, static fn (object $a, object $b): int => strcmp((string) $b->created, (string) $a->created));

        foreach ($records as $record) {
            $isCheckout       = $record->subject === self::SUBJECT;
            $record->order_id = $isCheckout ? self::extractOrderId((string) $record->body) : null;
            $record->source   = $isCheckout ? 'checkout' : 'account';
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
            ->where($this->db->quoteName('subject') . ' IN (' . $this->db->quote(self::SUBJECT) . ', ' . $this->db->quote(self::CORE_SUBJECT) . ')')
            ->bind(':userid', $userId, ParameterType::INTEGER);

        $this->db->setQuery($query);

        return $this->db->loadObjectList() ?: [];
    }

    /** @return list<object> */
    private function loadGuestConsent(string $guestEmail, string $guestToken): array
    {
        $guestEmail = trim($guestEmail);

        if ($guestEmail === '' || $guestToken === '') {
            return [];
        }

        $table = $this->ordersTable();

        if (!array_key_exists('token', $this->db->getTableColumns($table))) {
            return [];
        }

        // One token identifies one order; nothing else of that e-mail address is looked up.
        $query = $this->createQuery()
            ->select($this->db->quoteName('order_id'))
            ->from($this->db->quoteName($table))
            ->where($this->db->quoteName('user_id') . ' = 0')
            ->where($this->db->quoteName('token') . ' = :token')
            ->where($this->db->quoteName('user_email') . ' = :email')
            ->bind(':token', $guestToken)
            ->bind(':email', $guestEmail)
            ->setLimit(1);

        $this->db->setQuery($query);
        $orderId = (string) $this->db->loadResult();

        if (!self::isValidOrderId($orderId)) {
            return [];
        }

        $record = $this->findOrderConsent($orderId);

        return $record !== null && (int) $record->user_id === 0 ? [$record] : [];
    }

    private function likeMarker(string $orderId): string
    {
        return $this->db->quote('%' . $this->db->escape(self::orderMarker($orderId), true) . '%', false);
    }

    private function ordersTable(): string
    {
        if ($this->isJ2Commerce4 === null) {
            if (!class_exists(J2CommerceStack::class)) {
                require_once \dirname(__DIR__) . '/Support/J2CommerceStack.php';
            }

            // The enabled component decides; migrated sites keep the #__j2store_* tables.
            $this->isJ2Commerce4 = J2CommerceStack::isJ2Commerce4($this->db);
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

    /**
     * Current language: the application's, or (CLI without application) a language object of the
     * default site language. Factory::getLanguage() is deprecated.
     */
    private static function currentLanguage(): Language
    {
        try {
            $app = Factory::getApplication();

            if (method_exists($app, 'getLanguage')) {
                return $app->getLanguage();
            }
        } catch (\Throwable $e) {
            // No application (CLI script).
        }

        static $fallback = null;

        if ($fallback === null) {
            try {
                $tag = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
            } catch (\Throwable $e) {
                $tag = 'en-GB';
            }

            $fallback = Factory::getContainer()->get(LanguageFactoryInterface::class)->createLanguage($tag);
        }

        return $fallback;
    }
}
