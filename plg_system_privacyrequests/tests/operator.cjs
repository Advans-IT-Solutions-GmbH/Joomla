/**
 * Actual public Core contact communication and authorized unresolved handling.
 * Neither another reply channel nor this contact confirms the original mailbox.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {createHash} = require('node:crypto');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {root, route, mailDir, check} = require('./browser.cjs');
const {fixture, action} = require('./lifecycle.cjs');

async function main() {
    for (const file of ['operator.cjs', 'lifecycle.cjs', 'browser.cjs', 'fixture.php']) {
        console.log('EXECUTION_BINDING', JSON.stringify({file,
            sha256: createHash('sha256').update(fs.readFileSync(path.join(__dirname, file))).digest('hex')}));
    }
    console.log('CORE_BINDINGS', JSON.stringify(fixture('bindings')));
    fixture('admission-reset');
    const stamp = Date.now();
    const contact = fixture('operator-contact-create', `operator-${stamp}@example.test`);
    check('Native configuration requires a concrete local contact route and rejects external/fragment/control paths',
        fixture('operator-contact-validation', contact.route).valid === true
        && ['', 'https://example.invalid/', '//example.invalid/', 'javascript:alert(1)',
            'index.php#contact', 'index.php?path=\\outside', 'index.php?line=\r\n'].every(value =>
            fixture('operator-contact-validation', value).valid === false));
    const browser = await chromium.launch({channel: 'msedge', headless: true});
    try {
        const page = await browser.newPage();
        await page.goto(route);
        const initialLanguage = fixture('operator-admin-language-get').admin_language;
        const expected = {
            'de-DE': 'Datenschutzanfrage: Bestätigungsversand fehlgeschlagen',
            'en-GB': 'Privacy request: confirmation delivery failed',
            'fr-FR': "Demande de confidentialité : échec de l'envoi de confirmation",
        };
        try {
            for (const [tag, subject] of Object.entries(expected)) {
                fixture('operator-admin-language-set', tag);
                const email = `operator-locale-ä-${stamp}-${tag.toLowerCase()}@example.test`;
                const csrf = /name="([a-f0-9]{32})" value="1"/.exec(await page.content())?.[1];
                assert(csrf);
                await page.request.post(route, {form: {email, operation: 'request', [csrf]: '1'}});
                const pending = fixture('snapshot', email);
                assert.equal(pending.requests.length, 1);
                const notification = fixture('operator-notification', pending.requests[0].id);
                check(`Native private administrator delivery warning uses recipient ${tag} without changing application language`,
                    notification.message.subject === subject
                    && notification.message.message.includes(String(pending.requests[0].id))
                    && Number(notification.message.user_id_to) === notification.id
                    && Number(notification.message.user_id_from) === notification.id
                    && Number(pending.deliveries[0].delivery_failed) === 1
                    && (await page.locator('h1').innerText()).includes('Request removal'));
            }
        } finally {
            fixture('operator-admin-language-set', initialLanguage);
        }
        const link = page.locator('[data-privacy-contact]');
        check('Public operator link is available without an account or submitting the email form',
            await link.count() === 1 && await link.isVisible()
            && new URL(await link.getAttribute('href')).origin === new URL(root).origin);
        const originalEmail = `operator-ä-${stamp}@example.test`;
        const html = await page.content();
        const csrf = /name="([a-f0-9]{32})" value="1"/.exec(html)?.[1];
        assert(csrf, 'A genuine public session token is required');
        // This is explicit HTTP submission, not a claim that the browser's native
        // type=email validation or unchanged Joomla mail supports EAI.
        await page.request.post(route, {form: {email: originalEmail, operation: 'request', [csrf]: '1'}});
        const pending = fixture('snapshot', originalEmail);
        assert.equal(pending.requests.length, 1);
        const receipt = pending.requests[0];
        check('Original Unicode native-recipient failure retains the actual pending receipt and delivery-failed record',
            Number(receipt.status) === 0 && pending.deliveries.length === 1
            && Number(pending.deliveries[0].delivery_failed) === 1);
        await page.locator('#privacy-email').fill(originalEmail);
        await Promise.all([page.waitForNavigation(), link.click()]);
        const form = page.locator('form#contact-form');
        check('Real visitor can reach the configured native public Contact form even with an unsubmitted original address',
            await form.count() === 1 && await form.isVisible()
            && Number.parseInt(await form.locator('input[name="id"]').getAttribute('value'), 10) === contact.id);
        const marker = `CONTACT_ASSISTANCE_ONLY_${stamp}`;
        await form.locator('#jform_contact_name').fill('Synthetic visitor requesting operator assistance');
        await form.locator('#jform_contact_email').fill(`reply-channel-only-${stamp}@example.test`);
        await form.locator('#jform_contact_emailmsg').fill(marker);
        await form.locator('#jform_contact_message').fill(
            `${marker}: original request ${receipt.id}; original address ${originalEmail}. `
            + 'This reply channel does not prove control of the original mailbox. No confirmation or erasure is authorized.');
        const before = new Set(fs.readdirSync(mailDir));
        await Promise.all([page.waitForNavigation(), form.locator('button[type="submit"]').click()]);
        let captured;
        let source;
        for (let attempt = 0; attempt < 50 && !captured; attempt++) {
            for (const file of fs.readdirSync(mailDir).filter(name => !before.has(name))) {
                const raw = fs.readFileSync(path.join(mailDir, file), 'utf8');
                const mail = JSON.parse(raw);
                if (!mail.envelope.some(line => line.toLowerCase().includes(`<${contact.recipient}>`))) continue;
                captured = {raw, mail};
                source = file;
                break;
            }
            if (!captured) await new Promise(resolve => setTimeout(resolve, 100));
        }
        assert(captured, 'Real native Contact submission must actually reach the captured operator mailbox');
        const mime = captured.mail.mime.replace(/=\r?\n/g, '');
        const decoded = /Content-Transfer-Encoding:\s*quoted-printable/i.test(mime)
            ? Buffer.from(mime.replace(/=([a-f0-9]{2})/ig, (_, hex) => String.fromCharCode(parseInt(hex, 16))),
                'latin1').toString('utf8') : mime;
        const mailHash = createHash('sha256').update(captured.raw).digest('hex');
        fs.writeFileSync(path.join(process.env.PRIVACY_TEST_REPORT_DIR, `operator-mail-${mailHash}.json`),
            captured.raw, {flag: 'wx'});
        console.log('OPERATOR_MAIL_BINDING', JSON.stringify({kind: 'native-contact-communication-only',
            source, sha256: mailHash, request_id: receipt.id, recipient: contact.recipient}));
        check('Native Contact really delivers the exact original address as support context without a substitute confirmation token',
            decoded.includes(marker) && decoded.includes(originalEmail) && !decoded.includes('confirm_token='));
        const admin = await browser.newPage();
        await admin.goto(`${root}/administrator/index.php`);
        await admin.locator('#mod-login-username').fill(process.env.JOOMLA_ADMIN_USERNAME);
        await admin.locator('#mod-login-password').fill(process.env.JOOMLA_ADMIN_PASSWORD);
        await Promise.all([admin.waitForNavigation(), admin.locator('button[type="submit"]').click()]);
        const native = `${root}/administrator/index.php?option=com_privacy&view=request&id=${receipt.id}`;
        await admin.goto(native);
        check('Genuine authorized operator sees actionable failure, local contact route and explicit unsupported manual-confirmation boundary',
            await admin.locator('[data-privacy-delivery="failed"]').count() === 1
            && (await admin.locator('[data-privacy-delivery="failed"]').innerText()).includes('no supported manual confirmation')
            && await admin.locator('[data-privacy-contact]').count() === 1);
        await action(admin, 'request.remove');
        const removed = fixture('snapshot', originalEmail);
        check('Actual authorized native premature Remove cannot advance the unconfirmed receipt or dispatch privacy removal callbacks',
            Number(removed.requests[0].status) === 0 && removed.events.length === 0
            && removed.requests[0].confirm_token === receipt.confirm_token);
        await admin.goto(native);
        await action(admin, 'request.complete');
        const unresolved = fixture('snapshot', originalEmail);
        check('Actual native Complete remains refused: contact does not change originalID/date/token, delivery failure or ownership',
            Number(unresolved.requests[0].id) === Number(receipt.id)
            && Number(unresolved.requests[0].status) === 0 && unresolved.requests[0].requested_at === receipt.requested_at
            && unresolved.requests[0].confirm_token === receipt.confirm_token
            && Number(unresolved.deliveries[0].delivery_failed) === 1 && unresolved.accounts === 0
            && unresolved.events.length === 0);
        console.log('UNRESOLVED_NATIVE_BOUNDARY', JSON.stringify({request_id: receipt.id, status: 0,
            delivery_failed: 1, contact_delivered: true, confirmed: false, removed: false, completed: false}));
    } finally {
        await browser.close();
    }
}
main().catch(error => {console.error(error); process.exitCode = 1;});
