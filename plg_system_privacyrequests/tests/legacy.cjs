/**
 * Normally installed J2Commerce guest-confirmation coexistence and native Acy callback.
 * No shop is needed for either the existing confirmation adapter or newsletter lookup.
 */
const fs = require('node:fs');
const path = require('node:path');
const {createHash} = require('node:crypto');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {root, route, mailDir, tokenFor, submit, check} = require('./browser.cjs');
const {fixture, create, action} = require('./lifecycle.cjs');

async function legacyConfirm(page, email, token) {
    await page.goto(`${root}/index.php?option=com_ajax&plugin=j2commercePrivacy&group=privacy&format=html&task=confirmRequest`);
    await page.locator('#j2commerce-privacy-confirm-email').fill(email);
    await page.locator('#j2commerce-privacy-confirm-token').fill(token);
    await Promise.all([page.waitForNavigation(),
        page.locator('form:has(#j2commerce-privacy-confirm-email) button[type="submit"]').click()]);
    return page.locator('body').innerText();
}

async function main() {
    for (const file of ['legacy.cjs', 'lifecycle.cjs', 'browser.cjs', 'fixture.php']) {
        console.log('EXECUTION_BINDING', JSON.stringify({file,
            sha256: createHash('sha256').update(fs.readFileSync(path.join(__dirname, file))).digest('hex')}));
    }
    console.log('CORE_BINDINGS', JSON.stringify(fixture('bindings')));
    const binding = fixture('legacy-bindings');
    console.log('LEGACY_BINDINGS', JSON.stringify(binding));
    check('Actual legacy privacy and mail-hook plugins are enabled with stored guest default absent and jobs disabled',
        binding.extensions.some(row => row.folder === 'privacy' && Number(row.enabled) === 1
            && !Object.hasOwn(JSON.parse(row.params), 'guest_confirmation'))
        && binding.extensions.some(row => row.folder === 'system' && Number(row.enabled) === 1)
        && binding.extensions.some(row => row.folder === 'task' && Number(row.enabled) === 0)
        && binding.active_privacy_tasks === 0);
    fixture('admission-reset');
    const browser = await chromium.launch({channel: 'msedge', headless: true});
    try {
        const page = await browser.newPage();
        const stamp = `${new URL(root).port}-${Date.now()}`;
        const managedEmail = `managed-legacy-${stamp}@example.test`;
        const managedMail = await create(page, managedEmail);
        check('Enabled real legacy mail hook leaves generic-owned Core mail on its own supported route',
            managedMail.mime.includes('plugin=privacyrequests')
            && !managedMail.mime.includes('plugin=j2commercePrivacy'));
        const admin = await browser.newPage();
        await admin.goto(`${root}/administrator/index.php`);
        await admin.locator('#mod-login-username').fill(process.env.JOOMLA_ADMIN_USERNAME);
        await admin.locator('#mod-login-password').fill(process.env.JOOMLA_ADMIN_PASSWORD);
        await Promise.all([admin.waitForNavigation(), admin.locator('button[type="submit"]').click()]);
        const email = `native-legacy-${stamp}@example.test`;
        const before = new Set(fs.readdirSync(mailDir));
        await admin.goto(`${root}/administrator/index.php?option=com_privacy&task=request.add`);
        await admin.locator('#jform_email').fill(email);
        await admin.locator('#jform_request_type').selectOption('remove');
        await action(admin, 'request.save');
        const oldMail = await tokenFor(email, before);
        const pending = fixture('snapshot', email);
        const receipt = pending.requests[0];
        check('Actual admin-created native mail uses the installed legacy default guest-confirmation route',
            Number(receipt.status) === 0 && pending.deliveries.length === 0
            && oldMail.mime.includes('plugin=j2commercePrivacy') && !oldMail.mime.includes('plugin=privacyrequests'));
        await page.goto(route);
        await submit(page, email, 'request');
        check('Generic self-submission neither adopts nor renews an unmanaged native legacy receipt',
            JSON.stringify(fixture('snapshot', email)) === JSON.stringify(pending));
        await submit(page, email, 'confirm', oldMail.token);
        check('Generic confirmation does not take ownership of an unmanaged legacy receipt',
            JSON.stringify(fixture('snapshot', email)) === JSON.stringify(pending));
        const renewedBefore = new Set(fs.readdirSync(mailDir));
        check('Public native administrator model performs real pending renewal', fixture('native-renew', receipt.id).renewed);
        const newMail = await tokenFor(email, renewedBefore);
        const renewed = fixture('snapshot', email);
        check('Native renewal keeps legacy route and original receipt while replacing its delivered token',
            newMail.token !== oldMail.token && newMail.mime.includes('plugin=j2commercePrivacy')
            && renewed.requests[0].id === receipt.id && renewed.requests[0].requested_at === receipt.requested_at);
        const oldResult = await legacyConfirm(page, email, oldMail.token);
        check('Legacy page rejects the revoked previous native token',
            !oldResult.includes('Your request has been confirmed')
            && Number(fixture('snapshot', email).requests[0].status) === 0);
        const confirmedResult = await legacyConfirm(page, email, newMail.token);
        const confirmed = fixture('snapshot', email);
        check('Actual guest confirms the current native token through the existing legacy adapter without an account',
            confirmedResult.includes('Your request has been confirmed') && confirmed.accounts === 0
            && Number(confirmed.requests[0].status) === 1 && !confirmed.requests[0].confirm_token
            && confirmed.deliveries.length === 0);
        await legacyConfirm(page, email, newMail.token);
        check('Consumed current legacy token replay leaves the exact Core request and audit unchanged',
            JSON.stringify(fixture('snapshot', email)) === JSON.stringify(confirmed));
        await page.goto(route);
        const managedResult = await submit(page, managedEmail, 'confirm', managedMail.token);
        check('Managed generic confirmation still uses native Core with the real legacy plugins active',
            managedResult.includes('No data has been removed'));

        const reviewUrl = `${root}/administrator/index.php?option=com_privacy&view=request&id=${receipt.id}`;
        fixture('probe', 'allow');
        const unavailable = fixture('acy-create', email);
        check('Actual synthetic subscriber survives in an unavailable lookup table',
            unavailable.unavailable_rows === 1 && unavailable.active_rows === null);
        await admin.goto(reviewUrl);
        await action(admin, 'request.remove');
        console.log('NATIVE_ACY_MESSAGES', JSON.stringify(await admin.locator('#system-message-container').innerText()));
        const failed = fixture('snapshot', email);
        check('Existing installed Acy callback propagates lookup failure visibly and refuses a safe Core outcome',
            (await admin.locator('body').innerText()).includes('lookup')
            && Number(failed.outcomes[0]?.reviewed_result) === 0
            && fixture('acy-state').unavailable_rows === 1 && Number(failed.requests[0].status) === 1);
        await admin.goto(reviewUrl);
        await admin.locator('input[name="jform[privacy_scope_reviewed]"]').check();
        await action(admin, 'request.complete');
        check('Native Complete cannot turn a failed Acy lookup into successful erasure',
            Number(fixture('snapshot', email).requests[0].status) === 1 && fixture('acy-state').unavailable_rows === 1);
        fixture('acy-restore');
        await admin.goto(reviewUrl);
        await action(admin, 'request.remove');
        check('Restoring the actual subscriber lookup permits native retry and real subscriber deletion',
            fixture('acy-state').active_rows === 0
            && Number(fixture('snapshot', email).outcomes[0]?.reviewed_result) === 1);
        await admin.goto(reviewUrl);
        await admin.locator('input[name="jform[privacy_scope_reviewed]"]').check();
        await admin.locator('input[name="jform[privacy_remediation_reviewed]"]').check();
        await action(admin, 'request.complete');
        check('Only actual Acy repair and a separate reviewed Core Complete close the legacy request',
            Number(fixture('snapshot', email).requests[0].status) === 2);
    } finally {
        fixture('acy-cleanup');
        fixture('probe', 'block');
        await browser.close();
    }
}
main().catch(error => {console.error(error); process.exitCode = 1;});
