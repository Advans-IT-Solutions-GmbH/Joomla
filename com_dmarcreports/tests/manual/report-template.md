# Blank manual acceptance report

Copy this template into private, access-controlled storage for an execution of the
[manual test plan](README.md). Do not commit completed reports or raw evidence to the public
repository. This blank template records no executed tests or acceptance decision.

```text
Test report: pkg_dmarcreports (com_dmarcreports, mod_dmarcreports, plg_task_dmarcreports, plg_system_dmarcmtasts)

Actual tested commit:
Installation package / checksum:
Test period:
Tester:

Environments:
- Joomla / PHP / database:
- Browser / operating system:
- IMAP mail transport (server / SSL or STARTTLS):
- DNS resolver / SSRF fixtures:
- Languages inspected:

Coverage declaration (before execution; plan section 2.1.1):
- Full 194-case runs and section 17: Joomla 5.4/MySQL; Joomla 6/MySQL:
- MariaDB repeat set and section 17:
- Required browser / language / screen repetitions:
- IMAP mailbox and auto-ingestion repetitions (server recorded):
- Isolated DNS / SSRF fixture runs:
- Live-DNS/TLS MTA-STS publication condition (MS-11, live parts of SY-04..SY-08, MS-10): active / inactive
- Test domain used (redacted) and reason if active:

Results:
- PASS:
- FAIL:
- BLOCKED:
- NOT RUN:
- Mandatory or activated executions BLOCKED / NOT RUN (acceptance blockers):
- Inactive live-DNS/TLS publication repetitions NOT RUN (separately counted, with reasons):

Case-level execution records (repeat for every case):
- Test ID / type:
- Run / platform / mailbox / resolver:
- Applicability: mandatory / active live-DNS repetition / inactive live-DNS repetition
- Activation decision and reason if live-DNS repetition:
- Actual tested commit / package:
- Environment:
- Initial state / synthetic fixture:
- Actual result:
- Result: PASS / FAIL / BLOCKED / NOT RUN
- Private evidence reference / redacted public defect reference:
- Tester / date:

Open defects:
- Test case:
- Priority:
- Reproduction steps:
- Expected result:
- Actual result:
- Redacted evidence:
- Defect reference:

Specifically confirmed:
- Idempotent dedupe (no duplicate under a reused report id):
- Parser rejection of every malformed-report class:
- Auto-ingestion parallel safety (locks, exactly-once):
- SSRF refusal of internal and rebinding targets:
- MTA-STS domain gate (served only for monitored, published domains):
- TLS-RPT routed to its own tables:
- Self-hosting without a third-party service:
- Joomla privacy (mailbox password encrypted, never logged):
- Retention and uninstall (all tables and data removed):
- Roles and permissions (auto-create gating):

Untested integrations or limitations:

Acceptance decision:
[ ] Accepted
[ ] Accepted with explicitly listed limitations
[ ] Not accepted

Coverage gate:
- All mandatory and activated executions performed (no BLOCKED / NOT RUN):
- Inactive exceptions limited to declared live-DNS/TLS publication repetitions:
- FAIL results resolved or explicitly assessed as P2/P3 limitations:
- No open P0/P1 defects:

Reason:
Name / date:

Separate Copilot recommendation:
- Reviewed commit:
- Review reference / recommendation:
```
