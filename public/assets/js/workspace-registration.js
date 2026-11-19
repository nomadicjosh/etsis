/* Staff batch registration: explicit review, atomic confirmation and durable retry receipts. */
window.createWorkspaceRegistration = function (ui) {
    "use strict";
    const { node, request, task, table, money, recordSummary, buildForm, openDrawer, refreshDirectory } = ui;
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
    const schedule = window.createRegistrationSchedule({ node });
    async function policy(back = null) {
        const body = openDrawer("Timetable policy", "REGISTRATION SETTINGS");
        const value = await request("/sis/registration-policy/");
        if (!body.isConnected) return;
        const choices = ["block — Block confirmed overlaps", "warn — Warn and allow registration"];
        const form = await buildForm([["mode", "When published meetings overlap", "choice", choices]], {
            mode: choices.find((item) => item.startsWith(value.mode)),
        });
        if (!body.isConnected) return;
        const save = node("button", { type: "submit", class: "btn btn-primary" }, "Save policy");
        form.append(save);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                await request("/sis/registration-policy/", "POST", {
                    mode: form.elements.mode.value.split(" — ")[0],
                    revision: value.revision,
                });
                if (body.isConnected) await policy(back);
            }, save);
        });
        body.append(
            node(
                "p",
                {},
                "Applies to new student, staff and group registrations. Unpublished schedules remain not checked. Existing registrations and charges are unchanged.",
            ),
            form,
            back ? button("Back to registration", back, "btn-link") : null,
        );
    }
    async function single(student, back, draft = {}) {
        let body = openDrawer("Register student", "SECTION → ELIGIBILITY & FEES → CONFIRM");
        const form = await buildForm([["section_id", "Course section", "lookup", "sections"]], draft);
        if (!body.isConnected) return;
        let generation = 0,
            confirmation = null;
        async function reviewSelection(section) {
            const source = body,
                turn = ++generation;
            if (confirmation) confirmation.disabled = true;
            const reviewed = await request("/sis/registrations/review/", "POST", {
                student_id: student,
                section_id: section,
                by_staff: true,
            });
            if (!source.isConnected || turn !== generation) return;
            const panel = openDrawer("Review registration", "SECTION → ELIGIBILITY & FEES → CONFIRM");
            body = panel;
            const confirm = button(
                "Confirm registration",
                async () => {
                    await request("/sis/registrations/", "POST", {
                        student_id: student,
                        section_id: section,
                        by_staff: true,
                        operation_id: reviewed.operation_id,
                        revision: reviewed.revision,
                        review_token: reviewed.review_token,
                    });
                    if (!panel.isConnected) return;
                    await back();
                    await refreshDirectory();
                },
                "btn-primary",
            );
            confirmation = confirm;
            confirm.disabled = !schedule.canConfirm(reviewed);
            panel.append(
                schedule.registration(reviewed, money),
                node(
                    "div",
                    { class: "inline-actions" },
                    confirm,
                    button("Refresh review", () => reviewSelection(section)),
                    button("Change section", () => single(student, back, { section_id: section })),
                ),
                button("Back to student", back, "btn-link"),
            );
        }
        const review = node("button", { type: "submit", class: "btn btn-primary" }, "Review registration");
        form.append(review);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (form.reportValidity()) await reviewSelection(form.elements.section_id.value);
            }, review);
        });
        body.append(form, button("Back to student", back, "btn-link"));
    }
    function receipt(result, again) {
        const body = openDrawer("Batch completed", "REGISTRATION RECEIPT");
        body.append(
            node(
                "p",
                { role: "status" },
                `${result.created} ${result.created === 1 ? "registration" : "registrations"} created. ${result.existing} existing ${result.existing === 1 ? "registration" : "registrations"} kept.`,
            ),
            node("p", {}, "The registrations, applicable charges and batch receipt were saved together."),
            node(
                "p",
                { class: "registration-reference" },
                "Batch reference: ",
                node("code", {}, result.batch_id),
            ),
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
                const result = await request(`/sis/registration-batches/${id}/`);
                if (!body.isConnected) return;
                receipt(result, () => start());
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
    async function start(presetSection = "", presetStudents = [], back = null) {
        if (presetStudents.length > 25) throw new Error("Select no more than 25 students.");
        const chosen = new Map(
            presetStudents.map((student) => [
                student.id,
                `${student.institutional_id || "ID not assigned"} · ${student.name}`,
            ]),
        );
        let sectionId = presetSection;
        async function selection() {
            const body = openDrawer("Register a group", "BATCH REGISTRATION");
            const tools = node(
                "div",
                {
                    class: "inline-actions mb-3",
                    role: "group",
                    "aria-label": "Registration tools",
                },
                button("Find a completed batch", findReceipt, "btn-outline-secondary"),
            );
            if (ui.can?.("sis:registration:policy"))
                tools.append(button("Timetable policy", () => policy(selection)));
            body.append(
                node(
                    "p",
                    { class: "form-text" },
                    "Choose one section and up to 25 students. Review before confirming. Eligibility and capacity are checked on confirmation; one failure rolls back the entire batch.",
                ),
                tools,
            );
            if (back) tools.append(button("Back to report selection", back));
            const form = await buildForm(
                [
                    ["section_id", "Course section", "lookup", "sections"],
                    ["student_id", "Find a student", "lookup", "registrationstudents", false],
                ],
                { section_id: sectionId },
                { layout: "registration_group" },
            );
            if (!body.isConnected) return;
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
            function render(focusIndex = null) {
                selected.replaceChildren(
                    node("h3", {}, `${chosen.size} selected ${chosen.size === 1 ? "student" : "students"}`),
                    node(
                        "ul",
                        { class: "registration-selection" },
                        ...[...chosen].map(([id, label], index) => {
                            const remove = button(
                                "Remove",
                                async () => {
                                    chosen.delete(id);
                                    render(index);
                                },
                                "btn-outline-secondary btn-sm",
                            );
                            remove.setAttribute("aria-label", `Remove ${label}`);
                            return node("li", {}, node("span", {}, label), remove);
                        }),
                    ),
                );
                if (!chosen.size)
                    selected.append(node("p", { class: "form-text" }, "Add students to build this group."));
                if (focusIndex !== null) {
                    const controls = selected.querySelectorAll("button");
                    (controls[Math.min(focusIndex, controls.length - 1)] || add).focus();
                }
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
                    if (!body.isConnected) return;
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
                recordSummary([
                    ["Course", `${section.course_code} · ${section.course_title}`],
                    ["Credits", Number(section.credits_milli) / 1000],
                    ["Seats remaining", section.seats_remaining],
                    ["Section fee", `${money(section.fee_minor, section.currency)} per new registration`],
                ]),
                node(
                    "p",
                    { class: "form-text" },
                    "Review each student’s proposed charges below. Existing active enrollments are kept without a second charge. This review expires after 20 minutes; confirmation rechecks the selection and saves the group atomically.",
                ),
            );
            if (!section.active)
                body.append(
                    node(
                        "p",
                        { class: "alert alert-warning" },
                        "This section or course is inactive. New registrations cannot be added.",
                    ),
                );
            const students = grid(
                ["Student", "Institutional ID", "Activity", "Prerequisites", "Timetable", "Registration"],
                reviewed.students.map((student) => [
                    `${student.given_name} ${student.family_name}`,
                    student.institutional_id,
                    student.active ? "Active" : "Inactive",
                    node(
                        "div",
                        {},
                        student.prerequisites?.status === "missing"
                            ? `Missing ${student.prerequisites.missing_count} ${student.prerequisites.missing_count === 1 ? "prerequisite" : "prerequisites"}`
                            : {
                                  met: "Verified for this pursuit",
                                  not_required: "No course prerequisites",
                                  not_rechecked: ["A", "N"].includes(student.enrollment_status)
                                      ? "Existing registration — unchanged"
                                      : "Not checked — closed enrollment",
                              }[student.prerequisites?.status] || "Checked on confirmation",
                        schedule.prerequisites(student.prerequisites, reviewed.section.prerequisite_courses),
                    ),
                    schedule.text(student.schedule),
                    ["A", "N"].includes(student.enrollment_status)
                        ? "Already enrolled — keep"
                        : student.enrollment_id
                          ? "Closed enrollment — registrar review required"
                          : "New registration",
                ]),
                "Reviewed registration group",
            );
            body.append(
                node(
                    "p",
                    { class: "registration-reference" },
                    "Batch reference: ",
                    node("code", {}, reviewed.operation_id),
                ),
                node(
                    "p",
                    { class: "form-text" },
                    "If a response is interrupted, check this receipt or retry this same confirmation. Keep the reference to find a completed batch later.",
                ),
            );
            for (const student of reviewed.students) {
                if (student.schedule?.conflict_count)
                    body.append(
                        node("h3", { class: "h6" }, `${student.given_name} ${student.family_name}`),
                        schedule.panel(student.schedule),
                    );
            }
            for (const student of reviewed.students) {
                if (student.issues.length)
                    body.append(
                        node(
                            "div",
                            { class: "alert alert-warning" },
                            node("strong", {}, `${student.given_name} ${student.family_name}`),
                            node(
                                "ul",
                                { class: "mb-0" },
                                student.issues.map((issue) => node("li", {}, issue)),
                            ),
                        ),
                    );
                const details = node(
                    "details",
                    { class: "mb-3" },
                    node(
                        "summary",
                        { class: "fw-semibold" },
                        `${student.given_name} ${student.family_name} · ${student.charges.total_minor == null ? "Pricing needs review" : money(student.charges.total_minor, student.charges.currency)}`,
                    ),
                );
                const content = node("div", { class: "mt-2" });
                details.append(content);
                details.addEventListener("toggle", () =>
                    content.replaceChildren(
                        ...(details.open ? [schedule.charges(student.charges, money)] : []),
                    ),
                );
                body.append(details);
            }
            const submit = button(
                "Confirm group registration",
                async () => {
                    const result = await request("/sis/registration-batches/commit/", "POST", {
                        ...payload,
                        operation_id: reviewed.operation_id,
                        revision: reviewed.revision,
                        review_token: reviewed.review_token,
                    });
                    if (!body.isConnected) return;
                    await refreshDirectory();
                    if (!body.isConnected) return;
                    receipt(result, () => start(sectionId));
                },
                "btn-primary",
            );
            submit.disabled = !schedule.canConfirm(reviewed);
            if (reviewed.students.some((student) => student.schedule?.blocked)) {
                submit.disabled = true;
                body.append(
                    node(
                        "p",
                        { class: "alert alert-warning", role: "status" },
                        "Resolve overlapping meetings or change the selected group before confirming.",
                    ),
                );
            }
            if (reviewed.students.some((student) => student.prerequisites?.status === "missing")) {
                submit.disabled = true;
                body.append(
                    node(
                        "p",
                        { class: "alert alert-warning", role: "status" },
                        "Some selected students do not meet the prerequisites. Change the selection or review their academic records before confirming.",
                    ),
                );
            }
            body.append(
                node(
                    "div",
                    {
                        class: "inline-actions my-3",
                        role: "group",
                        "aria-label": "Registration confirmation",
                    },
                    submit,
                    button("Refresh review", async () => {
                        submit.disabled = true;
                        const next = await request("/sis/registration-batches/review/", "POST", payload);
                        if (body.isConnected) await confirmation(next, payload);
                    }),
                    button("Check batch receipt", async () => {
                        try {
                            const result = await request(
                                `/sis/registration-batches/${reviewed.operation_id}/`,
                            );
                            if (!body.isConnected) return;
                            receipt(result, () => start(sectionId));
                        } catch (error) {
                            if (error.status === 404)
                                throw new Error(
                                    "No completed receipt exists yet. Keep this review to retry, or change the selection and review it again.",
                                );
                            throw error;
                        }
                    }),
                ),
                students,
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
                node(
                    "p",
                    { class: "form-text" },
                    `Timetable at acceptance: ${schedule.text(record.schedule)}.`,
                ),
            );
            const prerequisites = record.prerequisites;
            panel.append(
                node(
                    "p",
                    { class: "form-text" },
                    prerequisites?.status === "verified"
                        ? `Prerequisites at acceptance: ${prerequisites.required_course_count} required ${prerequisites.required_course_count === 1 ? "course" : "courses"} matched to ${prerequisites.supporting_record_count} verified academic ${prerequisites.supporting_record_count === 1 ? "record" : "records"}.`
                        : prerequisites?.status === "not_required"
                          ? "No course prerequisites were required at acceptance."
                          : "Supporting prerequisite references were not recorded for this earlier decision.",
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
    return { single, policy, start, history };
};
