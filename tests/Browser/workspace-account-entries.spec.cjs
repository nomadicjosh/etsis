const { recordAction } = require('./support/workspace-actions.cjs');
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => `01K000000000000000000000${String(n).padStart(2, '0')}`;

async function fixture(page, options = {}) {
    const calls = [], errors = [];
    let position = 2, balance = options.charge ? 1000 : -1000 + (options.unlinkedRefundMinor || 0), corrected = false, reviewNumber = 0, interrupt = false, posted = null, sourceReversed = false, acceptedReversal = null;
    const refundCorrectionReviews = new Map();
    const postingReviews = new Map(), receiptReviews = new Map(), reversalReviews = new Map(), creditReviews = new Map();
    const credits = [], linkedRefunds = [], refundReviews = new Map();
    const linkedRefundMinor = () => linkedRefunds.filter(r => !r.reversed).reduce((total, r) =>
        total + (r.refund_allocations || []).filter(p => p.payment_operation_id === id(4)).reduce((sum, p) => sum + p.amount_minor, 0), 0);
    const creditedMinor = () => credits.filter(c => !c.reversed).reduce((sum, c) => sum + c.minor, 0);
    balance -= (options.extraPayments || 0) * 1000;
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
            entries: [{ operation_id: id(4), type: options.charge ? 'charge' : 'payment', minor: 1000, currency, reversed: corrected || sourceReversed,
                ...(options.registrationPrice ? { registration_price: options.registrationPrice } : {}),
                can_correct_amount: options.eligible !== false && !corrected && !sourceReversed && creditedMinor() === 0,
                can_correct_payment_details: options.eligible !== false && !options.charge,
                ...(!options.charge ? { can_refund_payment: !corrected && !sourceReversed && balance < 0 && linkedRefundMinor() < 1000,
                    linked_refund_minor: linkedRefundMinor(), linked_refund_count: linkedRefunds.length,
                    remaining_refundable_minor: sourceReversed ? 0 : 1000 - linkedRefundMinor() } : {}),
                ...(options.charge ? { can_credit_charge: !sourceReversed && creditedMinor() < 1000,
                    credited_minor: creditedMinor(), remaining_charge_minor: 1000 - creditedMinor(),
                    linked_credit_count: credits.filter(c => !c.reversed).length } : {}),
                ...(corrected ? { corrected_by_operation_id: id(5) } : {}) },
                ...(corrected ? [{ operation_id: id(5), type: 'payment', minor: 1250, currency, reversed: false,
                    can_correct_amount: true, can_correct_payment_details: true, corrects_operation_id: id(4) }] : []),
                ...(posted ? [{ operation_id: id(6), type: posted.type, minor: posted.amount_minor, currency,
                    reversed: false, can_correct_amount: true, can_correct_payment_details: posted.type !== "charge", refund_allocations: posted.refund_allocations }] : []),
                ...Array.from({ length: options.extraPayments || 0 }, (_, n) => ({ operation_id: id(30 + n), type: 'payment',
                    minor: 1000, currency, reversed: false, remaining_refundable_minor: 1000,
                    linked_refund_minor: 0, linked_refund_count: 0 })),
                ...credits, ...linkedRefunds.map(({ token, result, ...entry }) => entry)],
        };
        else if (path.endsWith('/correction/review/')) {
            reviewNumber++;
            result = { review_token: `fictional-correction-${reviewNumber}`, type: 'payment', original_minor: 1000,
                corrected_minor: Number(data.amount_minor), currency, balance_minor: balance,
                resulting_balance_minor: balance - (Number(data.amount_minor) - 1000), reason: data.reason };
            const refund = linkedRefunds.find(r => path.includes(r.operation_id));
            if (refund) {
                result = { ...result, type: 'refund', original_minor: refund.minor,
                    resulting_balance_minor: balance + data.amount_minor - refund.minor,
                    refund_allocations: data.refund_allocations, position, source: refund.operation_id };
                refundCorrectionReviews.set(result.review_token, result);
            }
            if (options.delayReview) await options.delayReview();
        } else if (path.endsWith('/correction/commit/')) {
            if (refundCorrectionReviews.has(data.review_token)) {
                const r = refundCorrectionReviews.get(data.review_token);
                linkedRefunds.find(e => e.operation_id === r.source).reversed = true;
                linkedRefunds.push({ operation_id: id(90), type: 'refund', minor: r.corrected_minor,
                    currency, reversed: false, corrects_operation_id: r.source, refund_allocations: r.refund_allocations });
                position++; balance = r.resulting_balance_minor;
                result = { status: 'corrected', operation_id: id(90), source_operation_id: r.source, balance_minor: balance, position };
            } else if (data.review_token === 'fictional-correction-1' && options.stale) {
                status = 409; result = { error: 'This financial record changed. Review the account again.' };
            } else if (interrupt) {
                interrupt = false; status = 500; result = { error: 'Fictional interrupted response.' };
            } else {
                corrected = true; position++; balance = -1250;
                result = { status: 'corrected', operation_id: id(5), source_operation_id: id(4), balance_minor: balance, position };
            }
        } else if (path === `/sis/accounts/${id(1)}/entries/review/`) {
            reviewNumber++;
            if (data.type === 'refund' && data.amount_minor > -balance) {
                status = 422; result = { error: 'Please correct the highlighted fields.',
                    errors: [{ field: 'amount_minor', message: 'A refund cannot exceed available credit.' }] };
            } else {
                const next = balance + (data.type === 'payment' ? -data.amount_minor : data.amount_minor);
                result = { review_token: `fictional-posting-${reviewNumber}`, type: data.type,
                    amount_minor: data.amount_minor, currency, balance_minor: balance, resulting_balance_minor: next,
                    available_credit_minor: Math.max(0, -balance), remaining_credit_minor: Math.max(0, -next),
                    reason: data.reason, position, refund_allocations: data.refund_allocations, payment_details: data.payment_method || data.payment_reference
                        ? { method: data.payment_method || null, reference: data.payment_reference || null } : null };
                postingReviews.set(result.review_token, result);
                if (options.delayReview) await options.delayReview();
            }
        } else if (path === `/sis/accounts/${id(1)}/entries/commit/`) {
            const reviewed = postingReviews.get(data.review_token);
            if (reviewed.position !== position && posted?.review_token !== data.review_token) {
                status = 409; result = { error: 'This financial record changed. Review the account again.' };
            } else {
                if (!posted) { posted = reviewed; position++; balance = reviewed.resulting_balance_minor; }
                result = { status: 'posted', operation_id: id(6), balance_minor: balance, position };
                if (interrupt) {
                    interrupt = false; status = 500; result = { error: 'Fictional interrupted response.' };
                }
            }
        } else if (path.endsWith('/refund/review/')) {
            reviewNumber++;
            const remaining = 1000 - linkedRefundMinor();
            const plan = data.refund_allocations || [{ payment_operation_id: id(4), amount_minor: data.amount_minor }];
            const sourceMinor = plan.filter(p => p.payment_operation_id === id(4)).reduce((sum, p) => sum + p.amount_minor, 0);
            if (sourceMinor > remaining || data.amount_minor > -balance) {
                status = 422; result = { error: 'Please correct the highlighted fields.', errors: [
                    { field: 'amount_minor', message: 'The refund must fit the remaining payment and available account credit.' }] };
            } else {
                result = { review_token: `fictional-payment-refund-${reviewNumber}`, type: 'refund', amount_minor: data.amount_minor,
                    currency, payment_minor: 1000, linked_refund_minor: linkedRefundMinor(),
                    refund_allocations: plan, allocation_receipts: plan.map(p => ({ ...p, payment_details: p.payment_operation_id === id(4) ? receiptDetails : null })),
                    unlinked_refund_minor: options.unlinkedRefundMinor || 0,
                    remaining_payment_minor: remaining - sourceMinor, balance_minor: balance,
                    resulting_balance_minor: balance + data.amount_minor,
                    available_credit_minor: -balance, remaining_credit_minor: -balance - data.amount_minor,
                    reason: data.reason, position,
                    payment_details: data.payment_method || data.payment_reference
                        ? { method: data.payment_method || null, reference: data.payment_reference || null } : null };
                refundReviews.set(result.review_token, result);
                if (options.delayReview) await options.delayReview();
            }
        } else if (path.endsWith('/refund/commit/')) {
            const reviewed = refundReviews.get(data.review_token), accepted = linkedRefunds.find(r => r.token === data.review_token);
            if (!accepted && reviewed.position !== position) {
                status = 409; result = { error: 'This financial record changed. Review the account again.' };
            } else {
                if (!accepted) {
                    balance = reviewed.resulting_balance_minor; position++;
                    const operation = id(70 + linkedRefunds.length);
                    linkedRefunds.push({ operation_id: operation, refund_allocations: reviewed.refund_allocations, can_correct_amount: true, type: 'refund',
                        minor: reviewed.amount_minor, currency, reversed: false, token: data.review_token,
                        result: { status: 'posted', operation_id: operation, balance_minor: balance, position } });
                }
                result = linkedRefunds.find(r => r.token === data.review_token).result;
                if (interrupt) {
                    interrupt = false; status = 500; result = { error: 'Fictional interrupted linked refund response.' };
                }
            }
        } else if (path.endsWith('/credit/review/')) {
            reviewNumber++;
            if (data.amount_minor > 1000 - creditedMinor()) {
                status = 422; result = { error: 'Please correct the highlighted fields.', errors: [
                    { field: 'amount_minor', message: 'The credit cannot exceed the remaining charge.' }] };
            } else {
                result = { review_token: `fictional-credit-${reviewNumber}`, type: 'charge_credit', amount_minor: data.amount_minor,
                    charge_minor: 1000, credited_minor: creditedMinor(), remaining_charge_minor: 1000 - creditedMinor() - data.amount_minor,
                    currency, balance_minor: balance, resulting_balance_minor: balance - data.amount_minor,
                    reason: data.reason, position };
                creditReviews.set(result.review_token, result);
                if (options.delayReview) await options.delayReview();
            }
        } else if (path.endsWith('/credit/commit/')) {
            const reviewed = creditReviews.get(data.review_token), accepted = credits.find(c => c.token === data.review_token);
            if (!accepted && reviewed.position !== position) {
                status = 409; result = { error: 'This financial record changed. Review the account again.' };
            } else {
                if (!accepted) {
                    balance = reviewed.resulting_balance_minor; position++;
                    const op = id(7 + credits.length);
                    credits.push({ operation_id: op, type: 'charge_credit', minor: reviewed.amount_minor, currency,
                        reversed: false, source_operation_id: id(4), token: data.review_token,
                        result: { status: 'credited', operation_id: op, source_operation_id: id(4), balance_minor: balance, position } });
                }
                result = credits.find(c => c.token === data.review_token).result;
                if (interrupt) {
                    interrupt = false; status = 500; result = { error: 'Fictional interrupted credit response.' };
                }
            }
        } else if (path.endsWith('/reverse/review/')) {
            reviewNumber++;
            result = { review_token: `fictional-reversal-${reviewNumber}`, type: options.charge ? 'charge' : 'payment', amount_minor: 1000,
                reversed_credit_count: options.charge ? credits.filter(c => !c.reversed).length : 0,
                reversed_credit_minor: options.charge ? creditedMinor() : 0,
                linked_refund_minor: linkedRefundMinor(), linked_refund_count: linkedRefunds.length,
                currency, balance_minor: balance, resulting_balance_minor: balance + (options.charge ? -(1000 - creditedMinor()) : 1000),
                reason: data.reason, position, payment_details: receiptDetails };
            reversalReviews.set(result.review_token, result);
            if (options.delayReview) await options.delayReview();
        } else if (path.endsWith('/reverse/commit/')) {
            const reviewed = reversalReviews.get(data.review_token);
            if ((options.staleReversal && data.review_token === 'fictional-reversal-1')
                || (reviewed.position !== position && acceptedReversal?.token !== data.review_token)) {
                status = 409; result = { error: 'This financial record changed. Review the account again.' };
            } else {
                if (!acceptedReversal) {
                    sourceReversed = true; balance = reviewed.resulting_balance_minor; position++;
                    credits.filter(c => !c.reversed).forEach(c => { c.reversed = true; c.reversed_with_operation_id = id(4); });
                    acceptedReversal = { token: data.review_token, result: { status: 'reversed', operation_id: id(4), balance_minor: balance, position } };
                }
                result = acceptedReversal.result;
                if (interrupt) {
                    interrupt = false; status = 500; result = { error: 'Fictional interrupted reversal response.' };
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
                interrupt = false; status = 500; result = { error: 'Fictional interrupted receipt response.' };
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
            if (linkedRefunds.length) {
                const original = { ...history.at(-1), operation_id: id(4), type: 'payment', action: 'posted' };
                result.items = [original, ...linkedRefunds.map(r => ({ ...original, operation_id: r.operation_id,
                    type: 'refund', amount_minor: r.minor, refund_allocations: [{ payment_operation_id: id(4), amount_minor: r.minor }], action: 'posted' }))].reverse();
                result.next_after = null;
            }
            if (options.charge) {
                const posted = { ...history.at(-1), operation_id: id(4), action: 'posted', type: 'charge', amount_minor: 1000 };
                result.entry = { operation_id: id(4), type: 'charge', minor: 1000, currency, reversed: sourceReversed };
                result.items = [posted, ...credits.map(c => ({ ...posted, operation_id: c.operation_id,
                    action: 'credited', type: 'charge_credit', amount_minor: c.minor, source_operation_id: id(4) })),
                    ...(sourceReversed ? [{ ...posted, action: 'reversed', reversed_credit_ids: credits.map(c => c.operation_id) }] : [])].reverse();
                result.next_after = null;
            }
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
    await recordAction(page, 'Correct amount');
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
    await recordAction(page, 'Correct amount');
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
    await expect(page.getByRole('button', { name: 'Correct amount', exact: true, includeHidden: true })).toHaveCount(0);
    await fixture(page);
    await recordAction(page, 'Correct amount');
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
    await expect(page.getByRole('button', { name: 'Correct amount', exact: true, includeHidden: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Reverse entry', exact: true, includeHidden: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Post a transaction', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /entry history$/, includeHidden: true })).toHaveCount(0);
});

test('private entry history pages corrections with captured context and explicit refresh', async ({ page }) => {
    const f = await fixture(page);
    await recordAction(page, /View .* entry history/);
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
    await recordAction(page, /View .* entry history/);
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
    await recordAction(page, 'Correct amount');
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
        await recordAction(page, 'Correct amount');
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
    await recordAction(page, 'Correct receipt details');
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
    await recordAction(page, /View .* entry history/);
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
    await recordAction(page, 'Correct receipt details');
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
    await recordAction(page, 'Reverse entry');
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
    await recordAction(page, 'Reverse entry');
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
    await recordAction(page, 'Reverse entry');
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

test('partial and full charge credits retain the original bill and show understandable remaining amounts', async ({ page }) => {
    const f = await fixture(page, { charge: true });
    await page.getByRole('button', { name: 'Credit charge', exact: true }).click();
    await page.getByLabel('Amount to credit (USD)').fill('2');
    await page.getByLabel('Reason for charge credit').fill('Fictional waiver <script>literal only</script>.');
    await page.getByRole('button', { name: 'Review charge credit', exact: true }).click();
    const preview = page.getByRole('region', { name: 'Reviewed charge credit' });
    await expect(preview).toContainText('Charge credit: -$2.00');
    await expect(preview).toContainText('Original charge: $10.00');
    await expect(preview).toContainText('Charge remaining: $8.00');
    await expect(preview).toContainText('$10.00 → $8.00');
    await expect(preview.locator('script')).toHaveCount(0);
    await page.setViewportSize({ width: 375, height: 850 }); await accessible(page);
    await page.getByRole('button', { name: 'Confirm charge credit', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Credit for');
    await expect(page.locator('#drawer-body')).toContainText('Credited $2.00 · Remaining $8.00');
    await expect(page.getByRole('button', { name: 'Correct amount', exact: true, includeHidden: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Credit charge', exact: true }).click();
    await page.getByLabel('Amount to credit (USD)').fill('8');
    await page.getByLabel('Reason for charge credit').fill('Fictional remaining waiver.');
    await page.getByRole('button', { name: 'Review charge credit', exact: true }).click();
    await expect(preview).toContainText('Previously credited: $2.00');
    await expect(preview).toContainText('Charge remaining: $0.00');
    await page.getByRole('button', { name: 'Confirm charge credit', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Balance: $0.00');
    await expect(page.getByRole('button', { name: 'Credit charge', exact: true })).toHaveCount(0);
    expect(f.calls.filter(c => c.path.endsWith('/credit/commit/')).map(c => Object.keys(c.data)))
        .toEqual([['review_token'], ['review_token']]);
    expect(f.errors).toEqual([]);
});

test('reversing a credited charge previews and retains the linked reversals', async ({ page }) => {
    const f = await fixture(page, { charge: true });
    await page.getByRole('button', { name: 'Credit charge', exact: true }).click();
    await page.getByLabel('Amount to credit (USD)').fill('2');
    await page.getByLabel('Reason for charge credit').fill('Fictional linked credit.');
    await page.getByRole('button', { name: 'Review charge credit', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm charge credit', exact: true }).click();
    await recordAction(page, 'Reverse entry');
    await page.getByLabel('Reason for reversal').fill('Fictional charge reversal.');
    await page.getByRole('button', { name: 'Review entry reversal', exact: true }).click();
    const preview = page.getByRole('region', { name: 'Reviewed entry reversal' });
    await expect(preview).toContainText('Also reverses 1 linked charge credits totaling $2.00.');
    await expect(preview).toContainText('$8.00 → $0.00');
    await page.getByRole('button', { name: 'Confirm entry reversal', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Reversed with charge');
    await recordAction(page, /View .* entry history/);
    const history = page.getByRole('region', { name: 'Entry activity' });
    await expect(history).toContainText('Charge credited');
    await expect(history).toContainText('charge credit: -$2.00');
    await expect(history).toContainText('Also reversed 1 linked charge credits.');
    expect(f.errors).toEqual([]);
});

test('excess and stale charge credits preserve inputs while accepted confirmation retries use one proof', async ({ page }) => {
    const f = await fixture(page, { charge: true });
    await page.getByRole('button', { name: 'Credit charge', exact: true }).click();
    const amount = page.getByLabel('Amount to credit (USD)'), reason = page.getByLabel('Reason for charge credit');
    await amount.fill('11'); await reason.fill('Fictional credit reason.');
    await page.getByRole('button', { name: 'Review charge credit', exact: true }).click();
    await expect(amount).toHaveValue('11'); await expect(amount).toHaveAttribute('aria-invalid', 'true');
    await amount.fill('2');
    await page.getByRole('button', { name: 'Review charge credit', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Confirm charge credit', exact: true })).toBeVisible();
    f.change();
    await page.getByRole('button', { name: 'Confirm charge credit', exact: true }).click();
    await expect(reason).toHaveValue('Fictional credit reason.');
    await page.getByRole('button', { name: 'Refresh account balance', exact: true }).click();
    await expect(amount).toHaveValue('2');
    await page.getByRole('button', { name: 'Review charge credit', exact: true }).click();
    f.interrupt();
    await page.getByRole('button', { name: 'Confirm charge credit', exact: true }).click();
    await expect(reason).toBeEnabled();
    await page.getByRole('button', { name: 'Confirm charge credit', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Balance: $9.00');
    const commits = f.calls.filter(c => c.path.endsWith('/credit/commit/'));
    expect(commits.at(-1).data).toEqual(commits.at(-2).data);
    expect(commits.every(c => c.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('a linked payment refund keeps incoming and outgoing receipts separate in one reviewed editor', async ({ page }) => {
    const f = await fixture(page, { receipt: { method: 'card', reference: 'FICT-INCOMING' } });
    await page.getByRole('button', { name: 'Refund payment', exact: true }).click();
    await page.getByLabel('Amount to refund (USD)').fill('2.50');
    await page.getByLabel('Refund method', { exact: true }).selectOption('check');
    await page.getByLabel('Refund receipt or check reference').fill('FICT-OUTGOING');
    await page.getByLabel('Reason for payment refund').fill('Fictional issued refund <script>literal only</script>.');
    await page.getByRole('button', { name: 'Review payment refund', exact: true }).click();
    const preview = page.getByRole('region', { name: 'Reviewed payment refund' });
    await expect(preview).toContainText('Refund: $2.50');
    await expect(preview).toContainText('Payment remaining: $7.50');
    await expect(preview).toContainText('FICT-INCOMING'); await expect(preview).toContainText('FICT-OUTGOING');
    await expect(preview).toContainText('Available credit: $10.00 → $7.50');
    await expect(preview.locator('script')).toHaveCount(0);
    await page.setViewportSize({ width: 375, height: 850 }); await accessible(page);
    await page.getByRole('button', { name: 'Confirm payment refund', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Balance: -$7.50');
    await expect(page.locator('#drawer-body')).toContainText('Refund of payment');
    await expect(page.locator('#drawer-body')).toContainText('Linked refunds $2.50 · Payment remaining $7.50');
    await expect(page.locator('#drawer-body')).not.toContainText('FICT-OUTGOING');
    expect(f.calls.find(c => c.path.endsWith('/refund/commit/'))).toMatchObject({
        data: { review_token: 'fictional-payment-refund-1' }, csrf: 'fictional-test-token',
    });
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('linked refunds explain earlier unallocated refunds and retain inputs after excess or stale requests', async ({ page }) => {
    const f = await fixture(page, { unlinkedRefundMinor: 300 });
    await page.getByRole('button', { name: 'Refund payment', exact: true }).click();
    const amount = page.getByLabel('Amount to refund (USD)'), reason = page.getByLabel('Reason for payment refund');
    await amount.fill('8'); await reason.fill('Fictional refund reason.');
    await page.getByRole('button', { name: 'Review payment refund', exact: true }).click();
    await expect(amount).toHaveAttribute('aria-invalid', 'true'); await expect(amount).toHaveValue('8');
    await amount.fill('2');
    await page.getByRole('button', { name: 'Review payment refund', exact: true }).click();
    const preview = page.getByRole('region', { name: 'Reviewed payment refund' });
    await expect(preview).toContainText('$3.00 in recorded refunds has no payment link');
    await expect(preview).toContainText('Payment remaining: $8.00');
    f.change();
    await page.getByRole('button', { name: 'Confirm payment refund', exact: true }).click();
    await expect(reason).toHaveValue('Fictional refund reason.');
    await page.getByRole('button', { name: 'Refresh account balance', exact: true }).click();
    await expect(amount).toHaveValue('2');
    await page.getByRole('button', { name: 'Review payment refund', exact: true }).click();
    f.interrupt();
    await page.getByRole('button', { name: 'Confirm payment refund', exact: true }).click();
    await expect(reason).toBeEnabled();
    await page.getByRole('button', { name: 'Confirm payment refund', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Balance: -$4.00');
    const commits = f.calls.filter(c => c.path.endsWith('/refund/commit/'));
    expect(commits.at(-1).data).toEqual(commits.at(-2).data);
    expect(f.errors).toEqual([]);
});

test('payment reversal keeps linked refunds visible and private history groups their recorded source', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Refund payment', exact: true }).click();
    await page.getByLabel('Amount to refund (USD)').fill('2');
    await page.getByLabel('Reason for payment refund').fill('Fictional source-linked refund.');
    await page.getByRole('button', { name: 'Review payment refund', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm payment refund', exact: true }).click();
    await recordAction(page, /View .* entry history/);
    await expect(page.getByRole('region', { name: 'Entry activity' })).toContainText('Refund of payment');
    await page.getByRole('button', { name: 'Back to account statement', exact: true }).click();
    await recordAction(page, 'Reverse entry');
    await page.getByLabel('Reason for reversal').fill('Fictional payment amount was not received.');
    await page.getByRole('button', { name: 'Review entry reversal', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Reviewed entry reversal' }))
        .toContainText('1 linked refunds totaling $2.00 remain recorded');
    expect(f.errors).toEqual([]);
});

test('one refund spans two payments with exact allocations and proof-only confirmation', async ({ page }) => {
    const f = await fixture(page, { extraPayments: 1 });
    await page.getByRole('button', { name: 'Refund payment', exact: true }).click();
    await page.getByLabel('Amount to refund (USD)').fill('14');
    await page.getByRole('button', { name: /Add payment 00000030/ }).click();
    await page.getByLabel('Allocation for payment 00000004 (USD)').fill('7');
    await page.getByLabel('Allocation for payment 00000030 (USD)').fill('7');
    await page.getByLabel('Reason for payment refund').fill('Fictional combined issued refund.');
    await page.getByRole('button', { name: 'Review payment refund', exact: true }).click();
    const preview = page.getByRole('region', { name: 'Reviewed payment refund' });
    await expect(preview.getByLabel('Reviewed refund allocations')).toContainText('Payment 00000030: $7.00');
    const request = f.calls.find(c => c.path.endsWith('/refund/review/'));
    expect(request.data.refund_allocations).toEqual([
        { payment_operation_id: id(4), amount_minor: 700 }, { payment_operation_id: id(30), amount_minor: 700 },
    ]);
    await page.setViewportSize({ width: 375, height: 850 }); await accessible(page);
    f.interrupt();
    await page.getByRole('button', { name: 'Confirm payment refund', exact: true }).click();
    await expect(page.getByLabel('Reason for payment refund')).toBeEnabled();
    await page.getByRole('button', { name: 'Confirm payment refund', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Balance: -$6.00');
    const commits = f.calls.filter(c => c.path.endsWith('/refund/commit/'));
    expect(commits.at(-1).data).toEqual(commits.at(-2).data);
    expect(Object.keys(commits.at(-1).data)).toEqual(['review_token']);
    await expect(page.locator('#drawer-body')).toContainText('Refund of payment 00000030 · $7.00');
    expect(f.errors).toEqual([]);
});

test('refund allocation correction redistributes one outflow with no balance change', async ({ page }) => {
    const f = await fixture(page, { extraPayments: 1 });
    await page.getByRole('button', { name: 'Refund payment', exact: true }).click();
    await page.getByLabel('Amount to refund (USD)').fill('2');
    await page.getByLabel('Reason for payment refund').fill('Fictional refund.');
    await page.getByRole('button', { name: 'Review payment refund', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm payment refund', exact: true }).click();
    await recordAction(page, 'Correct refund');
    await expect(page.getByRole('heading', { name: 'Correct a refund', exact: true })).toBeVisible();
    await page.getByLabel('Corrected amount (USD)').fill('2');
    await page.getByRole('button', { name: /Add payment 00000030/ }).click();
    await page.getByLabel('Allocation for payment 00000004 (USD)').fill('1');
    await page.getByLabel('Allocation for payment 00000030 (USD)').fill('1');
    await page.getByLabel('Reason for refund correction').fill('Fictional source correction.');
    await page.getByRole('button', { name: 'Review refund correction', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Reviewed refund correction' })).toContainText('Account balance: -$18.00 → -$18.00');
    await page.getByRole('button', { name: 'Confirm refund correction', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Refund of payment 00000030 · $1.00');
    await expect(page.locator('#drawer-body')).toContainText('Balance: -$18.00');
    expect(f.errors).toEqual([]);
});

test('payment choices are paged and searchable while edits invalidate reviewed allocations', async ({ page }) => {
    const f = await fixture(page, { extraPayments: 24 });
    await page.getByRole('button', { name: 'Refund payment', exact: true }).click();
    await expect(page.getByRole('button', { name: /Add payment / })).toHaveCount(20);
    await page.getByRole('button', { name: 'More payments', exact: true }).click();
    await expect(page.getByRole('button', { name: /Add payment / })).toHaveCount(4);
    await page.getByLabel('Find payments', { exact: true }).fill('00000053');
    await page.getByRole('button', { name: /Add payment 00000053/ }).click();
    await page.getByLabel('Amount to refund (USD)').fill('2');
    await page.getByLabel('Allocation for payment 00000004 (USD)').fill('1');
    await page.getByLabel('Allocation for payment 00000053 (USD)').fill('1');
    await page.getByLabel('Reason for payment refund').fill('Fictional paged split.');
    await page.getByRole('button', { name: 'Review payment refund', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Confirm payment refund', exact: true })).toBeVisible();
    await page.getByLabel('Allocation for payment 00000053 (USD)').fill('0.50');
    await expect(page.getByRole('button', { name: 'Confirm payment refund', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Review payment refund', exact: true }).click();
    expect(f.calls.filter(c => c.path.endsWith('/refund/review/'))).toHaveLength(1);
    await page.getByRole('button', { name: 'Refresh account balance', exact: true }).click();
    await expect(page.getByLabel('Allocation for payment 00000053 (USD)')).toHaveValue('0.50');
    expect(f.errors).toEqual([]);
});

test('general refunds use the same allocation editor and exact three-decimal currency handling', async ({ page }) => {
    const f = await fixture(page, { currency: 'KWD', extraPayments: 1 });
    await page.getByRole('button', { name: 'Post a transaction', exact: true }).click();
    await page.getByLabel('Transaction type').selectOption('refund');
    await page.getByLabel('Allocate refund to payments').check();
    await page.getByRole('button', { name: /Add payment 00000004/ }).click();
    await page.getByRole('button', { name: /Add payment 00000030/ }).click();
    await page.getByRole('spinbutton', { name: 'Amount (KWD)', exact: true }).fill('1.234');
    await page.getByLabel('Allocation for payment 00000004 (KWD)').fill('0.617');
    await page.getByLabel('Allocation for payment 00000030 (KWD)').fill('0.617');
    await page.getByLabel('Reason for transaction').fill('Fictional precisely allocated refund.');
    await page.getByRole('button', { name: 'Review transaction', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Reviewed transaction' })).toBeVisible();
    expect(f.calls.find(c => c.path.endsWith('/entries/review/')).data).toMatchObject({ amount_minor: 1234,
        refund_allocations: [{ payment_operation_id: id(4), amount_minor: 617 }, { payment_operation_id: id(30), amount_minor: 617 }] });
    await page.getByRole('button', { name: 'Confirm transaction', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Refund of payment 00000030');
    expect(f.errors).toEqual([]);
});


test('registration prices stay readable in statements without enabling amount replacement', async ({ page }) => {
    const f = await fixture(page, { charge: true, eligible: false, registrationPrice: {
        enrollment_id: id(30), rule_id: id(31), name: 'Fictional tuition', credits_milli: 4000,
        rate_minor: 250, currency: 'USD', rounding: 'half_up',
    } });
    await expect(page.locator('#drawer-body')).toContainText('Fictional tuition · 4 credits × $2.50 per credit');
    await expect(page.getByRole('button', { name: 'Correct amount', exact: true, includeHidden: true })).toHaveCount(0);
    await page.setViewportSize({ width: 375, height: 850 }); await accessible(page);
    expect(f.errors).toEqual([]);
});
