/* Connected employment and time review. Private data stays in memory. */
window.createWorkspaceEmployment = function (ui) {
    "use strict";
    const {
        node,
        request,
        task,
        can,
        openDrawer,
        openPerson,
        buildForm,
        formData,
        notify,
        table,
        badge,
        flow,
        showAudit,
        historyPanel,
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
    const display = (instant) => new Date(instant).toLocaleString();
    const duration = (seconds) =>
        `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m${seconds % 60 ? ` ${seconds % 60}s` : ""}`;
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
    const name = (person) => `${person.given_name} ${person.family_name}`;

    async function employee(id) {
        const record = await request(`/sis/employees/${id}/`);
        const body = openDrawer(name(record), "EMPLOYMENT & TIME");
        if (can("sis:audit:read"))
            body.append(
                button("View audit history", () => showAudit("employees", id)),
            );
        if (can("sis:hr:manage") && can("sis:audit:read"))
            body.append(button("Employment change history", () => history(record)));
        body.append(
            node("p", {}, `${record.institutional_id} · ${record.job_title}`),
            badge(record.active ? "Active" : "Inactive"),
        );
        body.append(
            node(
                "p",
                { class: "form-text" },
                record.earlier_history_unknown
                    ? "Earlier employment history is unknown. Changes made here retain the previous title and status."
                    : "Employment profile changes are retained in protected history.",
            ),
        );
        if (can("sis:person:manage"))
            body.append(
                button("Open connected person", () =>
                    openPerson(record.person_id),
                ),
            );
        body.append(
            button("Weekly time review", () => week(id)),
            button("Edit employment", () =>
                flow(
                    "Save employment",
                    [
                        ["job_title", "Job title"],
                        ["active", "Active employment", "checkbox"],
                        ["reason", "Reason for employment change", "textarea"],
                    ],
                    `/sis/employees/${id}/edit/`,
                    (data) => ({ ...data, revision: record.revision }),
                    () => employee(id),
                    record,
                    "Inactive employment blocks new time submissions. Historical entries and pending reviews remain available. This does not disable sign-in or change student status.",
                ),
            ),
        );
        if (record.active)
            body.append(
                button("Submit time for this employee", () =>
                    submit({ employee_id: id }),
                ),
            );
        else
            body.append(
                node(
                    "p",
                    { class: "form-text" },
                    "Reactivate employment before submitting new time.",
                ),
            );
        body.append(
            node("h3", { class: "mt-4" }, "Recent time entries"),
            node(
                "p",
                { class: "form-text" },
                "Entries are listed by most recent submission. Times use your browser’s local timezone. Durations are elapsed time, not payroll calculations.",
            ),
        );
        const information = node("p", {
            role: "status",
            "aria-live": "polite",
            class: "form-text",
        });
        const content = node("div");
        let after = "";
        let nextAfter = null;
        let serial = 0;
        const previousCursors = [];
        const previous = button("Previous time page", async () => {
            after = previousCursors.pop() || "";
            await load();
        });
        const next = button("Next time page", async () => {
            previousCursors.push(after);
            after = nextAfter;
            await load();
        });
        body.append(
            information,
            content,
            node("div", { class: "inline-actions" }, previous, next),
        );
        async function load() {
            const token = ++serial;
            const page = await request(
                `/sis/employees/${id}/time-entries/`,
                "POST",
                { after },
            );
            if (!body.isConnected || token !== serial) return;
            nextAfter = page.next_after;
            previous.disabled = !previousCursors.length;
            next.disabled = !nextAfter;
            information.textContent = `${page.items.length} entries on this page.${nextAfter ? " More are available." : ""}`;
            content.replaceChildren(
                page.items.length
                    ? grid(
                          [
                              "Started",
                              "Finished",
                              "Duration",
                              "Status",
                              "Action",
                          ],
                          page.items.map((item) => [
                              display(item.starts_at),
                              display(item.ends_at),
                              duration(item.duration_seconds),
                              badge(item.status),
                              button("Review time entry", () => entry(item.id)),
                          ]),
                          "Employee time entries",
                      )
                    : node("p", {}, "No time entries have been submitted."),
            );
        }
        await load();
    }
    async function history(record) {
        const id = record.id;
        const body = openDrawer("Employment change history", `${name(record)} · ${record.institutional_id}`);
        body.append(button("Back to employment", () => employee(id)));
        await historyPanel({
            body, endpoint: `/sis/employees/${id}/history/`, label: "Recorded employment changes",
            description: "Review recorded titles and active status, newest first. Private reasons, credentials and raw events are excluded. Recorder names are current account names. These changes do not establish hire or termination dates.",
            unknownText: "Earlier employment history is unknown. Observed details describe the record when tracking began, not when employment started.",
            emptyText: "No employment changes have been recorded yet. The current title and status remain in the employment record; earlier changes are unknown.",
            render: item => node("div", {}, node("p", { class: "private-text" }, item.job_title),
                badge(item.active ? "Active" : "Inactive"),
                node("p", { class: "form-text" }, item.earlier_details_unknown
                    ? "Existing details were observed here. Earlier changes and their dates are unknown."
                    : `Fields: ${item.fields.join(", ")}`)),
        });
    }
    async function week(id, selected = "") {
        const body = openDrawer("Weekly time review", "EMPLOYMENT & TIME");
        body.append(button("Back to employment", () => employee(id)));
        const form = node("form", { class: "record-form mt-3" });
        const date = node("input", {
            id: "time-review-date", name: "week", type: "date", required: true,
            min: "1000-01-08", max: "9998-12-24", class: "form-control",
        });
        const submit = node("button", { type: "submit", class: "btn btn-primary" }, "Review week");
        form.append(
            node("label", { for: date.id, class: "form-label" }, "Any date in the week"),
            date,
            node("div", { class: "inline-actions mt-3" }, submit),
        );
        const information = node("p", { role: "status", "aria-live": "polite", class: "mt-3" });
        const content = node("div");
        let reviewed = null;
        let serial = 0;
        const previous = button("Previous week", () => load(reviewed.previous));
        const next = button("Next week", () => load(reviewed.next));
        const refresh = button("Refresh week", () => load(reviewed.week));
        previous.disabled = next.disabled = refresh.disabled = true;
        body.append(form, node("div", { class: "inline-actions" }, previous, next, refresh), information, content);
        form.addEventListener("submit", event => {
            event.preventDefault();
            if (form.reportValidity()) task(() => load(date.value), submit);
        });
        async function load(value) {
            const token = ++serial;
            const data = await request(`/sis/employees/${id}/time-week/`, "POST", value ? { week: value } : {});
            if (!body.isConnected || token !== serial) return;
            reviewed = data;
            date.value = data.week;
            previous.disabled = data.previous < date.min;
            next.disabled = data.next > date.max;
            refresh.disabled = false;
            information.textContent = `${name(data.employee)} · ${data.employee.institutional_id}. Showing ${data.week} through ${data.through}. Times use ${data.timezone}.`;
            const cards = node("div", { class: "row g-3 my-2" });
            for (const status of ["pending", "approved", "rejected"]) {
                const total = data.totals[status];
                cards.append(node("div", { class: "col-12 col-sm-4" },
                    node("div", { class: "card h-100" }, node("div", { class: "card-body" },
                        node("h3", { class: "h6 text-capitalize" }, status),
                        node("p", { class: "h4" }, duration(total.seconds)),
                        node("p", { class: "mb-0" }, `${total.entries} ${total.entries === 1 ? "entry" : "entries"}`),
                    )),
                ));
            }
            const inZone = instant => new Date(instant).toLocaleString(undefined, { timeZone: data.timezone });
            content.replaceChildren(
                node("p", { class: "form-text" },
                    "Monday–Sunday calendar review. Overnight shifts are split by local day; only time inside this week is counted. Rejected time is shown separately. These are elapsed hours, without break deductions, overtime rules or payroll rounding."),
                cards,
                grid(["Day", "Pending", "Approved", "Rejected"], data.days.map(day => [
                    day.date, duration(day.pending_seconds), duration(day.approved_seconds), duration(day.rejected_seconds),
                ]), "Daily time totals"),
                node("h3", { class: "h5 mt-4" }, "Entries in this week"),
                node("p", { class: "form-text" },
                    "An entry crossing a week boundary shows only its portion here. Review opens its full work interval. Refresh this week after another reviewer makes changes."),
                data.items.length ? grid(["Started", "Finished", "Time this week", "Status", "Action"],
                    data.items.map(item => [inZone(item.starts_at), inZone(item.ends_at), duration(item.week_seconds),
                        badge(item.status), button("Review weekly time entry", () => entry(item.id, { employee: id, week: data.week })),
                    ]), "Weekly time entries") : node("p", {}, "No time entries overlap this week."),
            );
        }
        await load(selected);
    }
    async function entry(id, weekly = null) {
        const record = await request(`/sis/timesheets/${id}/`);
        const body = openDrawer(name(record), "TIME ENTRY REVIEW");
        if (can("sis:audit:read"))
            body.append(
                button("View audit history", () => showAudit("timesheets", id)),
            );
        body.append(
            node("p", {}, `${record.institutional_id} · ${record.job_title}`),
            badge(record.status),
            button("Back to employment", () => employee(record.employee_id)),
            grid(
                ["Started", "Finished", "Elapsed time"],
                [
                    [
                        display(record.starts_at),
                        display(record.ends_at),
                        duration(record.duration_seconds),
                    ],
                ],
                "Recorded work",
            ),
            node(
                "p",
                { class: "form-text" },
                "Times use your browser’s local timezone. Recorded elapsed time excludes no breaks and applies no payroll rounding.",
            ),
        );
        if (weekly) body.append(button("Back to weekly time review", () => week(weekly.employee, weekly.week)));
        if (record.status === "pending" && record.is_self)
            body.append(
                node(
                    "p",
                    {},
                    "Another authorized staff member must approve or reject your time entry.",
                ),
            );
        if (record.status === "pending" && !record.is_self) {
            for (const approve of [true, false])
                body.append(
                    button(
                        approve ? "Approve time entry" : "Reject time entry",
                        () =>
                            flow(
                                approve
                                    ? "Confirm time approval"
                                    : "Confirm time rejection",
                                [],
                                `/sis/timesheets/${id}/decision/`,
                                () => ({ approve, version: record.version }),
                                async () => {
                                    await ui.refreshDirectory();
                                    await entry(id, weekly);
                                },
                                {},
                                "Review the employee and work interval. Saving checks the displayed version and prevents self approval. Decisions retain the submitted work interval.",
                            ),
                        approve ? "btn-primary" : "btn-outline-secondary",
                    ),
                );
        }
    }
    async function submit(preset = {}) {
        const body = openDrawer("Submit time", "EMPLOYMENT & TIME");
        body.append(
            node(
                "p",
                { class: "form-text" },
                "Times use your browser’s local timezone. Each entry must end after it starts and span at most 24 elapsed hours. Breaks must be excluded through separate entries.",
            ),
        );
        const fields = [
            ["employee_id", "Team member", "lookup", "employees"],
            ["starts_at", "Started", "datetime-local"],
            ["ends_at", "Finished", "datetime-local"],
        ];
        const form = await buildForm(fields, preset);
        form.append(
            node(
                "button",
                { type: "submit", class: "btn btn-primary" },
                "Submit time entry",
            ),
        );
        form.addEventListener("input", () =>
            form.elements.ends_at.setCustomValidity(""),
        );
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                form.elements.ends_at.setCustomValidity("");
                if (!form.reportValidity()) return;
                const elapsed =
                    new Date(form.elements.ends_at.value) -
                    new Date(form.elements.starts_at.value);
                form.elements.ends_at.setCustomValidity(
                    elapsed <= 0
                        ? "Choose an end after the start."
                        : elapsed > 86400000
                          ? "An entry can span at most 24 elapsed hours."
                          : "",
                );
                if (!form.reportValidity()) return;
                const saved = await request(
                    "/sis/timesheets/",
                    "POST",
                    formData(form, fields),
                );
                notify("Time submitted for review.");
                await ui.refreshDirectory();
                await entry(saved.id);
            }, form.querySelector('[type="submit"]'));
        });
        body.append(form);
    }
    return { employee, entry, submit };
};
