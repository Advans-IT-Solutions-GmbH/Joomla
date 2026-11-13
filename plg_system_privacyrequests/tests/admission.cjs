/**
 * Actual HTTP contention on two independent PHP processes and persistent buckets.
 * The session token is exercised separately; it is not a third admission bucket.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {createHash} = require('node:crypto');
const {request} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {root, route, mailDir, tokenFor, check} = require('./browser.cjs');
const {fixture} = require('./lifecycle.cjs');

async function post(context, server, email, operation, token = '') {
    const url = server + new URL(route).pathname + new URL(route).search;
    const page = await context.get(url);
    const csrf = /name="([a-f0-9]{32})" value="1"/.exec(await page.text())?.[1];
    assert(csrf, 'Each independent actor needs its own genuine session CSRF');
    const response = await context.post(url, {
        form: {email, operation, confirm_token: token, [csrf]: '1'},
    });
    assert.equal(response.status(), 200);
    return response.text();
}

function deliveries(before, recipients) {
    const mails = [];
    for (const file of fs.readdirSync(mailDir).filter(name => !before.has(name))) {
        const mail = JSON.parse(fs.readFileSync(path.join(mailDir, file), 'utf8'));
        const recipient = recipients.find(email => mail.envelope.some(line =>
            line.toLowerCase().includes(`<${email}>`)));
        if (!recipient) continue;
        const token = /confirm_token=([a-z0-9]{16,64})/i.exec(
            mail.mime.replace(/=\r?\n/g, '').replace(/=3D/g, '='))?.[1];
        assert(token, 'Count only genuine native confirmation deliveries');
        mails.push({file, recipient});
    }
    return mails;
}

async function main() {
    for (const file of ['admission.cjs', 'lifecycle.cjs', 'browser.cjs', 'fixture.php']) {
        console.log('EXECUTION_BINDING', JSON.stringify({file,
            sha256: createHash('sha256').update(fs.readFileSync(path.join(__dirname, file))).digest('hex')}));
    }
    console.log('CORE_BINDINGS', JSON.stringify(fixture('bindings')));
    const worker = process.env.PRIVACY_CONCURRENT_URL;
    assert(worker && worker !== root, 'Two independently started PHP processes are mandatory');
    const actors = await Promise.all(Array.from({length: 6}, () => request.newContext()));
    const servers = [root, worker];
    const stamp = Date.now();
    try {
        fixture('admission-reset');
        const email = `address-limit-${stamp}@example.test`;
        const before = new Set(fs.readdirSync(mailDir));
        const responses = await Promise.all(actors.map((actor, i) =>
            post(actor, servers[i % 2], email, 'request')));
        const state = fixture('admission-state', email, 'request');
        const receipt = fixture('snapshot', email);
        const delivered = deliveries(before, [email]);
        check('Six independent sessions competing on two PHP processes admit exactly five address deliveries',
            delivered.length === 5 && Number(state.email.attempts) === 6 && Number(state.ip.attempts) === 6);
        check('Address denial retains the independently committed sixth client charge',
            Number(state.ip.attempts) === 6 && state.total_rows === 2);
        check('Allowed renewal and denied contention keep one genuine pending receipt and one creation audit',
            receipt.requests.length === 1 && Number(receipt.requests[0].status) === 0
            && receipt.audit.filter(row => row.message_language_key === 'COM_PRIVACY_ACTION_LOG_CREATED_REQUEST').length === 1);
        check('All six public request responses remain neutral without asserting delivery or receipt',
            responses.every(html => html.includes('does not confirm receipt')));
        const confirmation = await tokenFor(email, before);
        const confirmed = await post(actors[0], root, email, 'confirm', confirmation.token);
        // Earlier deliveries can be superseded, so do not assume the first captured
        // code is current: this assertion only observes the operation's own bucket.
        const confirmCounters = fixture('admission-state', email, 'confirm');
        check('Confirmation admission has its own persistent client counter and no address bucket',
            confirmCounters.email === null && Number(confirmCounters.ip.attempts) === 1
            && Number(fixture('admission-state', email, 'request').email.attempts) === 6
            && (confirmed.includes('could not be confirmed') || confirmed.includes('No data has been removed')));

        fixture('admission-reset');
        const clients = Array.from({length: 21}, (_, i) => `client-limit-${stamp}-${i}@example.test`);
        const clientBefore = new Set(fs.readdirSync(mailDir));
        for (let i = 0; i < 19; i++) {
            const html = await post(actors[i % actors.length], servers[i % 2], clients[i], 'request');
            assert(html.includes('does not confirm receipt'));
        }
        const contested = await Promise.all([19, 20].map(i =>
            post(actors[i % actors.length], servers[i % 2], clients[i], 'request')));
        const states = clients.map(address => fixture('admission-state', address, 'request'));
        const snapshots = clients.map(address => fixture('snapshot', address));
        const denied = snapshots.findIndex(snapshot => snapshot.requests.length === 0);
        check('Nineteen actual client admissions plus two competing workers admit exactly twenty deliveries',
            deliveries(clientBefore, clients).length === 20
            && snapshots.filter(snapshot => snapshot.requests.length === 1).length === 20
            && denied >= 19 && Number(states[0].ip.attempts) === 21);
        check('Client refusal allocates no fresh address bucket and no fake Core receipt or audit',
            states[denied].email === null && states[denied].total_rows === 21
            && snapshots[denied].requests.length === 0 && states[denied].matching_creation_audit === 0);
        check('Every admitted distinct address is charged once and denial has the same neutral response',
            states.every((state, i) => i === denied || Number(state.email.attempts) === 1)
            && contested.every(html => html.includes('does not confirm receipt')));
        for (let i = 0; i < 19; i++) {
            const html = await post(actors[i % actors.length], servers[i % 2], clients[i], 'confirm', 'not-the-issued-code');
            assert(html.includes('could not be confirmed'));
        }
        const confirmContested = await Promise.all([19, 20].map(i =>
            post(actors[i % actors.length], servers[i % 2], clients[i], 'confirm', 'not-the-issued-code')));
        const confirmStates = clients.map(address => fixture('admission-state', address, 'confirm'));
        check('Confirmation separately enforces twenty client admissions under actual two-worker contention',
            Number(confirmStates[0].ip.attempts) === 21
            && confirmStates.every(state => state.email === null && state.total_rows === 22)
            && confirmContested.every(html => html.includes('could not be confirmed')));
        const genuine = await tokenFor(clients[0], clientBefore);
        const ipRefusedCurrent = await post(actors[0], root, clients[0], 'confirm', genuine.token);
        const retained = clients.map(address => fixture('snapshot', address));
        check('Client-limit refusal rejects a genuinely delivered current token without changing receipt, token or date',
            ipRefusedCurrent.includes('could not be confirmed')
            && fixture('admission-state', clients[0], 'confirm').email === null
            && retained.every((snapshot, i) => JSON.stringify(snapshot.requests) === JSON.stringify(snapshots[i].requests)));

        fixture('admission-reset');
        const confirmEmail = `confirm-limit-${stamp}@example.test`;
        const confirmBefore = new Set(fs.readdirSync(mailDir));
        await post(actors[0], root, confirmEmail, 'request');
        const actual = await tokenFor(confirmEmail, confirmBefore);
        const original = fixture('snapshot', confirmEmail).requests[0];
        const invalid = await Promise.all(actors.map((actor, i) =>
            post(actor, servers[i % 2], confirmEmail, 'confirm', 'not-the-issued-code')));
        const acceptedCurrent = await post(actors[0], root, confirmEmail, 'confirm', actual.token);
        const remaining = fixture('snapshot', confirmEmail).requests[0];
        const confirmState = fixture('admission-state', confirmEmail, 'confirm');
        check('Competing wrong codes of other sessions cannot lock out the genuine current code',
            invalid.every(html => html.includes('could not be confirmed'))
            && acceptedCurrent.includes('No data has been removed') && confirmState.email === null
            && Number(confirmState.ip.attempts) === 7 && Number(remaining.status) === 1
            && !remaining.confirm_token && remaining.requested_at === original.requested_at);
        const boundary = fixture('admission-boundary', `window-${stamp}@example.test`, 'request');
        check('Real persistent buckets refuse at899 seconds and reset at the exact900-second boundary',
            boundary.initial.join(',') === 'true,true,true,true,true,false'
            && boundary.before_boundary === false && boundary.at_boundary === true
            && Number(boundary.email.attempts) === 1 && Number(boundary.ip.attempts) === 1);
        console.log('ACTUAL_HTTP_WORKERS', JSON.stringify({servers, independent_sessions: actors.length}));
    } finally {
        await Promise.all(actors.map(actor => actor.dispose()));
    }
}
main().catch(error => {console.error(error); process.exitCode = 1;});
