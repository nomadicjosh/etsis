window.createTimeReviewPolicy = function (ui) {
    "use strict";
    const { node, request, task, openDrawer, table, recordSummary, backToWorkspace } = ui;
    const button = (label, run, style = "btn-outline-primary") =>
        node(
            "button",
            {
                type: "button",
                class: `btn ${style}`,
                onclick: (event) => task(run, event.currentTarget),
            },
            label,
        );
    const labels = {
        daily_limit: "Daily time limit exceeded",
        weekly_limit: "Weekly time limit exceeded",
        overlap: "Overlapping work intervals",
    };
    const hours = (seconds) =>
        `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m${seconds % 60 ? ` ${seconds % 60}s` : ""}`;
    function summary(data) {
        if (!data)
            return node(
                "p",
                { class: "form-text" },
                "Earlier decision policy and exception evidence were not recorded.",
            );
        const r = data.policy.rules;
        return node(
            "section",
            { class: "record-section", "aria-label": "Time policy review" },
            node("h3", { class: "h6" }, "Time policy review"),
            node(
                "p",
                {},
                `${data.policy.version ? `Retained policy ${data.policy.version}` : "No institutional review policy published"} · ${r.timezone}`,
            ),
            node(
                "p",
                { class: "form-text" },
                "These are clocked elapsed hours, separate from section contact hours. Pending and approved time both count. Rejected time is excluded; overlapping intervals are summed without deduplication. No breaks, overtime or pay are calculated.",
            ),
            node(
                "p",
                { role: "status", class: data.flags.length ? "alert alert-warning" : "form-text" },
                data.flags.length
                    ? data.flags.map((flag) => labels[flag] || flag).join(" · ")
                    : "No configured time-policy exceptions found.",
            ),
            node(
                "p",
                {},
                `Daily limit: ${r.daily_minutes ?? "Not set"}${r.daily_minutes ? " minutes" : ""}. Weekly limit: ${r.weekly_minutes ?? "Not set"}${r.weekly_minutes ? " minutes" : ""}. ${r.mode === "exception_required" ? "Flagged approvals require an exception duty and reason." : "Flags are advisory."}`,
            ),
            node(
                "p",
                { class: "form-text" },
                data.scope === "rejection"
                    ? "This rejection retains only the selected interval; it does not approve weekly time-policy totals."
                    : r.daily_minutes === null && r.weekly_minutes === null && !r.overlaps
                      ? "No checks are configured; displayed totals cover the selected entry only."
                      : "Totals cover the days and weeks touched by this entry across the employee's positions.",
            ),
            node(
                "div",
                {
                    class: "table-responsive",
                    tabindex: "0",
                    role: "region",
                    "aria-label": "Time-policy totals",
                },
                table(
                    ["Period", "Elapsed time"],
                    [
                        ...data.days.map((d) => [d.date, hours(d.seconds)]),
                        ...data.weeks.map((w) => [`Week of ${w.week}`, hours(w.seconds)]),
                    ],
                    "Time-policy totals",
                ),
            ),
        );
    }
    async function open() {
        const body = openDrawer("Timesheet review policy", "INSTITUTION SETUP", { record: null });
        const workspaceReturn = () =>
            backToWorkspace
                ? button("Back to Team & timesheets", backToWorkspace, "btn-link mb-3")
                : null;
        const stage = (title) => {
            document.getElementById("drawer-title").textContent = title;
            const location = document.querySelector("#drawer-body .record-task-location");
            if (location) location.textContent = title;
        };
        const denied = () => {
            stage("Policy access unavailable");
            body.replaceChildren(
                ...[
                    workspaceReturn(),
                    node(
                        "p",
                        { role: "status" },
                        "Timesheet review policy access is no longer available.",
                    ),
                ].filter(Boolean),
            );
        };
        const loading = node("p", { role: "status" }, "Loading policy…");
        body.append(...[workspaceReturn(), loading].filter(Boolean));
        let data;
        try {
            data = await request("/sis/time-policy/");
        } catch (failure) {
            if (!body.isConnected) return;
            if (failure.status === 403) denied();
            else
                body.replaceChildren(
                    ...[
                        workspaceReturn(),
                        node(
                            "p",
                            { role: "alert" },
                            "The current policy could not be loaded. Try again.",
                        ),
                        button("Retry policy read", open),
                    ].filter(Boolean),
                );
            return;
        }
        if (!body.isConnected) return;
        const form = node("form", {
            class: "record-form time-policy-form",
            "aria-label": "Timesheet review policy",
        });
        const group = (label, explanation) => {
            const grid = node("div", { class: "record-form-grid" });
            form.append(
                node(
                    "fieldset",
                    { class: "record-form-section" },
                    node("legend", {}, label),
                    node("p", { class: "form-text" }, explanation),
                    grid,
                ),
            );
            return grid;
        };
        const field = (container, label, control, help = "", wide = false) => {
            const hint = help
                ? node("p", { id: `${control.id}-help`, class: "form-text mb-0" }, help)
                : null;
            if (hint) control.setAttribute("aria-describedby", hint.id);
            container.append(
                node(
                    "div",
                    { class: `record-form-field${wide ? " record-form-wide" : ""}` },
                    node("label", { for: control.id, class: "form-label" }, label),
                    control,
                    ...(hint ? [hint] : []),
                ),
            );
        };
        const time = group(
            "Clocked-time review",
            "These thresholds flag recorded work for review across an employee’s positions. Pending and approved time count; rejected time is excluded. Blank limits leave that threshold unset.",
        );
        const fields = {};
        for (const [key, label, maximum] of [
            ["daily_minutes", "Daily limit in minutes (optional)", 1500],
            ["weekly_minutes", "Weekly limit in minutes (optional)", 10140],
        ]) {
            fields[key] = node("input", {
                id: `time-policy-${key}`,
                type: "number",
                class: "form-control",
                min: 1,
                max: maximum,
                step: 1,
                value: data.rules[key] ?? "",
            });
            field(time, label, fields[key]);
        }
        const mode = node(
            "select",
            { id: "time-policy-mode", class: "form-select" },
            node("option", { value: "advisory" }, "Advisory warnings"),
            node("option", { value: "exception_required" }, "Require exception approval"),
        );
        mode.value = data.rules.mode;
        field(
            time,
            "How flags affect approval",
            mode,
            "Advisory flags allow the normal HR decision. Required exceptions also need the separate exception duty and a reason.",
            true,
        );
        const overlap = node("input", {
            type: "checkbox",
            id: "time-policy-overlap",
            class: "form-check-input",
            checked: data.rules.overlaps,
        });
        time.append(
            node(
                "div",
                { class: "record-form-field record-form-wide form-check" },
                overlap,
                node(
                    "label",
                    { for: overlap.id, class: "form-check-label" },
                    "Flag overlapping work intervals",
                ),
            ),
        );
        const zone = node("input", {
            id: "time-policy-timezone",
            class: "form-control",
            value: data.rules.timezone,
            required: true,
            maxlength: 80,
        });
        field(
            time,
            "Institutional timezone",
            zone,
            "Use an institution timezone such as America/Los_Angeles. It determines the calendar days and weeks used for these checks.",
            true,
        );
        const teaching = group(
            "Teaching contact hours",
            "A contact hour is a teaching measure, separate from clocked work. Enter the institution’s instructional-minute unit and rounding rule for a typical teaching week.",
        );
        const contact = node("input", {
            type: "number",
            id: "contact-unit-minutes",
            class: "form-control",
            min: 1,
            max: 120,
            step: 1,
            required: true,
            value: data.rules.contact_unit_minutes,
        });
        field(teaching, "Instructional minutes per contact hour", contact);
        const roundingLabels = {
            nearest: "Nearest thousandth (half up)",
            down: "Round down to thousandth",
            up: "Round up to thousandth",
        };
        const rounding = node(
            "select",
            { id: "contact-rounding", class: "form-select" },
            ...Object.entries(roundingLabels).map(([value, label]) =>
                node("option", { value }, label),
            ),
        );
        rounding.value = data.rules.contact_rounding;
        field(teaching, "Contact-hours rounding", rounding);
        teaching.append(
            node(
                "p",
                { class: "form-text record-form-wide mb-0" },
                "Weekly contact hours = section weekly instructional minutes ÷ instructional minutes per contact hour. With no published policy, the default is 50 minutes, rounded to the nearest thousandth. This does not calculate timesheet work or payroll.",
            ),
        );
        const publication = group(
            "Policy publication",
            "Review all settings before confirming. New approvals use the published policy; previously recorded decisions and published offerings retain their original policy evidence.",
        );
        const reason = node("textarea", {
            id: "time-policy-reason",
            class: "form-control",
            maxlength: 1000,
            rows: 3,
            required: true,
        });
        field(
            publication,
            "Reason for policy publication",
            reason,
            "Explain why the institution is setting or changing these rules.",
            true,
        );
        const error = node("div", { role: "alert", class: "time-policy-error", tabindex: "-1" });
        const submit = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review time policy",
        );
        form.append(
            error,
            node(
                "div",
                { class: "inline-actions time-policy-actions" },
                submit,
                button("Reload current policy", open),
            ),
            node(
                "p",
                { class: "form-text" },
                "Reload current policy replaces these unsaved settings.",
            ),
        );
        const context = () =>
            node(
                "section",
                { class: "time-policy-context", "aria-label": "Current policy" },
                node(
                    "h3",
                    { class: "h6" },
                    data.publication_id ? `Published policy ${data.version}` : "Default settings",
                ),
                node(
                    "p",
                    { class: "form-text mb-0" },
                    data.publication_id
                        ? "You are editing a new policy based on this publication. Earlier decisions keep their recorded settings."
                        : "No institutional policy has been published. The defaults below apply until publication.",
                ),
            );
        const edit = () => {
            if (!body.isConnected) return;
            stage("Timesheet review policy");
            body.replaceChildren(...[workspaceReturn(), context(), form].filter(Boolean));
        };
        edit();
        const minutes = (value) => (value == null ? "Not set" : `${value} minutes`);
        const reviewSection = (label, rows) =>
            node(
                "section",
                { class: "time-policy-review-section", "aria-label": label },
                node("h3", { class: "h6" }, label),
                recordSummary(rows),
            );
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.isConnected || !form.reportValidity()) return;
                error.textContent = "";
                const rules = {
                    contact_unit_minutes: Number(contact.value),
                    contact_rounding: rounding.value,
                    daily_minutes:
                        fields.daily_minutes.value === ""
                            ? null
                            : Number(fields.daily_minutes.value),
                    weekly_minutes:
                        fields.weekly_minutes.value === ""
                            ? null
                            : Number(fields.weekly_minutes.value),
                    overlaps: overlap.checked,
                    mode: mode.value,
                    timezone: zone.value,
                };
                try {
                    const review = await request("/sis/time-policy/review/", "POST", {
                        rules,
                        version: data.version,
                        reason: reason.value,
                    });
                    if (!body.isConnected) return;
                    const reviewedRules = review.rules;
                    stage("Review timesheet policy");
                    const message = node("div", {
                        role: "alert",
                        class: "time-policy-error",
                        tabindex: "-1",
                    });
                    body.replaceChildren(
                        button("Back to policy settings", edit, "btn-link mb-3"),
                        node(
                            "p",
                            { class: "form-text" },
                            "Review both calculations and the publication reason before confirming. This review expires in twenty minutes.",
                        ),
                        reviewSection("Clocked-time review", [
                            ["Daily limit", minutes(reviewedRules.daily_minutes)],
                            ["Weekly limit", minutes(reviewedRules.weekly_minutes)],
                            [
                                "Overlapping intervals",
                                reviewedRules.overlaps ? "Flag for review" : "No overlap flag",
                            ],
                            [
                                "Approval rule",
                                reviewedRules.mode === "advisory"
                                    ? "Advisory warnings"
                                    : "Exception approval required",
                            ],
                            ["Institutional timezone", reviewedRules.timezone],
                        ]),
                        reviewSection("Teaching contact hours", [
                            ["Instructional minutes per contact hour", reviewedRules.contact_unit_minutes],
                            ["Contact-hours rounding", roundingLabels[reviewedRules.contact_rounding]],
                            ["Period", "Typical teaching week"],
                        ]),
                        node(
                            "section",
                            {
                                class: "time-policy-review-section",
                                "aria-label": "Publication reason",
                            },
                            node("h3", { class: "h6" }, "Publication reason"),
                            node("p", { class: "time-policy-reason" }, review.reason),
                        ),
                        node(
                            "p",
                            { class: "form-text" },
                            "New approvals use this policy. Previously recorded decisions and published offerings retain their original policy and evidence.",
                        ),
                        message,
                        node(
                            "div",
                            { class: "inline-actions time-policy-actions" },
                            button(
                                "Confirm time policy",
                                async () => {
                                    try {
                                        await request("/sis/time-policy/publish/", "POST", review);
                                        if (!body.isConnected) return;
                                        stage("Timesheet policy published");
                                        body.replaceChildren(
                                            ...[
                                                workspaceReturn(),
                                                node(
                                                    "p",
                                                    {
                                                        role: "status",
                                                        class: "alert alert-success",
                                                    },
                                                    "Timesheet policy published.",
                                                ),
                                                button("View current policy", open),
                                            ].filter(Boolean),
                                        );
                                    } catch (failure) {
                                        if (!body.isConnected) return;
                                        if (failure.status === 403) denied();
                                        else {
                                            message.textContent = failure.message;
                                            message.focus();
                                        }
                                    }
                                },
                                "btn-primary",
                            ),
                        ),
                    );
                } catch (failure) {
                    if (!body.isConnected) return;
                    if (failure.status === 403) denied();
                    else if (form.isConnected) {
                        error.textContent = failure.message;
                        error.focus();
                    }
                }
            }, submit);
        });
    }
    return { open, summary };
};
