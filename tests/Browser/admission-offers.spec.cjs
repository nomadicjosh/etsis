const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const path = require('node:path');

for (const staff of [false, true]) {
    test(`${staff ? 'staff issue' : 'applicant response'} uses one accessible private offer editor`, async ({ page }) => {
        await page.setContent('<!doctype html><html lang="en"><head><title>Fictional admission offer</title></head><body><main class="container py-4"><h1>Fictional application</h1><div id="offer"></div></main></body></html>');
        await page.addStyleTag({ path: path.resolve('public/assets/vendor/bootstrap.min.css') });
        await page.addScriptTag({ path: path.resolve('public/assets/js/admission-offers.js') });
        await page.evaluate(staff => {
            const node = (tag, attributes = {}, ...children) => {
                const element = document.createElement(tag);
                for (const [name, value] of Object.entries(attributes)) {
                    if (typeof value === 'boolean') element[name] = value;
                    else element.setAttribute(name, value);
                }
                element.append(...children); return element;
            };
            let offer = { id: 'fictional-letter', title: 'Fictional admission offer', message: 'Welcome to the fictional program.', status: 'pending', require_acceptance: true, respond_by: null };
            window.offerWrites = [];
            window.createAdmissionOffers({ node, notify() {}, task: run => Promise.resolve().then(run),
                request: async (url, method, body) => {
                    if (method === 'POST') window.offerWrites.push({ url, body });
                    if (url.endsWith('/review/')) return { action: body.action, offer: { ...offer, title: body.title || offer.title, message: body.message || offer.message, status: body.action === 'issue' ? 'pending' : 'accepted' }, review_token: 'fictional-private-review' };
                    if (url.endsWith('/commit/')) { offer = { ...offer, status: staff ? 'pending' : 'accepted' }; return {}; }
                    return { offer: staff ? null : offer, application_status: 'accepted', response_open: !staff && offer.status === 'pending', policy: { require_acceptance: true, revision: 0 } };
                } }).mount(document.getElementById('offer'), 'fictional-application', { staff, canIssue: staff, canPolicy: staff });
        }, staff);
        await page.getByRole('button', { name: 'Admission offer', exact: true }).press('Enter');
        await page.getByRole('button', { name: staff ? 'Issue offer' : 'Accept offer', exact: true }).click();
        if (staff) {
            await page.getByLabel('Offer letter', { exact: true }).fill('Welcome to the fictional program.');
            await page.getByLabel('Reason', { exact: true }).fill('Fictional reviewed offer.');
        }
        await page.getByRole('button', { name: 'Review', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Confirm', exact: true })).toBeFocused();
        expect(await page.evaluate(() => window.offerWrites.filter(item => item.url.endsWith('/commit/')))).toEqual([]);
        await page.setViewportSize({ width: 390, height: 844 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations.map(item => item.id)).toEqual([]);
        await page.getByRole('button', { name: 'Confirm', exact: true }).press('Enter');
        await expect.poll(() => page.evaluate(() => window.offerWrites.filter(item => item.url.endsWith('/commit/')).length)).toBe(1);
        const writes = await page.evaluate(() => window.offerWrites);
        expect(writes.at(-1).body).toEqual({ review_token: 'fictional-private-review' });
    });
}
