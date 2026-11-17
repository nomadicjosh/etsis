'use strict';
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

// Load the actual portal script with no page, server, credentials or browser process.
const context = { window: { addEventListener() {} }, document: { querySelector: () => null },
    fetch: () => { throw new Error('Progress guidance must not make a request.'); } };
vm.runInNewContext(readFileSync(path.join(__dirname, '../../public/assets/js/portal.js'), 'utf8'),
    context, { filename: 'portal.js', timeout: 500 });
const progress = context.window.campusApplicationProgress;

test('each supported application status has an explicit next step', () => {
    const domain = readFileSync(path.join(__dirname, '../../src/Domain/Admissions/Enum/ApplicationStatus.php'), 'utf8');
    const statuses = [...domain.matchAll(/case \w+ = '([^']+)'/g)].map(match => match[1]);
    assert.equal(statuses.length, 5);
    for (const status of statuses) {
        const result = progress(status);
        assert.notEqual(result.label, 'Status unavailable');
        assert.ok(result.next.length > 0);
        assert.ok(Object.isFrozen(result));
    }
    assert.equal(progress('rejected').label, 'Not accepted');
    assert.equal(progress('matriculated').label, 'Student record linked');
});

test('acceptance does not promise access to registration or create an actionable student shortcut', () => {
    assert.match(progress('accepted').next, /Admission offer to review and respond/);
    assert.doesNotMatch(progress('accepted').next, /created|eligible|registered|enrolled/i);
    for (const status of ['submitted', 'accepted', 'rejected', 'withdrawn']) assert.equal(progress(status).href, undefined);
    assert.equal(progress('matriculated').href, '/portal/student/');
    assert.doesNotMatch(progress('matriculated').next, /eligible|registered|enrolled/i);
});

test('unknown or prototype-like statuses receive neutral guidance without echoing untrusted data', () => {
    for (const status of ['constructor', '__proto__', '<img src=x>', '', null, 1, {}]) {
        const result = progress(status);
        assert.equal(result.label, 'Status unavailable');
        assert.equal(result.positive, false);
        assert.equal(result.href, undefined);
        assert.equal(result.next, 'Contact admissions to check the status of this application.');
    }
});


test('withdrawn applications give a neutral next step without exposing a reason or student access', () => {
    assert.equal(progress('withdrawn').label, 'Withdrawn');
    assert.equal(progress('withdrawn').positive, false);
    assert.equal(progress('withdrawn').href, undefined);
    assert.match(progress('withdrawn').next, /Contact admissions/);
});
