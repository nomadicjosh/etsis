const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

const fixture = require('./helpers/academic-timetable.cjs');

for (const theme of ['campus-harbor', 'campus-quad', 'campus-horizon']) {
test(`personal timetable pages accessible hybrid meetings in ${theme} without overview reads`, async ({ page }) => {
    const state = await fixture(page, { theme });
    const content = page.locator('#campus-service-content');
    await expect(content.locator('tbody tr')).toHaveCount(20);
    await expect(content).toContainText('Online · No room');
    await expect(content).toContainText('Fictional library');
    await expect(content).toContainText('Learning & teaching');
    await expect(content).toContainText('Published without scheduled meetings');
    await expect(content).toContainText('Meeting times have not been published yet');
    await expect(page.getByRole('region', { name: 'Schedule overlap review', exact: true }))
        .toContainText('No overlaps between different sections');
    await expect(page.getByRole('button', { name: 'Review possible overlaps', exact: true })).toHaveCount(0);
    await expect(content.locator('img')).toHaveCount(0);
    await page.getByRole('button', { name: 'Next meetings', exact: true }).click();
    await expect(content.locator('tbody tr')).toHaveCount(1);
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Month', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('.academic-timetable-day')).toHaveCount(31);
    const accessibility = await new AxeBuilder({ page }).include('.campus-student-main').analyze();
    expect(accessibility.violations).toEqual([]);
    await page.setViewportSize({ width: 320, height: 900 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(state.calls.every(call => call.path.includes('/timetable/')
        || call.path === '/portal/api/advising-appointments/calendar/')).toBe(true);
    expect(state.calls.filter(call => call.data).every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(state.errors).toEqual([]);
});

}

test('a delayed week cannot replace a new date selection and changing terms remains owner scoped', async ({ page }) => {
    const state = await fixture(page);
    state.delay = true;
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await expect.poll(() => !!state.release).toBe(true);
    await page.getByLabel('Calendar date', { exact: true }).fill('2026-08-24');
    state.release();
    await expect(page.getByRole('button', { name: 'Next', exact: true })).toHaveCount(0);
    await expect(page.locator('#campus-service-content')).toContainText('Choose View timetable');
    await page.getByRole('button', { name: 'View timetable', exact: true }).click();
    await expect(page.locator('#campus-service-content h2')).toContainText('Aug 24');
    await page.getByText('Find another term', { exact: true }).click();
    await page.getByLabel('Find a term', { exact: true }).fill('27');
    await page.getByRole('button', { name: 'Find terms', exact: true }).click();
    await expect(page.locator('.academic-timetable details [role="status"]')).toContainText('1 terms available');
    await page.getByLabel('Academic term', { exact: true }).selectOption(id(2));
    await expect(page.locator('#campus-service-content h2')).toContainText('27/SP');
    await page.getByLabel('Academic term', { exact: true }).selectOption(id(1));
    await expect(page.locator('#campus-service-content h2')).toContainText('26/FA');
    expect(state.errors).toEqual([]);
});

test('an account without classes sees a useful empty state in each portal theme', async ({ page }) => {
    for (const theme of ['campus-harbor', 'campus-quad', 'campus-horizon']) {
        const state = await fixture(page, { empty: true, theme });
        await expect(page.locator('#campus-service-content')).toContainText('active course registration or teaching assignment');
        await expect(page.getByRole('button', { name: 'View timetable', exact: true })).toHaveCount(0);
        expect(state.calls.map(call => call.path).sort()).toEqual([
            '/portal/api/advising-appointments/calendar/', '/portal/api/timetable/context/',
        ]);
        expect(state.errors).toEqual([]);
    }
});

for (const theme of ['campus-harbor', 'campus-quad', 'campus-horizon']) {
test(`owner compares overlaps inline with accessible keyboard paging in ${theme}`, async ({ page }) => {
    const state = await fixture(page, { theme, overlaps: true });
    const calls = state.calls.length;
    const summary = page.getByRole('region', { name: 'Schedule overlap review', exact: true });
    await expect(summary).toContainText('21 possible overlaps to review');
    await expect(summary).toContainText('Other terms and unpublished class times are outside this review');
    await page.getByRole('button', { name: 'Review possible overlaps', exact: true }).click();
    await expect(page.getByLabel('Show overlapping meetings only', { exact: true })).toBeChecked();
    const daily = page.getByRole('region', { name: 'Daily class meetings', exact: true });
    await expect(daily.getByRole('heading', { level: 3 })).toBeFocused();
    const target = page.getByRole('button', { name: 'Review 21 overlaps', exact: true });
    await target.focus();
    await page.keyboard.press('Enter');
    const panel = page.getByRole('region', { name: 'Selected meeting overlap details', exact: true });
    await expect(panel.getByRole('heading', { name: 'Overlaps for ENG.201.26FA.B', exact: true })).toBeFocused();
    await expect(panel.locator('tbody tr')).toHaveCount(20);
    await expect(panel).toContainText('30 minutes');
    await expect(panel).toContainText('Online · No room');
    await expect(panel.locator('img')).toHaveCount(0);
    const accessibility = await new AxeBuilder({ page }).include('.campus-student-main').analyze();
    expect(accessibility.violations).toEqual([]);
    await page.setViewportSize({ width: 320, height: 900 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await panel.getByRole('button', { name: 'Next comparisons', exact: true }).click();
    await expect(panel.locator('tbody tr')).toHaveCount(1);
    await expect(panel).toContainText('Comparisons 21–21 of 21');
    await panel.getByRole('button', { name: 'Back to meetings', exact: true }).click();
    await expect(target).toBeFocused();
    await expect(panel).toHaveCount(0);
    const tuesday = page.getByRole('button', { name: /Tuesday, Aug 11, 2026:/ });
    await tuesday.focus();
    await page.keyboard.press('Enter');
    await expect(daily.getByRole('heading', { level: 3 })).toBeFocused();
    await expect(daily).toContainText('No overlapping class meetings for this day');
    expect(state.calls).toHaveLength(calls);
    expect(state.errors).toEqual([]);
});
}

test('a delayed period cannot restore an old overlap review after switching terms', async ({ page }) => {
    const state = await fixture(page, { overlaps: true });
    await page.getByRole('button', { name: 'Review 21 overlaps', exact: true }).click();
    state.delay = true;
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await expect.poll(() => !!state.release).toBe(true);
    await page.getByLabel('Academic term', { exact: true }).selectOption(id(2));
    await expect(page.locator('#campus-service-content h2')).toContainText('27/SP');
    state.release();
    await expect(page.getByRole('button', { name: 'Next', exact: true })).toBeEnabled();
    await expect(page.getByRole('region', { name: 'Selected meeting overlap details', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Review possible overlaps', exact: true })).toHaveCount(0);
    await expect(page.locator('#campus-service-content')).not.toContainText('ENG.201.26FA.B');
    expect(state.errors).toEqual([]);
});
