/**
 * Actual native Core export lifecycle on the isolated synthetic site: a registered frontend
 * request and administrator-created requests without an account, genuine confirmation, the
 * native administrator export download and the native export mail. It checks that the
 * installed J2Commerce privacy plugin and the Appointments privacy plugin contribute their
 * domains, with the generic removal adapter both enabled and disabled.
 */
const fs = require('node:fs');
const path = require('node:path');
const {createHash} = require('node:crypto');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {root, mailDir, tokenFor, check} = require('./browser.cjs');
const {fixture, action} = require('./lifecycle.cjs');
const {legacyConfirm} = require('./legacy.cjs');

async function submit(page, selector) {
    await Promise.all([page.waitForNavigation(), page.locator(selector).click()]);
}

function domains(xml) {
    return new Set([...xml.matchAll(/<domain name="([^"]+)"/g)].map(match => match[1]));
}

async function exportXml(admin, id) {
    const response = await admin.request.get(
        `${root}/administrator/index.php?option=com_privacy&task=request.export&format=xml&id=${id}`);
    const body = await response.text();
    return {status: response.status(), xml: body.includes('<data-export') ? body : ''};
}

async function exportMail(email, before) {
    for (let attempt = 0; attempt < 50; attempt++) {
        for (const file of fs.readdirSync(mailDir).filter(f => !before.has(f))) {
            const mail = JSON.parse(fs.readFileSync(path.join(mailDir, file), 'utf8'));
            if (!mail.envelope.some(line => line.toLowerCase().includes(`<${email.toLowerCase()}>`))) continue;
            for (const block of mail.mime.split(/\r?\n--/)) {
                if (!/Content-Transfer-Encoding:\s*base64/i.test(block)) continue;
                const encoded = block.split(/\r?\n\r?\n/).slice(1).join('').replace(/\s+/g, '');
                const decoded = Buffer.from(encoded, 'base64').toString('utf8');
                if (decoded.includes('<data-export')) return decoded;
            }
        }
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    throw new Error('No actual captured export mail with an XML attachment');
}

async function adminExport(admin, label, email, id, expected, absent) {
    const review = `${root}/administrator/index.php?option=com_privacy&view=request&id=${id}`;
    const download = await exportXml(admin, id);
    const found = domains(download.xml);
    console.log('EXPORT_DOMAINS', JSON.stringify({label, domains: [...found].sort(),
        sha256: createHash('sha256').update(download.xml).digest('hex')}));
    check(`${label}: native administrator export contains ${expected.join(', ')}`,
        download.status === 200 && expected.every(name => found.has(name)) && download.xml.includes(email));
    const leaked = absent.filter(name => name.includes('@') ? download.xml.includes(name) : found.has(name));
    console.log('EXPORT_UNEXPECTED', JSON.stringify({label, leaked}));
    check(`${label}: export omits ${absent.join(', ') || 'nothing further'}`, leaked.length === 0);
    await admin.goto(review);
    const href = await admin.locator('a[href*="task=request.emailexport"]').first().getAttribute('href');
    const before = new Set(fs.readdirSync(mailDir));
    await admin.goto(new URL(href, `${root}/administrator/`).href);
    const mailed = domains(await exportMail(email, before));
    check(`${label}: native export mail to the request address carries the same domains`,
        expected.every(name => mailed.has(name)));
}

async function adminCreate(admin, email) {
    const before = new Set(fs.readdirSync(mailDir));
    await admin.goto(`${root}/administrator/index.php?option=com_privacy&task=request.add`);
    await admin.locator('#jform_email').fill(email);
    await admin.locator('#jform_request_type').selectOption('export');
    await action(admin, 'request.save');
    return tokenFor(email, before);
}

async function guestCase(browser, admin, label, email) {
    const primary = fixture('appointments-booking', email);
    const guest = fixture('appointments-booking', email, 'guest');
    fixture('acy-create', email);
    fixture('acy-restore');
    try {
        const mail = await adminCreate(admin, email);
        const pending = fixture('snapshot', email);
        const receipt = pending.requests.find(row => row.request_type === 'export');
        check(`${label}: administrator creates one pending native export request without an account`,
            pending.requests.length === 1 && Number(receipt.status) === 0 && pending.accounts === 0
            && pending.deliveries.length === 0);
        check(`${label}: an unconfirmed export request yields no export data`,
            (await exportXml(admin, receipt.id)).xml === '');
        const page = await browser.newPage();
        const result = await legacyConfirm(page, email, mail.token);
        await page.close();
        const confirmed = fixture('snapshot', email);
        check(`${label}: the delivered token confirms the export request without an account`,
            result.includes('Your request has been confirmed') && Number(confirmed.requests[0].status) === 1
            && !confirmed.requests[0].confirm_token && confirmed.accounts === 0);
        await adminExport(admin, label, email, receipt.id,
            ['appointments_bookings', 'appointments_guest_invitations', 'newsletter_subscriptions'],
            ['joomla_user', 'other@example.test', 'bystander@example.test']);
        console.log('GUEST_BOOKINGS', JSON.stringify({label, primary: primary.booking_id, guest: guest.booking_id}));
    } finally {
        fixture('acy-cleanup');
    }
}

async function main() {
    for (const file of ['export.cjs', 'legacy.cjs', 'lifecycle.cjs', 'browser.cjs', 'fixture.php']) {
        console.log('EXECUTION_BINDING', JSON.stringify({file,
            sha256: createHash('sha256').update(fs.readFileSync(path.join(__dirname, file))).digest('hex')}));
    }
    console.log('CORE_BINDINGS', JSON.stringify(fixture('bindings')));
    const binding = fixture('legacy-bindings');
    check('Installed J2Commerce privacy plugin is enabled for the native export',
        binding.extensions.some(row => row.folder === 'privacy' && Number(row.enabled) === 1));
    fixture('admission-reset');
    const stamp = `${new URL(root).port}-${Date.now()}`;
    const browser = await chromium.launch({channel: 'msedge', headless: true});
    try {
        const admin = await browser.newPage();
        await admin.goto(`${root}/administrator/index.php`);
        await admin.locator('#mod-login-username').fill(process.env.JOOMLA_ADMIN_USERNAME);
        await admin.locator('#mod-login-password').fill(process.env.JOOMLA_ADMIN_PASSWORD);
        await submit(admin, 'button[type="submit"]');

        const email = `export-registered-${stamp}@example.test`;
        const account = fixture('account-create', email);
        fixture('appointments-booking', email);
        fixture('acy-create', email);
        fixture('acy-restore');
        try {
            const page = await browser.newPage();
            await page.goto(`${root}/index.php?option=com_privacy&view=request`);
            await page.locator('#username').fill(account.username);
            await page.locator('#password').fill(process.env.JOOMLA_ADMIN_PASSWORD);
            await submit(page, '.login form button[type="submit"]');
            await page.goto(`${root}/index.php?option=com_privacy&view=request`);
            await page.locator('#jform_request_type').selectOption('export');
            const before = new Set(fs.readdirSync(mailDir));
            await submit(page, '.request-form button[type="submit"]');
            const mail = await tokenFor(email, before);
            const receipt = fixture('snapshot', email).requests.find(row => row.request_type === 'export');
            check('Registered frontend creates one pending native export request',
                Number(receipt?.status) === 0);
            await page.goto(`${root}/index.php?option=com_privacy&view=confirm`);
            await page.locator('#jform_confirm_token').fill(mail.token);
            await submit(page, '.request-confirm button[type="submit"]');
            await page.close();
            check('Registered native confirmation confirms the export request',
                Number(fixture('snapshot', email).requests[0].status) === 1);
            await adminExport(admin, 'Registered frontend export', email, receipt.id,
                ['joomla_user', 'joomla_action_logs', 'appointments_bookings', 'newsletter_subscriptions'], []);
        } finally {
            fixture('acy-cleanup');
        }

        check('Generic adapter is enabled for the first account-free export',
            Number(fixture('generic-id').enabled) === 1);
        await guestCase(browser, admin, 'Account-free export, adapter enabled', `export-guest-on-${stamp}@example.test`);
        fixture('generic-disable');
        try {
            check('Generic adapter is disabled for the second account-free export',
                Number(fixture('generic-id').enabled) === 0);
            await guestCase(browser, admin, 'Account-free export, adapter disabled', `export-guest-off-${stamp}@example.test`);
        } finally {
            fixture('generic-enable');
        }
        check('Generic adapter is enabled again after the export cases', Number(fixture('generic-id').enabled) === 1);
    } finally {
        await browser.close();
    }
}
main().catch(error => {console.error(error); process.exitCode = 1;});
