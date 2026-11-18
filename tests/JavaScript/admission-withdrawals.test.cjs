'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { Element, node, walk } = require('./support/elements.cjs');
const source = fs.readFileSync('public/assets/js/admission-withdrawals.js', 'utf8');
const packet = (mode = 'request') => ({ items: [], next: null, policy: { mode, revision: 0 }, application_status: 'submitted', has_pending: false, pending_id: null });
function fixture(callback = async () => packet(), options = {}) {
    const root = new Element('root'), calls = [], errors = [], tasks = [], window = { confirm: () => false };
    vm.runInNewContext(source, { window }, { timeout: 500 });
    const guard = window.createAdmissionWithdrawals({ node, notify() {},
        request: async (...args) => { calls.push(args); return callback(...args); },
        task: run => { const p = Promise.resolve().then(run).catch(e => errors.push(e)); tasks.push(p); return p; } }).mount(root, 'application', options);
    const flush = async () => { let n; do { n = tasks.length; await Promise.all(tasks); } while (n !== tasks.length); };
    const find = pred => walk(root).find(pred), button = label => find(e => e.tag === 'button' && e.textContent === label);
    const click = async label => { assert.ok(button(label), `Missing ${label}`); button(label).events.click(); await flush(); };
    const submit = async () => { find(e => e.tag === 'form').events.submit({ preventDefault() {} }); await flush(); };
    return { root, calls, errors, guard, flush, find, button, click, submit };
}
test('portal policy controls the owner action and loading stays on demand', async () => {
    for (const mode of ['request', 'direct', 'staff_only']) {
        const f = fixture(async () => packet(mode)); assert.equal(f.calls.length, 0);
        await f.click('Withdrawal');
        assert.equal(!!f.button('Request withdrawal'), mode === 'request');
        assert.equal(!!f.button('Withdraw application'), mode === 'direct');
        assert.equal(f.button('Approve withdrawal'), undefined);
        assert.equal(f.calls[0][0], '/portal/api/applications/application/withdrawal/');
    }
});
test('review preserves a private reason and retry uses its original confirmation token', async () => {
    let commits = 0, outcomes = 0;
    const f = fixture(async (url, method, body) => {
        if (url.endsWith('/review/')) { assert.equal(body.reason, '<img src=x> Fictional reason.'); return { action: 'request', reason: body.reason, review_token: 'same-private-review' }; }
        if (url.endsWith('/commit/')) { assert.equal(body.review_token, 'same-private-review'); if (++commits === 1) throw new Error('Fictional interrupted response.'); return { status: 'pending', application_status: 'submitted' }; }
        return packet();
    }, { onOutcome: () => { outcomes++; } });
    await f.click('Withdrawal'); await f.click('Request withdrawal');
    const reason = f.find(e => e.tag === 'textarea'); reason.value = '<img src=x> Fictional reason.'; reason.events.input();
    await f.submit(); assert.equal(f.guard.beforeLeave(), false); assert.equal(walk(f.root).some(e => e.tag === 'img'), false);
    await f.click('Revise'); assert.equal(reason.value, '<img src=x> Fictional reason.');
    await f.submit();
    await f.click('Confirm'); assert.equal(f.errors.length, 1); assert.ok(f.button('Confirm'));
    await f.click('Confirm'); assert.equal(outcomes, 1); assert.equal(f.guard.beforeLeave(), true);
    assert.equal(f.calls.filter(call => call[0].endsWith('/review/')).length, 2);
    assert.equal(f.calls.filter(call => call[0].endsWith('/commit/')).length, 2);
});
test('staff approval controls are independent from a read-only staff panel', async () => {
    const data = { ...packet(), has_pending: true, pending_id: 'request' };
    for (const canResolve of [true, false]) {
        const f = fixture(async () => data, { staff: true, canResolve, initiallyOpen: true }); await f.flush();
        assert.equal(!!f.button('Approve withdrawal'), canResolve); assert.equal(!!f.button('Close request'), canResolve);
        assert.equal(f.button('Cancel request'), undefined);
    }
});
test('another unfinished form blocks a new withdrawal action', async () => {
    const f = fixture(async () => packet(), { beforeChange: () => false });
    await f.click('Withdrawal'); await f.click('Request withdrawal');
    assert.equal(f.find(e => e.tag === 'form'), undefined);
    assert.equal(f.calls.length, 1);
});

test('staff queue pages metadata and opens the application at its withdrawal panel', async () => {
    const root = new Element('root'), calls = [], tasks = [], errors = [], window = {}, opened = [];
    vm.runInNewContext(source, { window }, { timeout: 500 });
    window.createAdmissionWithdrawals({ node, notify() {},
        request: async url => {
            calls.push(url);
            return { items: [{ id: 'request', application_id: 'application', given_name: '<img>', family_name: 'Fictional', application_status: 'submitted', reason: 'Not for this queue.' }], next: 'cursor' };
        }, task: run => { const p = Promise.resolve().then(run).catch(e => errors.push(e)); tasks.push(p); return p; }
    }).queue(root, row => opened.push(row));
    const flush = async () => { let n; do { n = tasks.length; await Promise.all(tasks); } while (n !== tasks.length); };
    await flush();
    assert.equal(root.children[0].textContent, 'Refresh requests');
    assert.equal(root.textContent.includes('Not for this queue.'), false);
    assert.equal(walk(root).some(e => e.tag === 'img'), false);
    walk(root).find(e => e.tag === 'button' && e.textContent === 'Review application').events.click(); await flush();
    assert.deepEqual(JSON.parse(JSON.stringify(opened)), [{ id: 'application', openWithdrawal: true }]);
    walk(root).find(e => e.tag === 'button' && e.textContent === 'Next requests').events.click(); await flush();
    assert.equal(calls[1], '/sis/admissions/withdrawal-requests/?after=cursor');
    assert.equal(errors.length, 0);
});
