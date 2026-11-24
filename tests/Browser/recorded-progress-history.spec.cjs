'use strict';
const { test, expect } = require('@playwright/test');
const { fixture, accessible } = require('./support/student-degree-fixture.cjs');
const { recordedFixture } = require('./support/recorded-progress-fixture.cjs');
const id = n => String(n).padStart(26, '0');

async function setup(page, options = {}) {
    const source = await fixture(page, { assigned: true, readOnly: options.readOnly });
    const records = await recordedFixture(page, '**/degree-requirements/records/**');
    let denied = false, receipts = [];
    const calls = [];
    await page.route('**/sis/history-checks/progress/**', async route => {
        const req = route.request();
        const path = new URL(req.url()).pathname;
        const data = JSON.parse(new URLSearchParams(req.postData()).get('_workspace_json'));
        expect(req.headers()['x-csrf-token']).toBeTruthy();
        calls.push({ path, data });
        if (denied) return route.fulfill({ status: 403, json: { error: 'Current progress support duty is required.' } });
        if (path.endsWith('/run/')) {
            if (options.delay) await options.delay();
            const receipt = { reference: id(80), status: 'verified', events_checked: 1, checked_at: '2026-08-10T12:00:00Z' };
            receipts = [receipt];
            return route.fulfill({ json: { result: { status: 'verified', scope: 'recorded_degree_progress',
                progress_record_reference: id(60), events_checked: 1, position: 1, records_checked: 1, issues: [], records_changed: false },
                receipt, event_limit: 200 } });
        }
        return route.fulfill({ json: { items: receipts, next_after: null } });
    });
    await page.getByRole('button', { name: 'Recorded progress reviews', exact: true }).click();
    await page.getByRole('button', { name: /^Open review/ }).click();
    return { ...source, records, checks: calls, deny: () => { denied = true; } };
}

test('a saved progress check returns to the exact selected review with a fresh private read', async ({ page }) => {
    const f = await setup(page);
    await page.getByRole('button', { name: 'Check recorded progress history', exact: true }).click();
    await expect(page.locator('dialog.degree-audit-studio')).toHaveCount(0);
    const body = page.locator('#drawer-body');
    await expect(body).toContainText('does not recalculate progress');
    expect(f.checks.filter(call => call.path.endsWith('/run/'))).toHaveLength(0);
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    await expect(body).toContainText('Recorded progress observations checked: 1');
    await expect(body).toContainText('restricted support receipt was recorded');
    await page.screenshot({ path: '/tmp/etsis-recorded-progress-history-desktop.png' });
    await page.setViewportSize({ width: 320, height: 1000 });
    const rootSize = await page.evaluate(() => parseFloat(getComputedStyle(document.documentElement).fontSize));
    await page.route('**/fixtures/progress-check-enlarged.css', route => route.fulfill({ contentType: 'text/css',
        body: `html { font-size: ${rootSize * 2}px !important; }` }));
    await page.evaluate(() => {
        const link = document.createElement('link');
        link.rel = 'stylesheet'; link.href = '/fixtures/progress-check-enlarged.css';
        document.head.append(link);
    });
    await expect.poll(() => page.evaluate(() => parseFloat(getComputedStyle(document.documentElement).fontSize)))
        .toBe(rootSize * 2);
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: '/tmp/etsis-recorded-progress-history.png', fullPage: true });
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    const summary = page.getByRole('region', { name: 'Recorded progress summary' });
    await expect(summary).toContainText('Computing <b>fictional</b>');
    await expect(summary.getByRole('heading', { name: 'Recorded progress summary', exact: true })).toBeFocused();
    expect(f.records.calls.filter(call => call.path.endsWith(`/${id(60)}/`))).toHaveLength(2);
    expect(f.calls.filter(call => call.path.endsWith('/progress/'))).toHaveLength(0);
    expect(f.errors).toEqual([]);
});

test('returning before a delayed check cannot replace the selected saved summary', async ({ page }) => {
    let release;
    const gate = new Promise(resolve => { release = resolve; });
    const f = await setup(page, { delay: () => gate });
    await page.getByRole('button', { name: 'Check recorded progress history', exact: true }).click();
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    await expect.poll(() => f.checks.filter(call => call.path.endsWith('/run/')).length).toBe(1);
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    release();
    await expect(page.getByRole('region', { name: 'Recorded progress summary' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Run history check', exact: true })).toBeHidden();
    expect(f.errors).toEqual([]);
});

test('lost support authority clears a previous saved-progress verification result', async ({ page }) => {
    const f = await setup(page);
    await page.getByRole('button', { name: 'Check recorded progress history', exact: true }).click();
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    const body = page.locator('#drawer-body');
    await expect(body).toContainText('Recorded progress observations checked: 1');
    f.deny();
    await page.getByRole('button', { name: 'Refresh previous checks', exact: true }).click();
    await expect(body).toContainText('Current progress support duty is required.');
    await expect(body).not.toContainText('Recorded progress observations checked: 1');
    await expect(body).not.toContainText(`Support reference: ${id(80)}`);
    expect(f.errors).toEqual([]);
});

test('student-read authority alone never exposes recorded-progress support actions', async ({ page }) => {
    const f = await setup(page, { readOnly: true });
    await expect(page.getByRole('region', { name: 'Recorded progress summary' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Check recorded progress history', exact: true })).toHaveCount(0);
    expect(f.checks).toEqual([]);
    expect(f.errors).toEqual([]);
});

test('returning to a saved review rechecks access before showing its private summary', async ({ page }) => {
    const f = await setup(page);
    await page.getByRole('button', { name: 'Check recorded progress history', exact: true }).click();
    await page.route(`**/degree-requirements/records/${id(60)}/`, route =>
        route.fulfill({ status: 403, json: { error: 'Saved progress access is no longer available.' } }));
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.locator('dialog.degree-audit-studio')).toContainText('Saved progress access is no longer available.');
    await expect(page.getByRole('region', { name: 'Recorded progress summary' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Check recorded progress history', exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});
