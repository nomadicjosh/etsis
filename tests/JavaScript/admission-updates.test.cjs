const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/admission-casework.js', 'utf8');
const notice = { id: 'notice', application_id: 'application', revision: 1, unread: true,
    program_code: 'BA.ART', program_title: 'Fictional Arts', term_code: '26/FA', updated_at: '2026-11-17T10:00:00.000000Z' };
const page = { items: [notice], unread_count: 1, next: null };

// A small DOM fixture runs the actual feed without starting a browser or server.
function fixture(request) {
    class Element {
        constructor(tag, attributes = {}) {
            this.tag = tag; this.attributes = attributes; this.children = []; this.parent = null;
            this.hidden = !!attributes.hidden; this.checked = !!attributes.checked; this.text = '';
        }
        get isConnected() { return this === root || !!this.parent?.isConnected; }
        get textContent() { return this.text + this.children.map(child => child.textContent).join(''); }
        set textContent(value) { this.replaceChildren(); this.text = String(value); }
        append(...children) {
            for (const child of children.flat(Infinity)) {
                if (child === null || child === undefined) continue;
                const element = child instanceof Element ? child : Object.assign(new Element('text'), { text: String(child) });
                element.parent = this; this.children.push(element);
            }
        }
        replaceChildren(...children) {
            this.children.forEach(child => { child.parent = null; }); this.children = []; this.text = ''; this.append(...children);
        }
        focus() {}
    }
    const root = new Element('root'), errors = [], calls = [], tasks = new Set();
    const node = (tag, attributes = {}, ...children) => {
        const element = new Element(tag, attributes); element.append(...children); return element;
    };
    const task = run => {
        const pending = Promise.resolve().then(run).catch(error => errors.push(error));
        tasks.add(pending); pending.finally(() => tasks.delete(pending)); return pending;
    };
    const window = {};
    vm.runInNewContext(source, { window, URLSearchParams }, { timeout: 500, filename: 'admission-casework.js' });
    window.createAdmissionCasework({ node, task, notify() {}, request: async (...args) => {
        calls.push(args); return request(...args);
    } }).updates(root);
    const descendants = element => [element, ...element.children.flatMap(descendants)];
    const button = name => {
        const element = descendants(root).find(item => item.tag === 'button' && item.textContent === name);
        assert.ok(element, `Missing button ${name}`); return element;
    };
    return { root, errors, calls, button, async flush() { await Promise.all([...tasks]); },
        async click(name) { const element = button(name); return element.attributes.onclick({ currentTarget: element }); } };
}

test('opening an update fetches current guidance then explicitly acknowledges the captured revision', async () => {
    let acknowledged = false;
    const f = fixture(async (url, method, body) => {
        if (url.startsWith('/portal/api/admissions-updates/?')) return acknowledged ? { ...page, items: [], unread_count: 0 } : page;
        if (url.endsWith('/next-steps/')) return { office: 'Admissions', next_step: 'Arrange your transcript.', items: [] };
        assert.equal(url, '/portal/api/admissions-updates/notice/read/');
        assert.equal(method, 'POST'); assert.equal(body.revision, 1); acknowledged = true; return { unread: false };
    });
    await f.flush();
    assert.equal(f.calls.length, 1); // Listing alone never writes read state.
    await f.click('Read update');
    assert.match(f.root.textContent, /Arrange your transcript/);
    assert.match(f.root.textContent, /Update marked read/);
    assert.match(f.root.textContent, /0 unread admissions updates/);
    assert.deepEqual(f.errors, []);
});

test('closing the detail before guidance arrives prevents a detached result from acknowledging it', async () => {
    let resolveGuidance;
    const f = fixture(async url => url.includes('/next-steps/')
        ? new Promise(resolve => { resolveGuidance = resolve; }) : page);
    await f.flush();
    const opening = f.click('Read update');
    await new Promise(setImmediate);
    await f.click('Close update');
    resolveGuidance({ office: 'Admissions', next_step: 'Late guidance.', items: [] }); await opening;
    assert.equal(f.calls.some(([, method]) => method === 'POST'), false);
    assert.doesNotMatch(f.root.textContent, /Late guidance/);
    assert.deepEqual(f.errors, []);
});

test('a newer notice stays unread when the older displayed revision is acknowledged', async () => {
    let lists = 0;
    const f = fixture(async (url, method, body) => {
        if (url.endsWith('/next-steps/')) return { office: '', next_step: 'Current guidance.', items: [] };
        if (url.endsWith('/read/')) { assert.equal(body.revision, 1); return { unread: true }; }
        return ++lists === 1 ? page : { ...page, items: [{ ...notice, revision: 2 }] };
    });
    await f.flush(); await f.click('Read update');
    assert.match(f.root.textContent, /A newer update arrived/);
    assert.match(f.root.textContent, /1 unread admissions updates/);
});

test('a refresh failure after a successful acknowledgement accurately reports the saved read status', async () => {
    let lists = 0;
    const f = fixture(async url => {
        if (url.endsWith('/next-steps/')) return { office: '', next_step: 'Current guidance.', items: [] };
        if (url.endsWith('/read/')) return { unread: false };
        if (++lists > 1) throw new Error('Fictional connection failure.');
        return page;
    });
    await f.flush(); await f.click('Read update');
    assert.match(f.root.textContent, /Your read status was saved/);
    assert.match(f.root.textContent, /Previous results are still shown/);
    assert.equal(f.errors.length, 1);
});
