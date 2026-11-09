/* Paged teaching tools. Each read and download is authorized again on the server. */
window.createWorkspaceTeaching = function (ui) {
    "use strict";
    const {
        node,
        request,
        requestFile,
        task,
        buildForm,
        formData,
        openDrawer,
        openRecord,
        table,
        badge,
        flow,
    } = ui;
    const status = (value) =>
        ({
            A: "Staff registered",
            N: "Self registered",
            D: "Dropped",
            W: "Withdrawn",
            C: "Cancelled",
        })[value] || value;
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
    const area = (title) =>
        node("section", { class: "record-section", "aria-label": title }, node("h3", {}, title));
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

    async function pages(container, fetchPage, render) {
        let after = "";
        const previousCursors = [];
        let version = 0;
        const information = node("p", {
            class: "form-text",
            role: "status",
            "aria-live": "polite",
            tabindex: "-1",
        });
        const content = node("div");
        const previous = button("Previous page", async () => {
            after = previousCursors.pop() || "";
            await load();
        });
        const next = button("Next page", async () => {
            previousCursors.push(after);
            after = next.dataset.after;
            await load();
        });
        container.append(information, content, node("div", { class: "inline-actions mt-3" }, previous, next));
        async function load() {
            const current = ++version;
            content.setAttribute("aria-busy", "true");
            let page;
            try {
                page = await fetchPage(after);
            } finally {
                if (version === current) content.removeAttribute("aria-busy");
            }
            if (current !== version || !container.isConnected) return page;
            information.textContent = `${page.items.length} records on this page.${page.next_after ? " More are available." : ""}`;
            content.replaceChildren(await render(page, after));
            previous.disabled = previousCursors.length === 0;
            next.disabled = !page.next_after;
            next.dataset.after = page.next_after || "";
            return page;
        }
        return load();
    }
    async function section(body, row, sectionName) {
        const back = () => openRecord(sectionName, row);
        if (row.term_code)
            body.append(
                node(
                    "p",
                    { class: "form-text" },
                    [row.course_title || row.course_code, row.term_code].filter(Boolean).join(" · "),
                ),
            );
        const panel = area("Section roster");
        body.append(panel);
        let first;
        try {
            first = await pages(
                panel,
                (after) =>
                    request(`/sis/ui/context/roster/${row.id}/`, "POST", {
                        after,
                    }),
                (page, after) =>
                    node(
                        "div",
                        {},
                        button("Download this roster page (CSV)", () =>
                            requestFile(`/sis/ui/exports/roster/${row.id}/`, {
                                after,
                            }),
                        ),
                        page.items.length
                            ? grid(
                                  ["Student", "Institutional ID", "Registration", "Final grade", "Action"],
                                  page.items.map((student) => [
                                      `${student.given_name} ${student.family_name}`,
                                      student.institutional_id,
                                      status(student.status),
                                      student.grade_code || "—",
                                      page.can_grade && ["A", "N"].includes(student.status)
                                          ? button("Grade & attendance", () =>
                                                studentRecord(row, student, back),
                                            )
                                          : "—",
                                  ]),
                                  "Section roster",
                              )
                            : node(
                                  "p",
                                  { class: "muted-text mt-3" },
                                  "No students are recorded for this section.",
                              ),
                    ),
            );
        } catch (error) {
            if (error.status !== 403) throw error;
            panel.replaceChildren(
                node("h3", {}, "Section roster"),
                node(
                    "p",
                    {},
                    "Roster access requires registration privileges or an assignment to this section.",
                ),
            );
            return;
        }
        if (!first.can_grade) return;
        const assignments = area("Assignments");
        body.append(assignments);
        assignments.append(
            button("Add assignment", () =>
                flow(
                    "Add assignment",
                    [
                        ["title", "Assignment title"],
                        ["possible_hundredths", "Possible points", "hundredths"],
                        ["due_on", "Due on", "date"],
                    ],
                    `/sis/sections/${row.id}/assignments/`,
                    null,
                    back,
                ),
            ),
        );
        await pages(
            assignments,
            (after) =>
                request(`/sis/ui/context/assignments/${row.id}/`, "POST", {
                    after,
                }),
            (page) =>
                page.items.length
                    ? grid(
                          ["Assignment", "Possible points", "Due", "Action"],
                          page.items.map((item) => [
                              item.title,
                              Number(item.possible_hundredths) / 100,
                              item.due_on,
                              button("Edit assignment", () => editAssignment(item.id, back)),
                          ]),
                          "Assignments",
                      )
                    : node("p", { class: "muted-text mt-3" }, "Add an assignment to begin recording scores."),
        );
    }
    async function editAssignment(id, back) {
        const record = await request(`/sis/assignments/${id}/`);
        await flow(
            "Save assignment changes",
            [
                ["title", "Assignment title"],
                ["possible_hundredths", "Possible points", "hundredths"],
                ["due_on", "Due on", "date"],
            ],
            `/sis/assignments/${id}/edit/`,
            (data) => ({ ...data, revision: record.revision }),
            back,
            {
                ...record,
                possible_hundredths: Number(record.possible_hundredths) / 100,
            },
            "Changing possible points changes the percentage represented by existing scores. Recorded earned scores are preserved; the new maximum cannot be below any earned score. Final grades remain separate.",
        );
    }
    async function recordFinalGrade(sectionRow, student, back, assignment = null) {
        const choices = await request(
            `/sis/sections/${sectionRow.id}/students/${student.student_id}/grade-choices/`,
            "POST",
            assignment ? { assignment_id: assignment } : {},
        );
        if (choices.needs_assignment) {
            const body = openDrawer("Choose grading program", "STUDENT PROGRAM CONTEXT");
            const key = "grade-pursuit-choice";
            const select = node(
                "select",
                { id: key, class: "form-select" },
                node("option", { value: "" }, "Choose the applicable program"),
            );
            for (const pursuit of choices.pursuits)
                select.append(node("option", { value: pursuit.id }, `${pursuit.code} · ${pursuit.title}`));
            body.append(
                node(
                    "p",
                    {},
                    "This student pursues programs with different grading scales. Choose the program for this academic record.",
                ),
                node("label", { for: key, class: "form-label" }, "Program pursuit"),
                select,
                button("Use selected program", async () => {
                    if (!select.value) throw new Error("Choose the applicable program.");
                    await recordFinalGrade(sectionRow, student, back, select.value);
                }),
                button("Back to roster", back, "btn-link"),
            );
            return;
        }
        if (!choices.items.length)
            throw new Error(
                "Catalog staff must publish the applicable grading scale before a grade can be recorded.",
            );
        const body = openDrawer(
            choices.posted ? "Correct final grade" : "Record final grade",
            "ACADEMIC RECORD",
        );
        body.append(
            button("Back to record", back, "btn-link"),
            node("p", {}, `Current grade: ${choices.current_grade_code || "Not graded"}`),
        );
        if (choices.posted && !choices.can_amend) {
            body.append(
                node(
                    "p",
                    { class: "alert alert-info" },
                    "A posted grade requires an authorized academic records officer to approve a correction.",
                ),
                button("View grade history", () => gradeHistory(sectionRow, student, back)),
            );
            return;
        }
        const descriptors = [
            [
                "grade_id",
                "Institutional grade",
                "choice",
                choices.items.map((g) => ({
                    value: g.id,
                    label: `${g.code} · ${Number(g.points_milli) / 1000} grade points`,
                })),
            ],
            ["effective_on", "Effective date", "date"],
            [
                "reason",
                choices.posted ? "Reason for correction" : "Decision note (optional)",
                "textarea",
                "",
                !!choices.posted,
            ],
        ];
        const form = await buildForm(descriptors, { effective_on: choices.effective_on_default });
        form.elements.reason.maxLength = 1000;
        form.elements.effective_on.max = choices.effective_on_default;
        const reviewButton = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review final grade",
        );
        form.append(reviewButton);
        body.append(
            node(
                "p",
                { class: "form-text" },
                "Review the grade before it becomes part of the academic record. Earlier decisions are preserved. Corrections require a reason and separate permission.",
            ),
            form,
        );
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const payload = {
                    ...formData(form, descriptors),
                    student_id: student.student_id,
                    version: choices.current_version,
                    ...(assignment ? { assignment_id: assignment } : {}),
                };
                const review = await request(
                    `/sis/sections/${sectionRow.id}/final-grades/review/`,
                    "POST",
                    payload,
                );
                const confirmation = openDrawer("Confirm final grade", "REVIEW ACADEMIC DECISION");
                confirmation.append(
                    node(
                        "p",
                        {},
                        `${choices.current_grade_code || "Not graded"} → ${review.grade.code} · ${Number(review.grade.points_milli) / 1000} grade points`,
                    ),
                    node("p", {}, `Effective date: ${payload.effective_on}`),
                    node("p", {}, review.reason),
                    review.earlier_history_unknown
                        ? node(
                              "p",
                              { class: "alert alert-info" },
                              "Earlier academic facts are retained as an observation. Earlier decision dates and authors are unknown.",
                          )
                        : null,
                    button("Change review", () => {
                        const restored = openDrawer("Review final grade", "ACADEMIC RECORD");
                        restored.append(button("Back to record", back, "btn-link"), form);
                    }),
                    button(
                        "Confirm final grade",
                        async () => {
                            await request(`/sis/sections/${sectionRow.id}/final-grades/commit/`, "POST", {
                                student_id: student.student_id,
                                review_token: review.review_token,
                            });
                            await back();
                        },
                        "btn-primary",
                    ),
                );
            }, reviewButton);
        });
    }
    async function gradeHistory(sectionRow, student, back) {
        const body = openDrawer("Grade history", "ACADEMIC RECORD");
        body.append(button("Back to record", back, "btn-link"));
        await pages(
            body,
            async (cursor) => {
                const page = await request(
                    `/sis/sections/${sectionRow.id}/students/${student.student_id}/grade-history/`,
                    "POST",
                    { cursor: cursor || null },
                );
                return { ...page, next_after: page.next_cursor };
            },
            (page) =>
                node(
                    "div",
                    {},
                    page.earlier_history_unknown
                        ? node(
                              "p",
                              { class: "alert alert-info" },
                              "Earlier history is unknown. An observation preserves the facts first seen by the new history system.",
                          )
                        : null,
                    page.items.length
                        ? grid(
                              ["Decision", "Grade", "Effective", "Recorded"],
                              page.items.map((item) => [
                                  {
                                      registration: "Registration accepted",
                                      observed_existing: "Existing facts observed",
                                      grade_decided: "Grade decision",
                                      dropped: "Course dropped",
                                  }[item.action] || "Academic decision",
                                  item.grade_code || "—",
                                  item.effective_at,
                                  item.recorded_at,
                              ]),
                              "Academic grade decisions",
                          )
                        : node("p", {}, "No recorded decisions yet."),
                ),
        );
    }

    async function studentRecord(sectionRow, student, back) {
        const body = openDrawer(`${student.given_name} ${student.family_name}`, "GRADES & ATTENDANCE");
        body.append(button("Back to roster", back, "btn-link"));
        const final = area("Final grade");
        body.append(final);
        final.append(
            node("p", {}, `Current grade: ${student.grade_code || "Not graded"}`),
            button(student.grade_code ? "Review final grade" : "Record final grade", () =>
                recordFinalGrade(sectionRow, student, back),
            ),
            button("Grade history", () => gradeHistory(sectionRow, student, back)),
        );
        const attendance = area("Attendance");
        body.append(attendance);
        let term = null;
        attendance.append(
            button("Record attendance", () => attendanceForm(sectionRow, student, back, null, term)),
        );
        await pages(
            attendance,
            (after) =>
                request(`/sis/sections/${sectionRow.id}/students/${student.student_id}/attendance/`, "POST", {
                    after,
                }),
            (page) => {
                term = page.term;
                return node(
                    "div",
                    {},
                    node(
                        "p",
                        { class: "form-text" },
                        ["present", "absent", "late", "excused"]
                            .map((key) => `${key}: ${page.counts[key] || 0}`)
                            .join(" · "),
                    ),
                    page.items.length
                        ? grid(
                              ["Class date", "Mark", "Action"],
                              page.items.map((item) => [
                                  item.held_on,
                                  badge(item.status),
                                  button("Correct mark", () =>
                                      attendanceForm(sectionRow, student, back, item, term),
                                  ),
                              ]),
                              "Recorded attendance",
                          )
                        : node("p", {}, "No attendance has been recorded."),
                );
            },
        );
        const scores = area("Assignment scores");
        body.append(scores);
        await pages(
            scores,
            (after) =>
                request(`/sis/sections/${sectionRow.id}/students/${student.student_id}/score-page/`, "POST", {
                    after,
                }),
            (page) =>
                page.items.length
                    ? grid(
                          ["Assignment", "Earned / possible", "Action"],
                          page.items.map((item) => [
                              item.title,
                              `${item.earned_hundredths == null ? "Not graded" : Number(item.earned_hundredths) / 100} / ${Number(item.possible_hundredths) / 100}`,
                              button("Enter score", async () => {
                                  const form = await flow(
                                      "Save score",
                                      [
                                          [
                                              "earned_hundredths",
                                              "Points earned (blank clears score)",
                                              "hundredths",
                                              "",
                                              false,
                                          ],
                                      ],
                                      `/sis/assignments/${item.assignment_id}/scores/`,
                                      (data) => ({
                                          ...data,
                                          earned_hundredths: data.earned_hundredths ?? null,
                                          student_id: student.student_id,
                                          version: Number(item.version),
                                      }),
                                      () => studentRecord(sectionRow, student, back),
                                      {
                                          earned_hundredths:
                                              item.earned_hundredths == null
                                                  ? ""
                                                  : Number(item.earned_hundredths) / 100,
                                      },
                                  );
                                  form.elements.earned_hundredths.max =
                                      Number(item.possible_hundredths) / 100;
                              }),
                          ]),
                          "Assignment scores",
                      )
                    : node("p", {}, "No assignments have been defined."),
        );
    }
    async function attendanceForm(section, student, back, record = null, term = null) {
        const descriptors = [
            ["held_on", "Class date", "date"],
            ["status", "Attendance", "choice", ["present", "absent", "late", "excused"]],
        ];
        const form = await flow(
            record ? "Save attendance correction" : "Save attendance",
            descriptors,
            `/sis/sections/${section.id}/attendance/`,
            (data) => ({
                ...data,
                student_id: student.student_id,
                ...(record ? { held_on: record.held_on, revision: record.revision } : {}),
            }),
            () => studentRecord(section, student, back),
            record || {},
            record
                ? "This corrects the selected mark and checks its current revision before saving."
                : "Attendance must fall within the term. To change an existing mark, use its Correct mark action in the attendance history.",
        );
        if (record) form.elements.held_on.readOnly = true;
        if (term) {
            form.elements.held_on.min = term.starts_on;
            form.elements.held_on.max = term.ends_on;
        }
    }
    return { section, recordFinalGrade, gradeHistory };
};
