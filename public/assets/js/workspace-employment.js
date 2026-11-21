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
        recordSummary,
        actionMenu,
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
        const actions = node("div", {
            class: "inline-actions",
            role: "group",
            "aria-label": "Employment tasks",
        });
        const extra = [];
        actions.append(button("Employment dates", () => employmentDates(id)));
        if (can("sis:audit:read"))
            extra.push(button("View audit history", () => showAudit("employees", id)));
        if (can("sis:hr:manage") && can("sis:audit:read"))
            extra.push(button("Employment change history", () => history(record)));
        if (can("sis:hr:manage"))
            actions.append(button("Position assignments", () => ui.positions().page(id)));
        body.append(
            recordSummary([
                ["Institutional ID", record.institutional_id],
                ["Job title", record.job_title],
                [
                    "Time submission setting",
                    badge(record.active ? "Time submissions enabled" : "Time submissions paused"),
                ],
            ]),
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
            extra.push(button("Open connected person", () => openPerson(record.person_id)));
        actions.append(button("Weekly time review", () => week(id)));
        extra.unshift(
            button("Edit employment", () =>
                flow(
                    "Save employment",
                    [
                        ["job_title", "Job title"],
                        ["active", "Time submissions enabled", "checkbox"],
                        ["reason", "Reason for employment change", "textarea"],
                    ],
                    `/sis/employees/${id}/edit/`,
                    (data) => ({ ...data, revision: record.revision }),
                    () => employee(id),
                    record,
                    "Inactive employment blocks new time submissions. Historical entries and pending reviews remain available. This does not disable sign-in or change student status.",
                    { layout: "employment_profile" },
                ),
            ),
        );
        if (record.active) {
            const submitTime = button(
                "Submit time",
                () => submit({ employee_id: id }),
                "btn-primary",
            );
            submitTime.setAttribute("aria-label", "Submit time for this employee");
            actions.append(submitTime);
        } else
            body.append(
                node(
                    "p",
                    { class: "form-text" },
                    "Enable time submissions before entering more time. Recorded employment dates also apply.",
                ),
            );
        if (extra.length)
            actions.append(actionMenu("More", extra, { context: "More employment actions" }));
        body.append(actions);
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
        body.append(information, content, node("div", { class: "inline-actions" }, previous, next));
        async function load() {
            const token = ++serial;
            const page = await request(`/sis/employees/${id}/time-entries/`, "POST", { after });
            if (!body.isConnected || token !== serial) return;
            nextAfter = page.next_after;
            previous.disabled = !previousCursors.length;
            next.disabled = !nextAfter;
            information.textContent = `${page.items.length} entries on this page.${nextAfter ? " More are available." : ""}`;
            content.replaceChildren(
                page.items.length
                    ? grid(
                          ["Position", "Started", "Finished", "Duration", "Status", "Action"],
                          page.items.map((item) => [
                              item.position
                                  ? `${item.position.code} · ${item.position.title}`
                                  : "Not recorded",
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

    async function employmentDates(id) {
        const body = openDrawer("Employment dates", "HIRE, REHIRE & END DATES");
        body.append(button("Back to employment", () => employee(id)));
        const data = await request(`/sis/employees/${id}/employment-dates/`);
        if (!body.isConnected) return;
        body.append(
            node(
                "p",
                { class: "form-text" },
                "Recorded periods use inclusive last employment days. Earlier dates remain unknown until HR records them. Person sign-in and student records have separate controls.",
            ),
        );
        const rows = [...data.periods]
            .sort((a, b) => b.from.localeCompare(a.from))
            .map((period) => {
                const status = period.voided
                    ? "Voided"
                    : period.from > data.today
                      ? "Scheduled"
                      : period.through && period.through < data.today
                        ? "Ended"
                        : "Current";
                const actions = node("div", { class: "inline-actions" });
                if (can("sis:hr:manage")) {
                    actions.append(
                        button("Review employment dates", () =>
                            editEmploymentDates(id, data, period),
                        ),
                    );
                    if (!period.voided && !period.through && period.from <= data.today)
                        actions.append(
                            button("End employment", () =>
                                editEmploymentDates(id, data, { ...period, through: data.today }),
                            ),
                        );
                } else actions.append("Read only");
                return [period.from, period.through || "Open ended", badge(status), actions];
            });
        body.append(
            rows.length
                ? grid(
                      ["Hire / start", "Last employment day", "Status", "Action"],
                      rows,
                      "Recorded employment periods",
                  )
                : node(
                      "p",
                      {},
                      "No hire or end dates have been recorded. The existing time-submission flag still applies.",
                  ),
        );
        if (can("sis:hr:manage"))
            body.append(
                button("Record hire or rehire", () => editEmploymentDates(id, data), "btn-primary"),
            );
    }

    async function editEmploymentDates(id, current, period = null) {
        const body = openDrawer(
            period ? "Review employment dates" : "Record hire or rehire",
            "HUMAN RESOURCES",
        );
        body.append(
            button("Back to employment dates", () => employmentDates(id)),
            node(
                "p",
                { class: "form-text" },
                "Review the effect on positions before saving. Ending employment closes agreements on the last employment day; later scheduled agreements are marked void. Earlier time entries remain for HR review. Reopening dates does not restore positions automatically.",
            ),
        );
        const fields = [
            ["from", "Hire / employment start", "date"],
            ["through", "Last employment day", "date", "", false],
            ...(period
                ? [["voided", "Mark this employment period incorrect", "checkbox", "", false]]
                : []),
            ["reason", "Reason for the employment decision", "textarea"],
        ];
        const form = await buildForm(fields, period || { from: current.today }, {
            layout: "employment_dates",
        });
        form.elements.namedItem("reason").maxLength = 1000;
        if (!body.isConnected) return;
        const submit = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review employment decision",
        );
        form.append(submit);
        const confirmation = node("div", { hidden: true });
        body.append(form, confirmation);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const entered = formData(form, fields);
                const payload = {
                    ...entered,
                    through: entered.through || null,
                    voided: entered.voided ?? false,
                    period_id: period?.id ?? current.new_id,
                    version: current.version,
                };
                const review = await request(
                    `/sis/employees/${id}/employment-dates/review/`,
                    "POST",
                    payload,
                );
                if (!body.isConnected) return;
                const changes = review.positions.map((position) => [
                    position.code,
                    position.title,
                    position.action,
                    position.through || "Not applicable",
                ]);
                confirmation.replaceChildren(
                    node("h3", { class: "h5" }, "Review employment decision"),
                    node(
                        "p",
                        {},
                        `${payload.from} through ${payload.through || "open ended"}${payload.voided ? " · Marked incorrect" : ""}`,
                    ),
                    node("p", { class: "private-text" }, `Reason: ${payload.reason}`),
                    changes.length
                        ? grid(
                              ["Job", "Title", "Decision", "Through"],
                              changes,
                              "Position changes to confirm",
                          )
                        : node("p", {}, "No position assignments need to change."),
                    node(
                        "p",
                        { class: "form-text" },
                        "These dates and listed position changes save together. Existing time, person, student and account records remain intact.",
                    ),
                    button("Back to dates form", () => {
                        confirmation.hidden = true;
                        form.hidden = false;
                        submit.focus();
                    }),
                    button(
                        "Confirm employment decision",
                        async () => {
                            await request(
                                `/sis/employees/${id}/employment-dates/confirm/`,
                                "POST",
                                { ...payload, position_revision: review.position_revision },
                            );
                            if (!body.isConnected) return;
                            notify("Employment dates and position changes recorded.");
                            await employmentDates(id);
                        },
                        "btn-primary",
                    ),
                );
                form.hidden = true;
                confirmation.hidden = false;
                confirmation.querySelector("button").focus();
            }, submit);
        });
    }
    async function history(record) {
        const id = record.id;
        const body = openDrawer(
            "Employment change history",
            `${name(record)} · ${record.institutional_id}`,
        );
        body.append(button("Back to employment", () => employee(id)));
        await historyPanel({
            body,
            endpoint: `/sis/employees/${id}/history/`,
            label: "Recorded employment changes",
            description:
                "Review recorded titles and active status, newest first. Private reasons, credentials and raw events are excluded. Recorder names are current account names. These changes do not establish hire or termination dates.",
            unknownText:
                "Earlier employment history is unknown. Observed details describe the record when tracking began, not when employment started.",
            emptyText:
                "No employment changes have been recorded yet. The current title and status remain in the employment record; earlier changes are unknown.",
            render: (item) =>
                node(
                    "div",
                    {},
                    node("p", { class: "private-text" }, item.job_title),
                    badge(item.active ? "Active" : "Inactive"),
                    node(
                        "p",
                        { class: "form-text" },
                        item.earlier_details_unknown
                            ? "Existing details were observed here. Earlier changes and their dates are unknown."
                            : `Fields: ${item.fields.join(", ")}`,
                    ),
                ),
        });
    }
    async function week(id, selected = "") {
        const body = openDrawer("Weekly time review", "EMPLOYMENT & TIME");
        body.append(button("Back to employment", () => employee(id)));
        const form = node("form", { class: "record-form mt-3" });
        const date = node("input", {
            id: "time-review-date",
            name: "week",
            type: "date",
            required: true,
            min: "1000-01-08",
            max: "9998-12-24",
            class: "form-control",
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
        body.append(
            form,
            node("div", { class: "inline-actions" }, previous, next, refresh),
            information,
            content,
        );
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            if (form.reportValidity()) task(() => load(date.value), submit);
        });
        async function load(value) {
            const token = ++serial;
            const data = await request(
                `/sis/employees/${id}/time-week/`,
                "POST",
                value ? { week: value } : {},
            );
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
                cards.append(
                    node(
                        "div",
                        { class: "col-12 col-sm-4" },
                        node(
                            "div",
                            { class: "card h-100" },
                            node(
                                "div",
                                { class: "card-body" },
                                node("h3", { class: "h6 text-capitalize" }, status),
                                node("p", { class: "h4" }, duration(total.seconds)),
                                node(
                                    "p",
                                    { class: "mb-0" },
                                    `${total.entries} ${total.entries === 1 ? "entry" : "entries"}`,
                                ),
                            ),
                        ),
                    ),
                );
            }
            const inZone = (instant) =>
                new Date(instant).toLocaleString(undefined, { timeZone: data.timezone });
            content.replaceChildren(
                node(
                    "p",
                    { class: "form-text" },
                    "Monday–Sunday calendar review. Overnight shifts are split by local day; only time inside this week is counted. Rejected time is shown separately. These are elapsed hours, without break deductions, overtime rules or payroll rounding.",
                ),
                cards,
                grid(
                    ["Day", "Pending", "Approved", "Rejected"],
                    data.days.map((day) => [
                        day.date,
                        duration(day.pending_seconds),
                        duration(day.approved_seconds),
                        duration(day.rejected_seconds),
                    ]),
                    "Daily time totals",
                ),
                node("h3", { class: "h5 mt-4" }, "Entries in this week"),
                node(
                    "p",
                    { class: "form-text" },
                    "An entry crossing a week boundary shows only its portion here. Review opens its full work interval. Refresh this week after another reviewer makes changes.",
                ),
                data.items.length
                    ? grid(
                          ["Position", "Started", "Finished", "Time this week", "Status", "Action"],
                          data.items.map((item) => [
                              item.position
                                  ? `${item.position.code} · ${item.position.title}`
                                  : "Not recorded",
                              inZone(item.starts_at),
                              inZone(item.ends_at),
                              duration(item.week_seconds),
                              badge(item.status),
                              button("Review weekly time entry", () =>
                                  entry(item.id, { employee: id, week: data.week }),
                              ),
                          ]),
                          "Weekly time entries",
                      )
                    : node("p", {}, "No time entries overlap this week."),
            );
        }
        await load(selected);
    }
    async function entry(id, weekly = null) {
        const record = await request(`/sis/timesheets/${id}/`);
        const body = openDrawer(name(record), "TIME ENTRY REVIEW");
        if (can("sis:audit:read"))
            body.append(button("View audit history", () => showAudit("timesheets", id)));
        body.append(
            node("p", {}, `${record.institutional_id} · ${record.job_title}`),
            node(
                "p",
                { class: "private-text" },
                record.position
                    ? `Position worked: ${record.position.code} · ${record.position.title}`
                    : "Position worked: Not recorded",
            ),
            record.position?.voided
                ? node(
                      "p",
                      { class: "form-text" },
                      "This assignment has since been marked incorrect. Review it before approving the work.",
                  )
                : null,
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
        if (weekly)
            body.append(
                button("Back to weekly time review", () => week(weekly.employee, weekly.week)),
            );
        if (record.status === "pending" && record.is_self)
            body.append(
                node(
                    "p",
                    {},
                    "Another authorized staff member must approve or reject your time entry.",
                ),
            );
        if (record.time_review_limited)
            body.append(
                node(
                    "p",
                    { role: "status", class: "alert alert-warning" },
                    "This time-policy review exceeds its bounded allowance. Approval is unavailable; HR can still reject the selected entry.",
                ),
            );
        if (record.time_review)
            body.append(window.createTimeReviewPolicy(ui).summary(record.time_review));
        else if (record.status !== "pending")
            body.append(
                node(
                    "p",
                    { class: "form-text" },
                    "Earlier decision policy evidence was not recorded.",
                ),
            );
        if (record.exception_reason)
            body.append(node("p", {}, `Recorded exception reason: ${record.exception_reason}`));
        if (record.status === "pending" && !record.is_self) {
            for (const approve of [true, false]) {
                const required = approve && record.time_review?.requires_exception;
                const control = button(
                    approve ? "Approve time entry" : "Reject time entry",
                    async () => {
                        const form = await flow(
                            approve ? "Confirm time approval" : "Confirm time rejection",
                            required
                                ? [
                                      [
                                          "exception_reason",
                                          "Reason for this time-policy exception",
                                          "textarea",
                                      ],
                                  ]
                                : [],
                            `/sis/timesheets/${id}/decision/`,
                            (data) => ({
                                ...data,
                                approve,
                                version: record.version,
                                ...(record.time_review
                                    ? { review_revision: record.time_review.revision }
                                    : {}),
                            }),
                            async () => {
                                await ui.refreshDirectory();
                                await entry(id, weekly);
                            },
                            {},
                            "Review the employee, work interval and policy flags. Saving rechecks the policy and current time entries, prevents self approval and retains the evidence used.",
                        );
                        if (!form.isConnected) return;
                        form.prepend(
                            node(
                                "p",
                                {},
                                `${record.given_name} ${record.family_name} · ${display(record.starts_at)} through ${display(record.ends_at)} · ${duration(record.duration_seconds)}`,
                            ),
                        );
                        if (record.time_review)
                            form.prepend(
                                window.createTimeReviewPolicy(ui).summary(record.time_review),
                            );
                    },
                    approve ? "btn-primary" : "btn-outline-secondary",
                );
                if (
                    (required && !record.can_approve_exception) ||
                    (approve && record.time_review_limited)
                ) {
                    control.disabled = true;
                    body.append(
                        node(
                            "p",
                            { class: "form-text" },
                            "An authorized exception reviewer must approve this flagged work. You can still reject it.",
                        ),
                    );
                }
                body.append(control);
            }
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
        const form = await buildForm(fields, preset, { layout: "work_time" });
        if (!body.isConnected) return;
        const positionGroup = node("div", { class: "mb-3" });
        const position = node("select", {
            id: "time-work-position",
            name: "position_id",
            class: "form-select",
            disabled: true,
            "aria-describedby": "time-work-position-status",
        });
        const reviewStatus = node("div");
        form.append(reviewStatus);
        const positionStatus = node("p", {
            id: "time-work-position-status",
            class: "form-text",
            role: "status",
            "aria-live": "polite",
        });
        positionGroup.append(
            node("label", { for: position.id, class: "form-label" }, "Position worked"),
            position,
            positionStatus,
            button("Refresh work positions", () => loadPositions(true)),
        );
        form.append(positionGroup);
        let epoch = 0,
            loadedKey = null,
            choices = null;
        function workKey() {
            return JSON.stringify([
                form.elements.employee_id.value,
                form.elements.starts_at.value,
                form.elements.ends_at.value,
            ]);
        }
        function validWork() {
            const elapsed =
                new Date(form.elements.ends_at.value) - new Date(form.elements.starts_at.value);
            return (
                form.elements.employee_id.value &&
                Number.isFinite(elapsed) &&
                elapsed > 0 &&
                elapsed <= 86400000
            );
        }
        async function loadPositions(force = false) {
            if (!validWork()) {
                positionStatus.textContent = "Choose the team member and work times first.";
                return;
            }
            const key = workKey();
            if (!force && loadedKey === key) return;
            const token = ++epoch,
                previous = position.value;
            choices = null;
            loadedKey = null;
            position.disabled = true;
            position.required = false;
            positionStatus.textContent = "Checking work positions…";
            const data = formData(form, fields);
            const result = await request(
                `/sis/employees/${data.employee_id}/work-positions/`,
                "POST",
                { starts_at: data.starts_at, ends_at: data.ends_at },
            );
            if (!body.isConnected || token !== epoch || key !== workKey()) return;
            choices = result;
            loadedKey = key;
            reviewStatus.replaceChildren(
                result.time_review
                    ? window.createTimeReviewPolicy(ui).summary(result.time_review)
                    : node("span"),
            );
            position.replaceChildren(
                node(
                    "option",
                    { value: "" },
                    result.position_required ? "Choose the position worked" : "Not recorded",
                ),
            );
            result.items.forEach((item) =>
                position.append(node("option", { value: item.id }, `${item.code} · ${item.title}`)),
            );
            if (result.items.some((item) => item.id === previous)) position.value = previous;
            else if (result.items.length === 1) position.value = result.items[0].id;
            position.required = result.position_required;
            position.disabled = !result.position_required;
            positionStatus.textContent = result.position_required
                ? result.items.length
                    ? `Choose the position covering the full interval. Assignment dates use ${result.timezone}.`
                    : "No position covers the full work interval. Split work at assignment boundaries or ask HR to review the dates."
                : "No position has been recorded for this employee. Earlier time stays unassigned.";
        }
        for (const key of ["employee_id", "starts_at", "ends_at"]) {
            form.elements[key].addEventListener("input", () => {
                reviewStatus.replaceChildren();
                epoch++;
                loadedKey = null;
                choices = null;
                position.required = false;
                position.disabled = true;
                position.replaceChildren();
                positionStatus.textContent = "Review positions for the entered work times.";
            });
            form.elements[key].addEventListener("change", () => {
                if (validWork()) task(() => loadPositions());
            });
        }
        positionStatus.textContent = "Choose the team member and work times first.";
        form.append(
            node("button", { type: "submit", class: "btn btn-primary" }, "Submit time entry"),
        );
        form.addEventListener("input", () => form.elements.ends_at.setCustomValidity(""));
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                form.elements.ends_at.setCustomValidity("");
                if (!form.reportValidity()) return;
                const elapsed =
                    new Date(form.elements.ends_at.value) - new Date(form.elements.starts_at.value);
                form.elements.ends_at.setCustomValidity(
                    elapsed <= 0
                        ? "Choose an end after the start."
                        : elapsed > 86400000
                          ? "An entry can span at most 24 elapsed hours."
                          : "",
                );
                if (!form.reportValidity()) return;
                await loadPositions();
                if (!body.isConnected || !choices || loadedKey !== workKey()) return;
                if (choices.position_required && !position.value) {
                    position.disabled = false;
                    position.required = true;
                    position.reportValidity();
                    return;
                }
                const selected = choices.items.find((item) => item.id === position.value);
                const saved = await request("/sis/timesheets/", "POST", {
                    ...formData(form, fields),
                    position_id: selected?.id ?? null,
                    position_version: selected?.version ?? null,
                });
                if (!body.isConnected) return;
                notify("Time submitted for review.");
                await ui.refreshDirectory();
                await entry(saved.id);
            }, form.querySelector('[type="submit"]'));
        });
        body.append(form);
    }
    return { employee, entry, submit };
};
