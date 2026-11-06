# Backend pointer regressions

These tests run against installed extensions in a disposable, mail-disabled Joomla site,
not mocked HTML. They cover the Appointments event-type editor and the DMARC administrator
module editor: every top-level tab and every nested permission group, including closing
and reopening active mobile accordion sections.

The matrix uses real Microsoft Edge and Playwright Firefox at **375, 768, 1024, 1280, 1440,
1600 and 1920 px**, each in light and dark mode: 28 browser projects and 56 form cases.
Each case starts in a fresh browser context. Tests assert the actual theme, generated tab
structure, target pane visibility and desktop selection state. No forced clicks, JavaScript
clicks, hidden-control skipping or retries turn a failed pointer action into a pass.
The tests also preserve an unsaved title and every permission selector's name/value through
navigation, and record the actual number of pointer clicks in each result.
The theme is selected through Atum's supported `userColorScheme` preference cookie and checked
on the rendered document, rather than assuming Firefox applies an OS preference at startup.
Ordinary instantaneous centred scrolling keeps controls away from Atum's sticky toolbar before clicking;
no pending smooth-scroll animation may move the hit target between mouse-down and mouse-up.
Playwright then performs its normal visibility, stability and hit-target checks.

`Backend Pointer Regression` builds the current-head packages, installs them in the existing
Joomla 6 container and runs this matrix. `Collect Results` requires the workflow whenever its
path filters match. The existing Appointments J5/J6 functional suites remain unchanged.

## Local execution

Use only a disposable test installation with the Appointments component and DMARC module
installed. **Never point this fixture at a deployment or production database.** The fixture
requires explicit CLI opt-in, a loopback URL, a local database and disabled mail. It creates
a new synthetic administrator and seven synthetic ACL groups. It authenticates through a
CLI-created Joomla session, never a browser password login. Destroy the disposable database
after use; do not retain the user or groups on an operator's site.

From this directory, in Bash with Joomla's PHP extensions available:

```bash
npm ci --ignore-scripts --no-audit --no-fund
npx playwright install --with-deps msedge firefox
umask 077
BACKEND_POINTER_TEST=1 BACKEND_POINTER_SITE=/path/to/disposable/joomla \
  BACKEND_POINTER_BASE_URL=http://localhost:8081 php fixture.php > /tmp/backend-pointer-fixture.json
BACKEND_POINTER_FIXTURE=/tmp/backend-pointer-fixture.json npm test
rm /tmp/backend-pointer-fixture.json
```

The JSON fixture contains an authenticated synthetic session cookie. Keep it private; do not
commit or upload it. Results contain assertion output and failure screenshots, not browser
storage states, network traces or credentials. The suite asserts pointer access, not exhaustive
visual or every-field acceptance of every extension.
