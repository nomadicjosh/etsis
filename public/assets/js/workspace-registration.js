/* Staff batch registration: explicit review, atomic confirmation and durable retry receipts. */
window.createWorkspaceRegistration = function (ui) {
    "use strict";
    const {
        node,
        request,
        task,
        table,
        money,
        buildForm,
        openDrawer,
        refreshDirectory,
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
            node(
                "p",
                {},
                "The registrations, applicable charges and batch receipt were saved together.",
            ),
            node(
                "p",
                {},
                "Batch reference: ",
                node("code", {}, result.batch_id),
            ),
            button("Start another group", again, "btn-primary"),
        );
    }
    async function findReceipt() {
        const body = openDrawer(
            "Find a completed batch",
            "REGISTRATION RECEIPT",
        );
        const form = await buildForm([["operation_id", "Batch reference"]]);
        form.elements.operation_id.maxLength = 26;
        const save = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Check completed batch",
        );
        form.append(save);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const id = form.elements.operation_id.value.trim();
                if (!/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/.test(id))
                    throw new Error("Enter a valid batch reference.");
                receipt(await request(`/sis/registration-batches/${id}/`), () =>
                    start(),
                );
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
                    [
                        "student_id",
                        "Find a student",
                        "lookup",
                        "registrationstudents",
                        false,
                    ],
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
                chosen.set(
                    control.value,
                    control.selectedOptions[0].textContent,
                );
                render();
            });
            function render() {
                selected.replaceChildren(
                    node(
                        "h3",
                        {},
                        `${chosen.size} selected ${chosen.size === 1 ? "student" : "students"}`,
                    ),
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
                    if (!chosen.size)
                        throw new Error(
                            "Add at least one student to the group.",
                        );
                    sectionId = form.elements.section_id.value;
                    const payload = {
                        section_id: sectionId,
                        student_ids: [...chosen.keys()],
                    };
                    const reviewed = await request(
                        "/sis/registration-batches/review/",
                        "POST",
                        payload,
                    );
                    await confirmation(reviewed, payload);
                }, review);
            });
            body.append(form);
        }
        async function confirmation(reviewed, payload) {
            const section = reviewed.section;
            const body = openDrawer(
                "Review group registration",
                "CONFIRM THE SELECTION",
            );
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
                node(
                    "p",
                    {},
                    "Batch reference: ",
                    node("code", {}, reviewed.operation_id),
                ),
                node(
                    "p",
                    { class: "form-text" },
                    "If a response is interrupted, check this receipt or retry this same confirmation. Keep the reference to find a completed batch later.",
                ),
            );
            const submit = button(
                "Confirm group registration",
                async () => {
                    const result = await request(
                        "/sis/registration-batches/commit/",
                        "POST",
                        {
                            ...payload,
                            operation_id: reviewed.operation_id,
                            revision: reviewed.revision,
                        },
                    );
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
                                await request(
                                    `/sis/registration-batches/${reviewed.operation_id}/`,
                                ),
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
    return { start };
};
