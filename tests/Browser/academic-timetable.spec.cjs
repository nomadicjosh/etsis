const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

async function fixture(page, { empty = false, theme = 'campus-harbor', overlaps = false } = {}) {
    const state = { calls: [], errors: [], delay: false, release: null };
    const term = { id: id(1), code: '26/FA', starts_on: '2026-08-03', ends_on: '2026-12-18' };
    const other = { id: id(2), code: '27/SP', starts_on: '2027-01-04', ends_on: '2027-05-19' };
    const result = data => {
        const day = new Date(`${data.date || '2026-08-10'}T12:00:00Z`);
        const view = data.view || 'week';
        if (view === 'month') day.setUTCDate(1);
        else day.setUTCDate(day.getUTCDate() - (day.getUTCDay() + 6) % 7);
        const from = day.toISOString().slice(0, 10), length = view === 'month'
            ? new Date(Date.UTC(day.getUTCFullYear(), day.getUTCMonth() + 1, 0)).getUTCDate() : 7;
        const next = new Date(day); next.setUTCDate(next.getUTCDate() + length);
        const previous = new Date(day);
        if (view === 'month') previous.setUTCMonth(previous.getUTCMonth() - 1);
        else previous.setUTCDate(previous.getUTCDate() - 7);
        const items = data.term_id === term.id && ['2026-08-01', '2026-08-10'].includes(from)
            ? Array.from({ length: 21 }, (_, n) => ({ id: id(100 + n), section_id: id(4), section_code: 'MATH.101.26FA.A',
                course_code: 'MATH.101', title: 'Fictional <img src=x onerror=alert(1)> course', status: 'published',
                learning: true, teaching: true, starts_at: overlaps ? '2026-08-10T10:30:00-07:00' : '2026-08-10T10:00:00-07:00',
                ends_at: overlaps ? '2026-08-10T11:30:00-07:00' : '2026-08-10T11:00:00-07:00', overlap_count: overlaps ? 1 : 0,
                room_code: n % 2 ? 'LIB.101' : null, room_name: n % 2 ? 'Fictional lecture room' : null,
                building_code: n % 2 ? 'LIB' : null, building_name: n % 2 ? 'Fictional library' : null })) : [];
        if (overlaps && items.length) items.unshift({ ...items[0], id: id(999), section_id: id(8),
            section_code: 'ENG.201.26FA.B', course_code: 'ENG.201', title: 'Fictional writing seminar',
            teaching: false, starts_at: '2026-08-10T10:00:00-07:00', ends_at: '2026-08-10T11:00:00-07:00', overlap_count: 21 });
        const summary = count => ({ pairs: count ? 21 : 0, meetings: count ? 22 : 0, sections: count ? 2 : 0 });
        const days = Array.from({ length }, (_, n) => {
            const date = new Date(day); date.setUTCDate(date.getUTCDate() + n);
            const code = date.toISOString().slice(0, 10);
            const daily = code === '2026-08-10' ? items : [];
            const tomorrow = new Date(date); tomorrow.setUTCDate(tomorrow.getUTCDate() + 1);
            return { date: code, starts_at: `${code}T00:00:00-07:00`, ends_at: `${tomorrow.toISOString().slice(0, 10)}T00:00:00-07:00`,
                counts: { published: daily.length }, overlaps: summary(overlaps && daily.length),
                items: daily.map(item => ({ id: item.id, overlap_count: item.overlap_count,
                    continues_before: false, continues_after: false })) };
        });
        return { from, through: days.at(-1).date, next: next.toISOString().slice(0, 10), previous: previous.toISOString().slice(0, 10),
            view, timezone: 'America/Los_Angeles', today: '2026-08-10', term: data.term_id === other.id ? other : term,
            items, days, overlaps: summary(overlaps && items.length), totals: { published: items.length },
            classes: data.term_id === other.id ? [{ id: id(70), code: 'SP.2027.SEMINAR', title: 'Future seminar',
                learning: true, teaching: false, schedule_status: 'unpublished' }] : [{ id: id(4), code: 'MATH.101.26FA.A',
                course_code: 'MATH.101', title: 'Fictional course', learning: true, teaching: true, schedule_status: 'published' },
                { id: id(5), code: 'Fictional asynchronous class', title: 'Independent research', learning: true,
                    teaching: false, schedule_status: 'unscheduled' },
                { id: id(6), code: 'Fictional unannounced class', title: 'Seminar', learning: false,
                    teaching: true, schedule_status: 'unpublished' }, ...(overlaps ? [{ id: id(8), code: 'ENG.201.26FA.B',
                    title: 'Fictional writing seminar', learning: true, teaching: false, schedule_status: 'published' }] : [])] };
    };
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/portal/api/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        state.calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let response = {};
        if (path.endsWith('/context/')) response = { today: '2026-08-10', timezone: 'America/Los_Angeles',
            terms: empty ? [] : [term, other], recommended_term: empty ? null : term, next_after: null };
        else if (path.endsWith('/terms/')) response = { items: data.search === '27' ? [other] : [term, other], next_after: null };
        else if (path.endsWith('/view/')) {
            response = result(data);
            if (state.delay && data.date === '2026-08-17') await new Promise(resolve => { state.release = resolve; });
        }
        await route.fulfill({ json: response, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(`/portal/timetable/?theme=${theme}`);
    await expect(page.getByRole('heading', { name: 'My timetable', exact: true })).toBeVisible();
    if (!empty) await expect(page.getByLabel('Academic term', { exact: true })).toHaveValue(term.id);
    return state;
}

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
    expect(state.calls.every(call => call.path.includes('/timetable/'))).toBe(true);
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
        expect(state.calls.map(call => call.path)).toEqual(['/portal/api/timetable/context/']);
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
