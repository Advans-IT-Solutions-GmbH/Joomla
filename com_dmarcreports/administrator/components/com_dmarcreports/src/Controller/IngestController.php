<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Controller;

use Advans\Component\DmarcReports\Administrator\Helper\DecoderUnavailableException;
use Advans\Component\DmarcReports\Administrator\Helper\DomainRegistry;
use Advans\Component\DmarcReports\Administrator\Helper\Ingestor;
use Advans\Component\DmarcReports\Administrator\Helper\ReportArchive;
use Advans\Component\DmarcReports\Administrator\Service\SourcePoller;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

/**
 * Runs report ingestion, either by polling a configured mailbox over IMAP or by
 * accepting a manually uploaded report file. Every action is CSRF protected and
 * gated by the custom "dmarc.ingest" ACL action.
 *
 * The IMAP poll itself — streaming, cursor advancement and the row-lock
 * discipline that keeps a manual poll safe next to the automatic scheduler task
 * — lives in {@see SourcePoller}, shared with plg_task_dmarcreports so both
 * paths behave identically and cannot double-process a message.
 *
 * @since  1.0.0
 */
class IngestController extends BaseController
{
    /**
     * Poll one configured mailbox and import the reports it holds.
     */
    public function import()
    {
        $this->checkToken();
        $this->authorise();

        $app      = Factory::getApplication();
        $sourceId = (int) $app->getInput()->getInt('source_id', 0);
        $redirect = Route::_('index.php?option=com_dmarcreports&view=sources', false);

        $source = $this->loadSource($sourceId);

        if ($source === null) {
            $this->setRedirect($redirect, Text::_('COM_DMARCREPORTS_INGEST_SOURCE_NOT_FOUND'), 'error');

            return false;
        }

        $result = (new SourcePoller(
            Factory::getContainer()->get(DatabaseInterface::class)
        ))->poll($source, $this->canAutoCreateDomains());

        $flashType = match ($result['status']) {
            SourcePoller::STATUS_ERROR   => 'error',
            SourcePoller::STATUS_BLOCKED => 'warning',
            default                      => 'message',
        };

        $this->setRedirect($redirect, $result['message'], $flashType);

        return $result['status'] !== SourcePoller::STATUS_ERROR;
    }

    /**
     * Import a manually uploaded report file.
     */
    public function upload()
    {
        $this->checkToken();
        $this->authorise();

        $app      = Factory::getApplication();
        $redirect = Route::_('index.php?option=com_dmarcreports&view=dashboard', false);
        $file     = $app->getInput()->files->get('report_file', null, 'raw');

        if (!\is_array($file) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $this->setRedirect($redirect, Text::_('COM_DMARCREPORTS_UPLOAD_NO_FILE'), 'error');

            return false;
        }

        try {
            // A manual upload is never bound to a configured source: the form
            // offers no source selector, so no client-supplied source_id is
            // read or trusted. The report is stored unbound (source_id 0) and
            // its domain is resolved from the published policy under the same
            // ACL gate as every other ingest path. Honouring a posted source_id
            // here would let a crafted request attach arbitrary or nonexistent
            // provenance to a report.
            $summary = (new Ingestor(null, $this->canAutoCreateDomains()))->ingestFile(
                (string) $file['tmp_name'],
                (string) ($file['name'] ?? 'report'),
                null
            );

            $type    = $summary['imported'] > 0 ? 'message' : 'warning';
            $message = $this->summaryText($summary);

            // When nothing was imported, append the specific reason (empty file,
            // wrong format, malformed/XXE, or a decompression-bomb guard) so the
            // operator knows why rather than seeing only an empty-result summary.
            if ((int) $summary['imported'] === 0 && !empty($summary['reason'])) {
                $key = $this->uploadReasonKey((string) $summary['reason']);

                if ($key !== '') {
                    $message .= ' ' . Text::_($key);
                }
            }

            $this->setRedirect($redirect, $message, $type);
        } catch (DecoderUnavailableException $e) {
            // The uploaded report is a compressed container the host cannot
            // decode because the required PHP extension is missing. Surface a
            // concrete requirement rather than a generic failure.
            $this->setRedirect($redirect, Text::_('COM_DMARCREPORTS_INGEST_DECODER_UNAVAILABLE'), 'error');

            return false;
        } catch (\Throwable $e) {
            $this->setRedirect($redirect, Text::_('COM_DMARCREPORTS_INGEST_FAILED'), 'error');

            return false;
        }

        return true;
    }

    private function authorise(): void
    {
        if (!Factory::getApplication()->getIdentity()->authorise('dmarc.ingest', 'com_dmarcreports')) {
            throw new \Joomla\CMS\Access\Exception\NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    /**
     * Whether the current user may auto-register an unknown domain discovered in
     * an incoming report. Registering a monitored domain is a configuration
     * change, so it requires core.create: an ingest-only operator can import
     * reports for already-configured domains but cannot create new ones.
     */
    private function canAutoCreateDomains(): bool
    {
        return DomainRegistry::autoCreateEnabled()
            && (bool) Factory::getApplication()->getIdentity()->authorise('core.create', 'com_dmarcreports');
    }

    private function loadSource(int $sourceId): ?object
    {
        if ($sourceId <= 0) {
            return null;
        }

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $this->createDbQuery($db)
            ->select('*')
            ->from($db->quoteName('#__dmarc_sources'))
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('published') . ' = 1')
            ->bind(':id', $sourceId, ParameterType::INTEGER);

        return $db->setQuery($query)->loadObject() ?: null;
    }

    /**
     * Map an ingest reason token from {@see Ingestor} to its localized upload
     * message key. An unknown token yields an empty string so only the summary
     * is shown.
     */
    private function uploadReasonKey(string $reason): string
    {
        return match ($reason) {
            'empty'      => 'COM_DMARCREPORTS_UPLOAD_REASON_EMPTY',
            'unreadable' => 'COM_DMARCREPORTS_UPLOAD_REASON_UNREADABLE',
            'format'     => 'COM_DMARCREPORTS_UPLOAD_REASON_FORMAT',
            'malformed'  => 'COM_DMARCREPORTS_UPLOAD_REASON_MALFORMED',
            'bomb'       => 'COM_DMARCREPORTS_UPLOAD_REASON_BOMB',
            default      => '',
        };
    }

    /**
     * @param   array<string, int|string>  $summary  Ingest counters plus an
     *                                                optional string `reason`
     *                                                token when nothing imported.
     */
    private function summaryText(array $summary): string
    {
        $text = Text::sprintf(
            'COM_DMARCREPORTS_INGEST_SUMMARY',
            (int) ($summary['imported'] ?? 0),
            (int) ($summary['duplicates'] ?? 0),
            (int) ($summary['failed'] ?? 0),
            (int) ($summary['skipped'] ?? 0)
        );

        // An archive that held more reports than the per-run cap had the surplus
        // dropped; tell the operator how many so a silently truncated import is
        // never mistaken for a complete one.
        $dropped = (int) ($summary['dropped'] ?? 0);

        if ($dropped > 0) {
            $text .= ' ' . Text::sprintf('COM_DMARCREPORTS_INGEST_DROPPED', $dropped, ReportArchive::MAX_DOCUMENTS);
        }
        // The stored rows and the mail messages they stand for, named apart: a
        // report with three rows can describe six messages.
        $records = (int) ($summary['records'] ?? 0);

        if ($records > 0) {
            $text .= ' ' . Text::sprintf('COM_DMARCREPORTS_INGEST_RECORDS', $records, (string) ($summary['messages'] ?? '0'));
        }

        return $text;
    }

    private function createDbQuery(DatabaseInterface $db): \Joomla\Database\QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
