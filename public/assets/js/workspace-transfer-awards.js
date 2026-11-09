/* Registrar corrections preserve the posted award and an encrypted history of every reviewed change. */
window.createWorkspaceTransferAwards = function (ui) {
    "use strict";
    const { node, request, task, table, buildForm, formData, openDrawer } = ui;
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
    const state = (credit) => (credit.status === "V" ? "Voided" : "Awarded");
    const title = (action) =>
        ({ amend: "Correct credits or grade", void: "Void this award", reinstate: "Reinstate this award" })[action];
    const endpoint = (student, award) => `/sis/students/${student}/transfer-awards/${award}/`;
    function identity(record) {
        return node(
            "div",
            { class: "record-section" },
            node("h3", {}, `${record.given_name} ${record.family_name} · ${record.institutional_id}`),
            node("p", {}, `${record.credit.course_code} · ${record.credit.course_title}`),
            node(
                "p",
                { class: "form-text" },
                `${record.institution_name} · ${record.external_code} · Completed ${record.completed_on} · Posting term ${record.term_code}`,
            ),
        );
    }
    function comparison(before, after) {
        return grid(
            ["Academic record", "Current", "After confirmation"],
            [
                ["Status", state(before), state(after)],
                ["Attempted credits", before.attempted_milli / 1000, after.attempted_milli / 1000],
                ["Earned credits", before.completed_milli / 1000, after.completed_milli / 1000],
                [
                    "Grade",
                    before.status === "V" ? "Voided" : before.grade_code || "—",
                    after.status === "V" ? "Voided" : after.grade_code || "—",
                ],
                ["Grade points", before.grade_points_milli / 1000, after.grade_points_milli / 1000],
                ["Counts toward GPA", before.counts_gpa ? "Yes" : "No", after.counts_gpa ? "Yes" : "No"],
            ],
            "Transfer correction comparison",
        );
    }
    async function record(student, award) {
        const value = await request(endpoint(student, award));
        const body = openDrawer("Posted transfer credit", "REGISTRAR REVIEW");
        body.append(
            identity(value),
            grid(
                ["Credits", "Grade", "Status", "Revision"],
                [
                    [
                        value.credit.completed_milli / 1000,
                        value.credit.status === "V" ? "Voided" : value.grade_code || "—",
                        state(value.credit),
                        value.credit.version + 1,
                    ],
                ],
                "Current transfer award",
            ),
            node(
                "p",
                { class: "form-text" },
                "Correct this student’s awarded credits or grade with a reviewed reason. Catalog equivalencies and other students’ awards keep their values.",
            ),
        );
        const actions = node("div", { class: "inline-actions" });
        (value.credit.status === "V" ? ["reinstate"] : ["amend", "void"]).forEach((action) =>
            actions.append(button(title(action), () => edit(student, award, value, action))),
        );
        actions.append(
            button("Correction history", () => history(student, award)),
            button("Back to equivalency", () => ui.back(value.equivalency_id)),
        );
        body.append(actions);
    }
    async function edit(student, award, value, action, preset = null) {
        const body = openDrawer(title(action), "REVIEW → CONFIRM → SAVED RECEIPT");
        body.append(
            identity(value),
            node(
                "p",
                {},
                action === "void"
                    ? "Voiding removes this award’s attempted credits, earned credits and GPA contribution. Its history remains available."
                    : "Enter the corrected award values and a reason. The system calculates grade points from the selected grade.",
            ),
        );
        const fields = [
            ...(action === "void"
                ? []
                : [
                      ["credits_milli", "Credits awarded", "credits"],
                      ["awarded_grade", "Awarded grade code"],
                  ]),
            ["reason", "Registrar reason", "textarea"],
        ];
        const initial = preset || {
            credits_milli: value.credit.status === "V" ? undefined : value.credit.completed_milli,
            awarded_grade: value.grade_code || "",
        };
        const form = await buildForm(fields, {
            ...initial,
            credits_milli: initial.credits_milli == null ? undefined : Number(initial.credits_milli) / 1000,
        });
        if (action !== "void") {
            form.elements.credits_milli.min = 0.001;
            form.elements.credits_milli.max = 999.999;
            form.elements.awarded_grade.maxLength = 12;
        }
        const submit = node("button", { type: "submit", class: "btn btn-primary" }, "Review proposed correction");
        form.append(
            node(
                "div",
                { class: "inline-actions" },
                submit,
                button("Back to award", () => record(student, award)),
            ),
        );
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const data = { ...formData(form, fields), action };
                const review = await request(endpoint(student, award) + "preview/", "POST", data);
                if (form.isConnected) await confirm(student, award, review);
            }, submit);
        });
        body.append(form);
    }
    async function confirm(student, award, review) {
        const operation = (await request("/sis/ui/operation-id/")).id;
        const payload = { ...review.request, revision: review.revision, operation_id: operation };
        const body = openDrawer("Confirm transfer correction", "REVIEWED ACADEMIC CHANGE");
        body.append(
            identity(review.record),
            comparison(review.before, review.after),
            node("h3", {}, "Registrar reason"),
            node("p", { class: "private-text" }, review.request.reason),
            node(
                "p",
                { class: "form-text" },
                "Future prerequisite checks and newly prepared transcripts use the corrected award. Existing enrollments, posted charges and previously generated documents retain their history. A prepared transcript must be reviewed again before delivery.",
            ),
        );
        const status = node("p", { role: "status", "aria-live": "polite" });
        let attempted = false;
        const change = button("Change correction", () =>
            edit(student, award, review.record, review.request.action, review.request),
        );
        const submit = button(
            "Confirm academic correction",
            async () => {
                attempted = true;
                change.disabled = true;
                status.textContent = `Saving correction ${operation}. After an interrupted connection, retry with this reference or check the saved correction.`;
                let receipt;
                try {
                    receipt = await request(endpoint(student, award) + "confirm/", "POST", payload);
                } catch (error) {
                    if ([400, 403, 404, 409, 422].includes(error.status)) {
                        attempted = false;
                        change.disabled = false;
                    }
                    throw error;
                }
                if (body.isConnected) saved(student, award, receipt);
            },
            "btn-primary",
        );
        const check = button("Check saved correction", async () => {
            if (!attempted) throw new Error("Confirm this reviewed correction before checking its receipt.");
            const receipt = await request(endpoint(student, award) + `changes/${operation}/`);
            if (body.isConnected) saved(student, award, receipt);
        });
        body.append(
            status,
            node(
                "div",
                { class: "inline-actions" },
                submit,
                check,
                change,
                button("Review current award", () => record(student, award)),
            ),
        );
    }
    function saved(student, award, receipt) {
        const body = openDrawer("Transfer correction saved", "ACADEMIC RECEIPT");
        body.append(
            node(
                "p",
                { role: "status" },
                `Saved ${receipt.action === "amend" ? "correction" : receipt.action === "void" ? "void" : "reinstatement"} at academic record revision ${receipt.version + 1}.`,
            ),
            node("p", {}, "Reference: ", node("code", {}, receipt.operation_id)),
            node(
                "div",
                { class: "inline-actions" },
                button("Review current award", () => record(student, award)),
                button("Correction history", () => history(student, award)),
            ),
        );
    }
    async function history(student, award, after = "", stack = []) {
        const result = await request(endpoint(student, award) + "history/", "POST", { after });
        const body = openDrawer("Transfer correction history", "PRIVATE REGISTRAR HISTORY");
        body.append(
            button("Back to award", () => record(student, award)),
            node(
                "p",
                { class: "form-text" },
                "Newest revisions first. Reasons and before/after snapshots are restricted to transfer staff with academic read access.",
            ),
        );
        result.items.forEach((item) => {
            body.append(
                node(
                    "section",
                    { class: "record-section" },
                    node(
                        "h3",
                        {},
                        `${item.action === "amend" ? "Correction" : item.action === "void" ? "Voided" : "Reinstated"} · Revision ${item.version + 1}`,
                    ),
                    node("p", { class: "form-text" }, `${item.created_at} · Staff reference ${item.actor_id}`),
                    node("p", { class: "private-text" }, item.reason),
                    comparison(item.before, item.after),
                ),
            );
        });
        if (!result.items.length)
            body.append(node("p", {}, "This award has no corrections. Its original posted values remain current."));
        const navigation = node("div", { class: "inline-actions" });
        if (stack.length)
            navigation.append(
                button("Previous correction page", () => history(student, award, stack.at(-1), stack.slice(0, -1))),
            );
        if (result.next_after)
            navigation.append(
                button("Next correction page", () => history(student, award, result.next_after, [...stack, after])),
            );
        body.append(navigation);
    }
    return { record };
};
