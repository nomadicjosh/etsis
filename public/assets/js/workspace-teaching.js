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
        recordSummary,
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
        if (sectionName === "teaching")
            body.append(
                recordSummary([
                    ["Section", row.code],
                    ["Course", row.course_title || row.course_code],
                    ["Term", row.term_code],
                    ["Seat capacity", row.capacity],
                ]),
            );
        else if (row.term_code)
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
                        node(
                            "div",
                            { class: "record-list-actions" },
                            button(
                                "Export roster page",
                                () => requestFile(`/sis/ui/exports/roster/${row.id}/`, { after }),
                                "btn-outline-secondary btn-sm",
                            ),
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
            button("Gradebook setup", () => calculationSetup(row, back)),
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
                    {},
                    "Assignments and scores are separate from the official final grade.",
                    { layout: "teaching_assignment" },
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
    async function calculationSetup(row, back) {
        const body = openDrawer("Gradebook setup", `${row.code} · Coursework calculation`);
        const setup = await request(`/sis/sections/${row.id}/gradebook/calculation/`);
        if (!body.isConnected) return;
        const mode = node(
            "select",
            { class: "form-select", id: "gradebook-mode" },
            node("option", { value: "points" }, "Total points"),
            node("option", { value: "weighted" }, "Assignment weights"),
            node("option", { value: "category" }, "Category weights"),
        );
        mode.value = setup.mode;
        const status = node("p", {
            class: "form-text",
            role: "status",
            "aria-live": "polite",
            id: "gradebook-weight-help",
        });
        const fields = node("fieldset", {}, node("legend", { class: "h6" }, "Assignment weights"));
        const inputs = [];
        const equal = button(
            "Equal weights",
            () => {
                const base = Math.floor(10000 / inputs.length);
                const remainder = 10000 % inputs.length;
                inputs.forEach(([, input], index) => {
                    input.value = ((base + (index < remainder ? 1 : 0)) / 100).toFixed(2);
                });
                update();
            },
            "btn-outline-secondary btn-sm mb-3",
        );
        equal.disabled = setup.assignments.length === 0;
        fields.append(equal);
        const weightGrid = node("div", { class: "row g-3" });
        fields.append(weightGrid);
        for (const assignment of setup.assignments) {
            const id = `weight-${assignment.id}`;
            const input = node("input", {
                id,
                type: "number",
                class: "form-control",
                min: "0",
                max: "100",
                step: "0.01",
                required: true,
                "aria-describedby": "gradebook-weight-help",
            });
            input.value = ((setup.weights[assignment.id] || 0) / 100).toFixed(2);
            inputs.push([assignment.id, input]);
            weightGrid.append(
                node(
                    "div",
                    { class: "col-md-6" },
                    node("label", { for: id, class: "form-label" }, `${assignment.title} · Weight %`),
                    input,
                ),
            );
        }
        const categoryEditor = window
            .createGradebookCalculation({ node })
            .editor(setup.assignments, setup.categories || [], update);
        const form = node(
            "form",
            {},
            node(
                "p",
                { class: "form-text" },
                "Choose total points, individual assignment weights or category weights. Zero-weight and unassigned work is excluded from weighted calculations. Official final grades stay unchanged.",
            ),
            node("label", { for: "gradebook-mode", class: "form-label" }, "Calculate using"),
            mode,
            status,
            fields,
            categoryEditor.element,
        );
        const save = node("button", { type: "submit", class: "btn btn-primary" }, "Save setup");
        form.append(node("div", { class: "inline-actions" }, save, button("Back to section", back)));
        function update() {
            const weighted = mode.value === "weighted";
            const category = mode.value === "category";
            categoryEditor.setEnabled(category);
            fields.hidden = !weighted;
            let total = 0;
            for (const [, input] of inputs) {
                input.disabled = !weighted;
                total += Math.round(Number(input.value) * 100);
            }
            const groups = categoryEditor.serialize();
            const empty = groups.filter((c) => c.weight_hundredths > 0 && !c.assignments.length).length;
            status.textContent = category
                ? `Assigned ${(groups.reduce((sum, c) => sum + c.weight_hundredths, 0) / 100).toFixed(2)}% of 100%. Use unique category names. ${empty} positive-weight ${empty === 1 ? "category has" : "categories have"} no assignments.`
                : weighted
                  ? `Assigned ${(total / 100).toFixed(2)}% of 100%. Ungraded assignments are excluded from the running average.`
                  : "All assignments contribute according to their possible points.";
            save.disabled =
                (weighted && (total !== 10000 || !inputs.length)) || (category && !categoryEditor.valid());
        }
        mode.addEventListener("change", update);
        inputs.forEach(([, input]) => input.addEventListener("input", update));
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            if (!form.reportValidity() || save.disabled) return;
            const weights =
                mode.value === "weighted"
                    ? Object.fromEntries(
                          inputs.map(([id, input]) => [id, Math.round(Number(input.value) * 100)]),
                      )
                    : {};
            task(async () => {
                await request(`/sis/sections/${row.id}/gradebook/calculation/`, "POST", {
                    mode: mode.value,
                    weights,
                    revision: setup.revision,
                    categories: mode.value === "category" ? categoryEditor.serialize() : [],
                });
                if (body.isConnected) await back();
            }, save);
        });
        update();
        body.append(form);
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
            { layout: "teaching_assignment" },
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
            recordSummary([
                ["Final grade", choices.current_grade_code || "Not graded"],
                ["Actual completion date", choices.completed_on || "Unknown"],
                ...(choices.can_record_academic_period
                    ? [["Recorded academic period start", choices.academic_on || "Unknown"]]
                    : []),
            ]),
        );
        const related = node("div", {
            class: "inline-actions",
            role: "group",
            "aria-label": "Related academic reviews",
        });
        if (choices.can_accept_course)
            related.append(
                button("Review course publication", () =>
                    coursePublication(sectionRow, student, choices, () =>
                        recordFinalGrade(sectionRow, student, back, assignment),
                    ),
                ),
            );
        if (choices.can_record_academic_period)
            related.append(
                button("Review academic period", () =>
                    academicDate(
                        sectionRow,
                        student,
                        choices,
                        () => recordFinalGrade(sectionRow, student, back, assignment),
                        "academic_period",
                    ),
                ),
            );
        if (choices.posted && choices.can_amend)
            related.append(
                button("Review actual completion date", () =>
                    academicDate(sectionRow, student, choices, () =>
                        recordFinalGrade(sectionRow, student, back, assignment),
                    ),
                ),
            );
        if (related.childElementCount) body.append(related);
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
        const form = await buildForm(
            descriptors,
            { effective_on: choices.effective_on_default },
            { layout: "final_grade" },
        );
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
                    node(
                        "p",
                        { class: "form-text" },
                        "This review expires after 20 minutes. If it expires, choose Change review and review again; your entries are preserved.",
                    ),
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
    async function reviewCoursePublication(sectionRow, student, back) {
        const choices = await request(
            `/sis/sections/${sectionRow.id}/students/${student.student_id}/grade-choices/`,
            "POST",
            {},
        );
        if (!choices.can_amend)
            throw new Error("This review requires current academic-amendment and student-record access.");
        if (!choices.can_accept_course) {
            const body = openDrawer("Course publication", "RETAINED ACADEMIC REFERENCE");
            body.append(
                node(
                    "p",
                    {},
                    "This record has a retained registration-time course or offering publication. The unknown-reference correction cannot replace those facts.",
                ),
                button("Back to record", back, "btn-link"),
            );
            return;
        }
        await coursePublication(sectionRow, student, choices, back);
    }

    async function coursePublication(sectionRow, student, gradeChoices, back) {
        const base = `/sis/sections/${sectionRow.id}/students/${student.student_id}/course-publication/`;
        const choices = await request(base + "choices/", "POST", {
            enrollment_id: gradeChoices.enrollment_id,
        });
        const body = openDrawer("Course publication acceptance", "REASONED ACADEMIC CORRECTION");
        body.append(
            node(
                "p",
                {},
                "The registration-time course publication was not recorded. Choose an exact publication of this same course supported by institutional evidence. This records a later acceptance; it does not prove which publication applied at registration. Grades, credits, completion dates and charges stay as recorded.",
            ),
            node("p", {}, `${choices.current_course_code} · ${choices.current_course_title}`),
            button("Back to grade record", back, "btn-link"),
        );
        if (!choices.items.length) {
            body.append(
                node(
                    "p",
                    { role: "status" },
                    "Catalog staff must publish this course before its reference can be reviewed.",
                ),
            );
            return;
        }
        const label = (item) => `Version ${item.version} · ${item.code} · ${item.transcript_title}`;
        const fields = [
            [
                "course_version_id",
                "Accepted course publication",
                "choice",
                choices.items.map((item) => ({
                    value: item.version_id,
                    label: label(item),
                })),
            ],
            ["effective_on", "Acceptance effective date", "date", "", true],
            ["reason", "Reason and course-publication evidence", "textarea", "", true],
        ];
        const form = await buildForm(fields, {
            effective_on: choices.effective_on_default,
        });
        form.elements.effective_on.max = choices.effective_on_default;
        form.elements.reason.maxLength = 1000;
        let cursor = choices.next_cursor;
        if (cursor) {
            const more = button("Load more course publications", async () => {
                const page = await request(base + "choices/", "POST", {
                    enrollment_id: gradeChoices.enrollment_id,
                    cursor,
                });
                if (!form.isConnected) return;
                for (const item of page.items)
                    form.elements.course_version_id.append(
                        node("option", { value: item.version_id }, label(item)),
                    );
                cursor = page.next_cursor;
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
            "Review course publication acceptance",
        );
        form.append(submit);
        body.append(form);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            task(async () => {
                const values = formData(form, fields);
                const review = await request(base + "review/", "POST", {
                    ...values,
                    enrollment_id: gradeChoices.enrollment_id,
                    version: choices.current_version,
                });
                const confirmation = openDrawer(
                    "Confirm course publication acceptance",
                    "PRESERVE ORIGINAL FACTS",
                );
                confirmation.append(
                    node(
                        "p",
                        {},
                        `${review.before.course_code} · ${review.before.course_title} → ${review.after.course_code} · ${review.after.course_title}`,
                    ),
                    node("p", {}, `Acceptance effective: ${review.effective_on}`),
                    node("p", {}, review.reason),
                    node(
                        "p",
                        {},
                        "The earlier reference remains unknown. Original facts and previous decisions remain in protected history. This changes the current accepted course labels/reference; no grades, credits or charges are recalculated.",
                    ),
                    button(
                        "Change course publication review",
                        () =>
                            openDrawer(
                                "Course publication acceptance",
                                "REASONED ACADEMIC CORRECTION",
                            ).append(form),
                        "btn-link",
                    ),
                    button(
                        "Confirm course publication acceptance",
                        async () => {
                            await request(base + "commit/", "POST", {
                                review_token: review.review_token,
                            });
                            await back();
                        },
                        "btn-primary",
                    ),
                );
            }, submit);
        });
    }

    async function academicDate(sectionRow, student, choices, back, kind = "completion") {
        const period = kind === "academic_period",
            field = period ? "academic_on" : "completed_on";
        const title = period ? "Source academic period" : "Actual course completion date";
        const action = period ? "academic period" : "completion date";
        const body = openDrawer(title, "REVIEWED ACADEMIC CORRECTION");
        body.append(
            node(
                "p",
                {},
                period
                    ? "Record the source academic-period start supported by institutional records for this older attempt. Its offering publication did not retain that date. Do not substitute today's term dates, grade posting or actual completion. Clearing the date marks the evidence unknown again."
                    : "Record when this attempt actually completed, including a non-passing attempt. This is separate from instructional end dates, grade posting and correction dates. Leave the date blank only to explicitly mark it unknown.",
            ),
            node(
                "p",
                {},
                `${period ? "Current source period start" : "Current completion date"}: ${choices[field] || "Unknown"}`,
            ),
            button("Back to grade record", back, "btn-link"),
        );
        const descriptors = [
            [
                field,
                period
                    ? "Source academic-period start (blank marks unknown)"
                    : "Actual completion date (blank marks unknown)",
                "date",
                "",
                false,
            ],
            ["reason", "Reason for recording or correcting this date", "textarea", "", true],
        ];
        const form = await buildForm(
            descriptors,
            { [field]: choices[field] || "" },
            { layout: "academic_date" },
        );
        form.elements[field].max = choices.effective_on_default;
        form.elements.reason.maxLength = 1000;
        body.append(form);
        const submit = node("button", { type: "submit", class: "btn btn-primary" }, `Review ${action}`);
        form.append(submit);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            task(async () => {
                const values = formData(form, descriptors);
                const base = `/sis/sections/${sectionRow.id}/students/${student.student_id}/${period ? "academic-period" : "completion-date"}/`;
                const review = await request(`${base}review/`, "POST", {
                    enrollment_id: choices.enrollment_id,
                    version: choices.current_version,
                    [field]: values[field] || null,
                    reason: values.reason,
                });
                const confirmation = openDrawer(`Confirm ${action}`, "PRESERVE THE ORIGINAL ACADEMIC FACTS");
                confirmation.append(
                    node(
                        "p",
                        {},
                        `${review[`previous_${field}`] || "Unknown"} → ${review[field] || "Unknown"}`,
                    ),
                    node("p", {}, review.reason),
                    node(
                        "p",
                        {},
                        period
                            ? "This records source chronology without changing grades, credits or charges. The retained repeat policy may change GPA ordering when it uses academic periods. Original unknowns and earlier dates remain in history."
                            : "This records a reasoned academic decision. Grades, earned credits and charges stay as recorded. The retained repeat policy may change GPA ordering when it uses actual completion dates.",
                    ),
                    button(
                        `Revise ${action}`,
                        () => openDrawer(title, "REVIEWED ACADEMIC CORRECTION").append(form),
                        "btn-link",
                    ),
                    button(
                        `Confirm ${action}`,
                        async () => {
                            await request(`${base}commit/`, "POST", {
                                review_token: review.review_token,
                            });
                            await back();
                        },
                        "btn-primary",
                    ),
                );
            }, submit);
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
                              [
                                  "Decision",
                                  "Grade or course",
                                  "Academic date change",
                                  "Effective",
                                  "Recorded",
                              ],
                              page.items.map((item) => [
                                  {
                                      registration: "Registration accepted",
                                      observed_existing: "Existing facts observed",
                                      grade_decided: "Grade decision",
                                      completion_recorded: "Actual completion date recorded",
                                      period_recorded: "Source academic period recorded",
                                      course_accepted: "Course publication accepted",
                                      dropped: "Course dropped",
                                  }[item.action] || "Academic decision",
                                  item.action === "course_accepted"
                                      ? `${item.course_code} · ${item.course_title}`
                                      : item.grade_code || "—",
                                  item.action === "completion_recorded"
                                      ? `${item.previous_completed_on || "Unknown"} → ${item.completed_on || "Unknown"}`
                                      : item.action === "period_recorded"
                                        ? `${item.previous_academic_on || "Unknown"} → ${item.academic_on || "Unknown"}`
                                        : "—",
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
        const attendance = area("Attendance");
        const scores = area("Assignment scores");
        const navigation = node("nav", {
            class: "record-section-nav",
            "aria-label": "Gradebook sections",
        });
        for (const [title, target] of [
            ["Final grade", final],
            ["Attendance", attendance],
            ["Scores", scores],
        ]) {
            const heading = target.querySelector("h3");
            heading.tabIndex = -1;
            navigation.append(
                button(
                    title,
                    () => {
                        heading.focus({ preventScroll: true });
                        heading.scrollIntoView({
                            block: "start",
                            behavior: "instant",
                        });
                    },
                    "record-jump-link",
                ),
            );
        }
        body.append(
            recordSummary([
                ["Institutional ID", student.institutional_id],
                ["Section", sectionRow.code || "Not specified"],
                ["Term", sectionRow.term_code || "Not specified"],
            ]),
            navigation,
            final,
            attendance,
            scores,
        );
        const scoresLoading = node("p", { class: "form-text", role: "status" }, "Loading assignment scores…");
        scores.append(scoresLoading);
        final.append(
            node("p", {}, `Current grade: ${student.grade_code || "Not graded"}`),
            node(
                "div",
                { class: "inline-actions" },
                button(
                    student.grade_code ? "Review final grade" : "Record final grade",
                    () => recordFinalGrade(sectionRow, student, back),
                    "btn-primary",
                ),
                button("Grade history", () => gradeHistory(sectionRow, student, back)),
            ),
        );
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
        if (!body.isConnected) return;
        scoresLoading.remove();
        try {
            const summary = await request(
                `/sis/sections/${sectionRow.id}/students/${student.student_id}/gradebook-summary/`,
            );
            if (!body.isConnected) return;
            scores.append(window.createGradebookCalculation({ node }).panel(summary));
        } catch (error) {
            if (!body.isConnected) return;
            if (error.status === 403 || error.status === 401) throw error;
            scores.append(
                node(
                    "p",
                    { class: "alert alert-warning", role: "status" },
                    "The running average is unavailable. You can still review individual scores and official grades.",
                ),
            );
        }
        await pages(
            scores,
            (after) =>
                request(`/sis/sections/${sectionRow.id}/students/${student.student_id}/score-page/`, "POST", {
                    after,
                }),
            (page) =>
                page.items.length
                    ? grid(
                          ["Assignment", "Earned / possible", "Calculation", "Action"],
                          page.items.map((item) => [
                              item.title,
                              `${item.earned_hundredths == null ? "Not graded" : Number(item.earned_hundredths) / 100} / ${Number(item.possible_hundredths) / 100}`,
                              window.createGradebookCalculation({ node }).assignmentLabel(item),
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
            { layout: "attendance_mark" },
        );
        if (record) form.elements.held_on.readOnly = true;
        if (term) {
            form.elements.held_on.min = term.starts_on;
            form.elements.held_on.max = term.ends_on;
        }
    }
    return { section, recordFinalGrade, gradeHistory, reviewCoursePublication };
};
