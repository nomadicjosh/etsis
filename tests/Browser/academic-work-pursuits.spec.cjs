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
            owner, reuse_assignment_ids: reused ? [receiving] : [] }], receiving: current, next_after: null };
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

test('selected earlier coursework survives paging and revision, then confirms together with a retry', async ({ page }) => {
    const f = await fixture(page);
    const student = id(1), receiving = id(2), old = id(120), first = id(121), second = id(122);
    const base = `/sis/students/${student}/academic-work-pursuits/`;
    const previous = { id: old, program_code: 'BA.OLD', program_title: 'Earlier program', entry_term_code: '22/FA', status: 'closed' };
    const current = { id: receiving, program_code: 'BS.CS', program_title: 'Computing', entry_term_code: '26/FA', status: 'active' };
    const work = (credit, code, owner = previous, reuse = []) => ({ id: credit, course_code: code,
        course_title: 'Fictional <b>retained title</b>', term_code: '22/FA', credit_type: 'institutional', owner,
        reuse_assignment_ids: reuse });
    const records = [work(first, 'ENG.101'), work(second, 'HIST.101')];
    const calls = [];
    let attempts = 0, approved = false;
    await page.route(`**${base}**`, async route => {
        const path = new URL(route.request().url()).pathname;
        const body = JSON.parse(new URLSearchParams(route.request().postData()).get('_workspace_json'));
        calls.push({ path, body, csrf: route.request().headers()['x-csrf-token'] });
        let result;
        if (path === base) result = { receiving: current,
            items: (body.after ? [records[1], work(id(123), 'UNKNOWN', null), work(id(124), 'CURRENT', current),
                work(id(125), 'REUSED', previous, [receiving])] : [records[0]])
                .map(row => approved && [first, second].includes(row.id) ? { ...row, reuse_assignment_ids: [receiving] } : row),
            next_after: body.after ? null : first };
        else if (path === `${base}reuse/review/`) result = { review_token: 'fictional-selection', target: current,
            items: records.map(row => ({ ...row, owner: previous })) };
        else if (path === `${base}reuse/commit/`) {
            if (++attempts === 1) return route.fulfill({ status: 503, json: { message: 'Fictional temporary interruption.' } });
            approved = true;
            result = { operation_id: id(126), assignment_id: receiving, approved: 2 };
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.getByRole('button', { name: 'Work attribution & reuse', exact: true }).click();
    const studio = page.locator('dialog.degree-audit-studio');
    await expect(studio.getByRole('button', { name: 'Review selected coursework', exact: true })).toBeDisabled();
    await studio.getByRole('checkbox', { name: /^Select ENG.101/ }).check();
    await studio.getByRole('button', { name: 'Next page', exact: true }).click();
    await expect(studio.getByRole('checkbox')).toHaveCount(1);
    await studio.getByRole('checkbox', { name: /^Select HIST.101/ }).check();
    await expect(studio.getByRole('region', { name: 'Selected earlier coursework' })).toContainText('2 of 20');
    await studio.getByRole('button', { name: 'Previous page', exact: true }).click();
    await expect(studio.getByRole('checkbox', { name: /^Select ENG.101/ })).toBeChecked();
    await studio.getByRole('button', { name: 'Review selected coursework', exact: true }).click();
    const reason = 'Fictional registrar evidence supports both earlier courses.';
    await studio.getByLabel('Reason and evidence for the selected coursework').fill(reason);
    await studio.getByRole('button', { name: 'Back to work selection', exact: true }).click();
    await expect(studio.getByRole('checkbox', { name: /^Select ENG.101/ })).toBeChecked();
    await studio.getByRole('button', { name: 'Review selected coursework', exact: true }).click();
    await expect(studio.getByLabel('Reason and evidence for the selected coursework')).toHaveValue(reason);
    await studio.getByRole('button', { name: 'Review reuse approvals', exact: true }).click();
    await expect(studio).toContainText('Original pursuit: BA.OLD');
    await expect(studio.locator('b')).toHaveCount(0);
    await studio.getByRole('button', { name: 'Revise reason', exact: true }).click();
    await expect(studio.getByLabel('Reason and evidence for the selected coursework')).toHaveValue(reason);
    await studio.getByRole('button', { name: 'Review reuse approvals', exact: true }).click();
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(attempts).toBe(0);
    await studio.getByRole('button', { name: 'Confirm selected reuse approvals', exact: true }).click();
    await expect(studio.getByRole('alert')).toContainText('The request could not be completed.');
    await studio.getByRole('button', { name: 'Confirm selected reuse approvals', exact: true }).click();
    await expect(studio).toContainText('2 reuse approvals recorded.');
    await expect(studio.getByRole('region', { name: 'Selected earlier coursework' })).toContainText('0 of 20');
    await accessible(page);
    expect(calls.filter(c => c.path.endsWith('reuse/review/')).map(c => c.body)).toEqual([
        { assignment_id: receiving, credit_ids: [first, second], reason },
        { assignment_id: receiving, credit_ids: [first, second], reason },
    ]);
    expect(calls.filter(c => c.path.endsWith('reuse/commit/')).map(c => c.body)).toEqual([
        { review_token: 'fictional-selection' }, { review_token: 'fictional-selection' },
    ]);
    expect(calls.filter(c => c.path === base).every(c => c.body.assignment_id === receiving)).toBe(true);
    expect(calls.every(c => c.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(f.errors).toEqual([]);
});

test('a closed receiving pursuit keeps earlier decisions visible without offering new reuse', async ({ page }) => {
    const f = await fixture(page);
    await page.route(`**/sis/students/${id(1)}/academic-work-pursuits/**`, route => route.fulfill({ json: {
        receiving: { id: id(2), program_code: 'BS.CS', program_title: 'Computing', entry_term_code: '26/FA', status: 'closed' },
        items: [{ id: id(121), course_code: 'ENG.101', course_title: 'Fictional English', credit_type: 'institutional',
            owner: { id: id(120), program_code: 'BA.OLD', program_title: 'Earlier degree', entry_term_code: '22/FA', status: 'closed' },
            reuse_assignment_ids: [id(2)] }], next_after: null,
    } }));
    await page.getByRole('button', { name: 'Work attribution & reuse', exact: true }).click();
    const studio = page.locator('dialog.degree-audit-studio');
    await expect(studio).toContainText('This receiving pursuit is closed.');
    await expect(studio.getByRole('checkbox')).toHaveCount(0);
    await expect(studio.getByRole('button', { name: 'Approve reuse for this pursuit', exact: true })).toHaveCount(0);
    await expect(studio.getByRole('button', { name: 'Review selected coursework', exact: true })).toBeDisabled();
    await expect(studio.getByRole('button', { name: 'Revoke reuse for this pursuit', exact: true })).toBeVisible();
    expect(f.errors).toEqual([]);
});
