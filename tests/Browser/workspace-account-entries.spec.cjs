const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => `01K000000000000000000000${String(n).padStart(2, '0')}`;

async function fixture(page, options = {}) {
    const calls = [], errors = [];
    let position = 2, balance = -1000, corrected = false, reviewNumber = 0, interrupt = false, posted = null, sourceReversed = false, acceptedReversal = null;
    const postingReviews = new Map(), receiptReviews = new Map(), reversalReviews = new Map();
    let receiptDetails = options.receipt || null;
    let historyHead = 26;
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
            entries: [{ operation_id: id(4), type: 'payment', minor: 1000, currency, reversed: corrected || sourceReversed,
                can_correct_amount: options.eligible !== false && !corrected && !sourceReversed,
                can_correct_payment_details: options.eligible !== false,
                ...(corrected ? { corrected_by_operation_id: id(5) } : {}) },
                ...(corrected ? [{ operation_id: id(5), type: 'payment', minor: 1250, currency, reversed: false,
                    can_correct_amount: true, can_correct_payment_details: true, corrects_operation_id: id(4) }] : []),
                ...(posted ? [{ operation_id: id(6), type: posted.type, minor: posted.amount_minor, currency,
                    reversed: false, can_correct_amount: true, can_correct_payment_details: posted.type !== "charge" }] : [])],
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
                    reason: data.reason, position, payment_details: data.payment_method || data.payment_reference
                        ? { method: data.payment_method || null, reference: data.payment_reference || null } : null };
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
        } else if (path.endsWith('/reverse/review/')) {
            reviewNumber++;
            result = { review_token: `fictional-reversal-${reviewNumber}`, type: 'payment', amount_minor: 1000,
                currency, balance_minor: balance, resulting_balance_minor: balance + 1000,
                reason: data.reason, position, payment_details: receiptDetails };
            reversalReviews.set(result.review_token, result);
            if (options.delayReview) await options.delayReview();
        } else if (path.endsWith('/reverse/commit/')) {
            const reviewed = reversalReviews.get(data.review_token);
            if ((options.staleReversal && data.review_token === 'fictional-reversal-1')
                || (reviewed.position !== position && acceptedReversal?.token !== data.review_token)) {
                status = 409; result = { error: { message: 'This financial record changed. Review the account again.' } };
            } else {
                if (!acceptedReversal) {
                    sourceReversed = true; balance = reviewed.resulting_balance_minor; position++;
                    acceptedReversal = { token: data.review_token, result: { status: 'reversed', operation_id: id(4), balance_minor: balance, position } };
                }
                result = acceptedReversal.result;
                if (interrupt) {
                    interrupt = false; status = 500; result = { error: { message: 'Fictional interrupted reversal response.' } };
                }
            }
        } else if (path.endsWith('/receipt/')) {
            result = { payment_details: receiptDetails, position, entry_operation_id: id(4) };
            if (options.delayReceipt) await options.delayReceipt();
        } else if (path.endsWith('/receipt/review/')) {
            reviewNumber++;
            result = { review_token: `fictional-receipt-${reviewNumber}`, type: 'payment', amount_minor: 1000,
                currency, balance_minor: balance, resulting_balance_minor: balance, reason: data.reason, position,
                previous_payment_details: receiptDetails,
                payment_details: { method: data.payment_method || null, reference: data.payment_reference || null } };
            receiptReviews.set(result.review_token, result);
        } else if (path.endsWith('/receipt/commit/')) {
            const accepted = receiptReviews.get(data.review_token);
            if (position === accepted.position) { receiptDetails = accepted.payment_details; position++; }
            result = { status: 'receipt_corrected', balance_minor: balance, position };
            if (interrupt) {
                interrupt = false; status = 500; result = { error: { message: 'Fictional interrupted receipt response.' } };
            }
        } else if (path === `/sis/accounts/${id(1)}/entries/${id(4)}/history/`) {
            const through = data.through_position ?? historyHead, after = data.after || 0;
            const history = Array.from({ length: through - 1 }, (_, i) => ({
                sequence: i + 2, operation_id: i ? id(30 + i) : id(4),
                ...(i ? { source_operation_id: i === 1 ? id(4) : id(29 + i), previous_minor: 999 + i } : {}),
                action: i ? 'corrected' : 'posted', type: 'payment', currency, amount_minor: 1000 + i,
                recorded_by: 'Fictional Finance Reviewer', actor_source: i ? 'command' : 'earlier_event',
                recorded_at: '2026-11-16T12:00:00.000000Z', effective_at: i ? '2026-11-16T11:59:59.000000Z' : null,
                reason: i ? `Fictional action ${i} <script>literal only</script>` : null,
            })).reverse();
            result = { operation_id: id(4), entry: { operation_id: id(4), type: 'payment', minor: 1000,
                currency, reversed: true }, through_position: through, earlier_details_unknown: true,
                items: history.slice(after, after + 20), next_after: after + 20 < history.length ? after + 20 : null };
            if (options.receiptHistory) result.items = [
                { ...result.items[0], operation_id: id(4), action: 'receipt_corrected', source_operation_id: undefined,
                    previous_payment_details: { method: 'check', reference: 'FICT-OLD' },
                    payment_details: { method: 'scholarship', reference: '<script>FICT-NEW</script>' } },
                { ...history.at(-1), payment_details: { method: 'check', reference: 'FICT-OLD' } },
            ];
            if (options.delayHistory) await options.delayHistory();
        }
        await route.fulfill({ status, json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(options.readOnly ? '/admin/account-read-fixture/' : '/admin/');
    await page.locator('.workspace-nav-link[data-workspace="accounts"]').click();
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    return { calls, errors, change: () => { position++; balance += 100; }, interrupt: () => interrupt = true,
        advanceHistory: () => historyHead++ };
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
    await expect(page.getByRole('button', { name: /entry history$/ })).toHaveCount(0);
});

test('private entry history pages corrections with captured context and explicit refresh', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: /View .* entry history/ }).first().click();
    const activity = page.getByRole('region', { name: 'Entry activity', exact: true });
    await expect(activity.locator('article')).toHaveCount(20);
    await expect(activity).toContainText('Fictional action 24 <script>literal only</script>');
    await expect(activity).toContainText('Fictional Finance Reviewer');
    await page.setViewportSize({ width: 375, height: 850 });
    await accessible(page);
    f.advanceHistory();
    await page.getByRole('button', { name: 'More entry activity', exact: true }).click();
    await expect(activity.locator('article')).toHaveCount(5);
    await expect(activity).toContainText('Earlier event attribution');
    await expect(activity).toContainText('Action time was not recorded.');
    await expect(activity).toContainText('No reason was recorded.');
    await expect(page.getByRole('button', { name: 'More entry activity', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Previous entry activity', exact: true }).click();
    await expect(activity).toContainText('Fictional action 24');
    await expect(activity).not.toContainText('Fictional action 25');
    await page.getByRole('button', { name: 'Refresh entry history', exact: true }).click();
    await expect(activity).toContainText('Fictional action 25');
    const calls = f.calls.filter(c => c.path.endsWith('/history/'));
    expect(calls.map(c => c.data)).toEqual([{ after: 0 }, { after: 20, through_position: 26 },
        { after: 0, through_position: 26 }, { after: 0 }]);
    expect(calls.every(c => c.csrf === 'fictional-test-token')).toBe(true);
    expect(f.calls.some(c => c.path.endsWith('/commit/') || c.path.endsWith('/reverse/'))).toBe(false);
    expect(await page.evaluate(() => ({ local: localStorage.length, session: sessionStorage.length })))
        .toEqual({ local: 0, session: 0 });
    await page.getByRole('button', { name: 'Back to account statement', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Account statement');
    expect(f.errors).toEqual([]);
});

test('a late private history response cannot reopen the view after navigation', async ({ page }) => {
    let release;
    const waiting = new Promise(resolve => release = resolve);
    const f = await fixture(page, { delayHistory: () => waiting });
    await page.getByRole('button', { name: /View .* entry history/ }).first().click();
    await expect(page.getByText('Loading entry history…', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Back to account statement', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Account statement');
    const delivered = page.waitForResponse(response => response.url().endsWith('/history/'));
    release();
    await (await delivered).finished();
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(resolve)));
    await expect.poll(() => f.calls.filter(c => c.path.endsWith('/history/')).length).toBe(1);
    await expect(page.getByRole('region', { name: 'Entry activity', exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
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

test('payment receipt fields are optional, private, escaped and excluded from charges', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Post a transaction', exact: true }).click();
    await page.getByLabel('Transaction type').selectOption('payment');
    await page.getByLabel('Amount (USD)').fill('10');
    await page.getByLabel('Payment method', { exact: true }).selectOption('scholarship');
    await page.getByLabel('Receipt or check reference').fill('<script>FICT-ONLY</script>');
    await page.getByLabel('Reason for transaction').fill('Fictional scholarship receipt.');
    await page.getByRole('button', { name: 'Review transaction', exact: true }).click();
    const preview = page.getByRole('region', { name: 'Reviewed transaction' });
    await expect(preview).toContainText('Payment method: Scholarship');
    await expect(preview).toContainText('<script>FICT-ONLY</script>');
    await expect(preview.locator('script')).toHaveCount(0);
    expect(f.calls.find(c => c.path.endsWith('/entries/review/')).data).toMatchObject({
        payment_method: 'scholarship', payment_reference: '<script>FICT-ONLY</script>',
    });
    await page.getByLabel('Transaction type').selectOption('charge');
    await expect(page.getByLabel('Receipt or check reference')).toBeHidden();
    await expect(page.getByRole('button', { name: 'Confirm transaction', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Review transaction', exact: true }).click();
    await expect(preview).toBeVisible();
    const charge = f.calls.filter(c => c.path.endsWith('/entries/review/')).at(-1).data;
    expect(charge).not.toHaveProperty('payment_method'); expect(charge).not.toHaveProperty('payment_reference');
    await expect(preview).not.toContainText('FICT-ONLY');
    await page.getByLabel('Transaction type').selectOption('payment');
    await expect(page.getByLabel('Receipt or check reference')).toHaveValue('<script>FICT-ONLY</script>');
    await page.setViewportSize({ width: 375, height: 850 });
    await accessible(page);
    expect(await page.evaluate(() => ({ local: localStorage.length, session: sessionStorage.length })))
        .toEqual({ local: 0, session: 0 });
    expect(f.errors).toEqual([]);
});

test('receipt corrections reuse the editor, preserve fields and retry only the reviewed proof', async ({ page }) => {
    const f = await fixture(page, { receipt: { method: 'check', reference: 'FICT-OLD' } });
    await page.getByRole('button', { name: 'Correct receipt details', exact: true }).click();
    await expect(page.getByLabel('Payment method', { exact: true })).toHaveValue('check');
    await expect(page.getByLabel('Receipt or check reference')).toHaveValue('FICT-OLD');
    await expect(page.getByLabel('Amount (USD)')).toHaveCount(0);
    await page.getByLabel('Payment method', { exact: true }).selectOption('grant');
    await page.getByLabel('Receipt or check reference').fill('FICT-NEW');
    await page.getByLabel('Reason for receipt correction').fill('Fictional grant details corrected.');
    await page.getByRole('button', { name: 'Review receipt correction', exact: true }).click();
    const preview = page.getByRole('region', { name: 'Reviewed receipt correction' });
    await expect(preview).toContainText('FICT-OLD'); await expect(preview).toContainText('FICT-NEW');
    await expect(preview).toContainText('Amount unchanged');
    await expect(preview).toContainText('-$10.00 → -$10.00');
    await page.setViewportSize({ width: 375, height: 850 });
    await accessible(page);
    await page.getByRole('button', { name: 'Refresh account balance', exact: true }).click();
    await expect(page.getByLabel('Receipt or check reference')).toHaveValue('FICT-NEW');
    await expect(page.getByRole('button', { name: 'Confirm receipt correction', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Review receipt correction', exact: true }).click();
    f.interrupt();
    await page.getByRole('button', { name: 'Confirm receipt correction', exact: true }).click();
    await expect(page.getByLabel('Receipt or check reference')).toBeEnabled();
    await page.getByRole('button', { name: 'Confirm receipt correction', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Balance: -$10.00');
    const commits = f.calls.filter(c => c.path.endsWith('/receipt/commit/'));
    expect(commits.map(c => c.data)).toEqual([
        { review_token: 'fictional-receipt-2' }, { review_token: 'fictional-receipt-2' },
    ]);
    expect(commits.every(c => c.csrf === 'fictional-test-token')).toBe(true);
    await expect(page.locator('#drawer-body')).not.toContainText('FICT-NEW');
    expect(f.errors).toEqual([]);
});

test('entry history shows receipt changes alongside the original retained reference', async ({ page }) => {
    const f = await fixture(page, { receiptHistory: true });
    await page.getByRole('button', { name: /View .* entry history/ }).first().click();
    const articles = page.getByRole('region', { name: 'Entry activity' }).locator('article');
    await expect(articles).toHaveCount(2);
    await expect(articles.first()).toContainText('Receipt details corrected');
    await expect(articles.first()).toContainText('<script>FICT-NEW</script>');
    await expect(articles.last()).toContainText('FICT-OLD');
    await expect(articles.last()).not.toContainText('FICT-NEW');
    await expect(articles.locator('script')).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('a delayed private receipt read cannot render into a different drawer', async ({ page }) => {
    let release;
    const pending = new Promise(resolve => release = resolve);
    const f = await fixture(page, { receipt: { method: 'check', reference: 'FICT-PRIVATE-DELAY' },
        delayReceipt: () => pending });
    await page.getByRole('button', { name: 'Correct receipt details', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Opening transaction');
    await page.getByRole('button', { name: 'Back to account statement', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Account statement');
    const delivered = page.waitForResponse(response => response.url().endsWith('/receipt/'));
    release();
    await (await delivered).finished();
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(resolve)));
    await expect(page.locator('#drawer-body')).toContainText('Account statement');
    await expect(page.locator('#drawer-body')).not.toContainText('FICT-PRIVATE-DELAY');
    await expect(page.getByLabel('Receipt or check reference')).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('staff review a reversal with exact balances and retained private receipt details', async ({ page }) => {
    const f = await fixture(page, { receipt: { method: 'scholarship', reference: 'FICT-PRIVATE-RECEIPT' } });
    await page.getByRole('button', { name: 'Reverse entry', exact: true }).click();
    await expect(page.getByLabel('Amount (USD)')).toHaveCount(0);
    await expect(page.getByLabel('Receipt or check reference')).toHaveCount(0);
    await page.getByLabel('Reason for reversal').fill('Fictional reversal <script>literal only</script>.');
    await page.getByRole('button', { name: 'Review entry reversal', exact: true }).click();
    const preview = page.getByRole('region', { name: 'Reviewed entry reversal' });
    await expect(preview).toContainText('Reverse payment: $10.00');
    await expect(preview).not.toContainText('null');
    await expect(preview).toContainText('-$10.00 → $0.00');
    await expect(preview).toContainText('Scholarship');
    await expect(preview).toContainText('FICT-PRIVATE-RECEIPT');
    await expect(preview).toContainText('<script>literal only</script>');
    await expect(preview.locator('script')).toHaveCount(0);
    expect(f.calls.some(c => c.path.endsWith('/commit/'))).toBe(false);
    await page.setViewportSize({ width: 375, height: 850 });
    await accessible(page);
    await page.getByRole('button', { name: 'Confirm entry reversal', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Balance: $0.00');
    await expect(page.locator('#drawer-body')).toContainText('Reversed');
    expect(f.calls.find(c => c.path.endsWith('/reverse/commit/'))).toMatchObject({
        data: { review_token: 'fictional-reversal-1' }, csrf: 'fictional-test-token',
    });
    expect(f.calls.some(c => c.path.endsWith('/reverse/'))).toBe(false);
    expect(await page.evaluate(() => ({ local: localStorage.length, session: sessionStorage.length })))
        .toEqual({ local: 0, session: 0 });
    expect(f.errors).toEqual([]);
});

test('stale reversals preserve the reason and accepted retries cannot reverse a later posting', async ({ page }) => {
    const f = await fixture(page, { staleReversal: true, eligible: false });
    await page.getByRole('button', { name: 'Reverse entry', exact: true }).click();
    const reason = page.getByLabel('Reason for reversal');
    await reason.fill('Fictional original reversal reason.');
    await page.getByRole('button', { name: 'Review entry reversal', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm entry reversal', exact: true }).click();
    await expect(reason).toHaveValue('Fictional original reversal reason.');
    f.change();
    await page.getByRole('button', { name: 'Refresh account balance', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Confirm entry reversal', exact: true })).toHaveCount(0);
    await expect(reason).toHaveValue('Fictional original reversal reason.');
    await page.getByRole('button', { name: 'Review entry reversal', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Reviewed entry reversal' })).toContainText('-$9.00 → $1.00');
    f.interrupt();
    await page.getByRole('button', { name: 'Confirm entry reversal', exact: true }).click();
    await expect(reason).toBeEnabled();
    f.change();
    await page.getByRole('button', { name: 'Confirm entry reversal', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Balance: $2.00');
    await expect(page.locator('#drawer-body')).toContainText('Reversed');
    expect(f.calls.filter(c => c.path.endsWith('/reverse/commit/')).map(c => c.data))
        .toEqual([{ review_token: 'fictional-reversal-1' }, { review_token: 'fictional-reversal-2' },
            { review_token: 'fictional-reversal-2' }]);
    expect(f.errors).toEqual([]);
});

test('editing a reversal invalidates confirmation and ignores a late review response', async ({ page }) => {
    let release;
    const waiting = new Promise(resolve => release = resolve);
    const f = await fixture(page, { delayReview: () => waiting });
    await page.getByRole('button', { name: 'Reverse entry', exact: true }).click();
    const reason = page.getByLabel('Reason for reversal');
    await reason.fill('Fictional original reason.');
    await page.getByRole('button', { name: 'Review entry reversal', exact: true }).click();
    await expect.poll(() => f.calls.filter(c => c.path.endsWith('/reverse/review/')).length).toBe(1);
    await reason.fill('Fictional newer reason.');
    const delivered = page.waitForResponse(response => response.url().endsWith('/reverse/review/'));
    release(); await (await delivered).finished();
    await expect(page.getByRole('button', { name: 'Confirm entry reversal', exact: true })).toHaveCount(0);
    await expect(reason).toHaveValue('Fictional newer reason.');
    await page.getByRole('button', { name: 'Review entry reversal', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Confirm entry reversal', exact: true })).toBeVisible();
    await reason.fill('Fictional final reason.');
    await expect(page.getByRole('button', { name: 'Confirm entry reversal', exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});
