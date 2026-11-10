const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

test('subject setup and explicit course assignment are accessible draft actions', async ({ page }) => {
    let subject = null, assigned = null;
    const calls = [], errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/courses/') result = { items: [{ id: id(1), code: 'MATH.101', title: 'Fictional Algebra', credits_milli: 3000, active: true }], next_after: null };
        else if (path === '/sis/catalog/subjects/') result = { items: subject ? [subject] : [], next_after: null };
        else if (path === '/sis/catalog/subjects/save/') { subject = { id: id(2), code: data.code, title: data.title, active: data.active, revision: 'a'.repeat(64) }; result = subject; }
        else if (path === `/sis/courses/${id(1)}/subject/`) {
            if (data) { assigned = data.subject_id ? subject : null; result = { status: 'saved' }; }
            else result = { course: { id: id(1), code: 'MATH.101', title: 'Fictional Algebra' }, subject: assigned, revision: 'b'.repeat(64) };
        }
        else if (path.endsWith('/publication/page/')) result = { items: [], next_cursor: null };
        else if (path.endsWith('/publication/review/')) result = { expected_version: 0, reason: data.reason,
            definition: { code: 'MATH.101', title: 'Fictional Algebra', credits_milli: 3000, prerequisites: [], transcript_title: null, description: null, subject: assigned }, review_token: 'fictional-subject-publication' };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    await page.getByRole('button', { name: 'Subjects', exact: true }).click();
    await page.getByRole('button', { name: 'Add subject', exact: true }).click();
    await page.getByRole('textbox', { name: 'Subject code', exact: true }).fill('MATH');
    await page.getByRole('textbox', { name: 'Subject name', exact: true }).fill('Mathematics <script>fictional</script>');
    await page.getByRole('button', { name: 'Save subject', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Mathematics <script>fictional</script>');
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await page.getByRole('button', { name: 'Fictional Algebra', exact: true }).click();
    await page.getByRole('button', { name: 'Course subject', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('No subject assigned');
    await page.getByRole('button', { name: 'Choose subject', exact: true }).click();
    await page.getByRole('button', { name: 'Choose MATH', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Earlier publications and student records stay unchanged');
    await page.getByRole('button', { name: 'Review course publication', exact: true }).click();
    await page.getByLabel('Reason for publication', { exact: true }).fill('Fictional classification approval');
    await page.getByRole('button', { name: 'Review current definition', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('MATH — Mathematics <script>fictional</script>');
    await page.setViewportSize({ width: 375, height: 812 });
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => v.id)).toEqual([]);
    expect(calls.some(call => call.path.endsWith('/publication/commit/'))).toBe(false);
    expect(calls.find(call => call.path === `/sis/courses/${id(1)}/subject/`)).toMatchObject({ data: { subject_id: id(2), revision: 'b'.repeat(64) }, csrf: 'fictional-test-token' });
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(errors).toEqual([]);
});
