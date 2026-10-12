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
        $this->checkToken('get');
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
            $client = new ImapClient((int) $this->getConfig('imap_timeout', 20));
            $limit  = (int) $this->getConfig('max_messages_per_run', 50);

            $messages = $client->fetch(
                [
                    'host'        => $source->host,
                    'port'        => (int) $source->port,
                    'security'    => $source->security,
                    'username'    => $source->username,
                    'password'    => CryptoHelper::decrypt((string) $source->password_enc),
                    'folder'      => $source->folder,
                    'limit'       => $limit,
                    'unseen_only' => false,
                ]
            );

            $summary = (new Ingestor())->ingestMessages($messages, $sourceId, (int) $source->domain_id);
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
        $sourceId = (int) $app->getInput()->getInt('source_id', 0);
        $redirect = Route::_('index.php?option=com_dmarcreports&view=dashboard', false);
        $file     = $app->getInput()->files->get('report_file', null, 'raw');

        if (!\is_array($file) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $this->setRedirect($redirect, Text::_('COM_DMARCREPORTS_UPLOAD_NO_FILE'), 'error');

            return false;
        }

        try {
            $summary = (new Ingestor())->ingestFile(
                (string) $file['tmp_name'],
                (string) ($file['name'] ?? 'report'),
                $sourceId ?: null
            );

            $type = $summary['imported'] > 0 ? 'message' : 'warning';
            $this->setRedirect($redirect, $this->summaryText($summary), $type);
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
            (int) ($summary['failed'] ?? 0)
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
