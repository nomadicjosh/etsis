'use strict';
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const context = { window: {}, crypto: require('node:crypto').webcrypto, TextEncoder, Uint8Array };
vm.runInNewContext(readFileSync(path.join(__dirname, '../../public/assets/js/admission-casework.js'), 'utf8'),
    context, { filename: 'admission-casework.js', timeout: 500 });
const clone = value => JSON.parse(JSON.stringify(value));
const item = { key: 'previous_transcript', label: 'Previous transcript', instructions: 'Arrange delivery.',
    needed: true, visible: true, status: 'complete', due_on: '2026-11-01', private_notes: 'Must not enter a preset.' };

test('preset definitions omit application progress dates and staff notes', () => {
    const definition = clone(context.window.admissionChecklistDefinition([item]));
    assert.deepEqual(definition, [{ key: item.key, label: item.label, instructions: item.instructions, needed: true, visible: true }]);
});

test('applying a preset keeps existing work and never duplicates or resets an existing copied item', async () => {
    const definition = clone(context.window.admissionChecklistDefinition([item]));
    const first = clone(await context.window.mergeAdmissionChecklist([item], definition, 'fictional-preset'));
    assert.equal(first.length, 2); assert.equal(first[0].status, 'complete');
    assert.equal(first[1].status, 'pending'); assert.equal(first[1].due_on, '');
    first[1].status = 'complete'; first[1].label = 'Reviewed application-specific label';
    const again = clone(await context.window.mergeAdmissionChecklist(first, [{ ...definition[0], label: 'Changed preset label' }], 'fictional-preset'));
    assert.deepEqual(again, first);
});

test('different presets stay distinct and over-limit copies leave the original working list untouched', async () => {
    const definition = clone(context.window.admissionChecklistDefinition([item]));
    const one = await context.window.mergeAdmissionChecklist([], definition, 'one');
    const two = await context.window.mergeAdmissionChecklist(one, definition, 'two');
    assert.equal(two.length, 2); assert.notEqual(two[0].key, two[1].key);
    const full = Array.from({ length: 30 }, (_, index) => ({ ...item, key: `existing_${index}` }));
    await assert.rejects(context.window.mergeAdmissionChecklist(full, definition, 'three'), /exceed thirty/);
    assert.equal(full.length, 30); assert.ok(full.every(row => row.status === 'complete'));
});
