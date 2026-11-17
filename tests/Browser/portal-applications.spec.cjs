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
], answer = null } = {}) {
    const reads = [], writes = [], errors = [];
    const me = { person: { id: person }, applications, students: [], contact: {}, accounts: [], news: [] };
    page.on('pageerror', error => errors.push(error.message));
    await page.route(/\/(?:portal\/api|sis\/(?:forms|form-submissions))\//, async route => {
        const req = route.request(), path = new URL(req.url()).pathname;
        let json = {};
        if (path === '/portal/api/me/') json = me;
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
    await page.goto('/portal/apply/');
    await expect(page.getByRole('heading', { name: 'Make this path your own' })).toBeVisible();
    return { reads, writes, errors };
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
    await expect(list.getByRole('row').filter({ hasText: 'DIP.ART' })).toContainText('next steps for this program');
    const studentRow = list.getByRole('row').filter({ hasText: 'BS.SCI' });
    await expect(studentRow).toContainText('Student record linked');
    await expect(studentRow.getByRole('link', { name: 'My campus', exact: true })).toHaveAttribute('href', '/portal/student/');
    expect(reads).toHaveLength(0);
    await openNewApplication(page);
    const trigger = list.getByRole('button', { name: /View answers for DIP.ART/ });
    await trigger.focus(); await page.keyboard.press('Enter');
    const viewer = page.locator('.campus-application-answers');
    await expect(viewer.getByRole('heading', { name: 'Original Arts application' })).toBeFocused();
    await expect(viewer.getByRole('status')).toHaveText('Your submitted answers are ready.');
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
    await expect(viewer.getByRole('heading', { name: 'Original Science application' })).toBeVisible();
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
