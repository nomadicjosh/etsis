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
            actions.append(button("Hiatus history", () => ui.hiatus().page(id)));
        if (can("sis:registration:manage"))
            actions.append(
                button("Change student activity", () =>
                    flow(
                        "Save student activity",
                        [["active", "Active student record", "checkbox"]],
                        `/sis/students/${id}/activity/`,
                        (data) => ({ ...data, revision: record.revision }),
                        back,
                        record,
                        "Inactive students cannot add new registrations. Existing enrollments, academic credits, charges, sign-in and employment remain connected. Drop or withdraw sections separately when appropriate.",
                    ),
                ),
            );

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
            programs.append(
                button("Assign an additional program", () =>
                    flow(
                        "Assign program",
                        [
                            ["program_id", "Academic program", "lookup", "programs"],
                            ["entry_term_id", "Program entry term", "lookup", "terms"],
                        ],
                        `/sis/students/${id}/programs/assign/`,
                        null,
                        back,
                        {},
                        "The assignment becomes active immediately. Select Make primary separately to change the program used for future primary-program rules. Existing financial entries and academic credits retain their history.",
                    ),
                ),
            );
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
                                      button("Matriculation cohort", () =>
                                          ui.calendar().studentCatalog(id, item.id),
                                      ),
                                  );
                                  if (!item.is_primary && item.status === "active")
                                      controls.append(
                                          button("Make primary", () =>
                                              flow(
                                                  "Confirm primary program",
                                                  [],
                                                  `/sis/students/${id}/primary-program/`,
                                                  () => ({
                                                      assignment_id: item.id,
                                                      revision: record.revision,
                                                  }),
                                                  back,
                                                  {},
                                                  `Make ${item.program_code} the primary program now. Future primary-program billing and reporting rules use this selection. Other program assignments stay active. Posted transactions and the institutional entry term stay unchanged.`,
                                              ),
                                          ),
                                      );
                                  if (!item.is_primary)
                                      controls.append(
                                          button(
                                              item.status === "active"
                                                  ? "Close assignment"
                                                  : "Reopen assignment",
                                              () =>
                                                  flow(
                                                      item.status === "active"
                                                          ? "Confirm program closure"
                                                          : "Confirm program reopening",
                                                      [],
                                                      `/sis/students/${id}/programs/${item.id}/activity/`,
                                                      () => ({
                                                          active: item.status !== "active",
                                                          revision: item.revision,
                                                      }),
                                                      back,
                                                      {},
                                                      `${item.program_code}: this changes the assignment status and preserves its entry term and identity. It does not drop courses or award a degree.`,
                                                  ),
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
