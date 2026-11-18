const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const path = require('node:path');

for (const staff of [false, true]) {
    test(`${staff ? 'staff approval' : 'applicant request'} has an accessible reviewed withdrawal flow`, async ({ page }) => {
        await page.setContent('<!doctype html><html lang="en"><head><title>Fictional application request</title></head><body><main class="container py-4"><h1>Fictional application</h1><div id="requests"></div></main></body></html>');
        await page.addStyleTag({ path: path.resolve('public/assets/vendor/bootstrap.min.css') });
        await page.addScriptTag({ path: path.resolve('public/assets/js/admission-withdrawals.js') });
        await page.evaluate(staff => {
            const node = (tag, attributes = {}, ...children) => {
                const element = document.createElement(tag);
                for (const [name, value] of Object.entries(attributes)) {
                    if (typeof value === 'boolean') element[name] = value;
                    else element.setAttribute(name, value);
                }
                element.append(...children); return element;
            };
            window.withdrawalWrites = [];
            const item = { id: 'fictional-request', reason: 'Fictional change of plans.', requested_at: '2026-11-17T12:00:00+00:00', status: 'pending', response: null };
            window.createAdmissionWithdrawals({ node, notify() {}, task: run => Promise.resolve().then(run), request: async (url, method, body) => {
                if (method === 'POST') window.withdrawalWrites.push({ url, body });
                if (url.endsWith('/review/')) return { action: body.action, reason: body.reason, request: staff ? item : null, review_token: 'fictional-private-review' };
                if (url.endsWith('/commit/')) return { status: staff ? 'approved' : 'pending', application_status: staff ? 'withdrawn' : 'submitted' };
                return { items: staff ? [item] : [], next: null, policy: { mode: 'request', revision: 0 }, application_status: 'submitted', has_pending: staff, pending_id: staff ? item.id : null };
            } }).mount(document.getElementById('requests'), 'fictional-application', { staff, canResolve: staff });
        }, staff);
        await page.getByRole('button', { name: 'Withdrawal', exact: true }).press('Enter');
        await page.getByRole('button', { name: staff ? 'Approve withdrawal' : 'Request withdrawal', exact: true }).click();
        await page.getByLabel(staff ? 'Response to applicant' : 'Reason for withdrawal').fill('Fictional reviewed instruction.');
        await page.getByRole('button', { name: 'Review', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Confirm', exact: true })).toBeFocused();
        expect(await page.evaluate(() => window.withdrawalWrites.filter(item => item.url.endsWith('/commit/')))).toEqual([]);
        await page.setViewportSize({ width: 390, height: 844 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations.map(item => item.id)).toEqual([]);
        await page.getByRole('button', { name: 'Confirm', exact: true }).press('Enter');
        await expect.poll(() => page.evaluate(() => window.withdrawalWrites.filter(item => item.url.endsWith('/commit/')).length)).toBe(1);
        expect((await page.evaluate(() => window.withdrawalWrites)).at(-1).body).toEqual({ review_token: 'fictional-private-review' });
    });
}
