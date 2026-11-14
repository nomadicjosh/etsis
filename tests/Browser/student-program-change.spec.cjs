const { test, expect } = require('@playwright/test');
const { fixture, accessible, id } = require('./support/student-degree-fixture.cjs');

test('a program change reviews the replacement and closes the former pursuit in one confirmation', async ({ page }) => {
    const f = await fixture(page);
    await page.locator('dialog.degree-audit-studio').getByRole('button', { name: 'Close builder', exact: true }).click();
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    const student = id(1), next = id(150), term = id(151);
    const calls = [];
    await page.route(`**/sis/students/${student}/academic-work-pursuits/**`, async route => {
        const body = JSON.parse(new URLSearchParams(route.request().postData()).get('_workspace_json'));
        calls.push({ path: new URL(route.request().url()).pathname, body,
            csrf: route.request().headers()['x-csrf-token'] });
        await route.fulfill({ json: { receiving: { id: id(153), program_code: 'BA.ART',
            program_title: 'Fictional new <b>program</b>', entry_term_code: '27/FA', status: 'active' },
            items: [], next_after: null } });
    });
    await page.route('**/sis/ui/data/programs/**', route => route.fulfill({ json: { items: [
        { id: next, code: 'BA.ART', title: 'Fictional new <b>program</b>' }], next_after: null } }));
    await page.route('**/sis/ui/data/terms/**', route => route.fulfill({ json: { items: [
        { id: term, code: '27/FA' }], next_after: null } }));
    await page.route(`**/sis/students/${student}/programs/change/**`, async route => {
        const body = JSON.parse(new URLSearchParams(route.request().postData()).get('_workspace_json'));
        calls.push({ path: new URL(route.request().url()).pathname, body,
            csrf: route.request().headers()['x-csrf-token'] });
        await route.fulfill({ json: route.request().url().endsWith('/review/') ? {
            before: { program_code: 'BS.CS', program_title: 'Computing' },
            source: { program_code: 'BA.ART', program_title: 'Fictional new <b>program</b>',
                entry_term_code: '27/FA', cohort: { year_code: '2027-2028', revision: 1, catalog_id: id(152) } },
            effective_on: '2026-11-14', student_active: true, review_token: 'fictional-program-change',
        } : { assignment_id: id(153), previous_status: 'closed', status: 'active' } });
    });
    await page.getByRole('button', { name: 'Change program', exact: true }).click();
    const drawer = page.locator('#record-drawer');
    await drawer.getByLabel('Academic program', { exact: false }).selectOption(next);
    await drawer.getByLabel('Program entry term', { exact: false }).selectOption(term);
    await drawer.getByLabel('Reason for this program change', { exact: false }).fill('Fictional registrar program change.');
    await drawer.getByRole('button', { name: 'Review program change', exact: true }).click();
    await expect(drawer).toContainText('Changes from BS.CS');
    await expect(drawer).toContainText('not institutional withdrawal');
    await expect(drawer).toContainText('Fictional new <b>program</b>');
    await expect(drawer.locator('b')).toHaveCount(0);
    await drawer.getByRole('button', { name: 'Revise program change', exact: true }).click();
    await expect(drawer.getByLabel('Reason for this program change', { exact: false })).toHaveValue('Fictional registrar program change.');
    await drawer.getByRole('button', { name: 'Review program change', exact: true }).click();
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(calls.filter(c => c.path.endsWith('/commit/'))).toHaveLength(0);
    await drawer.getByRole('button', { name: 'Confirm program change', exact: true }).click();
    await expect(drawer).toContainText('The new pursuit is current and the former pursuit is closed.');
    const requirementRequests = f.calls.filter(c => c.path.includes('degree-requirements')).length;
    await drawer.getByRole('button', { name: 'Review earlier coursework', exact: true }).click();
    const studio = page.locator('dialog.degree-audit-studio');
    await expect(studio).toContainText('Receiving pursuit: BA.ART');
    await expect(studio.locator('b')).toHaveCount(0);
    expect(calls.at(-1).body).toEqual({ assignment_id: id(153) });
    expect(f.calls.filter(c => c.path.includes('degree-requirements')).length).toBe(requirementRequests);
    await accessible(page);
    await studio.getByRole('button', { name: 'Back to student', exact: true }).click();
    await expect(studio).not.toBeVisible();
    expect(calls.filter(c => c.path.endsWith('/commit/'))).toHaveLength(1);
    expect(calls.every(c => c.csrf === 'fictional-test-token')).toBe(true);
    expect(calls.filter(c => c.path.endsWith('/commit/'))[0].body).toEqual({ review_token: 'fictional-program-change' });
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});
