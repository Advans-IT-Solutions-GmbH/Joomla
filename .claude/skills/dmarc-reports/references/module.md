# Dashboard Module — `mod_dmarcreports`

The administrator control-panel module that surfaces the DMARC Reports summary on the
Joomla backend dashboard. It is a **thin presenter over `com_dmarcreports`**: every figure
and every Action Center item comes from the component, so the module never duplicates query
logic, thresholds or the persisted action status.

## Location

`mod_dmarcreports/` (repository root, same level as `com_dmarcreports/`). A standalone
top-level extension with the usual layout (`build.sh`/`build.env` → `shared/build`, `VERSION`,
`LICENSE.txt`, `updates/update.xml`, `composer.json`/`phpunit.xml`, `tests/`). Tag prefix
`moddmarcreports-v*`; scope `moddmarcreports`. No J2Commerce dependency — the test stacks are
plain Joomla 5 and 6.

## What it shows

Rendered on the administrator `cpanel` position:

- **Multi-domain summary** (gated by the `show_summary` parameter): monitored domains, total
  messages, SPF/DKIM pass rate, and would-be-rejected share.
- **Top Action Center items**, capped by the `action_limit` parameter, each with severity
  label/class and the owning domain, plus a "more actions" count and an "Open dashboard"
  button into `index.php?option=com_dmarcreports`.

## Component reuse (no query duplication)

`src/Helper/DmarcReportsHelper.php` → `getDashboard(Registry $params, CMSApplicationInterface $app)`:

1. `ComponentHelper::isEnabled('com_dmarcreports')` — short-circuit to `unavailable` if the
   component is absent or disabled (checked before any component class is touched).
2. `getIdentity()` + `authorise('core.manage', 'com_dmarcreports')` — `unauthorised` otherwise,
   so the module respects the component's ACL.
3. `bootComponent('com_dmarcreports')->getMVCFactory()->createModel('Dashboard', 'Administrator', ['ignore_request' => true])`,
   then `getTotals()` and `getActions()` (the same `DashboardModel` the component view uses).
4. Loads the component language (`JPATH_ADMINISTRATOR`) and renders items through the
   component's `ActionPresenter` (`title`, `text`, `severityLabel`, `severityClass`) so the
   wording matches the dashboard exactly.

Every component access is guarded (`class_exists`/`method_exists`) and wrapped in
`try/catch (\Throwable)`. States returned to the layout: `ok`, `unavailable`, `unauthorised`,
`error`. For any non-`ok` state `tmpl/default.php` early-returns a short translated notice
instead of failing.

The pure helpers `summariseTotals()`, `rejectRate()` and `limitActions()` are Joomla-free and
unit-tested (`tests/Unit/`); `getDashboard()` needs a running Joomla and is covered by the
integration suite. `summariseTotals()` mirrors the component's `getTotals()` aggregation so
figures stay identical if a caller passes raw per-domain summaries.

## Graceful degradation (testable in isolation)

The module's own test environment installs **only the module** (component absent), so the
`graceful-degradation` suite directly asserts the `unavailable` notice renders, no raw language
key leaks, and no summary cards (`mod-dmarcreports__summary`) appear. See the module's
`tests/scripts/` and `references/testing.md` in the `joomla-extensions` skill for the shared
harness contract.

## Keep in sync with the component

If the component's `DashboardModel::getTotals()`/`getActions()` shape or `ActionPresenter`
method signatures change, update this helper's `normaliseTotals()`/`presentActions()` to match.
Do **not** change `com_dmarcreports` from the module's branch — coordinate a component change
separately. Versions (`VERSION`, manifest, `update.xml`) are owned by the release workflow —
never set by hand.
