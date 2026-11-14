/* Reusable course groups, retained degree requirements and current-work review. */
window.createWorkspaceDegreeAudit = function (ui) {
    "use strict";
    const { node, request, task } = ui;
    let dialog = null,
        opener = null,
        lastTrigger = null,
        sequence = 0;
    document.addEventListener(
        "click",
        (e) => {
            if (!dialog) lastTrigger = e.target.closest?.("button, a") || lastTrigger;
        },
        true,
    );
    const post = (path, data = {}) => request(path, "POST", data);
    const op = async () => (await request("/sis/ui/operation-id/")).id;
    function fail(error) {
        const target = dialog?.querySelector('[role="alert"]');
        if (!target) throw error;
        target.textContent = [
            error.message || "Please review this request.",
            ...(error.fields || []).map((f) => f.message),
        ]
            .filter(Boolean)
            .join(" ");
        target.hidden = false;
        target.focus();
    }
    const button = (label, run, style = "btn-outline-primary") =>
        node(
            "button",
            {
                type: "button",
                class: `btn ${style}`,
                onclick: (e) =>
                    task(async () => {
                        try {
                            await run();
                        } catch (error) {
                            fail(error);
                        }
                    }, e.currentTarget),
            },
            label,
        );
    function close() {
        dialog?.close();
        dialog?.remove();
        dialog = null;
        [
            opener,
            ...document.querySelectorAll(
                "#directory-results button, #directory-tabs a, #directory-tabs button",
            ),
        ]
            .find((el) => el?.isConnected && !el.disabled && el.getClientRects().length)
            ?.focus({ preventScroll: true });
    }
    function screen(title, eyebrow = "ACADEMIC REQUIREMENTS") {
        opener = dialog ? opener : lastTrigger || document.activeElement;
        dialog?.close();
        dialog?.remove();
        ui.closeDrawer();
        dialog = node("dialog", {
            class: "report-workbench degree-audit-studio",
            "aria-labelledby": "degree-audit-title",
        });
        const body = node("div", { class: "report-workbench-body" });
        dialog.append(
            node(
                "header",
                { class: "report-workbench-heading" },
                node(
                    "div",
                    {},
                    node("p", { class: "report-workbench-eyebrow" }, eyebrow),
                    node("h1", { id: "degree-audit-title" }, title),
                ),
                button("Close builder", close),
            ),
            node("div", {
                class: "alert alert-danger",
                role: "alert",
                tabindex: "-1",
                hidden: true,
            }),
            body,
        );
        dialog.addEventListener("cancel", (e) => {
            e.preventDefault();
            close();
        });
        document.body.append(dialog);
        dialog.showModal();
        return body;
    }
    window.addEventListener("pagehide", close);
    const text = (value) => (value == null ? "" : String(value / 1000));
    function milli(value, nullable = false) {
        if (nullable && value === "") return null;
        if (!/^\d{1,7}(\.\d{1,3})?$/.test(value))
            throw new Error("Use nonnegative numbers with up to three decimal places.");
        const [whole, fraction = ""] = value.split(".");
        return Number(whole) * 1000 + Number(fraction.padEnd(3, "0"));
    }
    function field(label, value, update, options = {}) {
        const id = `audit-input-${++sequence}`,
            input = node("input", {
                id,
                class: "form-control",
                value: value ?? "",
                ...options,
            });
        input.addEventListener("input", () => update(input.value));
        return node("div", { class: "mb-3" }, node("label", { for: id, class: "form-label" }, label), input);
    }
    function search(label, value, run) {
        let query = value;
        const form = node("form", { class: "audit-search" }),
            submit = node("button", { type: "submit", class: "btn btn-primary" }, "Search");
        form.append(
            field(label, value, (v) => (query = v), {
                type: "search",
                maxlength: 80,
            }),
            submit,
        );
        form.addEventListener("submit", (e) => {
            e.preventDefault();
            task(async () => {
                try {
                    await run(query.trim());
                } catch (error) {
                    fail(error);
                }
            }, submit);
        });
        return form;
    }
    const badge = (label) => node("span", { class: "audit-tag" }, label);
    const actions = (...buttons) => node("div", { class: "audit-actions" }, buttons);
    const panel = (title, help) =>
        node(
            "section",
            { class: "audit-panel" },
            node("h2", { class: "h5" }, title),
            node("p", { class: "form-text" }, help),
        );
    const count = (d) =>
        d.requirement_type === "courses"
            ? `${d.min_courses} ${d.min_courses === 1 ? "course" : "courses"}`
            : `${text(d.min_credits_milli)} credits${d.min_gpa_milli == null ? "" : ` · ${text(d.min_gpa_milli)} group GPA (earlier definition)`}`;
    const scaleText = (value) =>
        Number(value / 1000).toLocaleString(undefined, {
            minimumFractionDigits: 1,
            maximumFractionDigits: 3,
        });
    const courseLabel = (c) => `${c.code} · ${c.title}`;
    function groupPlan(definition, details, editing = false) {
        const ids = [
            ...new Set(
                definition.courses
                    ? definition.courses.map((course) => course.course_id)
                    : definition.rules.flatMap((rule) => rule.course_ids),
            ),
        ];
        let credits = 0;
        for (const id of ids) {
            if (!Number.isInteger(details[id]?.credits_milli))
                return {
                    course_count: ids.length,
                    catalog_credits_milli: null,
                    rule_count: definition.rules.length,
                    warnings: ["Course details are unavailable. Reload the group before comparing totals."],
                };
            credits += details[id].credits_milli;
        }
        const summary = {
            course_count: ids.length,
            catalog_credits_milli: credits,
            rule_count: definition.rules.length,
            warnings: [],
        };
        const raw =
            definition.requirement_type === "courses" ? definition.min_courses : definition.min_credits_milli;
        let required = raw;
        if (editing) {
            const pattern = definition.requirement_type === "courses" ? /^\d{1,3}$/ : /^\d{1,7}(\.\d{1,3})?$/;
            if (!pattern.test(String(raw))) required = null;
            else required = definition.requirement_type === "courses" ? Number(raw) : milli(String(raw));
        }
        if (definition.rules.some(r=>r.eligibility)) summary.warnings.push("Listed totals exclude saved-rule matches. Run a student test to evaluate matching work.");
        else if (!ids.length) summary.warnings.push("Add published courses to plan this group.");
        else if (required == null)
            summary.warnings.push("Enter a valid required total to compare it with the listed courses.");
        else if (definition.requirement_type === "courses") {
            if (required > ids.length)
                summary.warnings.push(
                    "The required course count exceeds the number of distinct listed courses.",
                );
        } else if (required > credits)
            summary.warnings.push(
                "The required credits exceed the catalog credits of the listed courses. Review this total.",
            );
        return summary;
    }
    function planningPanel(liveUpdates = false) {
        const counts = node("p", { class: "mb-2" }),
            warnings = node("div"),
            live = node(
                "div",
                liveUpdates ? { role: "status", "aria-atomic": "true" } : {},
                counts,
                warnings,
            ),
            element = node(
                "section",
                { class: "audit-tree-section", role: "group", "aria-label": "Group planning summary" },
                node("h3", { class: "h6" }, "Group at a glance"),
                live,
                node(
                    "p",
                    { class: "form-text" },
                    "Each listed course is counted once. Catalog credits are planning figures; student progress depends on grades, awards and approved exceptions.",
                ),
            );
        return {
            element,
            update(summary) {
                counts.textContent = `${summary.course_count} distinct ${summary.course_count === 1 ? "course" : "courses"} · ${summary.catalog_credits_milli == null ? "Catalog credits unavailable" : `${text(summary.catalog_credits_milli)} catalog credits`} · ${summary.rule_count} requirement ${summary.rule_count === 1 ? "card" : "cards"}`;
                warnings.replaceChildren(
                    ...summary.warnings.map((message) =>
                        node("p", { class: "alert alert-warning py-2" }, message),
                    ),
                );
            },
        };
    }
    function courseRuleTree(saved) {
        const fields = {
            course_id: "Course",
            subject_id: "Subject",
            department_id: "Department",
            academic_level_id: "Academic level",
            course_level_id: "Course level",
            course_level_number: "Numeric course level",
        };
        const operators = {
            equal: "is",
            not_equal: "is not",
            in: "is one of",
            not_in: "is not one of",
            greater: "is greater than",
            less: "is less than",
        };
        const group = (value) =>
            node(
                "div",
                { class: "audit-tree-section" },
                node("strong", {}, value.condition === "AND" ? "All conditions" : "Any condition"),
                node(
                    "ul",
                    {},
                    value.rules.map((rule) => {
                        if (rule.condition) return node("li", {}, group(rule));
                        const values = Array.isArray(rule.value) ? rule.value : [rule.value];
                        const labels = values.map((v) =>
                            rule.id === "course_level_number"
                                ? String(v)
                                : saved.definition.references[rule.id]?.[v]?.code || "Selected reference",
                        );
                        return node("li", {}, `${fields[rule.id]} ${operators[rule.operator]} ${labels.join(", ")}`);
                    }),
                ),
            );
        return node(
            "section",
            { class: "audit-preview" },
            node("h3", { class: "h6" }, `${saved.code} · ${saved.title} · Revision ${saved.revision}`),
            group(saved.definition.tree),
        );
    }
    function acceptedPublicationList(course) {
        const versions = course?.accepted_versions || [];
        if (!versions.length) return null;
        const view = node("details", {}, node("summary", {}, `Also accepted publications (${versions.length})`));
        let loaded = false;
        view.addEventListener("toggle", () => {
            if (!view.open || loaded) return;
            loaded = true;
            view.append(
                node(
                    "ul",
                    {},
                    versions.map((v) =>
                        node(
                            "li",
                            {},
                            `${v.version ? `Version ${v.version} · ` : ""}${courseLabel(v)} · ${text(v.credits_milli)} credits`,
                        ),
                    ),
                ),
            );
        });
        return view;
    }
    function groupTree(item, open = true) {
        const d = item.definition,
            details = item.course_details || {};
        const tree = node("details", { class: "audit-tree", open }, node("summary", {}, d.name, " ", badge(count(d))));
        if (!item.course_approvals) {
            const plan = planningPanel();
            plan.update(item.planning_summary || groupPlan(d, details));
            tree.append(plan.element);
        }
        if (d.min_course_grade_points_milli != null)
            tree.append(
                node(
                    "p",
                    { class: "form-text" },
                    `Each applied course requires at least ${text(d.min_course_grade_points_milli)} out of ${scaleText(item.effective_grade_point_scale?.maximum_milli ?? d.max_course_grade_points_milli)} grade points, using its grade definition.`,
                ),
            );
        for (const r of d.rules) {
            tree.append(
                node(
                    "section",
                    {},
                    node(
                        "h3",
                        { class: "h6" },
                        r.mode === "all"
                            ? "Take all"
                            : r.mode === "choose"
                              ? `Choose ${r.minimum_courses}`
                              : r.mode === "credits"
                                ? `Take ${text(r.minimum_credits_milli)} credits ${r.eligibility ? "matching this rule" : "from this list"}`
                                : d.requirement_type
                                  ? "Eligible courses"
                                  : "Credit pool",
                    ),
                    r.eligibility ? courseRuleTree(r.eligibility) : null,
                    node(
                        "ul",
                        {},
                        r.course_ids.map((id) => {
                            const waived = item.course_approvals?.waived_course_ids.includes(id),
                                replacement = item.course_approvals?.replacements[id];
                            return node(
                                "li",
                                {},
                                node(waived ? "s" : "span", {}, details[id] ? courseLabel(details[id]) : "Selected course"),
                                " ",
                                details[id] ? badge(`${text(details[id].credits_milli)} credits`) : null,
                                ...(details[id]?.accepted_versions?.length ? [acceptedPublicationList(details[id])] : []),
                                ...(item.course_approvals?.publications?.[id] || []).map((v) =>
                                    node(
                                        "p",
                                        { class: "form-text" },
                                        `Additional publication accepted for this pursuit: version ${v.version} · ${courseLabel(v)} · ${text(v.credits_milli)} catalog credits. Earned work remains separate.`,
                                    ),
                                ),
                                ...(waived ? [" ", badge("Waived for this pursuit")] : []),
                                ...(replacement
                                    ? [
                                          node(
                                              "p",
                                              { class: "form-text" },
                                              `Approved alternative: ${courseLabel(replacement)} · Publication ${replacement.version} · ${text(replacement.credits_milli)} catalog credits. Completion still depends on earned work.`,
                                          ),
                                      ]
                                    : []),
                            );
                        }),
                    ),
                ),
            );
        }
        if (!d.rules.length) tree.append(node("p", {}, "Add a requirement to begin."));
        return tree;
    }
    const policyChoices = {
        group_reuse: [
            ["one_group", "Apply a completed course to one group"],
            ["multiple_groups", "Allow a completed course in multiple groups"],
        ],
        repeat_credit: [
            ["highest_earned", "Highest earned award per course"],
            ["all_eligible", "Every eligible earned award"],
        ],
        repeat_gpa: [
            ["all_eligible", "All eligible graded attempts"],
            ["highest_grade", "Highest eligible grade per course"],
            ["latest_eligible", "Latest eligible attempt per course"],
        ],
        repeat_attempt_order: [
            ["academic_period", "Academic term / instructional period"],
            ["completion_date", "Actual completion date"],
        ],
        transfer_overall_gpa: [
            ["exclude", "Exclude transfer awards"],
            ["include_eligible", "Include accepted GPA-eligible awards"],
        ],
    };
    const policyLabels = {
        group_reuse: "Completed courses across requirement groups",
        repeat_credit: "Repeated-course earned credit",
        repeat_gpa: "Repeated-course GPA",
        repeat_attempt_order: "How latest attempts are ordered",
        transfer_overall_gpa: "Transfer awards in overall GPA",
    };
    function degreeTree(item, approvals = null) {
        const d = approvals
                ? { ...item.definition, ...approvals.effective_minimums.degree }
                : item.definition,
            tree = node(
                "section",
                { class: "audit-preview" },
                node("h2", { class: "h4" }, d.name),
                node(
                    "p",
                    {},
                    `${text(d.min_credits_milli)} total credits · ${text(d.min_resident_milli)} resident credits`,
                ),
            );
        if (item.program_publication) {
            const program = item.program_publication;
            const definition = program.definition;
            const facts = node(
                "details",
                { class: "audit-tree-section" },
                node("summary", {}, `Retained program · ${definition.code} — ${definition.title}`),
                node("p", {}, `${program.academic_year_code} · Catalog revision ${program.catalog_revision}`),
                node(
                    "p",
                    { class: "course-description-text" },
                    definition.description || "No description specified.",
                ),
            );
            for (const [kind, label] of Object.entries({
                department: "Department",
                academic_level: "Academic level",
                award: "Award",
            })) {
                const value = definition.classifications[kind];
                facts.append(
                    node("p", {}, `${label}: ${value ? `${value.code} — ${value.title}` : "Not specified"}`),
                );
            }
            tree.append(facts);
        }
        if (item.grade_scale) {
            const scale = item.grade_scale;
            const scaleDetails = node(
                "details",
                { class: "audit-tree" },
                node(
                    "summary",
                    {},
                    `${scale.source === "program" ? "Program" : scale.source === "default" ? "Unconfigured default" : "Global"} grading scale · Maximum ${scaleText(scale.maximum_milli)} grade points`,
                ),
            );
            for (const grade of scale.definition.grades)
                scaleDetails.append(
                    node(
                        "p",
                        {},
                        `${grade.code}: ${grade.min_score_milli == null ? "No percentage range" : `${text(grade.min_score_milli)}–${text(grade.max_score_milli)}%`} · ${text(grade.points_milli)} grade points · ${grade.counts_gpa ? "Counts in GPA" : "Excluded from GPA"}`,
                    ),
                );
            tree.append(scaleDetails);
        } else
            tree.append(
                node(
                    "p",
                    { class: "form-text" },
                    "Grading scale was not recorded with this earlier definition.",
                ),
            );
        const courseApprovals = approvals ? previewCourseApprovals(approvals) : null;
        for (const g of d.groups) {
            const resolved = item.group_details?.[g.group_id];
            if (resolved)
                tree.append(
                    groupTree({
                        ...resolved,
                        definition: approvals
                            ? { ...resolved.definition, ...approvals.effective_minimums.groups[g.group_id] }
                            : resolved.definition,
                        effective_grade_point_scale: item.grade_scale,
                        ...(approvals ? { course_approvals: courseApprovals } : {}),
                    }),
                );
            else if (g.rules)
                tree.append(
                    groupTree({
                        definition: g,
                        course_details: item.course_details,
                        ...(approvals ? { course_approvals: courseApprovals } : {}),
                    }),
                );
        }
        for (const [key, choices] of Object.entries(policyChoices))
            tree.append(
                node(
                    "p",
                    { class: "form-text" },
                    `${policyLabels[key]}: ${choices.find(([v]) => v === d.policies?.[key])?.[1] || "Not recorded"}`,
                ),
            );
        tree.append(
            node(
                "p",
                { class: "form-text" },
                "Waivers remove required courses everywhere; they create no earned credit or GPA points. This is a requirements preview, not a student degree audit.",
            ),
        );
        return tree;
    }
    function pager(target, data, after, stack, run, cursorKey = "next_after") {
        if (stack.length) target.append(button("Previous page", () => run(stack.at(-1), stack.slice(0, -1))));
        if (data[cursorKey])
            target.append(button("Next page", () => run(data[cursorKey], [...stack, after])));
    }
    async function library(query = "", after = null, stack = [], back = null) {
        const result = await post("/sis/course-groups/page/", {
            published_only: false,
            ...(query ? { search: query } : {}),
            ...(after ? { after } : {}),
        });
        const body = screen("Course group library", "REUSABLE BUILDING BLOCKS");
        body.append(
            node(
                "p",
                {},
                "Build a group once. Reuse its published versions in any degree, program or academic year. Groups have no program owner.",
            ),
            actions(
                button(
                    "Create course group",
                    () => newGroup(back || (() => library()), result.grade_point_scale),
                    "btn-primary",
                ),
                back ? button("Return to degree draft", back) : null,
            ),
            search("Search course groups", query, (q) => library(q, null, [], back)),
        );
        const cards = node("div", { class: "audit-library-grid" });
        for (const g of result.items)
            cards.append(
                node(
                    "article",
                    { class: "audit-library-card" },
                    badge(g.published_version_id ? "Published version available" : "Draft"),
                    node("h2", { class: "h5" }, g.name),
                    node("p", { class: "form-text" }, `Working draft · revision ${g.revision}`),
                    actions(
                        button(`Build ${g.name}`, async () =>
                            groupEditor(
                                await request(`/sis/course-groups/${g.id}/`),
                                back || (() => library(query, after, stack)),
                            ),
                        ),
                        button("Version history", () =>
                            history("group", g.id, null, back || (() => library(query, after, stack))),
                        ),
                    ),
                ),
            );
        if (!result.items.length)
            cards.append(node("p", {}, "No groups yet. Create a reusable block to get started."));
        body.append(cards);
        pager(body, result, after, stack, (a, s) => library(query, a, s, back));
    }
    function newGroup(back, scale = { maximum_milli: 4000, is_default: true }) {
        let name = "",
            creationId = null;
        const body = screen("Create a reusable course group");
        body.append(
            node(
                "p",
                {},
                "General education, a major core, or a flexible elective pool: each is an independent library block.",
            ),
            field("Course group name", "", (v) => (name = v), { maxlength: 191 }),
            actions(
                button(
                    "Create group draft",
                    async () => {
                        const result = await post("/sis/course-groups/create/", {
                            id: (creationId ??= await op()),
                            definition: {
                                name: name.trim(),
                                min_credits_milli: 0,
                                requirement_type: "credits",
                                min_courses: 0,
                                min_course_grade_points_milli: null,
                                max_course_grade_points_milli: scale.maximum_milli,
                                grade_scale_program_id: null,
                                courses: [],
                                rules: [],
                            },
                        });
                        groupEditor(result, back);
                    },
                    "btn-primary",
                ),
                button(back.returnLabel || "Back to library", back),
            ),
        );
    }
    async function launch(program, after = null, stack = []) {
        const result = await post(`/sis/programs/${program}/degree-audits/page/`, after ? { after } : {}),
            body = screen("Degree audit builder");
        body.append(
            node(
                "p",
                {},
                "Compose the requirements for a program and academic year from published course-group blocks. Each selection keeps its exact published version.",
            ),
            actions(
                button("Set up an academic year", () => years(program), "btn-primary"),
                button("Course group library", () => library("", null, [], () => launch(program))),
            ),
        );
        const cards = node("div", { class: "audit-library-grid" });
        for (const row of result.items)
            cards.append(
                node(
                    "article",
                    { class: "audit-library-card" },
                    badge(row.academic_year_code),
                    node("h2", { class: "h5" }, row.name || "Degree requirements"),
                    node("p", {}, `Draft revision ${row.revision}`),
                    actions(
                        button(`Edit ${row.academic_year_code} requirements`, async () =>
                            degreeEditor(await request(`/sis/degree-audits/${row.id}/`)),
                        ),
                        ui.can("sis:student:read")
                            ? button(`Test ${row.academic_year_code} audit`, async () => {
                                  const saved = await request(`/sis/degree-audits/${row.id}/`);
                                  auditTrial(saved, saved.definition, () => launch(program, after, stack));
                              })
                            : null,
                        button("Published requirements", () => history("degree", row.id, program, () => launch(program))),
                    ),
                ),
            );
        if (!result.items.length)
            cards.append(node("p", {}, "No requirements for this program yet. Choose an academic year to start."));
        body.append(cards);
        pager(body, result, after, stack, (a, s) => launch(program, a, s));
    }
    async function years(program, source = null, query = "", after = null, stack = []) {
        query = query.trim();
        const result = await post("/sis/academic-years/", {
            ...(query ? { search: query } : {}),
            ...(after ? { after } : {}),
        });
        const body = screen(
            source ? "Clone requirements to another academic year" : "Choose an academic year",
        );
        body.append(
            node(
                "p",
                {},
                "Academic years are set up in the Academic catalog. Catalog years remain derived from matriculation.",
            ),
            search("Academic year search", query, (q) => years(program, source, q)),
        );
        for (const y of result.items)
            body.append(
                node(
                    "article",
                    { class: "audit-library-card" },
                    node("h2", { class: "h5" }, y.code),
                    button(`Use ${y.code}`, async () => {
                        const row = source
                            ? await post(`/sis/degree-audits/${source.id}/clone/`, {
                                  version_id: source.version,
                                  program_id: program,
                                  academic_year_id: y.id,
                              })
                            : await post(`/sis/programs/${program}/degree-audits/open/`, {
                                  academic_year_id: y.id,
                              });
                        degreeEditor(row);
                    }),
                ),
            );
        if (!result.items.length)
            body.append(
                node(
                    "p",
                    {},
                    "No matching academic years. Set up an academic year in the Academic catalog first.",
                ),
            );
        pager(body, result, after, stack, (a, s) => years(program, source, query, a, s));
        body.append(button("Back to requirement drafts", () => launch(program)));
    }
    function workbench(body, titles) {
        const grid = node("div", { class: "audit-builder-grid" }),
            parts = titles.map(([title, help]) => panel(title, help));
        grid.append(...parts);
        body.append(grid);
        return parts;
    }
    function checkbox(label, update) {
        const input = node("input", {
            type: "checkbox",
            class: "form-check-input",
        });
        input.addEventListener("change", () => update(input.checked));
        return node("label", { class: "audit-source-choice" }, input, node("span", {}, label));
    }
    function draggable(element, payload) {
        element.draggable = true;
        element.addEventListener("dragstart", (e) => {
            e.dataTransfer.setData("application/x-etsis-requirement", JSON.stringify(payload));
            e.dataTransfer.effectAllowed = "copyMove";
        });
        element.append(
            node("span", { class: "audit-drag-hint", "aria-hidden": "true" }, "⠿ Drag or use the buttons"),
        );
    }
    function dropzone(element, accept) {
        element.addEventListener("dragover", (e) => {
            if (e.dataTransfer.types.includes("application/x-etsis-requirement")) {
                e.preventDefault();
                e.dataTransfer.dropEffect = "copy";
            }
        });
        element.addEventListener("drop", (e) => {
            e.preventDefault();
            e.stopPropagation();
            task(async () => {
                try {
                    const data = JSON.parse(e.dataTransfer.getData("application/x-etsis-requirement"));
                    await accept(data);
                } catch (error) {
                    fail(error);
                }
            }, element);
        });
    }
    function move(list, index, offset) {
        const next = index + offset;
        if (next < 0 || next >= list.length) return;
        [list[index], list[next]] = [list[next], list[index]];
    }
    function normalGroup(model) {
        const d = structuredClone(model);
        d.name = d.name.trim();
        d.min_credits_milli = d.requirement_type === "credits" ? milli(d.min_credits_milli) : 0;
        d.min_courses = d.requirement_type === "courses" ? Number(d.min_courses) : 0;
        d.min_course_grade_points_milli = milli(d.min_course_grade_points_milli, true);
        if (
            d.min_course_grade_points_milli != null &&
            d.min_course_grade_points_milli > d.max_course_grade_points_milli
        )
            throw new Error("The per-course minimum cannot exceed the maximum from grade definitions.");
        const used = new Set();
        for (const r of d.rules) {
            r.minimum_courses =
                r.mode === "all" ? r.course_ids.length : r.mode === "choose" ? Number(r.minimum_courses) : 0;
            if (r.mode === "credits") r.minimum_credits_milli = milli(String(r.minimum_credits_milli));
            else delete r.minimum_credits_milli;
            r.course_ids.forEach((id) => used.add(id));
        }
        d.courses = d.courses.filter((c) => used.has(c.course_id));
        return d;
    }
    // Both builders share one consent and stale-editor workflow. No browser storage.
    function readinessButton(kind, body, current, normalize) {
        return button(`Optional ${kind} AI help`, async () => {
            const saved = current();
            const baseline = JSON.stringify(saved.definition);
            if (JSON.stringify(normalize()) !== baseline) {
                throw new Error("Save changes before reviewing optional assistance.");
            }
            const preview = await request(
                `/sis/${kind === "group" ? "course-groups" : "degree-audits"}/${saved.id}/assistance/`,
            );
            if (!body.isConnected) return;
            if (preview.saved_revision !== current().revision || JSON.stringify(normalize()) !== baseline) {
                throw new Error("The saved draft changed. Reload it and inspect a fresh preview.");
            }
            body.querySelector(`[data-${kind}-assistance]`)?.remove();
            const panel = node(
                "section",
                {
                    [`data-${kind}-assistance`]: "",
                    "aria-label": `Optional ${kind} assistance`,
                    class: "record-section",
                },
                node("h3", { tabindex: "-1" }, `Saved ${kind} setup readiness`),
            );
            body.append(panel);
            panel.querySelector("h3").focus();
            if (!preview.enabled) {
                panel.append(
                    node(
                        "p",
                        { role: "status" },
                        "Optional assistance is disabled. Normal editing and publication remain available.",
                    ),
                );
                return;
            }
            const consent = node("input", {
                type: "checkbox",
                id: `${kind}-ai-consent`,
                class: "form-check-input",
                required: true,
            });
            const progress = node("p", { role: "status", class: "form-text" });
            const results = node("div", { "aria-live": "polite" });
            const controls = node(
                "fieldset",
                { class: "border-0 p-0" },
                node("legend", { class: "h5" }, `Consent for one ${kind} request`),
                node(
                    "div",
                    { class: "form-check mb-3" },
                    consent,
                    node(
                        "label",
                        { for: consent.id, class: "form-check-label" },
                        `I agree to send the displayed saved ${kind} setup to ${preview.label} for this request.`,
                    ),
                ),
                button(`Suggest saved ${kind} checks`, async () => {
                    if (!consent.reportValidity()) return;
                    if (
                        JSON.stringify(normalize()) !== baseline ||
                        current().revision !== preview.saved_revision
                    ) {
                        throw new Error("The saved draft changed. Save it and inspect a fresh preview.");
                    }
                    controls.disabled = true;
                    consent.checked = false;
                    results.replaceChildren();
                    progress.textContent = "Requesting suggestions… No requirements are being changed.";
                    try {
                        const response = await request(
                            `/sis/${kind === "group" ? "course-groups" : "degree-audits"}/${saved.id}/assistance/`,
                            "POST",
                            {
                                consent: true,
                                revision: preview.revision,
                            },
                        );
                        if (!results.isConnected) return;
                        if (
                            JSON.stringify(normalize()) !== baseline ||
                            current().revision !== preview.saved_revision
                        ) {
                            progress.textContent =
                                "Your draft changed. Suggestions were discarded; review a fresh saved preview.";
                            return;
                        }
                        const list = node("ul");
                        response.advice.suggestions.forEach((value) => list.append(node("li", {}, value)));
                        results.append(
                            node("h4", {}, "Suggestions to review"),
                            node("p", {}, response.advice.summary),
                            list,
                        );
                        progress.textContent =
                            "Suggestions received. No draft, publication or student requirements were changed.";
                    } finally {
                        controls.disabled = false;
                        if (!results.childNodes.length && !progress.textContent.includes("discarded"))
                            progress.textContent =
                                "No suggestion was applied. Another request requires fresh consent.";
                    }
                }),
            );
            panel.append(
                node(
                    "p",
                    {},
                    `Provider: ${preview.label}. Inspect the displayed facts. Do not consent if they contain confidential content. Suggestions may be incorrect.`,
                ),
                node(
                    "p",
                    {},
                    kind === "group"
                        ? "This reviews a reusable group and its exact selected course publications. Numeric positions connect courses to rules; they are not database IDs. Student records, descriptions, internal identifiers and publication reasons are excluded. Verify institutional policy separately."
                        : "This reviews saved minimums, policies and exact selected group/course publications. Numeric course positions identify overlaps without sending database IDs. Groups without a selected publication have no supplied contents. Student records, descriptions, publication reasons and unsaved edits are excluded. Verify coverage and institutional policy separately.",
                ),
                node(
                    "details",
                    {},
                    node("summary", {}, `Inspect saved ${kind} facts to be sent`),
                    node("pre", { class: "text-wrap text-break" }, JSON.stringify(preview.draft, null, 2)),
                ),
                controls,
                progress,
                results,
            );
        });
    }

    function groupEditor(saved, back, state = null) {
        if (!state && !saved.definition.requirement_type) {
            const body = screen("Update course group settings");
            body.append(
                node(
                    "p",
                    {},
                    "This earlier group records a credit minimum and an optional average group GPA. Choose the updated credit/course quantity and per-course grade-point threshold explicitly; its published history stays intact.",
                ),
                node(
                    "p",
                    {},
                    `Earlier average group GPA: ${saved.definition.min_gpa_milli == null ? "No minimum recorded" : text(saved.definition.min_gpa_milli)}. This value is not copied into the per-course grade-point field.`,
                ),
                actions(
                    button(
                        "Use credit/course settings",
                        () => {
                            const definition = structuredClone(saved.definition);
                            delete definition.min_gpa_milli;
                            Object.assign(definition, {
                                requirement_type: "credits",
                                min_courses: 0,
                                min_course_grade_points_milli: null,
                                max_course_grade_points_milli: saved.grade_point_scale.maximum_milli,
                                grade_scale_program_id: null,
                            });
                            groupEditor({ ...saved, definition }, back);
                        },
                        "btn-primary",
                    ),
                    button(back.returnLabel || "Back to library", back),
                ),
            );
            return;
        }

        let scale = state?.scale || saved.grade_point_scale;
        const model = state?.model || {
            ...structuredClone(saved.definition),
            rules: saved.definition.rules.map((rule) => ({
                ...structuredClone(rule),
                ...(rule.mode === "credits" ? { minimum_credits_milli: text(rule.minimum_credits_milli) } : {}),
            })),
            max_course_grade_points_milli: scale.maximum_milli,
            min_credits_milli: text(saved.definition.min_credits_milli),
            min_courses: String(saved.definition.min_courses),
            min_course_grade_points_milli: text(saved.definition.min_course_grade_points_milli),
        };
        const details = state?.details || structuredClone(saved.course_details || {});
        let active = state?.active || model.rules[0]?.id || null;
        const availableCourses = new Map();
        const body = screen("Course group builder", "INDEPENDENT LIBRARY BLOCK"),
            status = node("p", { role: "status", class: "audit-status" });
        const restore = () => groupEditor(saved, back, { model, details, active, scale });
        body.append(
            actions(
                button(back.returnLabel || "Back to library", back),
                button("Requirements preview", () =>
                    preview({ definition: normalGroup(model), course_details: details }, "group", restore),
                ),
                readinessButton(
                    "group",
                    body,
                    () => saved,
                    () => normalGroup(model),
                ),
                button("Save group draft", async () => {
                    saved = await save();
                    status.textContent = "Course group draft saved.";
                }),
                button(
                    "Save & review publication",
                    async () => {
                        saved = await save();
                        reasonScreen("group", saved, restore, back);
                    },
                    "btn-primary",
                ),
            ),
            status,
        );
        const [source, canvas, inspector] = workbench(body, [
            [
                "1 · Choose courses",
                "Check a published course version, then add it to a requirement. You can also drag a version card.",
            ],
            [
                "2 · Build requirements",
                "Select a requirement card to add courses. Use the buttons to reorder; dragging works too.",
            ],
            [
                "3 · Group settings",
                "This block is reusable across all programs. Publishing freezes the reviewed definition.",
            ],
        ]);
        const plan = planningPanel(true);
        function updatePlan() {
            plan.update(groupPlan(model, details, true));
        }
        const creditsField = field(
                "Group required credits",
                model.min_credits_milli,
                (v) => {
                    model.min_credits_milli = v;
                    updatePlan();
                },
                { inputmode: "decimal" },
            ),
            coursesField = field(
                "Number of courses required",
                model.min_courses,
                (v) => {
                    model.min_courses = v;
                    updatePlan();
                },
                { type: "number", min: 0, max: 200 },
            );
        const creditMode = button("Require credits", () => {
                model.requirement_type = "credits";
                quantity();
            }),
            courseMode = button("Require courses", () => {
                model.requirement_type = "courses";
                quantity();
            });
        function quantity() {
            creditsField.hidden = model.requirement_type !== "credits";
            coursesField.hidden = model.requirement_type !== "courses";
            creditMode.setAttribute("aria-pressed", String(model.requirement_type === "credits"));
            courseMode.setAttribute("aria-pressed", String(model.requirement_type === "courses"));
            updatePlan();
        }
        inspector.append(
            field("Course group name", model.name, (v) => (model.name = v), { maxlength: 191 }),
            node(
                "div",
                { role: "group", "aria-label": "Group requirement type", class: "audit-segments" },
                creditMode,
                courseMode,
            ),
            creditsField,
            coursesField,
            field(
                "Minimum grade points for each course (optional)",
                model.min_course_grade_points_milli,
                (v) => (model.min_course_grade_points_milli = v),
                {
                    type: "number",
                    inputmode: "decimal",
                    min: 0,
                    max: scale.maximum_milli / 1000,
                    step: "0.001",
                },
            ),
            button("Choose grading context", () =>
                gradingContext((program, chosen) => {
                    model.grade_scale_program_id = program;
                    model.max_course_grade_points_milli = chosen.maximum_milli;
                    scale = chosen;
                    restore();
                }, restore),
            ),
            node(
                "output",
                { "aria-label": "Maximum grade points", class: "form-text" },
                `Out of ${scaleText(scale.maximum_milli)} grade points${scale.is_default ? " · Default until grade definitions are configured" : scale.source === "program" ? " · Program grading scale" : " · From grade definitions"}`,
            ),
            node(
                "p",
                { class: "form-text" },
                "Choose the institution’s required minimum for each applied course, or leave it blank. The scale maximum is automatic; each grade definition supplies the course’s grade-point value.",
            ),
            plan.element,
            badge(`Working draft · revision ${saved.revision}`),
        );
        quantity();
        const cards = node("div", { class: "audit-canvas" });
        const versionReview = node("section", {
            class: "audit-version-review audit-tree",
            role: "group",
            "aria-label": "Review course version changes",
            tabindex: "-1",
            hidden: true,
        });
        canvas.append(
            button(
                "Add requirement",
                async () => {
                    const r = {
                        id: await op(),
                        mode: "pool",
                        minimum_courses: 0,
                        course_ids: [],
                    };
                    model.rules.push(r);
                    active = r.id;
                    render();
                },
                "btn-primary",
            ),
            versionReview,
            cards,
        );
        async function save() {
            return post(`/sis/course-groups/${saved.id}/save/`, {
                revision: saved.revision,
                definition: normalGroup(model),
            });
        }
        async function pickRule(rule, query = "", after = null, stack = []) {
            const previous = dialog;
            const data = await post("/sis/course-rules/page/", { search: query, ...(after ? { after } : {}) });
            if (dialog !== previous) return;
            const body = screen("Choose a saved course rule", "EXPLICIT REVISION SELECTION");
            body.append(
                search("Find a course rule by code", query, (q) => pickRule(rule, q)),
                node(
                    "p",
                    {},
                    "Browse a rule's saved revisions. Selecting one retains its conditions in this group; later rule edits do not replace it.",
                ),
            );
            for (const item of data.items) body.append(button(`Browse ${item.code} revisions`, () => revisions(item)));
            if (!data.items.length)
                body.append(
                    node("p", {}, "No matching saved course rules. Create a rule in Rules → Course eligibility first."),
                );
            pager(body, data, after, stack, (a, s) => pickRule(rule, query, a, s));
            body.append(button("Back to group draft", restore));
            async function revisions(item, before = null, history = []) {
                const previous = dialog;
                const page = await post(`/sis/course-rules/${item.rule_id}/revisions/`, before ? { before } : {});
                if (dialog !== previous) return;
                const body = screen("Choose a course-rule revision");
                for (const version of page.items)
                    body.append(
                        button(`Use ${version.code} revision ${version.revision} — ${version.title}`, async () => {
                            const previous = dialog;
                            const selected = await request(`/sis/course-rules/${version.id}/`);
                            if (dialog !== previous) return;
                            rule.eligibility = selected;
                            rule.course_ids = [];
                            if (rule.mode === "all") {
                                rule.mode = "credits";
                                rule.minimum_courses = 0;
                                rule.minimum_credits_milli = "3";
                            }
                            const used = new Set(model.rules.flatMap((r) => r.course_ids));
                            model.courses = model.courses.filter((c) => used.has(c.course_id));
                            restore();
                        }),
                    );
                if (history.length)
                    body.append(
                        button("Newer saved-rule revisions", () => revisions(item, history.at(-1), history.slice(0, -1))),
                    );
                if (page.next_before)
                    body.append(
                        button("Earlier saved-rule revisions", () =>
                            revisions(item, page.next_before, [...history, before]),
                        ),
                    );
                body.append(
                    button("Back to saved rules", () => pickRule(rule, query, after, stack)),
                    button("Back to group draft", restore),
                );
            }
        }
        function add(picks, confirmed = false, targetId = active) {
            const r = model.rules.find((rule) => rule.id === targetId);
            if (!r) throw new Error("Add or select a requirement card first.");
            if (r.eligibility)
                throw new Error("Choose Use listed courses on this requirement before adding individual courses.");
            if (new Set(picks.map((p) => p.course_id)).size !== picks.length)
                throw new Error("Choose one published version of each course.");
            const replacements = picks.filter((p) =>
                model.courses.some((c) => c.course_id === p.course_id && c.version_id !== p.version_id),
            );
            if (replacements.length && !confirmed) {
                versionReview.hidden = false;
                versionReview.replaceChildren(
                    node("h3", { class: "h5" }, "Review course version changes"),
                    node(
                        "p",
                        {},
                        "Adopting a version replaces this course reference throughout this working group. Published groups and degrees keep their earlier versions. The previous required publication is not automatically added to the accepted list.",
                    ),
                );
                for (const p of replacements) {
                    const affected = model.rules
                        .map((rule, i) => (rule.course_ids.includes(p.course_id) ? i + 1 : null))
                        .filter(Boolean);
                    versionReview.append(
                        node(
                            "p",
                            {},
                            `${p.detail.code}: ${details[p.course_id]?.title || "Current course"} → ${p.detail.title}. ${text(details[p.course_id]?.credits_milli || 0)} → ${text(p.detail.credits_milli)} credits. Used in requirements ${affected.join(", ")}.`,
                        ),
                    );
                }
                versionReview.append(
                    actions(
                        button(
                            "Adopt selected course versions",
                            () => {
                                add(picks, true, targetId);
                                versionReview.hidden = true;
                                cards.querySelector("button")?.focus({ preventScroll: true });
                            },
                            "btn-primary",
                        ),
                        button("Keep current course versions", () => {
                            versionReview.hidden = true;
                            status.textContent = "Current course references kept.";
                            cards.querySelector("button")?.focus({ preventScroll: true });
                        }),
                    ),
                );
                versionReview.focus();
                return;
            }
            for (const pick of picks) {
                const existing = model.courses.find((c) => c.course_id === pick.course_id);
                const accepted = (existing?.accepted_version_ids || []).filter(v=>v!==pick.version_id);
                const acceptedDetails = (details[pick.course_id]?.accepted_versions || []).filter(v=>accepted.includes(v.version_id));
                if (existing) {
                    existing.version_id = pick.version_id;
                    if (accepted.length) existing.accepted_version_ids = accepted;
                    else delete existing.accepted_version_ids;
                } else model.courses.push({ course_id: pick.course_id, version_id: pick.version_id });
                details[pick.course_id] = {...pick.detail, ...(acceptedDetails.length ? {accepted_versions:acceptedDetails} : {})};
                if (!r.course_ids.includes(pick.course_id)) r.course_ids.push(pick.course_id);
            }
            render();
            status.textContent = "Selected course versions applied to the working group.";
        }
        function render() {
            updatePlan();
            const focusedLabel = cards.contains(document.activeElement) ? document.activeElement.textContent : null;
            cards.replaceChildren();
            if (!model.rules.length)
                cards.append(
                    node(
                        "p",
                        { class: "audit-empty" },
                        "Start with a requirement, then choose its courses. Try an all-courses core, a choose-two list, or an elective pool.",
                    ),
                );
            model.rules.forEach((r, i) => {
                const card = node("article", {
                    class: `audit-rule-card${active === r.id ? " selected" : ""}`,
                });
                card.append(
                    button(
                        `Select requirement ${i + 1}`,
                        () => {
                            active = r.id;
                            render();
                        },
                        active === r.id ? "btn-primary" : "btn-outline-primary",
                    ),
                    node(
                        "div",
                        {
                            class: "audit-segments",
                            role: "group",
                            "aria-label": `Requirement ${i + 1} type`,
                        },
                        [
                            ["all", "All of these"],
                            ["choose", "Choose a number"],
                            ["credits", "Take credits"],
                            ["pool", "Eligible courses"],
                        ]
                            .filter(([mode]) => !r.eligibility || mode !== "all")
                            .map(([mode, label]) => {
                                const b = button(label, () => {
                                    r.mode = mode;
                                    r.minimum_courses = mode === "choose" ? 1 : 0;
                                    if (mode === "credits") r.minimum_credits_milli = "3";
                                    else delete r.minimum_credits_milli;
                                    render();
                                });
                                b.setAttribute("aria-pressed", String(r.mode === mode));
                                return b;
                            }),
                    ),
                );
                card.append(...[
                    r.eligibility
                        ? courseRuleTree(r.eligibility)
                        : node("p", { class: "form-text" }, "Source: selected published courses"),
                    ui.can("sis:rules:manage")
                        ? button(`Choose saved rule for requirement ${i + 1}`, () => pickRule(r))
                        : null,
                    r.eligibility
                        ? button(`Use listed courses for requirement ${i + 1}`, () => {
                              delete r.eligibility;
                              r.mode = "all";
                              r.minimum_courses = 0;
                              delete r.minimum_credits_milli;
                              render();
                          })
                        : null,
                ].filter(Boolean));
                if (r.mode === "choose")
                    card.append(
                        field(`Requirement ${i + 1} number of courses`, r.minimum_courses, (v) => (r.minimum_courses = v), {
                            type: "number",
                            min: 1,
                            max: r.eligibility ? 200 : 50,
                        }),
                    );
                if (r.mode === "credits")
                    card.append(
                        field(
                            `Requirement ${i + 1} credits ${r.eligibility ? "matching this rule" : "from this list"}`,
                            r.minimum_credits_milli,
                            (v) => (r.minimum_credits_milli = v),
                            { type: "number", min: "0.001", max: "9999.999", step: "0.001" },
                        ),
                        node(
                            "p",
                            { class: "form-text" },
                            "Only eligible earned credits from this list meet this requirement. The group minimum still applies separately.",
                        ),
                    );
                const list = node("ul", { class: "audit-course-list" });
                for (const id of r.course_ids)
                    list.append(
                        node(
                            "li",
                            {},
                            node("span", {}, details[id] ? courseLabel(details[id]) : "Selected course"),
                            button(`Manage accepted publications for ${details[id]?.code || "course"}`, () => acceptedVersions(id)),
                            ...(details[id]?.accepted_versions?.length ? [acceptedPublicationList(details[id])] : []),
                            button(
                                `Remove ${details[id]?.code || "course"} from requirement ${i + 1}`,
                                () => {
                                    r.course_ids = r.course_ids.filter((c) => c !== id);
                                    if (!model.rules.some((rule) => rule.course_ids.includes(id)))
                                        model.courses = model.courses.filter((c) => c.course_id !== id);
                                    render();
                                },
                                "btn-outline-secondary",
                            ),
                        ),
                    );
                card.append(
                    list,
                    r.eligibility
                        ? node(
                              "p",
                              { class: "form-text" },
                              "Eligibility comes from the retained rule. This card has no hardcoded course list.",
                          )
                        : r.course_ids.length
                          ? badge(`${r.course_ids.length} selected courses`)
                          : node(
                                "p",
                                { class: "form-text" },
                                "Drop published course versions here, or add checked courses.",
                            ),
                    actions(
                        button(`Move requirement ${i + 1} up`, () => {
                            move(model.rules, i, -1);
                            render();
                        }),
                        button(`Move requirement ${i + 1} down`, () => {
                            move(model.rules, i, 1);
                            render();
                        }),
                        button(
                            `Remove requirement ${i + 1}`,
                            () => {
                                model.rules.splice(i, 1);
                                model.courses = model.courses.filter((c) =>
                                    model.rules.some((rule) => rule.course_ids.includes(c.course_id)),
                                );
                                active = model.rules[0]?.id || null;
                                render();
                            },
                            "btn-outline-secondary",
                        ),
                    ),
                );
                draggable(card, { kind: "rule", id: r.id });
                dropzone(card, async (data) => {
                    if (data.kind === "course") {
                        active = r.id;
                        const pick = availableCourses.get(`${data.course_id}/${data.version_id}`);
                        if (!pick) throw new Error("Choose a version from this course panel first.");
                        add([pick]);
                    } else if (data.kind === "rule") {
                        const j = model.rules.findIndex((x) => x.id === data.id);
                        if (j >= 0) {
                            const [moving] = model.rules.splice(j, 1);
                            model.rules.splice(i, 0, moving);
                            render();
                        }
                    }
                });
                cards.append(card);
            });
            if (focusedLabel) {
                const target =
                    [...cards.querySelectorAll("button")].find((b) => b.textContent === focusedLabel) ||
                    cards.querySelector("button") ||
                    cards;
                target.tabIndex = target === cards ? -1 : target.tabIndex;
                target.focus({ preventScroll: true });
            }
        }
        async function acceptedVersions(id, cursor = null, stack = [], state = null) {
            const reference = model.courses.find((c) => c.course_id === id);
            const choices = state || {
                chosen: new Set(reference.accepted_version_ids || []),
                details: new Map((details[id]?.accepted_versions || []).map((v) => [v.version_id, v])),
            };
            const previous = dialog;
            const data = await post(`/sis/courses/${id}/publication/page/`, cursor ? { cursor } : {});
            if (dialog !== previous) return;
            const body = screen(`Accepted publications · ${details[id]?.code || "Course"}`, "EXPLICIT CURRICULUM ALLOWANCES");
            body.append(
                node(
                    "p",
                    {},
                    `Required publication: ${courseLabel(details[id])}. This remains the curriculum label and planning reference.`,
                ),
                node(
                    "p",
                    {},
                    "Choose up to ten additional publications of this course. They can satisfy its named requirements; actual course labels, credits, grades and saved-rule conditions still come from the student's retained work. Save and publish the group to make these choices available to a degree.",
                ),
            );
            const selected = node("section"),
                versions = node("div"),
                limitNotice = node("p", { role: "status" });
            const apply = button(
                "Apply accepted publications to draft",
                () => {
                    if (choices.chosen.size > 10) throw new Error("Choose at most ten additional course publications.");
                    const ids = [...choices.chosen].sort();
                    if (ids.length) {
                        reference.accepted_version_ids = ids;
                        details[id].accepted_versions = ids.map((v) => choices.details.get(v));
                    } else {
                        delete reference.accepted_version_ids;
                        delete details[id].accepted_versions;
                    }
                    restore();
                },
                "btn-primary",
            );
            function selectedList() {
                selected.replaceChildren(
                    node("h2", { class: "h5" }, `${choices.chosen.size} additional publications selected`),
                );
                for (const version of choices.chosen) {
                    const v = choices.details.get(version);
                    selected.append(
                        node(
                            "p",
                            {},
                            `${v.version ? `Version ${v.version} · ` : ""}${courseLabel(v)} · ${text(v.credits_milli)} credits`,
                            button(`Remove accepted publication ${v.version || courseLabel(v)}`, () => {
                                choices.chosen.delete(version);
                                selectedList();
                                versions
                                    .querySelectorAll("input")
                                    .forEach((input) => (input.checked = choices.chosen.has(input.dataset.version)));
                                apply.focus();
                            }),
                        ),
                    );
                }
            }
            for (const v of data.items) {
                const d = {
                    ...v.definition,
                    version_id: v.version_id,
                    version: v.version,
                    title: v.definition.transcript_title || v.definition.title,
                };
                choices.details.set(v.version_id, d);
                if (v.version_id === reference.version_id) {
                    versions.append(node("p", {}, `Version ${v.version} · ${courseLabel(d)} · Required publication`));
                    continue;
                }
                const control = checkbox(
                    `Accept version ${v.version} · ${courseLabel(d)} · ${text(d.credits_milli)} credits`,
                    (on) => {
                        if (on && choices.chosen.size >= 10) {
                            control.querySelector("input").checked = false;
                            limitNotice.textContent = "Choose at most ten additional course publications.";
                            return;
                        }
                        limitNotice.textContent = "";
                        if (on) choices.chosen.add(v.version_id);
                        else choices.chosen.delete(v.version_id);
                        selectedList();
                    },
                );
                control.querySelector("input").checked = choices.chosen.has(v.version_id);
                control.querySelector("input").dataset.version = v.version_id;
                versions.append(control);
            }
            selectedList();
            body.append(selected, limitNotice, versions, actions(apply, button("Keep current accepted publications", restore)));
            pager(body, data, cursor, stack, (c, h) => acceptedVersions(id, c, h, choices), "next_cursor");
        }
        async function courseSource(query = "", after = null, stack = []) {
            const data = await post("/sis/ui/data/courses/", {
                search: query,
                limit: 20,
                ...(after ? { after } : {}),
            });
            source.replaceChildren(
                node("h2", { class: "h5" }, "1 · Choose courses"),
                node(
                    "p",
                    { class: "form-text" },
                    "Check exact published versions. Drag a version or use Add checked courses.",
                ),
                search("Course search", query, (q) => courseSource(q)),
            );
            for (const c of data.items) {
                const card = node("details", { class: "audit-source-card" }, node("summary", {}, courseLabel(c)));
                const choices = node("div", { class: "audit-version-list" });
                let loaded = false;
                card.append(choices);
                card.addEventListener("toggle", () => {
                    if (card.open && !loaded) {
                        loaded = true;
                        task(async () => {
                            try {
                                await courseVersions(c, choices);
                            } catch (error) {
                                loaded = false;
                                fail(error);
                            }
                        }, card);
                    }
                });
                source.append(card);
            }
            if (!data.items.length)
                source.append(
                    node("p", {}, "No matching courses. Publish a course in the Academic catalog before using it here."),
                );
            pager(source, data, after, stack, (a, s) => courseSource(query, a, s));
        }
        async function courseVersions(c, target, cursor = null, stack = []) {
            const data = await post(`/sis/courses/${c.id}/publication/page/`, cursor ? { cursor } : {}),
                selected = new Map();
            target.replaceChildren();
            for (const v of data.items) {
                const pick = {
                    course_id: c.id,
                    version_id: v.version_id,
                    detail: { ...v.definition, title: v.definition.transcript_title || v.definition.title },
                };
                availableCourses.set(`${c.id}/${v.version_id}`, pick);
                const card = node(
                    "div",
                    { class: "audit-version-card" },
                    checkbox(
                        `Version ${v.version} · ${courseLabel(v.definition)} · ${text(v.definition.credits_milli)} credits`,
                        (on) => (on ? selected.set(v.version_id, pick) : selected.delete(v.version_id)),
                    ),
                );
                draggable(card, {
                    kind: "course",
                    course_id: c.id,
                    version_id: v.version_id,
                });
                target.append(card);
            }
            target.append(
                button("Add checked courses", () => {
                    if (!selected.size) throw new Error("Check a published course version first.");
                    add([...selected.values()]);
                    selected.clear();
                    target.querySelectorAll('input[type="checkbox"]').forEach((el) => (el.checked = false));
                }),
            );
            pager(target, data, cursor, stack, (a, s) => courseVersions(c, target, a, s), "next_cursor");
        }
        render();
        courseSource().catch(fail);
    }
    function auditTrial(saved, definition, back, state = null) {
        const snapshot = definition === null ? null : structuredClone(definition);
        const model = state || { student: null, pursuit: null, drafts: false, source: "draft", publication: null };
        const published = model.source === "publication" ? model.publication : null;
        let choiceRequest = 0,
            sourceRequest = 0;
        const body = screen("Test a degree audit", "RUNTIME ONLY · NO SAVED RESULTS");
        const restore = () => auditTrial(saved, snapshot, back, model);
        restore.returnLabel = "Back to test setup";
        body.append(
            node(
                "p",
                { class: "alert alert-info" },
                "This temporary test does not save an audit, publish definitions, or change the student's assigned requirements. Approved student-specific exceptions are not applied to this comparison. Results disappear when you close the workspace.",
            ),
            node("h2", { class: "h5" }, published ? published.definition.name : snapshot.name),
            node(
                "p",
                {},
                published
                    ? `${published.context.program_title} · ${published.context.academic_year_code} · Published audit version ${published.version}. Retained group and grading definitions.`
                    : `${saved.context.program_title} · ${saved.context.academic_year_code} · Current editor snapshot, including unsaved degree edits.`,
            ),
            actions(
                button(snapshot === null ? "Return to audit versions" : "Return to degree builder", back),
                button("Choose another degree audit", () => launch(saved.program_id)),
            ),
        );
        const sourceChoices = node("section", { class: "audit-panel", "aria-label": "Audit source" });
        sourceChoices.append(
            node("h2", { class: "h5" }, "Choose audit source"),
            actions(
                button("Use working degree draft", async () => {
                    if (snapshot !== null) {
                        model.source = "draft";
                        model.publication = null;
                        restore();
                    } else {
                        const target = dialog;
                        const draft = await request(`/sis/degree-audits/${saved.id}/`);
                        if (dialog === target) {
                            model.source = "draft";
                            model.publication = null;
                            auditTrial(draft, draft.definition, back, model);
                        }
                    }
                }),
                button("Choose published audit version", () => versions()),
            ),
        );
        const sourceList = node("div", { class: "audit-library-grid" });
        sourceChoices.append(sourceList);
        if (!published) {
            const choice = checkbox("Use current working drafts for all course groups", (on) => {
                model.drafts = on;
            });
            choice.querySelector("input").checked = model.drafts;
            sourceChoices.append(
                choice,
                node(
                    "p",
                    { class: "form-text" },
                    "Otherwise use each selected group publication; groups without a publication use their saved working draft. Test source revisions appear with the result.",
                ),
            );
        } else {
            sourceChoices.append(
                node(
                    "p",
                    { class: "form-text" },
                    "Published tests use the exact retained degree, group, course and grading definitions. Working drafts cannot replace them.",
                ),
            );
        }
        body.append(sourceChoices);
        async function versions(cursor = null, stack = []) {
            const target = dialog,
                token = ++sourceRequest;
            const data = await post(`/sis/degree-audits/${saved.id}/versions/`, cursor ? { cursor } : {});
            if (dialog !== target || token !== sourceRequest) return;
            sourceList.replaceChildren();
            for (const version of data.items)
                sourceList.append(
                    button(`Test published version ${version.version} — ${version.definition.name}`, () => {
                        model.source = "publication";
                        model.publication = version;
                        restore();
                    }),
                );
            if (!data.items.length)
                sourceList.append(node("p", {}, "No published versions. The working draft can still be tested."));
            pager(sourceList, data, cursor, stack, (next, previous) => versions(next, previous), "next_cursor");
        }
        const students = node("section", { class: "audit-panel" }, node("h2", { class: "h5" }, "Choose a student"));
        const choices = node("div", { class: "audit-library-grid" });
        students.append(
            search("Find a student by name or assigned ID", "", async (query) => {
                if (!query) throw new Error("Enter a student name or assigned ID first.");
                await findStudents(query);
            }),
            choices,
        );
        body.append(students);
        const selection = node("section", { class: "audit-panel", "aria-label": "Selected student pursuit" });
        body.append(selection);
        function selected() {
            selection.replaceChildren();
            if (!model.student || !model.pursuit) {
                selection.append(
                    node(
                        "p",
                        {},
                        "Choose a student and a pursuit to authorize access to their academic work. The test may use a degree from any program.",
                    ),
                );
                return;
            }
            selection.append(
                node(
                    "h2",
                    { class: "h5" },
                    `${model.student.given_name} ${model.student.family_name} · ${model.student.institutional_id}`,
                ),
                node("p", {}, `${model.pursuit.program_title} · ${model.pursuit.term_code} · ${model.pursuit.status}`),
                button("Run temporary audit", run, "btn-primary"),
            );
        }
        async function findStudents(query, after = null, stack = []) {
            const target = dialog,
                token = ++choiceRequest;
            const data = await post("/sis/ui/data/students/", { search: query, ...(after ? { after } : {}) });
            if (dialog !== target || token !== choiceRequest) return;
            choices.replaceChildren();
            for (const student of data.items)
                choices.append(
                    button(`${student.given_name} ${student.family_name} · ${student.institutional_id}`, () =>
                        pursuits(student),
                    ),
                );
            if (!data.items.length) choices.append(node("p", {}, "No matching students."));
            pager(choices, data, after, stack, (a, s) => findStudents(query, a, s));
        }
        async function pursuits(student, after = null, stack = []) {
            const target = dialog,
                token = ++choiceRequest;
            const data = await post(`/sis/students/${student.id}/programs/`, after ? { after } : {});
            if (dialog !== target || token !== choiceRequest) return;
            choices.replaceChildren(
                node("h3", { class: "h6" }, `Choose a pursuit for ${student.given_name} ${student.family_name}`),
            );
            const matches = data.items;
            for (const pursuit of matches)
                choices.append(
                    button(`${pursuit.program_title} · ${pursuit.term_code} · ${pursuit.status}`, () => {
                        model.student = student;
                        model.pursuit = pursuit;
                        selected();
                        selection.querySelector("button")?.focus();
                    }),
                );
            if (!matches.length)
                choices.append(node("p", {}, "No pursuits on this page. Choose another student or check the next page."));
            pager(choices, data, after, stack, (a, s) => pursuits(student, a, s));
        }
        async function run() {
            const target = dialog;
            const student = model.student,
                pursuit = model.pursuit,
                drafts = model.drafts,
                source = model.source,
                version = model.publication?.version_id;
            const data = await post(`/sis/degree-audits/${saved.id}/test/`, {
                source,
                ...(source === "publication"
                    ? { version_id: version }
                    : { definition: snapshot, use_group_drafts: drafts }),
                student_id: student.id,
                assignment_id: pursuit.id,
            });
            if (
                dialog !== target ||
                model.student !== student ||
                model.pursuit !== pursuit ||
                model.drafts !== drafts ||
                model.source !== source ||
                model.publication?.version_id !== version
            )
                return;
            renderDegreeProgress(null, restore, data, null, {
                setup: restore,
                student: `${student.given_name} ${student.family_name} · ${student.institutional_id}`,
            });
        }
        selected();
    }
    function normalDegree(model) {
        const d = structuredClone(model);
        d.name = d.name.trim();
        d.length_months = d.length_months === "" ? null : Number(d.length_months);
        for (const key of ["min_credits_milli", "min_resident_milli"]) d[key] = milli(d[key]);
        for (const key of ["min_cumulative_gpa_milli", "min_overall_gpa_milli"]) d[key] = milli(d[key], true);
        return d;
    }
    function degreeEditor(saved, state = null) {
        if (Object.hasOwn(saved.definition, "courses")) return legacy(saved);
        const model = state?.model || structuredClone(saved.definition),
            details = state?.details || structuredClone(saved.group_details || {});
        if (!state) {
            for (const k of [
                "min_credits_milli",
                "min_resident_milli",
                "min_cumulative_gpa_milli",
                "min_overall_gpa_milli",
            ])
                model[k] = text(model[k]);
            model.length_months = model.length_months == null ? "" : String(model.length_months);
        }
        const restore = () => degreeEditor(saved, { model, details }),
            body = screen("Degree audit builder", `${saved.context.academic_year_code} · ${saved.context.program_title}`),
            status = node("p", { role: "status", class: "audit-status" });
        restore.returnLabel = "Return to degree draft";
        body.append(
            actions(
                button("Back to requirement drafts", () => launch(saved.program_id)),
                button("Course group library", () => library("", null, [], restore)),
                button("Requirements preview", () =>
                    preview(
                        {
                            definition: normalDegree(model),
                            group_details: details,
                            grade_scale: saved.grade_scale,
                        },
                        "degree",
                        restore,
                    ),
                ),
                ui.can("sis:student:read")
                    ? button("Test with a student", () => auditTrial(saved, normalDegree(model), restore))
                    : null,
                button("Program grading scale", () =>
                    gradingScale(saved.program_id, async () => {
                        saved.grade_scale = (await request(`/sis/programs/${saved.program_id}/grading-scale/`)).scale;
                        restore();
                    }),
                ),
                readinessButton(
                    "degree",
                    body,
                    () => saved,
                    () => normalDegree(model),
                ),
                button("Save degree draft", async () => {
                    saved = await save();
                    status.textContent = "Degree draft saved.";
                }),
                button(
                    "Save & review publication",
                    async () => {
                        saved = await save();
                        reasonScreen("degree", saved, restore, () => launch(saved.program_id));
                    },
                    "btn-primary",
                ),
            ),
            status,
        );
        const [source, canvas, inspector] = workbench(body, [
            [
                "1 · Choose library blocks",
                "Reuse a published group or attach a working draft for testing. Publish the groups before publishing the degree.",
            ],
            [
                "2 · Compose the degree",
                "Arrange published versions and working drafts into a requirement tree. Testing does not publish them.",
            ],
            [
                "3 · Degree settings",
                "These minimums and institutional policies belong to this degree and requirement year.",
            ],
        ]);
        const cards = node("div", { class: "audit-canvas" });
        canvas.append(cards);
        inspector.append(
            field("Requirement title", model.name, (v) => (model.name = v), {
                maxlength: 191,
            }),
            field("Total earned credits required", model.min_credits_milli, (v) => (model.min_credits_milli = v), {
                inputmode: "decimal",
            }),
            field(
                "Institutional resident credits required",
                model.min_resident_milli,
                (v) => (model.min_resident_milli = v),
                { inputmode: "decimal" },
            ),
        );
        const more = node("details", { class: "audit-tree" }, node("summary", {}, "GPA & program length"));
        more.append(
            field(
                "Minimum cumulative GPA (optional)",
                model.min_cumulative_gpa_milli,
                (v) => (model.min_cumulative_gpa_milli = v),
            ),
            field("Minimum overall GPA (optional)", model.min_overall_gpa_milli, (v) => (model.min_overall_gpa_milli = v)),
            field("Expected length in months (optional)", model.length_months, (v) => (model.length_months = v), {
                type: "number",
                min: 1,
                max: 240,
            }),
        );
        inspector.append(more);
        const policies = node(
            "details",
            { class: "audit-tree", open: true },
            node("summary", {}, "Institutional audit policies"),
        );
        let orderingPolicy = null;
        for (const [key, choices] of Object.entries(policyChoices)) {
            const group = node("fieldset", { class: "audit-policy" }, node("legend", { class: "h6" }, policyLabels[key]));
            if (key === "repeat_attempt_order") {
                orderingPolicy = group;
                group.hidden = model.policies.repeat_gpa !== "latest_eligible";
                group.append(
                    node(
                        "p",
                        { class: "form-text" },
                        "Academic-period ordering uses the retained instructional start date, so posting or correcting an older grade later does not make it the latest period. Completion-date ordering needs actual retained completion dates; missing dates stay unknown.",
                    ),
                );
            }
            for (const [value, label] of choices) {
                const input = node("input", {
                    type: "radio",
                    name: `policy-${key}`,
                    value,
                    checked: model.policies[key] === value,
                });
                input.addEventListener("change", () => {
                    model.policies[key] = value;
                    if (key === "repeat_gpa" && orderingPolicy) {
                        orderingPolicy.hidden = value !== "latest_eligible";
                    }
                });
                group.append(node("label", {}, input, " ", label));
            }
            policies.append(group);
        }
        policies.append(
            node(
                "p",
                { class: "form-text" },
                "Group sharing never authorizes counting an award twice in overall credit or GPA totals. Repeat policies remain separate. A waiver creates no credit or GPA; minimum reductions require separate approvals.",
            ),
        );
        inspector.append(policies);
        async function save() {
            return post(`/sis/degree-audits/${saved.id}/save/`, {
                revision: saved.revision,
                definition: normalDegree(model),
            });
        }
        async function attach(picks) {
            // Resolve all versions before mutating the draft, so a failed request cannot partially attach a selection.
            const resolved = await Promise.all(
                picks.map((p) =>
                    request(
                        p.version_id
                            ? `/sis/course-groups/${p.group_id}/versions/${p.version_id}/`
                            : `/sis/course-groups/${p.group_id}/`,
                    ),
                ),
            );
            picks.forEach((p, i) => {
                const existing = model.groups.find((g) => g.group_id === p.group_id);
                if (existing) existing.version_id = p.version_id;
                else model.groups.push({ group_id: p.group_id, version_id: p.version_id });
                details[p.group_id] = resolved[i];
            });
            render();
            status.textContent = "Selected library blocks attached to this degree draft. Other degrees are unchanged.";
        }
        function render() {
            const focusedLabel = cards.contains(document.activeElement) ? document.activeElement.textContent : null;
            cards.replaceChildren();
            if (!model.groups.length)
                cards.append(
                    node(
                        "p",
                        { class: "audit-empty" },
                        "Your degree starts here. Add published blocks from the library, then arrange them in the order students should see them.",
                    ),
                );
            model.groups.forEach((g, i) => {
                const item = details[g.group_id],
                    card = node("article", { class: "audit-rule-card" });
                card.append(
                    badge(
                        g.version_id
                            ? `Pinned version ${item?.version || ""}`
                            : "Working group draft · test before publishing",
                    ),
                    item ? groupTree(item) : node("p", {}, "Course group"),
                    actions(
                        button(`Move block ${i + 1} up`, () => {
                            move(model.groups, i, -1);
                            render();
                        }),
                        button(`Move block ${i + 1} down`, () => {
                            move(model.groups, i, 1);
                            render();
                        }),
                        button(
                            `Remove block ${i + 1}`,
                            () => {
                                model.groups.splice(i, 1);
                                render();
                            },
                            "btn-outline-secondary",
                        ),
                        button(`Edit block ${i + 1} in library`, async () =>
                            groupEditor(await request(`/sis/course-groups/${g.group_id}/`), restore),
                        ),
                    ),
                );
                card.append(
                    node(
                        "p",
                        { class: "form-text" },
                        "Replace a block explicitly from the library panel. Working drafts can be tested; choose a published version before publishing this degree.",
                    ),
                );
                draggable(card, { kind: "block", id: g.group_id });
                dropzone(card, async (data) => {
                    if (data.kind === "group") await attach([data]);
                    else if (data.kind === "block") {
                        const j = model.groups.findIndex((x) => x.group_id === data.id);
                        if (j >= 0) {
                            const [moving] = model.groups.splice(j, 1);
                            model.groups.splice(i, 0, moving);
                            render();
                        }
                    }
                });
                cards.append(card);
            });
            if (focusedLabel) {
                const target =
                    [...cards.querySelectorAll("button")].find((b) => b.textContent === focusedLabel) ||
                    cards.querySelector("button") ||
                    cards;
                target.tabIndex = target === cards ? -1 : target.tabIndex;
                target.focus({ preventScroll: true });
            }
        }
        dropzone(canvas, async (data) => {
            if (data.kind === "group" && data.group_id && data.version_id) await attach([data]);
        });
        async function groupSource(query = "", after = null, stack = []) {
            const data = await post("/sis/course-groups/page/", {
                published_only: false,
                ...(query ? { search: query } : {}),
                ...(after ? { after } : {}),
            });
            source.replaceChildren(
                node("h2", { class: "h5" }, "1 · Choose library blocks"),
                node(
                    "p",
                    { class: "form-text" },
                    "Choose a published version or a working draft for testing. Publication still requires a published version for every group.",
                ),
                search("Search library groups", query, (q) => groupSource(q)),
            );
            for (const g of data.items) {
                const card = node(
                        "details",
                        { class: "audit-source-card" },
                        node("summary", {}, g.published_name || g.name),
                    ),
                    versions = node("div", { class: "audit-version-list" });
                let loaded = false;
                card.append(versions);
                card.addEventListener("toggle", () => {
                    if (card.open && !loaded) {
                        loaded = true;
                        task(async () => {
                            try {
                                await groupVersions(g, versions);
                            } catch (error) {
                                loaded = false;
                                fail(error);
                            }
                        }, card);
                    }
                });
                source.append(card);
            }
            if (!data.items.length)
                source.append(
                    node(
                        "p",
                        {},
                        "No matching groups. Build a working group in the library; it can be tested before publication.",
                    ),
                    button("Build library groups", () => library("", null, [], restore)),
                );
            pager(source, data, after, stack, (a, s) => groupSource(query, a, s));
        }
        async function groupVersions(g, target, cursor = null, stack = []) {
            const data = await post(`/sis/course-groups/${g.id}/versions/`, cursor ? { cursor } : {}),
                selected = new Map();
            target.replaceChildren(
                button("Use working draft for testing", () => attach([{ group_id: g.id, version_id: null }])),
            );
            for (const v of data.items) {
                const pick = {
                        kind: "group",
                        group_id: g.id,
                        version_id: v.version_id,
                    },
                    card = node(
                        "div",
                        { class: "audit-version-card" },
                        checkbox(`Version ${v.version} · ${v.definition.name} · ${count(v.definition)}`, (on) =>
                            on ? selected.set(v.version_id, pick) : selected.delete(v.version_id),
                        ),
                    );
                draggable(card, pick);
                target.append(card);
            }
            target.append(
                button("Add checked blocks", async () => {
                    if (!selected.size) throw new Error("Check a published group version first.");
                    if (selected.size > 1) throw new Error("Choose one version of this group for the degree.");
                    await attach([...selected.values()]);
                    selected.clear();
                    target.querySelectorAll('input[type="checkbox"]').forEach((el) => (el.checked = false));
                }),
            );
            pager(target, data, cursor, stack, (a, s) => groupVersions(g, target, a, s), "next_cursor");
        }
        render();
        groupSource().catch(fail);
    }
    function legacy(saved) {
        const body = screen("Move existing draft groups into the library");
        body.append(
            node(
                "p",
                {},
                "This working draft contains embedded groups from the earlier builder. Move them into independent library drafts before composing new degree requirements. Published history remains unchanged; this step publishes nothing.",
            ),
            degreeTree(saved),
            actions(
                button(
                    "Move draft groups into the library",
                    async () =>
                        degreeEditor(
                            await post(`/sis/degree-audits/${saved.id}/reuse-groups/`, {
                                revision: saved.revision,
                            }),
                        ),
                    "btn-primary",
                ),
                button("Back to requirement drafts", () => launch(saved.program_id)),
            ),
        );
    }
    function preview(item, kind, back) {
        const body = screen("Requirements preview");
        body.append(kind === "group" ? groupTree(item) : degreeTree(item), button("Back to builder", back));
    }
    function reasonScreen(kind, saved, back, directory) {
        const body = screen(
            kind === "group" ? "Review course group publication" : "Review degree publication",
        );
        let reason = "";
        body.append(
            node(
                "p",
                {},
                "Review saves no publication until you confirm the exact definition. Published versions are retained; later changes create new versions.",
            ),
            field("Reason for publication", "", (v) => (reason = v), {
                maxlength: 1000,
            }),
            actions(
                button(
                    "Prepare publication",
                    async function prepare() {
                        const path =
                                kind === "group"
                                    ? `/sis/course-groups/${saved.id}/`
                                    : `/sis/degree-audits/${saved.id}/`,
                            reviewed = await post(`${path}review/`, { reason });
                        const content = screen("Confirm reviewed publication");
                        content.append(
                            kind === "group" ? groupTree(reviewed) : degreeTree(reviewed),
                            ...(kind === "group"
                                ? []
                                : [
                                      node(
                                          "p",
                                          { class: "alert alert-info", role: "status" },
                                          reviewed.program_publication
                                              ? `Linked program: ${reviewed.program_publication.definition.code} — ${reviewed.program_publication.definition.title}. These requirements retain this exact published program revision.`
                                              : "No program definition is published for this academic year. This requirement version will have no inferred program-publication link.",
                                      ),
                                  ]),
                            node(
                                "p",
                                { class: "form-text" },
                                "Confirm within 20 minutes, or refresh this review. Publication takes effect when you confirm it.",
                            ),
                            node("p", {}, `Reason: ${reviewed.reason}`),
                            actions(
                                button(
                                    "Publish reviewed version",
                                    async () => {
                                        await post(`${path}publish/`, {
                                            review_token: reviewed.review_token,
                                        });
                                        await history(kind, saved.id, saved.program_id, directory);
                                    },
                                    "btn-primary",
                                ),
                                button("Refresh publication review", prepare),
                                button("Back to builder", back),
                            ),
                        );
                    },
                    "btn-primary",
                ),
                button("Back to builder", back),
            ),
        );
    }
    async function history(kind, id, program, back, cursor = null, stack = []) {
        const base = kind === "group" ? `/sis/course-groups/${id}/` : `/sis/degree-audits/${id}/`,
            result = await post(`${base}versions/`, cursor ? { cursor } : {}),
            body = screen("Published requirement versions");
        body.append(
            node(
                "p",
                {},
                "Retained versions preserve the original requirements. Editing a working draft never rewrites these publications.",
            ),
        );
        for (const row of result.items)
            body.append(
                node(
                    "article",
                    { class: "audit-library-card" },
                    node("h2", { class: "h5" }, `Version ${row.version} · ${row.definition.name}`),
                    actions(
                        button(`Inspect version ${row.version}`, async () =>
                            preview(await request(`${base}versions/${row.version_id}/`), kind, () =>
                                history(kind, id, program, back, cursor, stack),
                            ),
                        ),
                        kind === "degree" && ui.can("sis:student:read")
                            ? button(`Test version ${row.version} with a student`, () =>
                                  auditTrial(
                                      { id, program_id: program, context: row.context },
                                      null,
                                      () => history(kind, id, program, back, cursor, stack),
                                      {
                                          student: null,
                                          pursuit: null,
                                          drafts: false,
                                          source: "publication",
                                          publication: row,
                                      },
                                  ),
                              )
                            : null,
                        kind === "degree"
                            ? button(`Clone version ${row.version} to another academic year`, () =>
                                  years(program, { id, version: row.version_id }),
                              )
                            : null,
                    ),
                ),
            );
        if (!result.items.length) body.append(node("p", {}, "No published versions yet."));
        pager(body, result, cursor, stack, (a, s) => history(kind, id, program, back, a, s), "next_cursor");
        body.append(button("Back", back));
    }
    async function studentRequirements(studentId, assignmentId, back) {
        const base = `/sis/students/${studentId}/programs/${assignmentId}/degree-requirements/`;
        const data = await request(base),
            body = screen("Student degree requirements", "RETAINED PROGRAM REQUIREMENTS");
        const restore = () => studentRequirements(studentId, assignmentId, back);
        body.append(
            node(
                "p",
                {},
                "This is the published baseline selected for this program pursuit. Preview with approvals to see the combined requirements, or open Student exceptions to review approvals. This view does not calculate completion or degree eligibility.",
            ),
        );
        if (data.cohort_changed)
            body.append(
                node(
                    "p",
                    { class: "alert alert-info", role: "status" },
                    "The matriculation cohort changed after this selection. The retained requirements remain in place until an authorized registrar reviews a new assignment.",
                ),
            );
        if (data.selection) {
            body.append(
                node(
                    "p",
                    {},
                    `${data.requirements.context.program_title} · ${data.requirements.context.academic_year_code} · Version ${data.requirements.version}`,
                ),
                node(
                    "p",
                    {},
                    `Pursuit began ${data.selection.pursuit_started_on}. Anticipated completion: ${data.anticipated_completion_on || "Not specified; program length is unknown"}.`,
                ),
                degreeTree(data.requirements),
            );
        } else
            body.append(
                node(
                    "div",
                    { class: "audit-empty" },
                    "No published requirements have been assigned to this program. Publishing a degree does not automatically assign requirements to existing students.",
                ),
            );
        body.append(
            actions(
                button("Degree progress", () => degreeProgress(base, restore), "btn-primary"),
                button("Academic work", () => academicWork(base, restore)),
                button("Credits & GPA", () => academicCalculations(base, restore)),
                button("Review course placement", () => groupAllocation(base, restore)),
                button("Assignment history", () => studentHistory(base, restore)),
                data.selection
                    ? button("Preview with approvals", () =>
                          adjustedRequirements(
                              base.replace("degree-requirements/", "degree-adjustments/"),
                              restore,
                              true,
                              "Back to degree requirements",
                          ),
                      )
                    : null,
                data.selection
                    ? button("Student exceptions", () => studentExceptions(studentId, assignmentId, restore))
                    : null,
                ui.can?.("sis:degree-audit:assign") &&
                    ui.can?.("sis:student:read") &&
                    data.scope.status === "active"
                    ? button(
                          "Assign published requirements",
                          () => studentChoices(base, data, restore),
                          "btn-primary",
                      )
                    : null,
                button("Back to student", async () => {
                    close();
                    await back();
                }),
            ),
        );
    }
    const degreeMinimumLabels = {
        min_credits_milli: "Total earned credits",
        min_resident_milli: "Resident credits (institutional origin)",
        min_cumulative_gpa_milli: "Cumulative GPA",
        min_overall_gpa_milli: "Overall GPA",
    };
    const degreeMinimumStatuses = {
        satisfied: "Minimum met",
        below_minimum: "Below minimum",
        unknown: "Needs review",
        not_required: "No minimum required",
    };
    async function degreeProgress(base, back) {
        const data = await post(`${base}progress/`, {});
        renderDegreeProgress(base, back, data);
    }
    const degreeProgressGuide = (...args) =>
        window.createDegreeProgressGuide({ node, button, actions }, ...args);
    const progressNextSteps = (data) => window.createDegreeProgressNextSteps(node, data);
    function ruleAllocationNotices(issues) {
        const messages = {
            rule_facts_unknown:
                "Some potentially usable work lacks the retained course facts needed by a saved rule. No complete placement is shown.",
            rule_publication_conflict:
                "A named requirement and a saved rule use incompatible course publications for the same course. Review the retained definitions.",
        };
        return node(
            "div",
            {},
            (issues || [])
                .filter((code) => messages[code])
                .map((code) => node("p", { class: "alert alert-info", role: "status" }, messages[code])),
        );
    }
    function renderDegreeProgress(base, back, data, guideState = null, trial = null) {
        const shell = screen(
            trial ? "Temporary degree audit result" : "Degree progress review",
            trial ? "RUNTIME AUDIT TEST · NOT SAVED" : "GENERAL MINIMUMS & COURSE GROUPS",
        );
        const state = guideState || { open: false, side: "right", topic: "steps" };
        const layout = node("div", { class: "progress-guide-layout" });
        const body = node("div", { class: "progress-guide-review" });
        layout.append(body);
        shell.append(layout);
        const restore = () => renderDegreeProgress(base, back, data, state, trial);
        restore.returnLabel = "Back to degree progress";
        const steps = progressNextSteps(data);
        const slot = node("div", { class: "progress-guide-inline" }, steps);
        const guide = trial
            ? null
            : degreeProgressGuide(layout, steps, slot, data, state, {
                  minimums: () => body.querySelector("[data-progress-minimums]")?.focus(),
                  contributions: () => degreeProgressContributions(data, restore),
                  groups: () =>
                      renderGroupAllocation(base, restore, {
                          status: "review",
                          input_status: data.input_status,
                          current_record_count: data.current_record_count,
                          context: data.context,
                          ...data.allocation,
                      }),
              });
        body.append(
            node(
                "p",
                {},
                trial
                    ? "Temporary test of the selected audit requirements against verified student work. No result is saved. No definition is published and no student assignment changes. Student-specific exceptions approved for published requirements are not applied. This does not establish graduation eligibility."
                    : "Review current work against the selected degree's general minimums and course groups, including approved exceptions. All checks use one verified read. This is not a completed degree audit or graduation decision, and it saves no academic changes.",
            ),
        );
        body.append(
            actions(
                guide,
                trial
                    ? button("Back to test setup", trial.setup)
                    : button("Refresh degree progress", () => degreeProgress(base, back)),
                trial ? null : button("Back to degree requirements", back),
            ),
        );
        if (!trial) body.append(slot);
        if (trial) body.append(node("p", { class: "audit-status" }, trial.student));
        if (trial && data.context?.program_title)
            body.append(
                node(
                    "p",
                    {},
                    `Tested program: ${data.context.program_code} — ${data.context.program_title}. This does not change the student's program.`,
                ),
            );
        if (trial && data.sources) {
            body.append(node("h2", { class: "h5" }, "Tested group sources"));
            for (const source of data.sources)
                body.append(
                    node(
                        "p",
                        {},
                        `${source.name} · ${source.source === "draft" ? "Working draft revision" : "Published version"} ${source.revision}`,
                    ),
                );
        }
        if (data.status !== "review") {
            body.append(
                node(
                    "p",
                    { class: "alert alert-info", role: "status" },
                    data.status === "needs_selection"
                        ? "Select published degree requirements for this pursuit before reviewing degree progress."
                        : data.status === "inconsistent"
                          ? "The academic inputs could not be verified. No partial minimum checks, records or placements are shown. Ask the registrar to review the records."
                          : "The review reached its work limit. No partial minimum checks, records or placements are shown. Ask the registrar to arrange a controlled review.",
                ),
            );
            return;
        }
        body.append(
            node(
                "p",
                { class: "audit-status" },
                `${data.context.name} · ${data.context.academic_year_code} · ${data.context.mode === "runtime_test" ? (data.context.source === "publication" ? `Published audit version ${data.context.version} · Temporary test` : "Unpublished trial") : `Version ${data.context.version}`}`,
            ),
        );
        body.append(
            node(
                "p",
                {},
                `${data.current_record_count} current academic records checked against available sources. This does not certify that the historical archive is complete.`,
            ),
        );
        if (data.input_status === "history_gaps")
            body.append(
                node(
                    "p",
                    { class: "alert alert-info", role: "status" },
                    "Earlier history is unknown for some records. These checks use known current facts and do not reconstruct earlier totals.",
                ),
            );
        if (data.context.cohort_changed)
            body.append(
                node(
                    "p",
                    { class: "alert alert-info", role: "status" },
                    "The matriculation cohort changed. The retained degree selection still applies; ask the registrar to review it.",
                ),
            );
        body.append(node("h2", { tabindex: "-1", "data-progress-minimums": "" }, "General degree minimums"));
        const cards = node("div", { class: "audit-library-grid" });
        for (const check of data.minimums) {
            const gpa =
                check.key === "min_cumulative_gpa_milli"
                    ? data.gpa.cumulative
                    : check.key === "min_overall_gpa_milli"
                      ? data.gpa.overall
                      : null;
            const actual =
                check.actual_minimum_milli === null
                    ? "Unavailable"
                    : check.actual_minimum_milli === check.actual_maximum_milli
                      ? text(check.actual_minimum_milli)
                      : `${text(check.actual_minimum_milli)}–${text(check.actual_maximum_milli)}`;
            const card = node(
                "article",
                { class: "audit-card" },
                node("h3", {}, degreeMinimumLabels[check.key]),
                node(
                    "p",
                    { class: "audit-status" },
                    gpa?.ready && gpa.credits_milli === 0 && check.status === "unknown"
                        ? "GPA not yet established"
                        : degreeMinimumStatuses[check.status],
                ),
                node("p", { class: "h3" }, actual),
                node(
                    "p",
                    {},
                    check.required_milli == null
                        ? trial
                            ? "No draft minimum"
                            : "No published minimum"
                        : `Required: ${text(check.required_milli)}`,
                ),
            );
            if (check.adjusted)
                card.append(
                    node("p", {}, `Approved minimum reduction · Published minimum: ${text(check.published_milli)}`),
                );
            if (check.key === "min_resident_milli")
                card.append(
                    node(
                        "p",
                        {},
                        "Uses institutional-origin credit bounds. A tied institutional/transfer award is not silently chosen to satisfy this minimum; richer residency exclusions still need evaluation.",
                    ),
                );
            if (gpa) {
                card.append(
                    node(
                        "p",
                        {},
                        check.key === "min_cumulative_gpa_milli"
                            ? "Institutional work only; transfer attempts are excluded before repeat selection."
                            : "Uses the retained transfer-GPA policy and each grade's recorded GPA treatment.",
                    ),
                );
                card.append(
                    node(
                        "p",
                        {},
                        !gpa.ready
                            ? "Required GPA inputs are missing or conflicting. No partial GPA is shown."
                            : gpa.credits_milli === 0
                              ? "No eligible GPA credits are recorded. This is not a zero GPA."
                              : `${text(gpa.credits_milli)} GPA credits · ${text(gpa.quality_points_milli)} quality points. GPA is not rounded upward at minimum boundaries.`,
                    ),
                );
            }
            cards.append(card);
        }
        body.append(cards);
        body.append(
            node(
                "section",
                { class: "audit-preview" },
                node("h2", {}, "Unused and pending work"),
                node(
                    "p",
                    {},
                    `${data.work_summary.awaiting_grade_count} institutional records awaiting final grades · ${data.work_summary.awaiting_grade_milli === null ? "Credit total unavailable within the supported numeric limit" : `${text(data.work_summary.awaiting_grade_milli)} attempted credits`}.`,
                ),
                node(
                    "p",
                    {},
                    "Pending credits cover all posting terms in this review. They are not earned credits, completed requirements or a current-term enrollment-load calculation.",
                ),
                node(
                    "p",
                    {},
                    data.work_summary.placement_established
                        ? `${data.work_summary.unused_completed_count} completed records are unused in this proposed group placement. Their earned-credit and GPA treatment remains separate; another valid plan may use different courses.`
                        : "Unused courses cannot be identified until a complete group placement is established. No unplaced course is treated as unnecessary.",
                ),
                actions(button("Review unused and pending work", () => degreeProgressContributions(data, restore))),
            ),
        );
        for (const issue of data.issues)
            body.append(
                node(
                    "p",
                    { class: "alert alert-info" },
                    `${issue.component === "credits" ? "Earned credits" : issue.component === "institutional" ? "Cumulative GPA" : "Overall GPA"}: ${calculationIssues[issue.code] || "A retained input needs review."}`,
                ),
            );
        if (data.rule_eligibility?.length)
            body.append(
                node(
                    "section",
                    { class: "audit-preview" },
                    node("h2", {}, "Saved-rule course eligibility"),
                    node(
                        "p",
                        {},
                        "These checks compare retained course facts with the selected rule revision. Grades, repeat-credit policy and sharing are applied separately in the course-group placement review below.",
                    ),
                    button("Inspect saved-rule eligibility", () => ruleEligibility(data, restore)),
                ),
            );
        body.append(ruleAllocationNotices(data.allocation.issues));
        const outcomes = {
            feasible: "A complete course-group placement was found",
            infeasible: "No complete course-group placement found",
            undetermined: "The course-group search reached its limit",
            needs_review: "Course-group inputs need review",
            unsupported: "Some retained course-group rules are not yet supported",
        };
        body.append(
            node(
                "section",
                { class: "audit-preview" },
                node("h2", {}, "Course groups"),
                node("p", { role: "status" }, outcomes[data.allocation.outcome] || "Course-group placement needs review"),
                node(
                    "p",
                    {},
                    "General minimums and course-group placement are separate checks. Missing equivalence, unsupported rules, specialization exclusions and retained final evaluations still need completion.",
                ),
                actions(
                    button("Inspect course-group placement", () =>
                        renderGroupAllocation(base, restore, {
                            status: "review",
                            input_status: data.input_status,
                            current_record_count: data.current_record_count,
                            context: data.context,
                            ...data.allocation,
                        }),
                    ),
                ),
            ),
        );
        const competition = groupCompetition({ ...data.allocation, context: data.context });
        if (competition) body.append(competition);
        const policies = node(
            "details",
            { class: "audit-tree-section" },
            node("summary", {}, "Retained calculation policies"),
        );
        for (const key of ["repeat_credit", "repeat_gpa", "transfer_overall_gpa"])
            policies.append(
                node(
                    "p",
                    {},
                    `${policyLabels[key]}: ${policyChoices[key].find(([value]) => value === data.policy[key])?.[1] || "Not recorded"}`,
                ),
            );
        policies.append(
            node(
                "p",
                {},
                "Repeat ordering: " +
                    ({
                        academic_period: "Academic term / instructional period",
                        completion_date: "Actual completion date",
                    }[data.policy.attempt_order] || "Not recorded"),
            ),
        );
        body.append(
            policies,
            actions(button("Inspect credit and GPA contributions", () => degreeProgressContributions(data, restore))),
        );
    }
    const degreeWorkStates = {
        awaiting_grade: "Awaiting a final grade",
        completed: "Completed passing work",
        grade_unknown: "Retained grade evidence needs review",
        not_passing: "Non-passing grade",
        late_drop: "Late drop",
        voided: "Voided transfer award",
        no_earned_credit: "No completed earned award",
    };
    const degreeWorkPlacements = {
        proposed: "Proposed course-group placement",
        alternative: "Equivalent evidence alternative; no single award chosen",
        not_used: "Unused in this proposed course-group placement",
        unplaced: "No proposed course-group placement",
        not_established: "Course-group placement not established",
    };
    function ruleEligibility(data, back, offset = 0) {
        const body = screen("Saved-rule eligibility", "COURSE CONDITIONS · NOT COMPLETION");
        const values = data.rule_eligibility;
        body.append(
            node(
                "p",
                {},
                "Only course conditions are checked here. Grades, repeated awards, substitutions, waivers and final placement remain separate. These results are temporary.",
            ),
        );
        for (const row of values.slice(offset, offset + 20))
            body.append(
                node(
                    "article",
                    { class: "audit-card" },
                    node("h2", { class: "h5" }, `${row.course_code} — ${row.course_title}`),
                    node("p", {}, `${row.group_name} · ${row.rule_code} · Revision ${row.rule_revision}`),
                    node(
                        "p",
                        { class: "audit-status" },
                        row.outcome === "matches"
                            ? "Matches course rule"
                            : row.outcome === "does_not_match"
                              ? "Does not match course rule"
                              : "Unknown: required retained course facts are missing",
                    ),
                ),
            );
        body.append(
            node(
                "p",
                { role: "status" },
                `Showing ${offset + 1}–${Math.min(offset + 20, values.length)} of ${values.length} eligibility checks`,
            ),
        );
        if (offset) body.append(button("Previous eligibility checks", () => ruleEligibility(data, back, offset - 20)));
        if (offset + 20 < values.length)
            body.append(button("Next eligibility checks", () => ruleEligibility(data, back, offset + 20)));
        body.append(button("Back to degree progress", back));
    }
    function degreeProgressContributions(data, back, offset = 0, filter = "all", term = "all") {
        const body = screen("Degree progress contributions", "SAME VERIFIED CURRENT WORK");
        body.append(
            node(
                "p",
                {},
                "Earned credit, cumulative GPA and overall GPA have separate retained rules. A course-group placement does not create another contribution to these totals.",
            ),
            actions(button("Back to degree progress", back)),
        );
        const termRecords = data.records.filter((r) => term === "all" || r.term_id === term);
        const filters = {
            all: ["All work", () => true],
            unused: [
                "Unused completed",
                (r) => r.academic_state === "completed" && r.placement === "not_used",
            ],
            pending: ["Awaiting grades", (r) => r.academic_state === "awaiting_grade"],
            placed: ["Placed or alternatives", (r) => ["proposed", "alternative"].includes(r.placement)],
            other: ["Other outcomes", (r) => !["completed", "awaiting_grade"].includes(r.academic_state)],
        };
        const controls = actions();
        controls.setAttribute("role", "group");
        controls.setAttribute("aria-label", "Filter academic work");
        for (const [key, [label, test]] of Object.entries(filters)) {
            const count = termRecords.filter(test).length;
            const control = button(
                `${label} (${count})`,
                () => {
                    degreeProgressContributions(data, back, 0, key, term);
                    dialog.querySelector(`[data-work-filter="${key}"]`)?.focus();
                },
                key === filter ? "btn-primary" : "btn-outline-primary",
            );
            control.setAttribute("data-work-filter", key);
            control.setAttribute("aria-pressed", String(key === filter));
            if (["unused", "placed"].includes(key) && !data.work_summary.placement_established)
                control.disabled = true;
            controls.append(control);
        }
        body.append(controls);
        const terms = new Map();
        for (const record of data.records) {
            if (!terms.has(record.term_id)) terms.set(record.term_id, new Set());
            terms.get(record.term_id).add(record.term_code || "Label not retained");
        }
        if (terms.size > 1) {
            const select = node(
                "select",
                { id: "degree-work-term", class: "form-select" },
                node("option", { value: "all" }, "All posting terms"),
            );
            for (const [id, labels] of terms)
                select.append(node("option", { value: id }, [...labels].sort().join(" / ")));
            select.value = term;
            select.addEventListener("change", () => {
                degreeProgressContributions(data, back, 0, filter, select.value);
                dialog.querySelector("#degree-work-term")?.focus();
            });
            body.append(
                node(
                    "div",
                    { class: "mb-3" },
                    node("label", { for: "degree-work-term", class: "form-label" }, "Posting term"),
                    select,
                ),
            );
        }
        if (!data.work_summary.placement_established)
            body.append(
                node(
                    "p",
                    { class: "alert alert-info" },
                    "No complete placement was established. Unused and placed filters are unavailable; pending grades and recorded outcomes can still be inspected.",
                ),
            );
        body.append(
            node(
                "p",
                {},
                "Unused means not used in this proposed group plan. Repeat and grading rules still govern totals. Equal-credit placement alternatives remain alternatives, and no future credit or GPA is projected.",
            ),
        );
        const records = termRecords.filter(filters[filter][1]);
        const names = new Map(data.allocation.groups.map((g) => [g.id, g.name]));
        const cards = node("div", { class: "audit-library-grid" });
        for (const record of records.slice(offset, offset + 20)) {
            cards.append(
                node(
                    "article",
                    { class: "audit-card" },
                    node("h2", {}, `${record.course_code} · ${record.course_title}`),
                    node("p", {}, degreeWorkStates[record.academic_state]),
                    node("p", {}, window.degreeProgressGradeText(record, text)),
                    ...(record.recorded_earned_milli == null ? [] : [node("p", {}, `${text(record.recorded_earned_milli)} recorded earned credits before overall repeat-policy treatment.`)]),
                    node(
                        "p",
                        {},
                        `${text(record.attempted_milli)} recorded attempted credits · ${record.term_label_retained ? "Retained posting term" : "Posting term label not retained"}${record.term_code ? `: ${record.term_code}` : ""}.`,
                    ),
                    node("p", {}, degreeWorkPlacements[record.placement]),
                    ...record.uses.map((use) =>
                        node(
                            "p",
                            {},
                            `${use.alternative ? "Eligible evidence alternative for" : "Proposed placement in"} ${names.get(use.group_id)}.`,
                        ),
                    ),
                    node("p", {}, `Earned credit: ${calculationReasons[record.credit] || "Needs review"}.`),
                    node(
                        "p",
                        {},
                        `Cumulative GPA: ${record.cumulative_gpa === "transfer_excluded" ? "Transfer attempts are outside cumulative GPA" : calculationReasons[record.cumulative_gpa] || "Needs review"}.`,
                    ),
                    node(
                        "p",
                        {},
                        `Overall GPA: ${calculationReasons[record.overall_gpa] || "Needs review"}.`,
                    ),
                ),
            );
        }
        body.append(
            cards,
            node(
                "p",
                { role: "status" },
                records.length
                    ? `Showing ${offset + 1}–${Math.min(offset + 20, records.length)} of ${records.length} records.`
                    : "No records match this view. This does not establish degree completion.",
            ),
        );
        const pages = actions();
        if (offset > 0)
            pages.append(
                button("Previous contribution records", () =>
                    degreeProgressContributions(data, back, offset - 20, filter, term),
                ),
            );
        if (offset + 20 < records.length)
            pages.append(
                button("Next contribution records", () =>
                    degreeProgressContributions(data, back, offset + 20, filter, term),
                ),
            );
        body.append(pages);
    }
    const placementIssues = {
        policy_unknown: "A required sharing or repeat-credit policy was not recorded.",
        grade_unknown: "A relevant retained grade definition is unknown.",
        course_version_unknown:
            "Relevant earned work has no retained course publication. Review its equivalence.",
        course_version_differs:
            "Relevant work uses a different course publication. Review its equivalence before placement.",
        scale_unknown: "A required retained grading publication is unknown.",
        scale_differs:
            "A group grade minimum and the work use different grading publications. No conversion is assumed.",
        approval_conflict: "Resolve conflicting course waivers or substitutions in Student exceptions.",
        placement_restricted:
            "An approved placement preference reserves this course for another group in this pursuit.",
        group_average_gpa_unsupported:
            "An earlier group uses an average-GPA rule. This placement review cannot yet evaluate that constraint.",
    };
    const placementStatuses = {
        candidate: "Eligible after course-publication and group-grade checks",
        in_progress: "No final grade recorded",
        insufficient_grade: "Below a group's minimum course grade points",
        not_passing: "The retained grade is not passing",
        no_credit: "No eligible completed award",
        dropped: "Dropped work cannot satisfy completed-course requirements",
        voided: "Voided award",
        repeat_not_selected: "Another eligible repeat supplies the credit contribution",
    };
    async function groupAllocation(base, back) {
        const data = await post(`${base}academic-work/allocation/`, {});
        renderGroupAllocation(base, back, data);
    }
    function renderGroupAllocation(base, back, data, offset = 0) {
        const body = screen(
            "Course-group placement",
            data.context?.mode === "runtime_test"
                ? "TEMPORARY AUDIT TEST · NOT SAVED"
                : "RETAINED REQUIREMENTS & APPROVALS",
        );
        const restore = () => renderGroupAllocation(base, back, data, offset);
        body.append(
            node(
                "p",
                {},
                data.context?.mode === "runtime_test"
                    ? "Temporary placement against the tested audit requirements. Approved student-specific exceptions are not applied. No result or academic change is saved, and this is not a graduation decision."
                    : "Review a proposed placement of current work against course-group quantities and required courses, including approved exceptions. Overall GPA, residency, specialization rules and graduation eligibility are not evaluated here. This review saves no academic changes or audit result.",
            ),
        );
        body.append(
            actions(
                data.context?.mode === "runtime_test"
                    ? null
                    : button("Refresh course placement", () => groupAllocation(base, back)),
                button(back.returnLabel || "Back to previous screen", back),
            ),
        );
        if (data.status === "needs_selection" || data.status === "limited") {
            body.append(
                node(
                    "p",
                    { class: "alert alert-info", role: "status" },
                    data.status === "needs_selection"
                        ? "Select published degree requirements for this pursuit before reviewing course placement."
                        : "The review reached its work limit. No partial placements or records are shown. Ask the registrar to arrange a controlled review; this is not an academic failure.",
                ),
            );
            return;
        }
        body.append(ruleAllocationNotices(data.issues));
        const headings = {
            feasible: "A complete group placement was found",
            infeasible: "No complete group placement found",
            undetermined: "The placement search reached its limit",
            needs_review: "Some academic inputs need review",
            unsupported: "These retained rules need additional support",
        };
        body.append(node("h2", {}, headings[data.outcome] || "Placement needs review"));
        body.append(
            node(
                "p",
                { role: "status" },
                data.outcome === "feasible"
                    ? "The proposed placements meet the supplied course-group constraints. This is not a completed degree audit."
                    : "No placements have been assigned. A missing, uncertain or incomplete plan does not change the student's academic status.",
            ),
        );
        body.append(
            node(
                "p",
                { class: "audit-status" },
                `${data.context.name} · ${data.context.academic_year_code} · ${data.context.mode === "runtime_test" ? (data.context.source === "publication" ? `Published audit version ${data.context.version} · Temporary test` : "Unpublished trial") : `Version ${data.context.version}`}`,
            ),
        );
        body.append(
            node(
                "p",
                {},
                `${data.current_record_count} current academic records checked against available sources. This does not certify a complete historical archive.`,
            ),
        );
        body.append(
            node(
                "p",
                {},
                data.context.group_reuse === "multiple_groups"
                    ? "A completed course may satisfy multiple groups. Overall earned credit and GPA still count actual work once under their own policies."
                    : "A completed course can be placed in one group. The search considers the groups together.",
            ),
        );
        if (data.input_status === "history_gaps")
            body.append(
                node(
                    "p",
                    { class: "alert alert-info", role: "status" },
                    "Earlier history is unknown for some current work. Only supplied verified facts are used.",
                ),
            );
        if (data.context.cohort_changed)
            body.append(
                node(
                    "p",
                    { class: "alert alert-info", role: "status" },
                    "The matriculation cohort changed. This review uses the retained degree selection; ask the registrar to review it.",
                ),
            );
        for (const issue of data.issues)
            body.append(
                node("p", { class: "alert alert-info" }, placementIssues[issue] || "A retained input needs review."),
            );
        const competition = groupCompetition(data);
        if (competition) body.append(competition);
        body.append(node("h2", {}, "Course groups"));
        const groups = node("div", { class: "audit-library-grid" });
        for (const group of data.groups)
            groups.append(
                node(
                    "article",
                    { class: "audit-card" },
                    node("h3", {}, group.name),
                    node(
                        "p",
                        {},
                        group.requirement_type === "courses"
                            ? `${group.required_courses} required courses`
                            : `${text(group.required_credits_milli)} required credits`,
                    ),
                    node(
                        "p",
                        {},
                        data.outcome === "feasible"
                            ? (group.coverage
                                ? `${text(group.coverage.applied_credits_milli)} applied credits · ${group.coverage.applied_courses} distinct course${group.coverage.applied_courses === 1 ? "" : "s"} in this proposed plan`
                                : `${group.placements.length} proposed course placements`)
                            : "Placement not established",
                    ),
                    node("p", {}, groupGapSummary(group, data.outcome)),
                    button(`Inspect group ${group.name}`, () => groupPlacementDetails(data, group, restore)),
                ),
            );
        body.append(groups, node("h2", {}, "How current work was considered"));
        const names = new Map(data.groups.map((g) => [g.id, g.name]));
        const records = node("div", { class: "audit-library-grid" });
        for (const record of data.records.slice(offset, offset + 20)) {
            const card = node(
                "article",
                { class: "audit-card" },
                node("h3", {}, `${record.course_code} · ${record.course_title}`),
            );
            if (record.preferred_group_id)
                card.append(
                    node(
                        "p",
                        { class: "form-text" },
                        `Approved placement preference: ${names.get(record.preferred_group_id) || "Retained group"}. Eligibility and requirement rules still apply.`,
                    ),
                );
            for (const use of record.uses)
                card.append(
                    node(
                        "p",
                        {},
                        `${use.alternative ? "Equivalent eligible evidence for" : "Proposed placement in"} ${names.get(use.group_id)}.`,
                    ),
                );
            if (!record.uses.length)
                card.append(
                    node(
                        "p",
                        {},
                        data.outcome !== "feasible"
                            ? "No partial placement is assigned."
                            : record.statuses.includes("candidate")
                              ? "Eligible work not used in this complete placement; repeat and sharing rules still apply."
                              : "No eligible completed placement in these groups.",
                    ),
                );
            for (const status of record.statuses)
                card.append(
                    node(
                        "p",
                        {},
                        placementStatuses[status] || placementIssues[status] || "Review this record's eligibility.",
                    ),
                );
            records.append(card);
        }
        body.append(records);
        if (!data.records.length) body.append(node("p", {}, "No current contribution records to display."));
        else
            body.append(
                node(
                    "p",
                    { role: "status" },
                    `Showing ${offset + 1}–${Math.min(offset + 20, data.records.length)} of ${data.records.length} records.`,
                ),
            );
        const pages = actions();
        if (offset > 0)
            pages.append(button("Previous work records", () => renderGroupAllocation(base, back, data, offset - 20)));
        if (offset + 20 < data.records.length)
            pages.append(button("Next work records", () => renderGroupAllocation(base, back, data, offset + 20)));
        body.append(pages);
    }
    function groupCompetition(data) {
        if (
            data.outcome !== "infeasible" ||
            data.context.group_reuse !== "one_group" ||
            !data.competition?.conflicts.length
        )
            return null;
        const names = new Map(data.groups.map((group) => [group.id, group.name]));
        const section = node(
            "section",
            { class: "record-section", "aria-label": "Groups competing for completed courses" },
            node("h2", {}, "Groups competing for completed courses"),
            node(
                "p",
                {},
                "These groups compete for eligible work under the retained one-group policy. Their combined requirements exceed the shared evidence available. No partial placements have been assigned.",
            ),
        );
        const creditUnits = (value) => `${text(value)} credit${value === 1000 ? "" : "s"}`;
        for (const conflict of data.competition.conflicts) {
            const card = node(
                "article",
                { class: "audit-card" },
                node("h3", {}, conflict.group_ids.map((id) => names.get(id)).join(" · ")),
            );
            if (conflict.credit_shortfall_milli > 0)
                card.append(
                    node(
                        "p",
                        {},
                        `Together these groups require ${creditUnits(conflict.required_credits_milli)}. Their eligible courses can supply at most ${creditUnits(conflict.credit_upper_bound_milli)} when each course is used once. The combined credit shortfall is at least ${creditUnits(conflict.credit_shortfall_milli)}.`,
                    ),
                );
            if (conflict.course_shortfall > 0)
                card.append(
                    node(
                        "p",
                        {},
                        `Together these groups need at least ${conflict.required_courses} distinct course contributions. Only ${conflict.course_upper_bound} eligible course${conflict.course_upper_bound === 1 ? " is" : "s are"} available across them. The combined shortfall is at least ${conflict.course_shortfall} course contribution${conflict.course_shortfall === 1 ? "" : "s"}.`,
                    ),
                );
            card.append(
                node(
                    "p",
                    { class: "form-text" },
                    "Review the retained requirements and approved alternatives before planning additional work. These bounds do not select a course to take or change an academic record.",
                ),
            );
            section.append(card);
        }
        if (data.competition.more_conflicts)
            section.append(
                node(
                    "p",
                    { role: "status" },
                    "More shared-course shortages were found. Showing the first 10 explanations.",
                ),
            );
        section.append(
            node(
                "p",
                { class: "form-text" },
                "These explanations cover supported shared shortages. Other rule conflicts may still require registrar review.",
            ),
        );
        return section;
    }
    function groupGapSummary(group, outcome) {
        return !group.gaps
            ? "Shortfalls cannot be assessed until the retained inputs and rules are verified."
            : group.gaps.has_shortfall
              ? "Current eligible work falls short of this group's requirements."
              : outcome === "feasible"
                ? "The proposed placement meets this group's supported requirements."
                : "No simple shortage found. Courses must still fit together in a complete placement.";
    }
    function groupGapDetails(group, outcome) {
        const section = node(
            "section",
            { class: "audit-preview" },
            node("h2", {}, "What current work can support"),
            node("p", {}, groupGapSummary(group, outcome)),
        );
        const gaps = group.gaps;
        if (!gaps) return section;
        section.append(
            node(
                "p",
                {},
                "These are optimistic bounds on eligible completed evidence, not applied credits or a partial placement. Alternatives, target collisions and sharing can reduce what can actually be placed. In-progress, insufficient-grade, dropped and voided work supply no completed contribution.",
            ),
        );
        if (group.required_credits_milli > 0)
            section.append(
                node(
                    "p",
                    {},
                    `At most ${text(gaps.credit_upper_bound_milli)} credits toward the ${text(group.required_credits_milli)}-credit minimum.${gaps.credit_shortfall_milli > 0 ? ` At least ${text(gaps.credit_shortfall_milli)} more eligible credits are needed.` : " This bound does not establish completion."}`,
                ),
            );
        if (group.required_courses > 0)
            section.append(
                node(
                    "p",
                    {},
                    `At most ${gaps.course_upper_bound} distinct course contributions toward the ${group.required_courses}-course minimum.${gaps.course_shortfall > 0 ? ` At least ${gaps.course_shortfall} more eligible course contributions are needed.` : " This bound does not establish completion."}`,
                ),
            );
        section.append(
            node(
                "p",
                {},
                "Course waivers remove named obligations only. They create no credits, completed courses or reduction to choose counts. Approved quantity reductions are already included in the required minimums above.",
            ),
        );
        return section;
    }
    function ruleCoverageSummary(coverage) {
        if (coverage.mode === "pool")
            return `Eligible pool · ${text(coverage.applied_credits_milli)} credits from ${coverage.applied_courses} distinct course${coverage.applied_courses === 1 ? "" : "s"} applied; this card has no separate minimum.`;
        if (coverage.mode === "credits")
            return `Met in proposed plan · ${text(coverage.applied_credits_milli)} of ${text(coverage.required_credits_milli)} required credits applied.`;
        return `Met in proposed plan · ${coverage.covered_courses} of ${coverage.required_courses} required courses covered.${coverage.waived_courses ? ` ${coverage.waived_courses} course requirement${coverage.waived_courses === 1 ? "" : "s"} waived; no credit created.` : ""}`;
    }
    function groupPlacementDetails(data, group, back, offset = 0, ruleOffset = 0) {
        const body = screen(group.name, "PROPOSED COURSE-GROUP PLACEMENT");
        body.append(
            node(
                "p",
                {},
                `${group.requirement_type === "courses" ? `${group.required_courses} required courses` : `${text(group.required_credits_milli)} required credits`}${group.minimum_grade_points_milli == null ? "" : ` · Minimum course grade points: ${text(group.minimum_grade_points_milli)}`}`,
            ),
        );
        body.append(
            node(
                "p",
                {},
                `${group.waived_courses.length} approved course waivers in this group. Waivers create no earned credit and do not reduce quantity minimums.`,
            ),
        );
        body.append(actions(button("Back to course groups", back)));
        if (data.outcome !== "feasible")
            body.append(
                node(
                    "p",
                    { role: "status" },
                    "No complete placement was established. No speculative partial plan is shown.",
                ),
            );
        if (data.outcome === "feasible" && group.coverage)
            body.append(
                node(
                    "p",
                    { class: "alert alert-success", role: "status" },
                    `Group quantity met in this proposed plan: ${text(group.coverage.applied_credits_milli)} applied credits · ${group.coverage.applied_courses} distinct course${group.coverage.applied_courses === 1 ? "" : "s"}. These group contributions do not add to overall earned credit or GPA.`,
                ),
            );
        const namedRecords = new Map(data.records.map(record => [record.id, record]));
        body.append(
            window.createDegreeNamedCourseReview(
                { node, button, milli: text }, group.course_review,
                attempt => namedRecords.get(attempt.work_id),
            ),
            groupGapDetails(group, data.outcome),
            node("h2", {}, "Retained course requirements"),
        );
        for (const rule of group.rules.slice(ruleOffset, ruleOffset + 20)) {
            const gap = group.gaps?.rules.find((r) => r.id === rule.id);
            const coverage = data.outcome === "feasible" ? group.coverage?.rules[rule.id] : null;
            const detail = node(
                "details",
                { class: "audit-tree-section" },
                node(
                    "summary",
                    {},
                    rule.eligibility
                        ? `${rule.mode === "choose" ? `Choose ${rule.minimum_courses} matching courses` : rule.mode === "credits" ? `Take ${text(rule.minimum_credits_milli)} matching credits` : "Eligible course pool"} · ${rule.eligibility.code} · Revision ${rule.eligibility.revision}`
                        : `${rule.mode === "all" ? "All listed courses" : rule.mode === "choose" ? `Choose ${rule.minimum_courses} courses` : rule.mode === "credits" ? `Take ${text(rule.minimum_credits_milli)} credits from this list` : "Eligible course pool"} · ${rule.course_ids.length} listed`,
                ),
            );
            if (coverage) {
                detail.querySelector("summary").append(
                    node("span", { class: "d-block audit-status" }, ruleCoverageSummary(coverage)),
                );
                detail.append(
                    node(
                        "p",
                        {},
                        `Applied to this card: ${text(coverage.applied_credits_milli)} credits · ${coverage.applied_courses} distinct course${coverage.applied_courses === 1 ? "" : "s"}. A course can support overlapping cards while counting once in the group.`,
                    ),
                );
            }
            if (rule.eligibility) detail.append(courseRuleTree(rule.eligibility));
            if (!coverage && gap && rule.mode === "credits")
                detail.append(
                    node(
                        "p",
                        {},
                        `At most ${text(gap.credit_upper_bound_milli)} eligible credits toward this list's ${text(gap.required_credits_milli)}-credit minimum.${gap.credit_shortfall_milli > 0 ? ` At least ${text(gap.credit_shortfall_milli)} more eligible credits from this list are needed.` : " This bound does not establish completion; courses may compete for other requirements."}`,
                    ),
                );
            else if (!coverage && gap && rule.mode !== "pool")
                detail.append(
                    node(
                        "p",
                        {},
                        `Required course contributions: ${gap.required_courses} · ${rule.mode === "all" ? "Required courses" : "Listed options"} with eligible evidence: ${rule.eligibility ? gap.eligible_course_ids.length : gap.eligible_target_count}. At most ${gap.course_upper_bound} distinct eligible course contributions can be used.${gap.shortfall_courses > 0 ? ` At least ${gap.shortfall_courses} more distinct eligible course contribution${gap.shortfall_courses === 1 ? " is" : "s are"} needed.` : " Eligible choices may still compete for the same actual course or another group."}`,
                    ),
                );
            let loaded = false;
            detail.addEventListener("toggle", () => {
                if (!detail.open || loaded) return;
                loaded = true;
                const list = node("ul", {});
                for (const id of rule.course_ids) {
                    const course = group.courses[id];
                    const waived = !rule.eligibility && group.waived_courses.includes(id);
                    const placed =
                        data.outcome === "feasible" &&
                        group.placements.some(
                            (p) =>
                                (rule.eligibility ? p.course_id : p.target_course_id) === id &&
                                (!p.rule_credits_milli || Object.hasOwn(p.rule_credits_milli, rule.id)),
                        );
                    const eligible = (rule.eligibility ? gap?.eligible_course_ids : gap?.eligible_target_ids)?.includes(id);
                    list.append(
                        node(
                            "li",
                            {},
                            `${course.code} · ${course.title}${waived ? " · Course requirement waived" : placed ? " · Proposed placement" : ""}${group.gaps ? (eligible ? " · Eligible completed evidence available" : " · No eligible completed evidence") : ""}`,
                        ),
                    );
                }
                detail.append(list);
            });
            body.append(detail);
        }
        const rulePages = actions();
        if (ruleOffset > 0)
            rulePages.append(
                button("Previous requirement cards", () =>
                    groupPlacementDetails(data, group, back, offset, ruleOffset - 20),
                ),
            );
        if (ruleOffset + 20 < group.rules.length)
            rulePages.append(
                button("Next requirement cards", () => groupPlacementDetails(data, group, back, offset, ruleOffset + 20)),
            );
        body.append(rulePages, node("h2", {}, "Proposed placements"));
        const cards = node("div", { class: "audit-library-grid" });
        const records = new Map(data.records.map(record => [record.id, record]));
        for (const p of group.placements.slice(offset, offset + 20)) {
            const article = node(
                "article",
                { class: "audit-card" },
                node("h2", {}, `${p.course_code} · ${p.course_title}`),
                node("p", {}, `${text(p.credits_milli)} actual earned credits · One distinct course`),
                node(
                    "p",
                    {},
                    `${p.via_substitution ? "Approved alternative for" : "Supports requirement"} ${p.target_code} · ${p.target_title}`,
                ),
                node(
                    "p",
                    {},
                    p.work_options.length > 1
                        ? `${p.work_options.length} equivalent earned-credit evidence alternatives remain available. No overall credit or GPA winner is selected here.`
                        : `${p.work_options[0].length} eligible award${p.work_options[0].length === 1 ? "" : "s"} support this placement.`,
                ),
            );
            article.append(window.createDegreeProgressEvidence(
                { node, button, milli: text }, p.work_options, id => records.get(id),
            ));
            if (p.rule_credits_milli) {
                const contributions = node("details", {}, node("summary", {}, "Requirement contributions"));
                let loaded = false;
                contributions.addEventListener("toggle", () => {
                    if (!contributions.open || loaded) return;
                    loaded = true;
                    const list = node("ul", {});
                    for (const [id, credits] of Object.entries(p.rule_credits_milli)) {
                        const rule = group.rules.find((r) => r.id === id);
                        if (!rule) continue;
                        const label = rule.eligibility
                            ? `${rule.eligibility.code} · Revision ${rule.eligibility.revision}`
                            : `${rule.mode === "all" ? "All listed courses" : rule.mode === "choose" ? `Choose ${rule.minimum_courses} listed courses` : rule.mode === "credits" ? `Take ${text(rule.minimum_credits_milli)} listed credits` : "Eligible listed course pool"} · ${p.target_code}`;
                        list.append(node("li", {}, `${label}: ${text(credits)} eligible credits · One distinct course`));
                    }
                    contributions.append(list);
                });
                article.append(contributions);
            }
            cards.append(article);
        }
        body.append(cards);
        if (group.placements.length)
            body.append(
                node(
                    "p",
                    { role: "status" },
                    `Showing ${offset + 1}–${Math.min(offset + 20, group.placements.length)} of ${group.placements.length} placements.`,
                ),
            );
        const pages = actions();
        if (offset > 0)
            pages.append(
                button("Previous placements", () => groupPlacementDetails(data, group, back, offset - 20, ruleOffset)),
            );
        if (offset + 20 < group.placements.length)
            pages.append(
                button("Next placements", () => groupPlacementDetails(data, group, back, offset + 20, ruleOffset)),
            );
        body.append(pages);
    }

    const calculationReasons = window.degreeProgressCalculationReasons;
    const calculationIssues = {
        policy_unknown: "A required calculation policy was not recorded.",
        grade_unknown: "A retained grade definition is unavailable.",
        grading_policy_unknown: "A required grading publication is unknown.",
        grading_policy_differs:
            "Some grades use a different grading publication. Compatibility must be established before combining them.",
        ordering_policy_unknown:
            "The institutional choice for ordering repeat attempts was not retained. Current defaults cannot replace that missing choice.",
        chronology_unknown: "Retained academic-period or completion dates needed to compare repeats are unavailable.",
        ambiguous_repeat: "Tied repeat attempts have different GPA weights; no winner has been assumed.",
        arithmetic_limit: "The calculation reached its supported numeric limit.",
    };
    async function academicCalculations(base, back) {
        const data = await post(`${base}academic-work/calculations/`, {});
        renderCalculations(base, back, data);
    }

    function renderCalculations(base, back, data, offset = 0) {
        const body = screen("Credits & GPA", "RETAINED ACADEMIC POLICIES");
        body.append(
            node(
                "p",
                {},
                "Review current academic work using this pursuit's selected degree policies. These totals do not apply courses to requirements or determine degree completion, academic standing or graduation eligibility. This is not an issued transcript or a saved evaluation.",
            ),
        );
        body.append(
            actions(
                button("Refresh credits & GPA", () => academicCalculations(base, back)),
                button("Back to previous screen", back),
            ),
        );
        if (data.status === "needs_selection") {
            body.append(
                node(
                    "p",
                    { class: "alert alert-info", role: "status" },
                    "Select published degree requirements for this pursuit before calculating credits and GPA. The system will use that publication's retained policies.",
                ),
            );
            return;
        }
        if (data.status === "limited") {
            body.append(
                node(
                    "p",
                    { class: "alert alert-info", role: "status" },
                    "The review reached its work limit. No partial totals or records are shown. This is not a failed academic requirement; ask the registrar to arrange a controlled review.",
                ),
            );
            return;
        }
        const context = data.context,
            totals = data.totals;
        body.append(
            node(
                "p",
                { class: "audit-status" },
                `${context.name} · ${context.academic_year_code} · Version ${context.version}`,
            ),
        );
        body.append(
            node(
                "p",
                {},
                `${data.current_record_count} current academic records checked against available sources. This check does not certify that the historical archive is complete.`,
            ),
        );
        if (context.cohort_changed)
            body.append(
                node(
                    "p",
                    { class: "alert alert-info", role: "status" },
                    "The matriculation cohort changed. This review still uses the retained degree selection; ask the registrar to review it.",
                ),
            );
        if (data.input_status === "history_gaps")
            body.append(
                node(
                    "p",
                    { class: "alert alert-info", role: "status" },
                    "Earlier history is unknown for some current records. These are current-work calculations using known facts, not reconstructed earlier totals.",
                ),
            );
        const institutional =
            totals.institutional_minimum_milli === null
                ? "Undetermined"
                : totals.institutional_minimum_milli === totals.institutional_maximum_milli
                  ? text(totals.institutional_minimum_milli)
                  : `${text(totals.institutional_minimum_milli)}–${text(totals.institutional_maximum_milli)}`;
        body.append(
            node(
                "div",
                { class: "audit-library-grid" },
                node(
                    "article",
                    { class: "audit-card" },
                    node("h2", {}, "Earned credits"),
                    node(
                        "p",
                        { class: "h3" },
                        totals.credit_ready ? text(totals.earned_milli) : "Undetermined",
                    ),
                    node(
                        "p",
                        {},
                        "After the retained repeat-credit policy. Course sharing and waivers create no additional credit.",
                    ),
                ),
                node(
                    "article",
                    { class: "audit-card" },
                    node("h2", {}, "GPA"),
                    node(
                        "p",
                        { class: "h3" },
                        !totals.gpa_ready
                            ? "Undetermined"
                            : totals.gpa_milli === null
                              ? "Not applicable"
                              : text(totals.gpa_milli),
                    ),
                    node(
                        "p",
                        {},
                        !totals.gpa_ready
                            ? "Review the missing or conflicting inputs below. No partial GPA is shown."
                            : totals.gpa_milli === null
                              ? "There are no eligible GPA credits. This is not a zero GPA."
                              : `${text(totals.gpa_credits_milli)} GPA credits · ${text(totals.quality_points_milli)} quality points. Displayed to three decimals without rounding upward.`,
                    ),
                ),
                node(
                    "article",
                    { class: "audit-card" },
                    node("h2", {}, "Institutional-origin credits"),
                    node("p", { class: "h3" }, institutional),
                    node(
                        "p",
                        {},
                        "An interval preserves tied institutional and transfer alternatives. This is not a final residency-policy determination.",
                    ),
                ),
            ),
        );
        const policies = node(
            "details",
            { class: "audit-tree-section" },
            node("summary", {}, "Policies used for this review"),
        );
        for (const key of ["repeat_credit", "repeat_gpa", "transfer_overall_gpa"])
            policies.append(
                node(
                    "p",
                    {},
                    `${policyLabels[key]}: ${policyChoices[key].find(([value]) => value === data.policy[key])?.[1] || "Not recorded"}`,
                ),
            );
        policies.append(
            node(
                "p",
                {},
                "Repeat ordering: " +
                    ({
                        academic_period: "Academic term / instructional period",
                        completion_date: "Actual completion date",
                    }[data.policy.attempt_order] || "Not recorded"),
            ),
        );
        body.append(policies);
        if (data.issues.length) {
            const issues = node("section", { class: "audit-preview" }, node("h2", {}, "What needs review"));
            for (const issue of data.issues)
                issues.append(
                    node(
                        "p",
                        {},
                        `${issue.component === "credit" ? "Earned credits" : "GPA"}: ${calculationIssues[issue.code] || "A retained input needs review."}`,
                    ),
                );
            body.append(issues);
        }
        body.append(node("h2", {}, "How current records contribute"));
        const cards = node("div", { class: "audit-library-grid" });
        for (const record of data.records.slice(offset, offset + 20))
            cards.append(
                node(
                    "article",
                    { class: "audit-card" },
                    node("h3", {}, `${record.course_code} · ${record.course_title}`),
                    record.ordering_on
                        ? node(
                              "p",
                              {},
                              `${data.policy.attempt_order === "academic_period" ? (record.credit_type === "transfer" ? "Retained source period start" : "Retained instructional start") : "Retained completion date"}: ${record.ordering_on}.`,
                          )
                        : null,
                    node("p", {}, `Earned credit: ${calculationReasons[record.credit] || "Needs review"}.`),
                    node("p", {}, `GPA: ${calculationReasons[record.gpa] || "Needs review"}.`),
                    record.grading_republication_verified
                        ? node("p", {}, "The recorded grading publication was verified as identical to this degree's scale. Its original reference is preserved.")
                        : null,
                ),
            );
        body.append(cards);
        if (!data.records.length) body.append(node("p", {}, "No current academic work is recorded."));
        body.append(
            node(
                "p",
                { role: "status" },
                data.records.length
                    ? `Showing ${offset + 1}–${Math.min(offset + 20, data.records.length)} of ${data.records.length} records.`
                    : "No contribution records to display.",
            ),
        );
        const pages = actions();
        if (offset > 0)
            pages.append(
                button("Previous contributions", () => renderCalculations(base, back, data, offset - 20)),
            );
        if (offset + 20 < data.records.length)
            pages.append(
                button("Next contributions", () => renderCalculations(base, back, data, offset + 20)),
            );
        body.append(pages);
    }
    async function verifyAcademicInputs(base, back) {
        const data = await post(`${base}academic-work/verify/`, {});
        const body = screen("Academic input verification", "STUDENT ACADEMIC RECORDS");
        const limited = data.status === "limited";
        const gaps = data.status === "history_gaps";
        body.append(
            node(
                "p",
                {},
                "Check current academic records against available registration, academic and transfer history references. This does not calculate degree completion, GPA or graduation eligibility, or certify a complete historical archive.",
            ),
            node(
                "article",
                { class: "audit-card" },
                node(
                    "h2",
                    {},
                    limited
                        ? "Verification reached its work limit"
                        : gaps
                          ? "Earlier history is unknown"
                          : "Available references checked",
                ),
                node(
                    "p",
                    { role: "status" },
                    limited
                        ? "No complete input set was produced. This is not a failed academic requirement. Ask the registrar to arrange a larger, controlled review."
                        : `${data.current_record_count} current academic record${data.current_record_count === 1 ? "" : "s"} checked.`,
                ),
                node(
                    "p",
                    {},
                    limited
                        ? "Verification has limits on records and history replay to keep requests responsive. A partial count or partial calculation is never shown."
                        : gaps
                          ? "Current records and their available references agree, but some earlier history was not recorded. Missing history has not been invented."
                          : "The checked records agree with their available history references. Grading, repeat and degree-allocation policies still need evaluation.",
                ),
            ),
            actions(
                button("Refresh verification", () => verifyAcademicInputs(base, back)),
                button("Back to academic work", back),
            ),
        );
    }
    async function academicWork(base, back, after = null, stack = [], matching = false) {
        const data = await post(`${base}academic-work/${matching ? "matches/" : ""}`, after ? { after } : {});
        const body = screen(matching ? "Requirement matches" : "Academic work", "STUDENT ACADEMIC RECORDS");
        body.append(
            node(
                "p",
                {},
                "Review this student's institutional and transfer work. These records have not been allocated to this program's requirements. This view does not calculate completion, GPA or graduation eligibility.",
            ),
            node(
                "p",
                {},
                "Grades and credits use retained record facts. Earlier records may have unknown history. Term labels marked current are navigation labels, not retained historical definitions. The list is not ordered by completion date.",
            ),
            actions(
                button(matching ? "Refresh requirement matches" : "Refresh academic work", () =>
                    academicWork(base, back, null, [], matching),
                ),
                button(matching ? "View academic work only" : "Check requirement matches", () =>
                    academicWork(base, back, after, stack, !matching),
                ),
                button("Credits & GPA", () =>
                    academicCalculations(base, () => academicWork(base, back, after, stack, matching)),
                ),
                button("Review course placement", () =>
                    groupAllocation(base, () => academicWork(base, back, after, stack, matching)),
                ),
                button("Verify academic inputs", () =>
                    verifyAcademicInputs(base, () => academicWork(base, back, after, stack, matching)),
                ),
                button("Back to degree requirements", back),
            ),
        );
        if (matching) {
            const context = data.matches_context;
            body.append(
                node(
                    "p",
                    {},
                    "Matches identify potential uses of this page's work. Repeat, sharing, quantity, residency and GPA rules still need evaluation. No course is applied, no totals are calculated and no requirement is marked complete.",
                ),
            );
            if (context) {
                body.append(
                    node(
                        "p",
                        { class: "audit-status" },
                        `${context.name} · ${context.academic_year_code} · Version ${context.version}`,
                    ),
                );
                if (context.cohort_changed)
                    body.append(
                        node(
                            "p",
                            { class: "alert alert-info", role: "status" },
                            "The matriculation cohort changed. These matches still use the retained requirement selection; ask the registrar to review it.",
                        ),
                    );
                if (context.approval_conflicts)
                    body.append(
                        node(
                            "p",
                            { class: "alert alert-warning", role: "status" },
                            "Some course approvals conflict. Review Student exceptions before applying work.",
                        ),
                    );
            } else
                body.append(
                    node(
                        "p",
                        { class: "alert alert-info", role: "status" },
                        "Select published degree requirements for this pursuit before checking course matches.",
                    ),
                );
        }
        const cards = node("div", { class: "audit-library-grid" });
        for (const item of data.items) {
            const grade = item.grade;
            const status =
                item.status === "V"
                    ? "Voided award"
                    : item.status === "W"
                      ? "Late drop"
                      : item.grade_recorded
                        ? "Grade recorded"
                        : item.credit_type === "transfer"
                          ? "Transfer award"
                          : "No final grade recorded";
            cards.append(
                node(
                    "article",
                    { class: "audit-card" },
                    node("h2", {}, `${item.course_code} · ${item.course_title}`),
                    node(
                        "p",
                        {},
                        `${item.credit_type === "transfer" ? "Transfer" : "Institutional"} · ${status}`,
                    ),
                    node(
                        "p",
                        {},
                        `Term: ${item.term_code || "Unknown"}${item.term_code ? (item.term_label_retained ? " (retained)" : " (current label)") : ""}${item.completed_on ? ` · Completed ${item.completed_on}` : ""}${item.credit_type === "transfer" && item.academic_on ? ` · Sending period began ${item.academic_on}` : ""}`,
                    ),
                    node(
                        "p",
                        {},
                        `Attempted credits: ${text(item.attempted_milli)} · Awarded credits: ${text(item.completed_milli)}`,
                    ),
                    node(
                        "p",
                        {},
                        grade
                            ? `Retained grade: ${grade.code} · Grade points per course: ${text(grade.points_milli)}`
                            : item.grade_recorded
                              ? "Grade recorded; retained grade definition is unavailable."
                              : "No retained final grade.",
                    ),
                    grade
                        ? node(
                              "p",
                              {},
                              `Grade definition: ${grade.passes ? "passing" : "not passing"}; ${grade.counts_gpa ? "GPA eligible" : "excluded from GPA"}. Credit-weighted quality points: ${text(item.quality_points_milli)}.`,
                          )
                        : null,
                    node(
                        "p",
                        {},
                        `Course publication: ${item.course_version_id ? "retained reference" : "unknown"}.${item.credit_type === "institutional" ? ` Section publication: ${item.section_version_id ? "retained reference" : "unknown"}.` : ""}`,
                    ),
                    node(
                        "p",
                        {},
                        item.history_recorded
                            ? "Recorded history verified."
                            : "No recorded history; these are observed current facts.",
                    ),
                    item.earlier_history_unknown
                        ? node(
                              "p",
                              { class: "audit-status" },
                              "Earlier history is unknown. This record cannot establish all prior academic facts.",
                          )
                        : null,
                    matching && data.matches_context ? workMatches(item, data.match_limit) : null,
                ),
            );
        }
        body.append(cards);
        if (!data.items.length)
            body.append(node("p", { class: "audit-empty" }, "No current academic work on this page."));
        pager(body, data, after, stack, (a, s) => academicWork(base, back, a, s, matching));
    }
    function workMatches(item, limit) {
        const section = node(
            "section",
            { class: "audit-tree-section", "aria-label": "Course requirement matches" },
            node("h3", { class: "h6" }, "Requirement matches"),
        );
        if (item.saved_rule_matches_pending)
            section.append(
                node(
                    "p",
                    { class: "alert alert-info" },
                    "This list covers named-course matches. Use Degree progress for saved-rule eligibility and complete course-group placement.",
                ),
            );
        const matches = item.requirement_matches || [];
        if (!matches.length)
            section.append(
                node(
                    "p",
                    {},
                    "Not listed in the selected requirements or their approved alternatives. This does not decide whether the work counts toward overall credits or GPA.",
                ),
            );
        for (const match of matches)
            section.append(
                node(
                    "div",
                    { class: "mb-3" },
                    node("h4", { class: "h6" }, match.group_name),
                    node(
                        "p",
                        {},
                        `${match.via_substitution ? "Approved alternative for" : "Listed course:"} ${match.required_course_code} · ${match.required_course_title}`,
                    ),
                    node("p", {}, match.explanation),
                    match.minimum_grade_points_milli != null
                        ? node(
                              "p",
                              { class: "form-text" },
                              `Group minimum per-course grade points: ${text(match.minimum_grade_points_milli)}`,
                          )
                        : null,
                ),
            );
        if (item.more_requirement_matches)
            section.append(
                node(
                    "p",
                    { class: "audit-status" },
                    `Showing the first ${limit} matches for this record. More matches exist; this is not a complete allocation.`,
                ),
            );
        return section;
    }
    async function studentChoices(base, current, back) {
        const initial = await post(`${base}choices/`);
        let body = screen("Choose student degree requirements", "REGISTRAR REVIEW");
        const returnToChoices = () => {
            const previous = body;
            body = screen("Choose student degree requirements", "REGISTRAR REVIEW");
            body.append(...previous.childNodes);
        };
        let selected = null,
            template = initial.template_id,
            started = current.selection?.pursuit_started_on || "",
            reason = "";
        const choices = node("div", { class: "audit-library-grid" }),
            status = node("p", { role: "status", class: "audit-status" });
        body.append(
            node(
                "p",
                {},
                "Choose a specific published version for the current matriculation cohort. Record the actual pursuit start date; it is not inferred from the entry term. This replaces only the selected program’s requirement reference.",
            ),
            choices,
            status,
            field("Program pursuit start date", started, (v) => (started = v), {
                type: "date",
                required: true,
            }),
            field("Reason for requirement assignment", "", (v) => (reason = v), {
                maxlength: 1000,
                required: true,
            }),
            actions(
                button(
                    "Review requirement assignment",
                    async () => {
                        if (!selected || !started || !reason.trim())
                            throw new Error(
                                "Choose a published version, the actual pursuit start date, and a reason.",
                            );
                        const review = await post(`${base}review/`, {
                            template_id: template,
                            requirements_version_id: selected,
                            pursuit_started_on: started,
                            reason,
                        });
                        const content = screen("Confirm student requirement assignment");
                        content.append(
                            node(
                                "p",
                                {},
                                `${review.requirements.context.academic_year_code} · Version ${review.requirements.version}. Pursuit began ${review.selection.pursuit_started_on}. Anticipated completion: ${review.anticipated_completion_on || "Not specified"}.`,
                            ),
                            node("p", {}, `Reason: ${review.reason}`),
                            degreeTree(review.requirements),
                            actions(
                                button(
                                    "Assign reviewed requirements",
                                    async () => {
                                        await post(`${base}assign/`, { review_token: review.review_token });
                                        await back();
                                    },
                                    "btn-primary",
                                ),
                                button("Change selection", returnToChoices),
                            ),
                        );
                    },
                    "btn-primary",
                ),
                button("Back to selected requirements", back),
            ),
        );
        async function page(data, cursor = null, stack = []) {
            choices.replaceChildren();
            template = data.template_id;
            for (const v of data.items) {
                const input = node("input", {
                    type: "radio",
                    name: "student-requirement-version",
                    value: v.version_id,
                    checked: selected === v.version_id,
                    disabled: v.assignable === false || ![3, 4].includes(v.schema_version),
                });
                input.addEventListener("change", () => {
                    selected = v.version_id;
                    status.textContent = `Selected version ${v.version}.`;
                });
                choices.append(
                    node(
                        "article",
                        { class: "audit-library-card" },
                        node(
                            "label",
                            { class: "audit-source-choice" },
                            input,
                            node("span", {}, `Version ${v.version} · ${v.name} · ${v.academic_year_code}`),
                        ),
                        v.assignment_note || ![3, 4].includes(v.schema_version)
                            ? node(
                                  "p",
                                  { class: "form-text" },
                                  v.assignment_note ||
                                      "Publish an updated reusable-group composition before assigning this version.",
                              )
                            : null,
                    ),
                );
            }
            if (!data.items.length)
                choices.append(
                    node(
                        "p",
                        {},
                        "No published requirements for this matriculation cohort. Catalog staff must publish the degree composition first.",
                    ),
                );
            pager(
                choices,
                data,
                cursor,
                stack,
                async (next, previous) =>
                    page(await post(`${base}choices/`, next ? { cursor: next } : {}), next, previous),
                "next_cursor",
            );
        }
        await page(initial);
    }
    async function studentHistory(base, back, cursor = null, stack = []) {
        const data = await post(`${base}history/`, cursor ? { cursor } : {}),
            body = screen("Student requirement assignment history");
        body.append(
            node(
                "p",
                {},
                "Each decision retains its exact requirement reference and pursuit start date. Later changes preserve earlier decisions.",
            ),
        );
        for (const item of data.items)
            body.append(
                node(
                    "article",
                    { class: "audit-library-card" },
                    node("h2", { class: "h5" }, `Decision ${item.version}`),
                    node(
                        "p",
                        {},
                        `Pursuit began ${item.pursuit_started_on}. Effective ${item.effective_at}. Recorded ${item.recorded_at}.`,
                    ),
                    node(
                        "p",
                        { class: "form-text" },
                        `Requirement reference: ${item.requirements_version_id}`,
                    ),
                ),
            );
        if (!data.items.length) body.append(node("p", {}, "No requirement assignment decisions yet."));
        pager(
            body,
            data,
            cursor,
            stack,
            (next, previous) => studentHistory(base, back, next, previous),
            "next_cursor",
        );
        body.append(button("Back to selected requirements", back));
    }

    const minimumLabels = {
        min_credits_milli: "Required credits",
        min_resident_milli: "Resident credits",
        min_cumulative_gpa_milli: "Cumulative GPA",
        min_overall_gpa_milli: "Overall GPA",
        min_gpa_milli: "Minimum GPA",
        min_courses: "Required courses",
        min_course_grade_points_milli: "Minimum grade points per course",
    };
    function groupMinimums(data, title = "Group minimums for this student") {
        const entries = Object.entries(data.effective_minimums.groups || {});
        if (!entries.length) return null;
        const section = node(
            "section",
            { class: "audit-tree", role: "group", "aria-label": "Group minimums" },
            node("h2", { class: "h5" }, title),
        );
        for (const [id, group] of entries) {
            const name =
                data.group_labels?.[id] ||
                data.targets?.find((target) => target.group_id === id)?.label ||
                "Course group";
            const detail = node(
                "details",
                { open: (data.affected_groups || []).includes(name) },
                node("summary", {}, name),
            );
            for (const [key, value] of Object.entries(group)) {
                if (
                    !Object.hasOwn(minimumLabels, key) ||
                    (key === "min_courses" && group.requirement_type !== "courses") ||
                    (key === "min_credits_milli" && group.requirement_type === "courses")
                )
                    continue;
                const amount =
                    value == null ? "No minimum" : key === "min_courses" ? String(value) : text(value);
                detail.append(
                    node(
                        "p",
                        {},
                        `${minimumLabels[key]}: ${amount}${key === "min_course_grade_points_milli" && value != null ? ` out of ${scaleText(data.grade_scale?.maximum_milli ?? group.max_course_grade_points_milli)}` : ""}`,
                    ),
                );
            }
            section.append(detail);
        }
        return section;
    }
    function adjustmentLabel(p, data) {
        const d = p.definition;
        if (d.kind === "placement") {
            if (p.subject_label) return `${p.subject_label} → ${p.scope_label}`;
            if (p.applicable === false)
                return "Course placement · retained approval for an earlier degree publication";
            const course = (data?.placement_courses || data?.courses)?.find(
                (c) => c.course_id === d.course_id &&
                    c.substitution_adjustment_id === d.substitution_adjustment_id,
            );
            const retained = data?.substitution_details?.[p.adjustment_id];
            const group = course?.placement_groups?.find((g) => g.id === d.group_id);
            if (retained)
                return `Course placement · ${courseLabel(retained)} → ${group?.name || data?.group_labels?.[d.group_id] || "Retained group"}`;
            return `Course placement · ${group ? `${group.course_code} · ${group.course_title}` : "Retained course"} → ${group?.name || data?.group_labels?.[d.group_id] || "Retained group"}`;
        }
        if (d.kind === "course_version") {
            const version=data?.substitution_details?.[p.adjustment_id];
            const required=data?.courses?.find(c=>c.course_id===d.course_id);
            return `${p.subject_label || `Course publication acceptance · ${required ? courseLabel(required) : "Retained course"}`} → ${p.replacement_label || (version ? `Version ${version.version} · ${courseLabel(version)}` : "Selected retained publication")}`;
        }
        if (d.kind === "substitution") {
            const required = data?.courses?.find((c) => c.course_id === d.course_id),
                replacement = data?.substitution_details?.[p.adjustment_id];
            return `${p.subject_label || `Course substitution · ${required ? courseLabel(required) : "Retained required course"}`} → ${p.replacement_label || (replacement ? courseLabel(replacement) : "Selected replacement publication")}`;
        }
        if (d.kind === "waiver") {
            if (p.subject_label) return p.subject_label;
            const c = data?.courses?.find((c) => c.course_id === d.course_id);
            return `Course waiver · ${c ? courseLabel(c) : "Retained course reference"}`;
        }
        const t = data?.targets?.find((t) => t.group_id === d.group_id && t.minimum === d.minimum);
        return `${t?.label || p.scope_label || (d.group_id ? "Course group" : "Degree")} · ${minimumLabels[d.minimum]}: ${text(d.original_milli)} → ${text(d.required_milli)}`;
    }
    function previewCourseApprovals(data) {
        const replacements = {}, publications={};
        for (const item of data.items) {
            const d = item.definition;
            if (item.applicable && d.kind==="course_version" && data.effective_minimums.accepted_course_versions?.[d.course_id]?.[d.version_id]) {
                (publications[d.course_id] ||= []).push(data.substitution_details[item.adjustment_id]);
            }
            if (!item.applicable || d.kind !== "substitution") continue;
            const effective = data.effective_minimums.substitutions[d.course_id];
            if (
                effective?.course_id === d.replacement_course_id &&
                effective?.version_id === d.replacement_version_id
            )
                replacements[d.course_id] = data.substitution_details[item.adjustment_id];
        }
        return { waived_course_ids: data.effective_minimums.waived_course_ids, replacements, publications };
    }
    async function adjustedRequirements(
        base,
        back,
        withApprovals = true,
        backLabel = "Back to student exceptions",
    ) {
        const data = await request(`${base}preview/`),
            body = screen("Requirements with approvals", "ONE STUDENT · ONE PROGRAM PURSUIT");
        body.append(
            node(
                "p",
                {},
                "Inspect the selected publication with approved exceptions. This preview does not calculate completed coursework or degree eligibility.",
            ),
            actions(
                button("Refresh preview", () => adjustedRequirements(base, back, withApprovals, backLabel)),
                button(backLabel, back),
            ),
        );
        if (!data.requirements) {
            body.append(
                node(
                    "p",
                    { class: "audit-empty" },
                    "Assign published requirements before previewing this program pursuit.",
                ),
            );
            return;
        }
        const active = data.items.filter((item) => item.applicable),
            kinds = (kind) => active.filter((item) => item.definition.kind === kind).length,
            summary = [
                ["waiver", "course waiver"],
                ["substitution", "substitution approval"],
                ["course_version", "course-publication approval"],
                ["minimum", "minimum reduction"],
            ]
                .map(([kind, label]) => `${kinds(kind)} ${label}${kinds(kind) === 1 ? "" : "s"}`)
                .join(" · ");
        body.append(
            node(
                "p",
                { class: "form-text" },
                `Selected publication ${data.requirements.version} · ${summary}.`,
            ),
            node(
                "p",
                { class: "form-text" },
                "Waivers create no earned credit and do not lower group quantities or choose-count rules. Approved minimum reductions are shown separately.",
            ),
        );
        if (data.cohort_changed)
            body.append(
                node(
                    "p",
                    { class: "alert alert-info", role: "status" },
                    "The matriculation cohort changed after this selection. The retained requirements stay selected until an authorized review changes them.",
                ),
            );
        const conflicts = [...new Set(Object.values(data.effective_minimums.substitution_conflicts || {}))];
        if (conflicts.length)
            body.append(
                node(
                    "section",
                    { class: "alert alert-info", role: "status" },
                    node("h2", { class: "h5" }, "Registrar review needed"),
                    node(
                        "p",
                        {},
                        "Conflicting substitutions are omitted from the adjusted preview. The published course requirement remains visible.",
                    ),
                    ...conflicts.map((message) => node("p", {}, message)),
                ),
            );
        const panel = node("section", { "aria-label": "Requirement preview" }),
            baseline = button("Published baseline", () => show(false)),
            adjusted = button("With approved exceptions", () => show(true));
        body.append(
            node(
                "div",
                { class: "audit-actions", role: "group", "aria-label": "Requirement view" },
                baseline,
                adjusted,
            ),
            panel,
        );
        function show(value) {
            withApprovals = value;
            baseline.setAttribute("aria-pressed", String(!value));
            adjusted.setAttribute("aria-pressed", String(value));
            panel.replaceChildren(
                node(
                    "p",
                    { role: "status", class: "audit-status" },
                    value
                        ? "Viewing requirements with approved exceptions."
                        : "Viewing the published baseline.",
                ),
                degreeTree(data.requirements, value ? data : null),
            );
        }
        show(withApprovals);
    }
    async function studentExceptions(studentId, assignmentId, back) {
        const base = `/sis/students/${studentId}/programs/${assignmentId}/degree-adjustments/`,
            data = await request(base);
        const restore = () => studentExceptions(studentId, assignmentId, back),
            body = screen("Student exceptions", "ONE STUDENT · ONE PROGRAM PURSUIT");
        body.append(
            node(
                "p",
                {},
                "A course waiver removes the requirement to take that course throughout this program, across every group where it appears. It creates no earned credits or GPA points. A separate degree or dual-degree pursuit has its own approvals.",
            ),
            actions(
                ui.can?.("sis:degree-audit:adjust") && ui.can?.("sis:student:read") && data.selection
                    ? button(
                          "Approve an exception",
                          () => exceptionEditor(base, data, restore),
                          "btn-primary",
                      )
                    : null,
                ...(data.selection
                    ? [
                          button("Preview requirements with approvals", () =>
                              adjustedRequirements(base, restore),
                          ),
                      ]
                    : []),
                button("Exception history", () => exceptionHistory(base, restore)),
                button("Back to degree requirements", back),
            ),
        );
        const degree = data.effective_minimums.degree;
        if (Object.keys(degree).length)
            body.append(
                node(
                    "section",
                    { class: "audit-tree" },
                    node("h2", { class: "h5" }, "Current minimums for this student"),
                    ...Object.entries(degree).map(([k, v]) =>
                        node("p", {}, `${minimumLabels[k]}: ${v === null ? "No minimum" : text(v)}`),
                    ),
                ),
            );
        const groups = groupMinimums(data);
        if (groups) body.append(groups);
        const conflicts = Object.values(data.effective_minimums.substitution_conflicts || {});
        if (conflicts.length)
            body.append(
                node(
                    "section",
                    { class: "alert alert-info", role: "status" },
                    node("h2", { class: "h5" }, "Registrar review needed"),
                    node(
                        "p",
                        {},
                        "Overlapping approvals need review before applying a substitution. An authorized registrar can review revocation below.",
                    ),
                    ...[...new Set(conflicts)].map((message) => node("p", {}, message)),
                ),
            );
        if (!data.items.length)
            body.append(
                node(
                    "p",
                    { class: "audit-empty" },
                    "No active exceptions. Published requirements remain the baseline.",
                ),
            );
        for (const p of data.items) {
            const card = node(
                "article",
                { class: "audit-library-card" },
                badge(
                    p.applicable
                        ? "Applies to this pursuit"
                        : "Retained approval · outside current requirements",
                ),
                node("h2", { class: "h5 mt-3" }, adjustmentLabel(p, data)),
            );
            if (p.definition.kind === "waiver")
                card.append(
                    node(
                        "p",
                        {},
                        "This course waiver follows the course throughout this program pursuit, without lowering credit or GPA minimums.",
                    ),
                );
            else if (p.definition.kind === "placement")
                card.append(
                    node("p", {}, "Eligible work for this course is reserved for the chosen group. Its grade, publication, substitution and repeat requirements still apply."),
                );
            else if (["substitution", "course_version"].includes(p.definition.kind))
                card.append(
                    node(
                        "p",
                        {},
                        "This approval uses the chosen course publication and applies to the reviewed degree version. It creates no earned credit or grade.",
                    ),
                );
            else
                card.append(
                    node("p", {}, "This minimum exception applies to its exact published degree version."),
                );
            if (ui.can?.("sis:degree-audit:adjust") && ui.can?.("sis:student:read"))
                card.append(button("Review revocation", () => exceptionRevoke(base, p, data, restore)));
            body.append(card);
        }
    }
    function exceptionEditor(base, data, back) {
        let body = screen("Review a student exception", "REGISTRAR APPROVAL"),
            kind = "waiver",
            selected = null,
            value = "",
            reason = "",
            query = "",
            page = 0,
            replacement = null,
            placementGroup = null,
            replacementSerial = 0;
        const restore = () => {
            const old = body;
            body = screen("Review a student exception", "REGISTRAR APPROVAL");
            body.append(...old.childNodes);
        };
        body.append(
            node(
                "p",
                {},
                "Choose the course or minimum, explain the approval, and review its exact effect before confirming. Waivers and substitutions target a course; minimum exceptions can target a degree or one group. Substitutions use an explicit published replacement.",
            ),
        );
        const [source, inspector] = workbench(body, [
            [
                "1 · Choose an exception",
                "Search this program’s published requirements. A waiver targets the course itself.",
            ],
            ["2 · Explain and review", "The approval applies only to this student-program pursuit."],
        ]);
        source.parentElement.classList.add("audit-adjustment-grid");
        const list = node("div"),
            description = node("p", { role: "status", class: "audit-status" }, "Choose a course or minimum to begin."),
            valueField = field("New required minimum", "", (v) => (value = v), { inputmode: "decimal" });
        valueField.hidden = true;
        const waiverButton = button("Course waiver", () => {
                kind = "waiver";
                selected = null;
                page = 0;
                render();
            }),
            minimumButton = button("Requirement minimum", () => {
                kind = "minimum";
                selected = null;
                page = 0;
                render();
            });
        const placementPanel = panel(
                "Choose the preferred group",
                "This reserves eligible work for this course identity to one group in this selected degree publication. It does not choose a retake, grant credit or bypass eligibility.",
            ),
            placementChoices = node("div", { class: "audit-version-list" });
        placementPanel.append(placementChoices);
        placementPanel.hidden = true;
        const placementButton = button("Course placement", () => {
            kind = "placement";
            selected = null;
            placementGroup = null;
            page = 0;
            render();
        });
        function renderPlacementGroups() {
            placementChoices.replaceChildren();
            if (!selected) {
                placementChoices.append(
                    node("p", {}, "Choose a curriculum course or approved substitute first. No group is selected automatically."),
                );
                return;
            }
            for (const group of selected.placement_groups || []) {
                const choose = button(`Use in ${group.name}`, () => {
                    placementGroup = group.id;
                    renderPlacementGroups();
                });
                choose.setAttribute("aria-pressed", String(placementGroup === group.id));
                placementChoices.append(
                    node(
                        "article",
                        { class: "audit-source-card" },
                        node("p", {}, `${group.course_code} · ${group.course_title}`),
                        ...(selected.substitution_adjustment_id
                            ? [node("p", {}, `Approved substitute version ${selected.version} for ${group.required_course_code} · ${group.required_course_title}.`)]
                            : []),
                        choose,
                    ),
                );
            }
        }
        const replacementPanel = panel(
                "Choose the course publication",
                "Choose an exact published version. A course-publication approval stays on the same course; a substitution uses a different course. This does not require completed student work.",
            ),
            replacementList = node("div"),
            replacementStatus = node(
                "p",
                { role: "status", class: "audit-status" },
                "No replacement publication selected.",
            );
        replacementPanel.hidden = true;
        replacementPanel.append(replacementStatus, replacementList);
        const substitutionButton = button("Course substitution", async () => {
            kind = "substitution";
            selected = null;
            replacement = null;
            page = 0;
            render();
            await replacementCourses();
        });
        const versionButton = button("Accept course publication", () => {
            kind = "course_version";
            selected = null;
            replacement = null;
            page = 0;
            render();
            replacementList.replaceChildren(node("p", {}, "Choose a curriculum course, then browse its publications."));
        });
        source.append(
            actions(waiverButton, substitutionButton, versionButton, placementButton, minimumButton),
            search("Search exception targets", "", (q) => {
                query = q;
                page = 0;
                render();
            }),
            list,
        );
        inspector.append(
            description,
            valueField,
            replacementPanel,
            placementPanel,
            node(
                "p",
                { class: "form-text" },
                "Enter the new required value, not the amount to subtract. A waiver never creates earned credit or changes other requirement minimums.",
            ),
            field("Reason for student exception", "", (v) => (reason = v), { maxlength: 1000 }),
            actions(
                button(
                    "Review exception",
                    async () => {
                        if (!selected || !reason.trim())
                            throw new Error("Choose an exception target and enter its approval reason.");
                        if (kind === "placement" && !placementGroup)
                            throw new Error("Choose the group that should receive this course's eligible work.");
                        if (["substitution", "course_version"].includes(kind) && !replacement)
                            throw new Error(
                                kind === "course_version"
                                    ? "Choose an explicit published version of this course."
                                    : "Choose an explicit published replacement course version.",
                            );
                        if (kind === "minimum" && selected.minimum === "min_courses" && !/^\d{1,3}$/.test(value))
                            throw new Error("Use a whole number of courses for this minimum.");
                        const definition =
                            kind === "waiver"
                                ? { kind, course_id: selected.course_id }
                                : kind === "course_version"
                                  ? { kind, course_id: selected.course_id, version_id: replacement.version_id }
                                  : kind === "placement"
                                    ? {
                                          kind,
                                          course_id: selected.course_id,
                                          group_id: placementGroup,
                                          ...(selected.substitution_adjustment_id
                                              ? { substitution_adjustment_id: selected.substitution_adjustment_id }
                                              : {}),
                                      }
                                    : kind === "substitution"
                                      ? {
                                            kind,
                                            course_id: selected.course_id,
                                            replacement_course_id: replacement.course_id,
                                            replacement_version_id: replacement.version_id,
                                        }
                                      : {
                                            kind,
                                            group_id: selected.group_id,
                                            minimum: selected.minimum,
                                            required_milli: milli(value),
                                        };
                        const reviewed = await post(`${base}review/`, { definition, reason });
                        exceptionConfirmation(base, reviewed, data, back, restore);
                    },
                    "btn-primary",
                ),
                button("Back to student exceptions", back),
            ),
        );
        function render() {
            replacementPanel.hidden = !["substitution", "course_version"].includes(kind);
            versionButton.setAttribute("aria-pressed", String(kind === "course_version"));
            placementPanel.hidden = kind !== "placement";
            placementButton.setAttribute("aria-pressed", String(kind === "placement"));
            renderPlacementGroups();
            substitutionButton.setAttribute("aria-pressed", String(kind === "substitution"));
            waiverButton.setAttribute("aria-pressed", String(kind === "waiver"));
            minimumButton.setAttribute("aria-pressed", String(kind === "minimum"));
            if (!selected) {
                valueField.hidden = true;
                description.textContent = "Choose a course or minimum to begin.";
            }
            list.replaceChildren();
            const sourceChoices = kind === "placement"
                ? (data.placement_courses || data.courses)
                : kind !== "minimum" ? data.courses : data.targets;
            const all = sourceChoices.filter((t) =>
                (kind !== "minimum" ? `${t.code} ${t.title}` : `${t.label} ${minimumLabels[t.minimum]}`)
                    .toLowerCase()
                    .includes(query.toLowerCase()),
            );
            for (const t of all.slice(page * 30, (page + 1) * 30)) {
                const label =
                    kind !== "minimum"
                        ? `${courseLabel(t)}${t.substitution_adjustment_id ? ` · Approved substitute version ${t.version} for ${t.required_course_code}` : ""}`
                        : `${t.label} · ${minimumLabels[t.minimum]} · ${text(t.original_milli)}`;
                list.append(
                    node(
                        "article",
                        { class: "audit-source-card" },
                        button(label, () => {
                            selected = t;
                            if (kind === "course_version") {
                                replacement = null;
                                replacementStatus.textContent = "No course publication selected.";
                                task(() => replacementVersions({ id: t.course_id }, replacementList), replacementPanel);
                            }
                            placementGroup = null;
                            renderPlacementGroups();
                            description.textContent =
                                kind === "waiver"
                                    ? `Waive ${courseLabel(t)} for this program. Appears in: ${t.groups.join(", ")}.`
                                    : kind === "placement"
                                      ? `Choose which retained group may use eligible ${courseLabel(t)} work. Other groups will not use this course identity while the preference is active.`
                                      : kind === "course_version"
                                        ? `Accept an additional publication of ${courseLabel(t)} for this student's selected degree. The course record and earlier publications remain unchanged.`
                                        : kind === "substitution"
                                          ? `Replace required ${courseLabel(t)}. Appears in: ${t.groups.join(", ")}.`
                                          : `${t.label} · ${minimumLabels[t.minimum]}. Published minimum: ${text(t.original_milli)}.`;
                            valueField.hidden = kind !== "minimum";
                            if (kind === "minimum") {
                                value = text(t.original_milli);
                                const input = valueField.querySelector("input");
                                input.type = "number";
                                input.min = "0";
                                input.step = t.minimum === "min_courses" ? "1" : "0.001";
                                input.max =
                                    t.minimum === "min_courses"
                                        ? String(t.original_milli / 1000 - 1)
                                        : text(t.original_milli);
                                input.inputMode = t.minimum === "min_courses" ? "numeric" : "decimal";
                                input.value = value;
                                input.focus();
                            }
                        }),
                    ),
                );
            }
            if (!all.length)
                list.append(node("p", {}, "No matching requirements. Review the selected degree publication."));
            const pages = actions();
            if (page)
                pages.append(
                    button("Previous targets", () => {
                        page--;
                        render();
                    }),
                );
            if ((page + 1) * 30 < all.length)
                pages.append(
                    button("More targets", () => {
                        page++;
                        render();
                    }),
                );
            list.append(pages);
        }
        async function replacementCourses(query = "", after = null, stack = []) {
            const serial = ++replacementSerial;
            const result = await post(`${base}replacement-courses/`, {
                ...(query ? { search: query } : {}),
                ...(after ? { after } : {}),
            });
            if (serial !== replacementSerial || !replacementPanel.isConnected) return;
            replacementList.replaceChildren(search("Replacement course search", query, (q) => replacementCourses(q)));
            replacementStatus.textContent = replacement
                ? `Chosen version ${replacement.version} · ${courseLabel(replacement)} · ${text(replacement.credits_milli)} credits`
                : "No replacement publication selected.";
            for (const course of result.items) {
                const versions = node("div", { class: "audit-version-list" }),
                    card = node(
                        "details",
                        { class: "audit-source-card" },
                        node("summary", {}, `${courseLabel(course)} · catalog entry`),
                        versions,
                    );
                let loaded = false;
                card.addEventListener("toggle", () => {
                    if (card.open && !loaded) {
                        loaded = true;
                        task(async () => {
                            try {
                                await replacementVersions(course, versions);
                            } catch (error) {
                                loaded = false;
                                fail(error);
                            }
                        }, card);
                    }
                });
                replacementList.append(card);
            }
            if (!result.items.length)
                replacementList.append(node("p", {}, "No matching catalog entries. Try a different course code or title."));
            pager(replacementList, result, after, stack, (next, previous) => replacementCourses(query, next, previous));
        }
        async function replacementVersions(course, target, cursor = null, stack = []) {
            const expectedCourse = kind === "course_version" ? selected?.course_id : null;
            const result = await post(`${base}replacement-courses/${course.id}/versions/`, cursor ? { cursor } : {});
            if (
                !target.isConnected ||
                (expectedCourse !== null && (kind !== "course_version" || selected?.course_id !== expectedCourse))
            )
                return;
            target.replaceChildren();
            for (const version of result.items)
                target.append(
                    node(
                        "article",
                        { class: "audit-version-card" },
                        node("p", {}, `${courseLabel(version)} · ${text(version.credits_milli)} credits`),
                        button(
                            `${kind === "course_version" ? "Accept publication" : "Select replacement"} version ${version.version}`,
                            () => {
                                replacement = version;
                                replacementStatus.textContent = `Chosen version ${version.version} · ${courseLabel(version)} · ${text(version.credits_milli)} credits`;
                            },
                        ),
                    ),
                );
            if (!result.items.length)
                target.append(
                    node(
                        "p",
                        {},
                        "No published versions. Catalog staff must approve a course publication before it can be a replacement.",
                    ),
                );
            pager(
                target,
                result,
                cursor,
                stack,
                (next, previous) => replacementVersions(course, target, next, previous),
                "next_cursor",
            );
        }
        render();
    }
    function exceptionConfirmation(base, reviewed, data, back, change) {
        const body = screen(
            reviewed.action === "revoke" ? "Confirm exception revocation" : "Confirm student exception",
        );
        const p = reviewed.action === "revoke" ? reviewed.previous : reviewed.adjustment;
        body.append(
            node(
                "h2",
                { class: "h4" },
                adjustmentLabel(p, {
                    ...data,
                    substitution_details: { ...data.substitution_details, ...reviewed.substitution_details },
                }),
            ),
            node("p", {}, `Reason: ${reviewed.reason}`),
        );
        if (reviewed.previous && reviewed.action === "grant")
            body.append(
                node(
                    "p",
                    { class: "alert alert-info" },
                    "This replaces the earlier active approval for this target. Its decision history is retained.",
                ),
            );
        if (p.definition.kind === "waiver")
            body.append(
                node(
                    "p",
                    {},
                    reviewed.action === "revoke"
                        ? "Revoking this waiver restores the course requirement wherever it applies in this program."
                        : `This course waiver applies throughout this program. It currently appears in: ${reviewed.affected_groups.join(", ")}. Earned credit, grades and GPA points stay unchanged.`,
                ),
            );
        if (p.definition.kind === "placement")
            body.append(
                node(
                    "p",
                    {},
                    reviewed.action === "revoke"
                        ? "Revoking this preference restores normal eligible group choices under the retained sharing policy. No course is applied automatically."
                        : "Eligible work for this course will be considered only in the chosen group for this selected degree publication. Other courses retain their normal sharing rules. This does not guarantee a complete placement, override a grade/publication requirement, or change earned credits, GPA or charges. The same rule applies to eligible retake evidence; it does not select a particular attempt.",
                ),
            );
        if (p.definition.kind === "placement" && p.definition.substitution_adjustment_id)
            body.append(
                node("p", {}, "This placement was reviewed against an existing substitution approval. Course eligibility still requires a current approval; this separate group restriction remains until replaced or revoked. It never approves a substitute by itself."),
            );
        if (["substitution","course_version"].includes(p.definition.kind)) {
            const selected =
                reviewed.substitution_details?.[p.adjustment_id] ||
                data.substitution_details?.[p.adjustment_id];
            body.append(
                node(
                    "p",
                    {},
                    p.definition.kind === "course_version"
                        ? (reviewed.action === "revoke" ? "This decision removes only this student's additional publication allowance. It preserves the accepted course record and earlier approval." : "This publication may satisfy the same course's named requirements in this selected degree. Other publication approvals remain separate. Course awards, credits, grades and transcript labels are preserved.")
                    : reviewed.action === "revoke"
                        ? "Revoking this substitution restores the original course requirement. Earned coursework and grades are preserved."
                        : "This replacement may satisfy the original course requirement in the reviewed program. Earned credit and grades come from actual coursework; this approval creates neither.",
                ),
            );
            if (selected)
                body.append(
                    node(
                        "p",
                        {},
                        `Chosen ${p.definition.kind === "course_version" ? "course" : "replacement"} publication: version ${selected.version} · ${courseLabel(selected)} · ${text(selected.credits_milli)} credits`,
                    ),
                );
        }
        body.append(
            node(
                "section",
                { class: "audit-tree" },
                node("h2", { class: "h5" }, "Degree minimums after this decision"),
                ...Object.entries(reviewed.effective_minimums.degree).map(([k, v]) =>
                    node("p", {}, `${minimumLabels[k]}: ${v === null ? "No minimum" : text(v)}`),
                ),
            ),
            ...[
                groupMinimums(reviewed, "Group minimums after this decision"),
                reviewed.adjustment.definition?.minimum === "min_courses"
                    ? node(
                          "p",
                          { class: "form-text" },
                          "Required course lists and choose-count rules remain in force. This changes only the group's course-count minimum.",
                      )
                    : reviewed.adjustment.definition?.minimum === "min_course_grade_points_milli"
                      ? node(
                            "p",
                            { class: "form-text" },
                            "This changes the per-course grade-point threshold for this group. Recorded grades, grading scales and other groups retain their own values.",
                        )
                      : null,
            ].filter(Boolean),
            actions(
                button(
                    reviewed.action === "revoke" ? "Revoke reviewed exception" : "Approve reviewed exception",
                    async () => {
                        await post(`${base}commit/`, { review_token: reviewed.review_token });
                        await back();
                    },
                    "btn-primary",
                ),
                button("Change review", change),
                button("Back to student exceptions", back),
            ),
        );
    }
    function exceptionRevoke(base, p, data, back) {
        let reason = "",
            body = screen("Review exception revocation");
        const restore = () => {
            const old = body;
            body = screen("Review exception revocation");
            body.append(...old.childNodes);
        };
        body.append(
            node("h2", { class: "h4" }, adjustmentLabel(p, data)),
            node(
                "p",
                {},
                "Revocation retains the approval and records a new decision. Published requirements and other student programs remain unchanged.",
            ),
            field("Reason for revocation", "", (v) => (reason = v), { maxlength: 1000 }),
            actions(
                button(
                    "Prepare revocation",
                    async () => {
                        if (!reason.trim()) throw new Error("Enter a reason for revocation.");
                        exceptionConfirmation(
                            base,
                            await post(`${base}revoke-review/`, { adjustment_id: p.adjustment_id, reason }),
                            data,
                            back,
                            restore,
                        );
                    },
                    "btn-primary",
                ),
                button("Back to student exceptions", back),
            ),
        );
    }
    async function exceptionHistory(base, back, cursor = null, stack = []) {
        const data = await post(`${base}history/`, cursor ? { cursor } : {}),
            body = screen("Student exception history");
        body.append(
            node(
                "p",
                {},
                "Approvals, replacements and revocations remain recorded. This view contains decision references without protected approval narratives.",
            ),
        );
        for (const p of data.items)
            body.append(
                node(
                    "article",
                    { class: "audit-library-card" },
                    node(
                        "h2",
                        { class: "h5" },
                        `Decision ${p.version} · ${p.action === "grant" ? "Approved" : "Revoked"}`,
                    ),
                    p.definition ? node("p", {}, adjustmentLabel(p, null)) : null,
                    node("p", {}, `Effective ${p.effective_at}. Recorded ${p.recorded_at}.`),
                ),
            );
        if (!data.items.length) body.append(node("p", {}, "No exception decisions yet."));
        pager(
            body,
            data,
            cursor,
            stack,
            (next, previous) => exceptionHistory(base, back, next, previous),
            "next_cursor",
        );
        body.append(button("Back to student exceptions", back));
    }
    async function gradingContext(select, back, query = "") {
        const rows = await post("/sis/ui/data/programs/", query ? { search: query } : {});
        const body = screen("Choose a grading context");
        body.append(
            node(
                "p",
                {},
                "This only selects the scale used to author this reusable group. The group has no program owner; each degree uses its own resolved scale.",
            ),
            button("Use global grading scale", async () =>
                select(null, (await request("/sis/grading-scales/global/")).scale),
            ),
            search("Find a program grading context", query, (q) => gradingContext(select, back, q)),
        );
        for (const p of rows.items)
            body.append(
                button(`Use ${p.code} grading context`, async () =>
                    select(p.id, (await request(`/sis/programs/${p.id}/grading-scale/`)).scale),
                ),
            );
        body.append(button("Back to group draft", back));
    }
    async function gradingScale(program = null, back = null, state = null) {
        const base = program ? `/sis/programs/${program}/grading-scale/` : "/sis/grading-scales/global/";
        const data = state?.data || (await request(base));
        let rows =
            state?.rows ||
            data.scale.definition.grades.map((g) => ({
                ...g,
                min_score_milli: text(g.min_score_milli),
                max_score_milli: text(g.max_score_milli),
                points_milli: text(g.points_milli),
            }));
        let inherit = state?.inherit ?? Boolean(program && data.scale.source !== "program");
        let reason = state?.reason || "",
            active = state?.active ?? 0;
        const restore = () => gradingScale(program, back, { data, rows, inherit, reason, active });
        const body = screen(program ? "Program grading scale" : "Global grading scale", "GRADING POLICY");
        let validationErrors = [];
        function markScaleErrors() {
            for (const input of dialog.querySelectorAll("input[name]")) {
                const errorId = `${input.id}-scale-error`;
                document.getElementById(errorId)?.remove();
                input.classList.remove("is-invalid");
                input.removeAttribute("aria-invalid");
                const descriptions = (input.getAttribute("aria-describedby") || "")
                    .split(" ")
                    .filter((id) => id && id !== errorId);
                const error = validationErrors.find((entry) => entry.field === input.name);
                if (error) {
                    input.classList.add("is-invalid");
                    input.setAttribute("aria-invalid", "true");
                    descriptions.push(errorId);
                    input.parentElement.append(
                        node(
                            "p",
                            { id: errorId, class: "invalid-feedback d-block audit-field-error" },
                            error.message,
                        ),
                    );
                }
                if (descriptions.length) input.setAttribute("aria-describedby", descriptions.join(" "));
                else input.removeAttribute("aria-describedby");
            }
        }
        function clearScaleErrors() {
            validationErrors = [];
            markScaleErrors();
            dialog.querySelector('[role="alert"]').hidden = true;
        }
        function showScaleErrors(error) {
            validationErrors = error.fields || [];
            const firstGrade = validationErrors
                .map((entry) => /^definition\.grades\.(\d+)\./.exec(entry.field))
                .find(Boolean);
            if (firstGrade && rows[Number(firstGrade[1])]) {
                active = Number(firstGrade[1]);
                render();
            } else markScaleErrors();
            fail(error);
        }

        body.append(
            node(
                "p",
                {},
                program
                    ? "This program uses the global scale until you approve a complete program override. Other programs are unaffected."
                    : "The global scale applies to programs without an override. Grade-point maximums are derived from the grade definitions.",
            ),
        );
        const [source, canvas, inspector] = workbench(body, [
            ["Grades", "Select a grade to edit its score range and GPA treatment."],
            [
                "Scale preview",
                "Score percentages and GPA points are separate values. Ranges are inclusive; gaps require manual grading.",
            ],
            [
                "Grade settings",
                "Leave both score boundaries blank for administrative grades without a percentage range.",
            ],
        ]);
        const preview = node("div", { class: "audit-tree" });
        canvas.append(preview);
        function render() {
            source.replaceChildren(node("h2", { class: "h5" }, "Grades"));
            const limit = 20,
                start = Math.floor(active / limit) * limit;
            for (let i = start; i < Math.min(rows.length, start + limit); i++)
                source.append(
                    button(`Edit grade ${rows[i].code || i + 1}`, () => {
                        active = i;
                        render();
                    }),
                );
            if (start)
                source.append(
                    button("Previous grades", () => {
                        active = start - limit;
                        render();
                    }),
                );
            if (start + limit < rows.length)
                source.append(
                    button("Next grades", () => {
                        active = start + limit;
                        render();
                    }),
                );
            source.append(
                button("Add grade", () => {
                    if (rows.length >= 200) throw new Error("A scale supports at most 200 grades.");
                    inherit = false;
                    rows.push({
                        code: "",
                        min_score_milli: "",
                        max_score_milli: "",
                        points_milli: "",
                        passes: false,
                        counts_gpa: true,
                    });
                    active = rows.length - 1;
                    render();
                }),
            );
            if (program)
                source.append(
                    button(inherit ? "Create program override" : "Use global scale", () => {
                        inherit = !inherit;
                        if (!inherit && !rows.length)
                            rows = data.global_scale.definition.grades.map((g) => ({
                                ...g,
                                min_score_milli: text(g.min_score_milli),
                                max_score_milli: text(g.max_score_milli),
                                points_milli: text(g.points_milli),
                            }));
                        render();
                    }),
                );
            inspector.replaceChildren(node("h2", { class: "h5" }, "Grade settings"));
            const grade = rows[active];
            if (grade && !inherit) {
                for (const [key, label, options] of [
                    ["code", "Grade", { maxlength: 12 }],
                    ["min_score_milli", "Minimum score (%)", { inputmode: "decimal" }],
                    ["max_score_milli", "Maximum score (%)", { inputmode: "decimal" }],
                    ["points_milli", "Grade points", { inputmode: "decimal" }],
                ])
                    inspector.append(
                        field(
                            label,
                            grade[key],
                            (v) => {
                                grade[key] = key === "code" ? v.toUpperCase() : v;
                                clearScaleErrors();
                                summary();
                            },
                            {
                                ...options,
                                name: `definition.grades.${active}.${key}`,
                                required: key === "code" || key === "points_milli",
                            },
                        ),
                    );
                for (const [key, label] of [
                    ["passes", "Earns course credit"],
                    ["counts_gpa", "Count in GPA"],
                ]) {
                    const ident = `scale-choice-${++sequence}`;
                    inspector.append(
                        node(
                            "div",
                            { class: "form-check mb-3" },
                            node("input", {
                                type: "checkbox",
                                id: ident,
                                class: "form-check-input",
                                checked: grade[key],
                                onchange: (e) => {
                                    grade[key] = e.target.checked;
                                    summary();
                                },
                            }),
                            node("label", { for: ident, class: "form-check-label" }, label),
                        ),
                    );
                }
                inspector.append(
                    button("Remove selected grade", () => {
                        rows.splice(active, 1);
                        active = Math.max(0, active - 1);
                        render();
                    }),
                );
            }
            summary();
            markScaleErrors();
        }
        function summary() {
            const visible = inherit
                ? data.global_scale.definition.grades.map((g) => ({
                      ...g,
                      min_score_milli: text(g.min_score_milli),
                      max_score_milli: text(g.max_score_milli),
                      points_milli: text(g.points_milli),
                  }))
                : rows;
            const maximum = rows.length ? Math.max(...rows.map((g) => Number(g.points_milli) || 0)) : 4;
            preview.replaceChildren(
                node(
                    "p",
                    {},
                    inherit
                        ? "Using the institution’s global scale."
                        : `Draft maximum: ${maximum.toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 3 })} grade points.`,
                ),
            );
            for (const g of visible.slice(Math.floor(active / 20) * 20, Math.floor(active / 20) * 20 + 20))
                preview.append(
                    node(
                        "p",
                        {},
                        `${g.code || "New grade"}: ${g.min_score_milli === "" ? "No percentage range" : `${g.min_score_milli}–${g.max_score_milli}%`} · ${g.points_milli || "0"} grade points · ${g.counts_gpa ? "Counts in GPA" : "Excluded from GPA"} · ${g.passes ? "Earns credit" : "No earned credit"}`,
                    ),
                );
        }
        body.append(
            field(
                "Reason for grading scale change",
                reason,
                (v) => {
                    reason = v;
                    clearScaleErrors();
                },
                { maxlength: 1000, required: true, name: "reason", "aria-describedby": "scale-reason-help" },
            ),
            node(
                "p",
                { id: "scale-reason-help", class: "form-text" },
                "Required. Briefly explain why this grading policy is being set or changed.",
            ),
            actions(
                button(
                    "Review grading scale",
                    async () => {
                        let reviewed;
                        try {
                            clearScaleErrors();
                            if (!reason.trim()) {
                                const error = new Error("Enter a reason for this grading policy change.");
                                error.fields = [{ field: "reason", message: error.message }];
                                throw error;
                            }
                            const definition = inherit
                                ? null
                                : {
                                      grades: rows.map((g) => ({
                                          code: g.code.trim(),
                                          min_score_milli: milli(g.min_score_milli, true),
                                          max_score_milli: milli(g.max_score_milli, true),
                                          points_milli: milli(g.points_milli),
                                          passes: g.passes,
                                          counts_gpa: g.counts_gpa,
                                      })),
                                  };
                            reviewed = await post(base + "review/", { definition, reason });
                        } catch (error) {
                            showScaleErrors(error);
                            return;
                        }
                        const confirmation = screen("Review grading scale");
                        confirmation.append(
                            node(
                                "p",
                                {},
                                "Approve only after checking every grade and range. Published degree requirements retain their previous scale.",
                            ),
                        );
                        if (!reviewed.definition)
                            confirmation.append(
                                node("p", {}, "Restore this program’s use of the global scale."),
                            );
                        else
                            for (const g of reviewed.definition.grades)
                                confirmation.append(
                                    node(
                                        "p",
                                        {},
                                        `${g.code}: ${g.min_score_milli == null ? "No percentage range" : `${text(g.min_score_milli)}–${text(g.max_score_milli)}%`} · ${text(g.points_milli)} grade points · ${g.counts_gpa ? "Counts in GPA" : "Excluded from GPA"}`,
                                    ),
                                );
                        confirmation.append(
                            node("p", {}, reviewed.reason),
                            actions(
                                button("Change scale review", restore),
                                button(
                                    "Approve reviewed grading scale",
                                    async () => {
                                        await post(base + "commit/", { review_token: reviewed.review_token });
                                        await gradingScale(program, back);
                                    },
                                    "btn-primary",
                                ),
                            ),
                        );
                    },
                    "btn-primary",
                ),
                back ? button("Back to degree draft", back) : null,
            ),
        );
        render();
    }
    return { launch, library, studentRequirements, gradingScale };
};
