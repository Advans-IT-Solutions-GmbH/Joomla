/**
 * Actual installed-Core lifecycle and partial-failure checks. The CLI helper
 * only observes records or configures synthetic failure conditions: all request
 * creation, confirmation, Remove and Complete actions are genuine HTTP actions.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {createHash} = require('node:crypto');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {root, route, mailDir, tokenFor, submit, check} = require('./browser.cjs');
let observation = 0;

function fixture(op, value = '', ...extra) {
    assert(process.env.PRIVACY_TEST_ROOT && process.env.PRIVACY_PHP, 'Explicit isolated fixture controls required');
    const args = JSON.parse(process.env.PRIVACY_PHP_ARGS || '[]');
    const result = JSON.parse(execFileSync(process.env.PRIVACY_PHP,
        [...args, path.join(__dirname, 'fixture.php'), op, String(value), ...extra], {encoding: 'utf8'}));
    if (process.env.PRIVACY_TEST_REPORT_DIR) {
        fs.writeFileSync(path.join(process.env.PRIVACY_TEST_REPORT_DIR, `observation-${++observation}.json`),
            JSON.stringify({op, value, result}, null, 2), {flag: 'wx'});
    }
    return result;
}
async function create(page, email) {
    const before = new Set(fs.readdirSync(mailDir));
    await page.goto(route);
    check('New public request has neutral response', (await submit(page, email, 'request')).includes('If this address'));
    return tokenFor(email, before);
}
async function action(page, task) {
    await Promise.all([page.waitForNavigation(), page.evaluate(task => Joomla.submitbutton(task), task)]);
}
async function main() {
    for (const file of ['lifecycle.cjs', 'browser.cjs', 'fixture.php']) {
        console.log('EXECUTION_BINDING', JSON.stringify({file,
            sha256: createHash('sha256').update(fs.readFileSync(path.join(__dirname, file))).digest('hex')}));
    }
    console.log('CORE_BINDINGS', JSON.stringify(fixture('bindings')));
    fixture('admission-reset');
    const browser = await chromium.launch({channel: 'msedge', headless: true});
    try {
        const page = await browser.newPage();
        const admin = await browser.newPage();
        await admin.goto(`${root}/administrator/index.php`);
        await admin.locator('#mod-login-username').fill(process.env.JOOMLA_ADMIN_USERNAME);
        await admin.locator('#mod-login-password').fill(process.env.JOOMLA_ADMIN_PASSWORD);
        await Promise.all([admin.waitForNavigation(), admin.locator('button[type="submit"]').click()]);
        const stamp = Date.now();
        const failedMail = `mail-failure-${stamp}@example.test`;
        try {
            fixture('mail-hide');
            await page.goto(route);
            check('Native MailTemplate false result is not reported as mail sent',
                (await submit(page, failedMail, 'request')).includes('does not confirm receipt'));
            const failed = fixture('snapshot', failedMail);
            const first = failed.requests;
            check('Failed initial delivery retains one actual pending receipt', first.length === 1 && Number(first[0].status) === 0);
            check('Delivery failure is explicitly persisted for the actual pending Core receipt',
                Number(failed.deliveries[0]?.delivery_failed) === 1);
            await admin.goto(`${root}/administrator/index.php?option=com_privacy&view=request&id=${first[0].id}`);
            check('Authorized native Core request view exposes actionable delivery failure',
                await admin.locator('[data-privacy-delivery="failed"]').isVisible());
            await new Promise(resolve => setTimeout(resolve, 1100));
            check('Failed renewal makes no delivery claim',
                (await submit(page, failedMail, 'request')).includes('does not confirm receipt'));
            const renewed = fixture('snapshot', failedMail).requests;
            check('Failed renewal preserves Core id, pending state and original receipt date',
                renewed.length === 1 && renewed[0].id === first[0].id && Number(renewed[0].status) === 0
                && renewed[0].requested_at === first[0].requested_at);
        } finally {
            fixture('mail-restore');
        }
        const firstReceipt = fixture('snapshot', failedMail).requests[0];
        const renewedMail = await create(page, failedMail);
        check('A failed delivery remains actionable without an account',
            (await submit(page, failedMail, 'confirm', renewedMail.token)).includes('No data has been removed'));
        const recovered = fixture('snapshot', failedMail).requests[0];
        check('Successful recovery still preserves the original received date', recovered.requested_at === firstReceipt.requested_at);

        const email = `lifecycle-${stamp}@example.test`;
        const firstMail = await create(page, email);
        const created = fixture('snapshot', email);
        const receipt = created.requests[0];
        const audit = created.audit.filter(row => row.message_language_key === 'COM_PRIVACY_ACTION_LOG_CREATED_REQUEST');
        check('Native guest creation audit has actor zero, subject, request and no fictitious account link',
            audit.length === 1 && Number(audit[0].user_id) === 0
            && JSON.parse(audit[0].message).subjectemail === email
            && Number(JSON.parse(audit[0].message).id) === Number(receipt.id)
            && !('accountlink' in JSON.parse(audit[0].message))
            && audit[0].log_date >= receipt.requested_at && created.accounts === 0);
        fixture('expire', email);
        check('Expired token does not expire the request/right',
            (await submit(page, email, 'confirm', firstMail.token)).includes('not your right'));
        check('Core receipt remains pending after expiry', Number(fixture('snapshot', email).requests[0].status) === 0);
        const currentMail = await create(page, email);
        check('Renewal is not a new receipt', fixture('snapshot', email).requests[0].requested_at === receipt.requested_at);
        check('Renewal invalidates the old token',
            (await submit(page, email, 'confirm', firstMail.token)).includes('could not be confirmed'));
        check('Renewed code confirms through genuine Core',
            (await submit(page, email, 'confirm', currentMail.token)).includes('No data has been removed'));
        const consumed = fixture('snapshot', email);
        check('Consumed current token cannot be replayed',
            (await submit(page, email, 'confirm', currentMail.token)).includes('could not be confirmed'));
        check('Consumed-token replay leaves the confirmed receipt and audit unchanged',
            JSON.stringify(fixture('snapshot', email)) === JSON.stringify(consumed));
        const requestUrl = `${root}/administrator/index.php?option=com_privacy&view=request&id=${receipt.id}`;
        await admin.goto(requestUrl);
        await action(admin, 'request.complete');
        check('Native Complete before Remove is vetoed', Number(fixture('snapshot', email).requests[0].status) === 1);
        fixture('probe', 'block');
        await admin.goto(requestUrl);
        await action(admin, 'request.remove');
        let state = fixture('snapshot', email);
        check('Real privacy blocker runs before every removal callback',
            state.events.some(row => row.stage === 'can-remove' && row.mode === 'block')
            && !state.events.some(row => row.stage === 'remove'));
        check('Blocked operation is not a successful removal', Number(state.outcomes[0]?.reviewed_result) === 0);
        fixture('probe', 'throw');
        await admin.goto(requestUrl);
        const unreviewed = fixture('snapshot', email);
        await action(admin, 'request.remove');
        check('Unchecked remediation retry cannot reach removal callbacks or mutate the failed receipt',
            JSON.stringify(fixture('snapshot', email)) === JSON.stringify(unreviewed));
        await admin.goto(requestUrl);
        await admin.locator('input[name="jform[privacy_remediation_reviewed]"]').check();
        await action(admin, 'request.remove');
        state = fixture('snapshot', email);
        check('Thrown real callback cannot make removal or completion successful',
            state.events.some(row => row.stage === 'remove' && row.mode === 'throw')
            && Number(state.outcomes[0]?.reviewed_result) === 0 && Number(state.requests[0].status) === 1);
        fixture('probe', 'warning');
        await admin.goto(requestUrl);
        await admin.locator('input[name="jform[privacy_remediation_reviewed]"]').check();
        await action(admin, 'request.remove');
        state = fixture('snapshot', email);
        check('Returned warning remains incomplete despite Core dispatch success', Number(state.outcomes[0]?.reviewed_result) === 0);
        fixture('probe', 'allow');
        await admin.goto(requestUrl);
        await admin.locator('input[name="jform[privacy_remediation_reviewed]"]').check();
        await action(admin, 'request.remove');
        state = fixture('snapshot', email);
        check('Native authorized Remove dispatches real plugins without automatically completing',
            state.events.some(row => row.stage === 'remove' && row.mode === 'allow')
            && Number(state.outcomes[0]?.reviewed_result) === 1 && Number(state.requests[0].status) === 1);
        await admin.goto(requestUrl);
        await action(admin, 'request.complete');
        check('Successful Remove alone is insufficient for unreviewed Complete',
            Number(fixture('snapshot', email).requests[0].status) === 1);
        await admin.goto(requestUrl);
        await admin.locator('input[name="jform[privacy_scope_reviewed]"]').check();
        check('Native review checkbox submits affirmative value only when checked',
            await admin.locator('input[name="jform[privacy_scope_reviewed]"]').inputValue() === '1');
        await action(admin, 'request.complete');
        state = fixture('snapshot', email);
        if (Number(state.requests[0].status) !== 2) {
            console.error('Native completion messages:', await admin.locator('joomla-alert').allTextContents());
        }
        check('Explicitly reviewed separate native Core Complete and its audit succeed',
            Number(state.requests[0].status) === 2
            && state.audit.some(row => row.message_language_key.includes('COMPLETED'))
            && state.accounts === 0);
        const pending = `pending-${stamp}@example.test`;
        await create(page, pending);
        const native = `native-pending-${stamp}@example.test`;
        await admin.goto(`${root}/administrator/index.php?option=com_privacy&task=request.add`);
        await admin.locator('#jform_email').fill(native);
        await admin.locator('#jform_request_type').selectOption('remove');
        await action(admin, 'request.save');
        check('Unmanaged native administrator-created pending request exists',
            Number(fixture('snapshot', native).requests[0]?.status) === 0);
        const unknown = `unknown-${stamp}@example.test`;
        const beforeOff = [pending, failedMail, native, unknown].map(email => fixture('snapshot', email).requests);
        const messages = [];
        const mailState = fixture('mail-off');
        try {
            for (const address of [pending, failedMail, native, unknown]) {
                await page.goto(route);
                messages.push(await submit(page, address, 'request'));
            }
        } finally {
            fixture('mail-on', mailState.original_mailonline);
        }
        check('Mail-disabled availability is identical for pending, confirmed, native and unknown addresses',
            messages.every(message => message === messages[0] && message.includes('could not finish')));
        const afterOff = [pending, failedMail, native, unknown].map(email => fixture('snapshot', email).requests);
        check('Mail-disabled probes neither create fake receipts nor alter existing receipts',
            JSON.stringify(beforeOff) === JSON.stringify(afterOff));
        for (const failure of ['false', 'throw']) {
            const fresh = `unknown-${failure}-${stamp}@example.test`;
            const addresses = [pending, failedMail, native, fresh];
            const original = addresses.map(address => fixture('snapshot', address).requests);
            const replies = [];
            try {
                fixture(failure === 'false' ? 'mail-hide' : 'mail-throw');
                for (const address of addresses) {
                    await page.goto(route);
                    replies.push(await submit(page, address, 'request'));
                }
            } finally {
                fixture('mail-restore');
                fixture('mail-unthrow');
            }
            check(`Mail-${failure} response is identical for pending, confirmed, native and unknown addresses`,
                replies.every(reply => reply === replies[0] && reply.includes('does not confirm receipt')));
            const after = addresses.map(address => fixture('snapshot', address));
            check(`Mail-${failure} creates a genuine pending unknown receipt and persists explicit delivery failure`,
                after[3].requests.length === 1 && Number(after[3].requests[0].status) === 0
                && Number(after[3].deliveries[0]?.delivery_failed) === 1
                && Number(after[0].deliveries[0]?.delivery_failed) === 1);
            check(`Mail-${failure} preserves original managed receipt date and never adopts or renews legacy receipt`,
                after[0].requests[0].id === original[0][0].id
                && after[0].requests[0].requested_at === original[0][0].requested_at
                && JSON.stringify(after[1].requests) === JSON.stringify(original[1])
                && JSON.stringify(after[2].requests) === JSON.stringify(original[2])
                && after[2].deliveries.length === 0);
        }
        fixture('admission-reset');
        const refused = `rate-refused-${stamp}@example.test`;
        fixture('admission-exhaust', refused);
        await page.goto(route);
        check('Admission-refused unknown address gets no invented receipt or delivery claim',
            (await submit(page, refused, 'request')).includes('does not confirm receipt')
            && fixture('snapshot', refused).requests.length === 0);
    } finally {
        fixture('mail-restore');
        fixture('mail-unthrow');
        fixture('probe', 'block');
        await browser.close();
    }
}
module.exports = {fixture, create, action};
if (require.main === module) {
    main().catch(error => {console.error(error); process.exitCode = 1;});
}
