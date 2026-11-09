/* Staff hiatus history. Comments and amendment reasons stay in memory and render as text. */
window.createWorkspaceHiatus = function (ui) {
    "use strict";
    const {
        node,
        request,
        task,
        table,
        badge,
        buildForm,
        formData,
        flow,
        openDrawer,
    } = ui;
    const button = (
        label,
        callback,
        style = "btn-outline-primary",
        settled = () => {},
    ) =>
        node(
            "button",
            {
                type: "button",
                class: `btn ${style}`,
                onclick: (event) =>
                    task(callback, event.currentTarget).finally(settled),
            },
            label,
        );
    const grid = (headers, rows, caption) =>
        node(
            "div",
            {
                class: "table-responsive",
                tabindex: "0",
                role: "region",
                "aria-label": caption,
            },
            table(headers, rows, caption),
        );
    const explanation =
        "Record a hiatus through a guided review of course and activity changes. End-date corrections keep an amendment history. Codes and start dates stay fixed; void an incorrect history record and create a replacement.";
    const choices = [
        "LOA — Leave of absence",
        "W — Withdrawal",
        "SA — Study abroad",
        "ILLN — Illness",
        "DISM — Dismissal",
    ];
    async function choose() {
        const body = openDrawer("Student hiatus history", "REGISTRAR RECORDS");
        const form = await buildForm([
            ["student_id", "Student", "lookup", "registrationstudents"],
        ]);
        const submit = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Open hiatus history",
        );
        form.append(submit);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (form.reportValidity())
                    await page(form.elements.student_id.value);
            }, submit);
        });
        body.append(form);
    }
    async function pages(container, initial, fetchPage, render, label) {
        let after = "",
            nextAfter = null,
            stack = [],
            loading = false;
        const content = node("div"),
            status = node("p", {
                role: "status",
                "aria-live": "polite",
                class: "form-text",
            });
        const previous = button(
            `Previous ${label} page`,
            () => load(stack.at(-1) || "", stack.slice(0, -1)),
            undefined,
            controls,
        );
        const next = button(
            `Next ${label} page`,
            () => load(nextAfter, [...stack, after]),
            undefined,
            controls,
        );
        container.append(
            status,
            content,
            node("div", { class: "inline-actions" }, previous, next),
        );
        function controls() {
            previous.disabled = loading || !stack.length;
            next.disabled = loading || !nextAfter;
        }
        function show(result) {
            nextAfter = result.next_after;
            content.replaceChildren(render(result.items));
            status.textContent = `${result.items.length} records on this page.${nextAfter ? " More are available." : ""}`;
            controls();
        }
        async function load(cursor, history) {
            if (loading) return;
            loading = true;
            controls();
            try {
                const result = await fetchPage(cursor);
                if (!container.isConnected) return;
                after = cursor;
                stack = history;
                show(result);
            } finally {
                loading = false;
                controls();
            }
        }
        show(initial);
    }
    async function page(student) {
        const result = await request(
            `/sis/students/${student}/hiatuses/`,
            "POST",
            {},
        );
        const body = openDrawer("Student hiatus history", "REGISTRAR RECORDS");
        body.append(
            node(
                "p",
                {},
                `${result.student.given_name} ${result.student.family_name} · ${result.student.institutional_id}`,
            ),
            node("p", { class: "form-text" }, explanation),
            button("Record a hiatus", () => create(student), "btn-primary"),
            node(
                "p",
                { class: "form-text" },
                `Status as of ${result.today} (${result.timezone}). End dates include the entire day. Records of the same type cannot overlap unless voided.`,
            ),
        );
        const panel = node("section", {
            class: "record-section",
            "aria-label": "Hiatus records",
        });
        body.append(panel);
        await pages(
            panel,
            result,
            (after) =>
                request(`/sis/students/${student}/hiatuses/`, "POST", {
                    after,
                }),
            (rows) =>
                rows.length
                    ? grid(
                          ["Type", "From", "Through", "State", "Action"],
                          rows.map((item) => [
                              item.label,
                              item.starts_on,
                              item.ends_on || "Open-ended",
                              badge(item.state),
                              button("Review hiatus", () =>
                                  record(student, item.id),
                              ),
                          ]),
                          "Student hiatus records",
                      )
                    : node("p", {}, "No hiatus history has been recorded."),
            "hiatus",
        );
    }
    async function create(student, draft = {}) {
        const body = openDrawer(
            "Record a student hiatus",
            "DETAILS → REVIEW → CONFIRM",
        );
        const fields = [
            ["code", "Hiatus type", "choice", choices],
            ["starts_on", "Effective start date", "date"],
            ["ends_on", "End date (optional)", "date", "", false],
            ["note", "Private staff comment", "textarea"],
        ];
        const form = await buildForm(fields, {
            ...draft,
            code:
                choices.find((choice) =>
                    choice.startsWith(`${draft.code} —`),
                ) || choices[0],
        });
        const dates = () => {
            form.elements.ends_on.min = form.elements.starts_on.value;
        };
        form.elements.starts_on.addEventListener("input", dates);
        dates();
        const submit = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review hiatus plan",
        );
        form.append(submit);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const data = formData(form, fields);
                data.code = data.code.split(" — ")[0];
                const reviewed = await request(
                    `/sis/students/${student}/hiatus-workflow/review/`,
                    "POST",
                    data,
                );
                await plan(student, data, reviewed);
            }, submit);
        });
        body.append(
            node(
                "p",
                { class: "form-text" },
                "First record the details. Next, review proposed course and student-activity changes. Nothing is applied until you confirm the plan.",
            ),
            form,
        );
    }
    async function plan(student, draft, reviewed) {
        const body = openDrawer(
            "Review student hiatus plan",
            "DETAILS → REVIEW → CONFIRM",
        );
        const selected = new Set(reviewed.defaults.section_ids);
        const activity = node("input", {
            type: "checkbox",
            class: "form-check-input",
            id: "hiatus-deactivate",
            checked: reviewed.defaults.deactivate,
            disabled: !reviewed.effective_now || !reviewed.student.active,
        });
        const summary = node("p", { role: "status", "aria-live": "polite" });
        function announce() {
            summary.textContent = `${selected.size} course changes selected. Student activity after confirmation: ${activity.checked || !reviewed.student.active ? "Inactive" : "Active"}.`;
        }
        activity.addEventListener("change", announce);
        body.append(
            button("Change details", () => create(student, draft), "btn-link"),
            node("h3", {}, reviewed.label),
            node(
                "p",
                {},
                `${reviewed.student.given_name} ${reviewed.student.family_name} · ${reviewed.student.institutional_id}`,
            ),
            node(
                "p",
                {},
                `Effective date: ${reviewed.starts_on}. End date: ${reviewed.ends_on || "Open-ended"}.`,
            ),
            node(
                "p",
                { class: "form-text" },
                "Review every proposed action. During drop/add, course records are removed and their original course charges reversed. After drop/add, courses and charges remain with the term’s configured grade. Cash refunds require a separate review. Final grades are protected.",
            ),
            node(
                "div",
                { class: "form-check mb-3" },
                activity,
                node(
                    "label",
                    { class: "form-check-label", for: "hiatus-deactivate" },
                    "Make student record inactive now",
                ),
            ),
            node(
                "p",
                { class: "form-text" },
                "Inactive student records cannot add new registrations. This does not disable sign-in or employment.",
            ),
            reviewed.effective_now
                ? null
                : node(
                      "p",
                      { class: "alert alert-info" },
                      "This is a scheduled date. Confirmation records the history only; immediate course and activity changes are unavailable.",
                  ),
        );
        const rows = reviewed.courses.map((course) => {
            const id = `hiatus-choice-${course.section_id}`;
            const control = node("input", {
                type: "checkbox",
                class: "form-check-input me-2",
                id,
                checked: selected.has(course.section_id),
                disabled: !course.eligible,
            });
            control.addEventListener("change", () => {
                if (control.checked) selected.add(course.section_id);
                else selected.delete(course.section_id);
                announce();
            });
            return [
                course.section_code,
                course.course_title,
                course.term_code,
                course.drop_policy ? (course.drop_policy.delete_records ? "Remove course · reverse course charge" : `Retain course · grade ${course.drop_policy.grade_code} · keep charge`) : (course.proposed_status === "D" ? "Remove course · reverse course charge" : "Withdraw (W)"),
                node(
                    "div",
                    {},
                    node(
                        "label",
                        { for: id },
                        control,
                        `Apply to ${course.section_code}`,
                    ),
                    course.reason
                        ? node("p", { class: "form-text" }, course.reason)
                        : null,
                ),
            ];
        });
        body.append(
            rows.length
                ? grid(
                      [
                          "Section",
                          "Course",
                          "Term",
                          "Proposed status",
                          "Apply change",
                      ],
                      rows,
                      "Proposed course changes",
                  )
                : node(
                      "p",
                      {},
                      "No open course enrollments need review for this date.",
                  ),
            summary,
            node("h3", {}, "Staff comment"),
            node(
                "p",
                { class: "text-break", style: "white-space: pre-wrap" },
                draft.note,
            ),
            node(
                "p",
                { class: "alert alert-info" },
                "Early drops reverse their original course charges; late drops keep them. Finance reviews cash refunds and other adjustments separately.",
            ),
            node(
                "p",
                {},
                "Case reference: ",
                node("code", {}, reviewed.operation_id),
            ),
            node(
                "p",
                { class: "form-text" },
                "After an interrupted response, keep your choices unchanged and retry, or check the saved case before starting another one.",
            ),
        );
        announce();
        body.append(
            node(
                "div",
                { class: "inline-actions" },
                button(
                    "Confirm hiatus plan",
                    async () => {
                        const result = await request(
                            `/sis/students/${student}/hiatus-workflow/commit/`,
                            "POST",
                            {
                                ...draft,
                                operation_id: reviewed.operation_id,
                                revision: reviewed.revision,
                                section_ids: [...selected],
                                deactivate: activity.checked,
                            },
                        );
                        completed(student, result);
                        await ui.refreshDirectory();
                    },
                    "btn-primary",
                ),
                button("Check saved case", async () => {
                    try {
                        await record(student, reviewed.operation_id);
                    } catch (error) {
                        if (error.status === 404)
                            throw new Error(
                                "No saved case exists yet. Keep this review to retry or refresh its details.",
                            );
                        throw error;
                    }
                }),
            ),
        );
    }
    function completed(student, result) {
        const body = openDrawer(
            "Hiatus plan completed",
            "STUDENT RECORDS UPDATED",
        );
        body.append(
            node(
                "p",
                { role: "status" },
                `${result.impact.course_changes.length} course changes recorded. Student record: ${result.impact.student_active ? "Active" : "Inactive"}.`,
            ),
            node(
                "p",
                {},
                "The hiatus history and course changes were saved together. Early course charges were reversed; late charges were retained. Cash refunds require a separate financial review.",
            ),
            button(
                "Review saved hiatus",
                () => record(student, result.id),
                "btn-primary",
            ),
            button("Back to hiatus history", () => page(student)),
        );
    }
    async function returnDetails(student, value, draft = {}) {
        const body = openDrawer(
            "Return from hiatus / readmission",
            "DETAILS → REVIEW → CONFIRM",
        );
        const fields = [
            ["return_on", "Return effective date", "date"],
            ["note", "Reason for return decision", "textarea"],
        ];
        const form = await buildForm(fields, draft);
        const submit = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review return plan",
        );
        form.append(submit);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const data = formData(form, fields);
                const review = await request(
                    `/sis/students/${student}/hiatuses/${value.id}/return/review/`,
                    "POST",
                    data,
                );
                returnPlan(student, value, data, review);
            }, submit);
        });
        body.append(
            node(
                "p",
                {},
                "Record the registrar's return decision after completing your institution's readmission requirements. Review the program and current registration hold effects before confirming.",
            ),
            form,
        );
    }
    function returnPlan(student, value, draft, review) {
        const body = openDrawer(
            "Review return / readmission plan",
            "DETAILS → REVIEW → CONFIRM",
        );
        const activity = node("input", {
            type: "checkbox",
            id: "return-reactivate",
            class: "form-check-input",
            checked: false,
            disabled: review.student.active,
        });
        body.append(
            button(
                "Change return details",
                () => returnDetails(student, value, draft),
                "btn-link",
            ),
            node(
                "p",
                {},
                `${review.student.given_name} ${review.student.family_name} · ${review.student.institutional_id}`,
            ),
            node(
                "p",
                {},
                `Primary program: ${review.primary_program.code} — ${review.primary_program.title}. Entry term: ${review.entry_term.code}.`,
            ),
            node(
                "p",
                {},
                `${review.hiatus_label}: ${review.hiatus_starts_on} through ${review.hiatus_ends_on}. Return effective date: ${review.return_on}.`,
            ),
            node(
                "p",
                {},
                `Current student record: ${review.student.active ? "Active" : "Inactive"}.`,
            ),
            node(
                "div",
                { class: "form-check mb-3" },
                activity,
                node(
                    "label",
                    { for: "return-reactivate", class: "form-check-label" },
                    "Reactivate student record now",
                ),
            ),
            node(
                "p",
                { class: "alert alert-info" },
                `Current registration hold effects: staff registration ${review.staff_registration_blocked ? "blocked" : "not blocked"}; self-service registration ${review.self_registration_blocked ? "blocked" : "not blocked"}. Reactivation keeps all holds in place. Each new registration still checks its term window, prerequisites and other eligibility rules.`,
            ),
            node(
                "p",
                {},
                "The hiatus ends before the return date; an earlier recorded end stays unchanged. Returning never restores dropped or withdrawn enrollments, changes employment or login access, or adjusts charges.",
            ),
            node(
                "p",
                { class: "text-break", style: "white-space: pre-wrap" },
                draft.note,
            ),
            node(
                "p",
                {},
                "Return reference: ",
                node("code", {}, review.operation_id),
            ),
            node(
                "p",
                { class: "form-text" },
                "If the response is interrupted, keep this review to retry or check the saved return.",
            ),
            node(
                "div",
                { class: "inline-actions" },
                button(
                    "Confirm return decision",
                    async () => {
                        const saved = await request(
                            `/sis/students/${student}/hiatuses/${value.id}/return/commit/`,
                            "POST",
                            {
                                ...draft,
                                operation_id: review.operation_id,
                                revision: review.revision,
                                reactivate: activity.checked,
                            },
                        );
                        returnCompleted(student, value.id, saved);
                        await ui.refreshDirectory();
                    },
                    "btn-primary",
                ),
                button("Check saved return", async () => {
                    const saved = await request(
                        `/sis/students/${student}/hiatuses/${value.id}/returns/${review.operation_id}/`,
                    );
                    returnCompleted(student, value.id, saved);
                }),
            ),
        );
    }
    function returnCompleted(student, hiatus, saved) {
        const body = openDrawer(
            "Return decision recorded",
            "REGISTRAR RECORDS UPDATED",
        );
        const result = saved.result;
        body.append(
            node(
                "p",
                { role: "status" },
                `Return effective date: ${result.return_on}. Student record: ${result.student_active ? "Active" : "Inactive"}.`,
            ),
            node(
                "p",
                {},
                "Earlier enrollments, holds and charges remain unchanged. Registration eligibility is checked when a new course is requested.",
            ),
            node("p", {}, "Reference: ", node("code", {}, saved.operation_id)),
            button(
                "Review saved hiatus",
                () => record(student, hiatus),
                "btn-primary",
            ),
        );
    }
    async function record(student, id) {
        const value = await request(`/sis/students/${student}/hiatuses/${id}/`);
        const body = openDrawer(value.label, "STUDENT HIATUS");
        body.append(
            button("Back to hiatus history", () => page(student), "btn-link"),
            node(
                "p",
                {},
                `${value.starts_on} through ${value.ends_on || "open-ended"}`,
            ),
            badge(value.state),
            node("p", { class: "form-text" }, explanation),
            node("h3", {}, "Initial staff comment"),
            node(
                "p",
                { class: "text-break", style: "white-space: pre-wrap" },
                value.comment,
            ),
        );
        if (value.code === "W")
            body.append(
                node(
                    "p",
                    { class: "alert alert-info" },
                    "Review the recorded course and activity actions below. Older history-only records may still require a separate course review.",
                ),
            );
        if (value.impact)
            body.append(
                node(
                    "section",
                    {
                        class: "record-section",
                        "aria-label": "Recorded hiatus effects",
                    },
                    node("h3", {}, "Confirmed academic changes"),
                    node(
                        "p",
                        {},
                        `Student activity after this case: ${value.impact.student_active ? "Active" : "Inactive"}.`,
                    ),
                    value.impact.course_changes.length
                        ? grid(
                              ["Course", "Term", "Result", "Effective date"],
                              value.impact.course_changes.map((change) => [
                                  change.course_code,
                                  change.term_code,
                                  change.academic_records_removed === true ? "Course removed · course charge reversed if present" : (change.grade_code ? `Retained · grade ${change.grade_code} · course charge kept` : (change.status === "D" ? "Dropped (D)" : "Withdrawn (W)")),
                                  change.effective_date,
                              ]),
                              "Recorded course changes",
                          )
                        : node("p", {}, "No course enrollments were changed."),
                    node(
                        "p",
                        { class: "form-text" },
                        "These are historical outcomes. Subsequent corrections are managed separately; no automatic cash refund was posted.",
                    ),
                ),
            );
        if (value.return_operation_id)
            body.append(
                button("View recorded return", async () =>
                    returnCompleted(
                        student,
                        id,
                        await request(
                            `/sis/students/${student}/hiatuses/${id}/returns/${value.return_operation_id}/`,
                        ),
                    ),
                ),
            );
        if (!value.voided && !value.return_operation_id)
            body.append(
                button(
                    "Review return / readmission",
                    () => returnDetails(student, value),
                    "btn-primary",
                ),
            );
        if (!value.voided)
            body.append(
                node(
                    "div",
                    { class: "inline-actions" },
                    button("Set or correct end date", async () => {
                        const operation = await request(
                            "/sis/ui/operation-id/",
                        );
                        const form = await flow(
                            "Save hiatus end date",
                            [
                                ["ends_on", "End date", "date"],
                                ["note", "Reason for change", "textarea"],
                            ],
                            `/sis/students/${student}/hiatuses/${id}/end/`,
                            (data) => ({
                                ...data,
                                revision: value.revision,
                                operation_id: operation.id,
                            }),
                            () => record(student, id),
                            { ends_on: value.ends_on || "" },
                            "The previous date and your reason remain in the encrypted amendment history. A changed end date must not overlap another non-voided record of this type.",
                        );
                        form.elements.ends_on.min = value.starts_on;
                    }),
                    button(
                        "Void this record",
                        async () => {
                            const operation = await request(
                                "/sis/ui/operation-id/",
                            );
                            await flow(
                                "Confirm hiatus void",
                                [["note", "Reason for voiding", "textarea"]],
                                `/sis/students/${student}/hiatuses/${id}/void/`,
                                (data) => ({
                                    ...data,
                                    revision: value.revision,
                                    operation_id: operation.id,
                                }),
                                () => record(student, id),
                                {},
                                "Keep this record and its comments as history, marked voided. Create a new record for corrected details. Voiding history does not restore course enrollments or reactivate the student. Review any course, activity or financial corrections separately.",
                            );
                        },
                        "btn-outline-danger",
                    ),
                ),
            );
        const financePanel = node("section", {
            class: "record-section",
            "aria-label": "Finance hand-off",
        });
        body.append(financePanel);
        await ui.financeReviews().handoff(student, value, financePanel);
        const panel = node(
            "section",
            {
                class: "record-section",
                "aria-label": "Hiatus amendment history",
            },
            node("h3", {}, "Amendment history"),
        );
        body.append(panel);
        const history = await request(
            `/sis/students/${student}/hiatuses/${id}/history/`,
            "POST",
            {},
        );
        if (!panel.isConnected) return;
        await pages(
            panel,
            history,
            (after) =>
                request(
                    `/sis/students/${student}/hiatuses/${id}/history/`,
                    "POST",
                    { after },
                ),
            (rows) =>
                rows.length
                    ? node(
                          "div",
                          {},
                          rows.map((item) =>
                              node(
                                  "article",
                                  { class: "record-section" },
                                  node(
                                      "h4",
                                      {},
                                      {
                                          created: "Created",
                                          end_changed: "End date changed",
                                          voided: "Voided",
                                          returned:
                                              "Return / readmission recorded",
                                      }[item.action] || item.action,
                                  ),
                                  node(
                                      "p",
                                      { class: "form-text" },
                                      `${item.created_at} · ${item.actor_name || item.actor_id}`,
                                  ),
                                  item.action === "end_changed" ||
                                      item.action === "returned"
                                      ? node(
                                            "p",
                                            {},
                                            `End date: ${item.previous_ends_on || "Open-ended"} → ${item.new_ends_on}`,
                                        )
                                      : null,
                                  node(
                                      "p",
                                      {
                                          class: "text-break",
                                          style: "white-space: pre-wrap",
                                      },
                                      item.note,
                                  ),
                              ),
                          ),
                      )
                    : node("p", {}, "No amendments are available."),
            "amendment",
        );
    }
    return { choose, page, record };
};
