window.createTimeReviewPolicy = function (ui) {
    "use strict";
    const { node, request, task, openDrawer, table } = ui;
    const button = (label, run) =>
        node(
            "button",
            {
                type: "button",
                class: "btn btn-outline-primary",
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
        const body = openDrawer("Timesheet review policy", "INSTITUTION SETUP");
        body.append(node("p", { role: "status" }, "Loading policy…"));
        const data = await request("/sis/time-policy/");
        if (!body.isConnected) return;
        const fields = {};
        const form = node("form", {
            class: "record-form",
            "aria-label": "Timesheet review policy",
        });
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
            form.append(
                node("label", { for: fields[key].id, class: "form-label" }, label),
                fields[key],
            );
        }
        const mode = node(
            "select",
            { id: "time-policy-mode", class: "form-select" },
            node("option", { value: "advisory" }, "Advisory warnings"),
            node("option", { value: "exception_required" }, "Require exception approval"),
        );
        mode.value = data.rules.mode;
        const overlap = node("input", {
            type: "checkbox",
            id: "time-policy-overlap",
            class: "form-check-input",
            checked: data.rules.overlaps,
        });
        const zone = node("input", {
            id: "time-policy-timezone",
            class: "form-control",
            value: data.rules.timezone,
            required: true,
            maxlength: 80,
        });
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
        const rounding = node(
            "select",
            { id: "contact-rounding", class: "form-select" },
            ...["nearest", "down", "up"].map((value) =>
                node(
                    "option",
                    { value },
                    value === "nearest"
                        ? "Nearest thousandth (half up)"
                        : value === "down"
                          ? "Round down to thousandth"
                          : "Round up to thousandth",
                ),
            ),
        );
        rounding.value = data.rules.contact_rounding;
        form.append(
            node("h3", { class: "h6" }, "Teaching contact-hours calculation"),
            node(
                "label",
                { for: contact.id, class: "form-label" },
                "Instructional minutes per contact hour",
            ),
            contact,
            node("label", { for: rounding.id, class: "form-label" }, "Contact-hours rounding"),
            rounding,
            node(
                "p",
                { class: "form-text" },
                "Weekly contact hours = section weekly instructional minutes ÷ this unit. The fallback is 50 minutes, rounded to the nearest thousandth. This never converts contact hours into timesheet work or payroll.",
            ),
        );
        const reason = node("textarea", {
            id: "time-policy-reason",
            class: "form-control",
            maxlength: 1000,
            required: true,
        });
        const error = node("div", { role: "alert", class: "text-danger" });
        const submit = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review time policy",
        );
        form.append(
            node("label", { for: mode.id, class: "form-label" }, "How flags affect approval"),
            mode,
            node(
                "div",
                { class: "form-check" },
                overlap,
                node(
                    "label",
                    { for: overlap.id, class: "form-check-label" },
                    "Flag overlapping work intervals",
                ),
            ),
            node("label", { for: zone.id, class: "form-label" }, "Institutional timezone"),
            zone,
            node("label", { for: reason.id, class: "form-label" }, "Reason for policy publication"),
            reason,
            error,
            submit,
        );
        const edit = () =>
            body.replaceChildren(
                node(
                    "p",
                    {},
                    "Set institutional review thresholds. Blank limits mean no threshold. These rules do not calculate payroll or convert teaching contact hours to clocked work.",
                ),
                form,
            );
        edit();
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
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
                    const message = node("div", { role: "alert", class: "text-danger" });
                    body.replaceChildren(
                        node("h3", {}, "Review institutional time policy"),
                        node(
                            "p",
                            {},
                            `Daily: ${rules.daily_minutes ?? "Not set"} minutes · Weekly: ${rules.weekly_minutes ?? "Not set"} minutes · Overlap checks: ${rules.overlaps ? "Yes" : "No"} · ${rules.mode === "advisory" ? "Advisory" : "Exception approval required"} · ${rules.timezone} · Contact hour: ${rules.contact_unit_minutes} instructional minutes, rounding ${rules.contact_rounding}`,
                        ),
                        node("p", {}, review.reason),
                        node(
                            "p",
                            { class: "form-text" },
                            "New approvals use this policy. Previously recorded decisions retain their original policy and evidence. This review expires in twenty minutes.",
                        ),
                        message,
                        button("Confirm time policy", async () => {
                            try {
                                await request("/sis/time-policy/publish/", "POST", review);
                                if (!body.isConnected) return;
                                body.replaceChildren(
                                    node(
                                        "p",
                                        { role: "status", class: "alert alert-success" },
                                        "Timesheet policy published.",
                                    ),
                                    button("View current policy", open),
                                );
                            } catch (failure) {
                                if (body.isConnected) message.textContent = failure.message;
                            }
                        }),
                        button("Revise policy", edit),
                    );
                } catch (failure) {
                    if (form.isConnected) error.textContent = failure.message;
                }
            }, submit);
        });
    }
    return { open, summary };
};
