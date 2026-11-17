'use strict';
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const context = { window: {} };
vm.runInNewContext(readFileSync(path.join(__dirname, '../../public/assets/js/person-education.js'), 'utf8'),
    context, { filename: 'person-education.js', timeout: 500 });
const copy = value => JSON.parse(JSON.stringify(value));
const record = () => ({ version: 3, entries: [{ key: 'school_a', institution: 'Fictional College',
    review_status: 'changes_requested', feedback: 'Check the attendance dates.' }] });

test('save commands contain academic details but never allow client review-state assignment', () => {
    const source = record(), draft = context.window.createPersonEducationDraft(source);
    const command = copy(draft.command(source.entries, 'Correcting dates.'));
    assert.equal(command.version, 3);
    assert.equal(command.reason, 'Correcting dates.');
    assert.equal(command.entries[0].institution, 'Fictional College');
    assert.equal(command.entries[0].start_date, '');
    assert.equal(command.entries[0].review_status, undefined);
    assert.equal(command.entries[0].feedback, undefined);
});

test('the captured version and details cannot silently follow a later response or changed working array', () => {
    const source = record(), draft = context.window.createPersonEducationDraft(source);
    assert.equal(draft.changed(source.entries), false);
    const command = draft.command(source.entries, 'First change.');
    source.version = 99; source.entries[0].institution = 'Other College';
    assert.equal(draft.changed(source.entries), true);
    assert.equal(command.entries[0].institution, 'Fictional College');
    assert.equal(draft.command(source.entries, 'Another change.').version, 3);
});

test('removal and reordering require saving while review-only feedback is not a detail edit', () => {
    const source = record(), draft = context.window.createPersonEducationDraft(source);
    source.entries[0].feedback = 'Different review feedback.';
    assert.equal(draft.changed(source.entries), false);
    assert.equal(draft.changed([]), true);
    source.entries.push({ key: 'school_b', institution: 'Fictional University' });
    const two = context.window.createPersonEducationDraft(source);
    assert.equal(two.changed([...source.entries].reverse()), true);
});
