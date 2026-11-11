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
        ({ amend: "Correct credits or grade", void: "Void this award", reinstate: "Reinstate this award" })[
            action
        ];
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
            node(
                "p",
                { class: "form-text" },
                record.course_version_id
                    ? "Accepted course publication: retained with this award."
                    : "Accepted course publication: unknown; no definition has been inferred from today's catalog.",
            ),
            node(
                "p",
                { class: "form-text" },
                `Sending institution's period start: ${record.academic_on || "Unknown"}`,
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
                ["Quality points", before.grade_points_milli / 1000, after.grade_points_milli / 1000],
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
        body.append(
            node(
                "p",
                { class: "alert alert-info" },
                value.earlier_history_unknown
                    ? "Earlier award decisions are unknown. Existing facts are preserved as an observation when a correction is confirmed; earlier saved receipts remain available."
                    : "Source details and grading policy are retained with the award’s recorded history.",
            ),
        );
        const actions = node("div", { class: "inline-actions" });
        (value.credit.status === "V" ? ["reinstate"] : ["amend", "void"]).forEach((action) =>
            actions.append(button(title(action), () => edit(student, award, value, action))),
        );
        actions.append(
            ...(value.credit.status !== "V" && value.history_recorded
                ? [button("Review source academic period", () => editPeriod(student, award, value))]
                : []),
            ...(value.credit.status !== "V"
                ? [button("Review accepted course", () => editCourse(student, award, value))]
                : []),
            button("Course definition history", () => courseHistory(student, award)),
            button("Source period history", () => periodHistory(student, award)),
            button("Correction history", () => history(student, award)),
            button("Back to equivalency", () => ui.back(value.equivalency_id)),
        );
        body.append(actions);
    }
    async function editCourse(student, award, value) {
        const base = endpoint(student, award) + "accepted-course/";
        const choices = await request(base + "choices/", "POST", {});
        if (!choices.items.length)
            throw new Error(
                "Catalog staff must publish the receiving course before reviewing its acceptance.",
            );
        const label = (item) =>
            `Version ${item.version} · ${item.code} · ${item.transcript_title} · ${item.credits_milli / 1000} catalog credits`;
        const body = openDrawer("Accepted course definition", "REASONED TRANSFER REVIEW");
        body.append(
            identity(value),
            node(
                "p",
                {},
                "Select the receiving-course publication supported by your evaluation. Its code and transcript title will be applied to this award. The existing credits, grade, sending institution and posting term stay as recorded. Previous decisions remain in history.",
            ),
            button("Back to award", () => record(student, award)),
        );
        if (!value.course_version_id)
            body.append(
                node(
                    "p",
                    { class: "alert alert-info" },
                    "The earlier accepted publication is unknown. This records a new reviewed acceptance; it does not reconstruct an earlier decision.",
                ),
            );
        const fields = [
            [
                "course_version_id",
                "Accepted course publication",
                "choice",
                choices.items.map((item) => ({ value: item.version_id, label: label(item) })),
            ],
            ["effective_on", "Effective date", "date"],
            ["reason", "Reason and acceptance evidence", "textarea"],
        ];
        const form = await buildForm(fields, { effective_on: value.effective_on_default });
        form.elements.effective_on.min = value.completed_on;
        form.elements.effective_on.max = value.effective_on_default;
        form.elements.reason.maxLength = 1000;
        let cursor = choices.next_cursor;
        const status = node(
            "p",
            { role: "status", class: "form-text" },
            `${choices.items.length} publications loaded.`,
        );
        form.elements.course_version_id.parentElement.append(status);
        if (cursor) {
            const more = button("Load more accepted-course choices", async () => {
                const next = await request(base + "choices/", "POST", { cursor });
                if (!form.isConnected) return;
                next.items.forEach((item) =>
                    form.elements.course_version_id.append(
                        node("option", { value: item.version_id }, label(item)),
                    ),
                );
                cursor = next.next_cursor;
                status.textContent = `${form.elements.course_version_id.options.length - 1} publications loaded.`;
                if (!cursor) {
                    more.remove();
                    form.elements.course_version_id.focus();
                }
            });
            form.elements.course_version_id.parentElement.append(more);
        }
        const submit = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review accepted definition",
        );
        form.append(submit);
        body.append(form);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const data = formData(form, fields);
                const review = await request(base + "review/", "POST", {
                    ...data,
                    version: value.credit.version,
                });
                const confirm = openDrawer("Confirm accepted course", "PRESERVE ORIGINAL ACCEPTANCE");
                confirm.append(
                    grid(
                        ["Record", "Current", "After confirmation"],
                        [
                            ["Course code", review.before.course_code, review.after.course_code],
                            ["Transcript title", review.before.course_title, review.after.course_title],
                            [
                                "Publication",
                                review.previous_course_version_id ? "Retained" : "Unknown",
                                "Explicitly selected",
                            ],
                            [
                                "Awarded credits",
                                value.credit.completed_milli / 1000,
                                value.credit.completed_milli / 1000,
                            ],
                            ["Grade", value.grade_code || "—", value.grade_code || "—"],
                        ],
                        "Accepted course comparison",
                    ),
                    node("p", {}, `Effective ${review.effective_on}`),
                    node("p", {}, review.reason),
                    node(
                        "p",
                        {},
                        "This can change course-group matching and the current transcript labels. Existing issued documents and earlier decisions remain preserved; a prepared transcript may need a fresh review.",
                    ),
                    button("Revise accepted course", () =>
                        openDrawer("Accepted course definition", "REASONED TRANSFER REVIEW").append(form),
                    ),
                    button(
                        "Confirm course acceptance",
                        async () => {
                            await request(base + "commit/", "POST", { review_token: review.review_token });
                            await record(student, award);
                        },
                        "btn-primary",
                    ),
                );
            }, submit);
        });
    }
    async function courseHistory(student, award, cursor = null, stack = []) {
        const data = await request(endpoint(student, award) + "accepted-course/history/", "POST", { cursor });
        const body = openDrawer("Accepted course history", "RETAINED TRANSFER DECISIONS");
        body.append(button("Back to award", () => record(student, award)));
        if (data.earlier_history_unknown)
            body.append(
                node(
                    "p",
                    { class: "alert alert-info" },
                    "Earlier acceptance decisions remain unknown. Observed facts and later reviewed acceptances are recorded separately.",
                ),
            );
        body.append(
            data.items.length
                ? grid(
                      ["Decision", "Accepted course", "Publication", "Effective", "Recorded"],
                      data.items.map((item) => [
                          {
                              award: "Original award",
                              observed_existing: "Earlier facts observed",
                              course_accepted: "Course acceptance reviewed",
                          }[item.action],
                          `${item.course_code} · ${item.course_title}`,
                          item.course_version_id ? "Retained" : "Unknown",
                          item.effective_at || "Unknown",
                          item.recorded_at,
                      ]),
                      "Accepted course decisions",
                  )
                : node("p", {}, "No course acceptance decisions on this page."),
        );
        const actions = node("div", { class: "inline-actions" });
        if (stack.length)
            actions.append(
                button("Previous course history page", () =>
                    courseHistory(student, award, stack.at(-1), stack.slice(0, -1)),
                ),
            );
        if (data.next_cursor)
            actions.append(
                button("Next course history page", () =>
                    courseHistory(student, award, data.next_cursor, [...stack, cursor]),
                ),
            );
        body.append(actions);
    }
    async function editPeriod(student, award, value) {
        const body = openDrawer("Source academic period", "REVIEWED SENDING-INSTITUTION FACT");
        body.append(
            identity(value),
            node(
                "p",
                {},
                "Enter the sending institution's actual academic-period start for this course. Do not use the local posting term or infer it from completion. Leave blank only to explicitly mark a known date unknown.",
            ),
            button("Back to award", () => record(student, award)),
        );
        const fields = [
            ["academic_on", "Sending institution's period start (blank marks unknown)", "date", "", false],
            ["reason", "Reason and source evidence", "textarea"],
        ];
        const form = await buildForm(fields, { academic_on: value.academic_on || "" });
        form.elements.academic_on.max =
            value.completed_on < value.effective_on_default ? value.completed_on : value.effective_on_default;
        form.elements.reason.maxLength = 1000;
        const submit = node("button", { type: "submit", class: "btn btn-primary" }, "Review source period");
        form.append(submit);
        body.append(form);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const data = formData(form, fields);
                const base = endpoint(student, award) + "source-period/";
                const review = await request(base + "review/", "POST", {
                    academic_on: data.academic_on || null,
                    reason: data.reason,
                    version: value.credit.version,
                });
                const confirm = openDrawer(
                    "Confirm source academic period",
                    "PRESERVE EARLIER SOURCE EVIDENCE",
                );
                confirm.append(
                    node(
                        "p",
                        {},
                        `${review.previous_academic_on || "Unknown"} → ${review.academic_on || "Unknown"}`,
                    ),
                    node("p", {}, review.reason),
                    node(
                        "p",
                        {},
                        "Awarded credits, grade, completion date and posting term stay as recorded. This source date can change repeat-GPA ordering only when the selected degree policy uses academic periods.",
                    ),
                    button("Revise source period", () =>
                        openDrawer("Source academic period", "REVIEWED SENDING-INSTITUTION FACT").append(
                            form,
                        ),
                    ),
                    button(
                        "Confirm source period",
                        async () => {
                            await request(base + "commit/", "POST", { review_token: review.review_token });
                            await record(student, award);
                        },
                        "btn-primary",
                    ),
                );
            }, submit);
        });
    }
    async function periodHistory(student, award, cursor = null, stack = []) {
        const data = await request(endpoint(student, award) + "source-period/history/", "POST", { cursor });
        const body = openDrawer("Source period history", "PRIVATE TRANSFER CHRONOLOGY");
        body.append(
            button("Back to award", () => record(student, award)),
            node(
                "p",
                {},
                "Award and credit decisions are included for context. Only an explicit source-period decision changes this date; other decisions retain its prior value. Protected reasons and actors are omitted.",
            ),
        );
        if (data.earlier_history_unknown)
            body.append(
                node(
                    "p",
                    { class: "alert alert-info" },
                    "Earlier source history is unknown. No period start has been reconstructed from today's calendar.",
                ),
            );
        body.append(
            data.items.length
                ? grid(
                      ["Decision", "Source period change", "Effective", "Recorded"],
                      data.items.map((item) => [
                          {
                              course_accepted: "Accepted course reviewed",
                              period_recorded: "Source period recorded",
                              award: "Award established",
                              observed_existing: "Earlier facts observed",
                              amend: "Award corrected",
                              void: "Award voided",
                              reinstate: "Award reinstated",
                          }[item.action],
                          item.action === "period_recorded"
                              ? `${item.previous_academic_on || "Unknown"} → ${item.academic_on || "Unknown"}`
                              : "No period change",
                          item.effective_at || "Unknown",
                          item.recorded_at,
                      ]),
                      "Source academic-period decisions",
                  )
                : node("p", {}, "No recorded source decisions on this page."),
        );
        const actions = node("div", { class: "inline-actions" });
        if (stack.length)
            actions.append(
                button("Previous source history page", () =>
                    periodHistory(student, award, stack.at(-1), stack.slice(0, -1)),
                ),
            );
        if (data.next_cursor)
            actions.append(
                button("Next source history page", () =>
                    periodHistory(student, award, data.next_cursor, [...stack, cursor]),
                ),
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
            ["effective_on", "Effective date", "date"],
            ["reason", "Registrar reason", "textarea"],
        ];
        const initial = preset || {
            credits_milli: value.credit.status === "V" ? undefined : value.credit.completed_milli,
            awarded_grade: value.grade_code || "",
            effective_on: value.effective_on_default,
        };
        const form = await buildForm(fields, {
            ...initial,
            credits_milli: initial.credits_milli == null ? undefined : Number(initial.credits_milli) / 1000,
        });
        form.elements.effective_on.min = value.completed_on;
        form.elements.effective_on.max = value.effective_on_default;
        if (action !== "void") {
            form.elements.credits_milli.min = 0.001;
            form.elements.credits_milli.max = 999.999;
            form.elements.awarded_grade.maxLength = 12;
        }
        const submit = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review proposed correction",
        );
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
            node("p", {}, `Effective date: ${review.request.effective_on}`),
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
                    node(
                        "p",
                        { class: "form-text" },
                        `${item.created_at} · Staff reference ${item.actor_id}`,
                    ),
                    node(
                        "p",
                        { class: "form-text" },
                        item.history_source === "legacy_receipt"
                            ? "Earlier saved receipt · effective time unknown"
                            : `Effective: ${item.effective_at || "Unknown"}`,
                    ),
                    node("p", { class: "private-text" }, item.reason),
                    comparison(item.before, item.after),
                ),
            );
        });
        if (!result.items.length)
            body.append(
                node("p", {}, "This award has no corrections. Its original posted values remain current."),
            );
        const navigation = node("div", { class: "inline-actions" });
        if (stack.length)
            navigation.append(
                button("Previous correction page", () =>
                    history(student, award, stack.at(-1), stack.slice(0, -1)),
                ),
            );
        if (result.next_after)
            navigation.append(
                button("Next correction page", () =>
                    history(student, award, result.next_after, [...stack, after]),
                ),
            );
        body.append(navigation);
    }
    return { record };
};
