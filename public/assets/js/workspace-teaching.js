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
    const quantityReview = (quantities, reviewed = true) => {
        const value = (milli) => (milli == null ? "Unknown" : String(Number(milli) / 1000));
        return node(
            "section",
            { "aria-label": "Withdrawn credit quantities" },
            node(
                "h3",
                { class: "h6" },
                reviewed ? "Reviewed credit quantities" : "Recorded credit quantities",
            ),
            recordSummary([
                ["Attempted credits", value(quantities.attempted_milli)],
                [
                    "Earned credits",
                    reviewed ? `${value(quantities.completed_milli)} → 0` : value(quantities.completed_milli),
                ],
                [
                    "GPA quality points",
                    reviewed
                        ? `${value(quantities.grade_points_milli)} → ${value(quantities.expected_grade_points_milli)}`
                        : value(quantities.grade_points_milli),
                ],
            ]),
        );
    };
    const unitReview = (summary) =>
        node(
            "section",
            { "aria-label": "Credit-unit correction" },
            node("h3", { class: "h6" }, "Reviewed credit units"),
            recordSummary(
                [
                    ["Attempted credits", "attempted_milli"],
                    ["Earned credits", "completed_milli"],
                    ["GPA quality points", "grade_points_milli"],
                ].map(([label, key]) => [
                    label,
                    `${Number(summary[`previous_${key}`]) / 1000} → ${Number(summary[key]) / 1000}`,
                ]),
            ),
            node(
                "p",
                { class: "alert alert-info" },
                "The original course definition, registration and charges stay unchanged. Review any financial adjustment separately. Earlier academic values remain in history.",
            ),
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
    const rosterFilters = new Map();
    let worksheet = null;
    const canLeave = () => worksheet?.canLeave() ?? true;
    const gradeAssignment = (id, row, back) => {
        worksheet ??= window.createTeachingWorksheet(ui);
        return worksheet.assignment(id, row, back);
    };
    const takeAttendance = (row, back) => {
        worksheet ??= window.createTeachingWorksheet(ui);
        return worksheet.attendance(row, back);
    };
    const finalGradeSheet = (row, back, search) => {
        worksheet ??= window.createTeachingWorksheet(ui);
        return worksheet.finalGrades(row, back, search);
    };

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
        const filters = rosterFilters.get(row.id) || { search: "", grade_status: "all" };
        rosterFilters.delete(row.id);
        rosterFilters.set(row.id, filters);
        if (rosterFilters.size > 20) rosterFilters.delete(rosterFilters.keys().next().value);
        const searchId = `roster-search-${row.id}`,
            statusId = `roster-status-${row.id}`;
        const search = node("input", {
            id: searchId,
            type: "search",
            class: "form-control",
            maxlength: 80,
            value: filters.search,
        });
        const gradeStatus = node(
            "select",
            { id: statusId, class: "form-select" },
            node("option", { value: "all" }, "All registrations"),
            node("option", { value: "active" }, "Active registrations"),
        );
        const controls = node(
            "form",
            { class: "record-form mb-3", "aria-label": "Find roster students" },
            node("label", { for: searchId, class: "form-label" }, "Student name or institutional ID"),
            search,
            node("label", { for: statusId, class: "form-label" }, "Roster view"),
            gradeStatus,
        );
        const apply = node("button", { type: "submit", class: "btn btn-primary" }, "Apply filters");
        controls.append(apply);
        panel.append(controls);
        const results = node("div");
        panel.append(results);
        async function loadRoster() {
            const selected = { ...filters };
            const listing = node("div");
            const viewLabels = {
                all: "All registrations",
                active: "Active registrations",
                needs_grade: "Not yet graded",
                graded: "Graded",
            };
            results.replaceChildren(
                node(
                    "p",
                    { class: "form-text", role: "status" },
                    `Applied: ${selected.search ? `${selected.search} · ` : ""}${viewLabels[selected.grade_status]}`,
                ),
                listing,
            );
            return pages(
                listing,
                (after) =>
                    request(`/sis/ui/context/roster/${row.id}/`, "POST", {
                        after,
                        ...selected,
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
                                () =>
                                    requestFile(`/sis/ui/exports/roster/${row.id}/`, { after, ...selected }),
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
                                  "No students match this roster view. Adjust the search or filters to see other registrations.",
                              ),
                    ),
            );
        }
        controls.addEventListener("submit", (event) => {
            event.preventDefault();
            if (!controls.reportValidity()) return;
            filters.search = search.value.trim();
            filters.grade_status = gradeStatus.value;
            task(loadRoster, apply);
        });
        let first;
        try {
            first = await loadRoster();
            if (!body.isConnected) return;
            if (first.can_grade) {
                controls.append(
                    node("p", { class: "form-text" }, "Grade views include active registrations."),
                );
                gradeStatus.append(
                    node("option", { value: "needs_grade" }, "Not yet graded"),
                    node("option", { value: "graded" }, "Graded"),
                );
            }
            gradeStatus.value = filters.grade_status;
        } catch (error) {
            if (error.status !== 403) throw error;
            panel.replaceChildren(
                node("h3", {}, "Section roster"),
                ["needs_grade", "graded"].includes(filters.grade_status)
                    ? button("Show all registrations", async () => {
                          filters.grade_status = "all";
                          await openRecord(sectionName, row);
                      })
                    : null,
                node(
                    "p",
                    {},
                    "Roster access requires registration privileges or an assignment to this section.",
                ),
            );
            return;
        }
        if (!first.can_grade) return;
        panel.prepend(
            node(
                "div",
                { class: "inline-actions mb-3" },
                button("Take attendance", () => takeAttendance(row, back), "btn-primary"),
                button("Final-grade worksheet", () => finalGradeSheet(row, back, filters.search)),
                button("Review ungraded", () => gradeQueue(row, back, filters.search)),
            ),
        );
        const assignments = area("Assignments");
        body.append(assignments);
        assignments.append(
            button("Gradebook setup", () => calculationSetup(row, back)),
            button("Add assignment", () => assignmentForm(null, row, back)),
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
                          ["Assignment", "Possible points", "Bonus limit", "Due", "Action"],
                          page.items.map((item) => [
                              item.possible_hundredths === 0 ? `${item.title} · Bonus only` : item.title,
                              item.possible_hundredths === 0
                                  ? "No base points"
                                  : Number(item.possible_hundredths) / 100,
                              Number(item.bonus_hundredths || 0) / 100,
                              item.due_on,
                              node(
                                  "div",
                                  { class: "inline-actions" },
                                  button(
                                      "Grade assignment",
                                      () => gradeAssignment(item.id, row, back),
                                      "btn-primary btn-sm",
                                  ),
                                  button(
                                      "Edit assignment",
                                      () => editAssignment(item.id, back),
                                      "btn-outline-secondary btn-sm",
                                  ),
                              ),
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
                const regular = inputs.filter(([, , bonusOnly]) => !bonusOnly);
                if (!regular.length) return;
                const base = Math.floor(10000 / regular.length);
                const remainder = 10000 % regular.length;
                regular.forEach(([, input], index) => {
                    input.value = ((base + (index < remainder ? 1 : 0)) / 100).toFixed(2);
                });
                update();
            },
            "btn-outline-secondary btn-sm mb-3",
        );
        equal.disabled = !setup.assignments.some((a) => a.possible_hundredths !== 0);
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
            const bonusOnly = assignment.possible_hundredths === 0;
            inputs.push([assignment.id, input, bonusOnly]);
            weightGrid.append(
                node(
                    "div",
                    { class: "col-md-6" },
                    node(
                        "label",
                        { for: id, class: "form-label" },
                        `${assignment.title} · ${bonusOnly ? "Bonus percentage points" : "Weight %"}`,
                    ),
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
            let total = 0,
                bonusTotal = 0;
            for (const [, input, bonusOnly] of inputs) {
                input.disabled = !weighted;
                if (bonusOnly) bonusTotal += Math.round(Number(input.value) * 100);
                else total += Math.round(Number(input.value) * 100);
            }
            const groups = categoryEditor.serialize();
            const empty = groups.filter((c) => c.weight_hundredths > 0 && !c.assignments.length).length;
            status.textContent = category
                ? `Assigned ${(groups.reduce((sum, c) => sum + c.weight_hundredths, 0) / 100).toFixed(2)}% of 100%. Use unique names and whole drop counts from 0 to 499. ${empty} positive-weight ${empty === 1 ? "category has" : "categories have"} no assignments.`
                : weighted
                  ? `Assigned ${(total / 100).toFixed(2)}% of 100% regular weights. Bonus-only limits add up to ${(bonusTotal / 100).toFixed(2)} percentage points, separately. Ungraded bonus work never lowers the average; combined bonus limits cannot exceed 100 points.`
                  : "Regular work supplies possible points. Bonus-only work adds earned points without a denominator or missing-work penalty.";
            save.disabled =
                (weighted && (total !== 10000 || bonusTotal > 10000 || !inputs.length)) ||
                (category && !categoryEditor.valid());
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
        const loading = openDrawer("Edit assignment", "COURSEWORK");
        loading.append(node("p", { role: "status" }, "Loading assignment…"));
        const record = await request(`/sis/assignments/${id}/`);
        if (!loading.isConnected) return;
        await assignmentForm(record, { id: record.section_id }, back);
    }
    async function assignmentForm(record, section, back) {
        const form = await flow(
            record ? "Save assignment" : "Add assignment",
            [
                ["title", "Assignment title"],
                [
                    "assignment_kind",
                    "Assignment type",
                    "choice",
                    [
                        { value: "regular", label: "Regular assignment" },
                        { value: "bonus", label: "Bonus-only assignment" },
                    ],
                ],
                ["possible_hundredths", "Possible points", "hundredths"],
                ["bonus_hundredths", "Bonus limit", "hundredths", "", false],
                ["due_on", "Due on", "date"],
            ],
            record ? `/sis/assignments/${record.id}/edit/` : `/sis/sections/${section.id}/assignments/`,
            ({ assignment_kind, ...data }) => ({
                ...data,
                ...(assignment_kind === "bonus" ? { possible_hundredths: 0 } : {}),
                ...(record ? { revision: record.revision } : {}),
            }),
            back,
            {
                ...(record || {}),
                assignment_kind: record?.possible_hundredths === 0 ? "bonus" : "regular",
                possible_hundredths: record ? Number(record.possible_hundredths) / 100 : 100,
                bonus_hundredths: Number(record?.bonus_hundredths || 0) / 100,
            },
            "Regular assignments have base possible points. A bonus raises the score limit without adding possible points to the average. Bonus-only work has no base points and never lowers the average. The combined limit cannot be below a recorded mark. Official final grades are recorded separately.",
            { layout: "teaching_assignment" },
        );
        if (!form.isConnected) return;
        const kind = form.elements.assignment_kind;
        const possible = form.elements.possible_hundredths;
        const bonus = form.elements.bonus_hundredths;
        const help = node("p", {
            id: "assignment-points-help",
            class: "form-text",
            role: "status",
        });
        possible.setAttribute("aria-describedby", help.id);
        bonus.setAttribute("aria-describedby", help.id);
        form.append(help);
        let regularPoints = Number(possible.value) > 0 ? possible.value : "100";
        function update() {
            const onlyBonus = kind.value === "bonus";
            if (onlyBonus && Number(possible.value) > 0) regularPoints = possible.value;
            possible.readOnly = onlyBonus;
            possible.min = onlyBonus ? "0" : "0.01";
            possible.value = onlyBonus ? "0" : regularPoints;
            bonus.min = onlyBonus ? "0.01" : "0";
            bonus.required = onlyBonus;
            bonus.setAttribute("aria-required", String(onlyBonus));
            help.textContent = onlyBonus
                ? "Enter the maximum bonus points. This optional work adds no possible points or missing-work penalty. In Gradebook setup, place it with regular work in a category, or set its separate bonus percentage-point limit for assignment weights."
                : "Possible points set the denominator. An optional bonus extends the earned-score limit. Changing base points changes existing percentages. Before converting weighted work to another type, set its weight to zero and rebalance regular weights in Gradebook setup.";
        }
        kind.addEventListener("change", update);
        update();
    }

    async function gradeQueue(sectionRow, back, search = "", after = null) {
        const body = openDrawer("Final-grade queue", "INDIVIDUAL ACADEMIC REVIEWS");
        body.append(
            button("Back to roster", back, "btn-link"),
            node("p", { role: "status" }, "Finding the next active registration without a final grade…"),
        );
        const page = await request(`/sis/ui/context/roster/${sectionRow.id}/`, "POST", {
            search,
            grade_status: "needs_grade",
            after,
        });
        if (!body.isConnected) return;
        if (!page.can_grade) throw new Error("Grading access is required for this queue.");
        const student = page.items[0];
        if (!student) {
            body.replaceChildren(
                node(
                    "p",
                    { role: "status" },
                    "End of this pass. Skipped or newly registered students may still need review.",
                ),
                node(
                    "p",
                    { class: "form-text" },
                    search ? `Applied roster search: ${search}` : "Using the full section roster.",
                ),
                button("Restart queue", () => gradeQueue(sectionRow, back, search)),
                button("Back to roster", back, "btn-link"),
            );
            return;
        }
        await recordFinalGrade(sectionRow, student, back, null, null, {
            search,
            next: () => gradeQueue(sectionRow, back, search, student.id),
        });
    }

    async function recordFinalGrade(
        sectionRow,
        student,
        back,
        assignment = null,
        draft = null,
        queue = null,
    ) {
        const identity = () =>
            recordSummary([
                ["Student", `${student.given_name} ${student.family_name}`],
                ["Institutional ID", student.institutional_id],
                ["Section", sectionRow.code || "Selected section"],
                ...(sectionRow.course_code ? [["Course", sectionRow.course_code]] : []),
            ]);
        function appendContext(container) {
            container.append(identity());
            if (queue)
                container.append(
                    node(
                        "p",
                        { class: "form-text" },
                        queue.search
                            ? `Applied roster search: ${queue.search}`
                            : "Using the full section roster.",
                    ),
                    node(
                        "p",
                        { class: "form-text" },
                        "Each student needs a separate confirmation. Grades and coursework choices are not carried forward.",
                    ),
                    button("Skip student", queue.next),
                );
            container.append(button(queue ? "Back to roster" : "Back to record", back, "btn-link"));
        }
        const loading = openDrawer("Review final grade", "ACADEMIC RECORD");
        appendContext(loading);
        loading.append(node("p", { role: "status" }, "Loading grading context…"));
        const choices = await request(
            `/sis/sections/${sectionRow.id}/students/${student.student_id}/grade-choices/`,
            "POST",
            assignment ? { assignment_id: assignment } : {},
        );
        if (!loading.isConnected) return;
        if (queue && choices.enrollment_id !== student.id) {
            loading.replaceChildren(
                identity(),
                node(
                    "p",
                    { role: "status" },
                    "This registration changed. Refresh the roster before grading this student.",
                ),
                button("Skip student", queue.next),
                button("Back to roster", back, "btn-link"),
            );
            return;
        }
        if (queue && choices.posted) {
            loading.replaceChildren(
                identity(),
                node(
                    "p",
                    { role: "status" },
                    "This record already has an official grade. The queue will not change it.",
                ),
                button("Next student", queue.next),
                button("Back to roster", back, "btn-link"),
            );
            return;
        }
        if (choices.needs_assignment) {
            const body = openDrawer("Choose grading program", "STUDENT PROGRAM CONTEXT");
            body.append(identity());
            if (queue) body.append(button("Skip student", queue.next));
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
                    await recordFinalGrade(sectionRow, student, back, select.value, null, queue);
                }),
                button("Back to roster", back, "btn-link"),
            );
            return;
        }
        if (!choices.items.length) {
            loading.replaceChildren(
                identity(),
                node(
                    "p",
                    { role: "status" },
                    "Catalog staff must publish the applicable grading scale before a grade can be recorded.",
                ),
                button(queue ? "Back to roster" : "Back to record", back, "btn-link"),
            );
            if (queue) loading.append(button("Skip student", queue.next));
            return;
        }
        const body = openDrawer(
            choices.missing_withdrawal_grade
                ? "Resolve withdrawn grade"
                : choices.posted
                  ? "Correct final grade"
                  : "Record final grade",
            "ACADEMIC RECORD",
        );
        appendContext(body);
        body.append(
            recordSummary([
                [
                    "Final grade",
                    choices.current_grade_code ||
                        (choices.missing_withdrawal_grade ? "Unknown definition" : "Not graded"),
                ],
                ...(!choices.withdrawal_grade_correction
                    ? [["Actual completion date", choices.completed_on || "Unknown"]]
                    : []),
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
        if (choices.can_accept_course || choices.can_correct_course)
            related.append(
                button(
                    choices.can_correct_course ? "Correct awarded course" : "Review course publication",
                    () =>
                        coursePublication(sectionRow, student, choices, () =>
                            recordFinalGrade(sectionRow, student, back, assignment, null, queue),
                        ),
                ),
            );
        if (
            choices.posted &&
            choices.can_amend &&
            (!choices.withdrawal_grade_correction ||
                choices.can_record_academic_period ||
                choices.can_correct_posting_term)
        )
            related.append(
                button(choices.can_correct_posting_term ? "Academic details" : "Review academic dates", () =>
                    academicDates(sectionRow, student, choices, () =>
                        recordFinalGrade(sectionRow, student, back, assignment, null, queue),
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
        if (choices.pending_grade_change) {
            body.append(
                node(
                    "p",
                    { class: "alert alert-info" },
                    "A grade correction is awaiting review. The posted grade stays in effect until a different authorized registrar approves it.",
                ),
                button("Review correction", () => gradeChangeRecord(choices.pending_grade_change.id, back)),
            );
            return;
        }
        if (choices.withdrawal_grade_correction)
            body.append(
                node(
                    "p",
                    { class: "alert alert-info" },
                    choices.requires_quantity_reconciliation
                        ? "This review also corrects withdrawn-credit quantities. Enrollment stays withdrawn, earned credit is set to zero and charges stay unchanged. The selected grade definition determines its GPA treatment."
                        : "This corrects the withdrawn grade only. Enrollment stays withdrawn, earned credit stays zero and charges stay unchanged. The selected grade definition determines its GPA treatment.",
                ),
            );
        const evidenceNotice = choices.missing_withdrawal_grade
            ? node(
                  "p",
                  { id: "grade-evidence-help", class: "alert alert-warning" },
                  "The earlier withdrawal has no saved grading definition. Verify the grade against institutional records and explain the evidence in your reason. This records a decision now; it does not identify the unknown earlier definition. If no grading policy was retained, the available grades come from the selected current policy.",
              )
            : null;
        const quantityNotice = choices.requires_quantity_reconciliation
            ? node(
                  "div",
                  { id: "withdrawal-quantity-help", class: "alert alert-warning" },
                  node(
                      "p",
                      {},
                      "The recorded withdrawn-credit quantities need reconciliation. Verify the institutional evidence and select the correct grade. Review will show zero earned credit and GPA quality points calculated from that grade; attempted credits and charges stay unchanged.",
                  ),
                  quantityReview(choices.withdrawal_quantities, false),
              )
            : null;
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
        if (choices.missing_withdrawal_grade)
            descriptors.push([
                "grade_evidence",
                "I verified the grade against institutional records",
                "checkbox",
            ]);
        if (quantityNotice)
            descriptors.push([
                "reconcile_withdrawal_quantities",
                "I verified the withdrawn credit quantities against institutional records",
                "checkbox",
            ]);
        if (choices.can_correct_credit_units) {
            descriptors.push(
                ["correct_credit_units", "Correct credit units", "checkbox"],
                ["attempted_milli", "Corrected attempted credits", "credits", "", false],
            );
        }
        const form = await buildForm(
            descriptors,
            {
                effective_on: choices.effective_on_default,
                ...(draft || {}),
                attempted_milli:
                    Number(
                        draft?.attempted_milli ?? choices.current_credit_quantities?.attempted_milli ?? 0,
                    ) / 1000,
            },
            { layout: "final_grade" },
        );
        form.elements.reason.maxLength = 1000;
        form.elements.effective_on.max = choices.effective_on_default;
        if (choices.can_correct_credit_units) {
            const changeUnits = form.elements.correct_credit_units,
                units = form.elements.attempted_milli;
            units.max = "999.999";
            units.min = "0";
            units.step = "0.001";
            const help = node(
                "p",
                { id: "credit-unit-help", class: "form-text" },
                "Change units only when institutional evidence supports this student's credit correction. The reviewed grade determines earned credit and GPA. Registration, catalog definitions and charges stay unchanged.",
            );
            units.setAttribute("aria-describedby", "credit-unit-help");
            const toggleUnits = (focus = false) => {
                units.disabled = !changeUnits.checked;
                units.required = changeUnits.checked;
                units.parentElement.hidden = !changeUnits.checked;
                help.hidden = !changeUnits.checked;
                if (focus && changeUnits.checked) units.focus();
            };
            form.append(help);
            changeUnits.addEventListener("change", () => toggleUnits(true));
            toggleUnits();
        }
        if (evidenceNotice) {
            form.elements.grade_evidence.required = true;
            form.elements.grade_evidence.setAttribute("aria-describedby", "grade-evidence-help");
            form.append(evidenceNotice);
        }
        if (quantityNotice) {
            form.elements.reconcile_withdrawal_quantities.required = true;
            form.elements.reconcile_withdrawal_quantities.setAttribute(
                "aria-describedby",
                "withdrawal-quantity-help",
            );
            form.append(quantityNotice);
        }
        let courseworkRevision = null;
        if (!choices.posted && choices.coursework) {
            const suggestion = choices.coursework;
            const message = node(
                "p",
                { role: "status", "aria-live": "polite", class: "mb-2" },
                suggestion.available
                    ? `${suggestion.score_display} → ${suggestion.grade_code}`
                    : `${suggestion.score_display ? `${suggestion.score_display} · ` : ""}${suggestion.message}`,
            );
            const card = node(
                "section",
                { class: "alert alert-light border", "aria-label": "Coursework suggestion" },
                node("h4", { class: "h6" }, "Coursework suggestion"),
                message,
            );
            if (suggestion.available) {
                card.append(
                    node(
                        "p",
                        { class: "small" },
                        "Uses the exact coursework average and this record's retained grading ranges. No rounding across grade boundaries or automatic posting. The displayed percentage is shortened to six decimals.",
                    ),
                    button("Use suggested grade", () => {
                        form.elements.grade_id.value = suggestion.grade_id;
                        courseworkRevision = suggestion.revision;
                        message.textContent = `Selected ${suggestion.grade_code} from ${suggestion.score_display}. Review before confirming; the accepted calculation will be retained.`;
                    }),
                    button(
                        "Choose manually",
                        () => {
                            courseworkRevision = null;
                            message.textContent =
                                "Choose the institutional grade using the institution's policy. No coursework calculation will be attached.";
                            form.elements.grade_id.focus();
                        },
                        "btn-link",
                    ),
                );
            }
            card.append(
                button(
                    "Refresh suggestion",
                    () =>
                        recordFinalGrade(
                            sectionRow,
                            student,
                            back,
                            assignment,
                            formData(form, descriptors),
                            queue,
                        ),
                    "btn-outline-secondary btn-sm",
                ),
            );
            form.elements.grade_id.addEventListener("change", () => {
                courseworkRevision = null;
                message.textContent = "Manual grade selection. Review before confirming.";
            });
            form.prepend(card);
        }
        const reviewButton = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            choices.posted && choices.requires_approval ? "Review request" : "Review final grade",
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
        let reviewSequence = 0;
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const payload = {
                    ...formData(form, descriptors),
                    student_id: student.student_id,
                    enrollment_id: choices.enrollment_id,
                    initial_only: !choices.posted,
                    version: choices.current_version,
                    ...(assignment ? { assignment_id: assignment } : {}),
                    ...(courseworkRevision ? { coursework_revision: courseworkRevision } : {}),
                };
                if (!form.elements.correct_credit_units?.checked) delete payload.attempted_milli;
                delete payload.correct_credit_units;
                const sequence = ++reviewSequence;
                const review = await request(
                    `/sis/sections/${sectionRow.id}/final-grades/review/`,
                    "POST",
                    payload,
                );
                if (!form.isConnected || sequence !== reviewSequence) return;
                const confirmation = openDrawer(
                    review.requires_approval ? "Submit grade correction" : "Confirm final grade",
                    "REVIEW ACADEMIC DECISION",
                );
                confirmation.append(
                    ...[
                        identity(),
                        review.coursework_summary
                            ? node(
                                  "p",
                                  { class: "alert alert-info" },
                                  `Using the reviewed coursework calculation: ${review.coursework_summary.score_display}. Its inputs will be retained with the grade decision; later score edits will not change this official grade.`,
                              )
                            : null,
                        review.withdrawal_grade_correction
                            ? node(
                                  "p",
                                  { class: "alert alert-info" },
                                  review.withdrawal_quantity_reconciliation
                                      ? "Enrollment remains withdrawn. Earned credit is set to zero. Charges stay unchanged; the selected grade definition controls GPA treatment."
                                      : "Enrollment remains withdrawn. Earned credit remains zero. Charges stay unchanged; the selected grade definition controls GPA treatment.",
                              )
                            : null,
                        review.missing_withdrawal_grade
                            ? node(
                                  "p",
                                  { class: "alert alert-warning" },
                                  "The earlier grading definition remains unknown. This confirms a new, evidence-reviewed grade decision.",
                              )
                            : null,
                        review.withdrawal_quantity_reconciliation
                            ? quantityReview(review.withdrawal_quantities)
                            : null,
                        review.credit_unit_summary ? unitReview(review.credit_unit_summary) : null,
                        review.requires_approval
                            ? node(
                                  "p",
                                  { class: "alert alert-info" },
                                  "This submits a request for another authorized registrar. The original grade remains in effect until approval.",
                              )
                            : null,
                        node(
                            "p",
                            {},
                            `${choices.current_grade_code || (choices.missing_withdrawal_grade ? "Unknown definition" : "Not graded")} → ${review.grade.code} · ${Number(review.grade.points_milli) / 1000} grade points`,
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
                            appendContext(restored);
                            restored.append(form);
                        }),
                        button(
                            review.requires_approval ? "Submit request" : "Confirm final grade",
                            async () => {
                                await request(`/sis/sections/${sectionRow.id}/final-grades/commit/`, "POST", {
                                    student_id: student.student_id,
                                    review_token: review.review_token,
                                });
                                if (!confirmation.isConnected) return;
                                if (queue) await queue.next();
                                else await back();
                            },
                            "btn-primary",
                        ),
                    ].filter(Boolean),
                );
            }, reviewButton);
        });
    }
    async function gradeChanges() {
        const body = openDrawer("Grade corrections", "ACADEMIC RECORDS");
        body.append(button("Refresh", gradeChanges, "btn-outline-secondary btn-sm"));
        body.append(
            node(
                "p",
                { class: "form-text" },
                "Pending requests retain the original grade until a different authorized registrar approves. Student and course labels below are current directory labels.",
            ),
        );
        if (ui.can?.("sis:gradebook:policy")) body.append(button("Grade policy", () => gradeChangePolicy()));
        await pages(
            body,
            (after) => request("/sis/grade-changes/", "POST", { after }),
            (page) =>
                page.items.length
                    ? grid(
                          ["Student ID", "Course", "Requested change", "Action"],
                          page.items.map((row) => [
                              row.institutional_id || "Unavailable",
                              row.course_code || "Unavailable",
                              `${row.before} → ${row.proposed}${
                                  row.credit_unit_correction
                                      ? ` · Credits ${row.credit_unit_correction.previous_attempted_milli / 1000} → ${row.credit_unit_correction.attempted_milli / 1000}`
                                      : ""
                              }`,
                              button("Review request", () => gradeChangeRecord(row.id, gradeChanges)),
                          ]),
                          "Pending grade corrections",
                      )
                    : node("p", {}, "No grade corrections are awaiting review."),
        );
    }
    async function gradeChangePolicy() {
        const body = openDrawer("Grade correction policy", "INSTITUTIONAL POLICY");
        const policy = await request("/sis/grade-change-policy/");
        if (!body.isConnected) return;
        const fields = [
            [
                "mode",
                "Posted-grade corrections",
                "choice",
                [
                    { value: "direct", label: "Direct correction by authorized records staff" },
                    { value: "approval", label: "Require a different registrar’s approval" },
                ],
            ],
        ];
        const form = await buildForm(fields, policy);
        if (!body.isConnected) return;
        const save = node("button", { type: "submit", class: "btn btn-primary" }, "Save policy");
        form.append(save);
        body.append(
            node(
                "p",
                { class: "form-text" },
                "This applies to corrections of posted grades. Initial grades retain their existing teaching authority. Pending requests still need approval, rejection or cancellation if this policy later changes.",
            ),
            form,
        );
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                await request("/sis/grade-change-policy/", "POST", {
                    ...formData(form, fields),
                    revision: policy.revision,
                });
                if (body.isConnected) await gradeChangePolicy();
            }, save);
        });
    }
    async function gradeChangeRecord(id, back = gradeChanges) {
        const body = openDrawer("Grade correction", "REGISTRAR REVIEW");
        const record = await request(`/sis/grade-changes/${id}/`);
        if (!body.isConnected) return;
        const content = node("div");
        body.append(content);
        if (record.withdrawal_grade_correction)
            content.append(
                node(
                    "p",
                    { class: "alert alert-info" },
                    "This request corrects a withdrawn grade. It does not reopen the enrollment, award earned credit or change charges. Approval requires separate withdrawn-grade correction authority.",
                ),
            );
        content.append(
            button("Back", back, "btn-link"),
            recordSummary([
                ["Student ID", record.institutional_id || "Unavailable"],
                ["Course", record.course_code || "Unavailable"],
                ["Status", record.status],
                ["Grade before request", record.before?.code || "Unknown definition"],
                ["Proposed grade", record.grade.code],
                ["Effective date", record.effective_at.slice(0, 10)],
            ]),
            node("h3", { class: "h6" }, "Reason for request"),
            node("p", { class: "text-break" }, record.reason),
        );
        if (record.missing_withdrawal_grade)
            content.append(
                node(
                    "p",
                    { class: "alert alert-warning" },
                    "No earlier grading definition was saved. Review the requester's evidence and selected grade before approving a new decision. The earlier definition will remain unknown.",
                ),
            );
        if (record.withdrawal_quantity_reconciliation) {
            content.append(
                node(
                    "p",
                    { class: "alert alert-warning" },
                    "This request also reconciles the recorded withdrawn-credit quantities. Review the evidence and before/after values. Rejection or cancellation preserves the existing quantities; approval records zero earned credit and the selected grade's GPA treatment, with charges unchanged.",
                ),
                quantityReview(record.withdrawal_quantities),
            );
        }
        if (record.credit_unit_summary) {
            content.append(unitReview(record.credit_unit_summary));
        }
        if (record.stale)
            content.append(
                node(
                    "p",
                    { class: "alert alert-warning" },
                    "The academic record changed after this request. Reject or cancel this request and prepare a fresh correction.",
                ),
            );
        const options = [];
        if (record.can_approve) options.push({ value: "approved", label: "Approve correction" });
        if (record.can_reject) options.push({ value: "rejected", label: "Reject request" });
        if (record.can_cancel) options.push({ value: "cancelled", label: "Cancel my request" });
        if (!options.length) return;
        const fields = [
            ["outcome", "Decision", "choice", options],
            ["reason", "Reason for decision", "textarea"],
        ];
        const form = await buildForm(fields, { outcome: options[0].value });
        if (!body.isConnected) return;
        form.elements.reason.maxLength = 1000;
        const review = node("button", { type: "submit", class: "btn btn-primary" }, "Review decision");
        form.append(review);
        content.append(form);
        let reviewSequence = 0;
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const sequence = ++reviewSequence;
                const result = await request(
                    `/sis/grade-changes/${id}/review/`,
                    "POST",
                    formData(form, fields),
                );
                if (!form.isConnected || sequence !== reviewSequence) return;
                const confirmation = openDrawer("Confirm grade decision", "REGISTRAR REVIEW");
                confirmation.append(
                    recordSummary([
                        ["Student ID", record.institutional_id || "Unavailable"],
                        ["Course", record.course_code || "Unavailable"],
                    ]),
                    ...(record.withdrawal_quantity_reconciliation && result.outcome === "approved"
                        ? [quantityReview(record.withdrawal_quantities)]
                        : []),
                    ...(record.credit_unit_summary && result.outcome === "approved"
                        ? [unitReview(record.credit_unit_summary)]
                        : []),
                    node(
                        "p",
                        {},
                        `${record.before?.code || "Unknown definition"} → ${record.grade.code} · ${result.outcome}`,
                    ),
                    node("p", { class: "text-break" }, result.reason),
                    node(
                        "p",
                        { class: "form-text" },
                        result.outcome === "approved"
                            ? "Approval posts the requested correction and preserves the earlier grade. This review expires after 20 minutes."
                            : "The original grade remains in effect. This review expires after 20 minutes.",
                    ),
                    button("Change review", () => {
                        const restored = openDrawer("Grade correction", "REGISTRAR REVIEW");
                        restored.append(content);
                    }),
                    button(
                        "Confirm decision",
                        async () => {
                            await request(`/sis/grade-changes/${id}/commit/`, "POST", {
                                review_token: result.review_token,
                            });
                            if (confirmation.isConnected) await back();
                        },
                        "btn-primary",
                    ),
                );
            }, review);
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
        if (!choices.can_accept_course && !choices.can_correct_course) {
            const body = openDrawer("Course publication", "RETAINED ACADEMIC REFERENCE");
            body.append(
                node(
                    "p",
                    {},
                    choices.requires_quantity_reconciliation
                        ? "Review and reconcile this withdrawn record's credit quantities before reviewing its course publication."
                        : choices.pending_grade_change
                          ? "Resolve the pending grade correction before reviewing the course publication."
                          : "This record has a retained registration-time course or offering publication, or needs verified grade evidence first. The unknown-reference correction cannot replace known facts.",
                ),
                ...(choices.requires_quantity_reconciliation
                    ? [button("Review withdrawn record", () => recordFinalGrade(sectionRow, student, back))]
                    : []),
                button("Back to record", back, "btn-link"),
            );
            return;
        }
        await coursePublication(sectionRow, student, choices, back);
    }

    async function coursePublication(
        sectionRow,
        student,
        gradeChoices,
        back,
        selectedCourse = null,
        draft = {},
    ) {
        const correction = gradeChoices.can_correct_course === true;
        const targetCourse = selectedCourse || gradeChoices.course_id;
        const title = correction ? "Correct awarded course" : "Course publication acceptance";
        const base = `/sis/sections/${sectionRow.id}/students/${student.student_id}/course-publication/`;
        const loading = openDrawer(title, "REASONED ACADEMIC CORRECTION");
        loading.append(node("p", { role: "status" }, "Loading course publications…"));
        const choices = await request(base + "choices/", "POST", {
            enrollment_id: gradeChoices.enrollment_id,
            ...(correction ? { course_id: targetCourse } : {}),
        });
        if (!loading.isConnected) return;
        const identity = () =>
            recordSummary([
                ["Student", `${student.given_name} ${student.family_name}`],
                ["Institutional ID", student.institutional_id],
                ["Section", sectionRow.code || sectionRow.course_code || "Selected section"],
            ]);
        const body = openDrawer(title, "REASONED ACADEMIC CORRECTION");
        body.append(
            identity(),
            ...(gradeChoices.withdrawal_grade_correction
                ? [
                      node(
                          "p",
                          { class: "alert alert-info" },
                          "This attempt remains withdrawn. The review cannot reopen registration or award earned credit.",
                      ),
                  ]
                : []),
            node(
                "p",
                {},
                correction
                    ? "Correct the academic course or its exact publication using institutional evidence. The original section and offering remain in history. This does not move registration, create another award, change the student's pursuit or recalculate grades, credits, instructional dates or charges. A course correction does not establish reusable equivalence."
                    : "The registration-time course publication was not recorded. Choose an exact publication of this same course supported by institutional evidence. This records a later acceptance; it does not prove which publication applied at registration. Grades, credits, completion dates and charges stay as recorded.",
            ),
            node("p", {}, `${choices.current_course_code} · ${choices.current_course_title}`),
            button("Back to grade record", back, "btn-link"),
        );
        if (!choices.items.length && !correction) {
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
            ...draft,
            course_version_id: "",
        });
        if (!body.isConnected) return;
        form.elements.effective_on.max = choices.effective_on_default;
        form.elements.reason.maxLength = 1000;
        let cursor = choices.next_cursor;
        if (cursor) {
            const more = button("Load more course publications", async () => {
                const page = await request(base + "choices/", "POST", {
                    enrollment_id: gradeChoices.enrollment_id,
                    cursor,
                    ...(correction ? { course_id: targetCourse } : {}),
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
            correction ? "Review course change" : "Review course publication acceptance",
        );
        form.append(submit);
        if (correction) {
            const searchForm = await buildForm([["search", "Find another course", "search", "", true]]);
            if (!body.isConnected) return;
            searchForm.elements.search.maxLength = 80;
            const results = node("div", { "aria-live": "polite" });
            const find = node("button", { type: "submit", class: "btn btn-outline-primary" }, "Find course");
            searchForm.append(find);
            let searchAfter = null,
                query = "",
                sequence = 0;
            async function search(append = false) {
                const current = ++sequence;
                const page = await request(base + "courses/", "POST", {
                    enrollment_id: gradeChoices.enrollment_id,
                    search: query,
                    ...(append && searchAfter ? { after: searchAfter } : {}),
                });
                if (!searchForm.isConnected || current !== sequence) return;
                if (!append) results.replaceChildren();
                if (!page.items.length)
                    results.append(node("p", {}, "No matching courses. Try another code or title."));
                else
                    results.append(
                        grid(
                            ["Course", "Title", "Action"],
                            page.items.map((item) => [
                                item.code,
                                item.title,
                                button("Use course", () =>
                                    coursePublication(
                                        sectionRow,
                                        student,
                                        gradeChoices,
                                        back,
                                        item.id,
                                        formData(form, fields),
                                    ),
                                ),
                            ]),
                            "Matching courses",
                        ),
                    );
                searchAfter = page.next_after;
                moreCourses.hidden = !searchAfter;
            }
            const moreCourses = button("More courses", () => search(true), "btn-link");
            moreCourses.hidden = true;
            searchForm.addEventListener("submit", (event) => {
                event.preventDefault();
                if (!searchForm.reportValidity()) return;
                query = searchForm.elements.search.value.trim();
                task(() => search(), find);
            });
            body.append(searchForm, results, moreCourses);
            if (!choices.items.length)
                body.append(
                    node(
                        "p",
                        { role: "status" },
                        "No publications for this course. Search for another course or ask catalog staff to publish the required definition.",
                    ),
                );
        }
        const introduction = Array.from(body.childNodes);
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
                    ...(correction ? { course_id: targetCourse } : {}),
                });
                if (!form.isConnected) return;
                const confirmation = openDrawer(
                    correction ? "Confirm course change" : "Confirm course publication acceptance",
                    "PRESERVE ORIGINAL FACTS",
                );
                confirmation.append(
                    identity(),
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
                        correction
                            ? "The original course and offering remain in protected history. Current transcript and degree-progress checks will use the corrected awarded course. Earlier issued documents remain unchanged. Grades, credits, pursuit, registration and charges stay as recorded."
                            : "The earlier reference remains unknown. Original facts and previous decisions remain in protected history. This changes the current accepted course labels/reference; no grades, credits or charges are recalculated.",
                    ),
                    button(
                        correction ? "Revise course change" : "Change course publication review",
                        () => {
                            const editor = openDrawer(title, "REASONED ACADEMIC CORRECTION");
                            editor.append(...introduction, form);
                            form.elements.course_version_id.focus();
                        },
                        "btn-link",
                    ),
                    button(
                        correction ? "Confirm course change" : "Confirm course publication acceptance",
                        async () => {
                            await request(base + "commit/", "POST", {
                                review_token: review.review_token,
                            });
                            if (confirmation.isConnected) await back();
                        },
                        "btn-primary",
                    ),
                );
            }, submit);
        });
    }

    async function academicDates(sectionRow, student, choices, back) {
        const withdrawn = choices.withdrawal_grade_correction === true;
        const placement = choices.can_correct_posting_term === true;
        const title = placement ? "Academic details" : "Academic dates";
        const loading = openDrawer(title, "REVIEWED ACADEMIC CORRECTION");
        loading.append(node("p", { role: "status" }, "Preparing academic dates…"));
        const descriptors = [
            ...(placement
                ? [
                      ["posting_term_id", "Academic posting term", "lookup", "terms"],
                      ["effective_on", "Correction effective date", "date", "", true],
                  ]
                : []),
            ...(choices.can_record_academic_period
                ? [["academic_on", "Source academic-period start (blank marks unknown)", "date", "", false]]
                : []),
            ...(!withdrawn
                ? [["completed_on", "Actual completion date (blank marks unknown)", "date", "", false]]
                : []),
            [
                "reason",
                placement ? "Reason for academic correction" : "Reason for date change",
                "textarea",
                "",
                true,
            ],
        ];
        const form = await buildForm(
            descriptors,
            {
                academic_on: choices.academic_on || "",
                completed_on: choices.completed_on || "",
                posting_term_id: choices.posting_term_id,
                effective_on: choices.effective_on_default,
            },
            { layout: "academic_date" },
        );
        if (!loading.isConnected) return;
        for (const name of ["academic_on", "completed_on", "effective_on"]) {
            if (form.elements[name]) form.elements[name].max = choices.effective_on_default;
        }
        form.elements.reason.maxLength = 1000;
        if (placement && form.elements.posting_term_id.selectedOptions[0]) {
            form.elements.posting_term_id.selectedOptions[0].textContent =
                choices.posting_term_code || "Current academic term";
        }
        const identity = () =>
            recordSummary([
                ["Student", `${student.given_name} ${student.family_name}`],
                ["Institutional ID", student.institutional_id],
                ["Section", sectionRow.code || sectionRow.course_code || "Selected section"],
            ]);
        function edit() {
            const body = openDrawer(title, "REVIEWED ACADEMIC CORRECTION");
            body.append(
                identity(),
                ...(placement
                    ? [
                          node(
                              "p",
                              { class: "alert alert-info" },
                              "The academic posting term controls where this credit appears academically. Correcting it does not move the original registration, billing term, instructional dates or charges. Review source dates separately within this same confirmation.",
                          ),
                      ]
                    : []),
                node(
                    "p",
                    {},
                    withdrawn
                        ? "The attempt stays withdrawn with no earned credits and cannot be marked completed here. Use institutional evidence for any editable source date; a blank editable date explicitly marks that fact unknown."
                        : "Record when this attempt actually completed, including a non-passing attempt. This is separate from instructional end dates, grade posting and correction dates. A blank editable date explicitly marks that fact unknown.",
                ),
                node(
                    "p",
                    {},
                    choices.can_record_academic_period
                        ? "For this older attempt, the source academic-period start must come from institutional records. Do not substitute today's term dates or the date a grade was entered. Review both dates together before confirming."
                        : "Offering dates are retained and cannot be changed here.",
                ),
                recordSummary([
                    ...(choices.can_record_academic_period
                        ? [["Current academic-period start", choices.academic_on || "Unknown"]]
                        : []),
                    ...(!withdrawn ? [["Current completion date", choices.completed_on || "Unknown"]] : []),
                ]),
                button("Back to grade record", back, "btn-link"),
                form,
            );
        }
        const submit = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            placement ? "Review details" : "Review dates",
        );
        form.append(submit);
        edit();
        let sequence = 0;
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            task(async () => {
                const values = formData(form, descriptors),
                    current = ++sequence;
                const base = `/sis/sections/${sectionRow.id}/students/${student.student_id}/${placement ? "academic-details" : "academic-dates"}/`;
                const review = await request(`${base}review/`, "POST", {
                    enrollment_id: choices.enrollment_id,
                    version: choices.current_version,
                    academic_on: choices.can_record_academic_period
                        ? values.academic_on || null
                        : choices.academic_on || null,
                    completed_on: withdrawn ? choices.completed_on || null : values.completed_on || null,
                    reason: values.reason,
                    ...(placement
                        ? { posting_term_id: values.posting_term_id, effective_on: values.effective_on }
                        : {}),
                });
                if (!form.isConnected || current !== sequence) return;
                const confirmation = openDrawer(
                    placement ? "Confirm academic details" : "Confirm academic dates",
                    "PRESERVE THE ORIGINAL ACADEMIC FACTS",
                );
                confirmation.append(
                    identity(),
                    recordSummary([
                        ...(placement
                            ? [
                                  [
                                      "Academic posting term",
                                      `${review.previous_posting_term_code_display} → ${review.posting_term_code}`,
                                  ],
                                  ["Effective date", values.effective_on],
                              ]
                            : []),
                        ...(choices.can_record_academic_period
                            ? [
                                  [
                                      "Academic-period start",
                                      `${review.previous_academic_on || "Unknown"} → ${review.academic_on || "Unknown"}`,
                                  ],
                              ]
                            : []),
                        ...(!withdrawn
                            ? [
                                  [
                                      "Actual completion date",
                                      `${review.previous_completed_on || "Unknown"} → ${review.completed_on || "Unknown"}`,
                                  ],
                              ]
                            : []),
                    ]),
                    node("p", {}, review.reason),
                    node(
                        "p",
                        {},
                        (placement
                            ? "Academic placement and the reviewed source dates are confirmed together. "
                            : withdrawn
                              ? "The attempt remains withdrawn with no earned credit or completion date. "
                              : choices.can_record_academic_period
                                ? "Both dates are confirmed together. "
                                : "The academic period remains unchanged. ") +
                            "Grades, credits, enrollment and charges remain unchanged. Retained repeat policies may use these dates to order attempts. Earlier dates and unknown facts remain in history.",
                    ),
                    node(
                        "p",
                        { class: "form-text" },
                        "This review expires after 20 minutes. Revise and review again if the record changes or the review expires.",
                    ),
                    button(placement ? "Revise details" : "Revise dates", edit, "btn-link"),
                    button(
                        placement ? "Confirm details" : "Confirm dates",
                        async () => {
                            await request(`${base}commit/`, "POST", { review_token: review.review_token });
                            if (confirmation.isConnected) await back();
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
                                  "Academic detail change",
                                  "Effective",
                                  "Recorded",
                              ],
                              page.items.map((item) => [
                                  item.credit_unit_correction
                                      ? "Grade and credit units corrected"
                                      : item.withdrawal_quantity_reconciled
                                        ? "Withdrawn credits reconciled"
                                        : {
                                              registration: "Registration accepted",
                                              observed_existing: "Existing facts observed",
                                              grade_decided: "Grade decision",
                                              correction_requested: "Grade correction requested",
                                              correction_approved: "Grade correction approved",
                                              correction_rejected: "Grade correction rejected",
                                              correction_cancelled: "Grade correction cancelled",
                                              completion_recorded: "Actual completion date recorded",
                                              period_recorded: "Source academic period recorded",
                                              posting_term_corrected: "Academic posting term corrected",
                                              course_accepted: "Course publication accepted",
                                              course_corrected: "Awarded course corrected",
                                              dropped: "Course dropped",
                                          }[item.action] || "Academic decision",
                                  ["course_accepted", "course_corrected"].includes(item.action)
                                      ? `${item.course_code} · ${item.course_title}`
                                      : item.grade_code
                                        ? `${item.grade_code}${item.coursework_summary ? ` · Coursework ${item.coursework_summary.score_display}` : ""}`
                                        : "—",
                                  item.action === "posting_term_corrected"
                                      ? `${item.previous_posting_term_code || "Earlier label unknown"} → ${item.posting_term_code}`
                                      : item.credit_unit_correction
                                        ? `${item.credit_unit_correction.previous_attempted_milli / 1000} → ${item.credit_unit_correction.attempted_milli / 1000} credits`
                                        : item.action === "completion_recorded"
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
                              window.createGradebookCalculation({ node }).scoreLabel(item),
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
                                          ["excused", "Excuse this assignment", "checkbox", "", false],
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
                                          excused: item.excused === true,
                                          earned_hundredths:
                                              item.earned_hundredths == null
                                                  ? ""
                                                  : Number(item.earned_hundredths) / 100,
                                      },
                                  );
                                  form.elements.earned_hundredths.max =
                                      Number(item.score_limit_hundredths) / 100;
                                  const help = node(
                                      "p",
                                      { class: "form-text", id: "score-point-limit" },
                                      `Base: ${Number(item.possible_hundredths) / 100} points. Bonus limit: ${Number(item.bonus_hundredths) / 100} points. Maximum earned: ${Number(item.score_limit_hundredths) / 100} points. Excused work is omitted from the average without clearing the entered mark. Uncheck to include it again.`,
                                  );
                                  form.elements.earned_hundredths.setAttribute("aria-describedby", help.id);
                                  form.elements.excused.setAttribute("aria-describedby", help.id);
                                  form.append(help);
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
    return {
        canLeave,
        section,
        recordFinalGrade,
        gradeHistory,
        reviewCoursePublication,
        gradeChanges,
        gradeChangePolicy,
        gradeChangeRecord,
    };
};
