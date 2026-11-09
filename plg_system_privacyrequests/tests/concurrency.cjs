/**
 * Installed-Core concurrency controls. The caller must provide a second, genuinely
 * separate PHP server for this same isolated site/database, not another browser tab.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {spawn} = require('node:child_process');
const {createHash} = require('node:crypto');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {root, route, mailDir, tokenFor, check} = require('./browser.cjs');
const {fixture, action} = require('./lifecycle.cjs');

async function post(context, server, email, operation, token = '') {
    const url = server + new URL(route).pathname + new URL(route).search;
    const html = await (await context.request.get(url)).text();
    const csrf = /name="([a-f0-9]{32})" value="1"/.exec(html)?.[1];
    assert(csrf, 'Real session-bound form token required');
    return (await context.request.post(url, {
        form: {email, operation, confirm_token: token, [csrf]: '1'},
    })).text();
}

async function hold(id) {
    const child = spawn(process.env.PRIVACY_PHP, [
        ...JSON.parse(process.env.PRIVACY_PHP_ARGS || '[]'), path.join(__dirname, 'fixture.php'),
        'operation-hold', String(id),
    ], {env: process.env, stdio: ['pipe', 'pipe', 'pipe']});
    let output = '';
    let errors = '';
    child.stderr.on('data', chunk => {errors += chunk;});
    const exited = new Promise(resolve => child.once('close', code => resolve(code)));
    await new Promise((resolve, reject) => {
        const timer = setTimeout(() => {
            child.stdin.end('\n');
            reject(new Error('Actual database lock was not acquired'));
        }, 15000);
        child.stdout.on('data', chunk => {
            output += chunk;
            if (output.includes('LOCK_HELD')) {clearTimeout(timer); resolve();}
        });
        child.once('error', error => {clearTimeout(timer); reject(error);});
        child.once('exit', code => {
            clearTimeout(timer);
            if (!output.includes('LOCK_HELD')) reject(new Error(`Lock process exited ${code}: ${errors}`));
        });
    });
    return async () => {
        child.stdin.end('\n');
        assert.equal(await exited, 0, 'Actual lock owner must release its connection cleanly');
    };
}

async function main() {
    for (const file of ['concurrency.cjs', 'lifecycle.cjs', 'browser.cjs', 'fixture.php']) {
        console.log('EXECUTION_BINDING', JSON.stringify({file,
            sha256: createHash('sha256').update(fs.readFileSync(path.join(__dirname, file))).digest('hex')}));
    }
    console.log('CORE_BINDINGS', JSON.stringify(fixture('bindings')));
    const worker = process.env.PRIVACY_CONCURRENT_URL;
    assert(worker && worker !== root, 'A separate PHP process endpoint is mandatory');
    fixture('admission-reset');
    const browser = await chromium.launch({channel: 'msedge', headless: true});
    try {
        const contexts = await Promise.all([browser.newContext(), browser.newContext()]);
        const servers = [root, worker];
        const email = `parallel-${Date.now()}@example.test`;
        const before = new Set(fs.readdirSync(mailDir));
        const responses = await Promise.all(contexts.map((context, i) => post(context, servers[i], email, 'request')));
        check('Two real PHP processes concurrently accept the same address without a public delivery claim',
            responses.every(html => html.includes('does not confirm receipt')));
        const created = fixture('snapshot', email);
        check('Concurrent create/renew retains exactly one pending receipt and one truthful creation audit',
            created.requests.length === 1 && Number(created.requests[0].status) === 0
            && created.audit.filter(row => row.message_language_key === 'COM_PRIVACY_ACTION_LOG_CREATED_REQUEST').length === 1);
        const receipt = created.requests[0];
        const selected = await tokenFor(email, before);
        // The first delivery may have been superseded. Gather each genuine captured
        // token and try the same set from both independent sessions/processes.
        const tokens = new Set([selected.token]);
        for (const file of fs.readdirSync(mailDir).filter(file => !before.has(file))) {
            const captured = JSON.parse(fs.readFileSync(path.join(mailDir, file), 'utf8'));
            if (!captured.envelope.some(line => line.toLowerCase().includes(`<${email}>`))) continue;
            const token = /confirm_token=([a-z0-9]{16,64})/i.exec(
                captured.mime.replace(/=\r?\n/g, '').replace(/=3D/g, '='))?.[1];
            if (token && !tokens.has(token)) {
                tokens.add(token);
                await tokenFor(email, new Set(fs.readdirSync(mailDir).filter(name => name !== file)));
            }
        }
        let confirmations = 0;
        for (const token of tokens) {
            const replies = await Promise.all(contexts.map((context, i) => post(context, servers[i], email, 'confirm', token)));
            confirmations += replies.filter(html => html.includes('No data has been removed')).length;
        }
        const confirmed = fixture('snapshot', email);
        check('Concurrent confirmation consumes the current token exactly once, including superseded deliveries',
            confirmations === 1 && Number(confirmed.requests[0].status) === 1
            && confirmed.requests[0].requested_at === receipt.requested_at
            && !confirmed.requests[0].confirm_token);

        const stamp = Date.now();
        const addresses = [`muller-${stamp}@example.test`, `müller-${stamp}@example.test`];
        const distinctBefore = new Set(fs.readdirSync(mailDir));
        const distinct = await Promise.all(addresses.map((address, i) => post(contexts[i], servers[i], address, 'request')));
        const rows = addresses.map(address => fixture('snapshot', address));
        check('Collation-equivalent but exact-distinct concurrent addresses create independent actual Core receipts',
            distinct.every(html => html.includes('does not confirm receipt'))
            && rows.every(state => state.requests.length === 1 && Number(state.requests[0].status) === 0 && state.accounts === 0)
            && rows[0].requests[0].id !== rows[1].requests[0].id);

        const admin = await browser.newPage();
        await admin.goto(`${root}/administrator/index.php`);
        await admin.locator('#mod-login-username').fill(process.env.JOOMLA_ADMIN_USERNAME);
        await admin.locator('#mod-login-password').fill(process.env.JOOMLA_ADMIN_PASSWORD);
        await Promise.all([admin.waitForNavigation(), admin.locator('button[type="submit"]').click()]);
        const asciiMail = await tokenFor(addresses[0], distinctBefore);
        const unicodeTransport = fixture('unicode-mail-core-check', addresses[1]);
        let unicodeMail;
        if (unicodeTransport.accepted) {
            unicodeMail = await tokenFor(addresses[1], distinctBefore);
            check('Unicode mailbox receives an actual captured native confirmation message',
                Number(fixture('snapshot', addresses[1]).deliveries[0]?.delivery_failed) === 0);
        } else {
            const failed = fixture('snapshot', addresses[1]);
            check('Native Unicode recipient rejection retains its real pending receipt and visible delivery failure',
                Number(failed.deliveries[0]?.delivery_failed) === 1
                && failed.requests[0].id === rows[1].requests[0].id
                && failed.requests[0].requested_at === rows[1].requests[0].requested_at
                && Number(failed.requests[0].status) === 0);
            await admin.goto(`${root}/administrator/index.php?option=com_privacy&view=request&id=${failed.requests[0].id}`);
            check('Authorized native request view exposes the Unicode native-mail failure and actionable original receipt',
                (await admin.locator('body').innerText()).includes('Confirmation delivery failed.')
                && (await admin.locator('body').innerText()).includes('Do not bypass confirmation.'));
            console.log('OPEN_CORE_LIMITATION', JSON.stringify({requirement: 'Unicode local-part mail and positive confirmation',
                ...unicodeTransport}));
            // A native transport limit must not turn a partial acceptance run green.
            process.exitCode = 2;
        }
        const asciiAccount = fixture('account-create', addresses[0]);
        const originalAsciiAccount = fixture('account-snapshot', asciiAccount.user_id);
        const wrong = await post(contexts[1], servers[1], addresses[1], 'confirm', asciiMail.token);
        check('Accent-equivalent address cannot consume another exact address token',
            wrong.includes('could not be confirmed')
            && Number(fixture('snapshot', addresses[1]).requests[0].status) === 0
            && JSON.stringify(fixture('account-snapshot', asciiAccount.user_id)) === JSON.stringify(originalAsciiAccount));
        await admin.goto(`${root}/administrator/index.php?option=com_privacy&task=request.add`);
        await admin.locator('#jform_email').fill(addresses[0]);
        await admin.locator('#jform_request_type').selectOption('export');
        await action(admin, 'request.save');
        const exportRow = fixture('snapshot', addresses[0]).requests.find(row => row.request_type === 'export');
        check('Native Core creates a separate pending export alongside removal', exportRow && Number(exportRow.status) === 0);
        const confirmAddresses = unicodeMail ? addresses : [addresses[0]];
        const confirmMails = unicodeMail ? [asciiMail, unicodeMail] : [asciiMail];
        const exactConfirmations = await Promise.all(confirmAddresses.map((address, i) =>
            post(contexts[i], servers[i], address, 'confirm', confirmMails[i].token)));
        check('Pinned native confirmation selects the exact remove row without confirming the export',
            exactConfirmations.every(html => html.includes('No data has been removed'))
            && confirmAddresses.every(address => fixture('snapshot', address).requests
                .some(row => row.request_type === 'remove' && Number(row.status) === 1))
            && (unicodeMail || Number(fixture('snapshot', addresses[1]).requests[0].status) === 0)
            && JSON.stringify(fixture('snapshot', addresses[0]).requests.find(row => row.id === exportRow.id)) === JSON.stringify(exportRow));
        const requestUrl = `${root}/administrator/index.php?option=com_privacy&view=request&id=${receipt.id}`;
        const release = await hold(receipt.id);
        try {
            for (const task of ['request.remove', 'request.complete']) {
                await admin.goto(requestUrl);
                await admin.locator('input[name="jform[privacy_scope_reviewed]"]').check();
                const original = fixture('snapshot', email);
                await action(admin, task);
                check(`Actual held database operation lock refuses ${task} before any callback/audit/status mutation`,
                    JSON.stringify(fixture('snapshot', email)) === JSON.stringify(original)
                    && (await admin.locator('body').innerText()).includes('privacy operation'));
            }
        } finally {
            await release();
        }
        fixture('probe', 'allow');
        await admin.goto(requestUrl);
        await action(admin, 'request.remove');
        check('Released real operation lock admits native Remove again',
            Number(fixture('snapshot', email).outcomes[0]?.reviewed_result) === 1);
        if (!unicodeMail) {
            console.log('OPEN_CORE_LIMITATION', JSON.stringify({
                requirement: 'Positive Unicode confirmation before genuine account-mismatch native Remove',
                reason: 'No delivered native Unicode token exists; no synthetic confirmation status is substituted.',
            }));
            return;
        }
        fixture('admission-reset');
        const accountEmail = `muller-account-${Date.now()}@example.test`;
        const otherAddress = accountEmail.replace('muller-', 'müller-');
        const account = fixture('account-create', accountEmail);
        const originalAccount = fixture('account-snapshot', account.user_id);
        const accountMailBefore = new Set(fs.readdirSync(mailDir));
        await post(contexts[0], root, otherAddress, 'request');
        const otherMail = await tokenFor(otherAddress, accountMailBefore);
        await post(contexts[0], root, otherAddress, 'confirm', otherMail.token);
        const mismatch = fixture('snapshot', otherAddress).requests[0];
        await admin.goto(`${root}/administrator/index.php?option=com_privacy&view=request&id=${mismatch.id}`);
        await action(admin, 'request.remove');
        const blocked = fixture('snapshot', otherAddress);
        check('Native collation account mismatch blocks all removal and preserves the other real account',
            Number(mismatch.status) === 1 && Number(blocked.outcomes[0]?.reviewed_result) === 0
            && !blocked.events.some(row => row.stage === 'remove')
            && JSON.stringify(fixture('account-snapshot', account.user_id)) === JSON.stringify(originalAccount));
    } finally {
        fixture('probe', 'block');
        await browser.close();
    }
}
main().catch(error => {console.error(error); process.exitCode = 1;});
