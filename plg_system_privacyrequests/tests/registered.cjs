/**
 * Actual registered-user Core request/confirmation regression on the isolated site.
 * The generic plugin stays enabled but must not take ownership of these requests.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {createHash} = require('node:crypto');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {root, mailDir, tokenFor, check} = require('./browser.cjs');
const {fixture, action} = require('./lifecycle.cjs');

async function submit(page, selector) {
    await Promise.all([page.waitForNavigation(), page.locator(selector).click()]);
}

async function main() {
    for (const file of ['registered.cjs', 'lifecycle.cjs', 'browser.cjs', 'fixture.php', 'listener-order.php']) {
        console.log('EXECUTION_BINDING', JSON.stringify({file,
            sha256: createHash('sha256').update(fs.readFileSync(path.join(__dirname, file))).digest('hex')}));
    }
    console.log('CORE_BINDINGS', JSON.stringify(fixture('bindings')));
    const email = `registered-${new URL(root).port}-${Date.now()}@example.test`;
    const account = fixture('account-create', email);
    const originalAccount = fixture('account-snapshot', account.user_id);
    const browser = await chromium.launch({channel: 'msedge', headless: true});
    try {
        const page = await browser.newPage();
        const requestUrl = `${root}/index.php?option=com_privacy&view=request`;
        const confirmUrl = `${root}/index.php?option=com_privacy&view=confirm`;
        await page.goto(requestUrl);
        check('Unchanged native guest request view requires a genuine login',
            await page.locator('#username').count() === 1 && await page.locator('#jform_request_type').count() === 0);
        await page.locator('#username').fill(account.username);
        await page.locator('#password').fill(process.env.JOOMLA_ADMIN_PASSWORD);
        await submit(page, '.login form button[type="submit"]');
        await page.goto(requestUrl);
        check('Genuinely authenticated registered user retains the native Core request form',
            await page.locator('#jform_request_type').count() === 1
            && await page.locator('#privacy-email').count() === 0);
        const csrf = await page.request.post(`${root}/index.php?option=com_privacy&task=request.submit`,
            {form: {'jform[request_type]': 'remove'}});
        check('Native registered request retains Core CSRF protection',
            (await csrf.text()).toLowerCase().includes('token') && fixture('snapshot', email).requests.length === 0);
        await page.goto(requestUrl);
        await page.locator('#jform_request_type').selectOption('remove');
        const before = new Set(fs.readdirSync(mailDir));
        await submit(page, '.request-form button[type="submit"]');
        const pending = fixture('snapshot', email);
        const receipt = pending.requests[0];
        check('Native registered submit creates one unowned pending Core receipt and real-user audit',
            pending.requests.length === 1 && Number(receipt.status) === 0
            && pending.deliveries.length === 0
            && pending.audit.some(row => row.message_language_key === 'COM_PRIVACY_ACTION_LOG_CREATED_REQUEST'
                && Number(row.user_id) === account.user_id));
        const mail = await tokenFor(email, before);
        const links = [...mail.mime.matchAll(/https?:[^\s<>"']+/g)].map(match =>
            new URL(match[0].replaceAll('&amp;', '&')));
        check('Actual registered Core mail keeps its native com_privacy confirmation route',
            links.some(url => url.origin === new URL(root).origin && url.searchParams.get('confirm_token') === mail.token
                && ((url.searchParams.get('option') === 'com_privacy' && url.searchParams.get('view') === 'confirm')
                    || url.pathname.endsWith('/component/privacy/confirm')))
            && !mail.mime.includes('plugin=privacyrequests')
            && !mail.mime.includes('plugin=j2commercePrivacy'));
        await page.goto(confirmUrl);
        await page.locator('#jform_confirm_token').fill('0123456789abcdef0123456789abcdef');
        await submit(page, '.request-confirm button[type="submit"]');
        check('Native registered wrong-token submission leaves the actual receipt pending',
            Number(fixture('snapshot', email).requests[0].status) === 0);
        await page.goto(confirmUrl);
        await page.locator('#jform_confirm_token').fill(mail.token);
        await submit(page, '.request-confirm button[type="submit"]');
        const confirmed = fixture('snapshot', email);
        check('Native registered confirmation consumes its delivered token without generic adoption',
            Number(confirmed.requests[0].status) === 1 && !confirmed.requests[0].confirm_token
            && confirmed.requests[0].requested_at === receipt.requested_at && confirmed.deliveries.length === 0
            && JSON.stringify(fixture('account-snapshot', account.user_id)) === JSON.stringify(originalAccount));
        await page.goto(confirmUrl);
        await page.locator('#jform_confirm_token').fill(mail.token);
        await submit(page, '.request-confirm button[type="submit"]');
        check('Native registered consumed-token replay cannot repeat confirmation',
            JSON.stringify(fixture('snapshot', email)) === JSON.stringify(confirmed));
        const admin = await browser.newPage();
        await admin.goto(`${root}/administrator/index.php`);
        await admin.locator('#mod-login-username').fill(process.env.JOOMLA_ADMIN_USERNAME);
        await admin.locator('#mod-login-password').fill(process.env.JOOMLA_ADMIN_PASSWORD);
        await submit(admin, 'button[type="submit"]');
        const reviewUrl = `${root}/administrator/index.php?option=com_privacy&view=request&id=${receipt.id}`;
        fixture('probe', 'block');
        await admin.goto(reviewUrl);
        await action(admin, 'request.remove');
        check('Ordinary native registered Remove still respects active legal blockers',
            !fixture('snapshot', email).events.some(row => row.stage === 'remove')
            && JSON.stringify(fixture('account-snapshot', account.user_id)) === JSON.stringify(originalAccount));
        fixture('probe', 'allow');
        await admin.goto(reviewUrl);
        await admin.locator('input[name="jform[privacy_remediation_reviewed]"]').check();
        await action(admin, 'request.remove');
        const removed = fixture('snapshot', email);
        const changedAccount = fixture('account-snapshot', account.user_id);
        check('Authorized native registered Remove dispatches callbacks and genuine Core user pseudonymisation',
            removed.events.some(row => row.stage === 'remove') && Number(removed.outcomes[0]?.reviewed_result) === 1
            && changedAccount.blocked === 1 && changedAccount.email_sha256 !== originalAccount.email_sha256
            && Number(removed.requests[0].status) === 1);
        await admin.goto(reviewUrl);
        await action(admin, 'request.complete');
        check('Registered removal does not silently waive the separate completion review',
            Number(fixture('snapshot', email).requests[0].status) === 1);
        await admin.goto(reviewUrl);
        await admin.locator('input[name="jform[privacy_scope_reviewed]"]').check();
        await action(admin, 'request.complete');
        check('Reviewed registered request completes only through the separate native Core operation',
            Number(fixture('snapshot', email).requests[0].status) === 2);
        const ordered = fixture('listener-order', `ordered-${new URL(root).port}-${Date.now()}@example.test`);
        check('Listener-order checks explicitly distinguish native plugin events from Core/browser coverage',
            ordered.coverage === 'native ordered plugin events, not a Core model/browser lifecycle');
        for (const label of ordered.checks) check(label, true);
    } finally {
        fixture('probe', 'block');
        await browser.close();
    }
}
main().catch(error => {console.error(error); process.exitCode = 1;});
