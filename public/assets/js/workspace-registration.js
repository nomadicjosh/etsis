/* Staff batch registration: explicit review, atomic confirmation and durable retry receipts. */
window.createWorkspaceRegistration = function (ui) {
    "use strict";
    const {
        node,
        request,
        task,
        table,
        money,
        recordSummary,
        buildForm,
        openDrawer,
        refreshDirectory,
    } = ui;
    const configNode = document.getElementById('workspace-config');
    const creditLabels = configNode ? JSON.parse(configNode.textContent).credit_policy_labels || {} : {};
    const creditText = (key, fallback) => creditLabels[key] || fallback;
    const openRegistration = (title, eyebrow) => {
        const body = node("div", { class: "registration-workbench" });
        openDrawer(title, eyebrow).append(body);
        return body;
    };
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
    const change = window.createSectionChange({
        node,
        request,
        money,
        button: (label, action, primary) =>
            button(label, action, primary ? "btn-primary" : "btn-outline-primary"),
        open: (title) => openRegistration(title, "REVIEWED SECTION CHANGE"),
    });
    async function changeSection(student, from, back) {
        await change({
            student,
            from,
            baseUrl: "/sis/section-changes/",
            onBack: back,
            onComplete: async (result) => {
                const body = openRegistration("Section changed", "REGISTRATION RECEIPT");
                body.append(
                    node(
                        "p",
                        { role: "status" },
                        "The original registration and replacement were saved together.",
                    ),
                    node(
                        "p",
                        {},
                        `Account change: ${money(result.financial.net_change_minor, result.financial.currency)}`,
                    ),
                    node("p", {}, "Reference: ", node("code", {}, result.change_id)),
                    button("Back to student", back),
                );
                await refreshDirectory();
            },
        });
    }
    async function windowPolicy(term, back) {
        const body = openRegistration("Term registration policy", "DETAILS → REVIEW → PUBLISH");
        const base = `/sis/terms/${term}/registration-window/`;
        const saved = await request(base);
        if (!body.isConnected) return;
        const local = (value) => {
            if (!value) return "";
            const date = new Date(value.replace(" ", "T") + (value.includes("T") ? "" : "Z")),
                pad = (number) => String(number).padStart(2, "0");
            return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
        };
        const fields = [
            ["opens_at", "Registration opens", "datetime-local"],
            ["closes_at", "Registration closes", "datetime-local"],
            ["course_limit", "Maximum registered courses", "number"],
            ["reason", "Reason for publication", "textarea"],
        ];
        const form = await buildForm(
            fields,
            {
                opens_at: local(saved.definition?.opens_at),
                closes_at: local(saved.definition?.closes_at),
                course_limit: saved.definition?.course_limit ?? "",
            },
            { layout: "registration_window" },
        );
        if (!body.isConnected) return;
        form.elements.opens_at.step = "1";
        form.elements.closes_at.step = "1";
        form.elements.course_limit.min = "1";
        form.elements.course_limit.max = "1000";
        form.elements.reason.maxLength = 1000;
        const status = node("p", { role: "status", class: "form-text" });
        let view = 0;
        const summary = (definition) =>
            recordSummary([
                ["Term", definition.term_code],
                ["Opens", new Date(definition.opens_at.replace(" ", "T") + "Z").toLocaleString()],
                ["Closes", new Date(definition.closes_at.replace(" ", "T") + "Z").toLocaleString()],
                ["Course limit", definition.course_limit],
                ["Publication timezone", definition.timezone],
            ]);
        function edit() {
            view++;
            body.replaceChildren(
                node("h3", { class: "h5" }, saved.term.code),
                node(
                    "p",
                    { class: "form-text" },
                    `Times use your browser timezone: ${Intl.DateTimeFormat().resolvedOptions().timeZone}. Exact UTC instants are retained. Authorized staff keep their existing registration-window override; course limits still apply.`,
                ),
                node(
                    "p",
                    { class: "alert alert-info" },
                    saved.published
                        ? `Published revision ${saved.version}. New publications govern future registrations; earlier enrollment decisions stay unchanged.`
                        : "No publication is recorded. Existing setup, if present, has unknown earlier approval history.",
                ),
                form,
                status,
                button("Policy history", history),
                button(
                    "Back to term",
                    () => {
                        view++;
                        return back();
                    },
                    "btn-link",
                ),
            );
        }
        async function history() {
            const version = ++view;
            const page = await request(base + "page/", "POST", {});
            if (!body.isConnected || view !== version) return;
            const entries = node("div");
            const add = (data) => {
                for (const item of data.items)
                    entries.append(
                        node(
                            "section",
                            { class: "border rounded p-3 mb-3" },
                            node("h3", { class: "h5" }, `Revision ${item.version}`),
                            summary(item.definition),
                            node("p", { class: "form-text" }, `Recorded ${item.recorded_at}`),
                            item.observed_previous
                                ? node(
                                      "p",
                                      {},
                                      "Earlier setup was observed here. Its original approval time and author are unknown.",
                                  )
                                : null,
                        ),
                    );
            };
            let cursor = page.next_cursor;
            const more = button("More history", async () => {
                const next = await request(base + "page/", "POST", { cursor });
                if (!body.isConnected || view !== version) return;
                add(next);
                cursor = next.next_cursor;
                more.hidden = !cursor;
            });
            more.hidden = !cursor;
            add(page);
            body.replaceChildren(
                node("h3", {}, "Registration policy history"),
                page.items.length ? entries : node("p", {}, "No published policy history."),
                more,
                button("Back to policy", edit),
            );
        }
        const review = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review policy",
        );
        form.append(node("div", { class: "record-form-actions" }, review));
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const version = ++view;
                const input = () => ({
                    opens_at: new Date(form.elements.opens_at.value)
                        .toISOString()
                        .replace(".000Z", "+00:00"),
                    closes_at: new Date(form.elements.closes_at.value)
                        .toISOString()
                        .replace(".000Z", "+00:00"),
                    course_limit: Number(form.elements.course_limit.value),
                    reason: form.elements.reason.value,
                    version: saved.version,
                });
                const sent = input();
                const result = await request(base + "review/", "POST", sent);
                if (!body.isConnected || view !== version) return;
                if (JSON.stringify(input()) !== JSON.stringify(sent)) {
                    status.textContent = "Your fields changed. Review the current values again.";
                    return;
                }
                let accepted = false;
                body.replaceChildren(
                    node("h3", {}, "Review registration policy"),
                    summary(result.definition),
                    node("p", {}, "Completed registrations and charges keep their original facts."),
                    node("p", {}, `Reason: ${sent.reason}`),
                    button("Revise", edit),
                    button(
                        "Publish policy",
                        async () => {
                            if (accepted) return;
                            await request(base + "publish/", "POST", {
                                review_token: result.review_token,
                            });
                            accepted = true;
                            if (body.isConnected && view === version) await back();
                        },
                        "btn-primary",
                    ),
                );
            }, review);
        });
        edit();
    }
    async function policy(back = null) {
        const body = openRegistration("Registration policy", "REGISTRATION SETTINGS");
        const value = await request("/sis/registration-policy/");
        if (!body.isConnected) return;
        const choices = ["block — Block confirmed overlaps", "warn — Warn and allow registration"];
        const facultyChoices = [
            "block — Block overlapping classes",
            "warn — Warn and allow publication",
        ];
        const dropChoices = [
            "block — Require linked courses to be dropped together",
            "warn — Warn and allow individual drops",
        ];
        const form = await buildForm(
            [
                ["mode", "When a student’s published meetings overlap", "choice", choices],
                [
                    "faculty_mode",
                    "When a faculty member’s published classes overlap",
                    "choice",
                    facultyChoices,
                ],
                [
                    "prior_completion",
                    "Allow qualifying prior completion to satisfy co-requisites",
                    "checkbox",
                ],
                [
                    "drop_mode",
                    "When a drop leaves a co-requisite unsupported",
                    "choice",
                    dropChoices,
                ],
            ],
            {
                mode: choices.find((item) => item.startsWith(value.mode)),
                faculty_mode: facultyChoices.find((item) =>
                    item.startsWith(value.faculty_mode || "block"),
                ),
                prior_completion: value.corequisites.prior_completion,
                drop_mode: dropChoices.find((item) =>
                    item.startsWith(value.corequisites.drop_mode),
                ),
            },
            { layout: "registration_policy" },
        );
        if (!body.isConnected) return;
        const save = node("button", { type: "submit", class: "btn btn-primary" }, "Save policy");
        form.append(node("div", { class: "record-form-actions" }, save));
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                await request("/sis/registration-policy/", "POST", {
                    mode: form.elements.mode.value.split(" — ")[0],
                    revision: value.revision,
                    faculty_mode: form.elements.faculty_mode.value.split(" — ")[0],
                    prior_completion: form.elements.prior_completion.checked,
                    drop_mode: form.elements.drop_mode.value.split(" — ")[0],
                    coreq_revision: value.corequisites.revision,
                });
                if (body.isConnected) await policy(back);
            }, save);
        });
        body.append(
            node(
                "p",
                {},
                "Student overlap policy applies to student and staff registration, including planned schedules. Faculty overlap policy applies when publishing a section meeting plan; earlier publications keep their reviewed policy. Turn off prior completion to require co-requisites in the same academic term. Accepted registrations retain their original requirement and prior-completion policy; the current drop policy controls whether an unsupported drop is blocked or warned.",
            ),
            form,
            back || ui.closeDrawer
                ? button("Back to registration", back || ui.closeDrawer, "btn-link")
                : null,
        );
    }
    async function plan(student, back, identity = null) {
        const selected = new Map();
        let body = openRegistration(
            "Plan registration",
            "SELECT SECTIONS → REVIEW SCHEDULE → CONFIRM",
        );
        const form = await buildForm(
            [["section_id", "Add a course section", "lookup", "sections"]],
            {},
            { layout: "registration_section" },
        );
        if (!body.isConnected) return;
        const list = node("div", { "aria-live": "polite" });
        const add = node(
            "button",
            { type: "submit", class: "btn btn-outline-primary" },
            "Add section",
        );
        (form.querySelector("fieldset") || form).append(
            node("div", { class: "record-form-actions" }, add),
        );
        function draw() {
            list.replaceChildren(
                node("p", { role: "status" }, `${selected.size} of 10 sections selected.`),
            );
            if (selected.size)
                list.append(
                    grid(
                        ["Section", "Action"],
                        [...selected].map(([id, label]) => [
                            label,
                            button("Remove section", () => {
                                selected.delete(id);
                                draw();
                            }),
                        ]),
                        "Selected course sections",
                    ),
                );
            review.disabled = !selected.size;
        }
        function edit() {
            body = openRegistration(
                "Plan registration",
                "SELECT SECTIONS → REVIEW SCHEDULE → CONFIRM",
            );
            body.append(
                identity
                    ? recordSummary([
                          ["Student", `${identity.given_name} ${identity.family_name}`],
                          ["Institutional ID", identity.institutional_id],
                      ])
                    : null,
                node(
                    "p",
                    {},
                    "Select up to ten sections. Review prerequisites, timetable and actual charges together before anything is saved.",
                ),
                form,
                list,
                review,
                button("Back to student", back, "btn-link"),
            );
            draw();
        }
        const reviewSchedule = window.createRegistrationPlanReview({
            node,
            request,
            money,
            open: (title) => openRegistration(title, "REVIEWED REGISTRATION"),
            button: (label, action, primary) =>
                button(label, action, primary ? "btn-primary" : "btn-outline-primary"),
        });
        const review = button(
            "Review schedule",
            () =>
                reviewSchedule({
                    student,
                    sections: [...selected.keys()],
                    reviewUrl: "/sis/registration-plans/review/",
                    commitUrl: "/sis/registration-plans/commit/",
                    onRevise: edit,
                    onComplete: async (result) => {
                        const saved = receipt(
                            result,
                            () => plan(student, back, identity),
                            "Plan another schedule",
                        );
                        saved.append(button("Back to student", back, "btn-link"));
                    },
                }),
            "btn-primary",
        );
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(() => {
                if (!form.reportValidity()) return;
                const control = form.elements.section_id;
                if (selected.has(control.value))
                    throw new Error("This section is already selected.");
                if (selected.size >= 10) throw new Error("Select at most ten sections.");
                selected.set(control.value, control.selectedOptions[0].textContent);
                draw();
            }, add);
        });
        edit();
    }

    async function single(student, back, draft = {}) {
        let body = openRegistration("Register student", "SECTION → ELIGIBILITY & FEES → CONFIRM");
        const form = await buildForm(
            [["section_id", "Course section", "lookup", "sections"]],
            draft,
            { layout: "registration_section" },
        );
        if (!body.isConnected) return;
        let generation = 0,
            confirmation = null;
        let creditControl = null, creditChoices = {};
        async function reviewSelection(section) {
            if (creditControl) creditChoices = { [student]: creditControl.read() };
            const source = body,
                turn = ++generation;
            if (confirmation) confirmation.disabled = true;
            const reviewed = await request("/sis/registrations/review/", "POST", {
                student_id: student,
                section_id: section,
                by_staff: true,
                credit_choices: creditChoices,
            });
            if (!source.isConnected || turn !== generation) return;
            const panel = openRegistration(
                "Review registration",
                "SECTION → ELIGIBILITY & FEES → CONFIRM",
            );
            body = panel;
            const confirm = button(
                "Confirm registration",
                async () => {
                    await request("/sis/registrations/", "POST", {
                        student_id: student,
                        section_id: section,
                        by_staff: true,
                        credit_choices: creditChoices,
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
            creditControl = reviewed.students[0]?.enrollment_id ? null : window.createRegistrationCreditInput(ui, reviewed.section,
                creditChoices[student] ?? reviewed.students[0]?.credits_milli, () => { confirm.disabled = true; });
            panel.append(
                creditControl?.card || null,
                schedule.registration(reviewed, money),
                ui.graduate && reviewed.students[0]?.readiness?.some(check => check.code.startsWith('graduate_'))
                    ? button(ui.graduateLabels.open_approvals, () => ui.graduate.open(student, section,
                        () => single(student, back, { section_id: section }), reviewed.students[0].assignment_id)) : null,
                (reviewed.students[0]?.waitlist?.enabled ||
                    reviewed.students[0]?.waitlist?.entry_id) &&
                    ui.can("sis:registration:waitlist")
                    ? button("Waitlist options", () =>
                          window
                              .createSectionWaitlist(ui)
                              .place(student, { id: section }, () =>
                                  single(student, back, { section_id: section }),
                              ),
                      )
                    : null,
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
        const review = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review registration",
        );
        form.append(node("div", { class: "record-form-actions" }, review));
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (form.reportValidity()) await reviewSelection(form.elements.section_id.value);
            }, review);
        });
        body.append(form, button("Back to student", back, "btn-link"));
    }
    function receipt(result, again, againLabel = "Start another group") {
        if (result.drop_plan_id) {
            const body = openRegistration("Course drops completed", "REGISTRATION RECEIPT");
            body.append(
                node(
                    "p",
                    { role: "status" },
                    `${result.removed} courses removed; ${result.withdrawn} withdrawn.`,
                ),
                node("p", {}, "Reference: ", node("code", {}, result.drop_plan_id)),
                button(againLabel, again, "btn-primary"),
            );
            return body;
        }

        const planned = !!result.plan_id;
        const body = openRegistration(
            planned ? "Schedule registered" : "Batch completed",
            "REGISTRATION RECEIPT",
        );
        body.append(
            node(
                "p",
                { role: "status" },
                planned
                    ? `${result.created} registrations created together.`
                    : `${result.created} ${result.created === 1 ? "registration" : "registrations"} created. ${result.existing} existing ${result.existing === 1 ? "registration" : "registrations"} kept.`,
            ),
            node("p", {}, "The registrations, applicable charges and receipt were saved together."),
            node(
                "p",
                { class: "registration-reference" },
                planned ? "Schedule reference: " : "Batch reference: ",
                node("code", {}, result.plan_id || result.batch_id),
            ),
            button(againLabel, again, "btn-primary"),
        );
        return body;
    }
    async function findReceipt(back = null) {
        const body = openRegistration("Find a completed batch", "REGISTRATION RECEIPT");
        const form = await buildForm(
            [["operation_id", "Batch reference"]],
            {},
            { layout: "registration_receipt" },
        );
        if (!body.isConnected) return;
        form.elements.operation_id.maxLength = 26;
        const save = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Check completed batch",
        );
        form.append(node("div", { class: "record-form-actions" }, save));
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const id = form.elements.operation_id.value.trim();
                if (!/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/.test(id))
                    throw new Error("Enter a valid batch reference.");
                const result = await request(`/sis/registration-batches/${id}/`);
                if (!body.isConnected) return;
                const saved = receipt(result, () => start());
                if (back) saved.append(button("Back to registration", back));
            }, save);
        });
        body.append(
            node(
                "p",
                { class: "form-text" },
                "Only the staff member who submitted the batch can read its receipt, while their registration permission remains active.",
            ),
            form,
            back ? button("Back to registration", back) : null,
        );
    }
    async function start(presetSection = "", presetStudents = [], back = null, backLabel = "Back to report selection") {
        if (presetStudents.length > 25) throw new Error("Select no more than 25 students.");
        const chosen = new Map(
            presetStudents.map((student) => [
                student.id,
                `${student.institutional_id || "ID not assigned"} · ${student.name}`,
            ]),
        );
        const allocationText = (key, fallback) => ui.allocationLabels?.[key] || fallback;
        async function groupRequest(body, ...args) {
            try { return await request(...args); }
            catch (error) {
                if (!body.isConnected) return;
                if (error.status === 403) {
                    chosen.clear();
                    body.replaceChildren(node('p', { class: 'alert alert-warning', role: 'alert' },
                        allocationText('access_changed', 'Access to this registration group changed. Reopen the task after checking access.')));
                }
                throw error;
            }
        }
        let sectionId = presetSection;
        async function selection() {
            const body = openRegistration("Register a group", "BATCH REGISTRATION");
            let selectionForm = null;
            const rememberSection = () => {
                if (selectionForm) sectionId = selectionForm.elements.section_id.value;
            };
            const tools = node(
                "div",
                {
                    class: "inline-actions mb-3",
                    role: "group",
                    "aria-label": "Registration tools",
                },
                button(
                    "Find a completed batch",
                    () => {
                        rememberSection();
                        return findReceipt(selection);
                    },
                    "btn-outline-secondary",
                ),
            );
            if (ui.can?.("sis:registration:policy"))
                tools.append(
                    button("Registration policy", () => {
                        rememberSection();
                        return policy(selection);
                    }),
                );
            body.append(
                node(
                    "p",
                    { class: "form-text" },
                    "Choose one section and up to 25 students. Review before confirming. Eligibility and capacity are checked on confirmation; one failure rolls back the entire batch.",
                ),
                tools,
            );
            if (back) tools.append(button(backLabel, back));
            const form = await buildForm(
                [
                    ["section_id", "Course section", "lookup", "sections"],
                    ["student_id", "Find a student", "lookup", "registrationstudents", false],
                ],
                { section_id: sectionId },
                { layout: "registration_group" },
            );
            if (!body.isConnected) return;
            selectionForm = form;
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
                    node(
                        "h3",
                        {},
                        `${chosen.size} selected ${chosen.size === 1 ? "student" : "students"}`,
                    ),
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
                    selected.append(
                        node("p", { class: "form-text" }, "Add students to build this group."),
                    );
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
            const groups = form.querySelectorAll("fieldset");
            (groups[groups.length - 1] || form).append(
                node("div", { class: "record-form-actions" }, add),
                selected,
            );
            form.append(node("div", { class: "record-form-actions" }, review));
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
                    const reviewed = await groupRequest(body,
                        "/sis/registration-batches/review/",
                        "POST",
                        payload,
                    );
                    if (!body.isConnected) return;
                    await confirmation(reviewed, payload);
                }, review);
            });
            body.append(form);
        }
        async function confirmation(reviewed, payload) {
            const section = reviewed.section;
            const body = openRegistration("Review group registration", "CONFIRM THE SELECTION");
            const creditControls = new Map();
            body.append(
                button("Change selection", selection, "btn-link"),
                ...(back ? [button(backLabel, back, 'btn-link')] : []),
                node("h3", {}, section.code),
                recordSummary([
                    ["Course", `${section.course_code} · ${section.course_title}`],
                    [section.student_selectable_credits ? creditText("default", "Default academic credits") : "Credits",
                        Number(section.credits_milli) / 1000],
                    ["Seats remaining", section.seats_remaining],
                    [
                        "Section fee",
                        `${money(section.fee_minor, section.currency)} per new registration`,
                    ],
                ]),
                node(
                    "p",
                    { class: "form-text" },
                    "Review each student’s proposed charges below. Existing active enrollments are kept without a second charge. This review expires after 20 minutes; confirmation rechecks the selection and saves the group atomically.",
                ),
            );
            if (reviewed.students.some(student => student.waitlist?.queue_version != null))
                body.append(node('p', { class: 'alert alert-info' }, allocationText('review_help',
                    'Waiting students are processed in accepted queue order. Every earlier request must be included before a later request can register in this group.')));
            if (!section.active)
                body.append(
                    node(
                        "p",
                        { class: "alert alert-warning" },
                        "This section or course is inactive. New registrations cannot be added.",
                    ),
                );
            const students = node(
                "section",
                {
                    class: "registration-review-roster",
                    "aria-label": "Reviewed registration group",
                    tabindex: "0",
                },
                node("h3", { class: "h6" }, "Review each student"),
                ...reviewed.students.map((student) =>
                    node(
                        "article",
                        { class: "registration-review-card" },
                        node("h4", { class: "h6" }, `${student.given_name} ${student.family_name}`),
                        node("p", { class: "form-text" }, student.institutional_id),
                        recordSummary([
                            [
                                "Activity",
                                node(
                                    "div",
                                    {},
                                    student.active ? "Active" : "Inactive",
                                    schedule.readiness(student.readiness),
                                ),
                            ],
                            [
                                "Prerequisites",
                                node(
                                    "div",
                                    {},
                                    student.prerequisites?.status === "missing"
                                        ? `Missing ${student.prerequisites.missing_count} ${student.prerequisites.missing_count === 1 ? "prerequisite" : "prerequisites"}`
                                        : {
                                              permission: "Prerequisite exception approved",
                                              met: "Verified for this pursuit",
                                              not_required: "No course prerequisites",
                                              not_rechecked: ["A", "N"].includes(
                                                  student.enrollment_status,
                                              )
                                                  ? "Existing registration — unchanged"
                                                  : "Not checked — closed enrollment",
                                          }[student.prerequisites?.status] ||
                                              "Checked on confirmation",
                                    schedule.prerequisites(
                                        student.prerequisites,
                                        reviewed.section.prerequisite_courses,
                                    ),
                                    schedule.corequisites(
                                        student.corequisites,
                                        reviewed.section.corequisite_courses,
                                    ),
                                ),
                            ],
                            ["Timetable", schedule.text(student.schedule)],
                            [
                                "Registration",
                                ["A", "N"].includes(student.enrollment_status)
                                    ? "Already enrolled — keep"
                                    : student.enrollment_id
                                      ? "Closed enrollment — registrar review required"
                                      : "New registration",
                            ],
                        ]),
                    ),
                ),
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
                    {
                        class: "mb-3",
                        "data-charge-review": "",
                        "data-charges-attention": String(
                            student.charges.total_minor == null ||
                                student.charges.problems.length > 0,
                        ),
                    },
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
                    const result = await groupRequest(body, "/sis/registration-batches/commit/", "POST", {
                        ...payload,
                        operation_id: reviewed.operation_id,
                        revision: reviewed.revision,
                        review_token: reviewed.review_token,
                    });
                    if (!body.isConnected) return;
                    await refreshDirectory();
                    if (!body.isConnected) return;
                    receipt(result, back || (() => start(sectionId)), back ? backLabel : undefined);
                },
                "btn-primary",
            );
            body.append(schedule.guidance(reviewed, false, body));
            submit.disabled = !schedule.canConfirm(reviewed);
            for (const student of reviewed.students) {
                if (student.enrollment_id) continue;
                const credit = window.createRegistrationCreditInput(ui, section,
                    payload.credit_choices?.[student.id] ?? student.credits_milli,
                    () => { submit.disabled = true; }, `${student.given_name} ${student.family_name}`);
                if (credit) { creditControls.set(student.id, credit); body.append(credit.card); }
            }
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
                        if (creditControls.size) payload = { ...payload, credit_choices: Object.fromEntries(
                            [...creditControls].map(([id, credit]) => [id, credit.read()])) };
                        submit.disabled = true;
                        const next = await groupRequest(body,
                            "/sis/registration-batches/review/",
                            "POST",
                            payload,
                        );
                        if (body.isConnected) await confirmation(next, payload);
                    }),
                    button("Check batch receipt", async () => {
                        try {
                            const result = await groupRequest(body,
                                `/sis/registration-batches/${reviewed.operation_id}/`,
                            );
                            if (!body.isConnected) return;
                            receipt(result, back || (() => start(sectionId)), back ? backLabel : undefined);
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
        const pendingView = openRegistration("Registration history", "CONNECTED WORKSPACE");
        pendingView.append(
            node("p", { role: "status", class: "form-text" }, "Loading record details…"),
        );
        const page = await request(`/sis/students/${student}/registration-history/`, "POST", {
            after,
        });
        if (!pendingView.isConnected) return;
        const body = openRegistration("Registration history", "RESTRICTED REGISTRAR HISTORY");
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
            if (record.selected_credits_milli != null) panel.append(node('p', { class: 'form-text' },
                `${creditText('original_choice', 'Originally selected academic credits')}: ${record.selected_credits_milli / 1000}`));
            const registrationWindow = record.registration_window;
            if (registrationWindow?.opens_at)
                panel.append(
                    node(
                        "p",
                        { class: "form-text" },
                        `Accepted registration window: ${new Date(registrationWindow.opens_at).toLocaleString()} through ${new Date(registrationWindow.closes_at).toLocaleString()}; maximum ${registrationWindow.course_limit} courses. ` +
                            (registrationWindow.status === "published"
                                ? `Published revision ${registrationWindow.version}.`
                                : "Earlier publication history is not recorded."),
                    ),
                );
            const prerequisites = record.prerequisites;
            panel.append(
                node(
                    "p",
                    { class: "form-text" },
                    prerequisites?.status === "verified"
                        ? prerequisites.requirement_group_count != null
                            ? prerequisites.nested_rule
                                ? `Prerequisites at acceptance: the nested rule across ${prerequisites.requirement_group_count} requirements was satisfied using ${prerequisites.supporting_record_count} verified academic records.`
                                : `Prerequisites at acceptance: ${prerequisites.required_group_count ?? prerequisites.requirement_group_count} of ${prerequisites.requirement_group_count} prerequisite requirements satisfied using ${prerequisites.supporting_record_count} verified academic records.`
                            : `Prerequisites at acceptance: ${prerequisites.required_course_count} required ${prerequisites.required_course_count === 1 ? "course" : "courses"} matched to ${prerequisites.supporting_record_count} verified academic ${prerequisites.supporting_record_count === 1 ? "record" : "records"}.`
                        : prerequisites?.status === "permission"
                          ? "Registration accepted with a registrar prerequisite exception. This did not award prerequisite completion or credit."
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
                            reinstated: "Withdrawal reinstated",
                        }[item.action] || "Enrollment decision",
                        item.section_sequence ?? "—",
                        item.effective_at,
                        item.recorded_at,
                    ]),
                    "Recorded registration decisions",
                ),
            );
            if (
                record.status === "W" &&
                !record.earlier_history_unknown &&
                ui.can("sis:registration:reinstate") &&
                ui.can("sis:gradebook:closed")
            ) {
                panel.append(
                    button("Reinstate this withdrawal", () =>
                        window
                            .createEnrollmentReinstatement(ui)
                            .open(student, record, () => history(student, back, after, stack)),
                    ),
                );
            }
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
                button("Next history page", () =>
                    history(student, back, page.next_after, [...stack, after]),
                ),
            );
        body.append(nav);
    }
    return { single, plan, changeSection, policy, windowPolicy, start, history };
};
