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
        recordSummary,
        actionMenu,
    } = ui;
    const button = (label, callback, style = "btn-outline-primary", accessibleLabel = null) =>
        node(
            "button",
            {
                type: "button",
                class: `btn ${style}`,
                'aria-label': accessibleLabel,
                onclick: (event) => task(callback, event.currentTarget),
            },
            label,
        );
    const dateFields = [
        ["starts_at", "Starts", "datetime-local"],
        ["ends_at", "Ends", "datetime-local"],
    ];
    const classificationFields = [
        ['category_id', 'Category', 'lookup', 'activebookingcategories', false],
        ['section_id', 'Course section', 'lookup', 'bookingsections', false],
    ];
    function categoryBadge(record) {
        const name = record?.name || record?.category_name;
        if (!name) return node('span', { class: 'small text-secondary' }, 'Unassigned');
        const tone = record.tone || record.category_tone;
        const span = node('span', { class: 'booking-category' }, name);
        span.dataset.tone = ['blue', 'teal', 'amber', 'purple', 'gray'].includes(tone) ? tone : 'gray';
        return span;
    }
    const sectionLabel = record => record ? `${record.code} · ${record.term_code}` : 'No course section linked';
    async function sectionHint(form) {
        const select = form.elements.section_id;
        const panel = node('div', { class: 'form-text mb-3', role: 'status' });
        select.closest('.mb-4').append(panel);
        let serial = 0;
        const load = async () => {
            const current = ++serial, id = select.value;
            panel.replaceChildren();
            if (!id) return;
            panel.textContent = 'Loading the selected offering…';
            try {
                const context = await request(`/sis/booking-sections/${id}/`);
                if (!form.isConnected || serial !== current || select.value !== id) return;
                panel.textContent = `${sectionLabel(context)} · ${context.delivery_mode.replaceAll('_', ' ')} · Instruction: ${context.instruction_starts_on} through ${context.instruction_ends_on}. The meeting keeps this published offering reference.`;
                if (form.elements.from) panel.append(button('Use section dates', () => {
                    form.elements.from.value = context.instruction_starts_on;
                    form.elements.through.value = context.instruction_ends_on;
                    form.dispatchEvent(new Event('input', { bubbles: true }));
                }, 'btn-link'));
            } catch {
                if (form.isConnected && serial === current) panel.textContent = 'Select an active section with a published offering, or leave it unlinked.';
            }
        };
        select.addEventListener('change', load);
        await load();
    }
    const local = (date) =>
        new Date(date.getTime() - date.getTimezoneOffset() * 60000)
            .toISOString()
            .slice(0, 16);
    const display = (instant) => new Date(instant).toLocaleString();
    const meetingTime = instant => new Date(instant).toLocaleString(undefined, { timeZoneName: 'shortOffset' });
    const roomLabel = record => record ? [record.code, record.name].filter(Boolean).join(' · ') : 'Online · No room';
    const buildingLabel = record => record?.building ? `${record.building.code} · ${record.building.name}`
        : record ? 'Building not assigned' : 'No physical building';
    const onlineField = ['online', 'Online meeting without a room', 'checkbox', '', false];
    function locationControls(form, initialOnline = false) {
        const online = form.elements.online, room = form.elements.room_id;
        if (!online) return;
        const roomMark = room.labels[0]?.querySelector('.required-indicator');
        let previousRoom = room.value;
        online.checked = initialOnline;
        const apply = () => {
            if (online.checked) {
                previousRoom = room.value || previousRoom;
                room.value = '';
            } else if (!room.value && previousRoom) room.value = previousRoom;
            room.disabled = online.checked;
            room.required = !online.checked;
            if (roomMark) roomMark.hidden = online.checked;
        };
        online.addEventListener('change', apply);
        apply();
        online.closest('.mb-4').append(node('p', { class: 'form-text' },
            'Online meetings do not reserve a physical room. A course section is optional; linked classes need a published online or hybrid offering.'));
    }
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
    function meetingCard(title, startsAt, endsAt, status, details, actions, label, format = meetingTime) {
        return node(
            "article",
            { class: "scheduling-meeting-card", "aria-label": label },
            node("div", { class: "scheduling-meeting-heading" },
                node("span", { class: "icon-soft", "aria-hidden": "true" },
                    node("i", { class: "bi bi-calendar-event" })),
                node("h4", {}, title), badge(status)),
            details,
            recordSummary([
                ["Starts", node("time", { datetime: startsAt }, format(startsAt))],
                ["Ends", node("time", { datetime: endsAt }, format(endsAt))],
            ]),
            actions ? node("div", { class: "scheduling-meeting-footer" }, actions) : null,
        );
    }
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
    async function room(id, startsAt = null, savedAgenda = null) {
        const pending = openDrawer("Room workspace", "ROOM & AGENDA");
        pending.append(node("p", { role: "status", class: "form-text" }, "Loading room details…"));
        const record = await request(`/sis/rooms/${id}/`);
        if (!pending.isConnected) return;
        let currentAgenda = savedAgenda;
        const returnToRoom = () => room(id, startsAt, currentAgenda);
        const body = openDrawer(roomLabel(record), "ROOM & AGENDA", {record:{key:`room:${id}`,label:roomLabel(record),run:returnToRoom}});
        body.append(
            recordSummary([
                ["Room code", record.code],
                ["Building", buildingLabel(record)],
                ["Capacity", record.capacity],
            ]),
        );
        const actions = node(
            "div",
            { class: "inline-actions", role: "group", "aria-label": "Room tasks" },
            button("Room timetable", () => timetable(id, null, null, false, 'week', returnToRoom), "btn-primary"),
            button("Edit room details", () => roomForm(record, {}, returnToRoom)),
        );
        if (record.building_id)
            actions.append(button("Open building", () => building(record.building_id), "btn-outline-secondary"));
        if (can("sis:audit:read"))
            actions.append(
                button("View audit history", () => showAudit("rooms", id), "btn-outline-secondary"),
            );
        body.append(actions, note());
        if (!record.name || !record.building_id)
            body.append(
                node(
                    "p",
                    { class: "alert alert-info" },
                    "Complete this room’s name and building in Edit room details. Existing bookings are preserved.",
                ),
            );
        const starts = new Date(startsAt || Date.now());
        starts.setHours(0, 0, 0, 0);
        const ends = new Date(starts);
        ends.setDate(ends.getDate() + 1);
        const form = await buildForm(dateFields, savedAgenda ? {
            starts_at: local(new Date(savedAgenda.window.starts_at)),
            ends_at: local(new Date(savedAgenda.window.ends_at)),
        } : {
            starts_at: local(starts),
            ends_at: local(ends),
        }, { layout: 'agenda_window' });
        if (!body.isConnected) return;
        const status = node("p", {
            role: "status",
            "aria-live": "polite",
            class: "form-text",
        });
        const content = node("div");
        const pages = node("nav", { class: "inline-actions my-3", "aria-label": "Room agenda pages" });
        let window = null;
        let after = savedAgenda?.after || "";
        let nextAfter = null;
        let serial = 0;
        const previousCursors = [...(savedAgenda?.previousCursors || [])];
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
            currentAgenda = { window: { ...window }, after, previousCursors: [...previousCursors] };
            nextAfter = result.next_after;
            previous.disabled = previousCursors.length === 0;
            next.disabled = !nextAfter;
            content.replaceChildren(
                result.items.length
                    ? node("section", { class: "scheduling-meeting-list", "aria-label": "Room agenda" },
                          result.items.map((item) => meetingCard(
                              button(item.title, () => booking(item.booking_id,
                                  { label: "Back to room agenda", open: returnToRoom }), "btn-link"),
                              item.starts_at, item.ends_at, item.status,
                              node("div", { class: "scheduling-meeting-context" }, categoryBadge(item),
                                  item.section_code ? node("span", {}, `${item.section_code} · ${item.section_term_code}`) : null),
                              null, item.title, display,
                          )))
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
                "div",
                { class: "inline-actions", role: "group", "aria-label": "Agenda actions" },
                node("button", { type: "submit", class: "btn btn-primary" }, "View agenda"),
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
            ),
            node(
                "div",
                { class: "inline-actions", role: "group", "aria-label": "Meeting requests" },
                button("Request this room", () =>
                    requestBooking({
                        room_id: id,
                        starts_at: form.elements.starts_at.value,
                        ends_at: form.elements.ends_at.value,
                    }),
                ),
                button("Plan recurring meetings", () => requestSeries({ room_id: id }, () => room(id))),
            ),
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
        body.append(form, status, pages, content);
        window = formData(form, dateFields);
        await load();
    }
    let lastCalendar = null, timetableSerial = 0;
    async function timetable(roomId, date = null, selected = null, approvedOnly = false, view = 'week', roomBack = null) {
        return calendarView({ roomId, date, selected, approvedOnly, view, roomBack });
    }
    async function calendar(target, isCurrent = () => true) {
        const loading = node('p', { role: 'status' }, 'Loading booking calendar…');
        target.replaceChildren(loading);
        try {
            const context = await request('/sis/calendar/context/');
            if (!loading.isConnected || !isCurrent()) return;
            await calendarView({ ...(lastCalendar || { date: context.today }), target, isCurrent });
        } catch (error) {
            if (!loading.isConnected || !isCurrent()) return;
            loading.textContent = 'The calendar could not be loaded. Try opening Calendar again.';
            loading.setAttribute('role', 'alert');
            throw error;
        }
    }
    async function calendarView({ roomId = null, date = null, selected = null, approvedOnly = false,
        view = 'week', target = null, isCurrent = () => true, filters = {}, roomBack = null }) {
        const body = roomId ? openDrawer('Room timetable', 'SPACES & SCHEDULING')
            : node('section', { class: 'booking-calendar', 'aria-label': 'Calendar schedule' });
        if (target) target.replaceChildren(body);
        const active = () => body.isConnected && isCurrent();
        const fields = [['date', 'Calendar date', 'date'], ...(roomId ? [] : [
            ['building_id', 'Building', 'lookup', 'buildings', false],
            ['search', 'Search bookings, rooms or sections', 'text', '', false],
            ['category_id', 'Category filter', 'lookup', 'bookingcategories', false],
            ['location', 'Meeting location', 'choice', [
                { value: 'all', label: 'All locations' }, { value: 'room', label: 'Rooms' }, { value: 'online', label: 'Online' },
            ]],
        ])];
        const form = await buildForm(fields, { location: 'all', ...filters, ...(date ? { date } : {}) }, { layout: roomId ? null : 'booking_calendar' });
        if (!active()) return;
        const input = form.elements.date;
        if (!roomId) form.classList.add('booking-calendar-filters');
        if (!roomId) form.elements.search.maxLength = 100;
        const readFilters = () => roomId ? {} : { building_id: form.elements.building_id.value,
            search: form.elements.search.value.trim(), category_id: form.elements.category_id.value,
            location: form.elements.location.value };
        const heading = node('h3', { class: 'h5 private-text' });
        const status = node('p', { role: 'status', 'aria-live': 'polite' });
        const content = node('div');
        const filter = node('input', { type: 'checkbox', class: 'form-check-input', id: `timetable-approved-${++timetableSerial}` });
        filter.checked = approvedOnly;
        const filterField = node('div', { class: 'form-check my-3' }, filter,
            node('label', { class: 'form-check-label', for: filter.id }, 'Show approved bookings only'));
        let result = null, serial = 0, page = 0;
        const periodButton = direction => node('button', { type: 'button', class: 'btn btn-outline-primary',
            onclick: () => task(() => result && load(result[direction])) });
        const previous = periodButton('previous');
        const next = periodButton('next');
        previous.disabled = true; next.disabled = true;
        const today = button('', () => { selected = null; return load(null); });
        const controls = node('div', { class: 'inline-actions mb-3' }, previous, today, next);
        const modes = node('div', { class: 'inline-actions mb-3', role: 'group', 'aria-label': 'Calendar view' });
        for (const mode of ['week', 'month']) {
            modes.append(button(mode === 'week' ? 'Week' : 'Month', () => {
                if (view === mode) return;
                let anchor = (result ? selected : null) || input.value || null;
                view = mode;
                labels();
                if (anchor) anchor = anchor < input.min ? input.min : anchor > input.max ? input.max : anchor;
                return load(anchor);
            }));
        }
        const submit = node('button', { type: 'submit', class: 'btn btn-primary' });
        function labels() {
            previous.textContent = `Previous ${view}`;
            next.textContent = `Next ${view}`;
            today.textContent = `This ${view}`;
            submit.textContent = `View ${view}`;
            input.min = view === 'month' ? '1000-01-01' : '1000-01-06';
            input.max = view === 'month' ? '9998-12-31' : '9998-12-27';
            for (const [n, control] of [...modes.children].entries()) {
                const chosen = ['week', 'month'][n] === view;
                control.setAttribute('aria-pressed', String(chosen));
                control.className = chosen ? 'btn btn-primary' : 'btn btn-outline-primary';
            }
        }
        labels();
        const dateLabel = date => new Date(`${date}T12:00:00Z`).toLocaleDateString(undefined,
            { weekday: 'long', month: 'short', day: 'numeric', timeZone: 'UTC' });
        // Keep the server's school-local clock and explicit offset, regardless of browser timezone.
        const clockLabel = instant => `${instant.slice(11, 16)} UTC${instant.slice(19)}`;
        function draw(focusDay = false) {
            if (!result || !active()) return;
            const day = result.days.find(item => item.date === selected) || result.days[0];
            selected = day.date;
            if (!roomId) lastCalendar = { date: result.from, view, selected, approvedOnly: filter.checked, filters: readFilters() };
            const rowsById = new Map(result.items.map(item => [item.id, item]));
            const items = day.items.map(slot => ({ ...rowsById.get(slot.id), ...slot }))
                .filter(item => !filter.checked || item.status === 'approved');
            page = Math.min(page, Math.max(0, Math.ceil(items.length / 20) - 1));
            const month = result.view === 'month';
            const days = node('div', { class: month ? 'booking-month mb-4' : roomId ? 'row g-2 mb-4' : 'booking-week mb-4',
                role: 'group', 'aria-label': 'Choose a timetable day' });
            if (month) {
                for (const label of ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'])
                    days.append(node('span', { class: 'booking-month-weekday text-center small fw-semibold', 'aria-hidden': 'true' }, label));
                const offset = (new Date(`${result.from}T12:00:00Z`).getUTCDay() + 6) % 7;
                for (let n = 0; n < offset; ++n)
                    days.append(node('span', { class: 'booking-month-spacer', 'aria-hidden': 'true' }));
            }
            for (const item of result.days) {
                const dayButton = button(dateLabel(item.date), () => { selected = item.date; page = 0; draw(true); },
                    item.date === selected ? 'btn-primary w-100 text-start' : 'btn-outline-primary w-100 text-start');
                dayButton.setAttribute('aria-pressed', String(item.date === selected));
                dayButton.dataset.date = item.date;
                if (item.date === result.today) dayButton.setAttribute('aria-current', 'date');
                if (month) {
                    dayButton.setAttribute('aria-label', `${dateLabel(item.date)}: ${item.counts.approved} approved, ${item.counts.requested} requested`);
                    dayButton.replaceChildren(
                        node('span', { class: 'booking-month-date' }, String(Number(item.date.slice(8)))),
                        node('span', { class: 'booking-month-label' }, dateLabel(item.date)),
                        node('span', { class: 'booking-month-count' }, `${item.counts.approved} approved`),
                        node('span', { class: 'booking-month-count' }, `${item.counts.requested} requested`));
                } else dayButton.append(node('span', { class: 'd-block small mt-1' },
                    `${item.counts.approved} approved · ${item.counts.requested} requested`));
                days.append(node('div', { class: month ? 'booking-month-day' : roomId ? 'col-12 col-sm-6' : 'booking-week-day' }, dayButton));
            }
            const dayHeading = node('h4', { class: 'h6', tabindex: '-1' }, dateLabel(day.date));
            const summary = node('p', { class: 'form-text' },
                `${result.from} through ${result.through} · ${result.timezone}. ${result.totals.approved} approved and ${result.totals.requested} requested occurrences this ${view}. Overnight meetings appear on each day they overlap.`);
            const listing = items.length ? grid(['Booking', 'Category', 'Course section', ...(!roomId ? ['Room', 'Building'] : []), 'Status', 'Starts', 'Ends'],
                items.slice(page * 20, (page + 1) * 20).map(item => [
                    button(item.title, () => booking(item.booking_id, roomId
                        ? { label: 'Back to room timetable', open: () => timetable(roomId, result.from, selected, filter.checked, view, roomBack) }
                        : { label: 'Back to calendar', open: () => ui.openCalendar() })),
                    categoryBadge(item),
                    item.section_code ? `${item.section_code} · ${item.section_term_code}` : '—',
                    ...(!roomId ? [item.room_id ? button([item.room_code, item.room_name].filter(Boolean).join(' · '), () => room(item.room_id))
                        : 'Online · No room', item.room_id
                        ? [item.building_code, item.building_name].filter(Boolean).join(' · ') || 'Building not assigned' : '—'] : []),
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
            status.setAttribute('role', 'status');
            status.textContent = 'Loading timetable…';
            try {
                const fresh = await request(roomId ? `/sis/rooms/${roomId}/timetable/` : '/sis/calendar/view/',
                    'POST', { ...readFilters(), view, ...(date ? { date } : {}) });
                if (!active() || current !== serial) return;
                result = fresh; page = 0;
                input.value = fresh.from;
                heading.textContent = roomId ? `${roomLabel(fresh.room)} · Capacity: ${fresh.room.capacity}`
                    : (fresh.building ? `${fresh.building.code} · ${fresh.building.name}` : 'All buildings');
                previous.disabled = fresh.previous < input.min;
                next.disabled = fresh.next > input.max;
                if (!fresh.days.some(day => day.date === selected))
                    selected = fresh.days.some(day => day.date === fresh.today) ? fresh.today : fresh.from;
                draw();
            } catch (error) {
                if (!active() || current !== serial) return;
                status.setAttribute('role', 'alert');
                status.textContent = roomId ? 'Timetable unavailable. Retry or use the paged room agenda.'
                    : (error.fields || []).map(item => item.message).join(' ') ||
                        (error.status === 403 ? 'Scheduling access is required to view this calendar.'
                            : 'Calendar unavailable. Retry or narrow your search.');
                if (roomId) throw error;
            } finally {
                if (active() && current === serial) content.removeAttribute('aria-busy');
            }
        }
        form.append(submit);
        form.addEventListener('submit', event => {
            event.preventDefault();
            if (form.reportValidity()) task(() => load(input.value), form.querySelector('[type="submit"]'));
        });
        const changed = () => {
            ++serial; result = null;
            previous.disabled = true; next.disabled = true;
            heading.textContent = ''; content.replaceChildren(); content.removeAttribute('aria-busy');
            status.setAttribute('role', 'status');
            status.textContent = roomId ? `Choose View ${view} to load these dates.`
                : `Choose View ${view} to load these dates and filters.`;
        };
        form.addEventListener('input', changed); form.addEventListener('change', changed);
        filter.addEventListener('change', () => { page = 0; draw(); });
        body.append(heading, node('p', { class: 'form-text' },
            'Times use the institution’s timezone. Requested bookings do not reserve the room. Approval checks availability again.'),
            roomId ? button('Back to room agenda', roomBack || (() => room(roomId))) :
                node('p', { class: 'form-text' }, 'Search meeting purposes, rooms, buildings, categories or retained section/term codes. A building filter excludes room-free online meetings. Up to 500 matching meetings are shown, with complete counts for the chosen filters.'),
            modes, controls, form, filterField, status, content);
        await load(date);
    }
    async function requestBooking(preset = {}) {
        const body = openDrawer("Plan a meeting", "SPACES & SCHEDULING");
        body.append(note());
        const fields = [
            onlineField,
            ["room_id", "Room", "lookup", "rooms"],
            ["title", "Meeting purpose"],
            ...classificationFields,
            ...dateFields,
        ];
        const form = await buildForm(fields, preset, { layout: 'meeting_request' });
        locationControls(form, preset.online === true);
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
            node(
                "div",
                { class: "inline-actions", role: "group", "aria-label": "Meeting request actions" },
                button("Plan recurring meetings", () => {
                    const draft = {
                        ...formData(form, classificationFields),
                        online: form.elements.online.checked,
                        room_id: form.elements.room_id.value,
                        title: form.elements.title.value,
                        starts_at: form.elements.starts_at.value,
                        ends_at: form.elements.ends_at.value,
                    };
                    return requestSeries(draft, () => requestBooking(draft));
                }),
                button("Check availability", async () => {
                    if (form.elements.online.checked) {
                        status.textContent =
                            "Online meetings need no room reservation. Approval confirms the schedule; linked classes must match their published offering.";
                        return;
                    }
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
                node("button", { type: "submit", class: "btn btn-primary" }, "Submit meeting request"),
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
                    category_id: data.category_id,
                    section_id: data.section_id,
                    slots: [
                        { starts_at: data.starts_at, ends_at: data.ends_at },
                    ],
                });
                notify('Meeting requested. Approval is required to confirm the schedule.');
                await ui.refreshDirectory();
                await booking(saved.id);
            }, form.querySelector('[type="submit"]'));
        });
        body.append(form, status);
        await sectionHint(form);
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
        const fields = [onlineField, ['room_id', 'Room', 'lookup', 'rooms'], ['title', 'Meeting purpose'], ...classificationFields,
            ['from', 'First date', 'date'], ['through', 'Last date', 'date'],
            ['start_time', 'Meeting starts', 'time'], ['end_time', 'Meeting ends', 'time'],
            ['overnight', 'Ends next day', 'checkbox', '', false], ['interval_weeks', 'Repeat interval in weeks', 'number'],
            ['excluded_dates', 'Dates to skip', 'textarea', '', false]];
        const form = await buildForm(fields, defaults, { layout: 'recurring_meeting' });
        if (!body.isConnected) return;
        locationControls(form, defaults.online === true);
        form.elements.interval_weeks.min = '1'; form.elements.interval_weeks.max = '12';
        form.elements.excluded_dates.maxLength = 1500;
        form.elements.excluded_dates.placeholder = '2026-11-26\n2026-12-24';
        const days = node('fieldset', { class: 'meeting-days record-form-wide', 'aria-describedby': 'meeting-days-error' },
            node('legend', { class: 'h6' }, 'Meeting days'));
        const dayOptions = node('div', { class: 'meeting-day-options' });
        days.append(dayOptions);
        const errors = node('p', { role: 'alert', class: 'text-danger', id: 'meeting-days-error' });
        const selected = new Date(`${defaults.from}T12:00:00Z`).getUTCDay() || 7;
        const checks = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'].map((label, i) => {
            const control = node('input', { type: 'checkbox', class: 'form-check-input', id: `meeting-day-${i + 1}`,
                value: i + 1, checked: selected === i + 1, 'aria-label': label });
            control.addEventListener('change', () => { errors.textContent = ''; });
            dayOptions.append(node('div', { class: 'form-check' }, control,
                node('label', { for: control.id, class: 'form-check-label', title: label }, label.slice(0, 3))));
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
                const payload = { room_id: data.room_id, title: data.title,
                    category_id: data.category_id, section_id: data.section_id, pattern: {
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
                        item.local_start, item.local_end,
                        badge(result.room === null ? 'No room required' : item.available ? 'Available at review' : 'Conflict'),
                    ]), 'Meetings to request'));
                    status.textContent = `Meetings ${page * 20 + 1}–${Math.min((page + 1) * 20, result.items.length)} of ${result.items.length}.`;
                    previous.disabled = page === 0; next.disabled = (page + 1) * 20 >= result.items.length;
                }
                preview.replaceChildren(node('h3', { class: 'h5 private-text' }, `${roomLabel(result.room)} · ${data.title}`),
                    categoryBadge(result.category), node('p', { class: 'form-text' }, sectionLabel(result.section)),
                    node('p', { class: 'form-text' }, result.room === null
                        ? `${result.items.length} online meetings in ${result.timezone}. Approval confirms the schedule without reserving a room.`
                        : `${roomLabel(result.room)} · ${result.items.length} meetings in ${result.timezone}. ${result.conflicts} conflicts with approved bookings. Approval will check again.`),
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
        await sectionHint(form);
    }
    async function booking(id, back = null, selectedPage = 0) {
        const pending = openDrawer("Booking details", "SPACE REQUEST");
        pending.append(node("p", { role: "status", class: "form-text" }, "Loading booking details…"));
        const record = await request(`/sis/bookings/${id}/`);
        if (!pending.isConnected) return;
        const place = record.room_id ? await request(`/sis/rooms/${record.room_id}/`) : null;
        if (!pending.isConnected) return;
        let page = Math.max(0, Math.min(selectedPage, Math.ceil(record.occurrences.length / 20) - 1));
        const returnToBooking = () => booking(id, back, page);
        const body = openDrawer(record.title, "SPACE REQUEST", {record:{key:`booking:${id}`,label:record.title,run:returnToBooking}});
        if (back) body.append(button(back.label, back.open));
        const actions = node("div", {
            class: "inline-actions",
            role: "group",
            "aria-label": "Booking tasks",
        });
        const extra = [];
        if (record.source_booking_id)
            extra.push(button("View previous booking", () => booking(record.source_booking_id, back)));
        if (can("sis:audit:read")) extra.push(button("View audit history", () => showAudit("bookings", id)));
        body.append(
            recordSummary([
                ["Status", badge(record.status)],
                ["Location", roomLabel(place)],
                ...(place
                    ? [
                          ["Building", buildingLabel(place)],
                          ["Capacity", place.capacity],
                      ]
                    : []),
                ["Category", categoryBadge(record.category)],
                ["Course section", sectionLabel(record.section)],
            ]),
            node(
                "p",
                { class: "form-text" },
                "Times use your browser’s local timezone; each meeting shows its UTC offset. Requested bookings do not reserve a room.",
            ),
        );
        if (place)
            actions.append(
                button(
                    "View room agenda",
                    () => room(record.room_id, record.occurrences[0]?.starts_at),
                    "btn-primary",
                ),
            );
        if (!record.section && can("sis:scheduling:manage") && can("sis:portal:manage"))
            actions.append(button("Manage public event", () => publicEvent(record, back)));
        if (can("sis:scheduling:manage"))
            extra.push(
                button("Change category", async () => {
                    const edit = openDrawer("Change booking category", "SPACES & SCHEDULING");
                    const fields = [classificationFields[0]];
                    const form = await buildForm(fields, { category_id: record.category_id || "" });
                    if (!edit.isConnected) return;
                    form.append(
                        button("Back to booking", returnToBooking),
                        node("button", { type: "submit", class: "btn btn-primary" }, "Save category"),
                    );
                    form.addEventListener("submit", (event) => {
                        event.preventDefault();
                        task(async () => {
                            await request(`/sis/bookings/${id}/category/`, "POST", {
                                ...formData(form, fields),
                                version: record.version,
                            });
                            if (!edit.isConnected) return;
                            notify("Booking category saved.");
                            await ui.refreshDirectory();
                            await returnToBooking();
                        }, form.querySelector('[type="submit"]'));
                    });
                    edit.append(
                        categoryBadge(record.category),
                        node(
                            "p",
                            { class: "form-text" },
                            "A category labels the booking; it does not change its linked course section or reservation.",
                        ),
                        form,
                    );
                }),
            );
        if (extra.length) actions.append(actionMenu("More", extra, { context: "More booking actions" }));
        if (actions.childElementCount) body.append(actions);
        const content = node('div'), status = node('p', { role: 'status', class: 'form-text', tabindex: '-1' });
        const previous = button('Previous booking meetings', () => { page--; draw(true); });
        const next = button('Next booking meetings', () => { page++; draw(true); });
        function draw(focus = false) {
            content.replaceChildren(node('section', { class: 'scheduling-meeting-list', 'aria-label': 'Booking meetings' },
                record.occurrences.slice(page * 20, (page + 1) * 20).map((occurrence, index) => meetingCard(
                    `Meeting ${page * 20 + index + 1}`, occurrence.starts_at, occurrence.ends_at,
                    occurrence.status, null,
                    occurrence.status === 'cancelled'
                        ? node('div', {},
                            node('p', { class: 'small mb-2' }, occurrence.cancelled_at
                                ? `Cancelled ${display(occurrence.cancelled_at)}` : 'Booking cancelled'),
                            occurrence.replacement_booking_id ? button('View replacement',
                                () => booking(occurrence.replacement_booking_id, back), 'btn-outline-secondary',
                                `View replacement for ${meetingTime(occurrence.starts_at)}`) : null)
                        : node('div', { class: 'booking-meeting-actions', role: 'group', 'aria-label': `Actions for meeting ${page * 20 + index + 1}` },
                            can('sis:scheduling:manage') ? button('Reschedule',
                                () => reschedule(record, occurrence, place, back, returnToBooking), 'btn-outline-primary',
                                `Reschedule meeting on ${meetingTime(occurrence.starts_at)}`) : null,
                            button('Cancel meeting', () => {
                            const panel = openDrawer('Cancel one meeting', 'SPACE REQUEST');
                            panel.append(node('p', { class: 'private-text' }, record.title),
                                node('p', {}, `${meetingTime(occurrence.starts_at)} through ${meetingTime(occurrence.ends_at)}`),
                                node('p', {}, 'This cancels only this meeting and releases its reservation. Other meetings keep their current approval. The original date stays in this booking’s history.'),
                                button('Back to booking', returnToBooking),
                                button('Confirm meeting cancellation', async () => {
                                    await request(`/sis/bookings/${id}/occurrences/${occurrence.id}/cancel/`, 'POST',
                                        { version: record.version });
                                    if (!panel.isConnected) return;
                                    notify('Meeting cancelled.');
                                    await ui.refreshDirectory();
                                    await returnToBooking();
                                }, 'btn-primary'));
                            panel.querySelector('button').focus();
                        }, 'btn-outline-danger', `Cancel meeting on ${meetingTime(occurrence.starts_at)}`)),
                    `Meeting ${page * 20 + index + 1}`,
                )), record.occurrences.length ? null : node('p', {}, 'No meetings in this booking.')));
            status.textContent = record.occurrences.length
                ? `Meetings ${page * 20 + 1}–${Math.min((page + 1) * 20, record.occurrences.length)} of ${record.occurrences.length}.`
                : 'No meetings in this booking.';
            const active = record.occurrences.filter(item => item.status !== 'cancelled').length;
            status.textContent += ` ${active} active ${active === 1 ? 'meeting' : 'meetings'}.`;
            previous.disabled = page === 0; next.disabled = (page + 1) * 20 >= record.occurrences.length;
            if (focus) status.focus();
        }
        const meetings = node('section', { class: 'record-section', 'aria-label': 'Booking schedule' },
            node('h3', {}, 'Meetings'),
            node('nav', { class: 'inline-actions mb-3', 'aria-label': 'Booking meeting pages' }, previous, next),
            status, content);
        body.append(meetings);
        draw();
        const decisions = node('div', { class: 'inline-actions my-3', role: 'group', 'aria-label': 'Booking decisions' });
        if (record.status === "requested")
            decisions.append(
                button(
                    place ? "Approve space request" : 'Approve online meetings',
                    () =>
                        flow(
                            place ? "Confirm space approval" : 'Confirm online meeting approval',
                            [],
                            `/sis/bookings/${id}/approve/`,
                            () => ({ version: record.version }),
                            async () => {
                                await ui.refreshDirectory();
                                await returnToBooking();
                            },
                            {},
                            place ? "Approval reserves the remaining active meetings. Overlapping approved bookings prevent approval."
                                : 'Approval confirms the remaining online meetings. It does not reserve a physical room or check student/faculty conflicts.',
                        ),
                    "btn-primary",
                ),
            );
        if (record.status !== "cancelled")
            decisions.append(
                button(place ? "Cancel space request" : 'Cancel online meetings', () =>
                    flow(
                        place ? "Confirm space cancellation" : 'Confirm online meeting cancellation',
                        [],
                        `/sis/bookings/${id}/cancel/`,
                        () => ({ version: record.version }),
                        async () => {
                            await ui.refreshDirectory();
                            await returnToBooking();
                        },
                        {},
                        "Cancellation applies to every occurrence, releases approved times and keeps the booking history.",
                    ), 'btn-outline-danger',
                ),
            );
        if (decisions.childElementCount) body.insertBefore(decisions, meetings);
    }
    async function publicEvent(record, back) {
        const body = openDrawer('Public campus event', 'SPACES & SCHEDULING');
        const base = `/sis/bookings/${record.id}/public-event/`;
        const setup = await request(`${base}setup/`, 'POST', {});
        if (!body.isConnected) return;
        const fields = [['title', 'Public event title'], ['description', 'Public event description', 'textarea']];
        const form = await buildForm(fields, setup.current || {});
        if (!body.isConnected) return;
        form.elements.description.maxLength = 4000;
        const heading = text => node('h2', { class: 'h4', tabindex: '-1' }, text);
        const preview = node('section', { hidden: true, 'aria-label': 'Public event review' });
        const edit = node('section', {}, heading('Invite your campus community'),
            node('p', { class: 'form-text' },
                'Only these public words and reviewed dates and locations appear in the portal. The internal booking purpose stays private. Cancelled meetings disappear; replacement bookings need their own publication.'),
            node('p', { role: 'status' }, setup.current?.published ? 'Public event content is published.' : 'This event is not published.'), form);
        const close = () => booking(record.id, back);
        form.append(button('Back to booking', close));
        if (!setup.preview) edit.append(node('p', { class: 'alert alert-info' },
            'Publication requires an approved standalone booking with active meetings and no course-section link. Existing public content can still be withdrawn.'));
        const submit = node('button', { type: 'submit', class: 'btn btn-primary', disabled: !setup.preview }, 'Review public event');
        form.append(submit);
        let generation = 0;
        form.addEventListener('input', () => { generation++; });
        const editAgain = () => { preview.hidden = true; edit.hidden = false; submit.focus(); };
        if (setup.current?.published && can('sis:communications:manage')) {
            edit.append(node('p', { class: 'form-text' },
                'Prepare a message from the published invitation. Review and publish any changes here first.'),
            button('Prepare announcement', async () => {
                const serial = ++generation;
                const draft = await request(`${base}announcement/`, 'POST', {});
                if (!body.isConnected || serial !== generation) return;
                await ui.composeAnnouncement(draft, () => publicEvent(record, back));
            }));
        }
        if (setup.current?.published) edit.append(button('Withdraw public event', () => {
            preview.replaceChildren(heading('Remove this event from the public calendar?'),
                node('p', {}, 'The room reservation and booking history stay in place. This only withdraws the public event content.'),
                button('Back to event details', editAgain), button('Confirm withdrawal', async () => {
                    await request(`${base}withdraw/`, 'POST', { version: setup.current.version });
                    if (!body.isConnected) return;
                    notify('Public event withdrawn.'); await publicEvent(record, back);
                }, 'btn-primary'));
            edit.hidden = true; preview.hidden = false; preview.querySelector('h2').focus();
        }));
        form.addEventListener('submit', event => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const serial = ++generation;
                const review = await request(`${base}review/`, 'POST', formData(form, fields));
                if (!body.isConnected || serial !== generation) return;
                const content = node('div'), paging = node('nav', { class: 'inline-actions mt-3', 'aria-label': 'Public event review pages' });
                let page = 0;
                const draw = () => {
                    content.replaceChildren(grid(['Starts', 'Ends'], review.preview.times.slice(page * 20, (page + 1) * 20)
                        .map(time => [new Date(time.starts_at).toLocaleString(undefined, { timeZone: review.preview.timezone, timeZoneName: 'shortOffset' }),
                            new Date(time.ends_at).toLocaleString(undefined, { timeZone: review.preview.timezone, timeZoneName: 'shortOffset' })]), 'Public event dates'));
                    paging.replaceChildren();
                    if (page) paging.append(button('Previous event dates', () => { page--; draw(); }));
                    const status = node('p', { role: 'status', tabindex: '-1' },
                        `Dates ${page * 20 + 1}–${Math.min((page + 1) * 20, review.preview.times.length)} of ${review.preview.times.length}`);
                    paging.append(status);
                    if ((page + 1) * 20 < review.preview.times.length)
                        paging.append(button('Next event dates', () => { page++; draw(); }));
                    if (paging.isConnected) status.focus();
                };
                preview.replaceChildren(heading('Review the public invitation'), node('h3', { class: 'h5' }, review.title),
                    node('p', { class: 'campus-event-description' }, review.description),
                    node('p', {}, `${review.preview.room_label} · ${review.preview.building_label}`),
                    node('p', { class: 'form-text' }, `Public dates use ${review.preview.timezone}. Review expires in 20 minutes. Anyone can read published content.`),
                    content, paging, button('Back to event details', editAgain), button('Publish public event', async () => {
                        await request(`${base}publish/`, 'POST', { review_token: review.review_token });
                        if (!body.isConnected) return;
                        notify('Public event published.'); await publicEvent(record, back);
                    }, 'btn-primary'));
                draw(); edit.hidden = true; preview.hidden = false; preview.querySelector('h2').focus();
            }, submit);
        });
        body.append(edit, preview, node('a', { href: '/portal/events/', class: 'd-inline-block mt-3' }, 'Open the public campus calendar'));
    }
    async function reschedule(record, occurrence, place, back, returnToBooking = () => booking(record.id, back)) {
        const body = openDrawer('Reschedule one meeting', 'SPACES & SCHEDULING');
        const fields = [...(!record.section || ['online', 'hybrid'].includes(record.section.delivery_mode) ? [onlineField] : []),
            ['room_id', 'New room', 'lookup', 'rooms'], ...dateFields];
        const form = await buildForm(fields, { room_id: record.room_id,
            starts_at: local(new Date(occurrence.starts_at)), ends_at: local(new Date(occurrence.ends_at)) });
        if (!body.isConnected) return;
        locationControls(form, !record.room_id);
        const selectedRoom = form.elements.room_id.selectedOptions[0];
        if (selectedRoom && place) selectedRoom.textContent = `${roomLabel(place)} · ${buildingLabel(place)}`;
        const status = node('p', { role: 'status', class: 'form-text' });
        const preview = node('div', { hidden: true });
        const reviewButton = node('button', { type: 'submit', class: 'btn btn-primary' }, 'Review new meeting');
        let epoch = 0;
        for (const event of ['input', 'change']) form.addEventListener(event, () => { ++epoch; status.textContent = ''; });
        form.append(reviewButton, button('Back to booking', returnToBooking));
        body.append(node('p', { class: 'private-text' }, record.title),
            node('p', {}, `Current meeting: ${roomLabel(place)} · ${meetingTime(occurrence.starts_at)} through ${meetingTime(occurrence.ends_at)}`),
            node('p', { class: 'form-text' },
                'The current meeting stays in place until confirmation. Choose a room or an online meeting. Linked classes must keep a compatible offering and instructional dates. Times use your browser’s timezone; meetings may last up to 31 days.'),
            form, status, preview);
        form.addEventListener('submit', event => {
            event.preventDefault();
            task(async () => {
                if (!validWindow(form) || !form.reportValidity()) return;
                const current = ++epoch;
                const payload = { ...formData(form, fields), version: record.version };
                status.textContent = 'Checking the new meeting…';
                const review = await request(`/sis/bookings/${record.id}/occurrences/${occurrence.id}/reschedule/review/`,
                    'POST', payload);
                if (!body.isConnected || current !== epoch) return;
                status.textContent = '';
                const confirm = button('Confirm reschedule', async () => {
                    const saved = await request(`/sis/bookings/${record.id}/occurrences/${occurrence.id}/reschedule/confirm/`,
                        'POST', { ...payload, id: review.new_id, revision: review.revision });
                    if (!body.isConnected) return;
                    notify('Meeting rescheduled. Other meetings keep their current status.');
                    await ui.refreshDirectory();
                    await booking(saved.id, back);
                }, 'btn-primary');
                confirm.disabled = !review.available;
                preview.replaceChildren(node('h3', { class: 'h5' }, 'Review the move'),
                    grid(['Meeting', 'Room', 'Starts', 'Ends'], [
                        ['Current', roomLabel(review.source_room), meetingTime(review.source_starts_at), meetingTime(review.source_ends_at)],
                        ['Replacement', roomLabel(review.room), meetingTime(review.starts_at), meetingTime(review.ends_at)],
                    ], 'Meeting change review'),
                    node('p', { class: review.available ? 'form-text' : 'alert alert-warning', role: 'status' },
                        review.available ? (review.room === null
                            ? `The replacement will be online and ${review.status}. No room will be reserved; the original meeting stays in history.`
                            : `No approved conflict at review. The replacement will be ${review.status}. Confirmation checks again and preserves the original meeting history.`)
                            : 'An approved booking overlaps the new meeting. Choose another room or time. The current reservation is unchanged.'),
                    button('Back to new meeting', () => { preview.hidden = true; form.hidden = false; reviewButton.focus(); }),
                    button('Back to booking', returnToBooking), confirm);
                form.hidden = true; preview.hidden = false;
                preview.querySelector('button').focus();
            }, reviewButton);
        });
    }
    async function building(id) {
        const pending = openDrawer('Building details', 'BUILDING');
        pending.append(node('p', { role: 'status', class: 'form-text' }, 'Loading building details…'));
        const record = await request(`/sis/buildings/${id}/`);
        if (!pending.isConnected) return;
        const body = openDrawer(`${record.code} · ${record.name}`, 'BUILDING', {record:{key:`building:${id}`,label:record.code,run:()=>building(id)}});
        body.append(recordSummary([['Building code', record.code], ['Building name', record.name]]),
            node('p', {class:'form-text'}, 'Rooms reference this building by its stable identity. Renaming it preserves room and booking links.'),
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
    async function roomForm(record = null, preset = {}, back = null) {
        const fields = [['code', 'Room code'], ['name', 'Room name'],
            ['building_id', 'Building', 'lookup', 'buildings'], ['capacity', 'Capacity', 'number']];
        const body = openDrawer(record ? 'Edit room details' : 'Add a room', 'SPACES & SCHEDULING');
        const initial = Object.fromEntries(Object.entries({ ...record, ...preset }).filter(([, value]) => value !== null));
        const form = await buildForm(fields, initial, { layout: 'rooms' });
        if (!body.isConnected) return;
        form.elements.code.maxLength = 80;
        body.append(node('p', { class: 'form-text' },
            'Use a short unique code and a descriptive name, for example LIB.101 and Quiet study room. Choose its building; booking links stay connected when room details change.'));
        form.append(button('Add a building', () => {
            const draft = formData(form, fields);
            return buildingForm(null, id => roomForm(record, { ...draft, building_id: id }, back), () => roomForm(record, draft, back));
        }));
        if (record) form.append(button('Back to room agenda', back || (() => room(record.id))));
        form.append(node('button', { type: 'submit', class: 'btn btn-primary' }, record ? 'Save room details' : 'Save room'));
        form.addEventListener('submit', event => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const result = await request(record ? `/sis/rooms/${record.id}/edit/` : '/sis/rooms/', 'POST',
                    { ...formData(form, fields), ...(record ? { revision: record.revision } : {}) });
                if (!body.isConnected) return;
                notify('Room saved.'); await ui.refreshDirectory();
                if (back) await back();
                else await room(result.id || record.id);
            }, form.querySelector('[type="submit"]'));
        });
        body.append(form);
    }
    async function categoryForm(row = null) {
        const body = openDrawer(row ? 'Edit booking category' : 'Add a category', 'SPACES & SCHEDULING');
        const record = row ? await request(`/sis/booking-categories/${row.id}/`) : null;
        if (!body.isConnected) return;
        const fields = [['code', 'Category code'], ['name', 'Category name'],
            ['tone', 'Color', 'choice', ['blue', 'teal', 'amber', 'purple', 'gray']],
            ...(record ? [['active', 'Available for new bookings', 'checkbox', '', false]] : [])];
        const form = await buildForm(fields, record || { tone: 'blue' });
        if (!body.isConnected) return;
        form.elements.code.maxLength = 80;
        const sample = node('p', { class: 'my-3', 'aria-label': 'Category preview' });
        const draw = () => sample.replaceChildren(categoryBadge({ name: form.elements.name.value || 'Category preview', tone: form.elements.tone.value }));
        form.addEventListener('input', draw); draw();
        form.append(node('button', { type: 'submit', class: 'btn btn-primary' }, 'Save category'));
        form.addEventListener('submit', event => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const result = await request(record ? `/sis/booking-categories/${record.id}/edit/` : '/sis/booking-categories/',
                    'POST', { ...formData(form, fields), ...(record ? { version: record.version } : {}) });
                if (!body.isConnected) return;
                notify('Category saved.'); await ui.refreshDirectory();
                await categoryForm({ id: result.id || record.id });
            }, form.querySelector('[type="submit"]'));
        });
        body.append(node('p', { class: 'form-text' },
            'Use labels such as Course sections, Meetings or Conferences. Retiring a category keeps existing links and calendar filters; restoring it makes it available again. Labels accompany every color.'), sample, form);
    }
    function meetingPlanTable(definition, heading) {
        const region = node('section', { class: 'mt-4', 'aria-label': heading });
        const content = node('div');
        let page = 0;
        const meetings = definition.meetings;
        const locations = new Map(definition.locations.map(location => [location.room_id, location]));
        const time = instant => new Date(instant).toLocaleString(undefined, {
            timeZone: definition.timezone, timeZoneName: 'shortOffset',
        });
        const draw = () => {
            const start = page * 20;
            content.replaceChildren();
            if (!meetings.length) {
                content.append(node('p', { class: 'alert alert-info' },
                    'No scheduled meetings. This plan contains no room or online meeting times.'));
                return;
            }
            content.append(grid(['Starts', 'Ends', 'Location', 'Building'],
                meetings.slice(start, start + 20).map(meeting => {
                    const location = locations.get(meeting.room_id);
                    return [time(meeting.starts_at), time(meeting.ends_at),
                        location ? `${location.code} · ${location.name}` : 'Online · No room',
                        location?.building_id ? `${location.building_code} · ${location.building_name}`
                            : location ? 'Building not assigned' : 'No physical building'];
                }), `${heading} · ${definition.timezone}`));
            const nav = node('nav', { class: 'inline-actions mt-3', 'aria-label': `${heading} pages` });
            if (page > 0) nav.append(button('Previous meetings', () => { page--; draw(); }));
            const status = node('p', { class: 'small mb-0', role: 'status', tabindex: '-1' },
                `Meetings ${start + 1}–${Math.min(start + 20, meetings.length)} of ${meetings.length}`);
            nav.append(status);
            if (start + 20 < meetings.length) nav.append(button('Next meetings', () => { page++; draw(); }));
            content.append(nav);
            if (region.isConnected) status.focus();
        };
        region.append(node('h3', { class: 'h5' }, heading), node('p', { class: 'form-text' },
            `${meetings.length} meetings · ${definition.timezone}. Room and building names are retained at publication.`), content);
        draw();
        return region;
    }
    function facultyAssessment(value) {
        const region = node('section', { class: 'mt-3', 'aria-label': 'Faculty schedule review' });
        region.append(node('h3', { class: 'h5' }, 'Faculty schedule'));
        if (!value) {
            region.append(node('p', { class: 'form-text' }, 'Faculty conflict review was not recorded for this publication.'));
            return region;
        }
        const message = value.pairs ? `${value.pairs} faculty overlap${value.pairs === 1 ? '' : 's'}. ${value.blocked ? 'Resolve the schedule before publishing.' : 'Institution policy allows publication after reviewing these warnings.'}`
            : value.status === 'not_needed' ? 'No scheduled meetings need a faculty review.'
            : value.status === 'unassigned' ? 'No faculty assigned to these meetings; overlaps could not be checked.'
            : `No overlaps found across ${value.checked_faculty} assigned faculty and ${value.compared_sections} other published sections.`;
        region.append(node('p', { class: `alert ${value.pairs || value.unassigned_meetings ? 'alert-warning' : 'alert-info'}`, role: 'status' }, message));
        if (value.unassigned_meetings && value.status !== 'unassigned') region.append(node('p', {},
            `${value.unassigned_meetings} meetings have no assigned faculty and were not checked.`));
        if (value.conflicts.length) {
            region.append(node('p', { class: 'small' }, `Showing ${value.conflicts.length} of ${value.pairs} overlaps. Times below are UTC.`),
                grid(['Other section', 'Term', 'Overlap starts · UTC', 'Overlap ends · UTC'], value.conflicts.map(item => [
                    item.section_code, item.term_code, item.starts_at.replace('T', ' ').replace('Z', ''), item.ends_at.replace('T', ' ').replace('Z', ''),
                ]), 'Faculty overlaps with published classes'));
        }
        return region;
    }
    async function meetingPlan(sectionId, resume = null) {
        if (!can('sis:scheduling:manage') || !can('sis:catalog:manage')) return;
        const body = openDrawer('Section meeting plan', 'ACADEMIC SCHEDULE');
        body.append(node('p', { role: 'status' }, 'Loading the section meeting plan…'));
        const base = `/sis/sections/${sectionId}/meeting-plan/`;
        let reason = resume?.reason || '', generation = 0;
        const historyCheck = (cursor = null, showHistory = false) => {
            if (!ui.historyChecks || !['sis:scheduling:manage', 'sis:catalog:manage', 'sis:history:verify', 'sis:audit:read']
                .every(can)) return;
            body.append(ui.historyChecks().publicationAction('meetingplan', sectionId,
                () => meetingPlan(sectionId, { reason, showHistory, historyCursor: cursor }), () => {
                    reason = body.querySelector('textarea[name="reason"]')?.value ?? reason;
                    generation++;
                }));
        };
        let snapshot;
        try { snapshot = await request(`${base}setup/`, 'POST', {}); }
        catch (error) {
            if (!body.isConnected) return;
            body.replaceChildren(node('p', { class: 'alert alert-warning', role: 'alert' },
                ui.historyCheckLabels.meeting_plan_unavailable));
            if (![401, 403].includes(error.status)) historyCheck();
            if (resume) return;
            throw error;
        }
        if (!body.isConnected) return;
        const focusTitle = () => body.querySelector('h2')?.focus();
        const title = text => node('h2', { class: 'h4', tabindex: '-1' }, text);
        const explain = () => node('p', { class: 'form-text' },
            'Publishing retains a historical academic schedule. It does not approve requests, move reservations or change student registration. Later booking changes need a new reviewed publication.');
        const guide = (data, reviewed = false) => window.createReviewGuidance({ node },
            window.capturedReviewGuidance.meetingPlan(data, reviewed), {
                faculty: { label: 'Show faculty review', find: () => {
                    const heading = body.querySelector('[aria-label="Faculty schedule review"] h3');
                    if (heading) heading.tabIndex = -1;
                    return heading;
                } },
                meetings: { label: 'Show meeting times', find: () => {
                    const region = body.querySelector('[aria-label="Approved working meetings"], [aria-label="Meetings to publish"]');
                    if (region) region.tabIndex = -1;
                    return region;
                } },
                pending: { label: 'Go to pending requests', find: () =>
                    [...body.querySelectorAll('button')].find(item => item.textContent === 'Review request 1') || null },
                published: { label: 'Go to published plan', find: () =>
                    [...body.querySelectorAll('button')].find(item => item.textContent === 'View published plan') || null },
            });
        const home = () => {
            generation++;
            body.replaceChildren(title(`${snapshot.working.section_code} · ${snapshot.working.term_code}`), explain());
            const status = node('p', { class: 'alert alert-info', role: 'status' },
                !snapshot.published ? 'No meeting plan has been published.' : snapshot.changed
                    ? 'The working schedule differs from the published plan. Review and publish the approved changes.'
                    : `Published plan ${snapshot.published.version} matches the approved working schedule.`);
            body.append(status);
            if (snapshot.published) body.append(button('View published plan', () => retained(snapshot.published)));
            if (snapshot.published) body.append(button('Publication history', () => history()));
            historyCheck();
            if (snapshot.pending_booking_ids.length) {
                body.append(node('h3', { class: 'h5 mt-4' }, 'Meeting requests need a decision'),
                    node('p', {}, 'Approve or cancel these pending requests before reviewing the plan. Return here after making the decisions.'));
                const pending = node('div');
                let page = 0;
                const draw = () => {
                    const start = page * 20;
                    pending.replaceChildren();
                    snapshot.pending_booking_ids.slice(start, start + 20).forEach((id, index) =>
                        pending.append(button(`Review request ${start + index + 1}`, () => booking(id,
                            { label: 'Back to meeting plan', open: () => meetingPlan(sectionId) }))));
                    const nav = node('nav', { class: 'inline-actions mt-3', 'aria-label': 'Pending request pages' });
                    if (page) nav.append(button('Previous requests', () => { page--; draw(); }));
                    nav.append(node('p', { class: 'small mb-0', role: 'status' },
                        `Requests ${start + 1}–${Math.min(start + 20, snapshot.pending_booking_ids.length)} of ${snapshot.pending_booking_ids.length}`));
                    if (start + 20 < snapshot.pending_booking_ids.length)
                        nav.append(button('Next requests', () => { page++; draw(); }));
                    pending.append(nav);
                };
                draw();
                body.append(pending);
                body.append(button('Reload meeting plan', () => meetingPlan(sectionId)));
            }
            body.append(guide(snapshot), facultyAssessment(snapshot.faculty_assessment), meetingPlanTable(snapshot.working, 'Approved working meetings'));
            if (snapshot.faculty_assessment?.blocked && !snapshot.pending_booking_ids.length)
                body.append(button('Reload meeting plan', () => meetingPlan(sectionId)));
            if (snapshot.changed && !snapshot.pending_booking_ids.length && !snapshot.faculty_assessment?.blocked) {
                const form = node('form', { class: 'mt-4' });
                const input = node('textarea', { id: 'meeting-plan-reason', name: 'reason', required: true,
                    maxlength: 1000, rows: 3, class: 'form-control' });
                input.value = reason;
                const submit = node('button', { type: 'submit', class: 'btn btn-primary mt-3' }, 'Review meeting plan');
                form.append(node('label', { for: input.id, class: 'form-label' }, 'Reason for publication'), input, submit);
                input.addEventListener('input', () => { reason = input.value; generation++; });
                form.addEventListener('submit', event => {
                    event.preventDefault();
                    task(async () => {
                        if (!form.reportValidity()) return;
                        reason = input.value;
                        const serial = ++generation;
                        const review = await request(`${base}review/`, 'POST', { reason });
                        if (!form.isConnected || serial !== generation || input.value !== reason) return;
                        confirm(review);
                    }, submit);
                });
                body.append(form);
            }
            focusTitle();
        };
        const retained = (publication, back = home) => {
            generation++;
            body.replaceChildren(title(`Published meeting plan ${publication.version}`),
                node('p', {}, `Published ${display(publication.recorded_at)}`),
                node('p', {}, publication.reason || 'Publication reason not available.'),
                facultyAssessment(publication.faculty_assessment), meetingPlanTable(publication.definition, 'Retained meetings'), button('Back', back));
            focusTitle();
        };
        const history = async (cursor = null) => {
            const serial = ++generation;
            const result = await request(`${base}versions/`, 'POST', { cursor });
            if (!body.isConnected || serial !== generation) return;
            body.replaceChildren(title('Meeting plan publications'), node('p', { class: 'form-text' },
                `History through publication ${result.through_version}. Earlier plans retain their original locations and meeting times.`));
            historyCheck(cursor, true);
            result.items.forEach(item => body.append(node('article', { class: 'border rounded p-3 mb-3' },
                node('h3', { class: 'h5' }, `Plan ${item.version}`),
                node('p', {}, `${display(item.recorded_at)} · ${item.definition.meetings.length} meetings`),
                button('View retained plan', () => retained(item, () => history(cursor))))));
            if (result.next_cursor) body.append(button('More publications', () => history(result.next_cursor)));
            body.append(button('Back to working schedule', home));
            focusTitle();
        };
        const confirm = review => {
            generation++;
            body.replaceChildren(title('Review before publishing'), explain(), node('p', {}, review.reason),
                node('p', { class: 'alert alert-info' }, review.previous
                    ? `This will replace plan ${review.previous.version} with ${review.previous.definition.meetings.length} meetings. Its history will be retained.`
                    : 'This will publish the first meeting plan for this section.'),
                guide(review, true), facultyAssessment(review.faculty_assessment), meetingPlanTable(review.definition, 'Meetings to publish'));
            const status = node('p', { class: 'form-text', role: 'status' },
                `Review expires ${display(new Date(review.expires_at * 1000))}.`);
            const acknowledgement = review.faculty_assessment?.pairs ? node('input', {
                type: 'checkbox', class: 'form-check-input', id: 'faculty-overlap-ack',
            }) : null;
            if (acknowledgement) body.append(node('div', { class: 'form-check mb-3' }, acknowledgement,
                node('label', { for: acknowledgement.id, class: 'form-check-label' }, 'I reviewed the faculty overlaps.')));
            const confirmButton = button(review.definition.meetings.length ? 'Publish reviewed meeting plan' : 'Publish plan with no scheduled meetings', async () => {
                if (acknowledgement && !acknowledgement.checked) {
                    acknowledgement.focus();
                    return;
                }
                if (!body.isConnected || Date.now() >= review.expires_at * 1000) {
                    status.textContent = 'This review has expired. Go back and review the meeting plan again.';
                    return;
                }
                const serial = generation;
                await request(`${base}publish/`, 'POST', { review_token: review.review_token });
                if (!body.isConnected || serial !== generation) return;
                notify('Meeting plan published. Earlier publications are retained.');
                await meetingPlan(sectionId);
            }, 'btn-primary');
            body.append(status, node('div', { class: 'inline-actions' },
                button('Back to working schedule', home), confirmButton));
            focusTitle();
        };
        if (resume?.showHistory) await history(resume.historyCursor);
        else home();
    }
    return { room, booking, requestBooking, requestSeries, building, buildingForm, roomForm, calendar, categoryForm, categoryBadge, meetingPlan };
};
