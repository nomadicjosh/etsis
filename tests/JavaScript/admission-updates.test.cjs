const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/admission-casework.js', 'utf8');
const notice = { id: 'notice', application_id: 'application', revision: 1, unread: true,
    program_code: 'BA.ART', program_title: 'Fictional Arts', term_code: '26/FA', updated_at: '2026-11-17T10:00:00.000000Z' };
const page = { items: [notice], unread_count: 1, next: null };

// A small DOM fixture runs the actual feed without starting a browser or server.
function fixture(request, search = '') {
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
        querySelectorAll(selector) {
            const tags = selector.split(',').map(tag => tag.trim());
            return this.children.flatMap(child => [...(tags.includes(child.tag) ? [child] : []),
                ...child.querySelectorAll(selector)]);
        }
    }
    const root = new Element('root'), errors = [], calls = [], tasks = new Set();
    const node = (tag, attributes = {}, ...children) => {
        const element = new Element(tag, attributes); element.append(...children); return element;
    };
    const task = run => {
        const pending = Promise.resolve().then(run).catch(error => errors.push(error));
        tasks.add(pending); pending.finally(() => tasks.delete(pending)); return pending;
    };
    const window = { location: { search },
        createApplicantApplicationHistory: () => ({ mount: () => ({ trigger: () => node('span') }) }),
        createAdmissionChangeRequests: () => ({ mount: () => ({ beforeLeave: () => true }) }),
    };
    vm.runInNewContext(source, { window, URLSearchParams }, { timeout: 500, filename: 'admission-casework.js' });
    window.createAdmissionCasework({ node, task, notify() {}, request: async (...args) => {
        calls.push(args); return request(...args);
    } }).updates(root);
    const descendants = element => [element, ...element.children.flatMap(descendants)];
    const button = name => {
        const element = descendants(root).find(item => item.tag === 'button' && item.textContent.trim() === name);
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

test('personal deletion posts the displayed revision and removes only the notice from the reader', async () => {
    let deleted = false;
    const f = fixture(async (url, method, body) => {
        if (url.endsWith('/delete/')) {
            assert.equal(method, 'POST'); assert.equal(JSON.stringify(body), '{"revision":1}');
            deleted = true; return { deleted: true };
        }
        return deleted ? { items: [], unread_count: 0, next: null } : page;
    });
    await f.flush(); await f.click('Delete update');
    assert.match(f.root.textContent, /Admission update deleted/);
    assert.doesNotMatch(f.root.textContent, /Fictional Arts/);
    assert.equal(f.calls.filter(([, method]) => method === 'POST').length, 1);
    assert.equal(f.calls.some(([url]) => url.includes('/applications/')), false);
    assert.deepEqual(f.errors, []);
});

test('a refused delete clears private cards while a stale delete preserves them for refresh', async () => {
    for (const status of [403, 409]) {
        const f = fixture(async url => {
            if (url.endsWith('/delete/')) throw Object.assign(new Error('Fictional refusal'), { status });
            return page;
        });
        await f.flush(); await f.click('Delete update');
        assert.equal(f.root.textContent.includes('Fictional Arts'), status === 409);
        assert.match(f.root.textContent, status === 403 ? /unavailable for this account/ : /changed or is unavailable/);
    }
});

test('a successful delete remains clear when refreshing the rest of the list fails', async () => {
    let deleted = false;
    const f = fixture(async url => {
        if (url.endsWith('/delete/')) { deleted = true; return { deleted: true }; }
        if (deleted) throw new Error('Fictional list failure.');
        return page;
    });
    await f.flush(); await f.click('Delete update');
    assert.match(f.root.textContent, /Admission update deleted. Refresh updates/);
    assert.doesNotMatch(f.root.textContent, /Fictional Arts/);
    assert.equal(f.errors.length, 1);
});

test('an exact selected notice resolves owner metadata before current guidance and its read confirmation', async () => {
    const selected = '00000000000000000000000001';
    const item = { ...notice, id: selected };
    const f = fixture(async (url, method, body) => {
        if (url.endsWith(`/${selected}/`)) return item;
        if (url.includes('/next-steps/')) return { office: '', next_step: 'Selected owner guidance.', items: [] };
        if (url.endsWith('/read/')) { assert.equal(body.revision, 1); return { unread: false }; }
        return { ...page, items: [] };
    }, `?update=${selected}`);
    await f.flush();
    assert.match(f.root.textContent, /Selected owner guidance/);
    assert.ok(f.calls.findIndex(([url]) => url.endsWith(`/${selected}/`)) <
        f.calls.findIndex(([url]) => url.includes('/next-steps/')));
    assert.deepEqual(f.errors, []);
});

test('refreshing while selected notice metadata is pending prevents a late guidance read', async () => {
    const selected = '00000000000000000000000002';
    let resolveMetadata;
    const f = fixture(async url => url.endsWith(`/${selected}/`)
        ? new Promise(resolve => { resolveMetadata = resolve; }) : page, `?update=${selected}`);
    await new Promise(setImmediate);
    assert.equal(typeof resolveMetadata, 'function');
    await f.click('Refresh updates');
    resolveMetadata({ ...notice, id: selected });
    await f.flush();
    assert.equal(f.calls.some(([url]) => url.includes('/applications/')), false);
    assert.equal(f.calls.some(([, method]) => method === 'POST'), false);
    assert.deepEqual(f.errors, []);
});

test('an inaccessible selected notice clears private cards and never requests application guidance', async () => {
    const selected = '00000000000000000000000003';
    const f = fixture(async url => {
        if (url.endsWith(`/${selected}/`)) throw Object.assign(new Error('Fictional denial.'), { status: 403 });
        return page;
    }, `?update=${selected}`);
    await f.flush();
    assert.match(f.root.textContent, /unavailable for this account/);
    assert.doesNotMatch(f.root.textContent, /Fictional Arts/);
    assert.equal(f.calls.some(([url]) => url.includes('/applications/')), false);
    assert.equal(f.errors.length, 1);
});
