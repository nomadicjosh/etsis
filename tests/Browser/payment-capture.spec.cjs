const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction } = require('./support/workspace-actions.cjs');
const id = n => `01K${String(n).padStart(23, '0')}`;
const account = id(6), term = id(2), student = id(4), payment = id(15);
async function fixture(page, portal = false) {
    const state = { phase: 'reserved', denied: false, next: false, calls: [], errors: [] };
    const row = { id: account, student_id: student, term_id: term, currency: 'USD', balance_minor: 12345,
        institutional_id: 'FICT.001', title: 'Fictional account' };
    page.on('pageerror', e => state.errors.push(e.message));
    await page.route(/\/(sis|portal\/api)\//, async route => {
        const r = route.request(), u = new URL(r.url()), path = u.pathname;
        const data = r.postData() ? JSON.parse(new URLSearchParams(r.postData()).get('_workspace_json') || '{}') : {};
        state.calls.push({ path, data, csrf: r.headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/accounts/') result = { items: [row], next_after: null };
        else if (path === `/sis/accounts/${account}/` || path === `/portal/api/accounts/${account}/`) result = { ...row, entries: [], position: 4 };
        else if (path === '/portal/api/me/') result = { person: { id: id(3), given_name: 'Fictional', family_name: 'Student', institutional_id: 'FICT.001' },
            contact: { preferred_name: '', phone: '', address: '', version: 0 }, students: [], applications: [], restrictions: [],
            accounts: [row], transcript_requests: [], news: [], payment_id: payment };
        else if (path.endsWith('/payments/status/')) result = { enabled: true };
        else if (path.includes('/payment-activity/') && path.endsWith('/retry/')) result = { status: 'queued' };
        else if (path.includes('/payment-activity/')) {
            if (state.denied) return route.fulfill({ status: 403, json: { error: 'Fictional access lost.' } });
            result = { items: [{ id: payment, currency: 'USD', authorized_minor: 12345, capture_minor: 11345,
                received_minor: state.phase === 'received' ? 11345 : null, state: state.phase, can_retry: true }],
                held_minor: 11345, next_after: state.next && !u.searchParams.has('after') ? payment : null };
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    if (portal) {
        await page.goto('/portal/student/accounts/');
        await page.getByRole('button', { name: 'Payment activity', exact: true }).click();
    } else {
        await page.goto('/admin/workspace/accounts/');
        await page.locator('.record-link').first().click();
        await recordAction(page, 'Payment activity');
    }
    return state;
}

test('staff sees the reserved ceiling, queues verification with CSRF and returns to the record', async ({ page }) => {
    const state = await fixture(page), body = page.locator('#drawer-body');
    await expect(body).toContainText('Capture reserved');
    await expect(body).toContainText('113.45');
    await body.getByRole('button', { name: 'Request verification', exact: true }).click();
    await expect(body).toContainText('Verification is queued on the server');
    const retry = state.calls.find(c => c.path.endsWith('/retry/'));
    expect(retry.csrf).toBe('fictional-test-token'); expect(retry.data).toEqual({});
    await body.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(body).toContainText('Account statement'); expect(state.errors).toEqual([]);
});

test('student activity separates authorization, received funds and posting at narrow widths', async ({ page }) => {
    const state = await fixture(page, true), body = page.locator('#campus-dialog-body');
    state.phase = 'received';
    await body.getByRole('button', { name: 'Refresh payment activity', exact: true }).click();
    await expect(body).toContainText('Funds received · Awaiting ledger posting');
    await expect(body).toContainText('Verified received amount');
    await page.setViewportSize({ width: 320, height: 850 });
    const scan = await new AxeBuilder({ page }).include('#campus-dialog').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations).toEqual([]);
    expect(await body.evaluate(e => e.scrollWidth <= e.clientWidth + 1)).toBe(true);
    await page.screenshot({ path: test.info().outputPath('payment-activity-narrow.png') });
    await body.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.locator('#campus-dialog')).not.toBeVisible(); expect(state.errors).toEqual([]);
});

test('paging and provider review keep the retained amount visible without changing it', async ({ page }) => {
    const state = await fixture(page), body = page.locator('#drawer-body');
    state.next = true; state.phase = 'review';
    await body.getByRole('button', { name: 'Refresh payment activity', exact: true }).click();
    await expect(body).toContainText('Provider reconciliation required');
    await body.getByRole('button', { name: 'Next page', exact: true }).click();
    await expect(body).toContainText('Maximum capture');
    expect(state.calls.some(c => c.path.endsWith('/checkout/'))).toBe(false);
    expect(state.errors).toEqual([]);
});

test('access loss clears the activity and removes retry controls', async ({ page }) => {
    const state = await fixture(page, true), body = page.locator('#campus-dialog-body');
    state.denied = true;
    await body.getByRole('button', { name: 'Refresh payment activity', exact: true }).click();
    await expect(body).toContainText('Payment activity is unavailable with your current access.');
    await expect(body.getByRole('button', { name: 'Request verification', exact: true })).toHaveCount(0);
});
