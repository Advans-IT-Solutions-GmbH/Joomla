# Testing

## Test Infrastructure

Tests run via Docker against a real Joomla + J2Commerce installation. The shared test runner is in `shared/tests/`. Each plugin delegates to it via `tests/run-tests.sh`.

## Running Tests Locally

```bash
cd j2commerce/plg_privacy_j2commerce/tests
docker compose up -d
sleep 120   # wait for Joomla + J2Commerce to install
./run-tests.sh all
docker compose down -v
```

## Test Suites (Privacy Plugin)

| Suite | What it tests |
|-------|--------------|
| Installation | Plugin registration, file deployment |
| Configuration | Plugin params, language files |
| Privacy Plugin Base | Privacy API method validation |
| Data Integration | J2Commerce table access |
| Data Export | `onPrivacyExportRequest` output |
| Data Anonymization | `anonymizeOrders()`, field-level verification |
| GDPR Compliance | Retention period enforcement |
| Uninstall | Clean removal, no orphaned data |

## CI

Tests run automatically on every push to `main` that touches plugin or shared paths (see `j2commerce-privacy.yml`). CodeQL security analysis runs on all pushes.

## Known Limitations

- Automated test coverage is partial — manual testing required for UI flows (checkout consent checkbox, MyProfile privacy tab)
- Subscription/recurring product lifecycle not covered
