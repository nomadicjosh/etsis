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
        const body = openDrawer(record.code, "ROOM & AGENDA");
        if (can("sis:audit:read"))
            body.append(
                button("View audit history", () => showAudit("rooms", id)),
            );
        body.append(node("p", {}, `Capacity: ${record.capacity}`), note());
        body.append(
            button("Edit room details", async () => {
                const form = await flow(
                    "Save room details",
                    [
                        ["code", "Room name or code"],
                        ["capacity", "Capacity", "number"],
                    ],
                    `/sis/rooms/${id}/edit/`,
                    (data) => ({ ...data, revision: record.revision }),
                    () => room(id),
                    record,
                    "Existing booking identities and times are preserved. A stale edit requires reloading.",
                );
                form.elements.code.maxLength = 80;
            }),
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
    async function booking(id) {
        const record = await request(`/sis/bookings/${id}/`);
        const place = await request(`/sis/rooms/${record.room_id}/`);
        const body = openDrawer(record.title, "SPACE REQUEST");
        if (can("sis:audit:read"))
            body.append(
                button("View audit history", () => showAudit("bookings", id)),
            );
        body.append(
            badge(record.status),
            node("p", {}, `Room: ${place.code} · Capacity: ${place.capacity}`),
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
                                await booking(id);
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
                            await booking(id);
                        },
                        {},
                        "Cancellation releases approved times and keeps the booking history.",
                    ),
                ),
            );
    }
    return { room, booking, requestBooking };
};
