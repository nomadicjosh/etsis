'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { Element, node, walk } = require('./support/elements.cjs');
const source = fs.readFileSync('public/assets/js/admission-policy.js', 'utf8');
function fixture() {
    const root = new Element('root'), calls = [], errors = [], tasks = [], window = { confirm: () => false };
    vm.runInNewContext(source, { window }, { timeout: 500 });
    const guard = window.createAdmissionPolicy({ node, notify() {},
        request: async (url, method, body) => {
            calls.push({ url, method, body });
            if (method === 'POST') return body.area === 'offers' ? { require_acceptance: body.require_acceptance, revision: 4 } : body.area === 'checklist' ? { required: body.required, revision: 1 } : { mode: body.mode, revision: 8 };
            return { offers: { require_acceptance: true, revision: 3 }, withdrawals: { mode: 'request', revision: 7 }, answers: { mode: 'request', revision: 0 }, checklist: { required: false, revision: 0 } };
        }, task: run => { const p = Promise.resolve().then(run).catch(e => errors.push(e)); tasks.push(p); return p; } }).mount(root);
    const flush = async () => { let n; do { n = tasks.length; await Promise.all(tasks); } while (n !== tasks.length); };
    return { root, guard, calls, errors, flush };
}
test('one setup request loads all independent policies without an application', async () => {
    const f = fixture(); await f.flush();
    assert.equal(f.calls.length, 1); assert.equal(f.calls[0].url, '/sis/admission-policy/');
    assert.match(f.root.textContent, /Staff approval required/);
    assert.match(f.root.textContent, /Applicants may withdraw directly/);
    assert.equal(walk(f.root).filter(e => e.tag === 'select').length, 2);
});
test('saving one policy preserves the other policy choice and its unsaved guard', async () => {
    const f = fixture(); await f.flush();
    const checkbox = walk(f.root).find(e => e.attributes.type === 'checkbox'), select = walk(f.root).find(e => e.tag === 'select');
    const forms = walk(f.root).filter(e => e.tag === 'form');
    checkbox.checked = false; checkbox.events.change(); select.value = 'direct'; select.events.change();
    forms[0].events.submit({ preventDefault() {} }); await f.flush();
    assert.equal(f.calls[1].body.area, 'offers'); assert.equal(f.calls[1].body.require_acceptance, false);
    assert.equal(f.calls[1].body.revision, 3); assert.equal(select.value, 'direct');
    assert.equal(f.guard.beforeLeave(), false);
    forms[1].events.submit({ preventDefault() {} }); await f.flush();
    assert.equal(f.calls[2].body.area, 'withdrawals'); assert.equal(f.calls[2].body.mode, 'direct');
    assert.equal(f.calls[2].body.revision, 7); assert.equal(f.guard.beforeLeave(), true); assert.equal(f.errors.length, 0);
});

test('checklist policy saves its own revision while preserving another unsaved policy', async () => {
    const f = fixture(); await f.flush();
    const checkboxes = walk(f.root).filter(e => e.attributes.type === 'checkbox');
    const forms = walk(f.root).filter(e => e.tag === 'form');
    const withdrawal = walk(f.root).find(e => e.tag === 'select');
    withdrawal.value = 'direct'; withdrawal.events.change();
    checkboxes[1].checked = true; checkboxes[1].events.change();
    forms[3].events.submit({ preventDefault() {} }); await f.flush();
    assert.equal(f.calls[1].body.area, 'checklist'); assert.equal(f.calls[1].body.required, true);
    assert.equal(f.calls[1].body.revision, 0); assert.equal(withdrawal.value, 'direct');
    assert.equal(f.guard.beforeLeave(), false); assert.equal(f.errors.length, 0);
});
