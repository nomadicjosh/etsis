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
        body.append(
            node(
                "div",
                { class: "d-flex flex-wrap gap-2" },
                button("Back to student", back),
                button(
                    "Confirm course drop",
                    async () =>
                        finish(
                            await request(
                                "/sis/registrations/drop/",
                                "POST",
                                command,
                            ),
                        ),
                    true,
                ),
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
    async function policy(term, back) {
        const data = await request(`/sis/terms/${term.id}/drop-policy/`);
        const body = openDrawer("Drop / withdrawal policy", "ACADEMIC TERM");
        body.append(
            node("h3", {}, data.term.code),
            node(
                "p",
                {},
                `Through ${data.term.add_drop_ends_on}, courses are removed and their original course charges reversed. After that date, the periods below assign a grade and retain charges. All late drops earn zero credits.`,
            ),
            node(
                "p",
                { class: "form-text" },
                "Use grade codes already defined in the academic catalog. Their GPA settings apply. Cutoff dates are inclusive in the institution’s timezone. The final period covers all later dates.",
            ),
        );
        if (data.default_policy)
            body.append(
                node(
                    "p",
                    { class: "alert alert-info" },
                    "Currently using the default W marker, excluded from GPA. Save explicit periods to use your catalog grade definitions.",
                ),
            );
        let windows = data.windows.map((window) => ({ ...window }));
        const periods = node("div");
        const status = node("p", { role: "status", "aria-live": "polite" });
        let forms = [];
        const collect = () =>
            forms.map((form, index) => ({
                through:
                    index === forms.length - 1
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
                // Each period is a field group; a single explicit Save controls submission.
                form.addEventListener("submit", (event) =>
                    event.preventDefault(),
                );
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
            status.textContent = `${windows.length} grade period${windows.length === 1 ? "" : "s"}.`;
        }
        await render();
        body.append(
            periods,
            status,
            node(
                "div",
                { class: "d-flex flex-wrap gap-2" },
                button("Add grade period", () => {
                    if (forms.length >= 12) return;
                    windows = collect();
                    windows.splice(windows.length - 1, 0, {
                        through: "",
                        grade_code: "W",
                    });
                    return render();
                }),
                button(
                    "Save drop policy",
                    async () => {
                        if (!forms.every((form) => form.reportValidity()))
                            return;
                        await request(
                            `/sis/terms/${term.id}/drop-policy/`,
                            "POST",
                            { version: data.version, windows: collect() },
                        );
                        notify("Term drop policy saved.");
                        await back();
                    },
                    true,
                ),
                button("Back to term", back),
            ),
        );
    }
    return { review, policy };
};
