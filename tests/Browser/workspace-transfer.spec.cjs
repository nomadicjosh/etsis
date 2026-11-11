const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

async function fixture(page, options = {}) {
    const id = suffix => `01K000000000000000000000${suffix}`;
    const institution = id('41'), external = id('42'), equivalency = id('43'), course = id('44'), student = id('45'), term = id('46');
    const publication = id('51'), olderPublication = id('52');
    const sent = [], reads = [], errors = [];
    let name = 'Fictional College', revision = 'a'.repeat(64), credits = 3000, failAward = true;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) sent.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        else reads.push(path);
        let result = {}, status = 200;
        const context = { id: equivalency, institution_id: institution, institution_code: 'EXT.1', institution_name: name,
            external_course_id: external, external_code: 'MATH.101', external_title: '<script>fictional</script>',
            course_id: course, course_code: 'MATH.101', course_title: 'College Algebra', starts_on: '2020-01-01',
            ends_on: null, credits_milli: credits, minimum_grade_rank: 2, awarded_grade: 'TR', counts_gpa: 0,
            points_milli: 0, passes: 1, revision };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) {
            const dataset = path.split('/')[4];
            const rows = {
                institutions: [{ id: institution, code: 'EXT.1', name }],
                external: [{ id: external, code: 'MATH.101', title: 'External algebra', institution_name: name }],
                equivalencies: [context],
                transferstudents: [{ id: student, given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL' }],
                courses: [{ id: course, code: 'MATH.101', title: 'College Algebra' }],
                terms: [{ id: term, code: '26/FA' }],
            };
            result = { items: rows[dataset] || [], next_after: null };
        } else if (path === `/sis/ui/transfer/institutions/${institution}/`) result = { id: institution, code: 'EXT.1', name, revision };
        else if (path === `/sis/ui/transfer/institutions/${institution}/edit/`) { name = data.name; revision = 'b'.repeat(64); }
        else if (path === `/sis/ui/transfer/institutions/${institution}/children/`) result = { items: [{ id: external, code: 'MATH.101', title: 'External algebra' }], next_after: data.after ? null : external };
        else if (path === `/sis/ui/transfer/external/${external}/`) result = { id: external, institution_id: institution, institution_code: 'EXT.1', institution_name: name, code: 'MATH.101', title: 'External algebra', revision };
        else if (path === `/sis/ui/transfer/external/${external}/children/`) result = { items: [context], next_after: null };
        else if (path === `/sis/ui/transfer/equivalencies/${equivalency}/`) result = context;
        else if (path === `/sis/ui/transfer/equivalencies/${equivalency}/edit/`) { credits = data.credits_milli; revision = 'c'.repeat(64); }
        else if (path === `/sis/transfer-equivalencies/${equivalency}/course-versions/`) result = { items: options.unpublished ? [] : [{ version_id: data.cursor ? olderPublication : publication, version: data.cursor ? 1 : 2, code: 'MATH.101', title: 'College Algebra', transcript_title: data.cursor ? 'Earlier <script>algebra</script>' : 'College Algebra', credits_milli: 3000 }], next_cursor: options.more && !data.cursor ? 'frozen-page' : null };
        else if (path.endsWith('/awards/')) result = { items: [], next_after: null };
        else if (path === '/sis/transfer-awards/' && failAward) { status = 409; result = { error: 'This record changed. Reload it before trying again.' }; failAward = false; }
        else if (path === `/sis/students/${student}/record/`) result = { given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', active: true, primary_program: { code: 'BS.MATH', title: 'Mathematics' }, entry_term: { code: '26/FA' } };
        else if (path === `/sis/students/${student}/transcript/`) result = { credits: [{ course_code: 'ENG.101', course_title: 'Writing', completed_milli: 3000, grade_code: 'A' }] };
        await route.fulfill({ status, json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    return { institution, external, equivalency, course, student, term, publication, olderPublication, sent, reads, errors };
}

async function accessible(page) {
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
}

test('registrar reviews connected catalog and student academics without leaving award form', async ({ page }) => {
    const f = await fixture(page);
    await page.goto('/admin/transfer-fixture/');
    await expect(page.locator('.workspace-nav-link[data-workspace="courses"]')).toHaveCount(0);
    await expect(page.locator('.workspace-nav-link[data-workspace="transcripts"]')).toHaveCount(0);
    await page.locator('.workspace-nav-link[data-workspace="institutions"]').click();
    await page.getByRole('button', { name: 'Fictional College', exact: true }).click();
    await page.getByRole('button', { name: 'Next catalog page', exact: true }).click();
    await expect.poll(() => f.sent.some(item => item.path.endsWith('/children/') && item.data.after === f.external)).toBe(true);
    await page.getByRole('button', { name: 'Previous catalog page', exact: true }).click();
    await page.getByRole('button', { name: 'Edit institution', exact: true }).click();
    await page.getByLabel('Institution name', { exact: false }).fill('Updated Fictional College');
    await page.getByRole('button', { name: 'Save transfer catalog record', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Updated Fictional College');
    expect(f.sent.find(item => item.path.endsWith('/edit/'))).toMatchObject({ data: { code: 'EXT.1', name: 'Updated Fictional College', revision: 'a'.repeat(64) }, csrf: 'fictional-test-token' });
    await page.getByRole('button', { name: 'Open external course', exact: true }).click();
    await page.getByRole('button', { name: 'Define equivalency for this course', exact: true }).click();
    await expect(page.getByLabel('Institutional course', { exact: false }).first()).toHaveValue('');
    await expect(page.locator('#drawer-body [name="institution_id"]')).toHaveCount(0);
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await page.getByRole('button', { name: 'Review equivalency', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('<script>fictional</script>');
    await expect(page.locator('#drawer-body script')).toHaveCount(0);
    await page.getByRole('button', { name: 'Edit equivalency rules', exact: true }).click();
    await expect(page.getByLabel('Credits awarded', { exact: false })).toHaveValue('3');
    await page.getByLabel('Credits awarded', { exact: false }).fill('3.125');
    await page.getByRole('button', { name: 'Save equivalency rules', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('3.125 · TR');
    expect(f.sent.find(item => item.path.includes('/equivalencies/') && item.path.endsWith('/edit/')).data).toMatchObject({ credits_milli: 3125, revision: 'b'.repeat(64), minimum_grade_rank: 2 });
    await page.getByRole('button', { name: 'Award transfer credit', exact: true }).click();
    await expect(page.getByLabel('Accepted institutional course publication', { exact: false })).toHaveValue('');
    await page.getByLabel('Accepted institutional course publication', { exact: false }).selectOption(f.publication);
    await page.locator('#drawer-body').getByRole('combobox', { name: /^Student/ }).selectOption(f.student);
    await page.getByLabel('Posting term', { exact: false }).first().selectOption(f.term);
    await page.getByLabel('External course completion date').fill('2026-05-01');
    await page.getByLabel('External grade rank').fill('2');
    await page.getByRole('button', { name: 'Review student academics', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Existing academic credits', exact: true })).toContainText('ENG.101');
    await expect(page.getByRole('region', { name: 'Student academic review', exact: true })).toContainText('BS.MATH');
    await page.locator('#drawer-body').getByRole('combobox', { name: /^Student/ }).selectOption('');
    await expect(page.getByRole('region', { name: 'Student academic review', exact: true })).toBeEmpty();
    await page.locator('#drawer-body').getByRole('combobox', { name: /^Student/ }).selectOption(f.student);
    await expect(page.getByLabel('External course completion date')).toHaveValue('2026-05-01');
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Confirm transfer award', exact: true }).click();
    await expect(page.locator('#drawer-error').filter({ hasText: 'This record changed.' })).toBeVisible();
    await expect(page.getByLabel('External course completion date')).toHaveValue('2026-05-01');
    await expect(page.getByLabel('Accepted institutional course publication', { exact: false })).toHaveValue(f.publication);
    expect(f.sent.find(item => item.path === '/sis/transfer-awards/')).toMatchObject({ data: { course_version_id: f.publication, student_id: f.student, institution_id: f.institution, equivalency_id: f.equivalency, revision: 'c'.repeat(64), grade_rank: 2 }, csrf: 'fictional-test-token' });
    expect(f.sent.some(item => item.path.includes('/ui/data/students/'))).toBe(false);
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('transfer catalog permission alone cannot start an award or fetch protected choices', async ({ page }) => {
    const f = await fixture(page);
    await page.goto('/admin/transfer-only-fixture/');
    await expect(page.locator('.workspace-nav-link[data-workspace="students"]')).toHaveCount(0);
    await page.locator('.workspace-nav-link[data-workspace="institutions"]').click();
    await page.locator('#directory-tabs [data-workspace="equivalencies"]').click();
    await page.getByRole('button', { name: 'Open MATH.101 → MATH.101', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Edit equivalency rules', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Award transfer credit', exact: true })).toHaveCount(0);
    await accessible(page);
    expect(f.reads.some(path => path.includes('/sis/students/'))).toBe(false);
    expect(f.sent.some(item => item.path.endsWith('/course-versions/'))).toBe(false);
    expect(f.errors).toEqual([]);
});

async function openEquivalency(page) {
    await page.goto('/admin/transfer-fixture/');
    await page.locator('.workspace-nav-link[data-workspace="institutions"]').click();
    await page.locator('#directory-tabs [data-workspace="equivalencies"]').click();
    await page.getByRole('button', { name: 'Open MATH.101 → MATH.101', exact: true }).click();
}

test('publication pages preserve deliberate selection and render published labels as text', async ({ page }) => {
    const f = await fixture(page, { more: true });
    await openEquivalency(page);
    await page.getByRole('button', { name: 'Award transfer credit', exact: true }).click();
    const choice = page.getByLabel('Accepted institutional course publication', { exact: false });
    await expect(choice).toHaveValue('');
    await page.getByRole('button', { name: 'Load more course publications', exact: true }).click();
    await expect(choice).toBeFocused();
    await expect(choice).toHaveValue('');
    await expect(choice.locator('option')).toHaveCount(3);
    await expect(choice).toContainText('Earlier <script>algebra</script>');
    await expect(page.locator('#drawer-body script')).toHaveCount(0);
    await choice.selectOption(f.olderPublication);
    await expect(choice).toHaveValue(f.olderPublication);
    expect(f.sent.find(item => item.data.cursor === 'frozen-page')).toMatchObject({ csrf: 'fictional-test-token' });
    await accessible(page);
    expect(f.errors).toEqual([]);
});

test('unpublished receiving course explains the prerequisite without submitting an award', async ({ page }) => {
    const f = await fixture(page, { unpublished: true });
    await openEquivalency(page);
    await page.getByRole('button', { name: 'Award transfer credit', exact: true }).click();
    await expect(page.locator('#drawer-error')).toContainText('Catalog staff must publish the institutional course');
    await expect(page.getByRole('button', { name: 'Confirm transfer award', exact: true })).toHaveCount(0);
    expect(f.sent.some(item => item.path === '/sis/transfer-awards/')).toBe(false);
    expect(f.errors).toEqual([]);
});
