const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = number => String(number).padStart(26, '0');
const person = id(3), first = id(11), second = id(12), direct = id(13), receipt = id(21), otherReceipt = id(22);
const program = '01K00000000000000000000001', term = '01K00000000000000000000002', formId = id(31);
const fields = [
    { key: 'statement', label: 'Your original plans', type: 'textarea', required: true },
    { key: 'previous', label: 'Previously attended?', type: 'checkbox' },
    { key: 'credits', label: 'Other credits', type: 'number' },
];
const layout = [{ key: 'plans', label: 'Your next chapter', help: 'A fictional application.', columns: [['statement'], ['previous', 'credits']] }];
const row = (application, submission, code, title, status = 'submitted') => ({
    id: application, person_id: person, program_id: id(99), entry_term_id: id(98), status,
    program_code: code, program_title: title, term_code: '26/FA', submission_id: submission,
});
const record = (application, text) => ({ application_id: application,
    form: { id: formId, name: application === first ? 'Original Arts application' : 'Original Science application', fields, layout },
    answers: { statement: text, previous: false, credits: 0 },
});

async function fixture(page, { applications = [
    row(first, receipt, 'DIP.ART', 'Studio Arts', 'accepted'),
    row(second, otherReceipt, 'BS.SCI', 'Applied Science', 'matriculated'),
    row(direct, null, 'BA.HIST', 'History'),
], answer = null, refresh = null, education = null, nextSteps = null, profileRequests = null, verification = null } = {}) {
    const reads = [], writes = [], errors = [], overviewReads = [], statusReads = [];
    const me = { person: { id: person }, applications, students: [], contact: {}, accounts: [], news: [] };
    page.on('pageerror', error => errors.push(error.message));
    await page.route(/\/(?:portal\/api|sis\/(?:forms|form-submissions))\//, async route => {
        const req = route.request(), path = new URL(req.url()).pathname;
        let json = {};
        if (path.startsWith('/portal/api/email-verification/') && verification) return verification(route);
        if (path === '/portal/api/me/') { overviewReads.push(path); json = me; }
        else if (/^\/portal\/api\/(?:[a-z]+-updates|announcements)\/$/.test(path)) json = { items: [], next: null };
        else if (path === '/portal/api/notification-preferences/') json = {
            preferences: { sound: false, sound_id: 'tone', keep_visible: false }, revision: 0, person_id: person, own: true,
        };
        else if (/^\/portal\/api\/applications\/[^/]+\/next-steps\/$/.test(path)) {
            if (nextSteps) return nextSteps(route);
            json = { office: '', next_step: '', items: [] };
        }
        else if (path.startsWith('/portal/api/profile-update-requests/')) {
            if (profileRequests) return profileRequests(route);
            json={items:[],next_after:null,new_id:id(90),areas:{education:'Educational background'}};
        }
        else if (path === '/portal/api/education/') {
            if (education) return education(route);
            json = { entries: [], version: 0 };
        }
        else if (path === '/portal/api/applications/') {
            expect(req.method()).toBe('GET');
            statusReads.push(path);
            if (refresh && statusReads.length > 1) return refresh(route, statusReads.length - 1, me);
            json = { person_id: person, items: me.applications };
        }
        else if (/^\/portal\/api\/applications\/[^/]+\/answers\/$/.test(path)) {
            expect(req.method()).toBe('GET');
            if (answer) return answer(route, path, reads.length);
            const application=path.split('/')[4];
            json=record(application, application===first
                ? '<img src=x onerror=alert(1)>\nFictional original plan.' : 'Fictional second plan.');
        }
        else if (path.includes('/sis/form-submissions/')) {
            expect(req.method()).toBe('GET');
            expect(req.headers()['accept']).toBe('application/json');
            reads.push(path);
            if (answer) return answer(route, path, reads.length);
            json = record(path.includes(receipt) ? first : second,
                path.includes(receipt) ? '<img src=x onerror=alert(1)>\nFictional original plan.' : 'Fictional second plan.');
        } else if (path === `/sis/forms/programs/${program}/active/`) {
            json = { id: formId, name: 'Current application', submission_id: receipt,
                fields: [{ key: 'new_note', label: 'New application note', type: 'text', required: true }] };
        } else if (path === `/sis/forms/${formId}/submissions/`) {
            expect(req.headers()['x-csrf-token']).toBe('fictional-test-token');
            writes.push(JSON.parse(new URLSearchParams(req.postData()).get('_workspace_json')));
            me.applications.push(row(first, receipt, 'BS.CS', 'Computer Science'));
            json = { id: first, status: 'submitted' };
        } else throw new Error(`Unexpected request: ${path}`);
        await route.fulfill({ json, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(`/portal/apply/${verification ? '?verify=1' : ''}`);
    await expect(page.getByRole('heading', { name: 'Make this path your own' })).toBeVisible();
    return { reads, writes, errors, overviewReads, statusReads };
}
async function openNewApplication(page) {
    await page.getByLabel('Choose your academic program').selectOption(program);
    await page.getByLabel('When would you like to begin?').selectOption(term);
    await page.getByRole('button', { name: 'Continue to your application' }).click();
    await page.getByRole('textbox', { name: 'New application note', exact: true }).fill('Unsaved fictional new answer.');
}
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => node.target) }))).toEqual([]);
}

test('owners open retained answers on demand without losing a new application or storing private answers', async ({ page }) => {
    const { reads, writes, errors } = await fixture(page);
    const list = page.getByRole('table', { name: 'Your admissions applications' });
    await expect(list).toContainText('DIP.ART · Studio Arts');
    await expect(list).toContainText('No form answers');
    await expect(list.getByRole('row').filter({ hasText: 'DIP.ART' })).toContainText('your next steps');
    const studentRow = list.getByRole('row').filter({ hasText: 'BS.SCI' });
    await expect(studentRow).toContainText('Student record linked');
    await expect(studentRow.getByRole('link', { name: 'My campus', exact: true })).toHaveAttribute('href', '/portal/student/');
    expect(reads).toHaveLength(0);
    await openNewApplication(page);
    const trigger = list.getByRole('button', { name: /View answers for DIP.ART/ });
    await trigger.focus(); await page.keyboard.press('Enter');
    const viewer = page.locator('.campus-application-answers');
    await expect(viewer.getByRole('heading', { name: 'Original Arts application' }).first()).toBeFocused();
    await expect(viewer.getByRole('status').filter({hasText:'Your submitted answers are ready.'})).toHaveText('Your submitted answers are ready.');
    await expect(viewer.getByRole('group', { name: 'Your next chapter' })).toBeVisible();
    await expect(viewer.locator('[data-answer-key="previous"] dd')).toHaveText('No');
    await expect(viewer.locator('[data-answer-key="credits"] dd')).toHaveText('0');
    await expect(viewer).toContainText('<img src=x onerror=alert(1)>');
    await expect(viewer.locator('img, input, textarea')).toHaveCount(0);
    await expect(page.getByRole('textbox', { name: 'New application note', exact: true })).toHaveValue('Unsaved fictional new answer.');
    await accessible(page);
    await page.screenshot({ path: 'test-results/portal-submitted-application-desktop.png', fullPage: true });
    await page.setViewportSize({ width: 320, height: 800 });
    const reflow = await page.evaluate(() => ({ page: document.documentElement.scrollWidth, viewport: innerWidth,
        boxes: [...document.querySelectorAll('.campus-student-main, .campus-surface, .table-responsive, .campus-application-answers, .application-fields, .application-container, .application-review-title')]
            .map(element => ({ class: element.className, width: element.getBoundingClientRect().width, right: element.getBoundingClientRect().right })),
    }));
    expect(reflow.page, JSON.stringify(reflow)).toBeLessThanOrEqual(reflow.viewport);
    await accessible(page);
    await viewer.scrollIntoViewIfNeeded();
    await page.screenshot({ path: 'test-results/portal-submitted-application-mobile.png' });
    await viewer.getByRole('button', { name: 'Close answers' }).click();
    await expect(trigger).toBeFocused();
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    await expect(viewer).toBeEmpty();
    await expect(viewer).toBeHidden();
    expect(await page.evaluate(() => ({ local: localStorage.length, session: sessionStorage.length }))).toEqual({ local: 0, session: 0 });
    expect(reads).toHaveLength(1); expect(writes).toHaveLength(0); expect(errors).toEqual([]);
});

test('a failed answer read has a local retry and keeps the applicant’s unsubmitted answers', async ({ page }) => {
    const { reads, errors } = await fixture(page, { answer: async (route, path, count) => {
        if (count === 1) return route.fulfill({ status: 503, json: { error: 'Fictional private upstream detail' } });
        await route.fulfill({ json: record(first, 'Fictional original answer.') });
    } });
    await openNewApplication(page);
    await page.getByRole('button', { name: /View answers for DIP.ART/ }).click();
    const viewer = page.locator('.campus-application-answers');
    await expect(viewer.getByRole('alert')).toBeFocused();
    await expect(viewer).toContainText('We couldn’t load these answers');
    await expect(page.locator('body')).not.toContainText('Fictional private upstream detail');
    await viewer.getByRole('button', { name: 'Try again' }).click();
    await expect(viewer).toContainText('Fictional original answer.');
    await expect(page.getByRole('textbox', { name: 'New application note', exact: true })).toHaveValue('Unsaved fictional new answer.');
    expect(reads).toHaveLength(2); expect(errors).toEqual([]);
});

test('closing or selecting another application prevents late reads from restoring old private answers', async ({ page }) => {
    const gates = [];
    const { reads, errors } = await fixture(page, { answer: async (route, path) => {
        if (path.includes(receipt)) await new Promise(resolve => gates.push(resolve));
        await route.fulfill({ json: path.includes(receipt) ? record(first, 'Fictional late first answer.') : record(second, 'Fictional second answer.') });
    } });
    const firstButton = page.getByRole('button', { name: /View answers for DIP.ART/ });
    const viewer = page.locator('.campus-application-answers');
    await firstButton.click(); await expect.poll(() => gates.length).toBe(1);
    await viewer.getByRole('button', { name: 'Close answers' }).click();
    await expect(firstButton).toBeFocused();
    const closed = page.waitForResponse(`**/sis/form-submissions/${receipt}/`);
    gates[0](); await (await closed).finished();
    await expect(viewer).toBeEmpty(); await expect(viewer).toBeHidden();
    await firstButton.click(); await expect.poll(() => gates.length).toBe(2);
    await page.getByRole('button', { name: /View answers for BS.SCI/ }).click();
    await expect(viewer).toContainText('Fictional second answer.');
    const late = page.waitForResponse(`**/sis/form-submissions/${receipt}/`);
    gates[1](); await (await late).finished();
    await expect(viewer).not.toContainText('Fictional late first answer.');
    await expect(viewer.getByRole('heading', { name: 'Original Science application' }).first()).toBeVisible();
    await expect(firstButton).toHaveAttribute('aria-expanded', 'false');
    expect(reads).toHaveLength(3); expect(errors).toEqual([]);
});

test('an inconsistent application reference never displays a different submission', async ({ page }) => {
    await fixture(page, { answer: route => route.fulfill({ json: record(second, 'Fictional mismatched private answer.') }) });
    await page.getByRole('button', { name: /View answers for DIP.ART/ }).click();
    const viewer = page.locator('.campus-application-answers');
    await expect(viewer.getByRole('alert')).toBeVisible();
    await expect(viewer).not.toContainText('Fictional mismatched private answer.');
    await expect(viewer.locator('.application-answer-review')).toHaveCount(0);
});

test('a newly submitted application becomes available for answer review without a page reload', async ({ page }) => {
    const { reads, writes, errors } = await fixture(page, { applications: [],
        answer: route => route.fulfill({ json: record(first, 'Unsaved fictional new answer.') }) });
    await expect(page.getByRole('heading', { name: 'Your applications', exact: true })).toHaveCount(0);
    await openNewApplication(page);
    await page.getByRole('button', { name: 'Review answers', exact: true }).click();
    await page.getByRole('button', { name: 'Submit application', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Your applications', exact: true })).toBeVisible();
    expect(writes).toHaveLength(1); expect(reads).toHaveLength(0);
    await page.getByRole('button', { name: /View answers for BS.CS/ }).click();
    await expect(page.locator('.campus-application-answers')).toContainText('Unsaved fictional new answer.');
    expect(writes[0].submission_id).toBe(receipt); expect(errors).toEqual([]);
});

test('status refresh preserves an unfinished application and avoids another full overview read', async ({ page }) => {
    let release;
    const state = await fixture(page, { refresh: async route => {
        await new Promise(resolve => { release = resolve; });
        await route.fulfill({ json: { person_id: person, items: [row(first, receipt, 'DIP.ART', 'Studio Arts', 'rejected')] } });
    } });
    await openNewApplication(page);
    const refresh = page.getByRole('button', { name: 'Refresh status', exact: true });
    await refresh.click();
    await expect(refresh).toBeDisabled();
    await expect(refresh).toHaveAttribute('aria-busy', 'true');
    await expect(page.getByRole('status').filter({ hasText: 'Checking your application status' })).toBeVisible();
    await expect.poll(() => typeof release).toBe('function');
    release();
    await expect(page.getByRole('table', { name: 'Your admissions applications' })).toContainText('Not accepted');
    await expect(page.getByRole('textbox', { name: 'New application note', exact: true })).toHaveValue('Unsaved fictional new answer.');
    await expect(refresh).toBeEnabled();
    await expect(page.getByRole('status').filter({ hasText: 'Your application status is up to date.' })).toBeVisible();
    expect(state.overviewReads).toHaveLength(0); expect(state.statusReads).toHaveLength(2);
    expect(state.reads).toEqual([]); expect(state.writes).toEqual([]); expect(state.errors).toEqual([]);
});

test('a failed status refresh retains previous results and can be retried', async ({ page }) => {
    const state = await fixture(page, { refresh: (route, attempt, me) => attempt === 1
        ? route.fulfill({ status: 503, json: { error: 'Fictional unavailable service.' } })
        : route.fulfill({ json: { person_id: person, items: me.applications } }) });
    await openNewApplication(page);
    const refresh = page.getByRole('button', { name: 'Refresh status', exact: true });
    await refresh.click();
    await expect(page.getByRole('status').filter({ hasText: 'Status could not be refreshed.' })).toBeVisible();
    await expect(page.getByRole('table', { name: 'Your admissions applications' })).toContainText('DIP.ART');
    await expect(page.getByRole('textbox', { name: 'New application note', exact: true })).toHaveValue('Unsaved fictional new answer.');
    await refresh.click();
    await expect(page.getByRole('status').filter({ hasText: 'Your application status is up to date.' })).toBeVisible();
    expect(state.statusReads).toHaveLength(3); expect(state.overviewReads).toHaveLength(0);
    expect(state.errors).toEqual([]);
});

test('a delayed status read cannot replace the list refreshed after submission', async ({ page }) => {
    let release;
    const state = await fixture(page, { applications: [], refresh: async (route, attempt, me) => {
        if (attempt === 1) {
            await new Promise(resolve => { release = resolve; });
            return route.fulfill({ json: { person_id: person, items: [] } });
        }
        return route.fulfill({ json: { person_id: person, items: me.applications } });
    } });
    await openNewApplication(page);
    const refresh = page.getByRole('button', { name: 'Refresh status', exact: true });
    await refresh.click();
    await expect(refresh).toBeDisabled();
    await page.getByRole('button', { name: 'Review answers', exact: true }).click();
    await page.getByRole('button', { name: 'Submit application', exact: true }).click();
    await expect(page.getByRole('table', { name: 'Your admissions applications' })).toContainText('BS.CS');
    await expect.poll(() => typeof release).toBe('function');
    release();
    await expect(refresh).toBeEnabled();
    await expect(page.getByRole('table', { name: 'Your admissions applications' })).toContainText('BS.CS');
    expect(state.statusReads).toHaveLength(3); expect(state.overviewReads).toHaveLength(0);
    expect(state.writes).toHaveLength(1); expect(state.errors).toEqual([]);
});

test('a failed follow-up status read keeps a successful submission confirmed', async ({ page }) => {
    const state = await fixture(page, { applications: [], refresh: route =>
        route.fulfill({ status: 503, json: { error: 'Fictional unavailable status read.' } }) });
    await openNewApplication(page);
    await page.getByRole('button', { name: 'Review answers', exact: true }).click();
    await page.getByRole('button', { name: 'Submit application', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'You’re one step closer.', exact: true })).toBeVisible();
    await expect(page.getByRole('status').filter({ hasText: 'Status could not be refreshed.' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Submit application', exact: true })).toHaveCount(0);
    expect(state.writes).toHaveLength(1); expect(state.statusReads).toHaveLength(2);
    expect(state.overviewReads).toHaveLength(0); expect(state.errors).toEqual([]);
});

test('an applicant responds to educational-background feedback without losing an application draft', async ({ page }) => {
    const updates = [];
    let background = { version: 2, entries: [{ key: 'college_one', institution: 'Fictional College',
        institution_code: '', start_date: '', end_date: '', major: '', degree: '', conferred_date: '',
        gpa: '7.5', gpa_max: '10', review_status: 'changes_requested', feedback: 'Please provide attendance dates.' }] };
    const state = await fixture(page, { education: route => {
        const request = route.request();
        if (request.method() === 'POST') {
            expect(request.headers()['x-csrf-token']).toBe('fictional-test-token');
            const input = JSON.parse(new URLSearchParams(request.postData()).get('_workspace_json'));
            updates.push(input);
            background = { version: 3, entries: input.entries.map(entry => ({ ...entry, review_status: 'unreviewed', feedback: '' })) };
            return route.fulfill({ json: { status: 'saved' } });
        }
        return route.fulfill({ json: background, headers: { 'Cache-Control': 'private, no-store' } });
    } });
    await openNewApplication(page);
    await page.getByRole('button', { name: 'Institutions attended', exact: true }).click();
    const editor = page.getByRole('region', { name: 'Institutions attended editor' });
    await expect(editor).toContainText('Fictional College');
    await editor.locator('summary').filter({ hasText: 'Fictional College' }).click();
    await expect(editor).toContainText('Please provide attendance dates.');
    await editor.getByLabel('Attended from', { exact: true }).fill('2020-08-01');
    await editor.getByLabel('Attended through', { exact: true }).fill('2024-06-30');
    await editor.getByLabel('Reason for update', { exact: true }).fill('Provided the requested attendance dates.');
    await editor.getByRole('button', { name: 'Save background', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Close institutions', exact: true })).toBeFocused();
    await expect(editor.locator('fieldset')).toContainText('Not yet reviewed');
    expect(updates).toHaveLength(1); expect(updates[0].version).toBe(2);
    expect(updates[0].entries[0].review_status).toBeUndefined();
    expect(updates[0].entries[0].feedback).toBeUndefined();
    expect(updates[0].entries[0].gpa).toBe('7.5'); expect(updates[0].entries[0].gpa_max).toBe('10');
    await expect(page.getByRole('textbox', { name: 'New application note', exact: true })).toHaveValue('Unsaved fictional new answer.');
    await expect(editor.getByText('Review entry', { exact: true })).toHaveCount(0);
    await accessible(page);
    expect(state.errors).toEqual([]); expect(state.overviewReads).toHaveLength(0);
});

test('applicant next steps load on demand without replacing an unfinished application', async ({ page }) => {
    const requests = [];
    const state = await fixture(page, { nextSteps: route => {
        requests.push(route.request().url());
        return route.fulfill({ json: { office: 'Admissions', next_step: 'Arrange your previous transcript.', items: [
            { label: 'Previous transcript', instructions: '<img src=x onerror=alert(1)> Contact admissions for delivery.',
                needed: true, status: 'pending', due_on: '2026-12-01' },
        ] } });
    } });
    expect(requests).toEqual([]);
    await openNewApplication(page);
    await page.getByRole('button', { name: /Next steps for DIP.ART/ }).click();
    const steps = page.getByRole('region', { name: 'Application next steps', exact: true });
    await expect(steps).toContainText('Arrange your previous transcript.');
    await expect(steps).toContainText('Requested for review');
    await expect(steps).toContainText('<img src=x onerror=alert(1)>');
    await expect(steps.locator('img')).toHaveCount(0);
    await expect(page.getByRole('textbox', { name: 'New application note', exact: true })).toHaveValue('Unsaved fictional new answer.');
    expect(requests).toHaveLength(1); expect(state.writes).toEqual([]); expect(state.errors).toEqual([]);
    await accessible(page);
});


test('applicant education follow-up stays beside an unfinished application and uses the existing editor', async ({page}) => {
    const educationWrites=[], replies=[];
    let responded=false;
    let background={version:1,entries:[{key:'college_one',institution:'Fictional College',
        start_date:'2020-08-01',end_date:'2024-05-01',review_status:'changes_requested',feedback:'Check attendance.'}]};
    const state=await fixture(page,{
        education:async route=>{
            const req=route.request();
            if (req.method()==='POST') {
                const data=JSON.parse(new URLSearchParams(req.postData()).get('_workspace_json'));
                educationWrites.push(data);background={version:2,entries:data.entries.map(entry=>({...entry,review_status:'unreviewed',feedback:''}))};
                return route.fulfill({json:{status:'saved'}});
            }
            return route.fulfill({json:background});
        },
        profileRequests:async route=>{
            const req=route.request();
            if (req.method()==='POST') {
                expect(req.headers()['x-csrf-token']).toBe('fictional-test-token');
                replies.push(JSON.parse(new URLSearchParams(req.postData()).get('_workspace_json')));
                responded=true;return route.fulfill({json:{status:'saved'}});
            }
            expect(new URL(req.url()).searchParams.get('area')).toBe('education');
            return route.fulfill({json:{items:[{id:id(91),area:'education',status:responded?'responded':'requested',
                version:responded?1:0,instructions:'Please correct the attendance date.',response:responded?'Dates corrected.':null,
                created_at:'2026-11-21T12:00:00Z'}],next_after:null,new_id:id(92),areas:{education:'Educational background'}}});
        },
    });
    await openNewApplication(page);
    await page.getByRole('button',{name:'Education update requests',exact:true}).click();
    const updates=page.getByRole('region',{name:'Education update requests',exact:true});
    await updates.getByRole('button',{name:'Open educational background',exact:true}).click();
    const editor=page.getByRole('region',{name:'Institutions attended editor',exact:true});
    await editor.getByText('Fictional College',{exact:true}).click();
    await editor.getByLabel('Attended through',{exact:true}).fill('2024-06-01');
    await editor.getByLabel('Reason for update',{exact:true}).fill('Fictional corrected attendance.');
    await editor.getByRole('button',{name:'Save background',exact:true}).click();
    await expect(editor).toContainText('Not yet reviewed');
    await updates.getByLabel('Update for staff',{exact:true}).fill('Dates corrected.');
    await updates.getByRole('button',{name:'Send update for review',exact:true}).click();
    await expect(updates).toContainText('Ready for staff review');
    await expect(page.getByRole('textbox',{name:'New application note',exact:true})).toHaveValue('Unsaved fictional new answer.');
    expect(educationWrites).toHaveLength(1);expect(replies).toHaveLength(1);expect(state.writes).toHaveLength(0);
    await page.setViewportSize({width:320,height:1000});await accessible(page);
    expect(await page.evaluate(()=>localStorage.length+sessionStorage.length)).toBe(0);
    expect(state.errors).toEqual([]);
});


test('identity follow-up beside an application preserves new answers and sends only a review reply', async ({ page }) => {
    const profileWrites = [], profileReads = [];
    let responded = false;
    const f = await fixture(page, { applications: [], profileRequests: async route => {
        const req = route.request(), url = new URL(req.url());
        profileReads.push(url.pathname);
        if (url.pathname.endsWith('/identity/')) return route.fulfill({ json: {
            given_name: 'Fictional <name>', family_name: 'Applicant', institutional_id: 'P-001',
        } });
        if (req.method() === 'POST') {
            profileWrites.push({ data: JSON.parse(new URLSearchParams(req.postData()).get('_workspace_json')),
                csrf: req.headers()['x-csrf-token'] });
            responded = true; return route.fulfill({ json: { status: 'saved' } });
        }
        expect(url.searchParams.get('area')).toBe('identity');
        return route.fulfill({ json: { items: [{ id: id(94), area: 'identity', status: responded ? 'responded' : 'requested',
            version: responded ? 1 : 0, created_at: '2026-11-23T12:00:00Z',
            instructions: 'Check the fictional recorded name.', response: responded ? 'Fictional spelling correction.' : null,
        }], next_after: null, new_id: id(95) } });
    } });
    await openNewApplication(page);
    await page.getByRole('button', { name: 'Identity correction requests', exact: true }).click();
    const panel = page.getByRole('region', { name: 'Identity correction requests', exact: true });
    await panel.getByLabel('Update for staff', { exact: true }).fill('Fictional spelling correction.');
    await panel.getByRole('button', { name: 'Review recorded identity', exact: true }).click();
    await expect(panel).toContainText('Fictional <name>');
    await expect(page.getByRole('textbox', { name: 'New application note', exact: true })).toHaveValue('Unsaved fictional new answer.');
    await panel.getByRole('button', { name: 'Send update for review', exact: true }).click();
    await expect(panel).toContainText('Ready for staff review');
    expect(profileWrites).toEqual([{ data: { version: 0, message: 'Fictional spelling correction.' }, csrf: 'fictional-test-token' }]);
    expect(f.writes).toHaveLength(0);
    await expect(page.getByRole('textbox', { name: 'New application note', exact: true })).toHaveValue('Unsaved fictional new answer.');
    expect(profileReads.filter(path => path.endsWith('/identity/'))).toHaveLength(1);
    await page.getByRole('region', { name: 'Identity correction requests', exact: true }).scrollIntoViewIfNeeded();
    await page.screenshot({ path: '/tmp/etsis-identity-applicant.png' });
    await page.setViewportSize({ width: 320, height: 1000 }); await accessible(page);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});


test('optional email verification preserves an application draft across explicit confirmation in another tab', async ({ page, context }) => {
    let verified = false;
    const calls = [];
    const verification = async route => {
        const request = route.request();
        const path = new URL(request.url()).pathname;
        if (request.method() === 'POST') {
            calls.push(JSON.parse(new URLSearchParams(request.postData()).get('_workspace_json')));
            expect(request.headers()['x-csrf-token']).toBe('fictional-test-token');
            verified = true;
        }
        await route.fulfill({ json: { verified, required: true, delivery_enabled: true,
            destination_hint: 'f…@example.invalid', version: 1, state: verified ? 'verified' : 'sent', can_request: false } });
    };
    await context.route('**/portal/api/email-verification/**', verification);
    const f = await fixture(page, { verification });
    await openNewApplication(page);
    const panel = page.getByRole('region', { name: 'Verify your sign-in email', exact: true });
    const open = panel.getByRole('link', { name: 'Open email verification in a new tab', exact: true });
    await expect(open).toHaveAttribute('target', '_blank');
    const popup = page.waitForEvent('popup');
    await open.click();
    const confirmation = await popup;
    const privateToken = 'b'.repeat(64);
    await confirmation.goto('/portal/verify-email/#' + privateToken);
    await confirmation.getByRole('button', { name: 'Confirm my email', exact: true }).click();
    await expect(confirmation.getByRole('region', { name: 'Verify your sign-in email', exact: true }))
        .toContainText('Your current sign-in email is verified.');
    await page.bringToFront();
    await panel.getByRole('button', { name: 'Refresh verification status', exact: true }).click();
    await expect(panel).toContainText('Your current sign-in email is verified.');
    await expect(page.getByRole('textbox', { name: 'New application note', exact: true }))
        .toHaveValue('Unsaved fictional new answer.');
    expect(calls).toEqual([{ token: privateToken }]);
    await page.getByRole('button', { name: 'Review answers', exact: true }).click();
    await page.getByRole('button', { name: 'Submit application', exact: true }).click();
    await expect.poll(() => f.writes.length).toBe(1);
    expect(f.writes[0].answers).toEqual({ new_note: 'Unsaved fictional new answer.' });
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
    await confirmation.close();
});
