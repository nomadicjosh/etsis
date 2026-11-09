/* Staff batch registration: explicit review, atomic confirmation and durable retry receipts. */
window.createWorkspaceRegistration = function (ui) {
    "use strict";
    const { node, request, task, table, money, buildForm, openDrawer, refreshDirectory } = ui;
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
                tabindex: "0",
                role: "region",
                "aria-label": caption,
            },
            table(headers, rows, caption),
        );
    function receipt(result, again) {
        const body = openDrawer("Batch completed", "REGISTRATION RECEIPT");
        body.append(
            node(
                "p",
                { role: "status" },
                `${result.created} ${result.created === 1 ? "registration" : "registrations"} created. ${result.existing} existing ${result.existing === 1 ? "registration" : "registrations"} kept.`,
            ),
            node("p", {}, "The registrations, applicable charges and batch receipt were saved together."),
            node("p", {}, "Batch reference: ", node("code", {}, result.batch_id)),
            button("Start another group", again, "btn-primary"),
        );
    }
    async function findReceipt() {
        const body = openDrawer("Find a completed batch", "REGISTRATION RECEIPT");
        const form = await buildForm([["operation_id", "Batch reference"]]);
        form.elements.operation_id.maxLength = 26;
        const save = node("button", { type: "submit", class: "btn btn-primary" }, "Check completed batch");
        form.append(save);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const id = form.elements.operation_id.value.trim();
                if (!/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/.test(id))
                    throw new Error("Enter a valid batch reference.");
                receipt(await request(`/sis/registration-batches/${id}/`), () => start());
            }, save);
        });
        body.append(
            node(
                "p",
                { class: "form-text" },
                "Only the staff member who submitted the batch can read its receipt, while their registration permission remains active.",
            ),
            form,
        );
    }
    async function start(presetSection = "") {
        const chosen = new Map();
        let sectionId = presetSection;
        async function selection() {
            const body = openDrawer("Register a group", "BATCH REGISTRATION");
            body.append(
                node(
                    "p",
                    { class: "form-text" },
                    "Choose one section and up to 25 students. Review before confirming. Eligibility and capacity are checked on confirmation; one failure rolls back the entire batch.",
                ),
                button("Find a completed batch", findReceipt),
            );
            const form = await buildForm(
                [
                    ["section_id", "Course section", "lookup", "sections"],
                    ["student_id", "Find a student", "lookup", "registrationstudents", false],
                ],
                { section_id: sectionId },
            );
            const selected = node("section", {
                class: "record-section",
                "aria-label": "Selected students",
                "aria-live": "polite",
            });
            const add = button("Add student to group", async () => {
                const control = form.elements.student_id;
                if (!control.value) throw new Error("Choose a student to add.");
                if (!chosen.has(control.value) && chosen.size >= 25)
                    throw new Error("Select no more than 25 students.");
                chosen.set(control.value, control.selectedOptions[0].textContent);
                render();
            });
            function render() {
                selected.replaceChildren(
                    node("h3", {}, `${chosen.size} selected ${chosen.size === 1 ? "student" : "students"}`),
                    ...[...chosen].map(([id, label]) =>
                        node(
                            "p",
                            {},
                            label,
                            " ",
                            button(
                                `Remove ${label}`,
                                async () => {
                                    chosen.delete(id);
                                    render();
                                },
                                "btn-link",
                            ),
                        ),
                    ),
                );
            }
            render();
            const review = node(
                "button",
                { type: "submit", class: "btn btn-primary" },
                "Review selected group",
            );
            form.append(add, selected, review);
            form.addEventListener("submit", (event) => {
                event.preventDefault();
                task(async () => {
                    if (!form.reportValidity()) return;
                    if (!chosen.size) throw new Error("Add at least one student to the group.");
                    sectionId = form.elements.section_id.value;
                    const payload = {
                        section_id: sectionId,
                        student_ids: [...chosen.keys()],
                    };
                    const reviewed = await request("/sis/registration-batches/review/", "POST", payload);
                    await confirmation(reviewed, payload);
                }, review);
            });
            body.append(form);
        }
        async function confirmation(reviewed, payload) {
            const section = reviewed.section;
            const body = openDrawer("Review group registration", "CONFIRM THE SELECTION");
            body.append(
                button("Change selection", selection, "btn-link"),
                node("h3", {}, section.code),
                node(
                    "p",
                    {},
                    `${section.course_code} · ${section.course_title} · ${Number(section.credits_milli) / 1000} credits`,
                ),
                node(
                    "p",
                    {},
                    `${section.seats_remaining} seats remaining. Section fee: ${money(section.fee_minor, section.currency)} per new registration.`,
                ),
                node(
                    "p",
                    { class: "form-text" },
                    "Configured term fees may also apply. Existing active enrollments are kept without a second charge. Confirmation rechecks activity, holds, prerequisites, course limits and seats. No partial batch is saved.",
                ),
                section.active
                    ? null
                    : node(
                          "p",
                          { class: "alert alert-warning" },
                          "This section or course is inactive. New registrations cannot be added.",
                      ),
                grid(
                    ["Student", "Institutional ID", "Activity", "Registration"],
                    reviewed.students.map((student) => [
                        `${student.given_name} ${student.family_name}`,
                        student.institutional_id,
                        student.active ? "Active" : "Inactive",
                        ["A", "N"].includes(student.enrollment_status)
                            ? "Already enrolled — keep"
                            : student.enrollment_id
                              ? "Closed enrollment — registrar review required"
                              : "New registration",
                    ]),
                    "Reviewed registration group",
                ),
                node("p", {}, "Batch reference: ", node("code", {}, reviewed.operation_id)),
                node(
                    "p",
                    { class: "form-text" },
                    "If a response is interrupted, check this receipt or retry this same confirmation. Keep the reference to find a completed batch later.",
                ),
            );
            const submit = button(
                "Confirm group registration",
                async () => {
                    const result = await request("/sis/registration-batches/commit/", "POST", {
                        ...payload,
                        operation_id: reviewed.operation_id,
                        revision: reviewed.revision,
                    });
                    await refreshDirectory();
                    receipt(result, () => start(sectionId));
                },
                "btn-primary",
            );
            body.append(
                node(
                    "div",
                    { class: "inline-actions mt-3" },
                    submit,
                    button("Check batch receipt", async () => {
                        try {
                            receipt(
                                await request(`/sis/registration-batches/${reviewed.operation_id}/`),
                                () => start(sectionId),
                            );
                        } catch (error) {
                            if (error.status === 404)
                                throw new Error(
                                    "No completed receipt exists yet. Keep this review to retry, or change the selection and review it again.",
                                );
                            throw error;
                        }
                    }),
                ),
            );
        }
        await selection();
    }
    async function history(student, back, after = "", stack = []) {
        const page = await request(`/sis/students/${student}/registration-history/`, "POST", { after });
        const body = openDrawer("Registration history", "RESTRICTED REGISTRAR HISTORY");
        if (back) body.append(button("Back to student", back, "btn-link"));
        body.append(
            node(
                "p",
                { class: "form-text" },
                "Recorded enrollment decisions, including early drops. Section order reflects committed server decisions; it does not describe browser click order. Earlier unrecorded activity may be unavailable.",
            ),
        );
        for (const record of page.items) {
            const panel = node(
                "section",
                { class: "record-section" },
                node("h3", {}, `${record.section_code} · ${record.term_code}`),
            );
            if (record.current_records_removed)
                panel.append(
                    node(
                        "p",
                        { class: "alert alert-info" },
                        "Current records removed during add/drop. This restricted history is retained for registrar review.",
                    ),
                );
            if (record.earlier_history_unknown)
                panel.append(
                    node(
                        "p",
                        { class: "form-text" },
                        "Earlier enrollment decisions are unknown; existing facts were observed at the recorded action.",
                    ),
                );
            panel.append(
                grid(
                    ["Decision", "Section order", "Effective", "Recorded"],
                    record.items.map((item) => [
                        {
                            registered: "Registration accepted",
                            observed_existing: "Existing enrollment observed",
                            early_drop: "Early drop",
                            late_drop: "Late drop / withdrawal",
                        }[item.action] || "Enrollment decision",
                        item.section_sequence ?? "—",
                        item.effective_at,
                        item.recorded_at,
                    ]),
                    "Recorded registration decisions",
                ),
            );
            body.append(panel);
        }
        if (!page.items.length)
            body.append(
                node(
                    "p",
                    {},
                    "No recorded enrollment decisions yet. Reading this screen does not create historical events.",
                ),
            );
        const nav = node("div", { class: "inline-actions" });
        if (stack.length)
            nav.append(
                button("Previous history page", () =>
                    history(student, back, stack.at(-1), stack.slice(0, -1)),
                ),
            );
        if (page.next_after)
            nav.append(
                button("Next history page", () => history(student, back, page.next_after, [...stack, after])),
            );
        body.append(nav);
    }
    return { start, history };
};
