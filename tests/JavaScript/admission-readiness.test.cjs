'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs'), vm = require('node:vm');
const { node, walk } = require('./support/elements.cjs');
const window = {};
vm.runInNewContext(fs.readFileSync('public/assets/js/admission-readiness.js', 'utf8'), { window }, { timeout: 500 });
const ui = window.createAdmissionReadiness({ node });
const data = { required: true, ready: false, needed: 2, complete: 0, not_needed: 0,
    pending: ['Fictional transcript', '<img> Private fictional staff check'], needs_case_review: false };
test('required saved readiness shows unresolved tasks as text and distinguishes received from complete', () => {
    const panel = ui.staff(data);
    assert.match(panel.textContent, /Required before acceptance/);
    assert.match(panel.textContent, /pending or received/);
    assert.match(panel.textContent, /Fictional transcript/);
    assert.match(panel.attributes.class, /alert-warning/);
    assert.equal(walk(panel).some(e => e.tag === 'img'), false);
});
test('advisory and empty-case guidance do not claim that admission was granted', () => {
    const panel = ui.staff({ ...data, required: false, needs_case_review: true, pending: [], needed: 0 });
    assert.match(panel.textContent, /Advisory checklist/); assert.match(panel.textContent, /mark casework Done/);
    assert.match(panel.textContent, /permits a decision/); assert.match(panel.attributes.class, /alert-info/);
});
test('history displays frozen counts and policy without revealing names or mutable working details', () => {
    const panel = ui.history({ ...data, required: true, ready: true, complete: 1, not_needed: 1 });
    assert.match(panel.textContent, /at this decision/); assert.match(panel.textContent, /required · ready/);
    assert.match(panel.textContent, /1 complete · 1 not needed/);
    assert.equal(panel.textContent.includes('Fictional transcript'), false);
    assert.equal(panel.textContent.includes('Private'), false);
});
