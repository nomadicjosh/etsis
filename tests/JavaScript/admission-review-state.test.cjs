const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { Element, walk } = require('./support/elements.cjs');
const source = fs.readFileSync('public/assets/js/workspace-people.js', 'utf8');
const start = source.indexOf('    async function reviewState(');
const end = source.indexOf('    async function decisionHistory(', start);
assert.ok(start >= 0 && end > start);
async function fixture(withdraw = true, callback = null) {
    let body; const tasks = [], calls = [], errors = [], reopened = [];
    const node = (tag, attributes = {}, ...children) => {
        const element = new Element(tag, attributes);
        Object.defineProperty(element, 'childNodes', { get: () => element.children });
        element.append(...children); return element;
    };
    const openDrawer = title => {
        if (body) walk(body).forEach(element => { element.isConnected = false; });
        body = node('root'); body.title = title; return body;
    };
    const task = run => { const pending = Promise.resolve().then(run).catch(error => errors.push(error)); tasks.push(pending); return pending; };
    const context = { window: {}, node, task, notify() {}, openDrawer,
        button: (label, run) => node('button', { onclick: () => task(run) }, label),
        application: async row => reopened.push(row.id),
        buildForm: async (fields, preset) => {
            const form = node('form'), reason = node('textarea', { name: 'reason', value: preset.reason || '' });
            form.elements = { reason }; form.append(reason); return form;
        }, formData: form => ({ reason: form.elements.reason.value }),
        request: async (...args) => {
            calls.push(args); if (callback) return callback(...args);
            return { person: { given_name: 'Fictional', family_name: 'Applicant', institutional_id: 'S-EXAMPLE' },
                source: { program_code: 'BA.ART', program_title: 'Fictional arts', entry_term_code: '26/FA' },
                previous_status: withdraw ? 'submitted' : 'withdrawn', status: withdraw ? 'withdrawn' : 'submitted',
                effective_on: '2026-11-17', review_token: 'fictional-sealed-review' };
        } };
    vm.runInNewContext(source.slice(start, end) + '\nwindow.start = reviewState;', context, { timeout: 500 });
    await context.window.start({ id: 'application' }, withdraw);
    return { calls, errors, reopened, body: () => body, flush: async () => { await Promise.all([...tasks]); },
        navigate: () => openDrawer('Another task'),
        form: () => walk(body).find(element => element.tag === 'form'),
        button: label => walk(body).find(element => element.tag === 'button' && element.textContent === label) };
}

for (const withdraw of [true, false]) test(`${withdraw ? 'withdrawal' : 'return to review'} preserves a reason through revision and confirms only the sealed review`, async () => {
    const f = await fixture(withdraw);
    f.form().elements.reason.value = 'Fictional private reason.';
    f.form().events.submit({ preventDefault() {} }); await f.flush();
    assert.match(f.body().title, /Confirm/);
    assert.equal(f.calls.length, 1);
    assert.equal(f.calls[0][2].withdraw, withdraw);
    f.button('Revise change').attributes.onclick(); await f.flush();
    assert.equal(f.form().elements.reason.value, 'Fictional private reason.');
    f.form().events.submit({ preventDefault() {} }); await f.flush();
    f.button(withdraw ? 'Confirm withdrawal' : 'Confirm return to review').attributes.onclick(); await f.flush();
    const committed = f.calls.at(-1);
    assert.equal(committed[0], '/sis/applications/application/review-state/commit/');
    assert.deepEqual(JSON.parse(JSON.stringify(committed[2])), { review_token: 'fictional-sealed-review' });
    assert.deepEqual(f.reopened, ['application']); assert.deepEqual(f.errors, []);
});

test('a late review response cannot overwrite another staff task', async () => {
    let finish;
    const f = await fixture(true, () => new Promise(resolve => { finish = resolve; }));
    const form = f.form(); form.elements.reason.value = 'Fictional reason.';
    form.events.submit({ preventDefault() {} }); await Promise.resolve(); await Promise.resolve();
    assert.equal(walk(form).find(element => element.tag === 'fieldset').disabled, true);
    f.navigate(); finish({}); await f.flush();
    assert.equal(f.body().title, 'Another task'); assert.equal(f.calls.length, 1);
});
