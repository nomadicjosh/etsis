'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs'), vm = require('node:vm');
const { node, walk } = require('./support/elements.cjs');
async function fixture({ changed = false, interrupted = false, legacy = false, reviewGate = null } = {}) {
    const window = {};
    vm.runInNewContext(fs.readFileSync('public/assets/js/workspace-course-drops.js', 'utf8'), { window }, { timeout: 500 });
    vm.runInNewContext(fs.readFileSync('public/assets/js/registration-drops.js', 'utf8'), { window }, { timeout: 500 });
    const body = node('div');
    Object.defineProperty(body, 'childNodes', { get: () => body.children });
    const sent = [], messages = []; let backs = 0, commits = 0;
    const windows = [{ through: null, grade_code: 'W' }];
    const definition = { grace_ends_on: '2026-08-15', timezone: 'America/Los_Angeles', windows };
    const catalog = { term: { code: '26/FA', add_drop_ends_on: '2026-08-16' }, version: 0, windows,
        calendar_changed: changed, published: legacy ? null : definition, default_policy: false };
    const ui = window.createWorkspaceCourseDrops({ node: (...args) => { const e = node(...args); Object.defineProperty(e, "childNodes", { get: () => e.children }); return e; },
        request: async (path, method, data) => {
            sent.push({ path, method, data });
            if (path.endsWith('review/') && reviewGate) await reviewGate;
            if (path.endsWith('review/')) return { definition, review_token: 'fictional-opaque-review' };
            if (path.endsWith('commit/')) {
                if (interrupted && ++commits === 1) throw Error('Fictional connection interruption');
                return { publication_id: 'fictional', version: 1 };
            }
            if (path.includes('history/')) return { items: [{ definition, version: 0, recorded_at: '2026-08-10',
                observed_previous_policy: { windows: [{ through: null, grade_code: '<img>' }] } }], next_cursor: null };
            return catalog;
        },
        openDrawer: () => body, notify: message => messages.push(message), task: run => run(),
        buildForm: async (fields, values) => {
            const form = node('form');
            for (const [name] of fields) form.append(node('input', { name, value: values[name] ?? '' }));
            form.elements = { namedItem: name => walk(form).find(e => e.name === name) };
            return form;
        },
    });
    await ui.policy({ id: 'term' }, async () => { backs++; });
    const findButton = text => walk(body).find(e => e.tag === 'button' && e.textContent === text);
    return { body, sent, messages, findButton, backs: () => backs };
}
test('publishing requires a separate review and keeps the opaque token for an uncertain retry', async () => {
    const f = await fixture({ interrupted: true });
    walk(f.body).find(e => e.tag === 'textarea').value = 'Fictional approval';
    await f.findButton('Review policy').attributes.onclick({ currentTarget: f.findButton('Review policy') });
    assert.match(f.body.textContent, /Completed drops/);
    assert.equal(f.sent.some(r => r.path.endsWith('commit/')), false);
    const publish = f.findButton('Publish policy');
    await assert.rejects(publish.attributes.onclick({ currentTarget: publish }), /interruption/);
    await publish.attributes.onclick({ currentTarget: publish });
    assert.equal(f.backs(), 1);
    const commands = f.sent.filter(r => r.path.endsWith('commit/'));
    assert.deepEqual(commands[0].data, commands[1].data);
    assert.equal(commands[0].data.review_token, 'fictional-opaque-review');
    assert.match(f.messages[0], /published/);
});
test('revising and viewing history retain entered fields and reason without treating content as HTML', async () => {
    const f = await fixture({ changed: true });
    assert.match(f.body.textContent, /Date edits do not replace the published deadline/);
    const reason = walk(f.body).find(e => e.tag === 'textarea'); reason.value = '<img> Fictional review';
    await f.findButton('Review policy').attributes.onclick({ currentTarget: f.findButton('Review policy') });
    assert.match(f.body.textContent, /<img> Fictional review/);
    await f.findButton('Revise').attributes.onclick({ currentTarget: f.findButton('Revise') });
    assert.equal(walk(f.body).find(e => e.tag === 'textarea').value, '<img> Fictional review');
    await f.findButton('Policy history').attributes.onclick({ currentTarget: f.findButton('Policy history') });
    assert.match(f.body.textContent, /original approval and dates are unknown/);
    assert.equal(walk(f.body).some(e => e.tag === 'img'), false);
    await f.findButton('Back to policy').attributes.onclick({ currentTarget: f.findButton('Back to policy') });
    assert.equal(walk(f.body).find(e => e.tag === 'textarea').value, '<img> Fictional review');
    assert.equal(f.sent.some(r => r.path.endsWith('commit/')), false);
});
test('untracked existing rules are clearly distinguished from a publication', async () => {
    const f = await fixture({ legacy: true });
    assert.match(f.body.textContent, /earlier approval details are unknown/);
    assert.equal(f.body.textContent.includes('Published revision'), false);
});

test('a delayed review cannot replace a task opened after its drawer was dismissed', async () => {
    let resolveReview;
    const gate = new Promise(resolve => { resolveReview = resolve; });
    const f = await fixture({ reviewGate: gate });
    const review = f.findButton('Review policy');
    const pending = review.attributes.onclick({ currentTarget: review });
    f.body.children[0].isConnected = false;
    resolveReview(); await pending;
    assert.equal(f.findButton('Publish policy'), undefined);
    assert.equal(f.sent.some(r => r.path.endsWith('commit/')), false);
});

test('changing fields during a pending review keeps the newer draft and requires another review', async () => {
    let release;
    const gate = new Promise(resolve => { release = resolve; });
    const f = await fixture({ reviewGate: gate });
    const reason = walk(f.body).find(e => e.tag === 'textarea');
    reason.value = 'First fictional reason';
    const review = f.findButton('Review policy');
    const pending = review.attributes.onclick({ currentTarget: review });
    reason.value = 'Updated fictional reason';
    release(); await pending;
    assert.match(f.body.textContent, /fields changed/);
    assert.equal(reason.value, 'Updated fictional reason');
    assert.equal(f.findButton('Publish policy'), undefined);
});
