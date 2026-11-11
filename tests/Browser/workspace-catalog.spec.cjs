const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => `01K000000000000000000000${String(n).padStart(2, '0')}`;
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
}
async function fixture(page, options = {}) {
    const course = id(1), term = id(2), clone = id(3), prerequisite = id(4), operation = id(5), section = id(6);
    const termRow = { id: term, code: '26/FA', starts_on: '2026-08-01', ends_on: '2026-12-20', add_drop_ends_on: '2026-08-15' };
    const calls = [], errors = []; let published = false, rows = options.rows || [], saved = options.saved || false, definitionPublished = false;
    let sectionBound = false, acceptedReview = null, courseReviewCount = 0, courseCommitCount = 0;
    let courseText = { transcript_title: null, description: null };
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = {};
        const review = { term: termRow, sections: rows.map(row => ({ ...row, course_code: 'MATH.101', course_title: 'Algebra', faculty_name: null })) };
        const receipt = { operation_id: operation, review: published && acceptedReview ? acceptedReview : review, published_at: published ? '2026-11-07T12:00:00Z' : null, created_at: '2026-11-07T11:00:00Z' };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/operation-id/') result = { id: section };
        else if (path.startsWith('/sis/ui/data/')) result = { items: path.includes('/courses/') ? [{ id: course, code: 'MATH.101', title: 'Algebra', credits_milli: 3000, active: true }] : path.includes('/terms/') ? [termRow] : path.includes('/sections/') ? [{ id: section, code: 'MATH.101.A', course_title: 'Algebra', course_code: 'MATH.101', term_code: '26/FA', capacity: 20, enrolled: 0, active: true }] : [], next_after: null };
        else if (path === `/sis/courses/${course}/assistance/`) {
            if (data && options.assistanceFailure) { await route.fulfill({ status: 503, json: { error: 'Optional assistance is unavailable. Your draft is unchanged.' } }); return; }
            result = data ? { task: data.task, advice: { summary: '<script>window.aiExecuted = true</script> Review this suggestion.', suggestions: ['Verify learning outcomes.'], description: data.task === 'description' ? 'A fictional introduction to algebra.' : null } } : { enabled: !options.assistanceDisabled, label: 'Fictional provider', tasks: ['readiness', 'description'], revision: 'a'.repeat(64), draft: { code: 'MATH.101', title: 'Algebra', transcript_title: courseText.transcript_title, description: courseText.description, credits_milli: 3000, prerequisite_count: 0, subject: null, classifications: {} } };
        }
        else if (path === `/sis/courses/${course}/text/`) {
            if (data) { courseText = { transcript_title: data.transcript_title, description: data.description }; result = { status: 'saved' }; }
            else result = { id: course, code: 'MATH.101', title: 'Algebra', ...courseText, revision: 'fictional-text-revision' };
        }
        else if (path.endsWith('/course-definition/page/')) result = { section: { id: section, code: 'MATH.101.A' }, has_student_records: false,
            bound: sectionBound ? { version_id: operation, definition: { code: 'MATH.101', title: 'Algebra', credits_milli: 3000, prerequisites: [], transcript_title: null, description: '<img src=x onerror=alert(1)> Fictional catalog text' } } : null,
            versions: { items: options.noDefinitions && !definitionPublished ? [] : [{ version_id: options.paged && data?.cursor ? id(10) : operation, version: options.paged && data?.cursor ? 2 : 1, definition: { code: 'MATH.101', title: 'Algebra', credits_milli: 3000, prerequisites: [], transcript_title: null, description: '<img src=x onerror=alert(1)> Fictional catalog text' } }], next_cursor: options.paged && !data?.cursor ? 'fictional-next-page' : null } };
        else if (path.endsWith('/course-definition/review/')) result = { section_code: 'MATH.101.A', reason: data.reason, review_token: 'fictional-protected-section-token', selected: { version_id: operation, definition: { code: 'MATH.101', title: 'Algebra', credits_milli: 3000, prerequisites: [], transcript_title: null, description: '<img src=x onerror=alert(1)> Fictional catalog text' } } };
        else if (path.endsWith('/course-definition/commit/')) { sectionBound = true; result = { operation_id: id(9), last_version: 1 }; }
        else if (path.endsWith('/publication/page/')) result = { items: definitionPublished ? [{ version_id: operation, version: 1, definition: { code: 'MATH.101', title: 'Algebra', credits_milli: 3000, prerequisites: [], ...courseText }, effective_at: '2026-11-08T12:00:00Z', recorded_at: '2026-11-08T12:00:01Z' }] : [], next_cursor: null, through_version: definitionPublished ? 1 : 0 };
        else if (path.endsWith('/publication/review/')) result = { expected_version: 0, definition: { code: 'MATH.101', title: 'Algebra', credits_milli: 3000, prerequisites: [], ...courseText }, reason: data.reason, expires_at: 1794130800, review_token: options.refreshTokens ? `fictional-course-proof-${++courseReviewCount}` : 'fictional-protected-review-token' };
        else if (path.endsWith('/publication/commit/')) {
            if (options.rejectFirstCommit && courseCommitCount++ === 0) {
                await route.fulfill({ status: 409, json: { error: 'This course review expired. Refresh the review before confirming.' }, headers: { 'Cache-Control': 'private, no-store' } });
                return;
            }
            definitionPublished = true; result = { operation_id: operation, last_version: 1 };
        }
        else if (path.endsWith('/clone/review/')) result = { source: { id: course, code: 'MATH.101', title: 'Algebra', credits_milli: 3000, active: true }, prerequisites: [{ id: prerequisite, code: 'MATH.001', title: 'Foundations', active: true }], operation_id: clone, revision: 'a'.repeat(64) };
        else if (path.endsWith('/clone/commit/')) result = { id: clone, code: data.code, title: data.title, credits_milli: data.credits_milli, prerequisites_copied: data.prerequisites.length, active: false };
        else if (path === '/sis/catalog/offerings/review/') { rows = data.sections; result = { term: termRow, sections: rows.map(row => ({ ...row, course_code: 'MATH.101', course_title: 'Algebra', faculty_name: null })), operation_id: operation, revision: 'b'.repeat(64) }; }
        else if (path === '/sis/catalog/offerings/save/') { saved = true; result = receipt; }
        else if (path === '/sis/catalog/offerings/') result = { items: saved ? [{ operation_id: operation, term_code: '26/FA', section_count: rows.length, published_at: receipt.published_at, created_at: receipt.created_at }] : [], next_after: null };
        else if (path.endsWith('/publication-setup/')) result = { ...review, operation_id: operation, sections: review.sections.map(row => ({ ...row, was_bound: false, has_student_records: false, instruction_starts_on: termRow.starts_on, instruction_ends_on: termRow.ends_on, selected_offering: null })) };
        else if (path.endsWith('/publication-review/')) {
            result = { ...review, operation_id: operation, reason: data.reason, review_token: 'fictional-offering-publication-token', sections: review.sections.map(row => {
                const dates = data.section_dates.find(item => item.section_id === row.id);
                return { ...row, ...dates, section_version_id: id(11), selected_offering: null, course_version_id: operation,
                    section_definition: { ...row, course_version_id: operation, term_code: termRow.code,
                        term_starts_on: termRow.starts_on, term_ends_on: termRow.ends_on,
                        faculty_person_id: null, ...dates },
                    course_definition: { definition: { code: 'MATH.101', title: 'Algebra', credits_milli: 3000, prerequisites: [], ...courseText } } };
            }) };
            acceptedReview = result;
        }
        else if (path.endsWith('/publish/')) { published = true; result = { ...receipt, published_at: '2026-11-07T12:00:00Z' }; }
        else if (path === `/sis/catalog/offerings/${operation}/`) result = receipt;
        else if (path.includes('/sis/ui/operations/')) result = { items: [], next_after: null };
        else if (path.includes('/prerequisites/')) result = { items: [], next_after: null };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    return { course, term, clone, prerequisite, operation, section, calls, errors };
}

test('course cloning retains draft details and copies only selected prerequisites', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Algebra', exact: true }).click();
    await page.getByRole('button', { name: 'Clone as a course draft', exact: true }).click();
    await page.getByLabel('New course code').fill('MATH.102');
    await page.getByLabel('New course title').fill('Algebra II');
    await page.getByLabel('Credits', { exact: false }).fill('4');
    await page.getByLabel('MATH.001 — Foundations', { exact: true }).check();
    await page.getByRole('button', { name: 'Review course clone', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('4 credits. 1 prerequisites selected. New status: inactive draft.');
    expect(f.calls.some(call => call.path.endsWith('/clone/commit/'))).toBe(false);
    await page.getByRole('button', { name: 'Change clone details', exact: true }).click();
    await expect(page.getByLabel('New course code')).toHaveValue('MATH.102');
    await expect(page.getByLabel('Credits', { exact: false })).toHaveValue('4');
    await expect(page.getByLabel('MATH.001 — Foundations', { exact: true })).toBeChecked();
    await page.getByRole('button', { name: 'Review course clone', exact: true }).click();
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    await page.getByRole('button', { name: 'Save course draft', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('created as an inactive draft');
    expect(f.calls.find(call => call.path.endsWith('/clone/commit/'))).toMatchObject({ data: { operation_id: f.clone, code: 'MATH.102', title: 'Algebra II', credits_milli: 4000, prerequisites: [f.prerequisite], revision: 'a'.repeat(64) }, csrf: 'fictional-test-token' });
    expect(f.errors).toEqual([]);
});

test('course offerings stay drafts until a separately reviewed publication and can be resumed', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Build course offerings', exact: true }).click();
    await page.getByRole('combobox', { name: /^Offering term/ }).selectOption(f.term);
    await page.getByRole('button', { name: 'Choose sections', exact: true }).click();
    await page.getByRole('combobox', { name: /^Course/ }).selectOption(f.course);
    await page.getByLabel('Section code').fill('MATH.101.26FA.A');
    await page.getByLabel('Section fee').fill('125.50');
    await page.getByLabel('Delivery mode', { exact: false }).selectOption('online');
    await page.getByLabel('Enable self-registration when published').check();
    await page.getByRole('button', { name: 'Keep section in plan', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('have not been saved');
    await page.getByRole('button', { name: 'Review offering plan', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('MATH.101 — Algebra');
    expect(f.calls.some(call => call.path.endsWith('/save/'))).toBe(false);
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    await page.getByRole('button', { name: 'Save section drafts', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('inactive and unavailable for registration');
    expect(f.calls.some(call => call.path.endsWith('/publish/'))).toBe(false);
    await page.locator('#drawer-body').getByRole('button', { name: 'Saved offering plans', exact: true }).click();
    await page.getByRole('button', { name: 'Open offering plan', exact: true }).click();
    await page.getByRole('button', { name: 'Review publication', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Review definitions & publication', exact: true })).toBeDisabled();
    await page.getByRole('radio', { name: 'Version 1 · MATH.101 · Algebra', exact: true }).check();
    await page.getByText('MATH.101.26FA.A · 2026-08-01 through 2026-12-20', { exact: true }).click();
    await page.getByLabel('Instruction starts for MATH.101.26FA.A', { exact: true }).fill('2026-09-01');
    await page.getByLabel('Instruction ends for MATH.101.26FA.A', { exact: true }).fill('2026-11-30');
    await page.getByLabel('Reason for offering publication').fill('Fictional term offering approval');
    await page.getByRole('button', { name: 'Review definitions & publication', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('normal eligibility checks still apply');
    expect(f.calls.some(call => call.path.endsWith('/publish/'))).toBe(false);
    await page.getByRole('button', { name: 'Change definition choices', exact: true }).click();
    await expect(page.getByRole('radio')).toBeChecked();
    await expect(page.getByLabel('Reason for offering publication')).toHaveValue('Fictional term offering approval');
    await expect(page.getByLabel('Instruction starts for MATH.101.26FA.A', { exact: true })).toHaveValue('2026-09-01');
    await accessible(page);
    await page.getByRole('button', { name: 'Review definitions & publication', exact: true }).click();
    await accessible(page);
    await page.getByRole('button', { name: 'Publish reviewed sections', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Published 1 sections');
    expect(f.calls.find(call => call.path.endsWith('/save/'))).toMatchObject({ data: { operation_id: f.operation, term_id: f.term, sections: [{ id: f.section, course_id: f.course, fee_minor: 12550, web_registration: true, delivery_mode: 'online' }] }, csrf: 'fictional-test-token' });
    expect(f.calls.find(call => call.path.endsWith('/publish/'))).toMatchObject({ data: { review_token: 'fictional-offering-publication-token' }, csrf: 'fictional-test-token' });
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});

test('course definitions require review and retain accessible published versions without browser storage', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Algebra', exact: true }).click();
    await page.getByRole('button', { name: 'Published definitions', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('No definition has been published yet.');
    await page.getByLabel('Reason for publication').fill('Fictional approved academic definition');
    await page.getByRole('button', { name: 'Review current definition', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('This creates version 1.');
    await expect(page.locator('#drawer-body')).toContainText('Publication takes effect when you confirm it.');
    await expect(page.locator('#drawer-body')).toContainText('An unconfirmed review expires after 20 minutes.');
    expect(f.calls.some(call => call.path.endsWith('/publication/commit/'))).toBe(false);
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    await page.getByRole('button', { name: 'Publish reviewed definition', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Version 1 is retained.');
    expect(f.calls.find(call => call.path.endsWith('/publication/commit/'))).toMatchObject({ data: { review_token: 'fictional-protected-review-token' }, csrf: 'fictional-test-token' });
    await page.getByRole('button', { name: 'View published definitions', exact: true }).click();
    await page.getByText('Version 1 · MATH.101 · Algebra', { exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Recorded: 2026-11-08T12:00:01Z');
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('course text drafts preserve paragraphs and render markup as text through publication', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Algebra', exact: true }).click();
    await page.getByRole('button', { name: 'Titles & description', exact: true }).click();
    await page.getByLabel('Transcript title (optional)', { exact: true }).fill('Algebra I');
    const description = 'First paragraph.\n<script>window.fictionalCourseTextExecuted = true</script>\nSecond paragraph.';
    await page.getByLabel('Catalog description (optional)', { exact: true }).fill(description);
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    await page.getByRole('button', { name: 'Save course text draft', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Your text is saved as a draft.');
    const saved = f.calls.find(call => call.path.endsWith('/text/'));
    expect(saved).toMatchObject({ csrf: 'fictional-test-token', data: { transcript_title: 'Algebra I', description, revision: 'fictional-text-revision' } });
    expect(f.calls.some(call => call.path.endsWith('/publication/commit/'))).toBe(false);
    await page.getByRole('button', { name: 'Review publication', exact: true }).click();
    await page.getByLabel('Reason for publication').fill('Fictional approved description');
    await page.getByRole('button', { name: 'Review current definition', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText(description);
    await expect(page.locator('#drawer-body')).toContainText('Algebra I');
    expect(await page.evaluate(() => window.fictionalCourseTextExecuted)).toBeUndefined();
    await accessible(page);
    await page.getByRole('button', { name: 'Publish reviewed definition', exact: true }).click();
    await page.getByRole('button', { name: 'View published definitions', exact: true }).click();
    await page.getByText('Version 1 · MATH.101 · Algebra', { exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText(description);
    expect(await page.evaluate(() => window.fictionalCourseTextExecuted)).toBeUndefined();
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(f.errors).toEqual([]);
});


test('section definition is selected and reviewed before an immutable binding is saved', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('link', { name: 'Sections', exact: true }).click();
    await page.getByRole('button', { name: 'MATH.101.A', exact: true }).click();
    await page.getByRole('button', { name: 'Course definition', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Review section definition', exact: true })).toBeDisabled();
    await page.getByRole('radio', { name: 'Version 1 · MATH.101 · Algebra', exact: true }).check();
    await page.getByLabel('Reason for this section definition').fill('Fictional offering approval');
    await page.getByRole('button', { name: 'Review section definition', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('<img src=x onerror=alert(1)> Fictional catalog text');
    expect(await page.locator('#drawer-body img').count()).toBe(0);
    expect(f.calls.some(call => call.path.endsWith('/course-definition/commit/'))).toBe(false);
    await page.getByRole('button', { name: 'Change selection', exact: true }).click();
    await expect(page.getByRole('radio')).toBeChecked();
    await expect(page.getByLabel('Reason for this section definition')).toHaveValue('Fictional offering approval');
    await page.getByRole('button', { name: 'Review section definition', exact: true }).click();
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Retain reviewed definition', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('This section retains the course definition below.');
    await expect(page.getByRole('radio')).toHaveCount(0);
    expect(f.calls.find(call => call.path.endsWith('/course-definition/commit/'))).toMatchObject({ data: { review_token: 'fictional-protected-section-token' }, csrf: 'fictional-test-token' });
    expect(await page.evaluate(() => [localStorage.length, sessionStorage.length])).toEqual([0, 0]);
    expect(f.errors).toEqual([]);
    await accessible(page);
});


test('one paged definition choice covers multiple offering drafts without losing the selection', async ({ page }) => {
    const rows = [id(6), id(7)].map((section, index) => ({ id: section, course_id: id(1), code: `MATH.101.26FA.${index + 1}`, capacity: 20, delivery_mode: 'online', fee_minor: 0, currency: 'USD', web_registration: true }));
    const f = await fixture(page, { rows, saved: true, paged: true });
    await page.getByRole('button', { name: 'Saved offering plans', exact: true }).click();
    await page.getByRole('button', { name: 'Open offering plan', exact: true }).click();
    await page.getByRole('button', { name: 'Review publication', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Sections: MATH.101.26FA.1, MATH.101.26FA.2');
    await expect(page.getByRole('radio')).toHaveCount(1);
    await page.getByRole('radio').check();
    await page.getByLabel('Reason for offering publication').fill('Fictional multi-section approval');
    await page.getByRole('button', { name: 'More versions', exact: true }).click();
    await expect(page.getByRole('radio')).toHaveAccessibleName('Version 2 · MATH.101 · Algebra');
    await expect(page.locator('#drawer-body')).toContainText('Selected version 1: Algebra. Applies to 2 sections.');
    await page.getByRole('button', { name: 'Previous versions', exact: true }).click();
    await expect(page.getByRole('radio')).toBeChecked();
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    await page.getByRole('button', { name: 'Review definitions & publication', exact: true }).click();
    expect(f.calls.find(call => call.path.endsWith('/publication-review/'))).toMatchObject({ data: {
        course_versions: rows.map(row => ({ section_id: row.id, version_id: f.operation })),
        section_dates: rows.map(row => ({ section_id: row.id, instruction_starts_on: '2026-08-01', instruction_ends_on: '2026-12-20' })), reason: 'Fictional multi-section approval',
    }, csrf: 'fictional-test-token' });
    expect(f.calls.some(call => call.path.endsWith('/course-definition/commit/'))).toBe(false);
    expect(f.calls.some(call => call.path.endsWith('/publish/'))).toBe(false);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(f.errors).toEqual([]);
});


test('missing course publication can be completed before returning to the saved offering choices', async ({ page }) => {
    const row = { id: id(6), course_id: id(1), code: 'MATH.101.26FA.A', capacity: 20, delivery_mode: 'online', fee_minor: 0, currency: 'USD', web_registration: true };
    const f = await fixture(page, { rows: [row], saved: true, noDefinitions: true });
    await page.getByRole('button', { name: 'Saved offering plans', exact: true }).click();
    await page.getByRole('button', { name: 'Open offering plan', exact: true }).click();
    await page.getByRole('button', { name: 'Review publication', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Publish a course definition before publishing these offerings.');
    await page.getByLabel('Reason for offering publication').fill('Keep this fictional offering reason');
    await page.getByRole('button', { name: 'Open course publications', exact: true }).click();
    await page.getByRole('button', { name: 'Back to offering definitions', exact: true }).click();
    await expect(page.getByLabel('Reason for offering publication')).toHaveValue('Keep this fictional offering reason');
    await page.getByRole('button', { name: 'Open course publications', exact: true }).click();
    await page.getByLabel('Reason for publication', { exact: true }).fill('Fictional course approval');
    await page.getByRole('button', { name: 'Review current definition', exact: true }).click();
    await page.getByRole('button', { name: 'Publish reviewed definition', exact: true }).click();
    await page.getByRole('button', { name: 'Back to offering definitions', exact: true }).click();
    await expect(page.getByLabel('Reason for offering publication')).toHaveValue('Keep this fictional offering reason');
    await page.getByRole('radio').check();
    await expect(page.getByRole('button', { name: 'Review definitions & publication', exact: true })).toBeEnabled();
    expect(f.calls.some(call => call.path.endsWith('/publish/'))).toBe(false);
    expect(f.errors).toEqual([]);
    await accessible(page);
});


test('offering date overrides stay section-specific and invalid periods do not submit', async ({ page }) => {
    const rows = [id(6), id(7)].map((section, index) => ({ id: section, course_id: id(1), code: `MATH.101.${index + 1}`,
        capacity: 20, delivery_mode: 'online', fee_minor: 0, currency: 'USD', web_registration: true }));
    const f = await fixture(page, { rows, saved: true });
    await page.getByRole('button', { name: 'Saved offering plans', exact: true }).click();
    await page.getByRole('button', { name: 'Open offering plan', exact: true }).click();
    await page.getByRole('button', { name: 'Review publication', exact: true }).click();
    await page.getByRole('radio').check();
    await page.getByLabel('Reason for offering publication').fill('Fictional short-session approval');
    await page.getByText('MATH.101.1 · 2026-08-01 through 2026-12-20', { exact: true }).click();
    await page.getByLabel('Instruction starts for MATH.101.1', { exact: true }).fill('2026-09-01');
    await page.getByLabel('Instruction ends for MATH.101.1', { exact: true }).fill('2026-08-31');
    await page.getByRole('button', { name: 'Review definitions & publication', exact: true }).click();
    expect(f.calls.some(call => call.path.endsWith('/publication-review/'))).toBe(false);
    await page.getByLabel('Instruction ends for MATH.101.1', { exact: true }).fill('2027-01-05');
    await expect(page.locator('#drawer-body')).toContainText('These instructional dates extend beyond the term');
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    await page.getByRole('button', { name: 'Review definitions & publication', exact: true }).click();
    expect(f.calls.find(call => call.path.endsWith('/publication-review/')).data.section_dates).toEqual([
        { section_id: rows[0].id, instruction_starts_on: '2026-09-01', instruction_ends_on: '2027-01-05' },
        { section_id: rows[1].id, instruction_starts_on: '2026-08-01', instruction_ends_on: '2026-12-20' },
    ]);
    await expect(page.locator('#drawer-body')).toContainText('MATH.101.1 · 2026-09-01 through 2027-01-05');
    await page.getByText('MATH.101.1 · 2026-09-01 through 2027-01-05', { exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Publication will retain this offering version for future registrations');
    await accessible(page);
    expect(f.calls.some(call => call.path.endsWith('/publish/'))).toBe(false);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});


test('an expired course review can be refreshed without losing its reason or publishing unseen changes', async ({ page }) => {
    const f = await fixture(page, { refreshTokens: true, rejectFirstCommit: true });
    await page.getByRole('button', { name: 'Algebra', exact: true }).click();
    await page.getByRole('button', { name: 'Published definitions', exact: true }).click();
    await page.getByLabel('Reason for publication', { exact: true }).fill('Fictional retained approval reason');
    await page.getByRole('button', { name: 'Review current definition', exact: true }).click();
    await page.getByRole('button', { name: 'Return to definitions', exact: true }).click();
    await expect(page.getByLabel('Reason for publication', { exact: true })).toHaveValue('Fictional retained approval reason');
    await page.getByRole('button', { name: 'Review current definition', exact: true }).click();
    await page.getByRole('button', { name: 'Publish reviewed definition', exact: true }).click();
    await expect(page.locator('#drawer-error')).toContainText('This course review expired.');
    expect(f.calls.filter(call => call.path.endsWith('/publication/commit/'))).toHaveLength(1);
    await page.setViewportSize({ width: 375, height: 812 });
    await page.getByRole('button', { name: 'Refresh course review', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('#drawer-body')).toContainText('Reason: Fictional retained approval reason');
    await accessible(page);
    expect(f.calls.filter(call => call.path.endsWith('/publication/review/'))).toHaveLength(3);
    expect(f.calls.filter(call => call.path.endsWith('/publication/commit/'))).toHaveLength(1);
    await page.getByRole('button', { name: 'Publish reviewed definition', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('#drawer-body')).toContainText('Version 1 is retained.');
    const confirmations = f.calls.filter(call => call.path.endsWith('/publication/commit/'));
    expect(confirmations.map(call => call.data.review_token)).toEqual(['fictional-course-proof-2', 'fictional-course-proof-3']);
    expect(confirmations.every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(f.errors).toEqual([]);
});


test('optional assistance requires consent and explicit copy and save, with accessible private results', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Algebra', exact: true }).click();
    await page.getByRole('button', { name: 'Titles & description', exact: true }).click();
    await page.getByRole('button', { name: 'Optional AI help', exact: true }).click();
    await page.getByText('Inspect exactly what will be sent', { exact: true }).click();
    await expect(page.locator('#drawer-body pre')).toContainText('"code": "MATH.101"');
    await page.getByRole('button', { name: 'Suggest a course description', exact: true }).click();
    expect(f.calls.filter(call => call.path.endsWith('/assistance/'))).toHaveLength(0);
    const consent = page.getByRole('checkbox', { name: /I agree to send/ });
    await consent.check();
    await page.getByRole('button', { name: 'Suggest a course description', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Use suggested description in this editor' })).toBeVisible();
    await expect(consent).not.toBeChecked();
    await expect(page.getByLabel('Catalog description (optional)', { exact: true })).toHaveValue('');
    expect(f.calls.filter(call => call.path.endsWith('/assistance/'))).toEqual([{ path: `/sis/courses/${f.course}/assistance/`, csrf: 'fictional-test-token', data: { task: 'description', consent: true, revision: 'a'.repeat(64) } }]);
    expect(f.calls.filter(call => call.path.endsWith('/text/'))).toHaveLength(0);
    expect(await page.evaluate(() => window.aiExecuted)).toBeUndefined();
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Use suggested description in this editor' }).click();
    await expect(page.getByLabel('Catalog description (optional)', { exact: true })).toHaveValue('A fictional introduction to algebra.');
    expect(f.calls.filter(call => call.path.endsWith('/text/'))).toHaveLength(0);
    await page.getByRole('button', { name: 'Save course text draft', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Your text is saved as a draft.');
    expect(f.calls.filter(call => call.path.endsWith('/publication/commit/'))).toHaveLength(0);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('disabled or failed assistance leaves normal editing available', async ({ page }) => {
    for (const options of [{ assistanceDisabled: true }, { assistanceFailure: true }]) {
        await page.unroute('**/sis/**');
        const f = await fixture(page, options);
        await page.getByRole('button', { name: 'Algebra', exact: true }).click();
        await page.getByRole('button', { name: 'Titles & description', exact: true }).click();
        await page.getByRole('button', { name: 'Optional AI help', exact: true }).click();
        if (options.assistanceFailure) {
            await page.getByRole('checkbox', { name: /I agree to send/ }).check();
            await page.getByRole('button', { name: 'Suggest publication checks', exact: true }).click();
            await expect(page.locator('#drawer-body')).toContainText('No suggestion was applied.');
        } else {
            await expect(page.locator('#drawer-body')).toContainText('Optional assistance is disabled.');
            expect(f.calls.filter(call => call.path.endsWith('/assistance/'))).toHaveLength(0);
        }
        await page.getByLabel('Catalog description (optional)', { exact: true }).fill('Staff-authored description.');
        await page.getByRole('button', { name: 'Save course text draft', exact: true }).click();
        await expect(page.locator('#drawer-body')).toContainText('Your text is saved as a draft.');
        expect(f.errors).toEqual([]);
    }
});
