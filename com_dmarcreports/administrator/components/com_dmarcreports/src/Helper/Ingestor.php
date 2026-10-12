<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Helper;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

/**
 * Orchestrates the ingestion pipeline: raw container -> XML document(s) ->
 * parsed report -> deduplicated storage. The same report, imported twice, is
 * stored once because every report is keyed by a content fingerprint. All
 * writes use prepared statements and run inside a transaction per report.
 *
 * @since  1.0.0
 */
final class Ingestor
{
    private DatabaseInterface $db;

    public function __construct(?DatabaseInterface $db = null)
    {
        $this->db = $db ?? Factory::getContainer()->get(DatabaseInterface::class);
    }

    /**
     * Ingest the decoded attachments of raw IMAP messages.
     *
     * @param   array<int, array{uid?: int, raw: string}>  $messages
     * @param   int|null  $sourceId        The source these messages came from, or null.
     * @param   int       $forcedDomainId  When > 0, assign every report to this domain
     *                                     instead of resolving it from the report XML
     *                                     (an operator binding a mailbox to one domain).
     *
     * @return  array<string, int>  imported, duplicates, failed, records
     */
    public function ingestMessages(array $messages, ?int $sourceId = null, int $forcedDomainId = 0): array
    {
        $summary = $this->newSummary();

        foreach ($messages as $message) {
            foreach (MimeMessage::attachments((string) ($message['raw'] ?? '')) as $attachment) {
                $documents = ReportArchive::extract($attachment['content'], $attachment['filename']);
                $this->ingestDocuments($documents, $sourceId, $forcedDomainId, $summary);
            }
        }

        return $summary;
    }

    /**
     * Ingest a single uploaded report file (.xml, .gz or .zip).
     *
     * @return  array<string, int>
     */
    public function ingestFile(string $path, string $filename, ?int $sourceId = null, int $forcedDomainId = 0): array
    {
        $summary = $this->newSummary();
        $payload = is_readable($path) ? (string) file_get_contents($path) : '';

        if ($payload === '') {
            $summary['failed']++;

            return $summary;
        }

        $documents = ReportArchive::extract($payload, $filename);
        $this->ingestDocuments($documents, $sourceId, $forcedDomainId, $summary);

        return $summary;
    }

    /**
     * @param   string[]            $documents
     * @param   array<string, int>  $summary
     */
    private function ingestDocuments(array $documents, ?int $sourceId, int $forcedDomainId, array &$summary): void
    {
        // An attachment or upload that yields no usable document is a failed
        // ingestion, not a silent success.
        if ($documents === []) {
            $summary['failed']++;

            return;
        }

        foreach ($documents as $xml) {
            try {
                $report = DmarcParser::parse($xml);
            } catch (\InvalidArgumentException) {
                $summary['failed']++;
                continue;
            }

            $this->store($report, $sourceId, $forcedDomainId, $summary);
        }
    }

    /**
     * @param   array<string, mixed>  $report
     * @param   array<string, int>    $summary
     */
    private function store(array $report, ?int $sourceId, int $forcedDomainId, array &$summary): void
    {
        $hash = DmarcParser::fingerprint($report);

        if ($this->reportExists($hash)) {
            $summary['duplicates']++;

            return;
        }

        $domainId = $forcedDomainId > 0 ? $forcedDomainId : $this->resolveDomain($report['policy']['domain']);

        $this->db->transactionStart();

        try {
            $reportId = $this->insertReport($report, $hash, $domainId, $sourceId);

            foreach ($report['records'] as $record) {
                $this->insertRecord($record, $reportId, $domainId);
                $summary['records'] += (int) $record['count'];
            }

            $this->db->transactionCommit();
            $summary['imported']++;
        } catch (\Throwable) {
            $this->db->transactionRollback();
            $summary['failed']++;
        }
    }

    private function reportExists(string $hash): bool
    {
        $query = $this->query()
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__dmarc_reports'))
            ->where($this->db->quoteName('report_hash') . ' = :hash')
            ->bind(':hash', $hash);

        return (int) $this->db->setQuery($query)->loadResult() > 0;
    }

    /**
     * Find the configured domain by name, creating a monitored entry on first
     * sight so a shared reporting mailbox populates the domain list by itself.
     */
    private function resolveDomain(string $domain): int
    {
        $domain = strtolower(trim($domain));

        if ($domain === '') {
            return 0;
        }

        $query = $this->query()
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__dmarc_domains'))
            ->where($this->db->quoteName('domain') . ' = :domain')
            ->bind(':domain', $domain);
        $id = (int) $this->db->setQuery($query)->loadResult();

        if ($id > 0) {
            return $id;
        }

        $now    = Factory::getDate()->toSql();
        $insert = (object) [
            'domain'          => $domain,
            'title'           => $domain,
            'expected_policy' => $this->defaultPolicyTarget(),
            'published'       => 1,
            'created'         => $now,
        ];
        $this->db->insertObject('#__dmarc_domains', $insert, 'id');

        return (int) $insert->id;
    }

    /**
     * The enforcement target assigned to domains discovered automatically from
     * incoming reports, taken from the component configuration (default reject).
     */
    private function defaultPolicyTarget(): string
    {
        $target = strtolower(
            (string) ComponentHelper::getParams('com_dmarcreports')->get('default_policy_target', 'reject')
        );

        return \in_array($target, ['none', 'quarantine', 'reject'], true) ? $target : 'reject';
    }

    /**
     * @param   array<string, mixed>  $report
     */
    private function insertReport(array $report, string $hash, int $domainId, ?int $sourceId): int
    {
        $row = (object) [
            'domain_id'      => $domainId,
            'source_id'      => $sourceId ?: 0,
            'org_name'       => $report['org_name'],
            'org_email'      => $report['org_email'],
            'report_id'      => $report['report_id'],
            'report_hash'    => $hash,
            'date_begin'     => $report['date_begin'],
            'date_end'       => $report['date_end'],
            'policy_domain'  => $report['policy']['domain'],
            'policy_p'       => $report['policy']['p'],
            'policy_sp'      => $report['policy']['sp'],
            'policy_pct'     => $report['policy']['pct'],
            'policy_adkim'   => $report['policy']['adkim'],
            'policy_aspf'    => $report['policy']['aspf'],
            'message_count'  => array_sum(array_column($report['records'], 'count')),
            'created'        => Factory::getDate()->toSql(),
        ];

        $this->db->insertObject('#__dmarc_reports', $row, 'id');

        return (int) $row->id;
    }

    /**
     * @param   array<string, mixed>  $record
     */
    private function insertRecord(array $record, int $reportId, int $domainId): void
    {
        $row = (object) [
            'report_id'     => $reportId,
            'domain_id'     => $domainId,
            'source_ip'     => $record['source_ip'],
            'count'         => $record['count'],
            'disposition'   => $record['disposition'],
            'dkim_result'   => $record['dkim_result'],
            'spf_result'    => $record['spf_result'],
            'dkim_aligned'  => $record['dkim_aligned'],
            'spf_aligned'   => $record['spf_aligned'],
            'header_from'   => $record['header_from'],
            'envelope_from' => $record['envelope_from'],
            'envelope_to'   => $record['envelope_to'],
        ];

        $this->db->insertObject('#__dmarc_records', $row);
    }

    private function query(): \Joomla\Database\QueryInterface
    {
        return method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
    }

    /**
     * @return array<string, int>
     */
    private function newSummary(): array
    {
        return ['imported' => 0, 'duplicates' => 0, 'failed' => 0, 'records' => 0];
    }
}
