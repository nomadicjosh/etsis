const { recordAction, revealRecordAction } = require('./support/workspace-actions.cjs');
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

async function fixture(page) {
    const state = { calls: [], errors: [], categories: [{ id: id(1), code: 'COURSE', name: 'Course sections', tone: 'blue', active: true, version: 0 }], booking: null };
    const room = { id: id(2), code: 'LIB.101', name: 'Fictional lecture room', capacity: 40 };
    const section = { id: id(3), code: 'MATH.101.26FA.A', term_code: '26/FA', publication_id: id(4),
        instruction_starts_on: '2026-08-03', instruction_ends_on: '2026-08-19', delivery_mode: 'hybrid' };
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        state.calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/bookingcategories/') result.items = state.categories;
        else if (path === '/sis/ui/data/activebookingcategories/') result.items = state.categories.filter(item => item.active);
        else if (path === '/sis/ui/data/bookingsections/') result.items = [{ id: section.id, code: section.code, name: section.term_code }];
        else if (path === '/sis/ui/data/rooms/') result.items = [room];
        else if (path === '/sis/ui/data/bookings/' && state.booking) result.items = [state.booking];
        else if (path === `/sis/rooms/${room.id}/`) result = room;
        else if (path === `/sis/booking-sections/${section.id}/`) result = section;
        else if (path === '/sis/booking-categories/') {
            result = { id: id(9) };
            state.categories.push({ ...data, id: result.id, active: true, version: 0 });
        } else if (path.startsWith('/sis/booking-categories/')) {
            const record = state.categories.find(item => path.includes(item.id));
            if (data) Object.assign(record, data, { version: record.version + 1 });
            result = data ? { status: 'saved' } : record;
        } else if (path === '/sis/bookings/') {
            state.booking = { id: id(10), room_id: data.room_id || null, title: data.title, category_id: data.category_id || null,
                category: null, section: data.section_id ? section : null, status: 'requested', version: 0,
                occurrences: data.slots.map((slot, n) => ({ id: id(11 + n), ...slot, status: 'requested' })) };
            result = { id: state.booking.id, status: 'requested' };
        } else if (path === '/sis/bookings/planning/') result = { new_id: id(10), today: '2026-08-10', timezone: 'America/Los_Angeles' };
        else if (path === '/sis/bookings/planning/review/') result = { room: data.room_id ? room : null,
            category: state.categories[0], section,
            timezone: 'America/Los_Angeles', revision: 'fictional-review', conflicts: 0,
            items: [data.pattern.from, data.pattern.through].map(date => ({ starts_at: `${date}T10:00:00-07:00`,
                ends_at: `${date}T11:00:00-07:00`, local_start: `${date} 10:00 -07:00`, local_end: `${date} 11:00 -07:00`, available: true })) };
        else if (path === '/sis/bookings/planning/request/') {
            state.booking = { id: data.id, room_id: data.room_id || null, title: data.title, category_id: data.category_id,
                category: state.categories.find(item => item.id === data.category_id), section, status: 'requested', version: 0,
                occurrences: [{ id: id(11), starts_at: `${data.pattern.from}T10:00:00-07:00`,
                    ends_at: `${data.pattern.from}T11:00:00-07:00`, status: 'requested' }] };
            result = { id: data.id, status: 'requested' };
        } else if (path.endsWith('/approve/')) {
            state.booking.status = 'approved'; state.booking.version++;
            state.booking.occurrences.forEach(item => { item.status = 'approved'; });
            result = { id: state.booking.id, status: 'approved' };
        } else if (path.endsWith('/reschedule/review/')) {
            result = { new_id: id(12), room: data.room_id ? room : null,
                source_room: state.booking.room_id ? room : null, starts_at: data.starts_at, ends_at: data.ends_at,
                source_starts_at: state.booking.occurrences[0].starts_at,
                source_ends_at: state.booking.occurrences[0].ends_at, status: 'approved', available: true, revision: 'fictional-move' };
        } else if (path.endsWith('/reschedule/confirm/')) {
            state.booking = { ...state.booking, id: data.id, room_id: data.room_id || null, version: 1,
                occurrences: [{ id: id(14), status: 'approved', starts_at: data.starts_at, ends_at: data.ends_at }] };
            result = { id: data.id, status: 'approved' };
        } else if (path === `/sis/bookings/${id(10)}/category/`) {
            state.booking.category_id = data.category_id || null;
            state.booking.category = state.categories.find(item => item.id === data.category_id) || null;
            state.booking.version++;
            result = { id: id(10), version: state.booking.version };
        } else if (state.booking && path === `/sis/bookings/${state.booking.id}/`) result = state.booking;
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="bookings"]').click();
    return state;
}

test('category setup previews accessible colors and retires/restores definitions without deleting them', async ({ page }) => {
    const state = await fixture(page);
    await page.locator('#directory-tabs').getByRole('link', { name: 'Categories', exact: true }).click();
    await page.getByRole('button', { name: 'Add a category', exact: true }).click();
    await page.getByLabel('Category code').fill('EVENT');
    await page.getByLabel('Category name').fill('Campus events <img>');
    const body = page.locator('#drawer-body');
    for (const tone of ['blue', 'teal', 'amber', 'purple', 'gray']) {
        await page.getByRole('combobox', { name: 'Color', exact: true }).selectOption(tone);
        await expect(body.locator('.booking-category')).toHaveAttribute('data-tone', tone);
        const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
    }
    await expect(body.locator('img')).toHaveCount(0);
    await page.getByRole('button', { name: 'Save category', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Edit booking category', exact: true })).toBeVisible();
    await page.getByLabel('Available for new bookings').uncheck();
    await page.getByRole('button', { name: 'Save category', exact: true }).click();
    await expect(page.getByLabel('Available for new bookings')).not.toBeChecked();
    await expect(page.locator('#directory-results')).toContainText('Inactive');
    await page.getByLabel('Available for new bookings').check();
    await page.getByRole('button', { name: 'Save category', exact: true }).click();
    await expect(page.getByLabel('Available for new bookings')).toBeChecked();
    expect(state.categories).toHaveLength(2);
    expect(state.calls.filter(call => call.data).every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(state.errors).toEqual([]);
});

test('online class planning needs no room and can move an approved meeting into a room', async ({ page }) => {
    const state = await fixture(page);
    await page.getByRole('button', { name: 'Plan a meeting', exact: true }).click();
    await page.getByRole('combobox', { name: 'Room', exact: true }).selectOption(id(2));
    await page.getByLabel('Online meeting without a room').check();
    await expect(page.getByRole('combobox', { name: 'Room', exact: true })).toBeDisabled();
    await expect(page.getByRole('combobox', { name: 'Room', exact: true })).toHaveValue('');
    await expect(page.getByRole('combobox', { name: 'Course section', exact: true })).not.toHaveAttribute('required');
    await page.getByLabel('Online meeting without a room').uncheck();
    await expect(page.getByRole('combobox', { name: 'Room', exact: true })).toHaveValue(id(2));
    await page.getByLabel('Online meeting without a room').check();
    await page.getByLabel('Meeting purpose').fill('Fictional online Algebra');
    await page.getByRole('combobox', { name: 'Course section', exact: true }).selectOption(id(3));
    await page.getByRole('button', { name: 'Plan recurring meetings', exact: true }).click();
    await expect(page.getByLabel('Online meeting without a room')).toBeChecked();
    await expect(page.getByRole('combobox', { name: 'Room', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Use section dates', exact: true }).click();
    await page.getByRole('button', { name: 'Review meetings', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Online · No room');
    await expect(page.locator('#drawer-body')).toContainText('No room required');
    await page.getByRole('button', { name: 'Submit recurring request', exact: true }).click();
    await expect(page.getByRole('button', { name: 'View room agenda', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Approve online meetings', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm online meeting approval', exact: true }).click();
    await page.getByRole('button', { name: /^Reschedule meeting on/ }).click();
    await expect(page.getByLabel('Online meeting without a room')).toBeChecked();
    await page.getByLabel('Online meeting without a room').uncheck();
    await page.getByRole('combobox', { name: 'New room', exact: true }).selectOption(id(2));
    await page.getByRole('button', { name: 'Review new meeting', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Online · No room');
    await expect(page.locator('#drawer-body')).toContainText('LIB.101');
    await page.getByRole('button', { name: 'Confirm reschedule', exact: true }).click();
    await expect(page.getByRole('button', { name: 'View room agenda', exact: true })).toBeVisible();
    expect(state.booking.section.publication_id).toBe(id(4));
    expect(state.calls.some(call => /\/sis\/rooms\/(null|undefined)\//.test(call.path))).toBe(false);
    expect(state.calls.filter(call => call.data).every(call => call.csrf === 'fictional-test-token')).toBe(true);
    await page.setViewportSize({ width: 320, height: 1000 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
    expect(state.errors).toEqual([]);
});

test('standalone online meetings need no section and retain the connected approval and move workflow', async ({ page }) => {
    const state = await fixture(page);
    await page.getByRole('button', { name: 'Plan a meeting', exact: true }).click();
    await page.getByLabel('Online meeting without a room').check();
    await expect(page.getByRole('combobox', { name: 'Course section', exact: true })).toHaveValue('');
    await expect(page.getByRole('combobox', { name: 'Course section', exact: true })).not.toHaveAttribute('required');
    await expect(page.getByRole('combobox', { name: 'Room', exact: true })).toBeDisabled();
    await page.getByLabel('Meeting purpose').fill('Fictional standalone online event');
    await page.getByLabel(/^Starts/).fill('2026-08-10T10:00');
    await page.getByLabel(/^Ends/).fill('2026-08-10T11:00');
    await page.getByRole('button', { name: 'Submit meeting request', exact: true }).click();
    await page.getByRole('button', { name: 'Approve online meetings', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm online meeting approval', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Manage public event', exact: true })).toBeVisible();
    await page.getByRole('button', { name: /^Reschedule meeting on/ }).click();
    await expect(page.getByLabel('Online meeting without a room')).toBeChecked();
    await page.getByLabel('Online meeting without a room').uncheck();
    await page.getByRole('combobox', { name: 'New room', exact: true }).selectOption(id(2));
    await page.getByRole('button', { name: 'Review new meeting', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm reschedule', exact: true }).click();
    await expect(page.getByRole('button', { name: 'View room agenda', exact: true })).toBeVisible();
    expect(state.booking.section).toBeNull();
    expect(state.calls.some(call => /\/sis\/rooms\/(null|undefined)\//.test(call.path))).toBe(false);
    expect(state.calls.filter(call => call.data).every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(state.errors).toEqual([]);
});

test('recurring class requests use offering dates and preserve the actual section independently of category edits', async ({ page }) => {
    const state = await fixture(page);
    await page.getByRole('button', { name: 'Plan a meeting', exact: true }).click();
    await page.getByRole('combobox', { name: 'Room', exact: true }).selectOption(id(2));
    await page.getByLabel('Meeting purpose').fill('Fictional Algebra meetings');
    await page.getByRole('combobox', { name: 'Category', exact: true }).selectOption(id(1));
    await page.getByRole('combobox', { name: 'Course section', exact: true }).selectOption(id(3));
    await expect(page.locator('#drawer-body')).toContainText('MATH.101.26FA.A · 26/FA');
    await page.getByRole('button', { name: 'Plan recurring meetings', exact: true }).click();
    await expect(page.getByRole('combobox', { name: 'Category', exact: true })).toHaveValue(id(1));
    await expect(page.getByRole('combobox', { name: 'Course section', exact: true })).toHaveValue(id(3));
    await page.getByRole('button', { name: 'Use section dates', exact: true }).click();
    await expect(page.getByLabel('First date')).toHaveValue('2026-08-03');
    await expect(page.getByLabel('Last date')).toHaveValue('2026-08-19');
    await page.getByRole('button', { name: 'Review meetings', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Course sections');
    await expect(page.locator('#drawer-body')).toContainText('MATH.101.26FA.A · 26/FA');
    await page.getByRole('button', { name: 'Submit recurring request', exact: true }).click();
    await expect(await revealRecordAction(page, 'Change category')).toBeVisible();
    expect(state.calls.find(call => call.path === '/sis/bookings/planning/request/').data).toMatchObject({ category_id: id(1), section_id: id(3) });
    await recordAction(page, 'Change category');
    await page.getByRole('combobox', { name: 'Category', exact: true }).selectOption('');
    await page.getByRole('button', { name: 'Save category', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Unassigned');
    await expect(page.locator('#drawer-body')).toContainText('MATH.101.26FA.A · 26/FA');
    expect(state.booking.section.publication_id).toBe(id(4));
    expect(state.booking.category_id).toBeNull();
    await page.setViewportSize({ width: 320, height: 1000 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(state.errors).toEqual([]);
});
