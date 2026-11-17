const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { Element, node, walk } = require('./support/elements.cjs');
const source = fs.readFileSync('public/assets/js/workspace-operations.js', 'utf8');
const recipient = { id: 'person', user_id: 'account', given_name: 'Fictional', family_name: 'Applicant', institutional_id: 'EXAMPLE.ID' };
const permissions = ['sis:communications:manage', 'sis:person:manage', 'sis:admissions:manage', 'sis:student:read'];
function fixture({ grants = permissions, read = async () => ({ items: [recipient] }), ui = {} } = {}) {
    const requests = [], compositions = [], window = {};
    vm.runInNewContext(source, { window }, { timeout: 500, filename: 'workspace-operations.js' });
    const operations = window.createWorkspaceOperations({ can: permission => grants.includes(permission),
        request: async (...args) => { requests.push(args); return read(...args); },
        createRecord: async (...args) => compositions.push(args), ...ui });
    return { operations, requests, compositions };
}

test('all record shortcuts resolve metadata before opening the same empty encrypted-draft composer', async () => {
    for (const kind of ['person', 'student', 'application']) {
        const f = fixture(), back = () => {}, container = { isConnected: true };
        await f.operations.messageRecord(kind, 'record', back, container);
        assert.deepEqual(f.requests.map(args => args[0]), [`/sis/campaigns/recipients/${kind}/record/`]);
        const [section, preset, options] = f.compositions[0];
        assert.equal(section, 'campaigns'); assert.equal(Object.keys(preset).length, 0);
        assert.equal(options.back, back); assert.equal(options.backLabel, `Back to ${kind}`);
        assert.match(options.explanation, /Fictional Applicant · EXAMPLE.ID/);
        assert.match(options.explanation, /separate background-delivery confirmation/);
        assert.equal(f.requests.length, 1); // No draft, audience or send mutation in the shortcut.
    }
});

test('independent directory communications and source permissions are required for each shortcut', async () => {
    for (const [kind, required] of [['person', 'sis:person:manage'], ['student', 'sis:student:read'], ['application', 'sis:admissions:manage']]) {
        for (const missing of new Set(['sis:communications:manage', 'sis:person:manage', required])) {
            const f = fixture({ grants: permissions.filter(grant => grant !== missing) });
            await f.operations.messageRecord(kind, 'record', () => {}, { isConnected: true });
            assert.equal(f.requests.length, 0); assert.equal(f.compositions.length, 0);
        }
    }
});

test('navigation and a newly edited source draft discard a delayed lookup without opening another screen', async () => {
    for (const problem of ['detached', 'edited']) {
        let resolve;
        const f = fixture({ read: () => new Promise(done => { resolve = done; }) });
        const container = { isConnected: true }; let canLeave = true;
        const pending = f.operations.messageRecord('application', 'record', () => {}, container, () => canLeave);
        if (problem === 'detached') container.isConnected = false; else canLeave = false;
        resolve({ items: [recipient] }); await pending;
        assert.equal(f.compositions.length, 0);
    }
});

test('an unsaved source blocks lookup and an unavailable account never opens a draft', async () => {
    const unsaved = fixture();
    await unsaved.operations.messageRecord('application', 'record', () => {}, { isConnected: true }, () => false);
    assert.equal(unsaved.requests.length, 0); assert.equal(unsaved.compositions.length, 0);
    const unavailable = fixture({ read: async () => { throw new Error('Fictional unavailable account.'); } });
    await assert.rejects(unavailable.operations.messageRecord('application', 'record', () => {}, { isConnected: true }));
    assert.equal(unavailable.compositions.length, 0);
    const invalid = fixture({ read: async () => ({ items: [] }) });
    await assert.rejects(invalid.operations.messageRecord('application', 'record', () => {}, { isConnected: true }));
    assert.equal(invalid.compositions.length, 0);
});

test('the report-selection composer keeps its existing return label and reviewed sending flow', async () => {
    const f = fixture();
    await f.operations.composeMessage([recipient, { ...recipient, user_id: 'another-account' }], () => {});
    const options = f.compositions[0][2];
    assert.equal(options.backLabel, 'Back to report selection');
    assert.match(options.explanation, /2 recipients selected/);
    assert.equal(typeof options.afterSave, 'function');
});


test('saved drafts retain the source return path through recipient selection and the message record', async () => {
    const body = new Element('root'), opened = [], back = () => {};
    const f = fixture({ ui: { node, task: run => run(), openDrawer: () => body,
        openRecord: (...args) => opened.push(args) } });
    await f.operations.messageRecord('application', 'record', back, { isConnected: true });
    await f.compositions[0][2].afterSave({ id: 'saved-message', version: 0 });
    const buttons = walk(body).filter(element => element.tag === 'button');
    assert.ok(buttons.some(element => element.textContent === 'Back to application'));
    const message = buttons.find(element => element.textContent === 'Back to message');
    await message.attributes.onclick({ currentTarget: message });
    assert.equal(opened[0][0], 'campaigns');
    assert.equal(opened[0][1].id, 'saved-message');
    assert.equal(opened[0][2].back, back);
    assert.equal(opened[0][2].backLabel, 'Back to application');
    assert.equal(f.requests.length, 1);
});
