const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { Element, node, walk } = require('./support/elements.cjs');
const source = fs.readFileSync('public/assets/js/admission-documents.js', 'utf8');
class Multipart {
    constructor() { this.values = new Map(); }
    set(key, value) { this.values.set(key, value); }
    get(key) { return this.values.get(key); }
}
const packet = () => ({ enabled: true, can_retain: false, upload_id: 'fictional-upload', max_bytes: 5242880, items: [] });
async function fixture(data = packet(), callback = async () => data, staff = false) {
    const tasks = [], calls = [], errors = [], root = new Element('root'), window = {};
    const api = { node, request: async (...args) => { calls.push(args); return callback(...args); }, notify() {},
        task: run => { const pending = Promise.resolve().then(run).catch(error => errors.push(error)); tasks.push(pending); return pending; } };
    vm.runInNewContext(source, { window, FormData: Multipart }, { timeout: 500 });
    const guard = window.createAdmissionDocuments(api).mount(root, 'application', { staff });
    const flush = async () => { await Promise.all([...tasks]); };
    root.children[0].events.click(); await flush();
    const find = predicate => walk(root).find(predicate);
    const button = label => find(element => element.tag === 'button' && element.textContent === label);
    return { root, calls, errors, guard, flush, find, button };
}

test('disabled setup exposes guidance and no file input or hidden upload request', async () => {
    const f = await fixture({ ...packet(), enabled: false });
    assert.equal(walk(f.root).some(element => element.attributes.type === 'file'), false);
    assert.match(f.root.textContent, /New uploads are not enabled/);
    assert.equal(f.calls.length, 1);
});

test('uploads use multipart and keep their reference when retrying an interrupted selected file', async () => {
    let attempts = 0;
    const f = await fixture(packet(), async (url, method, body) => {
        if (method !== 'POST') return packet();
        assert.ok(body instanceof Multipart); assert.equal(body.get('upload_id'), 'fictional-upload');
        assert.equal(body.get('document').name, 'fictional.pdf');
        if (++attempts === 1) throw new Error('Fictional interrupted response.');
        return { status: 'quarantined' };
    });
    const file = f.find(element => element.attributes.type === 'file'); file.files = [{ name: 'fictional.pdf', size: 50 }];
    const form = f.find(element => element.tag === 'form');
    assert.equal(f.guard.beforeLeave(), false);
    form.events.submit({ preventDefault() {} }); await f.flush();
    assert.equal(file.files[0].name, 'fictional.pdf');
    form.events.submit({ preventDefault() {} }); await f.flush();
    assert.equal(attempts, 2); assert.equal(f.errors.length, 1);
});

test('only available files get protected attachment links and owners get no retention controls', async () => {
    const item = { id: 'file', name: '<img src=x>', status: 'ready', can_download: true, created_at: '2026-11-17T00:00:00Z', bytes: 50 };
    const f = await fixture({ ...packet(), items: [item, { ...item, id: 'pending', status: 'quarantined', can_download: false }] });
    const links = walk(f.root).filter(element => element.tag === 'a');
    assert.equal(links.length, 1); assert.equal(links[0].attributes.href, '/portal/api/applications/application/documents/file/download/');
    assert.equal(walk(f.root).some(element => ['img', 'iframe', 'object'].includes(element.tag)), false);
    assert.equal(f.button('File controls'), undefined);
});

test('changing retention invalidates an earlier removal review and preserves the unfinished draft', async () => {
    const data = { ...packet(), can_retain: true, items: [{ id: 'file', name: 'fictional.pdf', status: 'ready',
        can_download: true, can_purge: true, applicant_visible: true, held: false, version: 3, bytes: 50,
        created_at: '2024-01-01T00:00:00Z', expires_at: '2025-01-01T00:00:00Z' }] };
    const f = await fixture(data, async (url, method) => method === 'POST' ? { review_token: 'fictional-review' } : data, true);
    f.button('File controls').attributes.onclick();
    const reason = f.find(element => element.tag === 'textarea'); reason.value = 'Fictional expiry.';
    const disposal = walk(f.root).filter(element => element.tag === 'form').at(-1);
    disposal.events.submit({ preventDefault() {} }); await f.flush();
    const confirm = f.button('Permanently remove file'); assert.ok(confirm);
    const hold = f.find(element => element.attributes.id?.startsWith('document-hold-')); hold.checked = true; hold.events.change();
    assert.equal(f.button('Permanently remove file'), undefined);
    confirm.attributes.onclick({ currentTarget: confirm }); await f.flush();
    assert.equal(f.calls.some(([url]) => url.endsWith('/purge/')), false);
    assert.equal(f.guard.beforeLeave(), false); assert.equal(hold.checked, true);
});


test('staff uploads default to internal access and changing audience starts another upload reference', async () => {
    let reads = 0; const posted = [];
    const data = { ...packet(), can_retain: true };
    const f = await fixture(data, async (url, method, body) => {
        if (method !== 'POST') return { ...data, upload_id: ++reads === 1 ? 'first-reference' : 'new-reference' };
        posted.push([body.get('upload_id'), body.get('visible')]);
        throw new Error('Fictional interrupted upload response.');
    }, true);
    const file = f.find(element => element.attributes.type === 'file'); file.files = [{ name: 'fictional.pdf', size: 50 }];
    const form = f.find(element => element.tag === 'form');
    form.events.submit({ preventDefault() {} }); await f.flush();
    f.find(element => element.attributes.id?.startsWith('document-upload-visibility-')).checked = true;
    form.events.submit({ preventDefault() {} }); await f.flush();
    assert.deepEqual(posted, [['first-reference', '0'], ['new-reference', '1']]);
});

test('one custodial editor at a time preserves changes when another file is selected', async () => {
    const item = { id: 'first', name: 'fictional.pdf', status: 'ready', can_download: true,
        can_purge: false, applicant_visible: true, held: false, version: 3, bytes: 50,
        created_at: '2026-01-01T00:00:00Z', expires_at: '2027-01-01T00:00:00Z' };
    const f = await fixture({ ...packet(), can_retain: true, items: [item, { ...item, id: 'second' }] }, undefined, true);
    const buttons = walk(f.root).filter(element => element.tag === 'button' && element.textContent === 'File controls');
    buttons[0].attributes.onclick();
    const hold = f.find(element => element.attributes.id?.startsWith('document-hold-')); hold.checked = true;
    buttons[1].attributes.onclick();
    assert.equal(walk(f.root).filter(element => element.tag === 'details').length, 1);
    assert.equal(hold.checked, true); assert.equal(buttons[1].attributes['aria-expanded'], 'false');
    f.button('Discard changes').attributes.onclick();
    buttons[1].attributes.onclick();
    assert.equal(walk(f.root).filter(element => element.tag === 'details').length, 1);
    assert.equal(f.find(element => element.attributes.id?.startsWith('document-hold-')).checked, false);
});


test('refresh disables edits and blocks leaving until the private response finishes', async () => {
    let reads = 0, finish;
    const f = await fixture(packet(), async () => {
        if (++reads === 1) return packet();
        return new Promise(resolve => { finish = () => resolve(packet()); });
    });
    f.button('Refresh documents').attributes.onclick({ currentTarget: f.button('Refresh documents') });
    await Promise.resolve(); await Promise.resolve();
    assert.equal(f.find(element => element.tag === 'fieldset').disabled, true);
    assert.equal(f.guard.beforeLeave(), false);
    finish(); await f.flush();
    assert.equal(f.guard.beforeLeave(), true);
    assert.notEqual(f.find(element => element.tag === 'fieldset').disabled, true);
});
