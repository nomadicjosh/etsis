/* Staff room planning. All data remains in memory; the server checks every operation. */
window.createWorkspaceScheduling = function (ui) {
    "use strict";
    const {
        node,
        request,
        task,
        openDrawer,
        buildForm,
        formData,
        notify,
        table,
        badge,
        flow,
        can,
        showAudit,
    } = ui;
    const button = (label, callback, style = "btn-outline-primary") =>
        node(
            "button",
            {
                type: "button",
                class: `btn ${style}`,
                onclick: (event) => task(callback, event.currentTarget),
            },
            label,
        );
    const dateFields = [
        ["starts_at", "Starts", "datetime-local"],
        ["ends_at", "Ends", "datetime-local"],
    ];
    const local = (date) =>
        new Date(date.getTime() - date.getTimezoneOffset() * 60000)
            .toISOString()
            .slice(0, 16);
    const display = (instant) => new Date(instant).toLocaleString();
    const roomLabel = record => [record.code, record.name].filter(Boolean).join(' · ');
    const buildingLabel = record => record.building ? `${record.building.code} · ${record.building.name}` : 'Building not assigned';
    const grid = (headers, rows, caption) =>
        node(
            "div",
            {
                class: "table-responsive",
                role: "region",
                tabindex: "0",
                "aria-label": caption,
            },
            table(headers, rows, caption),
        );
    const note = () =>
        node(
            "p",
            { class: "form-text" },
            "Times use your browser’s local timezone. Search up to 31 days. Requested bookings do not reserve a room; approval checks conflicts again.",
        );

    function validWindow(form, bounded = true) {
        const start = form.elements.starts_at,
            end = form.elements.ends_at;
        end.setCustomValidity("");
        if (!start.reportValidity() || !end.reportValidity()) return false;
        const duration =
            new Date(end.value).getTime() - new Date(start.value).getTime();
        end.setCustomValidity(
            duration <= 0
                ? "Choose an end time after the start."
                : bounded && duration > 31 * 86400000
                  ? "Search a window of 31 days or fewer."
                  : "",
        );
        return end.reportValidity();
    }
    const signature = (form) =>
        ["room_id", "starts_at", "ends_at"]
            .map((key) => form.elements.namedItem(key)?.value || "")
            .join("|");
    async function availability(id, window, output, current = () => true) {
        const result = await request(
            `/sis/rooms/${id}/availability/`,
            "POST",
            window,
        );
        if (!output.isConnected || !current()) return;
        output.textContent = result.available
            ? "No approved booking overlaps this window. Availability may change before approval."
            : "An approved booking overlaps this window. Review the room agenda or choose another time.";
    }
    async function room(id, startsAt = null) {
        const record = await request(`/sis/rooms/${id}/`);
        const body = openDrawer(roomLabel(record), "ROOM & AGENDA");
        if (can("sis:audit:read"))
            body.append(
                button("View audit history", () => showAudit("rooms", id)),
            );
        body.append(node("p", {}, `Capacity: ${record.capacity}`), note());
        body.append(node('p', { class: 'private-text' }, buildingLabel(record)));
        if (!record.name || !record.building_id)
            body.append(node('p', { class: 'alert alert-info' }, 'Complete this room’s name and building in Edit room details. Existing bookings are preserved.'));
        body.append(button('Weekly timetable', () => timetable(id)));
        body.append(
            button('Edit room details', () => roomForm(record)),
        );
        const starts = new Date(startsAt || Date.now());
        starts.setHours(0, 0, 0, 0);
        const ends = new Date(starts);
        ends.setDate(ends.getDate() + 1);
        const form = await buildForm(dateFields, {
            starts_at: local(starts),
            ends_at: local(ends),
        });
        const status = node("p", {
            role: "status",
            "aria-live": "polite",
            class: "form-text",
        });
        const content = node("div");
        const pages = node("div", { class: "inline-actions mt-3" });
        let window = null;
        let after = "";
        let nextAfter = null;
        let serial = 0;
        const previousCursors = [];
        const previous = button("Previous agenda page", async () => {
            after = previousCursors.pop() || "";
            await load();
        });
        const next = button("Next agenda page", async () => {
            previousCursors.push(after);
            after = nextAfter;
            await load();
        });
        pages.append(previous, next);
        async function load() {
            const current = ++serial;
            const result = await request(`/sis/rooms/${id}/agenda/`, "POST", {
                ...window,
                after,
            });
            if (!body.isConnected || current !== serial) return;
            nextAfter = result.next_after;
            previous.disabled = previousCursors.length === 0;
            next.disabled = !nextAfter;
            content.replaceChildren(
                result.items.length
                    ? grid(
                          ["Booking", "Status", "Starts", "Ends"],
                          result.items.map((item) => [
                              button(item.title, () =>
                                  booking(item.booking_id),
                              ),
                              badge(item.status),
                              display(item.starts_at),
                              display(item.ends_at),
                          ]),
                          "Room agenda",
                      )
                    : node(
                          "p",
                          {},
                          "No requested or approved bookings overlap this window.",
                      ),
            );
            status.textContent = `${result.items.length} occurrences on this page.${nextAfter ? " More are available." : ""}`;
        }
        form.append(
            node(
                "button",
                { type: "submit", class: "btn btn-primary" },
                "View agenda",
            ),
            button("Check availability", async () => {
                if (!validWindow(form) || !form.reportValidity()) return;
                const before = signature(form);
                await availability(
                    id,
                    formData(form, dateFields),
                    status,
                    () => signature(form) === before,
                );
            }),
            button("Request this room", () =>
                requestBooking({
                    room_id: id,
                    starts_at: form.elements.starts_at.value,
                    ends_at: form.elements.ends_at.value,
                }),
            ),
            button('Plan recurring meetings', () => requestSeries({ room_id: id }, () => room(id))),
        );
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!validWindow(form) || !form.reportValidity()) return;
                window = formData(form, dateFields);
                after = "";
                previousCursors.length = 0;
                await load();
            }, form.querySelector('[type="submit"]'));
        });
        form.addEventListener("input", () => {
            status.textContent = "";
            form.elements.ends_at.setCustomValidity("");
        });
        form.addEventListener("change", () => {
            status.textContent = "";
            form.elements.ends_at.setCustomValidity("");
        });
        body.append(form, status, content, pages);
        window = formData(form, dateFields);
        await load();
    }
    async function timetable(id, week = null, selected = null, approvedOnly = false) {
        const body = openDrawer('Weekly room timetable', 'SPACES & SCHEDULING');
        const fields = [['week', 'Date in week', 'date']];
        const form = await buildForm(fields, week ? { week } : {});
        if (!body.isConnected) return;
        const input = form.elements.week;
        input.min = '1000-01-06'; input.max = '9998-12-27';
        const heading = node('h3', { class: 'h5 private-text' });
        const status = node('p', { role: 'status', 'aria-live': 'polite' });
        const content = node('div');
        const filter = node('input', { type: 'checkbox', class: 'form-check-input', id: 'timetable-approved' });
        filter.checked = approvedOnly;
        const filterField = node('div', { class: 'form-check my-3' }, filter,
            node('label', { class: 'form-check-label', for: filter.id }, 'Show approved bookings only'));
        let result = null, serial = 0, page = 0;
        const weekButton = (label, direction) => node('button', { type: 'button', class: 'btn btn-outline-primary',
            onclick: () => task(() => result && load(result[direction])) }, label);
        const previous = weekButton('Previous week', 'previous');
        const next = weekButton('Next week', 'next');
        previous.disabled = true; next.disabled = true;
        const today = button('This week', () => { selected = null; return load(null); });
        const controls = node('div', { class: 'inline-actions mb-3' }, previous, today, next);
        const dateLabel = date => new Date(`${date}T12:00:00Z`).toLocaleDateString(undefined,
            { weekday: 'long', month: 'short', day: 'numeric', timeZone: 'UTC' });
        // Keep the server's school-local clock and explicit offset, regardless of browser timezone.
        const clockLabel = instant => `${instant.slice(11, 16)} UTC${instant.slice(19)}`;
        function draw(focusDay = false) {
            if (!result || !body.isConnected) return;
            const day = result.days.find(item => item.date === selected) || result.days[0];
            selected = day.date;
            const rowsById = new Map(result.items.map(item => [item.id, item]));
            const items = day.items.map(slot => ({ ...rowsById.get(slot.id), ...slot }))
                .filter(item => !filter.checked || item.status === 'approved');
            page = Math.min(page, Math.max(0, Math.ceil(items.length / 20) - 1));
            const days = node('div', { class: 'row g-2 mb-4', role: 'group', 'aria-label': 'Choose a timetable day' });
            for (const item of result.days) {
                const dayButton = button(dateLabel(item.date), () => { selected = item.date; page = 0; draw(true); },
                    item.date === selected ? 'btn-primary w-100 text-start' : 'btn-outline-primary w-100 text-start');
                dayButton.setAttribute('aria-pressed', String(item.date === selected));
                dayButton.dataset.date = item.date;
                dayButton.append(node('span', { class: 'd-block small mt-1' },
                    `${item.counts.approved} approved · ${item.counts.requested} requested`));
                days.append(node('div', { class: 'col-12 col-sm-6' }, dayButton));
            }
            const dayHeading = node('h4', { class: 'h6', tabindex: '-1' }, dateLabel(day.date));
            const summary = node('p', { class: 'form-text' },
                `${result.week} through ${result.through} · ${result.timezone}. ${result.totals.approved} approved and ${result.totals.requested} requested occurrences this week. Overnight meetings appear on each day they overlap.`);
            const listing = items.length ? grid(['Booking', 'Status', 'Starts', 'Ends'],
                items.slice(page * 20, (page + 1) * 20).map(item => [
                    button(item.title, () => booking(item.booking_id,
                        () => timetable(id, result.week, selected, filter.checked))),
                    badge(item.status),
                    item.continues_before ? 'Continues from an earlier day' : clockLabel(item.starts_at),
                    item.continues_after ? 'Continues into the next day' :
                        `${item.ends_at.slice(0, 10) !== day.date ? item.ends_at.slice(0, 10) + ' ' : ''}${clockLabel(item.ends_at)}`,
                ]), 'Daily room timetable') : node('p', {},
                    filter.checked ? 'No approved bookings for this day.' : 'No requested or approved bookings for this day.');
            const older = button('Previous daily page', () => { page--; draw(true); });
            const newer = button('Next daily page', () => { page++; draw(true); });
            older.disabled = page === 0; newer.disabled = (page + 1) * 20 >= items.length;
            content.replaceChildren(summary, days, dayHeading, listing,
                node('div', { class: 'inline-actions' }, older, newer));
            status.textContent = items.length
                ? `${dateLabel(day.date)}: meetings ${page * 20 + 1}–${Math.min((page + 1) * 20, items.length)} of ${items.length}.`
                : `${dateLabel(day.date)}: no matching meetings.`;
            if (focusDay) dayHeading.focus();
        }
        async function load(date) {
            const current = ++serial;
            result = null; previous.disabled = true; next.disabled = true;
            content.setAttribute('aria-busy', 'true');
            heading.textContent = ''; content.replaceChildren();
            status.textContent = 'Loading timetable…';
            try {
                const fresh = await request(`/sis/rooms/${id}/week/`, 'POST', date ? { week: date } : {});
                if (!body.isConnected || current !== serial) return;
                result = fresh; page = 0;
                input.value = fresh.week;
                heading.textContent = `${roomLabel(fresh.room)} · Capacity: ${fresh.room.capacity}`;
                previous.disabled = fresh.previous < input.min;
                next.disabled = fresh.next > input.max;
                if (!fresh.days.some(day => day.date === selected))
                    selected = fresh.days.some(day => day.date === fresh.today) ? fresh.today : fresh.week;
                draw();
            } catch (error) {
                if (!body.isConnected || current !== serial) return;
                status.textContent = 'Timetable unavailable. Retry or use the paged room agenda.';
                throw error;
            } finally {
                if (body.isConnected && current === serial) content.removeAttribute('aria-busy');
            }
        }
        form.append(node('button', { type: 'submit', class: 'btn btn-primary' }, 'View week'));
        form.addEventListener('submit', event => {
            event.preventDefault();
            if (form.reportValidity()) task(() => load(input.value), form.querySelector('[type="submit"]'));
        });
        input.addEventListener('input', () => {
            ++serial; result = null;
            previous.disabled = true; next.disabled = true;
            heading.textContent = ''; content.replaceChildren(); content.removeAttribute('aria-busy');
            status.textContent = 'Choose View week to load these dates.';
        });
        filter.addEventListener('change', () => { page = 0; draw(); });
        body.append(heading, node('p', { class: 'form-text' },
            'Times use the institution’s timezone. Requested bookings do not reserve the room. Approval checks availability again.'),
            button('Back to room agenda', () => room(id)), controls, form, filterField, status, content);
        await load(week);
    }
    async function requestBooking(preset = {}) {
        const body = openDrawer("Request a space", "SPACES & SCHEDULING");
        body.append(note());
        const fields = [
            ["room_id", "Room", "lookup", "rooms"],
            ["title", "What is the space for?"],
            ...dateFields,
        ];
        const form = await buildForm(fields, preset);
        const status = node("p", {
            role: "status",
            "aria-live": "polite",
            class: "form-text",
        });
        form.addEventListener("input", () => {
            status.textContent = "";
            form.elements.ends_at.setCustomValidity("");
        });
        form.addEventListener("change", () => {
            status.textContent = "";
            form.elements.ends_at.setCustomValidity("");
        });
        form.append(
            button('Plan recurring meetings', () => {
                const draft = { room_id: form.elements.room_id.value, title: form.elements.title.value,
                    starts_at: form.elements.starts_at.value, ends_at: form.elements.ends_at.value };
                return requestSeries(draft, () => requestBooking(draft));
            }),
            button("Check availability", async () => {
                if (
                    !["room_id", "starts_at", "ends_at"].every((key) =>
                        form.elements[key].reportValidity(),
                    )
                )
                    return;
                if (!validWindow(form)) return;
                const dates = formData(form, dateFields);
                const before = signature(form);
                await availability(
                    form.elements.room_id.value,
                    dates,
                    status,
                    () => signature(form) === before,
                );
            }),
            node(
                "button",
                { type: "submit", class: "btn btn-primary" },
                "Submit space request",
            ),
        );
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!validWindow(form, false) || !form.reportValidity()) return;
                const data = formData(form, fields);
                const saved = await request("/sis/bookings/", "POST", {
                    room_id: data.room_id,
                    title: data.title,
                    slots: [
                        { starts_at: data.starts_at, ends_at: data.ends_at },
                    ],
                });
                notify("Space requested. Approval is required to reserve it.");
                await ui.refreshDirectory();
                await booking(saved.id);
            }, form.querySelector('[type="submit"]'));
        });
        body.append(form, status);
    }

    async function requestSeries(preset = {}, back = () => requestBooking(preset)) {
        const body = openDrawer('Plan recurring meetings', 'SPACES & SCHEDULING');
        body.append(button('Back to space request', back));
        const context = await request('/sis/bookings/planning/');
        if (!body.isConnected) return;
        body.append(node('p', { class: 'form-text' },
            `Meeting dates and times use ${context.timezone}. Choose days once, skip closures and review every occurrence. Requests do not reserve a room until approved.`));
        const defaults = { ...preset, from: context.today, through: context.today, start_time: '09:00', end_time: '10:00', interval_weeks: 1 };
        if (preset.starts_at && preset.ends_at) {
            try {
                const parts = instant => Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
                    timeZone: context.timezone, year: 'numeric', month: '2-digit', day: '2-digit',
                    hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
                }).formatToParts(new Date(instant)).filter(p => p.type !== 'literal').map(p => [p.type, p.value]));
                const start = parts(preset.starts_at), end = parts(preset.ends_at);
                defaults.from = `${start.year}-${start.month}-${start.day}`;
                defaults.through = defaults.from;
                defaults.start_time = `${start.hour}:${start.minute}`;
                defaults.end_time = `${end.hour}:${end.minute}`;
                defaults.overnight = `${end.year}-${end.month}-${end.day}` > defaults.from;
            } catch { /* Keep explicit institution-date defaults if the browser cannot format its timezone. */ }
        }
        const fields = [['room_id', 'Room', 'lookup', 'rooms'], ['title', 'What is the space for?'],
            ['from', 'First date', 'date'], ['through', 'Last date', 'date'],
            ['start_time', 'Meeting starts', 'time'], ['end_time', 'Meeting ends', 'time'],
            ['overnight', 'Ends next day', 'checkbox', '', false], ['interval_weeks', 'Repeat interval in weeks', 'number'],
            ['excluded_dates', 'Dates to skip', 'textarea', '', false]];
        const form = await buildForm(fields, defaults);
        if (!body.isConnected) return;
        form.elements.interval_weeks.min = '1'; form.elements.interval_weeks.max = '12';
        form.elements.excluded_dates.maxLength = 1500;
        form.elements.excluded_dates.placeholder = '2026-11-26\n2026-12-24';
        const days = node('fieldset', { class: 'mb-3', 'aria-describedby': 'meeting-days-error' },
            node('legend', { class: 'h6' }, 'Meeting days'));
        const errors = node('p', { role: 'alert', class: 'text-danger', id: 'meeting-days-error' });
        const selected = new Date(`${defaults.from}T12:00:00Z`).getUTCDay() || 7;
        const checks = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'].map((label, i) => {
            const control = node('input', { type: 'checkbox', class: 'form-check-input', id: `meeting-day-${i + 1}`,
                value: i + 1, checked: selected === i + 1 });
            control.addEventListener('change', () => { errors.textContent = ''; });
            days.append(node('div', { class: 'form-check form-check-inline' }, control,
                node('label', { for: control.id, class: 'form-check-label' }, label)));
            return control;
        });
        days.append(node('p', { class: 'form-text' },
            'The date range is inclusive. Alternate weeks start with the week containing the first date. List skipped meeting dates one per line or separated by commas.'), errors);
        form.elements.interval_weeks.closest('.mb-4').before(days);
        const reviewButton = node('button', { type: 'submit', class: 'btn btn-primary' }, 'Review meetings');
        form.append(reviewButton);
        const preview = node('div', { hidden: true });
        body.append(form, preview);
        form.addEventListener('submit', event => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const weekdays = checks.filter(input => input.checked).map(input => Number(input.value));
                if (!weekdays.length) { errors.textContent = 'Choose at least one meeting day.'; checks[0].focus(); return; }
                const data = formData(form, fields);
                const payload = { room_id: data.room_id, title: data.title, pattern: {
                    from: data.from, through: data.through, start_time: data.start_time, end_time: data.end_time,
                    overnight: data.overnight, interval_weeks: data.interval_weeks, weekdays,
                    excluded_dates: (data.excluded_dates || '').split(/[\s,]+/).filter(Boolean), timezone: context.timezone,
                } };
                const result = await request('/sis/bookings/planning/review/', 'POST', payload);
                if (!body.isConnected) return;
                const content = node('div'), status = node('p', { role: 'status', class: 'form-text' });
                let page = 0;
                const previous = button('Previous meetings', () => { page--; draw(); });
                const next = button('Next meetings', () => { page++; draw(); });
                function draw() {
                    const items = result.items.slice(page * 20, (page + 1) * 20);
                    content.replaceChildren(grid(['Starts', 'Ends', 'Availability'], items.map(item => [
                        item.local_start, item.local_end, badge(item.available ? 'Available at review' : 'Conflict'),
                    ]), 'Meetings to request'));
                    status.textContent = `Meetings ${page * 20 + 1}–${Math.min((page + 1) * 20, result.items.length)} of ${result.items.length}.`;
                    previous.disabled = page === 0; next.disabled = (page + 1) * 20 >= result.items.length;
                }
                preview.replaceChildren(node('h3', { class: 'h5 private-text' }, `${result.room.code} · ${data.title}`),
                    node('p', { class: 'form-text' }, `${roomLabel(result.room)} · ${result.items.length} meetings in ${result.timezone}. ${result.conflicts} conflicts with approved bookings. Approval will check again.`),
                    content, status, node('div', { class: 'inline-actions' }, previous, next),
                    button('Back to meeting pattern', () => { preview.hidden = true; form.hidden = false; reviewButton.focus(); }),
                    button('Submit recurring request', async () => {
                        const saved = await request('/sis/bookings/planning/request/', 'POST',
                            { ...payload, id: context.new_id, revision: result.revision });
                        if (!body.isConnected) return;
                        notify('Meetings requested. Approval is required to reserve the room.');
                        await ui.refreshDirectory();
                        await booking(saved.id);
                    }, 'btn-primary'));
                if (result.conflicts) preview.append(button('Show first conflict', () => {
                    page = Math.floor(result.items.findIndex(item => !item.available) / 20); draw();
                    content.querySelector('table')?.scrollIntoView({ block: 'nearest', behavior: 'instant' });
                }));
                draw(); form.hidden = true; preview.hidden = false;
                preview.querySelector('button:not(:disabled)').focus();
            }, reviewButton);
        });
    }
    async function booking(id, back = null) {
        const record = await request(`/sis/bookings/${id}/`);
        const place = await request(`/sis/rooms/${record.room_id}/`);
        const body = openDrawer(record.title, "SPACE REQUEST");
        if (back) body.append(button('Back to weekly timetable', back));
        if (can("sis:audit:read"))
            body.append(
                button("View audit history", () => showAudit("bookings", id)),
            );
        body.append(
            badge(record.status),
            node("p", {}, `Room: ${roomLabel(place)} · Capacity: ${place.capacity}`),
            node('p', { class: 'private-text' }, buildingLabel(place)),
            note(),
            button("View room agenda", () =>
                room(record.room_id, record.slots[0].starts_at),
            ),
            grid(
                ["Starts", "Ends"],
                record.slots.map((slot) => [
                    display(slot.starts_at),
                    display(slot.ends_at),
                ]),
                "Requested times",
            ),
        );
        if (record.status === "requested")
            body.append(
                button(
                    "Approve space request",
                    () =>
                        flow(
                            "Confirm space approval",
                            [],
                            `/sis/bookings/${id}/approve/`,
                            () => ({ version: record.version }),
                            async () => {
                                await ui.refreshDirectory();
                                await booking(id, back);
                            },
                            {},
                            "Approval reserves every listed occurrence. Overlapping approved bookings prevent approval.",
                        ),
                    "btn-primary",
                ),
            );
        if (record.status !== "cancelled")
            body.append(
                button("Cancel space request", () =>
                    flow(
                        "Confirm space cancellation",
                        [],
                        `/sis/bookings/${id}/cancel/`,
                        () => ({ version: record.version }),
                        async () => {
                            await ui.refreshDirectory();
                            await booking(id, back);
                        },
                        {},
                        "Cancellation applies to every occurrence, releases approved times and keeps the booking history.",
                    ),
                ),
            );
    }
    async function building(id) {
        const record = await request(`/sis/buildings/${id}/`);
        const body = openDrawer(`${record.code} · ${record.name}`, 'BUILDING');
        body.append(node('p', {}, 'Rooms reference this building by its stable identity. Renaming it preserves room and booking links.'),
            button('Edit building details', () => buildingForm(record)));
    }
    async function buildingForm(record = null, saved = null, back = null) {
        const fields = [['code', 'Building code'], ['name', 'Building name']];
        const body = openDrawer(record ? 'Edit building' : 'Add a building', 'SPACES & SCHEDULING');
        const form = await buildForm(fields, record || {});
        if (!body.isConnected) return;
        form.elements.code.maxLength = 80;
        if (back) form.append(button('Back to room details', back));
        form.append(node('button', { type: 'submit', class: 'btn btn-primary' }, 'Save building'));
        form.addEventListener('submit', event => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const result = await request(record ? `/sis/buildings/${record.id}/edit/` : '/sis/buildings/', 'POST',
                    { ...formData(form, fields), ...(record ? { revision: record.revision } : {}) });
                if (!body.isConnected) return;
                notify('Building saved.');
                await ui.refreshDirectory();
                if (saved) await saved(result.id || record.id);
                else await building(result.id || record.id);
            }, form.querySelector('[type="submit"]'));
        });
        body.append(form);
    }
    async function roomForm(record = null, preset = {}) {
        const fields = [['code', 'Room code'], ['name', 'Room name'],
            ['building_id', 'Building', 'lookup', 'buildings'], ['capacity', 'Capacity', 'number']];
        const body = openDrawer(record ? 'Edit room details' : 'Add a room', 'SPACES & SCHEDULING');
        const initial = Object.fromEntries(Object.entries({ ...record, ...preset }).filter(([, value]) => value !== null));
        const form = await buildForm(fields, initial);
        if (!body.isConnected) return;
        form.elements.code.maxLength = 80;
        body.append(node('p', { class: 'form-text' },
            'Use a short unique code and a descriptive name, for example LIB.101 and Quiet study room. Choose its building; booking links stay connected when room details change.'));
        form.append(button('Add a building', () => {
            const draft = formData(form, fields);
            return buildingForm(null, id => roomForm(record, { ...draft, building_id: id }), () => roomForm(record, draft));
        }));
        if (record) form.append(button('Back to room agenda', () => room(record.id)));
        form.append(node('button', { type: 'submit', class: 'btn btn-primary' }, record ? 'Save room details' : 'Save room'));
        form.addEventListener('submit', event => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const result = await request(record ? `/sis/rooms/${record.id}/edit/` : '/sis/rooms/', 'POST',
                    { ...formData(form, fields), ...(record ? { revision: record.revision } : {}) });
                if (!body.isConnected) return;
                notify('Room saved.'); await ui.refreshDirectory();
                await room(result.id || record.id);
            }, form.querySelector('[type="submit"]'));
        });
        body.append(form);
    }
    return { room, booking, requestBooking, building, buildingForm, roomForm };
};
