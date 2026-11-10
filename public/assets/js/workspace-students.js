/* Connected student activity, programs and registration. No private browser storage. */
window.createWorkspaceStudents = function (ui) {
    "use strict";
    const { node, request, task, can, openDrawer, openPerson, table, badge, flow, registration, showAudit } =
        ui;
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

    const status = (value) => (value === null ? "Unknown" : value ? "Active" : "Inactive");
    async function activity(record, back) {
        const page = await request(`/sis/students/${record.id}/activity/history/`, "POST", {});
        const body = openDrawer("Review student activity", "REGISTRAR DECISION");
        body.append(
            button("Back to student", back),
            node(
                "p",
                {},
                "Record when the status takes effect and why. Inactive students cannot add registrations. Existing courses, credits, charges, sign-in and employment stay connected.",
            ),
        );
        const descriptors = [
            ["active", "Active student record", "checkbox"],
            ["effective_on", "Effective date", "date"],
            ["reason", "Reason for this decision", "textarea"],
        ];
        const form = await ui.buildForm(descriptors, { active: record.active, effective_on: page.today });
        form.elements.effective_on.max = page.today;
        form.elements.effective_on.required = true;
        form.elements.reason.required = true;
        form.elements.reason.maxLength = 10000;
        const review = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review activity decision",
        );
        form.append(review);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const data = ui.formData(form, descriptors);
                const preview = await request(`/sis/students/${record.id}/activity/review/`, "POST", {
                    ...data,
                    revision: record.revision,
                });
                const confirmBody = openDrawer("Confirm student activity", "REVIEWED DECISION");
                confirmBody.append(
                    button("Revise decision", () => activity(record, back)),
                    node("h3", {}, `${status(preview.requested_active)} from ${preview.effective_on}`),
                    node(
                        "dl",
                        { class: "detail-list" },
                        node(
                            "div",
                            {},
                            node("dt", {}, "Previously known on that date"),
                            node("dd", {}, status(preview.active_on_date)),
                        ),
                        node(
                            "div",
                            {},
                            node("dt", {}, "Current status before"),
                            node("dd", {}, status(preview.current_active)),
                        ),
                        node(
                            "div",
                            {},
                            node("dt", {}, "Current status after"),
                            node("dd", {}, status(preview.resulting_active)),
                        ),
                    ),
                );
                if (preview.resulting_active !== preview.requested_active)
                    confirmBody.append(
                        node(
                            "p",
                            { class: "alert alert-info" },
                            "A later effective decision continues to control today's status. This decision changes the earlier period only.",
                        ),
                    );
                confirmBody.append(
                    node(
                        "p",
                        { class: "form-text" },
                        "Earlier history before tracking began is unknown. This decision retains its effective date, recording time and protected reason; it does not drop courses or reverse charges.",
                    ),
                    button(
                        "Confirm activity decision",
                        async () => {
                            await request(`/sis/students/${record.id}/activity/commit/`, "POST", {
                                review_token: preview.review_token,
                            });
                            await back();
                        },
                        "btn-primary",
                    ),
                );
                confirmBody.querySelector("h3").tabIndex = -1;
                confirmBody.querySelector("h3").focus();
            }, review);
        });
        body.append(form);
    }
    async function activityHistory(id, back, cursor = null, previous = []) {
        const page = await request(`/sis/students/${id}/activity/history/`, "POST", cursor ? { cursor } : {});
        const body = openDrawer("Activity history", "REGISTRAR RECORD");
        body.append(
            button("Back to student", back),
            node("p", {}, `Current status: ${status(page.active)}`),
            node(
                "p",
                { class: "form-text" },
                "This timeline lists decisions in recording order. Effective dates determine when each status applies. Earlier history is unknown; an observation records the status seen when tracking began. Protected reasons are not included here.",
            ),
        );
        const sources = {
            observed_existing: "Observed current status",
            registrar: "Registrar decision",
            hiatus: "Hiatus",
            hiatus_return: "Return from hiatus",
        };
        body.append(
            page.items.length
                ? grid(
                      ["Status", "Effective date", "Recorded", "Source"],
                      page.items.map((item) => [
                          status(item.active),
                          item.effective_on,
                          item.recorded_at,
                          sources[item.source] || "Decision",
                      ]),
                      "Student activity decisions",
                  )
                : node("p", {}, "No activity decisions have been recorded yet."),
        );
        const controls = node("div", { class: "inline-actions" });
        if (previous.length)
            controls.append(
                button("Previous activity page", () =>
                    activityHistory(id, back, previous[previous.length - 1], previous.slice(0, -1)),
                ),
            );
        if (page.next_cursor)
            controls.append(
                button("Next activity page", () =>
                    activityHistory(id, back, page.next_cursor, [...previous, cursor]),
                ),
            );
        body.append(controls);
    }

    async function programStatus(id, item, back, preset = {}) {
        const closing = item.status === "active";
        const body = openDrawer(
            closing ? "Close program assignment" : "Reopen program assignment",
            "REGISTRAR DECISION",
        );
        body.append(
            button("Back to student", back),
            node("p", {}, `${item.program_code} · ${item.program_title}`),
            node(
                "p",
                { class: "form-text" },
                "This status change takes effect when confirmed. It preserves the program's entry term, degree requirements, credits and financial history. Closing an assignment does not record graduation or withdraw the student from courses.",
            ),
        );
        const descriptors = [["reason", "Reason for this program decision", "textarea"]];
        const form = await ui.buildForm(descriptors, preset);
        form.elements.reason.required = true;
        form.elements.reason.maxLength = 10000;
        const review = node("button", { type: "submit", class: "btn btn-primary" }, "Review program status");
        form.append(review);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const data = ui.formData(form, descriptors);
                const preview = await request(
                    `/sis/students/${id}/programs/${item.id}/status/review/`,
                    "POST",
                    { ...data, active: !closing, revision: item.revision },
                );
                const confirmation = openDrawer("Confirm program status", "REVIEWED DECISION");
                confirmation.append(
                    button("Revise program decision", () => programStatus(id, item, back, data)),
                    node("h3", {}, `${preview.program.code}: ${preview.before} → ${preview.after}`),
                    node(
                        "p",
                        {},
                        `Entry term: ${preview.entry_term.code}. Takes effect when confirmed on ${preview.effective_on}.`,
                    ),
                    node(
                        "p",
                        { class: "form-text" },
                        "The previous status and this decision will be retained. Earlier history before tracking began is unknown. Your reason is protected and is not included in the ordinary status timeline.",
                    ),
                    button(
                        closing ? "Confirm program closure" : "Confirm program reopening",
                        async () => {
                            await request(`/sis/students/${id}/programs/${item.id}/status/commit/`, "POST", {
                                review_token: preview.review_token,
                            });
                            await back();
                        },
                        "btn-primary",
                    ),
                );
                const heading = confirmation.querySelector("h3");
                heading.tabIndex = -1;
                heading.focus();
            }, review);
        });
        body.append(form);
    }
    async function programStatusHistory(id, item, back, cursor = null, previous = []) {
        const page = await request(
            `/sis/students/${id}/programs/${item.id}/status/history/`,
            "POST",
            cursor ? { cursor } : {},
        );
        const body = openDrawer("Program status history", "REGISTRAR RECORD");
        body.append(
            button("Back to student", back),
            node("h3", {}, `${item.program_code} · ${item.program_title}`),
            node("p", {}, `Current assignment status: ${page.status}`),
            node(
                "p",
                { class: "form-text" },
                page.earlier_history_unknown === false
                    ? "This history starts with the recorded assignment. Protected reasons and raw event details are excluded."
                    : "An observation records the status seen when tracking began. Earlier decisions are unknown. Protected reasons and raw event details are excluded from this timeline.",
            ),
        );
        if (page.assignment_source) {
            const source = page.assignment_source;
            body.append(
                node(
                    "p",
                    { class: "form-text" },
                    `As assigned: ${source.program_code} · ${source.program_title}; entry term ${source.entry_term_code}; ` +
                        (source.cohort
                            ? `cohort ${source.cohort.year_code}, curriculum revision ${source.cohort.revision}.`
                            : "cohort was not linked at assignment."),
                ),
            );
        }
        body.append(
            page.items.length
                ? grid(
                      ["Status", "Effective", "Recorded", "Record"],
                      page.items.map((row) => [
                          row.status,
                          row.effective_at,
                          row.recorded_at,
                          row.kind === "assignment"
                              ? "Program assigned"
                              : row.kind === "observation"
                                ? "Observed current assignment"
                                : "Registrar decision",
                      ]),
                      "Program status decisions",
                  )
                : node("p", {}, "No program status decisions have been recorded yet."),
        );
        const controls = node("div", { class: "inline-actions" });
        if (previous.length)
            controls.append(
                button("Previous program history page", () =>
                    programStatusHistory(
                        id,
                        item,
                        back,
                        previous[previous.length - 1],
                        previous.slice(0, -1),
                    ),
                ),
            );
        if (page.next_cursor)
            controls.append(
                button("Next program history page", () =>
                    programStatusHistory(id, item, back, page.next_cursor, [...previous, cursor]),
                ),
            );
        body.append(controls);
    }

    async function primaryProgram(record, item, back, preset = {}) {
        const body = openDrawer("Review primary program", "REGISTRAR DECISION");
        body.append(
            button("Back to student", back),
            node("h3", {}, `${item.program_code} · ${item.program_title}`),
            node(
                "p",
                { class: "form-text" },
                "Choose this existing assignment as primary when you confirm. Future rules that use the primary program will use this selection. Other programs, entry terms, degree requirements, earned credits and posted charges stay connected to their existing records.",
            ),
        );
        const descriptors = [["reason", "Reason for this primary-program decision", "textarea"]];
        const form = await ui.buildForm(descriptors, preset);
        form.elements.reason.required = true;
        form.elements.reason.maxLength = 10000;
        const review = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review primary selection",
        );
        form.append(review);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const data = ui.formData(form, descriptors);
                const preview = await request(`/sis/students/${record.id}/primary-program/review/`, "POST", {
                    ...data,
                    assignment_id: item.id,
                    revision: record.revision,
                });
                const confirmation = openDrawer("Confirm primary program", "REVIEWED DECISION");
                confirmation.append(
                    button("Revise primary decision", () => primaryProgram(record, item, back, data)),
                    node("h3", {}, `${preview.before.program_code} → ${preview.after.program_code}`),
                    node(
                        "dl",
                        { class: "detail-list" },
                        node(
                            "div",
                            {},
                            node("dt", {}, "Previous primary pursuit"),
                            node(
                                "dd",
                                {},
                                `${preview.before.program_title} · ${preview.before.entry_term_code}`,
                            ),
                        ),
                        node(
                            "div",
                            {},
                            node("dt", {}, "New primary pursuit"),
                            node(
                                "dd",
                                {},
                                `${preview.after.program_title} · ${preview.after.entry_term_code}`,
                            ),
                        ),
                    ),
                    node("p", {}, `Takes effect when confirmed on ${preview.effective_on}.`),
                    node(
                        "p",
                        { class: "form-text" },
                        "The previous selection and this decision will be retained. Earlier history before tracking began is unknown. Existing charges will not be recalculated.",
                    ),
                    button(
                        "Confirm primary program",
                        async () => {
                            await request(`/sis/students/${record.id}/primary-program/commit/`, "POST", {
                                assignment_id: item.id,
                                review_token: preview.review_token,
                            });
                            await back();
                        },
                        "btn-primary",
                    ),
                );
                const heading = confirmation.querySelector("h3");
                heading.tabIndex = -1;
                heading.focus();
            }, review);
        });
        body.append(form);
    }
    async function primaryHistory(id, back, cursor = null, previous = []) {
        const page = await request(
            `/sis/students/${id}/primary-program/history/`,
            "POST",
            cursor ? { cursor } : {},
        );
        const body = openDrawer("Primary-program history", "REGISTRAR RECORD");
        body.append(
            button("Back to student", back),
            node(
                "p",
                {},
                `Current primary pursuit: ${page.current_selection.program_code} · ${page.current_selection.entry_term_code}`,
            ),
            node(
                "p",
                { class: "form-text" },
                "These labels are retained as recorded. An observation describes the selection seen when tracking began; earlier selections are unknown. Protected reasons and actor details are excluded.",
            ),
        );
        body.append(
            page.items.length
                ? grid(
                      ["Program as recorded", "Pursuit entry term", "Effective", "Recorded", "Record"],
                      page.items.map((row) => [
                          `${row.selection.program_code} · ${row.selection.program_title}`,
                          row.selection.entry_term_code,
                          row.effective_at,
                          row.recorded_at,
                          row.kind === "observation" ? "Observed current selection" : "Registrar selection",
                      ]),
                      "Primary-program decisions",
                  )
                : node("p", {}, "No primary-program decisions have been recorded yet."),
        );
        const controls = node("div", { class: "inline-actions" });
        if (previous.length)
            controls.append(
                button("Previous primary history page", () =>
                    primaryHistory(id, back, previous[previous.length - 1], previous.slice(0, -1)),
                ),
            );
        if (page.next_cursor)
            controls.append(
                button("Next primary history page", () =>
                    primaryHistory(id, back, page.next_cursor, [...previous, cursor]),
                ),
            );
        body.append(controls);
    }

    async function assignProgram(record, back, preset = {}) {
        const body = openDrawer("Assign an additional program", "REGISTRAR DECISION");
        body.append(
            button("Back to student", back),
            node(
                "p",
                { class: "form-text" },
                "Choose the program and its entry term. The cohort follows the academic year linked to that term. The student's original institutional entry term and primary program stay unchanged.",
            ),
        );
        const descriptors = [
            ["program_id", "Academic program", "lookup", "programs"],
            ["entry_term_id", "Program entry term", "lookup", "terms"],
            ["reason", "Reason for this assignment", "textarea"],
        ];
        const form = await ui.buildForm(descriptors, preset);
        form.elements.reason.required = true;
        form.elements.reason.maxLength = 10000;
        const review = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review program assignment",
        );
        form.append(review);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const data = ui.formData(form, descriptors);
                const preview = await request(`/sis/students/${record.id}/programs/assign/review/`, "POST", {
                    ...data,
                    revision: record.revision,
                });
                const source = preview.source;
                const cohort = source.cohort;
                const confirmation = openDrawer("Confirm program assignment", "REVIEWED DECISION");
                confirmation.append(
                    button("Revise assignment", () => assignProgram(record, back, data)),
                    node("h3", {}, `${source.program_code} · ${source.program_title}`),
                    node(
                        "dl",
                        { class: "detail-list" },
                        node(
                            "div",
                            {},
                            node("dt", {}, "Program entry term"),
                            node("dd", {}, source.entry_term_code),
                        ),
                        node(
                            "div",
                            {},
                            node("dt", {}, "Matriculation cohort"),
                            node(
                                "dd",
                                {},
                                cohort
                                    ? `${cohort.year_code} · curriculum revision ${cohort.revision}${cohort.catalog_id ? "" : " (created on confirmation)"}`
                                    : "Academic calendar review needed",
                            ),
                        ),
                    ),
                    node(
                        "p",
                        {},
                        `The additional assignment becomes active when confirmed on ${preview.effective_on}.`,
                    ),
                );
                if (!cohort)
                    confirmation.append(
                        node(
                            "p",
                            { class: "alert alert-warning" },
                            "This entry term is not linked to an academic year. The assignment will be saved without a cohort; review its academic calendar setup.",
                        ),
                    );
                confirmation.append(
                    node(
                        "p",
                        { class: "form-text" },
                        "This preserves the student's existing programs, earned credits, charges and original entry term. Choose Make primary separately if needed. Degree requirements are selected through their own review.",
                    ),
                    button(
                        "Confirm program assignment",
                        async () => {
                            await request(`/sis/students/${record.id}/programs/assign/commit/`, "POST", {
                                review_token: preview.review_token,
                            });
                            await back();
                        },
                        "btn-primary",
                    ),
                );
                const heading = confirmation.querySelector("h3");
                heading.tabIndex = -1;
                heading.focus();
            }, review);
        });
        body.append(form);
    }

    async function student(id) {
        const record = await request(`/sis/students/${id}/record/`);
        const body = openDrawer(`${record.given_name} ${record.family_name}`, "STUDENT RECORD");
        const back = () => student(id);
        body.append(
            node("p", {}, record.institutional_id),
            badge(record.active ? "Active" : "Inactive"),
            node(
                "dl",
                { class: "detail-list" },
                node(
                    "div",
                    {},
                    node("dt", {}, "Primary program"),
                    node("dd", {}, `${record.primary_program.code} · ${record.primary_program.title}`),
                ),
                node(
                    "div",
                    {},
                    node("dt", {}, "Institutional entry term"),
                    node("dd", {}, record.entry_term.code),
                ),
            ),
        );
        const actions = node("div", { class: "inline-actions" });
        body.append(actions);
        if (can("sis:person:manage"))
            actions.append(button("Open connected person", () => openPerson(record.person_id)));
        if (can("sis:audit:read"))
            actions.append(button("View audit history", () => showAudit("students", id)));
        if (can("sis:student:read"))
            actions.append(
                button("View transcript", async () => {
                    const response = await request(`/sis/students/${id}/transcript/`);
                    const transcript = openDrawer("Academic transcript", "STUDENT RECORD");
                    transcript.append(
                        button("Back to student", back),
                        grid(
                            ["Course", "Title", "Grade", "Action"],
                            (response.rows || response.credits || []).map((item) => [
                                item.course_code,
                                item.course_title,
                                item.grade_code || "—",
                                item.credit_type === "transfer" &&
                                item.source_id &&
                                can("sis:transfer:manage") &&
                                can("sis:student:read")
                                    ? button("Review transfer award", () =>
                                          ui.transferAwards().record(id, item.source_id),
                                      )
                                    : item.credit_type === "institutional" && item.section_id
                                      ? node(
                                            "div",
                                            { class: "inline-actions" },
                                            button("Grade history", () =>
                                                ui
                                                    .teaching()
                                                    .gradeHistory(
                                                        { id: item.section_id },
                                                        { student_id: id },
                                                        back,
                                                    ),
                                            ),
                                            can("sis:gradebook:amend") &&
                                                can("sis:student:read") &&
                                                (item.grade_code || can("sis:gradebook:manage")) &&
                                                ["A", "N"].includes(item.status)
                                                ? button("Review final grade", () =>
                                                      ui
                                                          .teaching()
                                                          .recordFinalGrade(
                                                              { id: item.section_id },
                                                              { student_id: id, grade_code: item.grade_code },
                                                              back,
                                                          ),
                                                  )
                                                : null,
                                        )
                                      : "—",
                            ]),
                            "Academic transcript",
                        ),
                    );
                }),
            );
        if (can("sis:registration:manage"))
            actions.append(button("Registration history", () => ui.registrationHistory().history(id, back)));
        if (can("sis:registration:manage"))
            actions.append(button("Hiatus history", () => ui.hiatus().page(id)));
        if (can("sis:registration:manage")) {
            actions.append(button("Change student activity", () => activity(record, back)));
            actions.append(button("Activity history", () => activityHistory(id, back)));
            actions.append(button("Primary-program history", () => primaryHistory(id, back)));
        }

        const programs = node(
            "section",
            { class: "record-section", "aria-label": "Student programs" },
            node("h3", {}, "Student programs"),
        );
        body.append(programs);
        programs.append(
            node(
                "p",
                { class: "form-text" },
                "Each program has its own entry term. Assignments take effect now; the institutional entry term stays unchanged. Closing an assignment is not graduation or withdrawal.",
            ),
        );
        if (can("sis:registration:manage"))
            programs.append(button("Assign an additional program", () => assignProgram(record, back)));
        const information = node("p", {
            class: "form-text",
            role: "status",
            "aria-live": "polite",
        });
        const content = node("div");
        let after = "";
        let nextAfter = null;
        let serial = 0;
        const previousCursors = [];
        const previous = button("Previous program page", async () => {
            after = previousCursors.pop() || "";
            await load();
        });
        const next = button("Next program page", async () => {
            previousCursors.push(after);
            after = nextAfter;
            await load();
        });
        programs.append(information, content, node("div", { class: "inline-actions" }, previous, next));
        async function load() {
            const token = ++serial;
            const page = await request(`/sis/students/${id}/programs/`, "POST", { after });
            if (!body.isConnected || token !== serial) return;
            nextAfter = page.next_after;
            previous.disabled = previousCursors.length === 0;
            next.disabled = !nextAfter;
            information.textContent = `${page.items.length} programs on this page.${nextAfter ? " More are available." : ""}`;
            content.replaceChildren(
                page.items.length
                    ? grid(
                          ["Program", "Entry term", "Catalog", "Status", "Primary", "Actions"],
                          page.items.map((item) => {
                              const controls = node("div", {
                                  class: "inline-actions",
                              });
                              if (can("sis:student:read"))
                                  controls.append(
                                      button("Degree requirements", () =>
                                          ui.degreeAudit().studentRequirements(id, item.id, back),
                                      ),
                                  );
                              if (can("sis:registration:manage")) {
                                  controls.append(
                                      button("Program status history", () =>
                                          programStatusHistory(id, item, back),
                                      ),
                                  );
                                  controls.append(
                                      button("Matriculation cohort", () =>
                                          ui.calendar().studentCatalog(id, item.id),
                                      ),
                                  );
                                  if (!item.is_primary && item.status === "active")
                                      controls.append(
                                          button("Make primary", () => primaryProgram(record, item, back)),
                                      );
                                  if (!item.is_primary)
                                      controls.append(
                                          button(
                                              item.status === "active"
                                                  ? "Close assignment"
                                                  : "Reopen assignment",
                                              () => programStatus(id, item, back),
                                          ),
                                      );
                              }
                              return [
                                  `${item.program_code} · ${item.program_title}`,
                                  item.term_code,
                                  item.catalog_year
                                      ? `${item.catalog_year} · revision ${item.catalog_revision}`
                                      : "Catalog needs review",
                                  badge(item.status),
                                  item.is_primary ? "Primary" : "—",
                                  controls,
                              ];
                          }),
                          "Program assignments",
                      )
                    : node("p", {}, "No program assignments are recorded."),
            );
        }
        await load();
        if (body.isConnected && can("sis:registration:manage"))
            await registration(body, {
                id,
                person_id: record.person_id,
                active: record.active,
            });
    }
    return { student };
};
