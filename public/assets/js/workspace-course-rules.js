/* Course eligibility authoring uses selected identities and explicit publication previews. No student data. */
window.createWorkspaceCourseRules = function (ui) {
    "use strict";
    const { node, request, task, openDrawer, closeDrawer, table } = ui;
    const labels = {
        course_id: "Course",
        subject_id: "Subject",
        department_id: "Department",
        academic_level_id: "Academic level",
        course_level_id: "Course level",
    };
    const root = document.getElementById("course-rule-studio");
    let mounted = false,
        selected = null,
        references = {},
        publications = [],
        generation = 0;
    let code, title, status, results, saved, builder;
    const widgets = new WeakMap();
    let conditionRevision = 0;
    const post = (path, body = {}) => request(path, "POST", body);
    const button = (label, run, style = "btn-outline-primary") =>
        node(
            "button",
            {
                type: "button",
                class: `btn ${style}`,
                onclick: (e) => task(run, e.currentTarget),
            },
            label,
        );
    const field = (label, id, maximum) => {
        const input = node("input", {
            id,
            class: "form-control",
            maxlength: maximum,
        });
        return [
            node(
                "div",
                { class: "mb-3" },
                node("label", { for: id, class: "form-label" }, label),
                input,
            ),
            input,
        ];
    };
    const tree = () => {
        const value = builder.queryBuilder("getRules");
        if (!value)
            throw new Error(
                "Complete each course-rule condition before continuing.",
            );
        return value;
    };
    function clearResults() {
        results.replaceChildren();
    }
    function accessible() {
        builder.find("i").attr("aria-hidden", "true");
        builder
            .find(".rule-filter-container select")
            .attr("aria-label", "Course condition field");
        builder
            .find(".rule-operator-container select")
            .attr("aria-label", "Course condition comparison");
        builder
            .find(".rule-value-container input")
            .attr("aria-label", "Numeric course level");
        builder.find(".rules-group-header input").each(function () {
            this.setAttribute(
                "aria-label",
                this.value === "AND"
                    ? "Match all course conditions"
                    : "Match any course condition",
            );
        });
    }
    async function catalog(
        fieldName,
        choose,
        search = "",
        after = null,
        stack = [],
    ) {
        const path =
            fieldName === "course_id"
                ? "/sis/ui/data/courses/"
                : fieldName === "subject_id"
                  ? "/sis/catalog/subjects/"
                  : `/sis/catalog/classifications/${fieldName.replace(/_id$/, "")}/page/`;
        const data = await post(path, { search, ...(after ? { after } : {}) });
        const body = openDrawer(
            `Choose ${labels[fieldName].toLowerCase()}`,
            "COURSE RULE",
        );
        const [control, input] = field(
            `Find ${labels[fieldName].toLowerCase()}`,
            "course-rule-reference-search",
            80,
        );
        input.value = search;
        body.append(
            control,
            button("Search references", () =>
                catalog(fieldName, choose, input.value.trim()),
            ),
            node(
                "p",
                { class: "form-text" },
                "Select a catalog record. Existing saved revisions keep their earlier labels.",
            ),
        );
        for (const row of data.items)
            body.append(
                button(`${row.code} — ${row.title}`, () => {
                    closeDrawer();
                    return choose(row);
                }),
            );
        if (!data.items.length)
            body.append(node("p", {}, "No matching references."));
        if (stack.length)
            body.append(
                button("Previous references", () =>
                    catalog(
                        fieldName,
                        choose,
                        search,
                        stack.at(-1),
                        stack.slice(0, -1),
                    ),
                ),
            );
        if (data.next_after)
            body.append(
                button("More references", () =>
                    catalog(fieldName, choose, search, data.next_after, [
                        ...stack,
                        after,
                    ]),
                ),
            );
    }
    function identityInput(rule, fieldName) {
        const container = node("div", {
            class: "d-flex flex-wrap gap-2 align-items-center",
        });
        const chosen = node("div", { class: "d-flex flex-wrap gap-2" });
        const state = {
            ids: [],
            draw: () => {
                chosen.replaceChildren();
                for (const id of state.ids) {
                    const value = references[fieldName]?.[id];
                    chosen.append(
                        node(
                            "span",
                            {
                                class: "badge text-bg-light text-wrap text-start",
                            },
                            value
                                ? `${value.code} — ${value.title}`
                                : "Selected reference",
                        ),
                        button(
                            `Remove ${value?.code || "reference"}`,
                            () => {
                                state.ids = state.ids.filter(
                                    (item) => item !== id,
                                );
                                rule.value = rule.operator.multiple
                                    ? state.ids
                                    : state.ids[0] || null;
                                state.draw();
                                clearResults();
                            },
                            "btn-outline-secondary btn-sm",
                        ),
                    );
                }
            },
        };
        widgets.set(rule, state);
        container.append(
            chosen,
            button(`Choose ${labels[fieldName].toLowerCase()}`, () =>
                catalog(fieldName, (value) => {
                    references[fieldName] ||= {};
                    references[fieldName][value.id] = value;
                    state.ids = rule.operator.multiple
                        ? [...new Set([...state.ids, value.id])]
                        : [value.id];
                    if (state.ids.length > 20) {
                        state.ids.pop();
                        throw new Error(
                            "Select at most 20 references per condition.",
                        );
                    }
                    rule.value = rule.operator.multiple ? state.ids : value.id;
                    state.draw();
                    clearResults();
                }),
            ),
        );
        return container;
    }
    function configure() {
        builder = window.jQuery("#course-rule-builder");
        const operators = ["equal", "not_equal", "in", "not_in"];
        builder.queryBuilder({
            allow_groups: 4,
            allow_empty: false,
            plugins: [],
            filters: [
                ...Object.entries(labels).map(([id, label]) => ({
                    id,
                    label,
                    type: "string",
                    operators,
                    input: () => '<div class="course-rule-identity"></div>',
                    valueGetter: (rule) => {
                        const ids = widgets.get(rule)?.ids || [];
                        return rule.operator.multiple ? ids : ids[0] || null;
                    },
                    valueSetter: (rule, value) => {
                        const widget = widgets.get(rule);
                        if (widget) {
                            widget.ids =
                                value == null
                                    ? []
                                    : Array.isArray(value)
                                      ? value
                                      : [value];
                            widget.draw();
                        }
                    },
                })),
                {
                    id: "course_level_number",
                    label: "Numeric course level",
                    type: "integer",
                    validation: { min: 0, max: 999999 },
                    operators: [...operators, "less", "greater"],
                },
            ],
            lang: {
                add_rule: "Add course condition",
                add_group: "Add condition group",
                conditions: { AND: "All (AND)", OR: "Any (OR)" },
            },
        });
        builder.on(
            "afterCreateRuleFilters.queryBuilder afterCreateRuleOperators.queryBuilder afterCreateRuleInput.queryBuilder afterSetRules.queryBuilder",
            accessible,
        );
        builder.on("afterCreateRuleInput.queryBuilder", (event, rule) => {
            if (labels[rule.filter?.id]) {
                rule.$el
                    .find(".course-rule-identity")[0]
                    .replaceChildren(identityInput(rule, rule.filter.id));
            }
        });
        const changed = () => {
            conditionRevision++;
            clearResults();
        };
        builder.on("rulesChanged.queryBuilder", changed);
        builder.on("input change", "input, select", changed);
        accessible();
    }
    async function library(search = "", after = null, stack = []) {
        const data = await post("/sis/course-rules/page/", {
            search,
            ...(after ? { after } : {}),
        });
        saved.replaceChildren();
        for (const item of data.items)
            saved.append(
                button(
                    `${item.code} — ${item.title} · Revision ${item.revision}`,
                    () => openRevision(item.id),
                ),
            );
        if (!data.items.length)
            saved.append(node("p", {}, "No matching saved course rules."));
        if (stack.length)
            saved.append(
                button("Previous course rules", () =>
                    library(search, stack.at(-1), stack.slice(0, -1)),
                ),
            );
        if (data.next_after)
            saved.append(
                button("More course rules", () =>
                    library(search, data.next_after, [...stack, after]),
                ),
            );
    }
    function reset() {
        generation++;
        selected = null;
        references = {};
        publications = [];
        code.value = title.value = "";
        code.disabled = false;
        builder.queryBuilder("reset");
        status.textContent =
            "New course rule. Choose catalog references to build conditions.";
        renderPublications();
        clearResults();
        code.focus();
    }
    async function openRevision(id) {
        const token = ++generation;
        const value = await request(`/sis/course-rules/${id}/`);
        if (token !== generation) return;
        selected = value;
        references = structuredClone(value.definition.references);
        code.value = value.code;
        code.disabled = true;
        title.value = value.title;
        builder.queryBuilder("setRules", value.definition.tree);
        status.textContent = `${value.code} · Saved revision ${value.revision}. Saving changes creates another revision.`;
        clearResults();
        accessible();
    }
    async function history(before = null) {
        if (!selected) throw new Error("Open a saved course rule first.");
        const rule = selected.rule_id;
        const data = await post(
            `/sis/course-rules/${rule}/revisions/`,
            before ? { before } : {},
        );
        const body = openDrawer(
            "Saved course-rule revisions",
            "RETAINED DEFINITIONS",
        );
        for (const item of data.items)
            body.append(
                button(
                    `${item.code} — ${item.title} · Revision ${item.revision}`,
                    async () => {
                        await openRevision(item.id);
                        closeDrawer();
                    },
                ),
            );
        if (data.next_before)
            body.append(
                button("Earlier rule revisions", () =>
                    history(data.next_before),
                ),
            );
    }
    async function save() {
        const snapshot = { tree: tree(), code: code.value, title: title.value };
        const conditionToken = conditionRevision;
        const token = generation;
        const value = await post("/sis/course-rules/save/", {
            ...snapshot,
            rule_id: selected?.rule_id || null,
            expected_revision: selected?.revision || null,
        });
        if (token !== generation) return;
        selected = value;
        code.disabled = true;
        const changed =
            conditionToken !== conditionRevision ||
            snapshot.title !== title.value ||
            snapshot.code !== code.value;
        status.textContent = `${value.code} · Revision ${value.revision} saved. Earlier revisions are unchanged.${changed ? " Your later edits remain unsaved." : ""}`;
        await library();
    }
    async function publicationChoices(course, cursor = null) {
        const data = await post(
            `/sis/courses/${course.id}/publication/page/`,
            cursor ? { cursor } : {},
        );
        const body = openDrawer(
            `Preview ${course.code}`,
            "CHOOSE A RETAINED PUBLICATION",
        );
        for (const item of data.items)
            body.append(
                button(
                    `Use publication ${item.version} — ${item.code} — ${item.title}`,
                    () => {
                        const reference = {
                            course_id: course.id,
                            version_id: item.version_id,
                            label: `${item.code} — ${item.title} · Publication ${item.version}`,
                        };
                        if (
                            !publications.some(
                                (p) =>
                                    p.course_id === reference.course_id &&
                                    p.version_id === reference.version_id,
                            )
                        ) {
                            if (publications.length >= 20)
                                throw new Error(
                                    "Preview at most 20 publications at once.",
                                );
                            publications.push(reference);
                        }
                        renderPublications();
                        clearResults();
                        closeDrawer();
                    },
                ),
            );
        if (!data.items.length)
            body.append(
                node(
                    "p",
                    {},
                    "Publish this course before using it in a preview.",
                ),
            );
        if (data.next_cursor)
            body.append(
                button("More course publications", () =>
                    publicationChoices(course, data.next_cursor),
                ),
            );
    }
    function renderPublications() {
        const list = root.querySelector("#course-rule-publications");
        list.replaceChildren();
        publications.forEach((item, i) =>
            list.append(
                node(
                    "li",
                    {},
                    item.label,
                    " ",
                    button(
                        `Remove preview publication ${i + 1}`,
                        () => {
                            publications.splice(i, 1);
                            renderPublications();
                            clearResults();
                        },
                        "btn-outline-secondary btn-sm",
                    ),
                ),
            ),
        );
    }
    async function preview() {
        const conditionToken = conditionRevision;
        const snapshot = {
            tree: tree(),
            publications: publications.map(({ course_id, version_id }) => ({
                course_id,
                version_id,
            })),
        };
        if (!snapshot.publications.length)
            throw new Error("Choose a course publication to preview first.");
        const token = generation;
        const result = await post("/sis/course-rules/preview/", snapshot);
        if (
            token !== generation ||
            conditionToken !== conditionRevision ||
            conditionToken !== conditionRevision ||
            JSON.stringify(snapshot.publications) !==
                JSON.stringify(
                    publications.map(({ course_id, version_id }) => ({
                        course_id,
                        version_id,
                    })),
                )
        )
            return;
        const outcomes = {
            matches: "Matches",
            does_not_match: "Does not match",
            unknown:
                "Needs review — retained classification is missing or not numeric",
        };
        results.replaceChildren(
            node("h3", { class: "h5" }, "Selected publication preview"),
            table(
                ["Course", "Retained title", "Eligibility"],
                result.items.map((item) => [
                    item.code,
                    item.title,
                    outcomes[item.outcome],
                ]),
                "Course rule preview",
            ),
        );
    }
    async function open() {
        if (!ui.can("sis:rules:manage") || !ui.can("sis:catalog:manage"))
            throw new Error(
                "Course rules need rule and catalog management access.",
            );
        if (mounted) return;
        mounted = true;
        const [codeField, codeInput] = field(
            "Course rule code",
            "course-rule-code",
            80,
        );
        const [titleField, titleInput] = field(
            "Course rule title",
            "course-rule-title",
            191,
        );
        code = codeInput;
        title = titleInput;
        const [searchField, searchInput] = field(
            "Find course rules by code",
            "course-rule-search",
            80,
        );
        saved = node("div", { class: "d-flex flex-column gap-2" });
        status = node("p", { role: "status" });
        results = node("div", { class: "mt-3" });
        root.append(
            node(
                "div",
                { class: "surface p-4" },
                node("h2", {}, "Course eligibility rules"),
                node(
                    "p",
                    {},
                    "Build subject, department and course conditions. Exclude specific courses or compare a numeric course level. Saved revisions do not change earlier definitions.",
                ),
                codeField,
                titleField,
                node(
                    "p",
                    { class: "form-text", id: "course-rule-help" },
                    "Numeric comparisons use the retained course-level definition's numeric code, never the course number. Missing classifications remain unknown. This preview does not award credit or evaluate a student.",
                ),
                node("div", {
                    id: "course-rule-builder",
                    "aria-describedby": "course-rule-help",
                }),
                node(
                    "div",
                    { class: "d-flex flex-wrap gap-2 my-3" },
                    button("Save course-rule revision", save, "btn-primary"),
                    button("New course rule", reset),
                    button("Browse saved revisions", () => history()),
                ),
                status,
                node(
                    "h3",
                    { class: "h5 mt-4" },
                    "Try selected course publications",
                ),
                button("Choose preview course", () =>
                    catalog("course_id", publicationChoices),
                ),
                node("ul", { id: "course-rule-publications", class: "mt-3" }),
                button("Preview selected publications", preview),
                results,
            ),
            node(
                "section",
                { class: "surface p-4 mt-3" },
                node("h2", { class: "h4" }, "Saved course rules"),
                searchField,
                button("Search course rules", () =>
                    library(searchInput.value.trim()),
                ),
                saved,
            ),
        );
        configure();
        reset();
        await library();
    }
    return { open };
};
