const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { Element, node, walk } = require('./support/elements.cjs');

async function fixture(module, record) {
    const window = {}, tasks = [];
    vm.runInNewContext(fs.readFileSync(`public/assets/js/${module}.js`, 'utf8'), { window }, { timeout: 500 });
    const root = new Element('root');
    const api = { node, request: async () => record, notify() {}, task: callback => {
        const pending = Promise.resolve().then(callback); tasks.push(pending); return pending;
    } };
    const controller = module === 'admission-casework' ? window.createAdmissionCasework(api) : window.createPersonEducation(api);
    const guard = controller.mount(root, 'fictional', { canReview: true });
    const toggle = root.children[0];
    if (toggle.attributes.onclick) await toggle.attributes.onclick({ currentTarget: toggle }); else toggle.events.click();
    await Promise.all(tasks);
    return { guard, root, control(name) { return walk(root).find(element => element.name === name); } };
}

test('applicant messaging cannot discard edited casework or assignment choices', async () => {
    const f = await fixture('admission-casework', { assignee_name: 'Unassigned', program_id: 'program', new_preset_id: 'preset',
        case: { version: 0, office: '', work_status: 'open', follow_up_on: '', private_notes: '', next_step: '', items: [] } });
    assert.equal(f.guard.beforeLeave(), true);
    f.control('private_notes').value = 'Fictional unsaved staff note.';
    assert.equal(f.guard.beforeLeave(), false);
    assert.match(f.root.textContent, /Save casework before starting a message/);
    assert.equal(f.control('private_notes').value, 'Fictional unsaved staff note.');
    f.control('private_notes').value = '';
    assert.equal(f.guard.beforeLeave(), true);
    f.control('claim').value = 'me';
    assert.equal(f.guard.beforeLeave(), false);
});

test('applicant messaging cannot discard an unsaved educational-background reason', async () => {
    const f = await fixture('person-education', { version: 0, entries: [] });
    assert.equal(f.guard.beforeLeave(), true);
    f.control('reason').value = 'Fictional unfinished update.';
    assert.equal(f.guard.beforeLeave(), false);
    assert.match(f.root.textContent, /Your draft is still here/);
    assert.equal(f.control('reason').value, 'Fictional unfinished update.');
    assert.equal(f.control('reason').focused, true);
    f.control('reason').value = '';
    assert.equal(f.guard.beforeLeave(), true);
});


test('unfinished institution details and staff review feedback both remain available before messaging', async () => {
    const f = await fixture('person-education', { version: 1, entries: [{ key: 'school_a',
        institution: 'Fictional College', review_status: 'unreviewed', feedback: '' }] });
    assert.equal(f.guard.beforeLeave(), true);
    f.control('entries.0.institution').value = 'Fictional corrected college';
    assert.equal(f.guard.beforeLeave(), false);
    f.control('entries.0.institution').value = 'Fictional College';
    assert.equal(f.guard.beforeLeave(), true);
    const review = walk(f.root).find(element => element.tag === 'textarea' && !element.name);
    review.value = 'Fictional unfinished review feedback.';
    assert.equal(f.guard.beforeLeave(), false);
    assert.equal(review.value, 'Fictional unfinished review feedback.');
    assert.equal(review.focused, true);
    assert.ok(walk(f.root).filter(element => element.tag === 'details').every(element => element.open));
});
