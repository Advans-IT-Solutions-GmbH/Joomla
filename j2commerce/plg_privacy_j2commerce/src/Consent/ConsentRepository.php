<?php
/**
 * @package     J2Commerce Privacy Plugin
 * @subpackage  Consent
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
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
 * - body holds order number, IP address and user agent, plus a language-independent order marker
 *   and a marker naming the language the text is written in (the language the customer saw).
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

    /** Marks an anonymized legacy record. */
    public const LEGACY_MARKER = '<!-- j2commerce-legacy-consent -->';

    /** Rows per batch in the bulk operations. */
    public const BATCH_SIZE = 200;

    private const MARKER_PREFIX = '<!-- j2commerce-order:';
    private const MARKER_SUFFIX = ' -->';

    /** Prefix of the marker naming the language the stored body is written in. */
    private const LANGUAGE_MARKER_PREFIX = '<!-- j2commerce-lang:';

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
    /**
     * Characters of an e-mail address token: everything except white space, "@" and the RFC 5322
     * specials that never occur in an unquoted address (parentheses, angle and square brackets,
     * braces, quote, comma, semicolon, colon, backslash). Used for request addresses and for
     * the addresses found in a consent body, so both follow the same syntax.
     */
    public const ADDRESS_CHARS = '[^\s@<>()\[\]{}",;:\\\\]';

    /**
     * An e-mail address as it is compared: trimmed and lower case (Unicode, locale-independent).
     * This is the only place where addresses are lower-cased for a decision; the database never
     * decides whether two addresses are equal.
     */
    public static function normaliseAddress(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    /**
     * Whether the database is PostgreSQL (else MySQL or MariaDB, the other servers Joomla runs on).
     */
    private static function isPostgres(DatabaseInterface $db): bool
    {
        return method_exists($db, 'getServerType') && strtolower((string) $db->getServerType()) === 'postgresql';
    }

    /**
     * ASCII-only lower case, independent of the PHP locale (strtolower() depends on it before PHP 8.2).
     */
    private static function asciiLower(string $value): string
    {
        return strtr($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    /**
     * SQL condition that preselects rows whose column may hold one of $emails: a superset under any
     * column collation and server locale, never the decision. The decision is
     * normaliseAddress($value) === $email in PHP (addressValues()), because SQL lower-casing and
     * collations differ from it: under utf8mb4_unicode_ci and general_ci "muller@example.com"
     * equals "müller@example.com" (another person), and PostgreSQL lower-cases by the server
     * locale (Turkish: "I" becomes a dotless i).
     *
     * MySQL/MariaDB: comparison with utf8mb4_unicode_ci (case- and accent-insensitive), converted
     * to utf8mb4 so that utf8mb3 tables (J2Store) work too. PostgreSQL: ASCII lower case through
     * the "C" collation, or lower case by the server locale.
     *
     * @param   string    $quotedColumn  Column, already quoted
     * @param   string[]  $emails        Normalised addresses
     */
    public static function addressPrefilter(DatabaseInterface $db, string $quotedColumn, array $emails): string
    {
        $emails = array_values(array_unique(array_filter(array_map('strval', $emails), 'strlen')));

        if ($emails === []) {
            return '1 = 0';
        }

        $list = static fn (array $values): string => implode(',', array_map([$db, 'quote'], $values));

        if (self::isPostgres($db)) {
            return '(LOWER(TRIM(' . $quotedColumn . ') COLLATE "C") IN (' . $list(array_map([self::class, 'asciiLower'], $emails)) . ')'
                . ' OR LOWER(TRIM(' . $quotedColumn . ')) IN (' . $list($emails) . '))';
        }

        return 'CONVERT(TRIM(' . $quotedColumn . ') USING utf8mb4) COLLATE utf8mb4_unicode_ci IN (' . $list($emails) . ')';
    }

    /**
     * SQL condition matching exactly the given stored values (byte-exact after SQL TRIM), for
     * values found by addressValues(). MySQL/MariaDB compare with utf8mb4_bin; PostgreSQL compares
     * deterministically by default.
     *
     * @param   string    $quotedColumn  Column, already quoted
     * @param   string[]  $values        Stored values (trimmed)
     */
    public static function exactValuesCondition(DatabaseInterface $db, string $quotedColumn, array $values): string
    {
        $values = array_values(array_unique(array_map('strval', $values)));

        if ($values === []) {
            return '1 = 0';
        }

        $column = self::isPostgres($db)
            ? 'TRIM(' . $quotedColumn . ')'
            : 'CONVERT(TRIM(' . $quotedColumn . ') USING utf8mb4) COLLATE utf8mb4_bin';

        return $column . ' IN (' . implode(',', array_map([$db, 'quote'], $values)) . ')';
    }

    /**
     * The stored values (trimmed, as SQL TRIM returns them) of $column in $table that are one of
     * $emails after normaliseAddress(): preselected with addressPrefilter(), decided in PHP.
     *
     * @param   string[]  $emails  Normalised addresses
     * @param   string[]  $where   Further conditions (already quoted)
     *
     * @return  string[]
     */
    public static function addressValues(DatabaseInterface $db, string $table, string $column, array $emails, array $where = []): array
    {
        $wanted = array_flip(array_values(array_filter(array_map('strval', $emails), 'strlen')));

        if ($wanted === []) {
            return [];
        }

        $quoted = $db->quoteName($column);
        $query  = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select('TRIM(' . $quoted . ') AS ' . $db->quoteName('value'))
            ->from($db->quoteName($table))
            ->where(self::addressPrefilter($db, $quoted, array_keys($wanted)));

        foreach ($where as $condition) {
            $query->where($condition);
        }

        $values = [];

        foreach ($db->setQuery($query)->loadColumn() ?: [] as $value) {
            $value = (string) $value;

            if (isset($wanted[self::normaliseAddress($value)])) {
                $values[$value] = true;
            }
        }

        return array_map('strval', array_keys($values));
    }

    /**
     * Whether $email (trimmed, lower case) is a request address: local part "@" domain with a dot,
     * only ADDRESS_CHARS, no leading or trailing dot, at most 320 bytes.
     */
    public static function isAddress(string $email): bool
    {
        return $email !== '' && \strlen($email) <= 320
            && !str_starts_with($email, '.') && !str_ends_with($email, '.')
            && preg_match('/^' . self::ADDRESS_CHARS . '+@' . self::ADDRESS_CHARS . '+\.' . self::ADDRESS_CHARS . '+$/u', $email) === 1;
    }

    /**
     * The complete e-mail address tokens of a text (HTML tags removed, entities decoded), lower
     * case, a sentence-ending dot removed. A token is a maximal run of ADDRESS_CHARS around "@",
     * so "o'anna@example.com" or "💡anna@example.com" is never the token "anna@example.com".
     *
     * @return  string[]
     */
    public static function addressTokens(string $text): array
    {
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (!preg_match_all('/' . self::ADDRESS_CHARS . '+@' . self::ADDRESS_CHARS . '+/u', $text, $matches)) {
            return [];
        }

        return array_map(static fn (string $token): string => mb_strtolower(rtrim($token, '.'), 'UTF-8'), $matches[0]);
    }

    /**
     * Whether one of the complete address tokens of $body is a key of $wanted (lower case).
     *
     * @param   array<string, mixed>  $wanted
     */
    private static function bodyHasAddress(string $body, array $wanted): bool
    {
        foreach (self::addressTokens($body) as $token) {
            if (isset($wanted[$token])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Guest records (user_id 0) of an earlier template override (LEGACY_SUBJECT) that name one
     * of $emails as a complete address, for a privacy export; nothing is changed. Joomla's consent
     * plugin exports only records of an account (user_id), never guest records.
     *
     * @param   string[]  $emails
     *
     * @return  object[]  Rows with id, state, created, subject and body, ordered by ID
     */
    public function findLegacyGuestConsents(array $emails): array
    {
        $emails = array_values(array_unique(array_filter(array_map(static fn ($email): string => mb_strtolower(trim((string) $email), 'UTF-8'), $emails), 'strlen')));

        if ($emails === []) {
            return [];
        }

        $wanted = array_flip($emails);
        $likes  = array_map(
            fn (string $email): string => $this->bodyPrefilter($email),
            $emails
        );
        $rows   = [];
        $lastId = 0;

        do {
            $query = $this->createQuery()
                ->select($this->db->quoteName(['id', 'state', 'created', 'subject', 'body']))
                ->from($this->db->quoteName('#__privacy_consents'))
                ->where($this->db->quoteName('subject') . ' = ' . $this->db->quote(self::LEGACY_SUBJECT))
                ->where($this->db->quoteName('user_id') . ' = 0')
                ->where('(' . implode(' OR ', $likes) . ')')
                ->where($this->db->quoteName('id') . ' > ' . $lastId)
                ->order($this->db->quoteName('id') . ' ASC')
                ->setLimit(self::BATCH_SIZE);

            $this->db->setQuery($query);
            $batch = $this->db->loadObjectList() ?: [];

            foreach ($batch as $row) {
                $lastId = max($lastId, (int) $row->id);

                if (self::bodyHasAddress((string) $row->body, $wanted)) {
                    $rows[] = $row;
                }
            }
        } while (\count($batch) === self::BATCH_SIZE);

        return $rows;
    }

    public static function isValidOrderId(string $orderId): bool
    {
        return $orderId !== '' && \strlen($orderId) <= 100 && preg_match('/^[A-Za-z0-9_.\-]+$/', $orderId) === 1;
    }

    public static function orderMarker(string $orderId): string
    {
        return self::MARKER_PREFIX . $orderId . self::MARKER_SUFFIX;
    }

    /**
     * Marker naming the language a stored body is written in. The record is evidence of what the
     * customer saw, so the text stays in the language it was written in and the tag says which
     * one that is. Unknown characters are dropped so the marker cannot break out of the comment.
     */
    public static function languageMarker(string $tag): string
    {
        $tag = (string) preg_replace('/[^A-Za-z0-9_-]/', '', $tag);

        return $tag === '' ? '' : self::LANGUAGE_MARKER_PREFIX . $tag . self::MARKER_SUFFIX;
    }

    /**
     * Tag of the language the wording actually came from, read from the file the language loaded.
     * A language whose pack is not installed on the site carries no metadata and reports no tag of
     * its own, and a load that fell back to en-GB must be named as en-GB, not as the language that
     * was asked for. The default site language stays the last resort.
     */
    private static function bodyLanguageTag(Language $language): string
    {
        // Joomla loads the default language before the requested one, so the file loaded last is
        // the one whose texts are in use.
        $paths = array_reverse((array) $language->getPaths('plg_system_j2commerceprivacy'), true);

        foreach ($paths as $file => $success) {
            if (!$success) {
                continue;
            }

            if (preg_match('#[/\\\\]([A-Za-z]{2,3}-[A-Za-z]{2,4})[/\\\\][^/\\\\]+\.ini$#', (string) $file, $matches) === 1) {
                return $matches[1];
            }
        }

        return self::defaultSiteLanguageTag();
    }

    /**
     * Language tag of a stored body, or null for a record written before the marker existed.
     */
    public static function extractLanguageTag(string $body): ?string
    {
        if (preg_match('/<!-- j2commerce-lang:([A-Za-z0-9_-]+) -->/', $body, $matches) === 1) {
            return $matches[1];
        }

        return null;
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
            . self::orderMarker($orderId) . self::languageMarker(self::bodyLanguageTag($language));
    }

    /**
     * Body of a consent record after its order was anonymized: consent and order number stay as
     * evidence, IP address and user agent are gone. Written while an administrator or the cleanup
     * task processes the order, so it uses the website's default site language, not the language
     * of the acting person.
     */
    public function buildEvidenceRemovedBody(string $orderId): string
    {
        $language = self::loadSiteBodyLanguage();

        return sprintf($language->_(self::BODY_REMOVED_KEY), htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8'))
            . self::orderMarker($orderId) . self::EVIDENCE_REMOVED_MARKER . self::languageMarker(self::bodyLanguageTag($language));
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
     * Update already anonymized legacy records that still store the raw language key. Global data
     * migration for the unscoped cleanup/installer path only; never called for a scoped removal
     * request, so it does not touch records outside the request's scope.
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
                    ->set($this->db->quoteName('state') . ' = -1')
                    ->whereIn($this->db->quoteName('id'), $ids)
                    ->bind(':body', $body)
            )->execute();

            $changed += \count($ids);
            $lastId   = max($ids);
        } while (\count($ids) === self::BATCH_SIZE);

        return $changed;
    }

    private static function loadSiteBodyLanguage(): Language
    {
        $tag      = self::defaultSiteLanguageTag();
        $language = Factory::getContainer()->get(LanguageFactoryInterface::class)->createLanguage($tag);

        $language->load('plg_system_j2commerceprivacy', JPATH_ADMINISTRATOR, $tag)
            || $language->load('plg_system_j2commerceprivacy', JPATH_PLUGINS . '/system/j2commerceprivacy', $tag);

        if (!$language->hasKey(self::LEGACY_BODY_KEY)) {
            $language->load('plg_system_j2commerceprivacy', JPATH_PLUGINS . '/system/j2commerceprivacy', $tag, true)
                || $language->load('plg_system_j2commerceprivacy', JPATH_ADMINISTRATOR, $tag, true);
        }

        return $language;
    }

    private static function defaultSiteLanguageTag(): string
    {
        try {
            return (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
        } catch (\Throwable $e) {
            return 'en-GB';
        }
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

    /**
     * Text of an anonymized legacy record together with the tag of the language it is written in.
     * The wording always comes from a language file, never from the code: if the site language
     * does not resolve the key, the packaged en-GB file is read. Should even that be missing, the
     * key itself is stored and repairLegacyAnonymizedBodies() replaces it with the text on the
     * next unscoped run, once the language file is back.
     *
     * @return  array{0: string, 1: string}  Body text, language tag
     */
    private static function legacyBody(Language $language): array
    {
        $text = $language->_(self::LEGACY_BODY_KEY);

        if ($text !== '' && $text !== self::LEGACY_BODY_KEY) {
            return [$text, self::bodyLanguageTag($language)];
        }

        $english = Factory::getContainer()->get(LanguageFactoryInterface::class)->createLanguage('en-GB');
        $english->load('plg_system_j2commerceprivacy', JPATH_PLUGINS . '/system/j2commerceprivacy', 'en-GB', true)
            || $english->load('plg_system_j2commerceprivacy', JPATH_ADMINISTRATOR, 'en-GB', true);

        $text = $english->_(self::LEGACY_BODY_KEY);

        return [$text, $text === self::LEGACY_BODY_KEY ? '' : 'en-GB'];
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
     * All checkout consent records of the given orders, whatever their state (valid, obsolete,
     * invalidated): for a privacy export every record of the data subject counts.
     *
     * @param   string[]  $orderIds
     *
     * @return  object[]  Rows with id, user_id, state, created, subject, body and order_id, ordered by ID
     */
    public function findConsentsForOrders(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('strval', $orderIds), [self::class, 'isValidOrderId'])));
        $rows     = [];

        foreach (array_chunk($orderIds, 50) as $chunk) {
            $wanted = array_flip($chunk);
            $likes  = array_map(fn (string $orderId): string => $this->db->quoteName('body') . ' LIKE ' . $this->likeMarker($orderId), $chunk);
            $query  = $this->createQuery()
                ->select($this->db->quoteName(['id', 'user_id', 'state', 'created', 'subject', 'body']))
                ->from($this->db->quoteName('#__privacy_consents'))
                ->where($this->db->quoteName('subject') . ' = ' . $this->db->quote(self::SUBJECT))
                ->where('(' . implode(' OR ', $likes) . ')')
                ->order($this->db->quoteName('id') . ' ASC');

            $this->db->setQuery($query);

            foreach ($this->db->loadObjectList() ?: [] as $row) {
                $row->order_id = self::extractOrderId((string) $row->body);

                // Only records whose order marker names one of the orders.
                if ($row->order_id !== null && isset($wanted[$row->order_id])) {
                    $rows[(int) $row->id] = $row;
                }
            }
        }

        ksort($rows);

        return array_values($rows);
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
     * LEGACY_DONE_SUBJECT and state -1 (invalid). Such records are not counted as consent.
     *
     * @param   int|null  $userId  Only this user's records (null: all; 0: no account, only the
     *                             guest records of $emails)
     * @param   string[]  $emails  With $userId: also guest records (user_id 0) mentioning one of these addresses
     *
     * @return  int  Number of records anonymized
     */
    public function anonymizeLegacyConsents(?int $userId = null, array $emails = []): int
    {
        $emails  = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $emails)), 'strlen')));

        // A request without account and without an address has no records of its own; "user_id 0"
        // alone would match the guest records of every customer.
        if ($userId !== null && $userId <= 0 && $emails === []) {
            return 0;
        }

        [$text, $tag] = self::legacyBody(self::loadSiteBodyLanguage());
        $body         = $text . self::LEGACY_MARKER . self::EVIDENCE_REMOVED_MARKER . self::languageMarker($tag);
        // The raw-key body repair is a data migration for records anonymized by an older version.
        // It runs only in the unscoped cleanup/installer path; a scoped removal request must not
        // touch other users' already-anonymized records or inflate the returned count.
        $changed = $userId === null ? $this->repairLegacyAnonymizedBodies($body) : 0;
        $lastId  = 0;

        // The SQL LIKE below only preselects: "anna@example.com" is also contained in
        // "joanna@example.com", "o'anna@example.com" or "💡anna@example.com". A guest record
        // counts only when one of the complete address tokens of its body (addressTokens(), the
        // same syntax as an accepted request address) equals a request address.
        $wanted = array_flip(array_map(static fn (string $email): string => mb_strtolower($email, 'UTF-8'), $emails));

        do {
            $query = $this->createQuery()
                ->select($this->db->quoteName(['id', 'user_id', 'body']))
                ->from($this->db->quoteName('#__privacy_consents'))
                ->where($this->db->quoteName('subject') . ' = ' . $this->db->quote(self::LEGACY_SUBJECT))
                ->where($this->db->quoteName('id') . ' > ' . $lastId)
                ->order($this->db->quoteName('id') . ' ASC')
                ->setLimit(self::BATCH_SIZE);

            if ($userId !== null) {
                $scope = $userId > 0 ? [$this->db->quoteName('user_id') . ' = ' . (int) $userId] : [];

                // LOWER on both sides: the preselection must not depend on the column collation.
                // Under a case-sensitive collation "Anna@Example.com" in the body would otherwise
                // never reach the case-insensitive check below.
                foreach ($emails as $email) {
                    $scope[] = '(' . $this->db->quoteName('user_id') . ' = 0 AND ' . $this->bodyPrefilter($email) . ')';
                }

                $query->where('(' . implode(' OR ', $scope) . ')');
            }

            $this->db->setQuery($query);
            $rows = $this->db->loadObjectList() ?: [];

            if ($rows === []) {
                break;
            }

            $ids = [];

            foreach ($rows as $row) {
                $own = $userId === null || ($userId > 0 && (int) $row->user_id === $userId);

                if (!$own && (int) $row->user_id === 0) {
                    $own = self::bodyHasAddress((string) $row->body, $wanted);
                }

                if ($own) {
                    $ids[] = (int) $row->id;
                }
            }

            if ($ids !== []) {
                $update = $this->createQuery()
                    ->update($this->db->quoteName('#__privacy_consents'))
                    ->set($this->db->quoteName('subject') . ' = ' . $this->db->quote(self::LEGACY_DONE_SUBJECT))
                    ->set($this->db->quoteName('body') . ' = :body')
                    ->set($this->db->quoteName('state') . ' = -1')
                    ->whereIn($this->db->quoteName('id'), $ids)
                    ->bind(':body', $body);
                $this->db->setQuery($update)->execute();
            }

            $changed += \count($ids);
            $lastId   = max(array_map(static fn (object $row): int => (int) $row->id, $rows));
        } while (\count($rows) === self::BATCH_SIZE);

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

        // One token identifies one order; nothing else of that e-mail address is looked up. The
        // address is compared in PHP (normaliseAddress()), not under the column collation.
        $query = $this->createQuery()
            ->select($this->db->quoteName(['order_id', 'user_email']))
            ->from($this->db->quoteName($table))
            ->where($this->db->quoteName('user_id') . ' = 0')
            ->where($this->db->quoteName('token') . ' = :token')
            ->bind(':token', $guestToken);

        $this->db->setQuery($query);
        $orderId = '';

        foreach ($this->db->loadObjectList() ?: [] as $row) {
            if (self::normaliseAddress((string) $row->user_email) === self::normaliseAddress($guestEmail)) {
                $orderId = (string) $row->order_id;
                break;
            }
        }

        if (!self::isValidOrderId($orderId)) {
            return [];
        }

        $record = $this->findOrderConsent($orderId);

        return $record !== null && (int) $record->user_id === 0 ? [$record] : [];
    }

    /**
     * SQL preselection of bodies that may contain $email: only the domain is compared ("@" or its
     * entity "&#64;" plus the domain), case- and accent-insensitive, so a superset under any
     * collation and server locale. The local part may be stored raw or with HTML entities in
     * several forms. The decision is bodyHasAddress() in PHP.
     */
    private function bodyPrefilter(string $email): string
    {
        $email  = self::normaliseAddress($email);
        $domain = (string) substr($email, (int) strrpos($email, '@') + 1);
        $body   = $this->db->quoteName('body');
        $likes  = [];

        foreach (array_unique([$domain, htmlspecialchars($domain, ENT_QUOTES, 'UTF-8')]) as $form) {
            foreach (['@', '&#64;'] as $at) {
                $pattern = '%' . $this->db->escape($at . $form, true) . '%';

                if (self::isPostgres($this->db)) {
                    $likes[] = 'LOWER(' . $body . ' COLLATE "C") LIKE ' . $this->db->quote(self::asciiLower($pattern), false);
                    $likes[] = 'LOWER(' . $body . ') LIKE ' . $this->db->quote($pattern, false);
                } else {
                    $likes[] = 'CONVERT(' . $body . ' USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ' . $this->db->quote($pattern, false);
                }
            }
        }

        return '(' . implode(' OR ', $likes) . ')';
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
