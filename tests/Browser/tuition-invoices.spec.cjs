const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');
const drawer = page => page.locator('#drawer-body');
async function fixture(page) {
    const state = { calls: [], errors: [], deny: false, stale: false, definition: null };
    const income = { id: id(1), code: 'REV', title: 'Fictional income', kind: 'income', currency: 'USD' };
    const bill = { id: id(2), student_label: 'Fictional Alex', term_label: 'Fictional August', schedule_name: 'Fictional tuition',
        currency: 'USD', version: 2, credits_milli: 3000, pending_minor: 31000, adjustment_minor: 0, can_reprice: true,
        default_due_on: '2026-08-31', invoices: [], next_invoice_after: null, lines: [
            { key: 'tuition', name: 'Fictional tuition <script>', quoted_minor: 30000, minor: 30000 },
            { key: 'section:fictional', name: 'Section fee', quoted_minor: 1000, minor: 1000 }] };
    page.on('pageerror', e => state.errors.push(e.message));
    await page.route('**/sis/**', async route => {
        const req = route.request(), url = new URL(req.url()), path = url.pathname;
        const data = req.postData() ? JSON.parse(new URLSearchParams(req.postData()).get('_workspace_json')) : {};
        state.calls.push({ path, data, csrf: req.headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/ledger/') result.items = [income];
        else if (path === '/sis/ui/data/terms/') result.items = [{ id: id(5), code: 'Fictional August' }];
        else if (path === '/sis/tuition/bills/') {
            if (state.deny) return route.fulfill({ status: 403, json: { error: 'Fictional access lost.' } });
            result.items = [bill];
        } else if (path === `/sis/tuition/bills/${bill.id}/`) result = bill;
        else if (path === '/sis/tuition/schedules/') result.items = [{ id: id(4), version: 1,
            definition: { name: 'Fictional schedule', currency: 'USD' } }];
        else if (path === '/sis/tuition/assignment/') result = { scope: url.searchParams.get('scope'),
            scope_id: url.searchParams.get('scope_id'), label: 'Fictional August', record: null };
        else if (path.endsWith('/review/') && path.startsWith('/sis/tuition/')) {
            state.definition = data;
            if (state.hold) await state.hold;
            result = { definition: { ...data, reference: `INV-${id(3)}`, income }, reason: data.reason, review_token: 'fictional-review' };
        } else if (path === '/sis/tuition/confirm/') {
            if (state.stale) { state.stale = false; return route.fulfill({ status: 409, json: { error: 'Fictional source changed.' } }); }
            result = { id: id(3), bill_id: bill.id };
        } else if (path === `/sis/tuition/invoices/${id(3)}/`) result = {
            reference: `INV-${id(3)}`, recipient: { name: 'Fictional Alex' }, term_label: bill.term_label,
            issued_at: '2026-08-10T12:00:00Z', due_on: '2026-08-31', currency: 'USD',
            lines: state.definition.lines.map(l => ({ ...l, name: bill.lines.find(b => b.key === l.key).name })), total_minor: 25000 };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/workspace/accounts/');
    await page.getByRole('button', { name: 'Tuition and invoices', exact: false }).click();
    return state;
}

test('pending lines can be adjusted or excluded with stale-safe confirmation and private print access', async ({ page }) => {
    const state = await fixture(page);
    await drawer(page).getByRole('button', { name: 'Review term bill', exact: true }).click();
    await expect(drawer(page)).toContainText('Not yet payable');
    await drawer(page).getByRole('button', { name: 'Review and issue invoice', exact: true }).click();
    await page.getByLabel('Amount to invoice').first().fill('250.00');
    await drawer(page).getByRole('button', { name: 'Remove from this invoice', exact: true }).last().click();
    await page.getByLabel('Reason for this billing decision').fill('Fictional reviewed adjustment.');
    await drawer(page).getByRole('button', { name: 'Review billing decision', exact: true }).click();
    expect(state.definition.lines.map(l => l.minor)).toEqual([25000, 0]);
    await expect(drawer(page).locator('script')).toHaveCount(0);
    state.stale = true;
    await drawer(page).getByRole('button', { name: 'Confirm billing decision', exact: true }).click();
    await expect(page.getByLabel('Amount to invoice').first()).toHaveValue('250.00');
    await expect(drawer(page).getByRole('button', { name: 'Confirm billing decision', exact: true })).toHaveCount(0);
    await drawer(page).getByRole('button', { name: 'Review billing decision', exact: true }).click();
    await drawer(page).getByRole('button', { name: 'Confirm billing decision', exact: true }).click();
    await expect(drawer(page).getByRole('link', { name: 'Print invoice' })).toHaveAttribute('href', `/sis/tuition/invoices/${id(3)}/print/`);
    for (const call of state.calls.filter(c => c.path.endsWith('/confirm/') || c.path.endsWith('/review/')))
        expect(call.csrf).toBe('fictional-test-token');
    expect(state.errors).toEqual([]);
});

test('schedule builder preserves precise bracket quantities, keyboard controls and narrow reflow', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 780 });
    const state = await fixture(page);
    await drawer(page).getByRole('button', { name: 'Tuition schedules', exact: true }).click();
    await drawer(page).getByRole('button', { name: 'Create tuition schedule', exact: true }).click();
    await page.getByRole('textbox', { name: 'Schedule name', exact: true }).fill('Fictional precise tuition');
    await page.getByLabel('Classified tuition income account').selectOption(id(1));
    await page.getByLabel('Reason for this billing decision').fill('Fictional bracket publication.');
    await page.getByLabel('Through credits (inclusive)').fill('11.999');
    await page.getByLabel('Amount per additional credit').fill('100.00');
    await drawer(page).getByRole('button', { name: 'Add bracket', exact: true }).click();
    await expect(drawer(page).locator('fieldset')).toHaveCount(2);
    await page.getByLabel('From credits (inclusive)').last().fill('12.000');
    await page.getByRole('spinbutton', { name: 'Flat amount', exact: true }).last().fill('1000.00');
    await drawer(page).getByRole('button', { name: 'Review billing decision', exact: true }).click();
    expect(state.definition.brackets[0]).toMatchObject({ through_milli: 11999, rate_minor: 10000 });
    expect(state.definition.brackets[1]).toMatchObject({ from_milli: 12000, flat_minor: 100000 });
    await page.getByRole('textbox', { name: 'Schedule name', exact: true }).fill('Fictional revised tuition');
    await expect(drawer(page).getByRole('button', { name: 'Confirm billing decision', exact: true })).toHaveCount(0);
    expect(await page.locator('#record-drawer').evaluate(e => e.scrollWidth <= e.clientWidth + 1)).toBeTruthy();
    const result = await new AxeBuilder({ page }).include('#record-drawer').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations).toEqual([]);
    if (process.env.ETSIS_TUITION_SCREENSHOT) await page.locator('#record-drawer').screenshot({ path: process.env.ETSIS_TUITION_SCREENSHOT });
    expect(state.errors).toEqual([]);
});

test('owners can see pending tuition without an account and open their original printable invoice', async ({ page }) => {
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    const issued = { id: id(3), reference: `INV-${id(3)}`, issued_at: '2026-08-10T12:00:00Z', total_minor: 30000 };
    const bill = { id: id(2), student_label: 'Fictional Alex', term_label: 'Fictional August', currency: 'USD', pending_minor: 1000,
        lines: [{ key: 'section', name: 'Fictional course', minor: 1000 }], invoices: [issued], next_invoice_after: null };
    await page.route('**/portal/api/**', async route => {
        const path = new URL(route.request().url()).pathname;
        let result = { items: [], next_after: null };
        if (path === '/portal/api/me/') result = { person: { id: id(1), given_name: 'Fictional', family_name: 'Student', institutional_id: 'FICT.001' },
            contact: { preferred_name: '', phone: '', address: '', version: 0 }, students: [], applications: [], restrictions: [],
            accounts: [], transcript_requests: [], news: [] };
        else if (path === '/portal/api/tuition/bills/') result.items = [bill];
        else if (path === `/portal/api/tuition/bills/${id(2)}/`) result = bill;
        else if (path === `/portal/api/tuition/invoices/${id(3)}/`) result = { ...issued, recipient: { name: 'Fictional Alex' },
            term_label: bill.term_label, due_on: '2026-08-31', currency: 'USD', lines: [{ name: 'Fictional tuition', minor: 30000 }] };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/portal/student/accounts/');
    await page.getByRole('button', { name: 'Review term bill', exact: true }).click();
    await expect(page.locator('#campus-dialog-body')).toContainText('pending estimates');
    await page.locator('#campus-dialog-body').getByRole('button', { name: 'Student invoice', exact: true }).click();
    await expect(page.locator('#campus-dialog-body').getByRole('link', { name: 'Print invoice' })).toHaveAttribute('href',
        `/portal/api/tuition/invoices/${id(3)}/print/`);
    expect(errors).toEqual([]);
});


test('term assignments load the current version and invalidate reviews after selection changes', async ({ page }) => {
    const state = await fixture(page);
    await drawer(page).getByRole('button', { name: 'Assign a tuition schedule', exact: true }).click();
    await page.getByLabel('Assignment target').selectOption(id(5));
    await expect(drawer(page)).toContainText('Current assignment loaded.');
    await page.getByLabel('Tuition schedule', { exact: true }).selectOption(id(4));
    await page.getByLabel('Reason for this billing decision').fill('Fictional term assignment.');
    await drawer(page).getByRole('button', { name: 'Review billing decision', exact: true }).click();
    expect(state.definition).toMatchObject({ scope: 'term', scope_id: id(5), schedule_id: id(4), version: 0 });
    await page.getByLabel('Tuition schedule', { exact: true }).selectOption('');
    await expect(drawer(page).getByRole('button', { name: 'Confirm billing decision', exact: true })).toHaveCount(0);
    expect(state.errors).toEqual([]);
});
