<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Controller;

use Advans\Component\DmarcReports\Administrator\Helper\CryptoHelper;
use Advans\Component\DmarcReports\Administrator\Helper\DecoderUnavailableException;
use Advans\Component\DmarcReports\Administrator\Helper\ImapClient;
use Advans\Component\DmarcReports\Administrator\Helper\Ingestor;
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

        try {
            $client   = new ImapClient((int) $this->getConfig('imap_timeout', 20));
            $limit    = (int) $this->getConfig('max_messages_per_run', 200);
            $ingestor = new Ingestor(null, $this->canAutoCreateDomains());
            $summary  = $ingestor->newSummary();
            $domainId = (int) $source->domain_id;

            // Bodies are streamed one at a time into the ingestor and released
            // immediately, so a large mailbox cannot be buffered into memory.
            $client->process(
                [
                    'host'                => $source->host,
                    'port'                => (int) $source->port,
                    'security'            => $source->security,
                    'username'            => $source->username,
                    'password'            => CryptoHelper::decrypt((string) $source->password_enc),
                    'folder'              => $source->folder,
                    'limit'               => $limit,
                    'unseen_only'         => true,
                    'delete_after_import' => (int) $source->delete_after_import,
                ],
                function (array $message) use ($ingestor, $sourceId, $domainId, &$summary): string {
                    return $ingestor->ingestMessage($message, $sourceId, $domainId, $summary);
                }
            );

            $this->recordRun($sourceId, 'ok', $this->summaryText($summary));

            $this->setRedirect($redirect, $this->summaryText($summary), 'message');
        } catch (\Throwable $e) {
            $this->recordRun($sourceId, 'error', Text::_('COM_DMARCREPORTS_INGEST_FAILED'));
            $this->setRedirect($redirect, Text::_('COM_DMARCREPORTS_INGEST_FAILED'), 'error');

            return false;
        }

        return true;
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

            $type = $summary['imported'] > 0 ? 'message' : 'warning';
            $this->setRedirect($redirect, $this->summaryText($summary), $type);
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
        return (bool) Factory::getApplication()->getIdentity()->authorise('core.create', 'com_dmarcreports');
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

    private function recordRun(int $sourceId, string $status, string $message): void
    {
        if ($sourceId <= 0) {
            return;
        }

        $db  = Factory::getContainer()->get(DatabaseInterface::class);
        $row = (object) [
            'id'          => $sourceId,
            'last_run'    => Factory::getDate()->toSql(),
            'last_status' => $status,
            'last_message' => $message,
        ];
        $db->updateObject('#__dmarc_sources', $row, 'id');
    }

    /**
     * @param   array<string, int>  $summary
     */
    private function summaryText(array $summary): string
    {
        return Text::sprintf(
            'COM_DMARCREPORTS_INGEST_SUMMARY',
            (int) ($summary['imported'] ?? 0),
            (int) ($summary['duplicates'] ?? 0),
            (int) ($summary['failed'] ?? 0),
            (int) ($summary['skipped'] ?? 0)
        );
    }

    private function getConfig(string $key, $default)
    {
        return \Joomla\CMS\Component\ComponentHelper::getParams('com_dmarcreports')->get($key, $default);
    }

    private function createDbQuery(DatabaseInterface $db): \Joomla\Database\QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
