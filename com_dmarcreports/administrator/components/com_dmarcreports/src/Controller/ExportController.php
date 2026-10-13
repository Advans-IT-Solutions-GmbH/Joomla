<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

/**
 * Streams the stored DMARC records of a domain as a CSV download, turning the
 * raw aggregate data into something an operator can open in a spreadsheet.
 *
 * @since  1.0.0
 */
class ExportController extends BaseController
{
    public function records()
    {
        $this->checkToken('get');

        $app = Factory::getApplication();

        if (!$app->getIdentity()->authorise('core.manage', 'com_dmarcreports')) {
            throw new \Joomla\CMS\Access\Exception\NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $domainId = (int) $app->getInput()->getInt('domain_id', 0);

        if ($domainId <= 0) {
            $this->setRedirect(
                Route::_('index.php?option=com_dmarcreports&view=dashboard', false),
                Text::_('COM_DMARCREPORTS_DETAIL_CHOOSE_DOMAIN'),
                'warning'
            );

            return false;
        }

        $db    = Factory::getContainer()->get(DatabaseInterface::class);

        // Snapshot the highest record id now and bound every chunk to it. Records
        // are append-only (ingestion inserts rows with higher auto-increment ids
        // and never reorders or rewrites existing ones), so an id ceiling freezes
        // the ordered result set: rows ingested during a long export get a higher
        // id and are excluded from all pages, so no record is duplicated or
        // skipped as later pages are read.
        $maxId = (int) $db->setQuery(
            (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
                ->select('MAX(' . $db->quoteName('id') . ')')
                ->from($db->quoteName('#__dmarc_records'))
                ->where($db->quoteName('domain_id') . ' = :domainId')
                ->bind(':domainId', $domainId, ParameterType::INTEGER)
        )->loadResult();

        $app->setHeader('Content-Type', 'text/csv; charset=utf-8', true);
        $app->setHeader('Content-Disposition', 'attachment; filename="dmarc-records-' . $domainId . '.csv"', true);
        $app->sendHeaders();

        $out = fopen('php://output', 'w');

        // Prepend a UTF-8 byte order mark so spreadsheet applications (notably
        // Excel on de-CH) detect UTF-8 and render accented characters correctly
        // instead of mojibake, and use the operator-configured field delimiter
        // (default semicolon) so Excel opens the file into columns rather than a
        // single column.
        fwrite($out, "\xEF\xBB\xBF");
        $delimiter = $this->csvDelimiter();

        // Pass an explicit (empty) escape argument: PHP 8.4 deprecates relying on
        // the default backslash escaping, and an empty escape keeps the output
        // RFC 4180 compatible (fields are quoted, not backslash-escaped).
        fputcsv($out, [
            'org_name', 'date_begin', 'date_end', 'source_ip', 'count', 'disposition',
            'dkim_result', 'spf_result', 'dkim_aligned', 'spf_aligned', 'header_from',
        ], $delimiter, '"', '');

        // Stream the records in bounded chunks so a domain with a very large
        // history does not have to be buffered in memory all at once. Each chunk
        // resumes from the last row's (date_begin, rec.id) key instead of a
        // growing OFFSET, so the database seeks straight to the next page via the
        // ordering columns rather than rescanning and discarding every preceding
        // row — keeping the export linear rather than quadratic in the number of
        // records.
        $chunk     = 2000;
        $cursorSet = false;
        $lastDate  = 0;
        $lastRecId = 0;

        do {
            $query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
                ->select(
                    [
                        $db->quoteName('rep.org_name'),
                        $db->quoteName('rep.date_begin'),
                        $db->quoteName('rep.date_end'),
                        $db->quoteName('rec.source_ip'),
                        $db->quoteName('rec.count'),
                        $db->quoteName('rec.disposition'),
                        $db->quoteName('rec.dkim_result'),
                        $db->quoteName('rec.spf_result'),
                        $db->quoteName('rec.dkim_aligned'),
                        $db->quoteName('rec.spf_aligned'),
                        $db->quoteName('rec.header_from'),
                        $db->quoteName('rec.id', 'rec_id'),
                    ]
                )
                ->from($db->quoteName('#__dmarc_records', 'rec'))
                ->join('INNER', $db->quoteName('#__dmarc_reports', 'rep'),
                    $db->quoteName('rep.id') . ' = ' . $db->quoteName('rec.report_id'))
                ->where($db->quoteName('rec.domain_id') . ' = :domainId')
                ->where($db->quoteName('rec.id') . ' <= :maxId')
                ->bind(':domainId', $domainId, ParameterType::INTEGER)
                ->bind(':maxId', $maxId, ParameterType::INTEGER)
                ->order($db->quoteName('rep.date_begin') . ' DESC, ' . $db->quoteName('rec.id') . ' ASC');

            if ($cursorSet) {
                // Keyset resume: continue strictly after the previous page's last
                // key under the "date_begin DESC, id ASC" order — an older report
                // date, or the same date with a higher record id. Two bound
                // placeholders carry the same date because a named parameter is
                // referenced twice in the predicate.
                $query->where(
                    '(' . $db->quoteName('rep.date_begin') . ' < :cursorDateLt'
                    . ' OR (' . $db->quoteName('rep.date_begin') . ' = :cursorDateEq'
                    . ' AND ' . $db->quoteName('rec.id') . ' > :cursorId))'
                )
                    ->bind(':cursorDateLt', $lastDate, ParameterType::INTEGER)
                    ->bind(':cursorDateEq', $lastDate, ParameterType::INTEGER)
                    ->bind(':cursorId', $lastRecId, ParameterType::INTEGER);
            }

            $query->setLimit($chunk);
            $rows = $db->setQuery($query)->loadAssocList() ?: [];

            foreach ($rows as $row) {
                // Capture the keyset cursor, then drop the helper id so it never
                // reaches the CSV; the remaining columns stay in header order.
                $lastDate  = (int) $row['date_begin'];
                $lastRecId = (int) $row['rec_id'];
                unset($row['rec_id']);
                $cursorSet = true;

                $row['date_begin'] = gmdate('Y-m-d', (int) $row['date_begin']);
                $row['date_end']   = gmdate('Y-m-d', (int) $row['date_end']);
                fputcsv($out, array_map([self::class, 'sanitiseCell'], $row), $delimiter, '"', '');
            }
        } while (\count($rows) === $chunk);

        fclose($out);

        $app->close();

        return true;
    }

    /**
     * Resolve the configured CSV field delimiter to its character. Defaults to a
     * semicolon so Excel on a de-CH locale opens the export into columns.
     */
    private function csvDelimiter(): string
    {
        $configured = (string) \Joomla\CMS\Component\ComponentHelper::getParams('com_dmarcreports')
            ->get('csv_delimiter', 'semicolon');

        return match ($configured) {
            'comma' => ',',
            'tab'   => "\t",
            default => ';',
        };
    }

    /**
     * Neutralise CSV formula injection: a spreadsheet treats a cell beginning
     * with =, +, -, @ or a leading control character (tab, carriage return or
     * line feed) as a formula, so such values are prefixed with a single quote
     * to force literal text.
     */
    private static function sanitiseCell($value): string
    {
        $value = (string) $value;

        if ($value !== '' && \in_array($value[0], ['=', '+', '-', '@', "\t", "\r", "\n"], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
