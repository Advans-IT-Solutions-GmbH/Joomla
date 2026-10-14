# Blank manual acceptance report

Copy this template into private, access-controlled storage for an execution of the
[manual test plan](README.md). Do not commit completed reports or raw evidence to the public
repository. This blank template records no executed tests or acceptance decision.

```text
Test report: com_appointments

PR:
Actual tested commit:
Installation package / checksum:
Test period:
Tester:

Environments:
- Joomla / PHP / database:
- Browser / operating system:
- Mail transport:
- Calendar clients and versions:
- Languages inspected:

Coverage declaration (before execution; plan section 2.1.1):
- Full 228-case runs and section 18: Joomla 5.4/MySQL; Joomla 6/MySQL:
- MariaDB repeat set and section 18:
- Required browser / language / JavaScript repetitions:
- Outlook Desktop / Thunderbird client repetitions and versions:
- Apple Calendar condition: active / inactive
- Apple support/use decision, reason and client/version if active:

Results:
- PASS:
- FAIL:
- BLOCKED:
- NOT RUN:
- Mandatory or activated executions BLOCKED / NOT RUN (acceptance blockers):
- Inactive Apple repetitions NOT RUN (separately counted, with reasons):

Case-level execution records (repeat for every case):
- Test ID / type:
- Run / platform / client:
- Applicability: mandatory / active Apple repetition / inactive Apple repetition
- Activation decision and reason if Apple repetition:
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
- No double booking:
- Correct time zones and clock changes:
- Invitations / updates / cancellations:
- Self-service erasure:
- Joomla privacy:
- Retention:
- Roles and permissions:
- JavaScript-free flow:
- Self-hosting without a third-party scheduling service:

Untested integrations or limitations:

Acceptance decision:
[ ] Accepted
[ ] Accepted with explicitly listed limitations
[ ] Not accepted

Coverage gate:
- All mandatory and activated executions performed (no BLOCKED / NOT RUN):
- Inactive exceptions limited to declared Apple repetitions:
- FAIL results resolved or explicitly assessed as P2/P3 limitations:
- No open P0/P1 defects:

Reason:
Name / date:

Separate Copilot recommendation:
- Reviewed commit:
- Review reference / recommendation:
```
