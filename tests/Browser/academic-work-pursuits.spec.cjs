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

test('registrars inspect frozen decision pages and return to their unchanged selection and reason', async ({ page }) => {
    const f = await fixture(page);
    const student = id(1), receiving = id(2), credit = id(121), old = id(120);
    const base = `/sis/students/${student}/academic-work-pursuits/`;
    const previous = { id: old, program_code: 'BA.OLD', program_title: 'Earlier <b>fictional degree</b>',
        entry_term_code: '22/FA', status: 'closed' };
    const current = { id: receiving, program_code: 'BS.CS', program_title: 'Computing', entry_term_code: '26/FA', status: 'active' };
    const work = { id: credit, course_code: 'ENG.101', course_title: 'Fictional <b>writing</b>',
        credit_type: 'institutional', term_code: '22/FA', owner: previous, reuse_assignment_ids: [] };
    const calls = [];
    let firstPages = 0;
    await page.route(`**${base}**`, async route => {
        const path = new URL(route.request().url()).pathname;
        const body = JSON.parse(new URLSearchParams(route.request().postData()).get('_workspace_json'));
        calls.push({ path, body, csrf: route.request().headers()['x-csrf-token'] });
        let result;
        if (path === base) result = { receiving: current, items: [work], next_after: null };
        else if (path === `${base}${credit}/history/`) {
            const item = (version, action, pursuit) => ({ version, action, pursuit,
                effective_at: '2026-11-14T10:00:00-08:00', recorded_at: '2026-11-14T18:00:01+00:00' });
            if (body.cursor) result = { items: [item(21, 'revoke_reuse', current)], next_cursor: null,
                through_version: 21, pursuit_labels: 'current' };
            else result = { items: [item(1, 'attribute', previous), item(2, 'approve_reuse', current),
                item(3, 'correct_attribution', current)], next_cursor: 'fictional-frozen-history',
                through_version: ++firstPages > 1 ? 22 : 21, pursuit_labels: 'current' };
        }
        await route.fulfill({ json: result });
    });
    await page.getByRole('button', { name: 'Work attribution & reuse', exact: true }).click();
    const studio = page.locator('dialog.degree-audit-studio');
    await studio.getByRole('checkbox').check();
    await studio.getByRole('button', { name: 'Review selected coursework', exact: true }).click();
    const reason = 'Fictional evidence kept while reviewing recorded decisions.';
    await studio.getByLabel('Reason and evidence for the selected coursework').fill(reason);
    await studio.getByRole('button', { name: 'Back to work selection', exact: true }).click();
    expect(calls.filter(c => c.path.endsWith('/history/'))).toHaveLength(0);
    await studio.getByRole('button', { name: 'Attribution & reuse history', exact: true }).click();
    await expect(studio).toContainText('Decision 1 · Original pursuit recorded');
    await expect(studio).toContainText('Decision 2 · Reuse approved');
    await expect(studio).toContainText('Decision 3 · Original pursuit corrected');
    await expect(studio).toContainText('not labels captured when each decision was made');
    await expect(studio.locator('b')).toHaveCount(0);
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    await studio.getByRole('button', { name: 'Next page', exact: true }).click();
    await expect(studio).toContainText('Decision 21 · Reuse revoked');
    await expect(studio).toContainText('through decision 21');
    await expect(studio.getByRole('button', { name: 'Next page', exact: true })).toHaveCount(0);
    await studio.getByRole('button', { name: 'Refresh decision history', exact: true }).click();
    await expect(studio).toContainText('through decision 22');
    await expect(studio.getByRole('button', { name: 'Previous page', exact: true })).toHaveCount(0);
    await studio.getByRole('button', { name: 'Back to work selection', exact: true }).click();
    await expect(studio.getByRole('checkbox')).toBeChecked();
    await studio.getByRole('button', { name: 'Review selected coursework', exact: true }).click();
    await expect(studio.getByLabel('Reason and evidence for the selected coursework')).toHaveValue(reason);
    await accessible(page);
    expect(calls.filter(c => c.path.endsWith('/history/')).map(c => c.body)).toEqual([
        {}, { cursor: 'fictional-frozen-history' }, {},
    ]);
    expect(calls.some(c => c.path.endsWith('/review/') || c.path.endsWith('/commit/'))).toBe(false);
    expect(calls.every(c => c.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(f.errors).toEqual([]);
});

test('an empty history explains unknown attribution and a late response cannot reopen a closed review', async ({ page }) => {
    const f = await fixture(page);
    const base = `/sis/students/${id(1)}/academic-work-pursuits/`, credit = id(121);
    let release, entered;
    const waiting = new Promise(resolve => { entered = resolve; });
    const pause = new Promise(resolve => { release = resolve; });
    let histories = 0;
    await page.route(`**${base}**`, async route => {
        if (route.request().url().endsWith('/history/')) {
            if (++histories > 1) { entered(); await pause; }
            return route.fulfill({ json: { items: [], next_cursor: null, through_version: 0, pursuit_labels: 'current' } });
        }
        await route.fulfill({ json: { receiving: { id: id(2), program_code: 'BS.CS', program_title: 'Computing',
            entry_term_code: '26/FA', status: 'active' }, items: [{ id: credit, course_code: 'ENG.101',
            course_title: 'Fictional English', credit_type: 'transfer', owner: null, reuse_assignment_ids: [] }], next_after: null } });
    });
    await page.getByRole('button', { name: 'Work attribution & reuse', exact: true }).click();
    const studio = page.locator('dialog.degree-audit-studio');
    await studio.getByRole('button', { name: 'Attribution & reuse history', exact: true }).click();
    await expect(studio.getByRole('status')).toContainText('No pursuit decisions have been recorded');
    await studio.getByRole('button', { name: 'Refresh decision history', exact: true }).click();
    await waiting;
    await studio.getByRole('button', { name: 'Close builder', exact: true }).click();
    const response = page.waitForResponse(r => r.url().endsWith('/history/'));
    release();
    await response;
    await page.waitForFunction(() => document.querySelector('dialog.degree-audit-studio') === null);
    await expect(studio).toHaveCount(0);
    expect(f.errors).toEqual([]);
});
