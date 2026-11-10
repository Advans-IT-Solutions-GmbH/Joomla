# Isolated native CI

Build the package with `bash build.sh` from the extension directory and copy the
resulting ZIP to `tests/extension.zip`. From `tests`, run
`docker compose -p plg_system_privacyrequests_test up -d --build`, then
`bash run-tests.sh all`. Cleanup is
`docker compose -p plg_system_privacyrequests_test down -v`.
The stack has no published host ports. Use a distinct Compose project and
`CONTAINER_NAME` for concurrent stacks. Set
`JOOMLA_TEST_IMAGE=joomla:6-php8.4-apache` for the latest Joomla 6.x lane;
the default is the latest Joomla 5.4.x/PHP 8.3 image.

The normal CLI installer leaves the plugin disabled. The native suites check
installed files, SQL and absence of Scheduler setup, then exercise the installed
service and public Core models with a supported native mail-template pre-render
listener that deliberately throws before transport in that process.
No transport is invoked and no mail setting is enabled or changed.
They verify persistent pending receipts, native audit, delivery failure, renewal
and invalid-token refusal. The last suite uninstalls the plugin natively,
checks that exactly the three auxiliary tables are gone while existing Core
privacy requests and action-log rows stay byte-for-byte unchanged, then
reinstalls the same ZIP (overridable with `PRIVACY_PACKAGE`) and restores the
earlier plugin settings. These suites are not delivered-token or browser acceptance.
The existing CJS suites require separate synthetic fixtures, actual captured
mail, Playwright and native administrator authorization; they must never target
a live site. `export.cjs` covers native export requests: a registered frontend
request and administrator-created account-free requests, confirmed with the
delivered token, then the native administrator download and export mail. It
checks the J2Commerce and Appointments privacy domains, with this adapter
enabled and disabled; this adapter itself handles removal only. CI also arms
and checks the shared production-like deprecation gate.

The compatibility/release requirements checker remains unchanged and mandatory.
The approved distribution channel uses this repository's GitHub raw update feed
and version-specific GitHub Release ZIPs. The real release-preparation workflow
owns all version changes and creates the first update feed in its release PR;
the publish workflow builds only the verified merged release source. Neither
workflow is dispatched by these tests. A development artifact is not a published
release or evidence of Docker/browser acceptance.
