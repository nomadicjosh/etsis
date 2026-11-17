'use strict';
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

// Exercise the production placement model without Chromium, a web server or application bootstrap.
const context = { window: {} };
vm.runInNewContext(readFileSync(path.join(__dirname, '../../public/assets/js/workspace-form-layout.js'), 'utf8'),
    context, { filename: 'workspace-form-layout.js', timeout: 500 });

function definition() {
    return { fields: [
        { key: 'prior_school', type: 'text', label: 'Previous college', help: 'A fictional question.', options: [] },
        { key: 'country', type: 'select', label: 'Country', options: ['US', 'CA'] },
        { key: 'degree', type: 'text', label: 'Degree', show_when: { condition: 'AND', rules: [
            { id: 'prior_school', operator: 'equal', value: 'prior_school' },
            { condition: 'OR', rules: [
                { id: 'country', operator: 'in', value: ['US', 'CA'] },
                { id: 'prior_school', operator: 'not_equal', value: 'none' },
            ] },
        ] } },
        { key: 'notes', type: 'text', label: 'Notes', show_when: { condition: 'OR', rules: [
            { id: 'prior_school', operator: 'in', value: ['prior_school', 'Fictional College'] },
            { id: 'degree', operator: 'equal', value: 'prior_school' },
        ] } },
    ], layout: [
        { key: 'basics', label: 'Basics', columns: [['prior_school', 'country'], ['degree']] },
        { key: 'other', label: 'Other information', columns: [['notes']] },
    ] };
}
const editor = draft => context.window.createApplicationLayoutEditor({ draft: () => draft });

test('a key change preserves nested conditions, literal answers and column order', () => {
    const draft = definition();
    assert.equal(editor(draft).rename('prior_school', 'college'), true);
    assert.equal(draft.fields[0].key, 'college');
    assert.deepEqual(draft.layout[0].columns, [['college', 'country'], ['degree']]);
    assert.deepEqual(draft.fields[2].show_when.rules, [
        { id: 'college', operator: 'equal', value: 'prior_school' },
        { condition: 'OR', rules: [
            { id: 'country', operator: 'in', value: ['US', 'CA'] },
            { id: 'college', operator: 'not_equal', value: 'none' },
        ] },
    ]);
    assert.deepEqual(draft.fields[3].show_when.rules, [
        { id: 'college', operator: 'in', value: ['prior_school', 'Fictional College'] },
        { id: 'degree', operator: 'equal', value: 'prior_school' },
    ]);
    assert.equal(draft.fields[0].label, 'Previous college');
    assert.equal(draft.fields[0].help, 'A fictional question.');
});

test('invalid, duplicate and missing keys leave the complete working definition unchanged', () => {
    for (const key of ['', 'country', '0college', 'college.name', 'College', 'a'.repeat(41), null, 42]) {
        const draft = definition(), before = structuredClone(draft);
        assert.equal(editor(draft).rename('prior_school', key), false);
        assert.deepEqual(draft, before);
    }
    const draft = definition(), before = structuredClone(draft);
    assert.equal(editor(draft).rename('missing_question', 'college'), false);
    assert.equal(editor(draft).rename('prior_school', 'prior_school'), true);
    assert.deepEqual(draft, before);
});

test('sequential key edits keep subsequent placement actions and restored definitions usable', () => {
    const draft = definition(), original = structuredClone(draft), model = editor(draft);
    assert.equal(model.rename('prior_school', 'college'), true);
    assert.equal(model.rename('college', 'college_name'), true);
    assert.equal(model.canMove('college_name', 1), true);
    model.move('college_name', 1);
    assert.deepEqual(draft.fields.map(field => field.key), ['country', 'college_name', 'degree', 'notes']);
    assert.equal(draft.fields[2].show_when.rules[0].id, 'college_name');
    Object.assign(draft, structuredClone(original));
    assert.equal(model.rename('prior_school', 'restored_college'), true);
    assert.equal(draft.fields[0].key, 'restored_college');
    assert.equal(draft.fields[2].show_when.rules[0].id, 'restored_college');
    assert.equal(draft.layout[0].columns[0][0], 'restored_college');
});

test('editing a working copy leaves the retained definition and submitted answers unchanged', () => {
    const retained = definition(), before = JSON.stringify(retained);
    const answers = Object.freeze({ prior_school: 'Fictional College', country: 'US' });
    const draft = structuredClone(retained);
    editor(draft).rename('prior_school', 'college');
    assert.equal(JSON.stringify(retained), before);
    assert.deepEqual(answers, { prior_school: 'Fictional College', country: 'US' });
    assert.equal(draft.layout[0].columns[0][0], 'college');
});

test('flat forms support valid prototype-like names without treating them as object properties', () => {
    const draft = definition(); draft.layout = [];
    const model = editor(draft);
    assert.equal(model.rename('prior_school', 'constructor'), true);
    assert.equal(draft.fields[2].show_when.rules[0].id, 'constructor');
    assert.equal(model.rename('constructor', 'college'), true);
    assert.equal(draft.fields[2].show_when.rules[0].id, 'college');
    assert.equal(Object.prototype.college, undefined);
});

test('all dependent questions remain linked in a maximum-sized form', () => {
    const draft = { fields: [{ key: 'source', type: 'text' }], layout: [] };
    for (let index = 1; index < 40; index++) draft.fields.push({ key: `question_${index}`, type: 'text',
        show_when: { condition: 'AND', rules: Array.from({ length: 20 }, () => ({ id: 'source', operator: 'equal', value: 'source' })) } });
    assert.equal(editor(draft).rename('source', 'college'), true);
    for (const field of draft.fields.slice(1)) for (const rule of field.show_when.rules) {
        assert.equal(rule.id, 'college'); assert.equal(rule.value, 'source');
    }
});

test('labels, hints and choices reject invalid definitions before a save', () => {
    const model = editor(definition());
    for (const value of ['', '   ', 'a'.repeat(121), 'A\tlabel']) assert.notEqual(model.inputProblem('label', value), '');
    assert.equal(model.inputProblem('label', '😀'.repeat(120)), '');
    assert.notEqual(model.inputProblem('label', '😀'.repeat(121)), '');
    assert.equal(model.inputProblem('help', ''), '');
    assert.equal(model.inputProblem('help', '😀'.repeat(300)), '');
    assert.notEqual(model.inputProblem('help', '😀'.repeat(301)), '');
    assert.notEqual(model.inputProblem('help', 'A\u0000hint'), '');
    for (const value of ['', '\n  \n', Array(31).fill('Choice').join('\n'), 'a'.repeat(121), 'One\tchoice'])
        assert.notEqual(model.inputProblem('options', value), '');
    assert.equal(model.inputProblem('options', 'One\n\nTwo\n'), '');
    assert.equal(model.inputProblem('options', Array(30).fill('😀'.repeat(120)).join('\n')), '');
});

test('condition editing respects the bounded rule language before applying changes', () => {
    const model = editor(definition());
    const leaf = value => ({ id: 'prior_school', operator: 'equal', value });
    const group = rules => ({ condition: 'AND', rules });
    assert.equal(model.conditionLimits(group([leaf(false), leaf(0), leaf('😀'.repeat(191))])), '');
    assert.equal(model.conditionLimits(group([{ id: 'country', operator: 'in', value: Array(20).fill('US') }])), '');
    for (const rules of [[], Array(21).fill(leaf('US')), [leaf('')], [leaf('a'.repeat(192))], [leaf('A\tvalue')],
        [leaf(1.5)], [{ id: 'country', operator: 'in', value: Array(21).fill('US') }]])
        assert.notEqual(model.conditionLimits(group(rules)), '');
    const limit = group([...Array.from({ length: 18 }, () => leaf('US')),
        group(Array.from({ length: 20 }, () => leaf('US'))), group(Array.from({ length: 9 }, () => leaf('US')))]);
    assert.equal(model.conditionLimits(limit), '');
    limit.rules[19].rules.push(leaf('US'));
    assert.notEqual(model.conditionLimits(limit), '');
    let nested = group([leaf('US')]);
    for (let depth = 0; depth < 4; depth++) nested = group([nested]);
    assert.equal(model.conditionLimits(nested), '');
    assert.notEqual(model.conditionLimits(group([nested])), '');
    const cycle = group([]); cycle.rules.push(cycle);
    assert.notEqual(model.conditionLimits(cycle), '');
});
