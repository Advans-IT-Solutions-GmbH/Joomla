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

Results:
- PASS:
- FAIL:
- BLOCKED:
- NOT RUN:

Case-level execution records (repeat for every case):
- Test ID / type:
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

Reason:
Name / date:

Separate Copilot recommendation:
- Reviewed commit:
- Review reference / recommendation:
```
