/* Dated employee assignments. Private drafts and reviewed terms stay in page memory. */
window.createWorkspacePositions = function (ui) {
    "use strict";
    const checkLabels = ui.historyCheckLabels || {};
    const canCheck = () => ui.historyChecks && can('sis:hr:manage') && can('sis:history:verify') && can('sis:audit:read');
    const {
        node,
        request,
        task,
        openDrawer,
        badge,
        flow,
        amounts,
        openEmployee,
        notify,
        can,
        recordSummary,
        actionMenu,
    } = ui;
    const base = (employee) => `/sis/employees/${employee}/positions`;
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
    const rate = (p) =>
        `${amounts.format(p.rate_minor, p.currency ?? p.job.currency, p.currency_digits)} ${amounts
            .basisLabel(p.basis ?? p.job.basis)
            .toLowerCase()}`;
    const workload = (minutes) =>
        minutes == null ? "Not set" : `${Math.floor(minutes / 60)}h ${minutes % 60}m per week`;
    const status = (p, today) =>
        p.voided
            ? "Voided"
            : p.valid_from > today
              ? "Scheduled"
              : p.valid_through && p.valid_through < today
                ? "Ended"
                : "Current";

    function unavailable(body, employee) {
        if (!body.isConnected) return;
        document.getElementById("drawer-title").textContent = "Position access unavailable";
        const location = document.querySelector("#drawer-body .record-task-location");
        if (location) location.textContent = "Position access unavailable";
        body.replaceChildren(
            button("Back to employment", () => openEmployee(employee), "btn-link"),
            node("p", { role: "status" }, "Access to this position view is no longer available."),
        );
    }
    async function privateRequest(body, employee, ...args) {
        try {
            return await request(...args);
        } catch (error) {
            if (error.status === 403) unavailable(body, employee);
            throw error;
        }
    }
    async function pages({
        body,
        employee,
        label,
        initial,
        read,
        render,
        remember = () => {},
        resetOnRefresh = false,
    }) {
        let state = { after: null, through: null, stack: [], ...initial },
            nextAfter = null,
            busy = false;
        const snapshot = () => ({ ...state, stack: [...state.stack] });
        const content = node("div");
        const status = node(
            "p",
            { role: "status", class: "form-text", tabindex: "-1" },
            "Loading…",
        );
        const control = (label, run) =>
            node(
                "button",
                {
                    type: "button",
                    class: "btn btn-outline-primary",
                    onclick: () => {
                        if (!busy) task(run);
                    },
                },
                label,
            );
        const previous = control(`Previous ${label}`, () =>
            load(
                { ...snapshot(), after: state.stack.at(-1), stack: state.stack.slice(0, -1) },
                true,
            ),
        );
        const next = control(`Next ${label}`, () =>
            load({ ...snapshot(), after: nextAfter, stack: [...state.stack, state.after] }, true),
        );
        const refresh = control(`Refresh ${label}`, () =>
            load(resetOnRefresh ? { after: 0, through: null, stack: [] } : snapshot(), true),
        );
        body.append(
            status,
            node(
                "nav",
                { class: "inline-actions mb-3", "aria-label": `${label} pages` },
                previous,
                next,
                refresh,
            ),
            content,
        );
        function controls(pending) {
            busy = pending;
            previous.disabled = pending || !state.stack.length;
            next.disabled = pending || nextAfter == null;
            refresh.disabled = pending;
            content.inert = pending;
            content.setAttribute("aria-busy", String(pending));
        }
        async function load(candidate = snapshot(), focus = false) {
            controls(true);
            let result;
            try {
                result = await read(candidate);
            } catch (error) {
                if (body.isConnected && content.isConnected)
                    status.textContent =
                        "This page could not be refreshed. Any previous page remains displayed. Try again.";
                throw error;
            } finally {
                if (body.isConnected) controls(false);
            }
            if (!body.isConnected || !content.isConnected) return;
            state = {
                ...candidate,
                through: result.through_version ?? candidate.through,
                stack: [...candidate.stack],
            };
            nextAfter = result.next_after;
            remember(snapshot());
            status.textContent = `${result.items.length} ${label} on this page.${nextAfter != null ? " More are available." : ""}${state.through != null ? ` Through decision ${state.through}.` : ""}`;
            content.replaceChildren(render(result.items, result));
            controls(false);
            if (focus) status.focus({ preventScroll: true });
        }
        await load();
    }
    const terms = (record) =>
        recordSummary([
            ["From", record.valid_from],
            ["Through (inclusive)", record.valid_through || "Open ended"],
            ["Agreed rate", rate(record)],
            ["Workload", workload(record.weekly_minutes)],
        ]);
    async function page(employee, after = null, stack = [], context = {}) {
        let state = { after, stack: [...stack] };
        const back = () => page(employee, state.after, state.stack, context);
        const body = openDrawer("Position assignments", "HUMAN RESOURCES", { record: null });
        const add = button(
            "Record position assignment",
            () => assign(employee, data.new_id, data.today, null, back),
            "btn-primary",
        );
        let data = null;
        add.disabled = true;
        body.append(
            button(context.backLabel || "Back to employment", context.back || (() => openEmployee(employee)), "btn-link"),
            node(
                "div",
                { class: "inline-actions" },
                add,
                button("Primary position", () =>
                    primary(employee, undefined, {
                        back,
                        backLabel: "Back to assignments",
                        list: back,
                    }),
                ),
            ),
            node(
                "p",
                { class: "form-text" },
                "Each assignment retains its reviewed job, pay grade and agreed terms. Different positions may run concurrently. Recording a position preserves its stated dates without inferring a hire or termination date.",
            ),
        );
        await pages({
            body,
            employee,
            label: "assignments",
            initial: state,
            remember: (value) => {
                state = value;
            },
            read: (candidate) =>
                privateRequest(
                    body,
                    employee,
                    `${base(employee)}/page/`,
                    "POST",
                    candidate.after ? { after: candidate.after } : {},
                ),
            render: (rows, result) => {
                data = result;
                add.disabled = false;
                return rows.length
                    ? node(
                          "ul",
                          { class: "position-list", "aria-label": "Employee position assignments" },
                          ...rows.map((item) =>
                              node(
                                  "li",
                                  { class: "position-card" },
                                  node(
                                      "div",
                                      { class: "position-heading" },
                                      node("h3", {}, item.title),
                                      badge(status(item, result.today)),
                                  ),
                                  node("p", { class: "form-text" }, item.job_code),
                                  terms(item),
                                  node(
                                      "div",
                                      { class: "inline-actions position-actions" },
                                      button("Open position assignment", () =>
                                          detail(employee, item.id, back),
                                      ),
                                  ),
                              ),
                          ),
                      )
                    : node(
                          "p",
                          {},
                          "No position assignments have been recorded. Earlier positions remain unknown.",
                      );
            },
        });
    }
    async function detail(employee, id, list = () => page(employee), returnTo = null) {
        const back = () => detail(employee, id, list, returnTo);
        const body = openDrawer("Position assignment", "RETAINED HR AGREEMENT", {
            record: { key: `position:${id}`, label: "Position assignment", run: back },
        });
        body.append(
            button(
                returnTo ? "Back to assignment" : "Back to assignments",
                returnTo || list,
                "btn-link",
            ),
            node("p", { role: "status" }, "Loading retained agreement…"),
        );
        const record = await privateRequest(body, employee, `${base(employee)}/${id}/`);
        if (!body.isConnected) return;
        body.replaceChildren(
            button(
                returnTo ? "Back to assignment" : "Back to assignments",
                returnTo || list,
                "btn-link",
            ),
            node(
                "section",
                { class: "position-card", "aria-label": "Retained position terms" },
                node(
                    "div",
                    { class: "position-heading" },
                    node("h3", {}, `${record.job.code} · ${record.job.title}`),
                    badge(record.voided ? "Voided" : "Recorded"),
                ),
                terms(record),
                node(
                    "p",
                    { class: "form-text" },
                    record.grade
                        ? `Reviewed pay grade: ${record.grade.code} · ${record.grade.title}. Job and pay-grade labels are retained as accepted.`
                        : "No pay grade was selected for this assignment.",
                ),
                node(
                    "p",
                    { class: "form-text position-attribution" },
                    `Last recorded: ${new Date(record.recorded_at).toLocaleString()}. Recorded by: ${record.recorded_by}.`,
                ),
            ),
        );
        const actions = node("div", {
            class: "inline-actions position-actions",
            role: "group",
            "aria-label": "Position assignment tasks",
        });
        const more = [];
        if (canCheck()) more.push(button(checkLabels.check_position,
            () => ui.historyChecks().open('position', id, back)));
        if (!record.voided) {
            actions.append(
                button("Change position terms", async () => {
                    const data = await privateRequest(
                        body,
                        employee,
                        `${base(employee)}/page/`,
                        "POST",
                        {},
                    );
                    if (!body.isConnected) return;
                    await assign(employee, data.new_id, data.today, record, list, returnTo);
                }),
            );
            if (can("sis:hr:primary"))
                actions.append(
                    button("Designate as primary position", () =>
                        primary(employee, record, { back, backLabel: "Back to assignment", list }),
                    ),
                );
            more.push(
                button("Change end date", () =>
                    flow(
                        "Save assignment end date",
                        [
                            ["valid_through", "Through date", "date", "", false],
                            ["reason", "Reason", "textarea"],
                        ],
                        `${base(employee)}/${id}/end/`,
                        (data) => ({
                            ...data,
                            valid_through: data.valid_through || null,
                            version: record.version,
                        }),
                        back,
                        record,
                        `${record.job.code} · ${record.job.title}. The through date is inclusive. Leave it blank to remove a scheduled closure. Earlier decisions remain in protected history.`,
                        { backLabel: "Back to assignment" },
                    ),
                ),
            );
        }
        if (record.replaces_id)
            more.push(
                button("Open previous assignment", () =>
                    detail(employee, record.replaces_id, list, back),
                ),
            );
        more.push(
            button(
                record.voided ? "Restore assignment" : "Void assignment",
                () =>
                    flow(
                        record.voided
                            ? "Confirm assignment restoration"
                            : "Confirm assignment void",
                        [["reason", "Reason", "textarea"]],
                        `${base(employee)}/${id}/void/`,
                        (data) => ({ ...data, voided: !record.voided, version: record.version }),
                        back,
                        {},
                        `${record.job.code} · ${record.job.title}. ${record.voided ? "Mark this recorded agreement valid again. The earlier void remains in protected history." : "Record why this assignment should be marked incorrect. Earlier facts stay protected."}`,
                        { backLabel: "Back to assignment" },
                    ),
                record.voided ? "btn-outline-primary" : "btn-outline-danger",
            ),
        );
        actions.append(actionMenu("More assignment actions", more));
        body.append(actions);
    }
    async function assign(
        employee,
        id,
        today,
        previous = null,
        list = () => page(employee),
        returnTo = null,
    ) {
        const body = openDrawer(
            previous ? "Change position terms" : "Record position assignment",
            "HUMAN RESOURCES",
        );
        body.append(
            button(previous ? "Back to assignment" : "Back to assignments", () =>
                previous ? detail(employee, previous.id, list, returnTo) : list(),
            ),
        );
        body.append(
            node(
                "p",
                { class: "form-text" },
                previous
                    ? "The previous agreement will end the day before the new one starts. Both decisions save together. Review the new terms and explain the change."
                    : "Select an active job, review its defaults and enter the agreed terms. Recording dates is an HR assertion; earlier positions are not inferred.",
            ),
        );
        const form = node("form", { class: "record-form" });
        const group = (label, description = "") => {
            const fields = node("div", { class: "record-form-grid" });
            form.append(
                node(
                    "fieldset",
                    { class: "record-form-section position-form-section" },
                    node("legend", {}, label),
                    ...(description ? [node("p", { class: "form-text" }, description)] : []),
                    fields,
                ),
            );
            return fields;
        };
        let section = group("Reviewed job defaults");
        const controls = {};
        const field = (key, label, type = "text", value = "", required = true) => {
            const control = node(
                type === "select" ? "select" : type === "textarea" ? "textarea" : "input",
                {
                    id: `position-${key}`,
                    name: key,
                    class: type === "select" ? "form-select" : "form-control",
                    required,
                    ...(type === "select" || type === "textarea" ? {} : { type }),
                    ...(type === "textarea" ? { rows: 3, maxlength: 1000 } : {}),
                    ...(type === "number"
                        ? { min: 0, max: key === "extra_minutes" ? 59 : 168, step: 1 }
                        : {}),
                },
            );
            control.value = value;
            control.addEventListener("input", () => {
                control.setCustomValidity("");
                control.classList.remove("is-invalid");
                control.removeAttribute("aria-invalid");
            });
            controls[key] = control;
            section.append(
                node(
                    "div",
                    {
                        class: `record-form-field${["job_id", "rate_minor", "reason"].includes(key) ? " record-form-wide" : ""}`,
                    },
                    node("label", { for: control.id, class: "form-label" }, label),
                    control,
                ),
            );
            return control;
        };
        const jobs = field("job_id", "Job definition", "select");
        jobs.append(node("option", { value: "" }, "Choose a job"));
        const search = node("input", {
            type: "search",
            maxlength: 100,
            class: "form-control",
            "aria-label": "Search job definitions",
            placeholder: "Search by code or title",
        });
        let cursor = null,
            selected = null,
            epoch = 0,
            choiceEpoch = 0;
        let iso = [];
        const source = node("section", {
            class: "position-source record-form-wide",
            "aria-label": "Reviewed job defaults",
        });
        const info = node("p", { role: "status", class: "form-text" });
        const find = button("Find jobs", () => choices(search.value));
        const more = button("More jobs", () => choices(search.value, cursor));
        more.hidden = true;
        search.addEventListener("keydown", (event) => {
            if (event.key === "Enter") {
                event.preventDefault();
                task(() => choices(search.value));
            }
        });
        section.append(
            node(
                "label",
                { class: "form-label record-form-wide" },
                "Search job definitions",
                search,
            ),
            node("div", { class: "inline-actions record-form-wide" }, find, more),
            info,
            source,
        );
        info.classList.add("record-form-wide");
        section = group(
            "Agreement dates",
            "Both dates are inclusive. An open through date does not assert a termination date.",
        );
        field(
            "valid_from",
            previous ? "Change effective on" : "From date",
            "date",
            previous && previous.valid_from >= today ? "" : today,
        );
        field("valid_through", "Through date", "date", "", false);
        section = group(
            "Agreed terms",
            "Blank rate or workload means unknown; zero is explicit. These terms do not calculate payroll or relabel earlier time entries.",
        );
        const amount = field("rate_minor", "Agreed rate", "text", "", false);
        amount.inputMode = "decimal";
        const rateHelp = node("p", { id: "position-rate-help", class: "form-text mb-0" });
        amount.after(rateHelp);
        amount.setAttribute("aria-describedby", rateHelp.id);
        field("weekly_hours", "Weekly hours", "number", "", false);
        field("extra_minutes", "Additional minutes", "number", "", false);
        section = group("Agreement reason");
        field("reason", "Reason for this agreement", "textarea");
        const review = node(
            "button",
            { type: "submit", class: "btn btn-primary", disabled: true },
            "Review assignment",
        );
        form.append(review);
        const confirmation = node("section", {
            hidden: true,
            class: "position-confirmation",
            "aria-label": "Position agreement review",
        });
        body.append(form, confirmation);
        async function choices(query, after = null) {
            const token = ++choiceEpoch;
            const data = await privateRequest(body, employee, "/sis/hr/jobs/page/", "POST", {
                search: query,
                ...(after ? { after } : {}),
            });
            if (!body.isConnected || token !== choiceEpoch) return;
            iso = data.currencies;
            const value = jobs.value;
            const option = [...jobs.options].find((item) => item.value === value)?.cloneNode(true);
            if (!after) {
                jobs.replaceChildren(node("option", { value: "" }, "Choose a job"));
                if (option && value) jobs.append(option);
            }
            data.items
                .filter((item) => item.active)
                .forEach((item) => {
                    if (![...jobs.options].some((option) => option.value === item.id))
                        jobs.append(
                            node("option", { value: item.id }, `${item.code} · ${item.title}`),
                        );
                });
            jobs.value = value;
            cursor = data.next_after;
            more.hidden = !cursor;
            info.textContent = `${jobs.options.length - 1} active choices loaded. Search to find another job.`;
        }
        async function loadSource() {
            const token = ++epoch;
            selected = null;
            review.disabled = true;
            amount.disabled = true;
            source.replaceChildren();
            rateHelp.textContent = "";
            if (!jobs.value) return;
            const job = await privateRequest(body, employee, `/sis/hr/jobs/${jobs.value}/`);
            if (!body.isConnected || token !== epoch) return;
            const grade = job.pay_grade_id
                ? await privateRequest(body, employee, `/sis/hr/pay-grades/${job.pay_grade_id}/`)
                : null;
            if (!body.isConnected || token !== epoch) return;
            if (!job.active || (grade && !grade.active))
                throw new Error("Choose an active job and pay grade.");
            const places = iso.find((item) => item.code === job.currency)?.digits;
            if (!Number.isInteger(places))
                throw new Error("Review the supported job currency again.");
            selected = { job, grade, digits: places };
            rateHelp.textContent = `${job.currency} · ${amounts.basisLabel(job.basis)} · ${places} decimal places`;
            const sameUnits =
                previous &&
                previous.job.id === job.id &&
                previous.job.currency === job.currency &&
                previous.job.basis === job.basis &&
                previous.currency_digits === places;
            amount.value = amounts.text(sameUnits ? previous.rate_minor : job.rate_minor, places);
            const minutes = sameUnits ? previous.weekly_minutes : job.weekly_minutes;
            controls.weekly_hours.value = minutes == null ? "" : Math.floor(minutes / 60);
            controls.extra_minutes.value = minutes == null ? "" : minutes % 60;
            source.append(
                node("h3", { class: "h5 private-text" }, `${job.code} · ${job.title}`),
                node(
                    "p",
                    {},
                    `${job.currency} · ${amounts.basisLabel(job.basis)} · ${places} decimal places`,
                ),
                node(
                    "p",
                    { class: "form-text" },
                    grade
                        ? `Pay grade ${grade.code}: ${amounts.format(grade.minimum_minor, job.currency, places)} through ${amounts.format(grade.maximum_minor, job.currency, places)}.`
                        : "This job has no pay grade.",
                ),
            );
            review.disabled = false;
            amount.disabled = false;
        }
        jobs.addEventListener("change", () => task(loadSource));
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            if (!selected) return;
            amount.setCustomValidity("");
            if (!form.reportValidity()) return;
            const rateMinor = amounts.parse(amount.value, selected.digits, amount);
            if (rateMinor === false) {
                form.reportValidity();
                return;
            }
            const minutes =
                controls.weekly_hours.value === "" && controls.extra_minutes.value === ""
                    ? null
                    : Number(controls.weekly_hours.value || 0) * 60 +
                      Number(controls.extra_minutes.value || 0);
            if (minutes > 10080) {
                controls.weekly_hours.setCustomValidity(
                    "Weekly hours must fit within a seven-day week.",
                );
                form.reportValidity();
                return;
            }
            const payload = {
                id,
                job_id: selected.job.id,
                job_version: selected.job.version,
                grade_version: selected.grade?.version ?? null,
                valid_from: controls.valid_from.value,
                valid_through: controls.valid_through.value || null,
                rate_minor: rateMinor,
                weekly_minutes: minutes,
                reason: controls.reason.value,
                replaces_id: previous?.id ?? null,
                replaced_version: previous?.version ?? null,
            };
            confirmation.replaceChildren(
                node("h3", { class: "h5" }, "Review position agreement"),
                recordSummary([
                    ["Job", `${selected.job.code} · ${selected.job.title}`],
                    ["From", payload.valid_from],
                    ["Through (inclusive)", payload.valid_through || "Open ended"],
                    [
                        "Agreed rate",
                        rate({
                            rate_minor: rateMinor,
                            job: selected.job,
                            currency_digits: selected.digits,
                        }),
                    ],
                    ["Workload", workload(minutes)],
                ]),
                node(
                    "p",
                    { class: "form-text" },
                    previous
                        ? `Previous assignment closes the day before ${payload.valid_from}.`
                        : "The reviewed job and pay-grade definitions will be retained with this agreement.",
                ),
                node("p", { class: "position-reason" }, `Reason: ${payload.reason}`),
                button("Back to assignment form", () => {
                    confirmation.hidden = true;
                    form.hidden = false;
                    review.focus();
                }),
                button(
                    "Confirm assignment",
                    async () => {
                        const saved = await privateRequest(
                            body,
                            employee,
                            `${base(employee)}/assign/`,
                            "POST",
                            payload,
                        );
                        if (!body.isConnected) return;
                        notify("Position agreement recorded.");
                        await detail(employee, saved.id, list);
                    },
                    "btn-primary",
                ),
            );
            form.hidden = true;
            confirmation.hidden = false;
            confirmation.append(
                node(
                    "div",
                    { class: "inline-actions position-actions" },
                    ...confirmation.querySelectorAll(":scope > button"),
                ),
            );
            confirmation.querySelector("button").focus();
        });
        await choices("");
    }
    const primaryLabel = (selection) =>
        selection ? `${selection.code} · ${selection.title}` : "No primary designation";
    const primaryStatus = (status) =>
        ({
            current: "Current",
            changed: "Appointment changed — HR review required",
            ended: "Appointment ended — HR review required",
            voided: "Appointment voided — HR review required",
            scheduled: "Appointment does not cover today — HR review required",
            cleared: "Explicitly cleared",
            unrecorded: "Not recorded; earlier designations are unknown",
        })[status] || "Review required";

    async function primary(employee, proposed = undefined, context = {}, draftReason = "") {
        const {
            back = () => openEmployee(employee),
            backLabel = "Back to employment",
            list = () => page(employee),
        } = context;
        const reopen = () => primary(employee, undefined, context);
        const body = openDrawer("Primary position", "EMPLOYMENT", {
            record: { key: `primary-position:${employee}`, label: "Primary position", run: reopen },
        });
        body.append(
            button(backLabel, back, "btn-link"),
            node("p", { role: "status" }, "Loading primary position…"),
        );
        const data = await privateRequest(
            body,
            employee,
            `/sis/employees/${employee}/primary-position/`,
        );
        if (!body.isConnected) return;
        body.replaceChildren(
            button(backLabel, back, "btn-link"),
            node(
                "section",
                { class: "position-card", "aria-label": "Current primary position" },
                node("h3", { class: "h6" }, "Current primary position"),
                node("p", { class: "position-label" }, primaryLabel(data.selection)),
                node("p", { role: "status", class: "form-text" }, primaryStatus(data.status)),
            ),
            node(
                "p",
                { class: "form-text" },
                "This is an explicit HR designation from confirmation onward. It does not change pay, security access or the position actually worked on a timesheet. Ended or replaced appointments stay visible until HR reviews the designation.",
            ),
        );
        const allowed = can("sis:hr:manage") && can("sis:hr:primary");
        let reason = null;
        const tasks = node("div", { class: "inline-actions position-actions" });
        if (canCheck()) tasks.append(button(checkLabels.check_primary, () => ui.historyChecks().open('primary', employee,
            () => primary(employee, proposed, context, reason?.value ?? draftReason))));
        if (can("sis:hr:manage")) tasks.append(button("Choose from position assignments", list));
        if (can("sis:hr:manage") && can("sis:audit:read"))
            tasks.append(
                button("Primary designation history", () =>
                    primaryHistory(
                        employee,
                        null,
                        reason ? () => primary(employee, proposed, context, reason.value) : reopen,
                    ),
                ),
            );
        if (allowed && proposed === undefined && data.selection)
            tasks.append(
                button("Clear primary designation", () => primary(employee, null, context)),
            );
        body.append(tasks);
        if (!allowed || proposed === undefined) return;
        const form = node("form", { class: "record-form" });
        reason = node("textarea", {
            id: "primary-position-reason",
            class: "form-control",
            required: true,
            rows: 3,
            maxlength: 1000,
            value: draftReason,
        });
        const submit = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review primary designation",
        );
        form.append(
            node(
                "fieldset",
                { class: "record-form-section position-form-section" },
                node("legend", {}, "Proposed primary designation"),
                node(
                    "p",
                    { class: "position-label" },
                    primaryLabel(
                        proposed?.job
                            ? { code: proposed.job.code, title: proposed.job.title }
                            : proposed,
                    ),
                ),
                node(
                    "label",
                    { for: reason.id, class: "form-label" },
                    "Reason for primary designation",
                ),
                reason,
            ),
            submit,
        );
        const confirmation = node("section", {
            hidden: true,
            class: "position-confirmation",
            "aria-label": "Primary designation review",
        });
        body.append(form, confirmation);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            task(async () => {
                const payload = {
                    operation_id: data.operation_id,
                    version: data.version,
                    position_id: proposed?.id ?? null,
                    position_version: proposed?.version ?? null,
                    reason: reason.value,
                };
                const reviewed = await privateRequest(
                    body,
                    employee,
                    `/sis/employees/${employee}/primary-position/review/`,
                    "POST",
                    payload,
                );
                if (!body.isConnected) return;
                confirmation.replaceChildren(
                    node("h3", { class: "h5" }, "Review primary designation"),
                    recordSummary([
                        ["Before", primaryLabel(reviewed.before)],
                        ["After", primaryLabel(reviewed.after)],
                    ]),
                    node("p", { class: "position-reason" }, `Reason: ${payload.reason}`),
                    node(
                        "div",
                        { class: "inline-actions position-actions" },
                        button("Revise primary designation", () => {
                            confirmation.hidden = true;
                            form.hidden = false;
                            reason.focus();
                        }),
                        button(
                            "Confirm primary designation",
                            async () => {
                                await privateRequest(
                                    body,
                                    employee,
                                    `/sis/employees/${employee}/primary-position/confirm/`,
                                    "POST",
                                    { ...payload, review_token: reviewed.review_token },
                                );
                                if (!body.isConnected) return;
                                notify("Primary position designation recorded.");
                                await reopen();
                            },
                            "btn-primary",
                        ),
                    ),
                );
                form.hidden = true;
                confirmation.hidden = false;
                confirmation.querySelector("button").focus();
            }, submit);
        });
    }
    async function primaryHistory(employee, initial = null, back = () => primary(employee)) {
        const body = openDrawer("Primary designation history", "RETAINED HR DECISIONS");
        body.append(
            button("Back to primary position", back, "btn-link"),
            node(
                "p",
                { class: "form-text" },
                "Only recorded designations appear. Earlier designations remain unknown. Recorder labels reflect current account names. Refresh decisions starts a new history view.",
            ),
        );
        await pages({
            body,
            employee,
            label: "decisions",
            initial: initial || { after: 0, through: null, stack: [] },
            resetOnRefresh: true,
            read: (candidate) =>
                privateRequest(
                    body,
                    employee,
                    `/sis/employees/${employee}/primary-position/history/`,
                    "POST",
                    { after: candidate.after, through_version: candidate.through },
                ),
            render: (rows) =>
                rows.length
                    ? node(
                          "ul",
                          { class: "position-list", "aria-label": "Primary designation decisions" },
                          ...rows.map((item) =>
                              node(
                                  "li",
                                  { class: "position-card" },
                                  node("h3", { class: "h6" }, `Decision ${item.version}`),
                                  node(
                                      "p",
                                      { class: "position-label" },
                                      primaryLabel(item.selection),
                                  ),
                                  node(
                                      "p",
                                      { class: "form-text" },
                                      "Effective: ",
                                      node(
                                          "time",
                                          { datetime: item.effective_at },
                                          new Date(item.effective_at).toLocaleString(),
                                      ),
                                  ),
                                  node(
                                      "p",
                                      { class: "form-text" },
                                      `Recorded by: ${item.recorded_by}`,
                                  ),
                                  item.recorded_at
                                      ? node(
                                            "p",
                                            { class: "form-text" },
                                            "Recorded: ",
                                            node(
                                                "time",
                                                { datetime: item.recorded_at },
                                                new Date(item.recorded_at).toLocaleString(),
                                            ),
                                        )
                                      : null,
                                  node("p", { class: "position-reason" }, item.reason),
                              ),
                          ),
                      )
                    : node("p", {}, "No designation decisions have been recorded."),
        });
    }
    return { page, detail, primary };
};
