/* Reviewed, version-bound course drops. Receipts survive early academic-row removal. */
window.createWorkspaceCourseDrops = function (ui) {
    "use strict";
    const { node, request, task, openDrawer, notify, buildForm, formData } = ui;
    const button = (label, run, primary = false) =>
        node(
            "button",
            {
                type: "button",
                class: `btn ${primary ? "btn-primary" : "btn-outline-primary"}`,
                onclick: (event) => task(run, event.currentTarget),
            },
            label,
        );
    async function review(student, section, back) {
        const plan = await request(
            `/sis/students/${student}/sections/${section}/drop-review/`,
            "POST",
            {},
        );
        const body = openDrawer("Review course drop", "REGISTRATION");
        const early = plan.outcome.delete_records;
        body.append(
            node("h3", {}, `${plan.section_code} · ${plan.outcome.term_code}`),
            node(
                "p",
                {},
                `${plan.given_name} ${plan.family_name} · ${plan.institutional_id}`,
            ),
            node(
                "p",
                {},
                `Effective date: ${plan.effective_on}. Drop/add ends ${plan.outcome.grace_ends_on}.`,
            ),
            node(
                "p",
                { class: "alert alert-info" },
                early
                    ? "The enrollment and academic credit will be removed. The original course charge, if present, will be reversed. Any resulting account credit is reviewed separately for a cash refund."
                    : `The course stays on the academic record with grade ${plan.outcome.grade_code} and zero earned credits. Its original course charge stays payable.`,
            ),
            node(
                "p",
                { class: "form-text" },
                early
                    ? "This does not issue a cash refund or reverse separate institutional fees."
                    : `GPA treatment: ${plan.outcome.counts_gpa ? "counts toward GPA under the catalog grade definition" : "excluded from GPA"}.`,
            ),
        );
        const command = Object.fromEntries(
            [
                "student_id",
                "section_id",
                "enrollment_id",
                "version",
                "operation_id",
                "revision",
                "effective_on",
            ].map((key) => [key, plan[key]]),
        );
        let finished = false;
        const finish = async (receipt) => {
            if (finished) return;
            finished = true;
            notify(
                receipt.academic_records_removed
                    ? "Course removed. Its course charge was reversed if present; cash refunds require a separate review."
                    : `Course withdrawn with grade ${receipt.grade_code}. Course charge retained.`,
            );
            await back();
        };
        const confirmation = button("Confirm course drop", async () => finish(
            await request("/sis/registrations/drop/", "POST", command)), true);
        confirmation.disabled = !!plan.corequisites?.blocked;
        if (plan.corequisites?.warning) body.append(node("div", {
            class: plan.corequisites.blocked ? "alert alert-danger" : "alert alert-warning", role: "status",
        }, node("p", {}, plan.corequisites.blocked
            ? "This drop would leave a required co-requisite unsupported. Review the linked courses together."
            : "This drop leaves a co-requisite unsupported. Institutional policy permits proceeding with this warning."),
        plan.corequisites.blocked ? button("Review linked drops", () => plans.start(student, back,
            [section, ...plan.corequisites.required_sections]), true) : null));
        body.append(
            node(
                "div",
                { class: "d-flex flex-wrap gap-2" },
                button("Back to student", back),
                confirmation,
                button("Check saved drop", async () =>
                    finish(
                        await request(
                            `/sis/course-drops/${plan.operation_id}/`,
                        ),
                    ),
                ),
            ),
        );
        body.append(
            node(
                "p",
                { class: "form-text" },
                "If the connection is interrupted, check the saved drop or retry this same confirmation. A retry cannot drop a newly added enrollment.",
            ),
        );
    }
    async function policy(term, back, offering = null) {
        const path = offering ? `/sis/terms/${term.id}/sections/${offering.id}/drop-policy/`
            : `/sis/terms/${term.id}/drop-policy/`;
        const drawer = openDrawer("Drop / withdrawal policy", offering ? "COURSE SECTION" : "ACADEMIC TERM");
        const body = node("div");
        drawer.append(body);
        const data = await request(path);
        if (body.isConnected === false) return;
        let view = 0;
        let active = true;
        const leave = async () => {
            active = false;
            await back();
        };
        const summary = (definition) =>
            node(
                "div",
                {},
                node(
                    "p",
                    {},
                    definition.uses_term_policy ? "Follow the term policy, including later term-policy publications." :
                        `Drop/add ends ${definition.grace_ends_on}. Dates use ${definition.timezone}.`,
                ),
                node(
                    "ul",
                    {},
                    ...definition.windows.map((w) =>
                        node(
                            "li",
                            {},
                            `${w.through ? `Through ${w.through}` : "All later dates"}: ${w.grade_code}`,
                        ),
                    ),
                ),
            );
        body.append(
            node("h3", {}, offering ? `${data.section.code} · ${data.term.code}` : data.term.code),
            node(
                "p",
                {},
                `Term drop/add deadline: ${data.term.add_drop_ends_on}. During drop/add, courses are removed and original course charges reversed. Later drops retain charges and receive the applicable grade, with zero earned credits.`,
            ),
            node(
                "p",
                { class: "form-text" },
                "Use catalog grade codes. Each student's retained grading scale determines GPA treatment. Cutoffs are inclusive; the final period covers all later dates.",
            ),
        );
        if (data.published && !data.uses_term_policy)
            body.append(
                node(
                    "section",
                    {
                        class: "border rounded-3 p-3 mb-3",
                        "aria-label": "Current published policy",
                    },
                    node("h4", {}, `Published revision ${data.version + 1}`),
                    summary(data.published),
                ),
            );
        if (data.calendar_changed)
            body.append(
                node(
                    "p",
                    { class: "alert alert-warning" },
                    "The term dates or timezone have changed. Date edits do not replace the published deadline. Review and publish the updated setup for future drops. A timezone change requires publication before new drops can proceed. Completed drops remain unchanged.",
                ),
            );
        if (data.default_policy)
            body.append(
                node(
                    "p",
                    { class: "alert alert-info" },
                    "The default W marker is excluded from GPA. Publish explicit periods to use catalog grade definitions.",
                ),
            );
        else if (!data.published && !offering)
            body.append(
                node(
                    "p",
                    { class: "alert alert-info" },
                    "These previously saved periods have no retained publication. Their earlier approval details are unknown. Publishing retains a new reviewed definition.",
                ),
            );
        const source = offering ? node("select", { id: "drop-policy-source", class: "form-select" },
            node("option", { value: "inherit" }, "Use term policy"),
            node("option", { value: "override" }, "Override for this section")) : null;
        if (source) source.value = data.uses_term_policy ? "inherit" : "override";
        const deadline = offering ? node("input", { id: "section-drop-deadline", type: "date", class: "form-control",
            value: data.grace_ends_on, required: true }) : null;
        const inherits = () => !!source && source.value === "inherit";
        if (offering) body.append(
            node("div", { class: "mb-3" }, node("label", { for: source.id, class: "form-label" }, "Policy source"), source),
            node("p", { class: "form-text" }, "An override controls this section's future drops only. Registration dates, teaching dates and completed decisions stay separate."),
            node("div", { class: "mb-3" }, node("label", { for: deadline.id, class: "form-label" }, "Section drop/add deadline"), deadline),
        );
        let windows = data.windows.map((w) => ({ ...w }));
        const periods = node("div");
        const status = node("p", { role: "status", "aria-live": "polite" });
        const reason = node("textarea", {
            id: "drop-policy-reason",
            class: "form-control",
            rows: 3,
            maxlength: 1000,
            required: true,
        });
        const reasonField = node(
            "div",
            { class: "mb-3" },
            node(
                "label",
                { for: "drop-policy-reason", class: "form-label" },
                "Reason for policy change",
            ),
            reason,
        );
        let forms = [];
        const addPeriod = button("Add grade period", () => {
            if (forms.length >= 12 || inherits()) return;
            windows = collect();
            windows.splice(windows.length - 1, 0, { through: "", grade_code: "W" });
            return render();
        });
        const collect = () =>
            forms.map((form, i) => ({
                through:
                    i === forms.length - 1
                        ? null
                        : form.elements.namedItem("through").value,
                grade_code: form.elements
                    .namedItem("grade_code")
                    .value.trim()
                    .toUpperCase(),
            }));
        async function render() {
            periods.replaceChildren();
            forms = [];
            for (const [index, window] of windows.entries()) {
                const last = index === windows.length - 1;
                const fields = [
                    ...(last
                        ? []
                        : [["through", "Through date (inclusive)", "date"]]),
                    ["grade_code", "Catalog grade code"],
                ];
                const form = await buildForm(fields, window);
                form.addEventListener("submit", (e) => e.preventDefault());
                forms.push(form);
                const block = node(
                    "section",
                    {
                        class: "border rounded-3 p-3 mb-3",
                        "aria-label": `Grade period ${index + 1}`,
                    },
                    node(
                        "h4",
                        {},
                        last ? "All later dates" : `Period ${index + 1}`,
                    ),
                    form,
                );
                if (!last)
                    block.append(
                        button("Remove period", () => {
                            windows = collect();
                            windows.splice(index, 1);
                            return render();
                        }),
                    );
                periods.append(block);
            }
            updateSource();
        }
        function updateSource() {
            if (deadline) deadline.disabled = inherits();
            periods.hidden = inherits();
            addPeriod.disabled = inherits();
            status.textContent = inherits() ? `Currently follows the term deadline: ${data.grace_ends_on}. Future term-policy updates also apply.`
                : `${windows.length} grade period${windows.length === 1 ? "" : "s"}.`;
        }
        if (source) source.addEventListener("change", updateSource);
        await render();
        const edit = () => {
            view++;
            body.replaceChildren(...editor);
            reason.focus();
        };
        const heading = (text) => {
            const h = node("h3", { tabindex: "-1" }, text);
            return h;
        };
        async function history() {
            const currentView = ++view;
            const result = await request(path + "history/");
            if (!active || body.isConnected === false || currentView !== view)
                return;
            const title = heading("Policy history");
            const entries = node("div");
            const count = node("p", { role: "status", "aria-live": "polite" });
            let shown = 0;
            function append(page) {
                for (const item of page.items) {
                    const section = node(
                        "section",
                        { class: "border rounded-3 p-3 mb-3" },
                        node("h4", {}, `Revision ${item.version + 1}`),
                        node(
                            "p",
                            { class: "form-text" },
                            `Published ${item.recorded_at}`,
                        ),
                        summary(item.definition),
                    );
                    if (item.observed_previous_policy)
                        section.append(
                            node(
                                "p",
                                { class: "form-text" },
                                "Earlier saved periods were observed at this publication. Their original approval and dates are unknown.",
                            ),
                            node(
                                "ul",
                                {},
                                ...item.observed_previous_policy.windows.map(
                                    (w) =>
                                        node(
                                            "li",
                                            {},
                                            `${w.through ? `Through ${w.through}` : "All later dates"}: ${w.grade_code}`,
                                        ),
                                ),
                            ),
                        );
                    entries.append(section);
                    shown++;
                }
                count.textContent = shown
                    ? `${shown} retained publication${shown === 1 ? "" : "s"}.`
                    : "No retained publications yet.";
            }
            let cursor = result.next_cursor;
            const more = button("Load more", async () => {
                const next = await request(
                    path + "history/?cursor=" + encodeURIComponent(cursor),
                );
                if (
                    !active ||
                    body.isConnected === false ||
                    currentView !== view
                )
                    return;
                append(next);
                cursor = next.next_cursor;
                more.hidden = !cursor;
            });
            append(result);
            more.hidden = !cursor;
            body.replaceChildren(
                title,
                count,
                entries,
                more,
                button("Back to policy", edit),
            );
            title.focus();
        }
        body.append(
            periods,
            status,
            reasonField,
            node(
                "div",
                { class: "d-flex flex-wrap gap-2" },
                addPeriod,
                button(
                    "Review policy",
                    async () => {
                        if (
                            (!inherits() && !forms.every((f) => f.reportValidity())) ||
                            (!inherits() && deadline && !deadline.reportValidity()) || !reason.reportValidity()
                        )
                            return;
                        const currentView = ++view;
                        const reviewedReason = reason.value;
                        const input = () => ({ version: data.version, windows: inherits() ? data.windows : collect(),
                            reason: reason.value, ...(offering ? { uses_term_policy: inherits(),
                                grace_ends_on: inherits() ? null : deadline.value } : {}) });
                        const submitted = input();
                        const review = await request(path + "review/", "POST", submitted);
                        if (
                            !active ||
                            body.isConnected === false ||
                            currentView !== view
                        )
                            return;
                        if (JSON.stringify(submitted) !== JSON.stringify(input())) {
                            status.textContent = "Your fields changed. Review the current policy again.";
                            return;
                        }
                        const title = heading("Review drop policy");
                        const notes = node(
                            "p",
                            {},
                            "This definition applies to new drop decisions after publication. Completed drops, their grades and financial results remain unchanged.",
                        );
                        let saved = false;
                        body.replaceChildren(
                            title,
                            summary(review.definition),
                            notes,
                            node("p", {}, `Reason: ${reviewedReason}`),
                            node(
                                "div",
                                { class: "d-flex flex-wrap gap-2" },
                                button("Revise", edit),
                                button(
                                    "Publish policy",
                                    async () => {
                                        if (saved) return;
                                        await request(
                                            path + "commit/",
                                            "POST",
                                            {
                                                review_token:
                                                    review.review_token,
                                            },
                                        );
                                        saved = true;
                                        notify(offering ? "Section drop policy published." : "Term drop policy published.");
                                        await leave();
                                    },
                                    true,
                                ),
                            ),
                        );
                        title.focus();
                    },
                    true,
                ),
                button("Policy history", history),
                button(offering ? "Back to section" : "Back to term", leave),
            ),
        );
        const editor = Array.from(body.childNodes);
    }
    const plans = window.createRegistrationDrops({ node, request, task,
        open: title => openDrawer(title, 'REGISTRATION'), prefix: '/sis/drop-plans/' });
    return { review, policy, plan: plans.start };
};
