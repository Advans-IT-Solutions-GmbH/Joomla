# Backend pointer regressions

These tests run against installed extensions in a disposable, mail-disabled Joomla site,
not mocked HTML. They cover the Appointments event-type editor and the DMARC administrator
module editor: every top-level tab and every nested permission group, including closing
and reopening active mobile accordion sections.

The matrix uses real Microsoft Edge and Playwright Firefox at **375, 768, 1024, 1280, 1440,
1600 and 1920 px**, each in light and dark mode: 84 pointer cases. Another 24 cases at
375 and 1600 px reject an empty required title, then save and reload the title and an
explicit permission for a synthetic group. The six browser/language/form
groups reproduce the original diagnostic matrix: Edge/French for both forms, Firefox/German
for both forms, and Firefox/English and Firefox/French for the event-type form.
Each case starts in a fresh browser context. Tests assert the actual language and theme, generated tab
structure, target pane visibility and desktop selection state. No forced clicks, JavaScript
clicks, hidden-control skipping or retries turn a failed pointer action into a pass.
The form opens at desktop width and awaits initial network idle, matching the original
diagnostic protocol, before resizing to the target width. This includes Joomla's asynchronous
statistics-consent notice; it must not shift the form between pointer down and pointer up.
Existing visible controls
receive focus and native constraint checks before navigation, reproducing the original stress
sequence without submitting data.
The tests also preserve an unsaved title and every permission selector's name/value through
navigation, and record the actual number of pointer clicks in each pointer result.
The event-type fixture has its own persisted ACL asset, whose hidden form binding is
asserted before exercising the rules widget. Save tests check the actual posted permission
and its reloaded value, not just unchanged client-side controls.
The theme is selected through Atum's supported `userColorScheme` preference cookie and checked
on the rendered document, rather than assuming Firefox applies an OS preference at startup.
Every initial click has a two-second action timeout, with Playwright's normal scrolling,
visibility, stability and hit-target checks. There is no preparatory centred scrolling or
recovery click. Permission panes must stay within the viewport, and their selectors must
retain a usable width. Runtime styles scoped to the two editors prevent competing smooth
scrolls during focus/accordion reflow and correct Atum's intermediate-width rules layout.

`Backend Pointer Regression` builds the current-head packages, installs them in the existing
Joomla 6 container and runs this matrix. `Collect Results` requires the workflow whenever its
path filters match. The existing Appointments J5/J6 functional suites remain unchanged.
CI installs browser binaries before creating authentication cookies, and gives only its
disposable site a 60-minute session lifetime. A slow browser download must not expire every
cookie before the first pointer test; authentication failures still fail the precondition.

## Local execution

Use only a disposable test installation with the Appointments component and DMARC module
installed, together with the official German and French Joomla language packages.
CI resolves current Joomla 6 translations from Joomla's official metadata and verifies
their advertised SHA256 checksums. **Never point this fixture at a deployment or production database.** The fixture
requires explicit CLI opt-in, a loopback URL, a local database and disabled mail. It creates
a new synthetic administrator, schedule, unpublished event type and seven synthetic ACL groups. It authenticates through a
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
storage states, network traces or credentials. The suite asserts pointer/layout access and these save/validation round trips, not exhaustive
visual or every-field acceptance of every extension.
