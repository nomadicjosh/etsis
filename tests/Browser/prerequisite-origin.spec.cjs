const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const path = require('node:path');
for (const context of ['workspace-config', 'campus-config']) {
    for (const origin of ['institutional', 'transfer', 'either']) {
        test(`${context} explains ${origin} prerequisite work without changing degree policy`, async ({ page }) => {
            await page.setContent(`<!doctype html><html lang="en"><head><title>Fictional registration review</title></head><body><main class="container p-3"><h1>Review registration</h1><script id="${context}" type="application/json">{"prerequisite_origin_labels":{"field":"Qualifying prerequisite coursework"}}</script><div id="checks"></div></main></body></html>`);
            await page.addStyleTag({ path: path.resolve('public/assets/vendor/bootstrap.min.css') });
            await page.addScriptTag({ path: path.resolve('public/assets/js/registration-schedule.js') });
            await page.evaluate(origin => {
                const node = (tag, attributes = {}, ...children) => {
                    const el = document.createElement(tag);
                    for (const [key, value] of Object.entries(attributes)) el.setAttribute(key, value);
                    el.append(...children); return el;
                };
                document.getElementById('checks').append(window.createRegistrationSchedule({ node }).prerequisites({
                    course_statuses: ['missing'], groups: [{ completion_window: {from: '2025-01-01', through: '2025-12-31', max_age_months: 60}, completion_anchor: origin === 'either' ? null : {starts_on: '2026-08-31', earliest_completion_on: '2021-08-31'}, positions: [0], required_count: 1, completed_count: 0, met: false, credit_origin: origin === 'transfer' ? 'institutional' : 'transfer' }],
                    group_policy: { credit_origin: origin, required_count: 1, completed_count: 0, total_count: 1 },
                }, [{ code: '<img> HIST 101', title: 'Fictional history' }]));
            }, origin);
            await page.getByText('Required courses', { exact: true }).press('Enter');
            const label = { institutional: 'Institutional work only', transfer: 'Transfer work only', either: 'Institutional or transfer' }[origin];
            await expect(page.getByText(`Qualifying prerequisite coursework: ${label}`, { exact: true })).toBeVisible();
            await expect(page.locator('ol').getByText(`Qualifying prerequisite coursework: ${origin === 'transfer' ? 'Institutional work only' : 'Transfer work only'}`, { exact: true })).toBeVisible();
            await expect(page.getByText('This rule does not change earned credits or decide which work counts toward a degree.', { exact: true })).toBeVisible();
            await expect(page.getByText('Completed from: 2025-01-01 · Completed through: 2025-12-31', {exact: true})).toBeVisible();
            await expect(page.getByText(origin === 'either'
                ? 'Maximum age before section starts (months): 60 · The published section start is unavailable. Select a published offering to assess this age limit.'
                : 'Maximum age before section starts (months): 60 · Published section start: 2026-08-31 · Earliest completion under age limit: 2021-08-31', {exact: true})).toBeVisible();
            await expect(page.locator('img')).toHaveCount(0);
            await page.setViewportSize({ width: 320, height: 800 });
            await page.addStyleTag({ content: 'html {font-size:200% !important;}' });
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
            const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
            expect(scan.violations.map(item => item.id)).toEqual([]);
            if (context === 'campus-config' && origin === 'transfer') await page.screenshot({ path: '/tmp/etsis-prerequisite-origin-phone.png', fullPage: true });
        });
    }
}
