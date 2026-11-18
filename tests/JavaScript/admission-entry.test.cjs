'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { Element, node, walk } = require('./support/elements.cjs');
const source = fs.readFileSync('public/assets/js/admission-entry.js', 'utf8');
const term = (id, code) => ({ id, code, name: 'Fictional fall', starts_on: '2027-08-15', ends_on: '2027-12-15', year_code: '2027-2028' });
const person = { given_name: '<img>', family_name: 'Fictional', institutional_id: 'F-123' };
const current = () => ({ person, current: term('old', '26/FA'), approved: null, application_status: 'accepted', withdrawal_pending: false, needs_review: false });
function fixture(callback, options = {}) {
    const root = new Element('root'), calls = [], errors = [], tasks = [], window = { confirm: () => false };
    vm.runInNewContext(source, { window, URLSearchParams }, { timeout: 500 });
    const guard = window.createAdmissionEntry({ node, notify() {},
        request: async (...args) => { calls.push(args); return callback(...args); },
        task: run => { const p = Promise.resolve().then(run).catch(e => errors.push(e)); tasks.push(p); return p; }
    }).mount(root, 'application', options);
    const flush = async () => { let n; do { n = tasks.length; await Promise.all(tasks); } while (n !== tasks.length); };
    const find = pred => walk(root).find(pred), button = label => find(e => e.tag === 'button' && e.textContent === label);
    const click = async label => { assert.ok(button(label), `Missing ${label}`); button(label).events.click(); await flush(); };
    const submit = async () => { find(e => e.tag === 'form').events.submit({ preventDefault() {} }); await flush(); };
    return { root, calls, errors, guard, flush, find, button, click, submit };
}
test('intake lookup stays on demand and offers labeled choices with bounded pagination', async () => {
    const f = fixture(async url => url.includes('terms/?') ? { items: [term('new', '27/FA')], next: 'cursor' } : current());
    await f.flush(); assert.equal(f.calls.length, 1); assert.equal(f.guard.beforeLeave(), true);
    await f.click('Choose later intake');
    assert.equal(f.calls[1][0], '/sis/applications/application/entry/terms/?search=');
    const radio = f.find(e => e.tag === 'input' && e.attributes.type === 'radio');
    assert.equal(radio.attributes.value, 'new'); assert.equal(walk(f.root).some(e => e.tag === 'img'), false);
    const search = f.find(e => e.attributes.type === 'search'); search.value = '27/FA';
    await f.click('Find terms'); assert.ok(f.calls[2][0].endsWith('search=27%2FFA'));
    await f.click('More terms'); assert.ok(f.calls[3][0].endsWith('search=27%2FFA&after=cursor'));
    assert.ok(f.button('First results')); assert.equal(f.errors.length, 0);
});
test('review freezes fields, preserves choices and reason on revise, and retries the original token', async () => {
    let commits = 0, completed = 0;
    const f = fixture(async (url, method, body) => {
        if (url.includes('terms/?')) return { items: [term('new', '27/FA')], next: null };
        if (url.endsWith('/review/')) {
            assert.equal(body.reason, '<img> Fictional reason.'); assert.equal(body.target_term, 'new'); assert.equal(body.kind, 'defer');
            return { person, before: term('old', '26/FA'), after: term('new', '27/FA'), review_token: 'original-private-review' };
        }
        if (url.endsWith('/commit/')) {
            assert.equal(body.review_token, 'original-private-review');
            if (++commits === 1) throw new Error('Fictional interruption.'); return { kind: 'defer' };
        }
        return current();
    }, { onComplete: () => { completed++; } });
    await f.flush(); await f.click('Choose later intake');
    f.find(e => e.attributes.type === 'radio').events.change();
    const reason = f.find(e => e.tag === 'textarea'); reason.value = '<img> Fictional reason.'; reason.events.input();
    await f.submit(); assert.equal(f.guard.beforeLeave(), false);
    assert.equal(f.find(e => e.tag === 'fieldset').disabled, true);
    await f.click('Revise'); assert.equal(reason.value, '<img> Fictional reason.'); assert.equal(reason.focused, true);
    await f.submit(); await f.click('Approve intake'); assert.equal(f.errors.length, 1);
    await f.click('Approve intake'); assert.equal(completed, 1); assert.equal(f.guard.beforeLeave(), true);
    assert.equal(walk(f.root).some(e => e.tag === 'img'), false);
    assert.equal(f.calls.filter(c => c[0].endsWith('/review/')).length, 2);
});
test('changed approved calendar uses reconfirmation without searching and pending withdrawal disables approval', async () => {
    const f = fixture(async (url, method, body) => {
        if (url.endsWith('/review/')) { assert.equal(body.kind, 'reconfirm'); assert.equal(body.target_term, 'old'); return { person, before: term('old', '26/FA'), after: term('old', '26/FA'), review_token: 'review' }; }
        return { ...current(), approved: term('old', '26/FA'), needs_review: true };
    });
    await f.flush(); await f.click('Review current intake');
    assert.equal(f.find(e => e.attributes.type === 'search'), undefined);
    f.find(e => e.tag === 'textarea').value = 'Fictional changed-calendar approval.';
    await f.submit(); assert.ok(f.button('Approve intake'));
    const pending = fixture(async () => ({ ...current(), withdrawal_pending: true })); await pending.flush();
    assert.equal(pending.button('Choose later intake'), undefined);
    assert.equal(pending.root.textContent.includes('Resolve the pending withdrawal request'), true);
});
test('detached late responses cannot mount a review into another application', async () => {
    let resolve;
    const f = fixture(async url => {
        if (url.includes('terms/?')) return { items: [term('new', '27/FA')], next: null };
        if (url.endsWith('/review/')) return new Promise(done => { resolve = done; });
        return current();
    });
    await f.flush(); await f.click('Choose later intake'); f.find(e => e.attributes.type === 'radio').events.change();
    f.find(e => e.tag === 'textarea').value = 'Fictional reason.';
    f.find(e => e.tag === 'form').events.submit({ preventDefault() {} });
    await new Promise(done => setImmediate(done)); assert.equal(f.guard.beforeLeave(), false);
    f.root.isConnected = false; resolve({ person, before: term('old', '26/FA'), after: term('new', '27/FA'), review_token: 'private' });
    await f.flush(); assert.equal(f.button('Approve intake'), undefined); assert.equal(f.errors.length, 0);
});
