const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
class Multipart {}
function transport(file, status = 200) {
    const source = fs.readFileSync(`public/assets/js/${file}.js`, 'utf8');
    const start = source.indexOf(file === 'workspace' ? '    async function send(' : '    async function request(');
    const end = source.indexOf(file === 'workspace' ? '    async function request(' : '    function notice(', start + 1);
    assert.ok(start >= 0 && end > start);
    const calls = [], redirects = [];
    const context = { window: { location: { assign: url => redirects.push(url) } }, FormData: Multipart, URLSearchParams,
        csrf: () => 'fictional-csrf', one: () => ({ value: 'fictional-csrf' }),
        config: { login_url: '/custom-login/', loginUrl: '/custom-login/' }, signInPending: false, pageLeaving: false,
        fetch: async (...args) => { calls.push(args); return { status, ok: status === 200, json: async () => ({ ok: true }) }; } };
    vm.runInNewContext(source.slice(start, end) + `\nwindow.transport = ${file === 'workspace' ? 'send' : 'request'};`, context, { timeout: 500 });
    return { request: context.window.transport, calls, redirects };
}

test('private file uploads retain browser multipart boundaries and framework CSRF headers', async () => {
    for (const source of ['workspace', 'portal']) {
        const f = transport(source), body = new Multipart();
        await f.request('/private-documents/', 'POST', body);
        const options = f.calls[0][1];
        assert.equal(options.body, body); assert.equal(options.headers['Content-Type'], undefined);
        assert.equal(options.headers['X-CSRF-Token'], 'fictional-csrf');
        assert.equal(options.credentials, 'same-origin'); assert.equal(options.cache, 'no-store');
        assert.equal(options.redirect, 'error');
    }
});

test('ordinary typed workspace mutations retain their existing form-encoded JSON contract', async () => {
    for (const source of ['workspace', 'portal']) {
        const f = transport(source);
        await f.request('/private-change/', 'POST', { version: 3, held: true, optional: null });
        const options = f.calls[0][1];
        assert.match(options.headers['Content-Type'], /application\/x-www-form-urlencoded/);
        assert.deepEqual(JSON.parse(new URLSearchParams(options.body).get('_workspace_json')),
            { version: 3, held: true, optional: null });
    }
});

test('expired sign-in during multipart upload still redirects through the configured login route', async () => {
    for (const source of ['workspace', 'portal']) {
        const f = transport(source, 401);
        await assert.rejects(f.request('/private-documents/', 'POST', new Multipart()), /Sign in again/);
        assert.deepEqual(f.redirects, ['/custom-login/']);
    }
});
