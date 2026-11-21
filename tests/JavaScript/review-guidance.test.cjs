'use strict';
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');
const { node, walk } = require('./support/elements.cjs');
const window = {};
vm.runInNewContext(readFileSync('public/assets/js/review-guidance.js', 'utf8'), { window },
    { filename: 'review-guidance.js', timeout: 500 });
const guides = window.capturedReviewGuidance;
const plain = value => JSON.parse(JSON.stringify(value));
const student = () => ({ issues: [], prerequisites: { status: 'met' }, corequisites: { status: 'met' },
    schedule: { status: 'clear', conflict_count: 0, unpublished_count: 0, blocked: false },
    charges: { total_minor: 0, problems: [] }, readiness: [], enrollment_id: null });
const text = value => JSON.stringify(value);

test('registration guide keeps existing and closed attempts distinct and omits private source content', () => {
    const rows = [{ ...student(), enrollment_id: 'existing', enrollment_status: 'A' },
        { ...student(), enrollment_id: 'closed', enrollment_status: 'D', given_name: 'Private person',
            review_token: 'private-proof', issues: ['Private restriction explanation'] }];
    const source = { students: rows, section: {}, reason: 'Private reason' };
    const before = text(source);
    const result = guides.registration(source);
    assert.match(text(result), /Keep existing registrations/);
    assert.match(text(result), /closed-attempt review/);
    assert.match(text(result), /Resolve registration checks/);
    assert.doesNotMatch(text(result), /Private|private-proof|existing"|closed"/);
    assert.equal(text(source), before);
    assert.equal(result.can_confirm, undefined);
});

test('registration warnings, blockers, missing schedules, prerequisites and pricing remain distinct', () => {
    const person = student();
    person.prerequisites.status = 'missing';
    person.corequisites.status = 'missing';
    person.charges.total_minor = null;
    person.schedule = { status: 'not_fully_checked', unpublished_count: 1, conflict_count: 1, blocked: true };
    const blocked = guides.registration({ students: [person] });
    assert.match(text(blocked), /Resolve overlapping meetings/);
    assert.match(text(blocked), /unpublished or unavailable/);
    assert.match(text(blocked), /prerequisite evidence/);
    assert.match(text(blocked), /companion courses/);
    assert.match(text(blocked), /Resolve pricing first/);
    person.schedule.blocked = false;
    assert.match(text(guides.registration({ students: [person] })), /Review timetable warnings/);
    person.schedule = null;
    assert.match(text(guides.registration({ students: [person] })), /missing schedule is not proof/);
});

test('planned schedule guidance uses the combined reviewed overlaps and never changes inputs', () => {
    const source = { sections: [{ student: student() }, { student: student() }],
        planned_schedule: { blocked: true, conflicts: [{}], unpublished_count: 0 } };
    const before = text(source);
    assert.match(text(guides.registration(source, true)), /Resolve overlapping meetings/);
    assert.equal(text(source), before);
    assert.match(text(guides.registration({ sections: [] }, true)), /incomplete/);
    assert.match(text(guides.registration({ students: Array(26).fill(student()) })), /incomplete/);
});

test('admissions guide separates acceptance, offer response, matriculation and later student decisions', () => {
    const app = status => guides.admissions({ application: { status }, private_notes: 'Secret staff notes' });
    assert.match(text(app('submitted')), /not itself create the student record/);
    assert.match(text(app('accepted')), /Applicant acceptance may be required/);
    assert.match(text(app('matriculated')), /same|parent identity/);
    assert.match(text(app('withdrawn')), /not an existing student/);
    assert.match(text(app('rejected')), /earlier decision/);
    assert.match(text(app('unknown')), /incomplete/);
    assert.doesNotMatch(text(app('submitted')), /Secret staff/);
});

test('meeting plan guide preserves pending, unassigned, warning, blocked and unchanged meanings', () => {
    const source = { working: { meetings: [{}] }, changed: true, pending_booking_ids: ['private-booking'],
        faculty_assessment: { status: 'unassigned', unassigned_meetings: 1, pairs: 1, blocked: true } };
    const before = text(source);
    const result = text(guides.meetingPlan(source));
    assert.match(result, /pending meeting requests/);
    assert.match(result, /Review faculty coverage/);
    assert.match(result, /Resolve faculty overlaps/);
    assert.doesNotMatch(result, /private-booking/);
    assert.equal(text(source), before);
    source.faculty_assessment.blocked = false;
    assert.match(text(guides.meetingPlan(source)), /Acknowledge faculty warnings/);
    source.changed = false;
    assert.match(text(guides.meetingPlan(source)), /already matches/);
    assert.match(text(guides.meetingPlan({ definition: { meetings: [] } }, true)), /empty schedule/);
    assert.match(text(guides.meetingPlan(null)), /incomplete/);
});

test('renderer provides native disclosure, text-only steps and no implicit actions', () => {
    let actions = 0;
    const value = { title: '<img src=x onerror=alert(1)>', steps: [{ title: 'Step', detail: '<script>secret</script>', target: 'details' }] };
    const view = window.createReviewGuidance({ node }, value,
        { details: { label: 'Show details', find: () => { actions++; return null; } } });
    assert.equal(view.tag, 'details');
    assert.equal(actions, 0);
    assert.equal(walk(view).filter(item => ['script', 'img'].includes(item.tag)).length, 0);
    view.isConnected = false;
    walk(view).find(item => item.tag === 'button').events.click();
    assert.equal(actions, 0);
    assert.deepEqual(plain(guides.registration(null)).steps.length, 1);
});
