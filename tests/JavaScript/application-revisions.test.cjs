'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs'), vm = require('node:vm');
const { Element, node, walk } = require('./support/elements.cjs');
const source = fs.readFileSync('public/assets/js/application-revisions.js', 'utf8');
const packet = () => ({ form: { name: 'Fictional original form', fields: [{ key: 'statement', label: 'Statement', type: 'textarea' }], layout: [] },
    answers: { statement: 'Original fictional answer.' }, submitted_term_code: '26/FA', versions: [], revision_id: null,
    application_status: 'submitted', can_request: true, request: null, request_pending: false, can_revise: false });
function fixture(callback = async () => packet(), options = {}) {
    const root = new Element('root'), calls = [], errors = [], tasks = [];
    let filled = null, answers = { statement: 'Revised fictional answer.' };
    const window = { confirm: () => false,
        createApplicationAnswerReview: data => node('div', { class: 'answers' }, JSON.stringify(data.answers)),
        createApplicationForm: () => ({ form: node('form'), fill: data => { filled = data; },
            answers: () => answers, reportValidity: () => true, showErrors() {} }) };
    vm.runInNewContext(source, { window }, { timeout: 500 });
    const guard = window.createApplicationRevisions({ node, notify() {}, request: async (...args) => { calls.push(args); return callback(...args); },
        task: run => { const p = Promise.resolve().then(run).catch(e => errors.push(e)); tasks.push(p); return p; }
    }).mount(root, 'application', options);
    const flush = async () => { let n; do { n = tasks.length; await Promise.all(tasks); } while (n !== tasks.length); };
    const find = pred => walk(root).find(pred), button = text => find(e => e.tag === 'button' && e.textContent === text);
    const click = async text => { assert.ok(button(text), `Missing ${text}`); button(text).events.click(); await flush(); };
    const submit = async () => { find(e => e.tag === 'form').events.submit({ preventDefault() {} }); await flush(); };
    return { root, guard, calls, errors, flush, find, button, click, submit, filled: () => filled };
}
test('owner answer reads are lazy and preserve a choice between current and original versions', async () => {
    const f = fixture(); assert.equal(f.calls.length, 0); await f.click('Application answers');
    assert.equal(f.calls[0][0], '/portal/api/applications/application/answers/');
    assert.equal(f.button('Request corrections'), undefined); assert.equal(f.button('Revise answers'), undefined);
    const selector = f.find(e => e.tag === 'select'); selector.value = '?original=1'; selector.events.change(); await f.flush();
    assert.equal(f.calls[1][0], '/portal/api/applications/application/answers/?original=1'); assert.equal(f.errors.length, 0);
});
test('requested edits fill the original form, preserve input on revise and retry the same token', async () => {
    let commits = 0, complete = 0;
    const f = fixture(async (url, method, body) => {
        if (url.endsWith('/review/')) {
            assert.equal(body.action, 'submit'); assert.equal(body.answers.statement, 'Revised fictional answer.');
            return { answers: body.answers, reason: body.reason, review_token: 'same-private-review' };
        }
        if (url.endsWith('/commit/')) { assert.equal(body.review_token, 'same-private-review'); if (++commits === 1) throw new Error('Fictional interruption.'); return { status: 'submitted' }; }
        return { ...packet(), can_revise: true, request_pending: true, request: { id: 'request', instructions: '<img> Fictional correction.' } };
    }, { onSubmitted: () => { complete++; } });
    await f.click('Application answers'); await f.click('Revise answers');
    assert.equal(f.filled().statement, 'Original fictional answer.');
    const reason = f.find(e => e.tag === 'textarea'); reason.value = '<img> Fictional reason.';
    f.find(e => e.tag === 'form').events.input(); await f.submit();
    assert.equal(f.guard.beforeLeave(), false); assert.equal(f.find(e => e.tag === 'form').hidden, true);
    await f.click('Edit changes'); assert.equal(reason.value, '<img> Fictional reason.'); await f.submit();
    await f.click('Resubmit answers'); assert.equal(f.errors.length, 1);
    await f.click('Resubmit answers'); assert.equal(complete, 1); assert.equal(f.guard.beforeLeave(), true);
    assert.equal(walk(f.root).some(e => e.tag === 'img'), false);
});
test('only independently authorized staff see correction request controls', async () => {
    for (const canAmend of [true, false]) {
        const f = fixture(async () => packet(), { staff: true, canAmend, initiallyOpen: true }); await f.flush();
        assert.equal(!!f.button('Request corrections'), canAmend);
        assert.equal(f.calls[0][0], '/sis/applications/application/answers/');
        if (canAmend) {
            await f.click('Request corrections'); const reason = f.find(e => e.tag === 'textarea'); reason.value = 'Fictional request.';
            assert.equal(reason.attributes.maxlength, 2000);
        }
    }
});
test('pending work in another panel prevents starting an answer editor', async () => {
    const f = fixture(async () => ({ ...packet(), can_revise: true }), { initiallyOpen: true, beforeChange: () => false });
    await f.flush(); await f.click('Revise answers'); assert.equal(f.find(e => e.tag === 'form'), undefined);
});
test('form restoration fills checkbox, radio, numbers and dependent questions without running a browser', () => {
    class Dom extends Element {
        constructor(tag) { super(tag); this.type = 'text'; this.classList = { toggle() {} }; this.validity = { valid: true }; }
        setAttribute(key, value) { super.setAttribute(key, value); if (['id', 'name', 'type', 'value'].includes(key)) this[key] = value; if (key === 'hidden') this.hidden = true; }
        removeAttribute(key) { delete this.attributes[key]; if (key === 'hidden') this.hidden = false; }
        appendChild(child) { this.append(child); return child; }
        after() {}
        setCustomValidity(message) { this.validity.valid = !message; }
    }
    const document = { createElement: tag => new Dom(tag), createTextNode: text => Object.assign(new Dom('text'), { text }) };
    const window = {};
    vm.runInNewContext(fs.readFileSync('public/assets/js/application-form.js', 'utf8'), { window, document, Node: Dom, HTMLFormElement: Dom }, { timeout: 500 });
    const form = window.createApplicationForm({ fields: [
        { key: 'yes', label: 'Yes', type: 'checkbox' }, { key: 'choice', label: 'Choice', type: 'radio', options: ['One', 'Two'] },
        { key: 'count', label: 'Count', type: 'number' },
        { key: 'details', label: 'Details', type: 'text', show_when: { condition: 'AND', rules: [{ id: 'yes', operator: 'equal', value: true }] } },
    ] });
    form.fill({ yes: true, choice: 'Two', count: 0, details: 'Fictional details.' });
    assert.deepEqual(JSON.parse(JSON.stringify(form.answers())), { yes: true, choice: 'Two', count: 0, details: 'Fictional details.' });
    form.fill({ yes: false, choice: 'One', count: 2, details: 'Now hidden.' });
    assert.deepEqual(JSON.parse(JSON.stringify(form.answers())), { yes: false, choice: 'One', count: 2 });
});
