'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { Element, node, walk } = require('./support/elements.cjs');
const source = fs.readFileSync('public/assets/js/admission-offers.js', 'utf8');
const offer = { id: 'letter', title: '<img src=x>', message: 'Fictional private letter.\nPlease review.',
    status: 'pending', respond_by: null, require_acceptance: true };
const packet = () => ({ offer, application_status: 'accepted', response_open: true,
    policy: { require_acceptance: true, revision: 3 } });
function fixture(callback = async () => packet(), staff = false, options = {}) {
    const root = new Element('root'), calls = [], tasks = [], errors = [], notifications = [];
    const window = { confirm: () => false };
    vm.runInNewContext(source, { window }, { timeout: 500 });
    const guard = window.createAdmissionOffers({ node, request: async (...args) => { calls.push(args); return callback(...args); },
        task: run => { const pending = Promise.resolve().then(run).catch(error => errors.push(error)); tasks.push(pending); return pending; },
        notify: message => notifications.push(message) }).mount(root, 'application', { staff, canIssue: staff, canPolicy: staff, ...options });
    const find = predicate => walk(root).find(predicate);
    const flush = async () => { let count; do { count = tasks.length; await Promise.all(tasks); } while (count !== tasks.length); };
    const button = label => find(e => e.tag === 'button' && e.textContent === label);
    const click = async label => { assert.ok(button(label), `Missing ${label}`); button(label).events.click(); await flush(); };
    const submit = async () => { find(e => e.tag === 'form').events.submit({ preventDefault() {} }); await flush(); };
    return { root, guard, calls, tasks, errors, notifications, find, flush, click, submit, button, window };
}

test('offers load only when opened and private letter content remains text', async () => {
    const f = fixture();
    assert.equal(f.calls.length, 0);
    await f.click('Admission offer');
    assert.equal(f.calls[0][0], '/portal/api/applications/application/offer/');
    assert.match(f.root.textContent, /<img src=x>/);
    assert.equal(walk(f.root).some(e => e.tag === 'img'), false);
    assert.equal(f.button('Issue offer'), undefined);
    assert.equal(f.button('Offer policy'), undefined);
    assert.ok(f.button('Accept offer'));
});

test('applicant confirmation retries the same review and unfinished responses block navigation', async () => {
    let commits = 0;
    const f = fixture(async (url, method, body) => {
        if (url.endsWith('/review/')) {
            assert.equal(body.action, 'accept');
            assert.equal(Object.keys(body).length, 1);
            return { action: 'accept', offer: { ...offer, status: 'accepted' }, review_token: 'same-private-review' };
        }
        if (url.endsWith('/commit/')) {
            assert.equal(body.review_token, 'same-private-review');
            if (++commits === 1) throw new Error('Fictional interrupted response.');
            return {};
        }
        return packet();
    });
    await f.click('Admission offer'); await f.click('Accept offer'); await f.submit();
    assert.equal(f.guard.beforeLeave(), false);
    await f.click('Confirm'); assert.ok(f.button('Confirm')); assert.equal(f.errors.length, 1);
    await f.click('Confirm'); assert.equal(commits, 2); assert.equal(f.guard.beforeLeave(), true);
    assert.equal(f.notifications.length, 1);
});

test('staff policy saves typed choices with the reviewed revision', async () => {
    const f = fixture(async (url, method, body) => {
        if (method === 'POST') {
            assert.equal(url, '/sis/admission-offer-policy/');
            assert.equal(body.require_acceptance, false); assert.equal(body.revision, 3); return {};
        }
        return packet();
    }, true);
    await f.click('Admission offer'); await f.click('Offer policy');
    const checkbox = f.find(e => e.attributes.type === 'checkbox'); checkbox.checked = false; checkbox.events.change();
    assert.equal(f.guard.beforeLeave(), false);
    await f.submit(); assert.equal(f.errors.length, 0); assert.equal(f.guard.beforeLeave(), true);
    assert.match(f.root.textContent, /Earlier|Applicant|Offer/);
});

test('a response arriving after the application closes cannot recreate its confirmation', async () => {
    let resolve;
    const pending = new Promise(done => { resolve = done; });
    const f = fixture(async url => url.endsWith('/review/') ? pending : packet());
    await f.click('Admission offer'); await f.click('Decline offer');
    f.find(e => e.tag === 'form').events.submit({ preventDefault() {} });
    await Promise.resolve();
    assert.equal(f.guard.beforeLeave(), false);
    f.root.isConnected = false;
    resolve({ action: 'decline', offer: { ...offer, status: 'declined' }, review_token: 'late-review' });
    await f.flush(); assert.equal(f.button('Confirm'), undefined);
    assert.equal(f.calls.some(call => call[0].endsWith('/commit/')), false);
});


test('institutional policy setup opens without an application or letter request', async () => {
    const f = fixture(async (url, method, body) => {
        assert.equal(url, '/sis/admission-offer-policy/');
        if (method === 'POST') {
            assert.equal(body.require_acceptance, false);
            assert.equal(body.revision, 7);
        }
        return { require_acceptance: true, revision: 7 };
    }, true, { policyOnly: true });
    await f.flush();
    const checkbox = f.find(e => e.attributes.type === 'checkbox');
    assert.ok(checkbox); checkbox.checked = false; checkbox.events.change();
    await f.submit();
    assert.equal(f.errors.length, 0);
    assert.equal(f.calls.length, 3);
    assert.equal(f.button('Issue offer'), undefined);
});
