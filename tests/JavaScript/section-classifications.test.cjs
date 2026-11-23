'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs'), vm = require('node:vm');
const { node: element, walk } = require('./support/elements.cjs');
const node = (...args) => {
    const value = element(...args);
    value.classList = { add: (...tokens) => value.setAttribute('class',
        [...new Set([...(value.getAttribute('class') || '').split(/\s+/u).filter(Boolean), ...tokens])].join(' ')) };
    return value;
};
async function fixture(section = true) {
    const window = {}, body = node('div'), calls = [], publications = [];
    vm.runInNewContext(fs.readFileSync('public/assets/js/workspace-classifications.js', 'utf8'), { window }, { timeout: 500 });
    const campus = { id: 'campus', code: 'MAIN', title: '<img> Fictional Campus', active: true };
    const method = { id: 'method', code: 'LEC', title: 'Lecture', active: true };
    let current = { section: { id: 'section', code: 'MATH.101.A' }, course: { code: 'MATH.101', title: 'Fictional Algebra' },
        revision: 'fictional-revision', classifications: section ? { instructional_method: null, campus: null, reporting_group: null } : { department: null, academic_level: null, course_level: null } };
    const ui = window.createWorkspaceClassifications({ node, openDrawer: () => { body.replaceChildren(); return body; },
        badge: value => node('span', {}, value),
        actionMenu: (label, entries) => node('div', {}, node('button', {}, label), ...entries),
        task: run => run(), publications: id => publications.push(id), sectionPublications: id => publications.push(id),
        request: async (path, verb, data) => {
            calls.push({ path, verb, data });
            if (path.includes('/page/')) return { items: path.includes('/campus/') ? [campus] : [method], next_after: null };
            if (verb === 'POST') { current = { ...current, classifications: { instructional_method: data.instructional_method_id ? method : null, campus: data.campus_id ? campus : null, reporting_group: null } }; return {}; }
            return current;
        }, buildForm: async () => node('form'), formData: () => ({}),
    });
    await (section ? ui.section('section') : ui.course('course'));
    const find = label => walk(body).find(e => e.tag === 'button' && e.textContent === label);
    const click = async label => { const button = find(label); assert.ok(button, label); return button.attributes.onclick({ currentTarget: button }); };
    return { ui, body, calls, publications, find, click };
}
test('section draft choices survive searches until one combined save, then publication becomes available', async () => {
    const f = await fixture();
    assert.equal(f.find('Save section setup').disabled, true);
    await f.click('Choose instructional method'); await f.click('Choose LEC');
    await f.click('Choose campus/site'); await f.click('Choose MAIN');
    assert.match(f.body.textContent, /Lecture/); assert.match(f.body.textContent, /<img> Fictional Campus/);
    assert.equal(walk(f.body).some(e => e.tag === 'img'), false);
    assert.equal(f.find('Review publication').disabled, true);
    assert.equal(f.calls.filter(c => c.verb === 'POST' && !c.path.includes('/page/')).length, 0);
    await f.click('Save section setup');
    const save = f.calls.find(c => c.verb === 'POST' && !c.path.includes('/page/'));
    assert.equal(save.data.instructional_method_id, 'method'); assert.equal(save.data.campus_id, 'campus');
    assert.equal(save.data.revision, 'fictional-revision');
    assert.match(f.body.textContent, /Section setup saved/);
    assert.equal(f.find('Review publication').disabled, false);
    await f.click('Review publication'); assert.deepEqual(f.publications, ['section']);
});
test('reference setup presents section categories without requiring campus or room selection', async () => {
    const f = await fixture(); await f.ui.library('campus');
    assert.ok(f.find('Instructional methods')); assert.ok(f.find('Campuses & sites')); assert.ok(f.find('Reporting groups'));
    assert.equal(f.find('Departments'), undefined);
    assert.match(f.body.textContent, /leave it blank when it does not apply/);
});
test('course assignments continue through the same shared editor with course-specific categories', async () => {
    const f = await fixture(false);
    assert.ok(f.find('Choose department')); assert.ok(f.find('Choose academic level')); assert.ok(f.find('Choose course level'));
    assert.equal(f.find('Choose campus/site'), undefined);
    assert.equal(f.find('Save draft classifications').disabled, true);
    await f.click('Review course publication'); assert.deepEqual(f.publications, ['course']);
});
