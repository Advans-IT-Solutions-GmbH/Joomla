<?php
/**
 * @package     DMARC Reports Dashboard Module
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Module\DmarcReports\Administrator\Helper;

use Advans\Component\DmarcReports\Administrator\Helper\ActionPresenter;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

/**
 * Collects the control-panel summary for the backend dashboard module. The
 * module is a thin presenter: all DMARC statistics and the Action Center are
 * produced by the com_dmarcreports Dashboard model, so the same query logic,
 * thresholds and persisted action status feed both the component dashboard and
 * this module. When the component is missing, disabled or unreadable the helper
 * degrades gracefully and the module shows a short notice instead of failing.
 *
 * The pure, Joomla-free helpers (summariseTotals, rejectRate, limitActions) are
 * unit tested; the orchestration in getDashboard needs a running Joomla and is
 * exercised by the integration suite.
 *
 * @since  1.0.0
 */
class DmarcReportsHelper
{
    /**
     * The component this module reports on.
     *
     * @var    string
     * @since  1.0.0
     */
    public const COMPONENT = 'com_dmarcreports';

    /**
     * Assemble the dashboard payload for the layout.
     *
     * @param   Registry                 $params  The module parameters.
     * @param   CMSApplicationInterface  $app     The administrator application.
     *
     * @return  array<string, mixed>
     *
     * @since   1.0.0
     */
    public function getDashboard(Registry $params, CMSApplicationInterface $app): array
    {
        $data = [
            'state'       => 'unavailable',
            'totals'      => self::summariseTotals([]),
            'rejectRate'  => 0.0,
            'actions'     => [],
            'activeCount' => 0,
            'link'        => 'index.php?option=' . self::COMPONENT,
        ];

        if (!ComponentHelper::isEnabled(self::COMPONENT)) {
            return $data;
        }

        $user = $app->getIdentity();

        if ($user === null || !$user->authorise('core.manage', self::COMPONENT)) {
            $data['state'] = 'unauthorised';

            return $data;
        }

        try {
            $model = $this->bootDashboardModel($app);

            if ($model === null) {
                return $data;
            }

            $app->getLanguage()->load(self::COMPONENT, JPATH_ADMINISTRATOR);

            $data = $this->presentDashboard($model, $params);
        } catch (\Throwable $e) {
            $data['state']  = 'error';
            $data['totals'] = self::summariseTotals([]);

            return $data;
        }

        return $data;
    }

    /**
     * Turn a component Dashboard model into the "ok" payload the layout renders.
     * Kept separate from getDashboard so the transformation and ActionPresenter
     * reuse can be exercised with a model double, without a running component.
     *
     * @param   object    $model   A component Dashboard model (getTotals/getActions).
     * @param   Registry  $params  The module parameters.
     *
     * @return  array<string, mixed>
     *
     * @since   1.0.0
     */
    public function presentDashboard(object $model, Registry $params): array
    {
        $totals = $this->normaliseTotals($model->getTotals());
        $active = $model->getActions();
        $limit  = (int) $params->get('action_limit', 5);

        return [
            'state'       => 'ok',
            'totals'      => $totals,
            'rejectRate'  => self::rejectRate($totals),
            'actions'     => $this->presentActions(self::limitActions($active, $limit)),
            'activeCount' => \count($active),
            'link'        => 'index.php?option=' . self::COMPONENT,
        ];
    }

    /**
     * Sum the per-domain summary rows into the overall totals shown in the
     * module. Mirrors the component Dashboard model so the figures match.
     *
     * @param   array<int, array<string, mixed>>  $summaries  Per-domain summary rows.
     *
     * @return  array<string, int|float>
     *
     * @since   1.0.0
     */
    public static function summariseTotals(array $summaries): array
    {
        $totals = ['domains' => 0, 'total' => 0, 'dmarc_pass' => 0, 'reject_under_p' => 0, 'pass_rate' => 0.0];

        foreach ($summaries as $row) {
            $totals['domains']++;
            $totals['total']          += (int) ($row['total'] ?? 0);
            $totals['dmarc_pass']     += (int) ($row['dmarc_pass'] ?? 0);
            $totals['reject_under_p'] += (int) ($row['reject_under_p'] ?? 0);
        }

        $totals['pass_rate'] = $totals['total'] > 0
            ? round($totals['dmarc_pass'] / $totals['total'] * 100, 1)
            : 0.0;

        return $totals;
    }

    /**
     * Share of messages that would be rejected under the published policy.
     *
     * @param   array<string, int|float>  $totals  Overall totals.
     *
     * @return  float
     *
     * @since   1.0.0
     */
    public static function rejectRate(array $totals): float
    {
        $total = (int) ($totals['total'] ?? 0);

        return $total > 0
            ? round((int) ($totals['reject_under_p'] ?? 0) / $total * 100, 1)
            : 0.0;
    }

    /**
     * Keep only the first $limit action items. A limit of zero hides the list;
     * a negative limit is treated as zero.
     *
     * @param   array<int, array<string, mixed>>  $active  Active action items.
     * @param   int                               $limit   Maximum items to keep.
     *
     * @return  array<int, array<string, mixed>>
     *
     * @since   1.0.0
     */
    public static function limitActions(array $active, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        return \array_slice($active, 0, $limit);
    }

    /**
     * Cast the component totals to the scalar types the layout expects.
     *
     * @param   array<string, mixed>  $totals  Raw totals from the component model.
     *
     * @return  array<string, int|float>
     *
     * @since   1.0.0
     */
    private function normaliseTotals(array $totals): array
    {
        return [
            'domains'        => (int) ($totals['domains'] ?? 0),
            'total'          => (int) ($totals['total'] ?? 0),
            'dmarc_pass'     => (int) ($totals['dmarc_pass'] ?? 0),
            'reject_under_p' => (int) ($totals['reject_under_p'] ?? 0),
            'pass_rate'      => (float) ($totals['pass_rate'] ?? 0.0),
        ];
    }

    /**
     * Render the raw action items into localised rows for the layout, reusing
     * the component's ActionPresenter so the wording matches the dashboard.
     *
     * @param   array<int, array<string, mixed>>  $items  Active action items.
     *
     * @return  array<int, array<string, mixed>>
     *
     * @since   1.0.0
     */
    private function presentActions(array $items): array
    {
        if (!class_exists(ActionPresenter::class)) {
            return [];
        }

        $rows = [];

        foreach ($items as $item) {
            $severity = (int) ($item['severity'] ?? 0);

            $rows[] = [
                'title'         => ActionPresenter::title($item),
                'text'          => ActionPresenter::text($item),
                'severity'      => $severity,
                'severityClass' => ActionPresenter::severityClass($severity),
                'severityLabel' => ActionPresenter::severityLabel($severity),
                'domain'        => (string) ($item['domain'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Boot the component and create its Dashboard model, or return null when the
     * component cannot provide one (files missing despite an enabled row).
     *
     * @param   CMSApplicationInterface  $app  The administrator application.
     *
     * @return  object|null
     *
     * @since   1.0.0
     */
    private function bootDashboardModel(CMSApplicationInterface $app): ?object
    {
        if (!method_exists($app, 'bootComponent')) {
            return null;
        }

        $component = $app->bootComponent(self::COMPONENT);

        if (!method_exists($component, 'getMVCFactory')) {
            return null;
        }

        $model = $component->getMVCFactory()->createModel('Dashboard', 'Administrator', ['ignore_request' => true]);

        if (!\is_object($model) || !method_exists($model, 'getTotals') || !method_exists($model, 'getActions')) {
            return null;
        }

        return $model;
    }
}
