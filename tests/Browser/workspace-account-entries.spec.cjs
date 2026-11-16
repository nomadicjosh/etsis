const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => `01K000000000000000000000${String(n).padStart(2, '0')}`;

async function fixture(page, options = {}) {
    const calls = [], errors = [];
    let position = 2, balance = -1000, corrected = false, reviewNumber = 0, interrupt = false, posted = null;
    const postingReviews = new Map();
    const currency = options.currency || 'USD';
    page.on('pageerror', e => errors.push(e.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = {}, status = 200;
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/accounts/') result = { items: [{ id: id(1), given_name: 'Alex',
            family_name: 'Example', institutional_id: 'S-FICTIONAL', currency }], next_after: null };
        else if (path === `/sis/accounts/${id(1)}/`) result = {
            id: id(1), student_id: id(2), term_id: id(3), institutional_id: 'S-FICTIONAL', currency,
            position, balance_minor: balance,
            entries: [{ operation_id: id(4), type: 'payment', minor: 1000, currency, reversed: corrected,
                can_correct_amount: options.eligible !== false && !corrected,
                ...(corrected ? { corrected_by_operation_id: id(5) } : {}) },
                ...(corrected ? [{ operation_id: id(5), type: 'payment', minor: 1250, currency, reversed: false,
                    can_correct_amount: true, corrects_operation_id: id(4) }] : []),
                ...(posted ? [{ operation_id: id(6), type: posted.type, minor: posted.amount_minor, currency,
                    reversed: false, can_correct_amount: true }] : [])],
        };
        else if (path.endsWith('/correction/review/')) {
            reviewNumber++;
            result = { review_token: `fictional-correction-${reviewNumber}`, type: 'payment', original_minor: 1000,
                corrected_minor: Number(data.amount_minor), currency, balance_minor: balance,
                resulting_balance_minor: balance - (Number(data.amount_minor) - 1000), reason: data.reason };
            if (options.delayReview) await options.delayReview();
        } else if (path.endsWith('/correction/commit/')) {
            if (data.review_token === 'fictional-correction-1' && options.stale) {
                status = 409; result = { error: { message: 'This financial record changed. Review the account again.' } };
            } else if (interrupt) {
                interrupt = false; status = 500; result = { error: { message: 'Fictional interrupted response.' } };
            } else {
                corrected = true; position++; balance = -1250;
                result = { status: 'corrected', operation_id: id(5), source_operation_id: id(4), balance_minor: balance, position };
            }
        } else if (path === `/sis/accounts/${id(1)}/entries/review/`) {
            reviewNumber++;
            if (data.type === 'refund' && data.amount_minor > -balance) {
                status = 422; result = { error: { message: 'Please correct the highlighted fields.',
                    details: [{ field: 'amount_minor', message: 'A refund cannot exceed available credit.' }] } };
            } else {
                const next = balance + (data.type === 'payment' ? -data.amount_minor : data.amount_minor);
                result = { review_token: `fictional-posting-${reviewNumber}`, type: data.type,
                    amount_minor: data.amount_minor, currency, balance_minor: balance, resulting_balance_minor: next,
                    available_credit_minor: Math.max(0, -balance), remaining_credit_minor: Math.max(0, -next),
                    reason: data.reason, position };
                postingReviews.set(result.review_token, result);
                if (options.delayReview) await options.delayReview();
            }
        } else if (path === `/sis/accounts/${id(1)}/entries/commit/`) {
            const reviewed = postingReviews.get(data.review_token);
            if (reviewed.position !== position && posted?.review_token !== data.review_token) {
                status = 409; result = { error: { message: 'This financial record changed. Review the account again.' } };
            } else {
                if (!posted) { posted = reviewed; position++; balance = reviewed.resulting_balance_minor; }
                result = { status: 'posted', operation_id: id(6), balance_minor: balance, position };
                if (interrupt) {
                    interrupt = false; status = 500; result = { error: { message: 'Fictional interrupted response.' } };
                }
            }
        }
        await route.fulfill({ status, json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(options.readOnly ? '/admin/account-read-fixture/' : '/admin/');
    await page.locator('.workspace-nav-link[data-workspace="accounts"]').click();
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    return { calls, errors, change: () => { position++; balance += 100; }, interrupt: () => interrupt = true };
}

async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
}

test('staff review one correction, confirm once and see linked immutable originals', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Correct amount', exact: true }).click();
    await page.getByLabel('Corrected amount (USD)').fill('12.50');
    await page.getByLabel('Reason for amount correction').fill('Fictional correction <script>literal only</script>');
    await page.getByRole('button', { name: 'Review amount correction', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Reviewed amount correction' })).toContainText('$10.00 → $12.50');
    expect(f.calls.some(c => c.path.endsWith('/commit/'))).toBe(false);
    await page.setViewportSize({ width: 375, height: 850 });
    await accessible(page);
    await page.getByRole('button', { name: 'Confirm amount correction', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Corrected by');
    await expect(page.locator('#drawer-body')).toContainText('Corrects');
    await expect(page.locator('#drawer-body')).toContainText('Balance: -$12.50');
    expect(f.calls.find(c => c.path.endsWith('/commit/'))).toMatchObject({
        data: { review_token: 'fictional-correction-1' }, csrf: 'fictional-test-token',
    });
    expect(f.calls.some(c => c.path.endsWith('/reverse/') || c.path.endsWith('/entries/'))).toBe(false);
    expect(await page.evaluate(() => ({ local: localStorage.length, session: sessionStorage.length })))
        .toEqual({ local: 0, session: 0 });
    expect(f.errors).toEqual([]);
});

test('a stale correction preserves form values and an interrupted confirmation reuses its proof', async ({ page }) => {
    const f = await fixture(page, { stale: true });
    await page.getByRole('button', { name: 'Correct amount', exact: true }).click();
    const amount = page.getByLabel('Corrected amount (USD)'), reason = page.getByLabel('Reason for amount correction');
    await amount.fill('12.50'); await reason.fill('Fictional reviewed correction.');
    await page.getByRole('button', { name: 'Review amount correction', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm amount correction', exact: true }).click();
    await expect(amount).toHaveValue('12.50'); await expect(reason).toHaveValue('Fictional reviewed correction.');
    f.change();
    await page.getByRole('button', { name: 'Refresh account balance', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Confirm amount correction', exact: true })).toHaveCount(0);
    await expect(amount).toHaveValue('12.50'); await expect(reason).toHaveValue('Fictional reviewed correction.');
    await page.getByRole('button', { name: 'Review amount correction', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Confirm amount correction', exact: true })).toBeVisible();
    f.interrupt();
    await page.getByRole('button', { name: 'Confirm amount correction', exact: true }).click();
    await expect(reason).toBeEnabled();
    await page.getByRole('button', { name: 'Confirm amount correction', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Corrected by');
    expect(f.calls.filter(c => c.path.endsWith('/review/')).map(c => c.data.position)).toEqual([2, 3]);
    expect(f.calls.filter(c => c.path.endsWith('/commit/')).map(c => c.data.review_token))
        .toEqual(['fictional-correction-1', 'fictional-correction-2', 'fictional-correction-2']);
    expect(f.errors).toEqual([]);
});

test('edits invalidate the review and automated or unknown entries offer no amount correction', async ({ page }) => {
    await fixture(page, { eligible: false });
    await expect(page.getByRole('button', { name: 'Correct amount', exact: true })).toHaveCount(0);
    await fixture(page);
    await page.getByRole('button', { name: 'Correct amount', exact: true }).click();
    await page.getByLabel('Corrected amount (USD)').fill('12.50');
    const reason = page.getByLabel('Reason for amount correction');
    await reason.fill('Fictional first reason.');
    await page.getByRole('button', { name: 'Review amount correction', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Confirm amount correction', exact: true })).toBeVisible();
    await reason.fill('Fictional edited reason.');
    await expect(page.getByRole('button', { name: 'Confirm amount correction', exact: true })).toHaveCount(0);
});

test('read-only finance access offers statements without correction controls', async ({ page }) => {
    await fixture(page, { readOnly: true });
    await expect(page.locator('#drawer-body')).toContainText('Balance: -$10.00');
    await expect(page.getByRole('button', { name: 'Correct amount', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Reverse entry', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Post a transaction', exact: true })).toHaveCount(0);
});

test('refund review explains available credit and records only after confirmation', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Post a transaction', exact: true }).click();
    await page.getByLabel('Transaction type').selectOption('refund');
    await page.getByLabel('Amount (USD)').fill('4.00');
    await page.getByLabel('Reason for transaction').fill('Fictional refund recorded after office verification.');
    await page.getByRole('button', { name: 'Review transaction', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Reviewed transaction' })).toContainText('Available credit: $10.00 → $6.00');
    expect(f.calls.some(c => c.path.endsWith('/commit/'))).toBe(false);
    await page.setViewportSize({ width: 375, height: 850 });
    await accessible(page);
    f.interrupt();
    await page.getByRole('button', { name: 'Confirm transaction', exact: true }).click();
    await expect(page.getByLabel('Transaction type')).toBeEnabled();
    await expect(page.getByLabel('Amount (USD)')).toHaveValue('4.00');
    await page.getByRole('button', { name: 'Confirm transaction', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Balance: -$6.00');
    expect(f.calls.filter(c => c.path.endsWith('/entries/commit/')).map(c => c.data))
        .toEqual([{ review_token: 'fictional-posting-1' }, { review_token: 'fictional-posting-1' }]);
    expect(f.calls.filter(c => c.path.endsWith('/entries/commit/')).every(c => c.csrf === 'fictional-test-token')).toBe(true);
    expect(f.calls.some(c => c.path.endsWith('/entries/'))).toBe(false);
    expect(f.errors).toEqual([]);
});

test('over-refunds and stale confirmations keep inputs while refreshing requires a new review', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Post a transaction', exact: true }).click();
    await page.getByLabel('Transaction type').selectOption('refund');
    const amount = page.getByLabel('Amount (USD)'), reason = page.getByLabel('Reason for transaction');
    await amount.fill('11.00'); await reason.fill('Fictional retained refund reason.');
    await page.getByRole('button', { name: 'Review transaction', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Confirm transaction', exact: true })).toHaveCount(0);
    await expect(amount).toHaveValue('11.00');
    await amount.fill('4.00');
    await page.getByRole('button', { name: 'Review transaction', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Confirm transaction', exact: true })).toBeVisible();
    f.change();
    await page.getByRole('button', { name: 'Confirm transaction', exact: true }).click();
    await expect(reason).toBeEnabled();
    await page.getByRole('button', { name: 'Refresh account balance', exact: true }).click();
    await expect(reason).toHaveValue('Fictional retained refund reason.');
    await expect(amount).toHaveValue('4.00');
    await expect(page.getByRole('button', { name: 'Confirm transaction', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Review transaction', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Reviewed transaction' })).toContainText('Available credit: $9.00 → $5.00');
    await page.getByLabel('Transaction type').selectOption('payment');
    await expect(page.getByRole('button', { name: 'Confirm transaction', exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('a late review cannot restore a confirmation after the form was edited', async ({ page }) => {
    let release;
    const waiting = new Promise(resolve => release = resolve);
    const f = await fixture(page, { delayReview: () => waiting });
    await page.getByRole('button', { name: 'Correct amount', exact: true }).click();
    await page.getByLabel('Corrected amount (USD)').fill('12.50');
    const reason = page.getByLabel('Reason for amount correction');
    await reason.fill('Fictional original reason.');
    await page.getByRole('button', { name: 'Review amount correction', exact: true }).click();
    await expect.poll(() => f.calls.filter(c => c.path.endsWith('/review/')).length).toBe(1);
    await reason.fill('Fictional edited reason.');
    await reason.fill('Fictional original reason.');
    release();
    await expect(page.getByRole('button', { name: 'Review amount correction', exact: true })).toBeEnabled();
    await expect(page.getByRole('button', { name: 'Confirm amount correction', exact: true })).toHaveCount(0);
});

for (const [currency, input, minor] of [['JPY', '1250', 1250], ['KWD', '1.250', 1250]]) {
    test(`amount corrections preserve ${currency} precision`, async ({ page }) => {
        const f = await fixture(page, { currency });
        await page.getByRole('button', { name: 'Correct amount', exact: true }).click();
        await page.getByLabel(`Corrected amount (${currency})`).fill(input);
        await page.getByLabel('Reason for amount correction').fill('Fictional exact currency correction.');
        await page.getByRole('button', { name: 'Review amount correction', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Confirm amount correction', exact: true })).toBeVisible();
        expect(f.calls.find(c => c.path.endsWith('/review/')).data).toMatchObject({ amount_minor: minor, currency });
    });
}
