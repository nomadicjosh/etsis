const { test, expect } = require('@playwright/test');
const { fixture, accessible, id } = require('./support/student-degree-fixture.cjs');

test('registrars attribute unknown work and approve or revoke reuse in an accessible review', async ({ page }) => {
    const f = await fixture(page);
    const student = id(1), receiving = id(2), old = id(120), credit = id(121);
    const base = `/sis/students/${student}/academic-work-pursuits/`;
    const previous = { id: old, program_code: 'BA.OLD', program_title: 'Fictional <b>past degree</b>',
        entry_term_code: '22/FA', status: 'closed' };
    const current = { id: receiving, program_code: 'BS.CS', program_title: 'Computing', entry_term_code: '26/FA', status: 'active' };
    let owner = null, reused = false, reviewed = null, commits = 0;
    const calls = [];
    await page.route(`**${base}**`, async route => {
        const path = new URL(route.request().url()).pathname;
        const body = JSON.parse(new URLSearchParams(route.request().postData()).get('_workspace_json'));
        calls.push({ path, body, csrf: route.request().headers()['x-csrf-token'] });
        let result;
        if (path === base) result = { items: [{ id: credit, course_code: 'ENG.101',
            course_title: 'Fictional <b>writing</b>', term_code: '22/FA', credit_type: 'institutional',
            owner, reuse_assignment_ids: reused ? [receiving] : [] }], next_after: null };
        else if (path === `${base}pursuits/`) result = { items: [previous, current], next_after: null };
        else if (path.endsWith('/review/')) {
            reviewed = body;
            result = { review_token: 'fictional-review', course_code: 'ENG.101', course_title: 'Fictional <b>writing</b>',
                action: body.action, target: body.assignment_id === old ? previous : current };
        } else if (path.endsWith('/commit/')) {
            commits++;
            if (reviewed.action === 'attribute') owner = previous;
            else reused = reviewed.action === 'approve_reuse';
            result = { operation_id: id(122) };
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.getByRole('button', { name: 'Work attribution & reuse', exact: true }).click();
    const studio = page.locator('dialog.degree-audit-studio');
    await expect(studio).toContainText('Pursuit not recorded');
    await expect(studio.locator('b')).toHaveCount(0);
    await studio.getByRole('button', { name: 'Attribute this work', exact: true }).click();
    const closed = studio.locator('section').filter({ hasText: 'BA.OLD' });
    await closed.getByRole('button', { name: 'Choose this pursuit', exact: true }).click();
    await studio.getByLabel('Reason and supporting evidence').fill('Fictional transcript identifies the earlier pursuit.');
    await studio.getByRole('button', { name: 'Review decision', exact: true }).click();
    await expect(studio).toContainText('Fictional <b>past degree</b>');
    await studio.getByRole('button', { name: 'Change review', exact: true }).click();
    await expect(studio.getByLabel('Reason and supporting evidence')).toHaveValue('Fictional transcript identifies the earlier pursuit.');
    await studio.getByRole('button', { name: 'Review decision', exact: true }).click();
    expect(commits).toBe(0);
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    await studio.getByRole('button', { name: 'Confirm reviewed decision', exact: true }).click();
    await expect(studio).toContainText('Belongs to BA.OLD');
    await studio.getByRole('button', { name: 'Approve reuse for this pursuit', exact: true }).click();
    await studio.getByLabel('Reason and supporting evidence').fill('Fictional registrar approves reuse for the receiving degree.');
    await studio.getByRole('button', { name: 'Review decision', exact: true }).click();
    await expect(studio).toContainText('Approve reuse: BS.CS');
    await studio.getByRole('button', { name: 'Confirm reviewed decision', exact: true }).click();
    await studio.getByRole('button', { name: 'Revoke reuse for this pursuit', exact: true }).click();
    await studio.getByLabel('Reason and supporting evidence').fill('Fictional approval withdrawn after evidence review.');
    await studio.getByRole('button', { name: 'Review decision', exact: true }).click();
    await studio.getByRole('button', { name: 'Confirm reviewed decision', exact: true }).click();
    await expect(studio.getByRole('button', { name: 'Approve reuse for this pursuit', exact: true })).toBeVisible();
    await accessible(page);
    expect(commits).toBe(3);
    expect(calls.filter(c => c.path.endsWith('/review/')).map(c => c.body.action)).toEqual([
        'attribute', 'attribute', 'approve_reuse', 'revoke_reuse',
    ]);
    expect(calls.every(c => c.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(f.errors).toEqual([]);
});

test('academic readers cannot see registrar attribution controls', async ({ page }) => {
    const f = await fixture(page, { readOnly: true });
    await expect(page.getByRole('button', { name: 'Work attribution & reuse', exact: true })).toHaveCount(0);
    expect(f.calls.some(c => c.path.includes('academic-work-pursuits'))).toBe(false);
});
