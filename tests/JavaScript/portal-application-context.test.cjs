'use strict';
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const source = readFileSync(path.join(__dirname, '../../public/assets/js/portal.js'), 'utf8');
const person = '01K00000000000000000000003';

// Run the actual startup code, stopping at rendering; no browser, server or network.
async function startup(section, response) {
    class Node {
        constructor(text = '') { this.text = text; this.children = []; }
        get textContent() { return this.text + this.children.map(child => child.textContent).join(''); }
        set textContent(value) { this.text = value; this.children = []; }
        setAttribute() {}
        focus() {}
        contains() { return false; }
        append(...children) { this.children.push(...children); }
        replaceChildren(...children) { this.children = children; }
    }
    const config = new Node(JSON.stringify({ signedIn: true, section }));
    const error = new Node(), content = new Node(), requests = [];
    let rendering = false;
    const document = {
        querySelector: selector => ({ '#campus-config': config, '#campus-error': error,
            '#campus-service-content': content })[selector] || null,
        createElement: () => new Node(), createTextNode: text => new Node(text),
        createDocumentFragment() { rendering = true; throw new Error('Stopped before DOM rendering.'); },
    };
    vm.runInNewContext(source, { document, Node, window: { addEventListener() {} },
        fetch: async (url, options) => {
            requests.push({ url, options });
            return { ok: true, status: 200, json: async () => response };
        } }, { filename: 'portal.js', timeout: 500 });
    await new Promise(setImmediate);
    return { requests, rendering, error: error.textContent };
}

test('opening applications requests only the owner application context, even before the first submission', async () => {
    const result = await startup('applications', { person_id: person, items: [] });
    assert.equal(result.rendering, true);
    assert.deepEqual(result.requests.map(request => request.url), ['/portal/api/applications/']);
    const options = result.requests[0].options;
    assert.equal(options.method, 'GET');
    assert.equal(options.credentials, 'same-origin');
    assert.equal(options.cache, 'no-store');
    assert.equal(options.redirect, 'error');
    assert.equal(options.headers.Accept, 'application/json');
});

test('missing person context or an invalid application list stops the form from opening', async () => {
    for (const response of [null, { items: [] }, { person_id: '', items: [] }, { person_id: person, items: null }]) {
        const result = await startup('applications', response);
        assert.equal(result.rendering, false);
        assert.deepEqual(result.requests.map(request => request.url), ['/portal/api/applications/']);
        assert.equal(result.error, 'Application status is unavailable. Please reload and try again.');
    }
});

test('the campus overview still loads its full service context', async () => {
    const result = await startup('overview', {});
    assert.equal(result.rendering, true);
    assert.deepEqual(result.requests.map(request => request.url), ['/portal/api/me/']);
});


test('admissions updates starts without loading the full student overview', async () => {
    const result = await startup('admissionsupdates', {});
    assert.equal(result.rendering, true);
    assert.deepEqual(result.requests, []);
});
