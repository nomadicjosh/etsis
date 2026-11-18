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
            if (method === 'POST') return body.area === 'offers' ? { require_acceptance: body.require_acceptance, revision: 4 } : { mode: body.mode, revision: 8 };
            return { offers: { require_acceptance: true, revision: 3 }, withdrawals: { mode: 'request', revision: 7 } };
        }, task: run => { const p = Promise.resolve().then(run).catch(e => errors.push(e)); tasks.push(p); return p; } }).mount(root);
    const flush = async () => { let n; do { n = tasks.length; await Promise.all(tasks); } while (n !== tasks.length); };
    return { root, guard, calls, errors, flush };
}
test('one setup request loads both independent policies without an application', async () => {
    const f = fixture(); await f.flush();
    assert.equal(f.calls.length, 1); assert.equal(f.calls[0].url, '/sis/admission-policy/');
    assert.match(f.root.textContent, /Staff approval required/);
    assert.match(f.root.textContent, /Applicants may withdraw directly/);
    assert.equal(walk(f.root).filter(e => e.tag === 'select').length, 1);
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
