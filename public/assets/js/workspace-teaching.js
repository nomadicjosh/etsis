/* Paged teaching tools. Each read and download is authorized again on the server. */
window.createWorkspaceTeaching = function (ui) {
    "use strict";
    const {
        node,
        request,
        requestFile,
        task,
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
        node(
            "section",
            { class: "record-section", "aria-label": title },
            node("h3", {}, title),
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
        container.append(
            information,
            content,
            node("div", { class: "inline-actions mt-3" }, previous, next),
        );
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
                    [row.course_title || row.course_code, row.term_code]
                        .filter(Boolean)
                        .join(" · "),
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
                                  [
                                      "Student",
                                      "Institutional ID",
                                      "Registration",
                                      "Final grade",
                                      "Action",
                                  ],
                                  page.items.map((student) => [
                                      `${student.given_name} ${student.family_name}`,
                                      student.institutional_id,
                                      status(student.status),
                                      student.grade_code || "—",
                                      page.can_grade &&
                                      ["A", "N"].includes(student.status)
                                          ? button("Grade & attendance", () =>
                                                studentRecord(
                                                    row,
                                                    student,
                                                    back,
                                                ),
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
                        [
                            "possible_hundredths",
                            "Possible points",
                            "hundredths",
                        ],
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
                              button("Edit assignment", () =>
                                  editAssignment(item.id, back),
                              ),
                          ]),
                          "Assignments",
                      )
                    : node(
                          "p",
                          { class: "muted-text mt-3" },
                          "Add an assignment to begin recording scores.",
                      ),
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
    async function studentRecord(sectionRow, student, back) {
        const body = openDrawer(
            `${student.given_name} ${student.family_name}`,
            "GRADES & ATTENDANCE",
        );
        body.append(button("Back to roster", back, "btn-link"));
        const final = area("Final grade");
        body.append(final);
        final.append(
            node(
                "p",
                {},
                `Current grade: ${student.grade_code || "Not graded"}`,
            ),
            button("Record final grade", () =>
                flow(
                    "Save final grade",
                    [["grade_id", "Institutional grade", "lookup", "grades"]],
                    `/sis/sections/${sectionRow.id}/final-grades/`,
                    (data) => ({
                        ...data,
                        student_id: student.student_id,
                        version: Number(student.grade_version),
                    }),
                    back,
                    {},
                    "Saving checks the displayed grade version. Reload the roster if another person changes the final grade.",
                ),
            ),
        );
        const attendance = area("Attendance");
        body.append(attendance);
        let term = null;
        attendance.append(
            button("Record attendance", () =>
                attendanceForm(sectionRow, student, back, null, term),
            ),
        );
        await pages(
            attendance,
            (after) =>
                request(
                    `/sis/sections/${sectionRow.id}/students/${student.student_id}/attendance/`,
                    "POST",
                    { after },
                ),
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
                                      attendanceForm(
                                          sectionRow,
                                          student,
                                          back,
                                          item,
                                          term,
                                      ),
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
                request(
                    `/sis/sections/${sectionRow.id}/students/${student.student_id}/score-page/`,
                    "POST",
                    { after },
                ),
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
                                          earned_hundredths:
                                              data.earned_hundredths ?? null,
                                          student_id: student.student_id,
                                          version: Number(item.version),
                                      }),
                                      () =>
                                          studentRecord(
                                              sectionRow,
                                              student,
                                              back,
                                          ),
                                      {
                                          earned_hundredths:
                                              item.earned_hundredths == null
                                                  ? ""
                                                  : Number(
                                                        item.earned_hundredths,
                                                    ) / 100,
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
    async function attendanceForm(
        section,
        student,
        back,
        record = null,
        term = null,
    ) {
        const descriptors = [
            ["held_on", "Class date", "date"],
            [
                "status",
                "Attendance",
                "choice",
                ["present", "absent", "late", "excused"],
            ],
        ];
        const form = await flow(
            record ? "Save attendance correction" : "Save attendance",
            descriptors,
            `/sis/sections/${section.id}/attendance/`,
            (data) => ({
                ...data,
                student_id: student.student_id,
                ...(record
                    ? { held_on: record.held_on, revision: record.revision }
                    : {}),
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
    return { section };
};
