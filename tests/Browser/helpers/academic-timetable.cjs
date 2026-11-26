'use strict';
const { expect } = require('@playwright/test');
const id = n => String(n).padStart(26, '0');
async function fixture(page, { empty = false, theme = 'campus-harbor', overlaps = false } = {}) {
    const state = { calls: [], errors: [], delay: false, release: null, exportMode: 'success', exportArrive: null, exportRelease: null };
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
        else if (path === '/portal/api/advising-appointments/calendar/') response = {
            from: '2026-08-10', through: '2026-08-16', view: 'week', previous: '2026-08-03', next: '2026-08-17',
            timezone: 'America/Los_Angeles', include_cancelled: false, items: [], days: [],
        };
        else if (path.endsWith('/terms/')) response = { items: data.search === '27' ? [other] : [term, other], next_after: null };
        else if (path.endsWith('/export/')) {
            if (state.exportMode === 'denied') return route.fulfill({ status: 403, json: { error: 'PRIVATE error' } });
            if (state.exportMode === 'failed') return route.fulfill({ status: 409, json: { error: 'PRIVATE source evidence' } });
            if (state.exportMode === 'delay') {
                state.exportArrive = true;
                await new Promise(resolve => { state.exportRelease = resolve; });
            }
            response = { calendar: 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\n'
                + 'UID:' + 'a'.repeat(64) + '@etsis\r\nDTSTAMP:20260810T120000Z\r\n'
                + 'DTSTART:20260810T170000Z\r\nDTEND:20260810T180000Z\r\n'
                + 'SUMMARY:Fictional <img> class\r\nCLASS:PRIVATE\r\nTRANSP:OPAQUE\r\n'
                + 'END:VEVENT\r\nEND:VCALENDAR\r\n', reason: 'Never render protected evidence' };
        }
        else if (path.endsWith('/view/')) {
            response = result(data);
            if (state.delay && data.date === '2026-08-17') await new Promise(resolve => { state.release = resolve; });
        }
        await route.fulfill({ json: response, headers: { 'Cache-Control': 'private, no-store' } }).catch(() => {});
    });
    await page.goto(`/portal/timetable/?theme=${theme}`);
    await expect(page.getByRole('heading', { name: 'My timetable', exact: true })).toBeVisible();
    if (!empty) await expect(page.getByLabel('Academic term', { exact: true })).toHaveValue(term.id);
    return state;
}

module.exports = fixture;
