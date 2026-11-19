/* Relational Report Studio: explicit field/relationship choices, live AJAX preview and versioned saves. */
window.createWorkspaceReportStudio = function (ui) {
    "use strict";
    const { node, request, requestFile, task, can, table } = ui;
    let widgets = [],
        chart = null,
        dialog = null,
        opener = null,
        releaseScreen = () => {};
    let lastTrigger = null;
    document.addEventListener(
        "click",
        (event) => {
            if (!dialog) lastTrigger = event.target.closest?.("button, a") || lastTrigger;
        },
        true,
    );
    const icon = (name) => node("i", { class: `bi bi-${name}`, "aria-hidden": "true" });
    function localError(error) {
        const alert = dialog?.querySelector(".report-local-error");
        if (!alert) throw error;
        alert.textContent = error.message || "The request could not be completed.";
        if (error.fields?.length)
            alert.append(
                node(
                    "ul",
                    {},
                    error.fields.map((item) => node("li", {}, item.message)),
                ),
            );
        alert.hidden = false;
        alert.focus();
    }
    const button = (label, callback, style = "btn-outline-primary", afterTask = null) =>
        node(
            "button",
            {
                type: "button",
                class: `btn ${style}`,
                onclick: async (event) => {
                    await task(async () => {
                        const alert = dialog?.querySelector(".report-local-error");
                        if (alert) alert.hidden = true;
                        try {
                            return await callback();
                        } catch (error) {
                            localError(error);
                        }
                    }, event.currentTarget);
                    afterTask?.();
                },
            },
            label,
        );
    const grid = (headers, rows, label) =>
        node(
            "div",
            {
                class: "table-responsive",
                tabindex: "0",
                role: "region",
                "aria-label": label,
            },
            table(headers, rows, label),
        );
    const escapeLabel = (value) =>
        String(value).replace(
            /[&<>"']/g,
            (char) =>
                ({
                    "&": "&amp;",
                    "<": "&lt;",
                    ">": "&gt;",
                    '"': "&quot;",
                    "'": "&#39;",
                })[char],
        );
    const rowSelectionUi = window.createReportRowSelection({ node, button, labeled });
    function destroyWidgets() {
        widgets.forEach((widget) => {
            try {
                widget.queryBuilder("destroy");
            } catch {}
        });
        widgets = [];
    }
    function cleanup(restoreFocus = true) {
        destroyWidgets();
        releaseScreen();
        releaseScreen = () => {};
        chart?.destroy();
        chart = null;
        const previous = dialog;
        dialog = null;
        if (previous) {
            ui.disposeActionMenus?.(previous);
            previous.close();
            previous.remove();
        }
        if (restoreFocus && previous) {
            if (opener?.isConnected) opener.focus({ preventScroll: true });
            else document.querySelector("#directory-tabs button, #workspace-main")?.focus({ preventScroll: true });
        }
    }
    function openSpace(title, eyebrow) {
        const launch = dialog ? opener : lastTrigger || document.activeElement;
        cleanup(false);
        opener = launch;
        ui.closeDrawer?.();
        dialog = node("dialog", { class: "report-workbench report-studio", "aria-labelledby": "report-studio-title" });
        const current = dialog;
        const close = button("Back to reports", () => cleanup(), "btn-outline-primary");
        close.prepend(icon("arrow-left"));
        const header = node(
            "header",
            { class: "report-workbench-heading" },
            node(
                "div",
                {},
                node("p", { class: "report-workbench-eyebrow" }, eyebrow),
                node("h1", { id: "report-studio-title" }, title),
            ),
            close,
        );
        const alert = node("div", {
            class: "alert alert-danger report-local-error",
            role: "alert",
            tabindex: "-1",
            hidden: true,
        });
        const body = node("div", { class: "report-workbench-body" });
        dialog.append(header, alert, body);
        document.body.append(dialog);
        dialog.addEventListener("cancel", (event) => {
            event.preventDefault();
            cleanup();
        });
        dialog.addEventListener("close", () => {
            if (dialog === current) cleanup();
        });
        dialog.showModal();
        return body;
    }
    window.addEventListener("pagehide", cleanup);
    async function start() {
        const catalog = await request("/sis/report-studio/catalog/");
        const body = openSpace("Report Studio", "TURN A QUESTION INTO A CLEAR ANSWER");
        const surface = node("section", { class: "report-studio report-launch" });
        body.append(surface);
        surface.append(
            node(
                "p",
                { class: "report-intro" },
                "Start with a question. Connect related records, check the fields you want, then preview and share a useful report.",
            ),
        );
        const cards = node("div", { class: "report-dataset-grid" });
        surface.append(cards);
        catalog.datasets.forEach((dataset) => {
            const card = node(
                "article",
                { class: "report-dataset-card" },
                node("i", { class: "bi bi-diagram-3", "aria-hidden": "true" }),
                node("h3", {}, dataset.label),
                node("p", {}, dataset.description),
                button(`Start report: ${dataset.label}`, () => edit(dataset, initial(dataset)), "btn-primary"),
            );
            const start = card.querySelector("button");
            start.setAttribute("aria-label", `Start report: ${dataset.label}`);
            start.textContent = "Start report";
            cards.append(card);
        });
        if (!catalog.datasets.length)
            surface.append(
                node("p", {}, "Your account needs an authorized source-data permission before it can build reports."),
            );
        const templates = node("details", { class: "report-templates" }, node("summary", {}, "Start from a template"));
        const templateGrid = node("div", { class: "report-template-grid" });
        templates.append(templateGrid);
        surface.append(templates);
        const students = catalog.datasets.find((item) => item.id === "students");
        const sections = catalog.datasets.find((item) => item.id === "sections");
        const applications = catalog.datasets.find((item) => item.id === "applications");
        const accounts = catalog.datasets.find((item) => item.id === "accounts");
        if (students?.fields.latest_term_code || sections?.fields.enrollment_id || applications?.fields.submitted_on || accounts?.fields.balance)
            templateGrid.append(node("p", {}, "Choose a starting question, then adjust its fields and filters."));
        if (applications?.fields.submitted_on)
            templateGrid.append(
                button("Applications with recorded submission dates", () => edit(applications, {
                    ...initial(applications),
                    filters: { condition: "AND", rules: [
                        { id: "submitted_on", operator: "is_not_null", value: null },
                    ] },
                })),
                button("Applications with unknown submission dates", () => edit(applications, {
                    ...initial(applications),
                    filters: { condition: "AND", rules: [
                        { id: "submitted_on", operator: "is_null", value: null },
                    ] },
                })),
                node("p", { class: "form-text" }, "Submission dates come from recorded submissions, in their original timezone. Earlier unknown dates stay blank. Use Filters to choose a date range; the intended entry term is a separate field."),
            );
        if (accounts?.fields.balance)
            templateGrid.append(button("Outstanding student balances", () => edit(accounts, {
                ...initial(accounts),
                filters: { condition: "AND", rules: [{ id: "balance", operator: "greater", value: 0 }] },
            })), button("Available student credit", () => edit(accounts, {
                ...initial(accounts),
                filters: { condition: "AND", rules: [{ id: "balance", operator: "less", value: 0 }] },
            })), button("Balances by billing term", () => edit(accounts, {
                ...initial(accounts),
                columns: [
                    { field: "term_code", label: "Billing term", aggregate: "none" },
                    { field: "currency", label: "Currency", aggregate: "none" },
                    ...["charges", "payments", "refunds"]
                        .filter(field => accounts.fields[field])
                        .map(field => ({ field, label: accounts.fields[field].label, aggregate: "sum" })),
                    { field: "balance", label: "Net balance", aggregate: "sum" },
                    { field: "account_id", label: "Accounts", aggregate: "count_distinct" },
                ],
            })));
        if (students?.fields.latest_term_code) {
            const latestDefinition = (neverRegistered) => ({
                dataset: "students",
                columns: ["institutional_id", "full_name", "program_code", "latest_term_code", "latest_term_starts_on"]
                    .filter((field) => students.fields[field])
                    .map((field) => ({ field, label: students.fields[field].label, aggregate: "none" })),
                joins: { latest_registration: "left" },
                join_filters: {},
                filters: neverRegistered ? { condition: "AND", rules: [
                    { id: "latest_term_code", operator: "is_null", value: null },
                ] } : null,
                having: null,
                sort: [],
                distinct: false,
            });
            templateGrid.append(
                button("Students and their latest registered term", () => edit(students, latestDefinition(false))),
                button("Students with no retained registration", () => edit(students, latestDefinition(true))),
                node("p", { class: "form-text" }, "Latest term uses academic term dates across all pursuits. Retained late drops and withdrawals count; cancellations and removed early drops do not. This is a reporting view, not an eligibility or coursework-reuse decision."),
            );
        }
        if (students?.fields.person_application_submitted_on)
            templateGrid.append(
                button("Latest student applications", () =>
                    edit(students, {
                        ...initial(students),
                        columns: [
                            "institutional_id",
                            "full_name",
                            "person_application_program_code",
                            "person_application_status",
                            "person_application_submitted_on",
                        ]
                            .filter((field) => students.fields[field])
                            .map((field) => ({ field, label: students.fields[field].label, aggregate: "none" })),
                        record_selection: {
                            person_application: {
                                date_field: "person_application_submitted_on",
                                direction: "latest",
                                ties: "all",
                            },
                        },
                    }),
                ),
            );
        if (students?.fields.registration_status)
            templateGrid.append(button("Students with active registrations", () => edit(students, {
                ...initial(students),
                existence: { registration: { mode: "any", filters: { condition: "AND", rules: [
                    { id: "registration_status", operator: "in", value: ["A", "N"] },
                ] } } },
            })));
        if (sections?.fields.enrollment_status)
            templateGrid.append(button("Sections with no active registrations", () => edit(sections, {
                ...initial(sections),
                existence: { enrollment: { mode: "none", filters: { condition: "AND", rules: [
                    { id: "enrollment_status", operator: "in", value: ["A", "N"] },
                ] } } },
            })));
        if (sections?.fields.enrollment_id)
            templateGrid.append(
                button("Section headcounts, including empty sections", () =>
                    edit(sections, {
                        dataset: "sections",
                        columns: [
                            {
                                field: "section_code",
                                label: "Section",
                                aggregate: "none",
                            },
                            {
                                field: "term_code",
                                label: "Term",
                                aggregate: "none",
                            },
                            {
                                field: "enrollment_id",
                                label: "Students",
                                aggregate: "count_distinct",
                            },
                        ],
                        joins: { enrollment: "left" },
                        join_filters: {
                            enrollment: {
                                condition: "AND",
                                rules: [
                                    {
                                        id: "enrollment_status",
                                        operator: "in",
                                        value: ["A", "N"],
                                    },
                                ],
                            },
                        },
                        filters: null,
                        having: null,
                        sort: [],
                        distinct: false,
                    }),
                ),
            );
        if (ui.simpleReport)
            templateGrid.append(
                node(
                    "div",
                    { class: "report-launch-secondary" },
                    node("h3", {}, "Simple student lists"),
                    node("p", {}, "For a basic list of students by program, entry term and activity."),
                    button("Create a simple student list", () => {
                        cleanup();
                        return ui.simpleReport();
                    }),
                ),
            );
    }
    function initial(dataset) {
        const choices = {
            accounts: ["institutional_id", "full_name", "term_code", "currency", "balance"],
            applications: ["institutional_id", "full_name", "program_code", "entry_term", "status", "submitted_on"],
            students: ["institutional_id", "full_name", "program_code", "entry_term"],
            programs: ["institutional_id", "full_name", "program_code", "catalog_year"],
            credits: ["full_name", "course_code", "course_title", "grade_code"],
            sections: ["section_code", "course_title", "term_code", "capacity"],
        };
        return {
            dataset: dataset.id,
            columns: (choices[dataset.id] || Object.keys(dataset.fields).slice(0, 4))
                .filter((id) => dataset.fields[id])
                .map((field) => ({
                    field,
                    label: dataset.fields[field].label,
                    aggregate: "none",
                })),
            joins: {},
            join_filters: {},
            filters: null,
            having: null,
            sort: [],
            distinct: false,
        };
    }
    function labeled(label, input) {
        const id = `report-control-${Math.random().toString(36).slice(2)}`;
        input.id = id;
        return node("div", { class: "mb-3" }, node("label", { class: "form-label", for: id }, label), input);
    }
    function conditionBuilder(container, fields, rules, label, changed = () => {}) {
        const holder = node("div", {
            class: "report-condition-builder",
            role: "group",
            "aria-label": label,
        });
        container.append(holder);
        const definitions = Object.entries(fields).map(([id, field]) => ({
            id,
            label: escapeLabel(field.label),
            type: field.type,
            ...(field.choices ? {
                input: (rule, name) => {
                    if (!["equal", "not_equal", "in", "not_in"].includes(rule.operator.type))
                        return `<input class="form-control" name="${escapeLabel(name)}" data-report-choice="text">`;
                    return `<select class="form-select" name="${escapeLabel(name)}" data-report-choice="select"${rule.operator.multiple ? " multiple" : ""}>`
                        + '<option value="" disabled selected>Choose a value</option>'
                        + Object.entries(field.choices).map(([value, title]) =>
                            `<option value="${escapeLabel(value)}">${escapeLabel(title)}</option>`).join("")
                        + '</select>';
                },
                valueGetter: (rule) => {
                    const value = rule.$el.find("[data-report-choice]").val();
                    return rule.operator.multiple && typeof value === "string"
                        ? value.split(",").map((item) => item.trim()) : value;
                },
                valueSetter: (rule, value) => {
                    const input = rule.$el.find("[data-report-choice]");
                    if (input.is("select")) {
                        // Keep retained/custom values when reopening; labels are display metadata only.
                        for (const item of [].concat(value ?? []))
                            if (item !== "" && !Array.from(input[0].options).some((option) => option.value === String(item)))
                                input.append(new Option(String(item), String(item)));
                        input.val(rule.operator.multiple ? [].concat(value ?? []) : value ?? "");
                    } else input.val(Array.isArray(value) ? value.join(",") : value ?? "");
                    input.trigger("change");
                },
            } : {}),
            ...(field.type === "boolean"
                ? {
                      input: "select",
                      values: { 1: "Yes", 0: "No" },
                      operators: ["equal", "not_equal", "is_null", "is_not_null"],
                  }
                : field.type === "string"
                  ? {
                        operators: [
                            "equal",
                            "not_equal",
                            "contains",
                            "not_contains",
                            "begins_with",
                            "ends_with",
                            "in",
                            "not_in",
                            "is_null",
                            "is_not_null",
                        ],
                    }
                  : {
                        ...(field.type === "date" ? {
                            input: (rule, name) => `<input class="form-control" type="${rule.operator.multiple ? "text" : "date"}" name="${escapeLabel(name)}">`,
                            value_separator: ",",
                        } : {}),
                        operators: [
                            "equal",
                            "not_equal",
                            "less",
                            "less_or_equal",
                            "greater",
                            "greater_or_equal",
                            "between",
                            "not_between",
                            "in",
                            "not_in",
                            "is_null",
                            "is_not_null",
                        ],
                    }),
        }));
        if (!definitions.length) {
            holder.append(node("p", {}, "Add a summarized column to filter totals."));
            return () => null;
        }
        const widget = $(holder).queryBuilder({
            allow_groups: 4,
            allow_empty: true,
            plugins: [],
            filters: definitions,
            lang: {
                add_rule: "Add condition",
                add_group: "Add group",
                conditions: { AND: "All", OR: "Any" },
            },
        });
        widgets.push(widget);
        widget.on("afterUpdateRuleOperator.queryBuilder", (_event, rule) => {
            if (fields[rule.filter?.id]?.choices && rule.operator?.nb_inputs) {
                const input = rule.$el.find("[data-report-choice]");
                const select = ["equal", "not_equal", "in", "not_in"].includes(rule.operator.type);
                if (input.is("select") !== select || (select && input.prop("multiple") !== rule.operator.multiple)) {
                    const previous = rule.value;
                    widget.queryBuilder("createRuleInput", rule);
                    rule.value = rule.operator.multiple ? [].concat(previous ?? [])
                        : Array.isArray(previous) ? previous[0] ?? "" : previous ?? "";
                }
            }
            if (rule.filter?.type !== "date" || !rule.operator) return;
            const type = rule.operator.multiple ? "text" : "date";
            // QueryBuilder reuses one input when switching equality and date-list operators.
            rule.$el.find(".rule-value-container input").each((_, input) => {
                if (input.type === type) return;
                input.type = type;
                $(input).trigger("change");
            });
        });
        const accessible = () => {
            $(holder).find("i").attr("aria-hidden", "true");
            $(holder).find(".rule-filter-container select").attr("aria-label", `${label}: field`);
            $(holder).find(".rule-operator-container select").attr("aria-label", `${label}: comparison`);
            $(holder)
                .find(".rule-value-container input, .rule-value-container select")
                .attr("aria-label", `${label}: value`);
            $(holder)
                .find(".rules-group-header input")
                .each((_, input) =>
                    input.setAttribute(
                        "aria-label",
                        input.value === "AND" ? "Match all conditions" : "Match any condition",
                    ),
                );
        };
        widget.on(
            "afterAddRule.queryBuilder afterAddGroup.queryBuilder afterCreateRuleFilters.queryBuilder " +
                "afterCreateRuleOperators.queryBuilder afterCreateRuleInput.queryBuilder " +
                "afterUpdateRuleFilter.queryBuilder afterUpdateRuleOperator.queryBuilder afterSetRules.queryBuilder",
            accessible,
        );
        widget.on("rulesChanged.queryBuilder", changed);
        if (rules) widget.queryBuilder("setRules", rules);
        accessible();
        const conditions = (group) => ({
            condition: group.condition,
            rules: group.rules.map((rule) => rule.condition ? conditions(rule) : {
                id: rule.id,
                operator: rule.operator,
                value: rule.value,
            }),
        });
        return (relaxed = false) => {
            const value = widget.queryBuilder("getRules", { skip_empty: true, allow_invalid: relaxed });
            if (value === false) throw new Error(`Complete the ${label.toLowerCase()} before continuing.`);
            // Persist typed predicates only; widget metadata may include custom input callbacks.
            return value?.rules?.length ? conditions(value) : null;
        };
    }
    async function edit(dataset, original, saved = null) {
        if (Object.keys(original.aliases || {}).length) {
            const previous = dialog;
            try {
                dataset = (await request("/sis/report-studio/graph/", "POST", {
                    dataset: dataset.id, aliases: original.aliases,
                })).dataset;
            } catch (error) {
                if (dialog !== previous) return;
                throw error;
            }
            if (dialog !== previous) return;
        }
        const body = openSpace(saved ? "Edit report" : "Build a report", "VISUAL REPORT BUILDER");
        const definition = structuredClone(original);
        definition.aliases ||= {};
        definition.joins ||= {};
        definition.join_filters ||= {};
        definition.existence ||= {};
        definition.record_selection ||= {};
        const rowSelectionOpen = new Set();
        const matchingLogic = window.createReportMatchLogic(definition);
        definition.sort ||= [];
        const root =
            Object.values(dataset.relationships)
                .map((r) => r.parent)
                .find((id) => !dataset.relationships[id]) || Object.values(dataset.fields)[0]?.relation;
        const rootLabel =
            { account: "Student accounts", application: "Applications", student: "Students", assignment: "Program assignments", credit: "Academic credits", section: "Sections" }[root] ||
            "Starting records";
        const relationLabel = (id) => (id === root ? rootLabel : dataset.relationships[id].label);
        const rowScope = id => dataset.relationships[id]?.many
            ? (definition.record_selection[id]?.ties === "one" ? " · at most one match" : " · may add rows")
            : "";
        const fieldsFor = (id) => Object.entries(dataset.fields).filter(([, f]) => f.relation === id);
        const matchingFields = (id) => Object.fromEntries(Object.entries(dataset.fields).filter(([, field]) => {
            for (let current = field.relation; current !== root; current = dataset.relationships[current].parent)
                if (current === id) return true;
            return false;
        }));
        let serial = 0,
            previewSerial = 0,
            frozen = false,
            pending = null,
            frozenState = null;
        let selectedColumn = null,
            activeTab = "design",
            readers = null;
        let graphRevision = 0;
        const positions = new Map(),
            enabled = new Set([root]);
        const events = new AbortController();
        releaseScreen = () => events.abort();
        function connect(id) {
            if (id !== root) connect(dataset.relationships[id].parent);
            enabled.add(id);
        }
        function conditionFields(group, callback) {
            (group?.rules || []).forEach((rule) =>
                rule.condition ? conditionFields(rule, callback) : callback(rule.id),
            );
        }
        definition.columns.forEach((c) => {
            connect(dataset.fields[c.field].relation);
            if (c.calculation) connect(dataset.fields[c.calculation.field].relation);
        });
        Object.keys(definition.joins).forEach(connect);
        Object.keys(definition.record_selection).forEach(connect);
        Object.keys(definition.join_filters).forEach(connect);
        Object.keys(definition.aliases).forEach(connect);
        conditionFields(definition.filters, (id) => connect(dataset.fields[id].relation));
        const surface = node("div", { class: "report-visual-editor" });
        const status = node(
            "p",
            { class: "report-live-status", role: "status", "aria-live": "polite" },
            "Check fields to include them. Drag cards to arrange your map; drag fields into report columns.",
        );
        const name = node("input", {
            class: "form-control report-name",
            maxlength: 191,
            value: saved?.name || "",
            placeholder: "Name your report",
        });
        const toolbar = node("div", { class: "report-editor-toolbar" }, labeled("Report name", name));
        const actions = node("div", { class: "report-editor-actions" });
        const heading = dialog.querySelector(".report-workbench-heading");
        heading.classList.add("report-editor-heading");
        heading.insertBefore(actions, heading.lastElementChild);
        const tabs = node("div", { class: "report-view-tabs", role: "tablist", "aria-label": "Report workspace" });
        const panels = {};
        const tabButtons = {};
        [
            ["design", "Fields", "diagram-3"],
            ["filters", "Filters", "funnel"],
            ["preview", "Results", "bar-chart-line"],
        ].forEach(([id, title, glyph]) => {
            const tab = button(title, () => selectTab(id), "report-view-tab");
            tab.prepend(icon(glyph));
            tab.id = `report-tab-${id}`;
            tab.setAttribute("role", "tab");
            tab.setAttribute("aria-controls", `report-panel-${id}`);
            tab.addEventListener("keydown", (event) => {
                const ids = Object.keys(tabButtons),
                    at = ids.indexOf(id);
                const next =
                    event.key === "ArrowRight"
                        ? ids[(at + 1) % ids.length]
                        : event.key === "ArrowLeft"
                          ? ids[(at + ids.length - 1) % ids.length]
                          : event.key === "Home"
                            ? ids[0]
                            : event.key === "End"
                              ? ids.at(-1)
                              : null;
                if (next) {
                    event.preventDefault();
                    selectTab(next);
                    tabButtons[next].focus();
                }
            });
            tabButtons[id] = tab;
            tabs.append(tab);
            panels[id] = node("section", {
                id: `report-panel-${id}`,
                role: "tabpanel",
                "aria-labelledby": tab.id,
                tabindex: "0",
            });
        });
        body.append(toolbar, tabs, status, surface);
        surface.append(panels.design, panels.filters, panels.preview);
        const design = node("div", { class: "report-design-layout" });
        const library = node("aside", { class: "report-library", "aria-label": "Available records and fields" });
        const search = node("input", { class: "form-control", type: "search", placeholder: "Find a record or field…" });
        const libraryRows = node("div", { class: "report-library-rows" });
        library.append(node("h2", {}, "Available fields"), labeled("Find records and fields", search), libraryRows);
        const main = node("div", { class: "report-design-main" });
        design.append(library, main);
        panels.design.append(design);
        const mapTools = node(
            "div",
            { class: "report-map-toolbar" },
            node("div", {}, node("h2", {}, "Record map"), node("p", {}, dataset.description),
                ...(dataset.id === "accounts" ? [node("p", { class: "form-text" },
                    "Keep Currency in the results. To total balances for students with matching registrations or coursework, use matching-record conditions so each account is counted once.")] : [])),
            button("Auto arrange", () => {
                positions.clear();
                renderMap();
            }),
        );
        const viewport = node("div", {
            class: "report-map-viewport",
            role: "region",
            "aria-label": "Record relationship map",
            tabindex: "0",
        });
        const board = node("div", { class: "report-map-board" });
        viewport.append(board);
        main.append(mapTools, viewport);
        const output = node("section", { class: "report-output", "aria-label": "Report columns" });
        const outputTitle = node("h2");
        const distinct = node("input", {
            type: "checkbox",
            class: "form-check-input",
            checked: !!definition.distinct,
            id: "report-distinct",
        });
        distinct.addEventListener("change", () => {
            if (!frozen) {
                definition.distinct = distinct.checked;
                dirty();
            }
        });
        output.append(
            node(
                "div",
                { class: "report-output-heading" },
                outputTitle,
                node("label", { class: "report-check", for: "report-distinct" }, distinct, "Unique rows only"),
            ),
            node(
                "p",
                { class: "form-text" },
                "Drag fields here or check them on a card. Reorder columns by dragging their handles, or use the arrow buttons.",
            ),
        );
        const outputList = node("div", {
            class: "report-output-list",
            role: "list",
            "aria-label": "Selected report columns",
        });
        output.append(outputList);
        main.append(output);
        const inspector = node("aside", { class: "report-inspector", "aria-label": "Column settings", hidden: true });
        design.append(inspector);
        const preview = node(
            "div",
            { class: "report-results-surface" },
            node("h2", {}, "Explore your results"),
            node("p", {}, "Preview your report to see a table and chart here."),
        );
        panels.preview.append(preview);
        function selectTab(id) {
            if (id === "filters") renderFilters();
            else syncFilters(true);
            activeTab = id;
            Object.entries(tabButtons).forEach(([key, tab]) => {
                tab.setAttribute("aria-selected", String(key === id));
                tab.tabIndex = key === id ? 0 : -1;
                panels[key].hidden = key !== id;
            });
        }
        function dirty() {
            serial++;
            status.textContent = "Unsaved changes. Preview to see the updated report.";
            preview.classList.add("report-results-stale");
        }
        name.addEventListener("input", dirty);
        const dropTargets = new WeakMap();
        let finishDrag = () => {};
        events.signal.addEventListener("abort", () => finishDrag(), { once: true });
        function dragSource(element, payload) {
            // Pointer capture supports mouse, touch and pen without browser-native button-drag differences.
            element.draggable = false;
            element.setAttribute("data-report-draggable", "true");
            element.addEventListener("pointerdown", (event) => {
                if (frozen || event.button !== 0) return;
                finishDrag();
                const start = { x: event.clientX, y: event.clientY };
                let moved = false,
                    ghost = null,
                    highlighted = null;
                element.setPointerCapture(event.pointerId);
                const move = (next) => {
                    if (!moved && Math.hypot(next.clientX - start.x, next.clientY - start.y) < 6) return;
                    moved = true;
                    surface.classList.add("report-is-dragging");
                    if (!ghost) {
                        ghost = node(
                            "div",
                            { class: "report-drag-ghost", "aria-hidden": "true" },
                            element.getAttribute("aria-label") || element.textContent,
                        );
                        dialog?.append(ghost);
                    }
                    ghost.style.left = `${next.clientX + 12}px`;
                    ghost.style.top = `${next.clientY + 12}px`;
                    const hit = document.elementFromPoint(next.clientX, next.clientY);
                    const destination = hit?.closest("[data-report-drop]");
                    if (highlighted !== destination) {
                        highlighted?.classList.remove("report-drop-active");
                        highlighted = destination;
                        highlighted?.classList.add("report-drop-active");
                    }
                    const map = hit?.closest(".report-map-viewport");
                    if (map) {
                        const rect = map.getBoundingClientRect();
                        map.scrollBy(
                            next.clientX > rect.right - 30 ? 18 : next.clientX < rect.left + 30 ? -18 : 0,
                            next.clientY > rect.bottom - 30 ? 18 : next.clientY < rect.top + 30 ? -18 : 0,
                        );
                    }
                    if (next.clientY > innerHeight - 45) dialog?.scrollBy(0, 24);
                    else if (next.clientY < 95) dialog?.scrollBy(0, -24);
                };
                const finish = () => {
                    ghost?.remove();
                    ghost = null;
                    highlighted?.classList.remove("report-drop-active");
                    surface.classList.remove("report-is-dragging");
                    element.removeEventListener("pointermove", move);
                    element.removeEventListener("pointerup", up);
                    element.removeEventListener("pointercancel", finish);
                    element.removeEventListener("lostpointercapture", finish);
                    if (element.hasPointerCapture(event.pointerId)) element.releasePointerCapture(event.pointerId);
                    finishDrag = () => {};
                };
                const up = (next) => {
                    const target = document.elementFromPoint(next.clientX, next.clientY)?.closest("[data-report-drop]");
                    const callback = target ? dropTargets.get(target) : null;
                    if (moved) {
                        const suppress = (click) => {
                            click.preventDefault();
                            click.stopImmediatePropagation();
                        };
                        element.addEventListener("click", suppress, { capture: true, once: true });
                        window.setTimeout(() => element.removeEventListener("click", suppress, true), 0);
                    }
                    finish();
                    if (moved && !frozen && callback)
                        task(async () => {
                            try {
                                callback(payload, next);
                            } catch (error) {
                                localError(error);
                            }
                        });
                };
                finishDrag = finish;
                element.addEventListener("pointermove", move);
                element.addEventListener("pointerup", up);
                element.addEventListener("pointercancel", finish);
                element.addEventListener("lostpointercapture", finish);
            });
        }
        function dropTarget(element, callback) {
            element.setAttribute("data-report-drop", "true");
            dropTargets.set(element, callback);
        }
        function columnChanges(next) {
            syncFilters(true);
            const previous = definition.columns;
            const mapping = new Map(previous.map((c, i) => [`c${i}`, next.includes(c) ? `c${next.indexOf(c)}` : null]));
            function remap(group) {
                if (!group) return null;
                const rules = group.rules.flatMap((rule) => {
                    if (rule.condition) {
                        const nested = remap(rule);
                        return nested ? [nested] : [];
                    }
                    const id = mapping.get(rule.id);
                    return id ? [{ ...rule, id }] : [];
                });
                return rules.length ? { ...group, rules } : null;
            }
            definition.having = remap(definition.having);
            definition.sort = definition.sort.flatMap((s) => {
                const id = mapping.get(`c${s.column}`);
                return id ? [{ ...s, column: Number(id.slice(1)) }] : [];
            });
            definition.columns = next;
            destroyWidgets();
            readers = null;
            panels.filters.replaceChildren();
            if (selectedColumn && !next.includes(selectedColumn)) closeInspector();
            dirty();
            renderColumns();
            renderMap();
            renderLibrary();
            if (activeTab === "filters") renderFilters();
        }
        function addField(id, at = definition.columns.length) {
            if (frozen) return;
            if (definition.columns.length >= 16) throw new Error("Choose up to 16 report columns.");
            connect(dataset.fields[id].relation);
            const next = [...definition.columns];
            next.splice(at, 0, { field: id, label: dataset.fields[id].label, aggregate: "none" });
            columnChanges(next);
        }
        function fieldCheckbox(id) {
            const field = dataset.fields[id];
            const input = node("input", {
                type: "checkbox",
                class: "form-check-input",
                checked: definition.columns.some((c) => c.field === id),
                disabled: frozen,
            });
            input.addEventListener("change", () =>
                task(async () => {
                    try {
                        const fromLibrary = libraryRows.contains(input);
                        if (input.checked) addField(id);
                        else columnChanges(definition.columns.filter((c) => c.field !== id));
                        const replacement = (fromLibrary ? libraryRows : board).querySelector(
                            `[data-report-choice="${CSS.escape(id)}"] input`,
                        );
                        replacement?.focus({ preventScroll: true });
                    } catch (error) {
                        input.checked = false;
                        localError(error);
                    }
                }),
            );
            return node(
                "label",
                { class: "report-check", "data-report-choice": id },
                input,
                node("span", {}, field.label),
            );
        }
        function renderLibrary() {
            libraryRows.replaceChildren();
            const query = search.value.trim().toLocaleLowerCase();
            [root, ...Object.keys(dataset.relationships)].forEach((id) => {
                const fields = fieldsFor(id),
                    matches = fields.filter(([, f]) => f.label.toLocaleLowerCase().includes(query));
                if (query && !relationLabel(id).toLocaleLowerCase().includes(query) && !matches.length) return;
                const entry = node("div", { class: "report-library-entry" });
                const add = button(
                    `${enabled.has(id) ? "Show" : "Add"} ${relationLabel(id)}`,
                    () => {
                        if (frozen) return;
                        connect(id);
                        if (id !== root) definition.joins[id] ||= "left";
                        renderMap();
                        renderLibrary();
                        dirty();
                        board
                            .querySelector(`[data-relation="${CSS.escape(id)}"]`)
                            ?.scrollIntoView({ block: "nearest", inline: "nearest" });
                    },
                    "report-library-button",
                );
                add.prepend(icon(enabled.has(id) ? "check-circle" : "plus-circle"));
                dragSource(add, { type: "relation", id });
                entry.append(
                    add,
                    node(
                        "small",
                        {},
                        `${fields.length} fields${rowScope(id)}`,
                    ),
                );
                if (id !== root && !dataset.relationships[id].copy_group) {
                    const copy = button(`Add another ${relationLabel(id)}`, () => copyRelation(id));
                    copy.disabled = frozen || Object.keys(definition.aliases).length >= 3;
                    entry.append(copy);
                }
                if (query) matches.forEach(([field]) => entry.append(fieldCheckbox(field)));
                libraryRows.append(entry);
            });
            if (!libraryRows.childElementCount) libraryRows.append(node("p", {}, "No matching records or fields."));
        }
        search.addEventListener("input", renderLibrary);
        async function copyRelation(origin) {
            if (frozen || Object.keys(definition.aliases).length >= 3) return;
            syncFilters(true);
            const id = ["copy1", "copy2", "copy3"].find(key => !definition.aliases[key]);
            const number = Object.values(definition.aliases).filter(copy => copy.relation === origin).length + 2;
            const aliases = { ...definition.aliases, [id]: { relation: origin,
                label: `${relationLabel(origin)} ${number}`.slice(0, 48) } };
            const revision = graphRevision;
            let result;
            try {
                result = await request("/sis/report-studio/graph/", "POST", { dataset: dataset.id, aliases });
            } catch (error) {
                if (!surface.isConnected || revision !== graphRevision) return;
                throw error;
            }
            if (!surface.isConnected || frozen || revision !== graphRevision) return;
            if (!result.dataset?.relationships[id]) throw new Error("This record is no longer available to your account.");
            syncFilters(true);
            destroyWidgets();
            readers = null;
            panels.filters.replaceChildren();
            dataset = result.dataset;
            definition.aliases = aliases;
            graphRevision++;
            connect(id);
            dirty();
            renderMap();
            renderLibrary();
            const card = board.querySelector(`[data-relation="${id}"]`);
            card?.scrollIntoView({ block: "nearest", inline: "nearest" });
            card?.querySelector("[data-copy-label]")?.focus({ preventScroll: true });
        }
        function renderMap() {
            board.replaceChildren();
            const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
            svg.classList.add("report-map-connections");
            svg.setAttribute("aria-hidden", "true");
            board.append(svg);
            const byDepth = new Map(),
                cards = new Map();
            const depth = (id) => (id === root ? 0 : depth(dataset.relationships[id].parent) + 1);
            [...enabled]
                .sort((a, b) => depth(a) - depth(b))
                .forEach((id) => {
                    const level = depth(id),
                        row = byDepth.get(level) || 0;
                    byDepth.set(level, row + 1);
                    if (!positions.has(id)) positions.set(id, { x: 24 + level * 300, y: 24 + row * 280 });
                    const position = positions.get(id);
                    const card = node("article", {
                        class: `report-record-card${id === root ? " report-record-root" : ""}`,
                        "data-relation": id,
                        "aria-label": relationLabel(id),
                    });
                    card.style.left = `${position.x}px`;
                    card.style.top = `${position.y}px`;
                    const handle = node(
                        "button",
                        {
                            type: "button",
                            class: "report-card-handle",
                            "aria-label": `Move ${relationLabel(id)} card`,
                            title: "Drag to move, or use arrow keys",
                        },
                        icon("grip-vertical"),
                        relationLabel(id),
                    );
                    handle.addEventListener("keydown", (event) => {
                        if (frozen || !["ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown"].includes(event.key)) return;
                        event.preventDefault();
                        position.x = Math.max(
                            12,
                            position.x + (event.key === "ArrowRight" ? 24 : event.key === "ArrowLeft" ? -24 : 0),
                        );
                        position.y = Math.max(
                            12,
                            position.y + (event.key === "ArrowDown" ? 24 : event.key === "ArrowUp" ? -24 : 0),
                        );
                        place();
                    });
                    handle.addEventListener("pointerdown", (event) => {
                        if (frozen || event.button !== 0 || !matchMedia("(min-width: 900px)").matches) return;
                        const start = { x: event.clientX, y: event.clientY, left: position.x, top: position.y };
                        handle.setPointerCapture(event.pointerId);
                        const move = (next) => {
                            position.x = Math.max(12, start.left + next.clientX - start.x);
                            position.y = Math.max(12, start.top + next.clientY - start.y);
                            place();
                        };
                        handle.addEventListener("pointermove", move);
                        handle.addEventListener(
                            "lostpointercapture",
                            () => handle.removeEventListener("pointermove", move),
                            { once: true },
                        );
                    });
                    const cardHeader = node("div", { class: "report-card-top" }, handle);
                    if (id !== root) {
                        const remove = button(
                            `Remove ${relationLabel(id)} and its fields`,
                            () => removeRelation(id),
                            "report-icon-button",
                        );
                        remove.replaceChildren(icon("x-lg"));
                        remove.setAttribute("aria-label", `Remove ${relationLabel(id)} and its fields`);
                        remove.title = "Remove this record, its dependent records and their fields and conditions";
                        cardHeader.append(remove);
                    }
                    card.append(cardHeader);
                    if (definition.aliases[id]) {
                        const title = node("input", { class: "form-control", maxlength: 48,
                            value: definition.aliases[id].label, "data-copy-label": "true" });
                        title.addEventListener("input", () => {
                            if (frozen) return;
                            definition.aliases[id].label = title.value;
                            for (const [key, metadata] of Object.entries(dataset.relationships)) {
                                if (metadata.copy_group !== id) continue;
                                metadata.label = key === id ? title.value :
                                    `${title.value} · ${dataset.relationships[metadata.copy_of].label}`;
                            }
                            graphRevision++;
                            dirty();
                            renderLibrary();
                            handle.replaceChildren(icon("grip-vertical"), title.value);
                            handle.setAttribute("aria-label", `Move ${title.value} card`);
                            card.querySelector(".report-match-options")?.setAttribute("aria-label", `${title.value} matching`);
                            card.querySelector(".report-row-selection-modes")?.setAttribute("aria-label", `${title.value} row selection`);
                            card.querySelector('[data-row-control="date"]')?.setAttribute("aria-label", `${title.value}: date`);
                            const remove = cardHeader.querySelector("button.report-icon-button");
                            remove?.setAttribute("aria-label", `Remove ${title.value} and its fields`);
                        });
                        card.append(labeled("Name this record copy", title));
                    }
                    if (id === root) card.append(node("p", { class: "report-card-origin" }, "Start here"));
                    else {
                        const relation = dataset.relationships[id];
                        const keep = (definition.joins[id] || "left") === "left";
                        const modes = node("div", {
                            class: "report-match-options",
                            role: "group",
                            "aria-label": `${relationLabel(id)} matching`,
                        });
                        [
                            ["left", "Keep all"],
                            ["inner", "Matching only"],
                        ].forEach(([mode, label]) => {
                            const toggle = button(
                                label,
                                () => {
                                    if (!frozen) {
                                        definition.joins[id] = mode;
                                        dirty();
                                        renderMap();
                                    }
                                },
                                "report-match-button",
                            );
                            toggle.setAttribute("aria-pressed", String(keep === (mode === "left")));
                            modes.append(toggle);
                        });
                        card.append(
                            node(
                                "p",
                                { class: "report-card-parent" },
                                `From ${relationLabel(relation.parent)}${rowScope(id)}`,
                            ),
                            modes,
                        );
                    }
                    if (id !== root) {
                        const chooser = rowSelectionUi.card({
                            id,
                            metadata: dataset.relationships[id],
                            value: definition.record_selection[id],
                            open: rowSelectionOpen.has(id),
                            onToggle: (opened) => {
                                if (opened) rowSelectionOpen.add(id);
                                else rowSelectionOpen.delete(id);
                                requestAnimationFrame(() => mapLayout());
                            },
                            onChange: (value, control) => {
                                if (frozen) return;
                                definition.joins[id] ||= "left";
                                if (value) definition.record_selection[id] = value;
                                else delete definition.record_selection[id];
                                rowSelectionOpen.add(id);
                                dirty();
                                renderMap();
                                board
                                    .querySelector(
                                        `[data-record-selection="${CSS.escape(id)}"] [data-row-control="${control}"]`,
                                    )
                                    ?.focus();
                            },
                        });
                        if (chooser) card.append(chooser);
                    }
                    const list = node("div", { class: "report-card-fields" });
                    fieldsFor(id).forEach(([field]) => {
                        const grip = button(
                            `Add another ${dataset.fields[field].label} column`,
                            () => addField(field),
                            "report-field-grip",
                        );
                        grip.replaceChildren(icon("grip-vertical"));
                        grip.title = "Drag into report columns, or click to add another column";
                        grip.setAttribute("aria-label", `Add another ${dataset.fields[field].label} column`);
                        dragSource(grip, { type: "field", id: field });
                        list.append(
                            node(
                                "div",
                                { class: "report-card-field", "data-report-field": field },
                                fieldCheckbox(field),
                                grip,
                            ),
                        );
                    });
                    if (!list.childElementCount)
                        list.append(node("p", { class: "form-text" }, "Connects these related records."));
                    card.append(list);
                    board.append(card);
                    cards.set(id, card);
                });
            function place() {
                let width = 0,
                    height = 0;
                cards.forEach((card, id) => {
                    const p = positions.get(id);
                    card.style.left = `${p.x}px`;
                    card.style.top = `${p.y}px`;
                    width = Math.max(width, p.x + card.offsetWidth + 24);
                    height = Math.max(height, p.y + card.offsetHeight + 24);
                });
                board.style.width = `${Math.max(viewport.clientWidth, width)}px`;
                board.style.height = `${Math.max(300, height)}px`;
                svg.setAttribute("width", String(Math.max(viewport.clientWidth, width)));
                svg.setAttribute("height", String(Math.max(300, height)));
                svg.replaceChildren();
                cards.forEach((card, id) => {
                    if (id === root) return;
                    const parentId = dataset.relationships[id].parent,
                        parent = cards.get(parentId);
                    if (!parent) return;
                    const a = positions.get(parentId),
                        b = positions.get(id);
                    const x1 = a.x + parent.offsetWidth,
                        y1 = a.y + 34,
                        x2 = b.x,
                        y2 = b.y + 34;
                    const path = document.createElementNS(svg.namespaceURI, "path");
                    path.setAttribute("d", `M${x1},${y1} C${x1 + 45},${y1} ${x2 - 45},${y2} ${x2},${y2}`);
                    path.setAttribute(
                        "class",
                        (definition.joins[id] || "left") === "inner" ? "report-link-required" : "report-link-optional",
                    );
                    svg.append(path);
                    [
                        [x1, y1],
                        [x2, y2],
                    ].forEach(([cx, cy]) => {
                        const dot = document.createElementNS(svg.namespaceURI, "circle");
                        dot.setAttribute("cx", String(cx));
                        dot.setAttribute("cy", String(cy));
                        dot.setAttribute("r", "4");
                        svg.append(dot);
                    });
                });
            }
            requestAnimationFrame(() => {
                if (board.isConnected) place();
            });
            mapLayout = place;
        }
        function removeRelation(id) {
            if (frozen || id === root) return;
            syncFilters(true);
            destroyWidgets();
            readers = null;
            panels.filters.replaceChildren();
            const branch = new Set([id]);
            const label = relationLabel(id);
            const removesCopy = !!definition.aliases[id];
            function descends(candidate) {
                return candidate === id || (candidate !== root && descends(dataset.relationships[candidate].parent));
            }
            (removesCopy ? Object.keys(dataset.relationships) : [...enabled]).forEach((candidate) => {
                if (descends(candidate)) branch.add(candidate);
            });
            definition.filters = pruneConditions(
                definition.filters,
                (field) => !branch.has(dataset.fields[field].relation),
            );
            if (removesCopy) {
                delete definition.aliases[id];
                graphRevision++;
                for (const [name, match] of Object.entries(definition.existence)) {
                    if (branch.has(name)) delete definition.existence[name];
                    else match.filters = pruneConditions(match.filters, field => !branch.has(dataset.fields[field].relation));
                }
            }
            matchingLogic.normalize();
            for (const [key, selection] of Object.entries(definition.record_selection)) {
                if (branch.has(key) || branch.has(dataset.fields[selection.date_field]?.relation)) {
                    delete definition.record_selection[key];
                    rowSelectionOpen.delete(key);
                }
            }
            branch.forEach((key) => {
                enabled.delete(key);
                positions.delete(key);
                delete definition.joins[key];
                delete definition.join_filters[key];
                rowSelectionOpen.delete(key);
            });
            columnChanges(
                definition.columns.filter(
                    (column) =>
                        !branch.has(dataset.fields[column.field].relation) &&
                        (!column.calculation || !branch.has(dataset.fields[column.calculation.field].relation)),
                ),
            );
            if (removesCopy) {
                for (const [field, metadata] of Object.entries(dataset.fields))
                    if (metadata.copy_group === id) delete dataset.fields[field];
                for (const [key, metadata] of Object.entries(dataset.relationships))
                    if (metadata.copy_group === id) delete dataset.relationships[key];
                renderMap();
                renderLibrary();
                if (activeTab === "filters") {
                    readers = null;
                    renderFilters();
                }
            }
            status.textContent = `${label} and its dependent fields and conditions were removed.`;
        }
        let mapLayout = () => {};
        window.addEventListener("resize", () => mapLayout(), { signal: events.signal });
        dropTarget(viewport, (payload, event) => {
            if (payload.type === "relation") {
                connect(payload.id);
                if (payload.id !== root) definition.joins[payload.id] ||= "left";
                const rect = viewport.getBoundingClientRect();
                positions.set(payload.id, {
                    x: Math.max(12, event.clientX - rect.left + viewport.scrollLeft - 100),
                    y: Math.max(12, event.clientY - rect.top + viewport.scrollTop - 25),
                });
                renderMap();
                renderLibrary();
                dirty();
            } else if (payload.type === "field") addField(payload.id);
        });
        function moveColumn(from, to) {
            if (frozen || from === to) return;
            const next = [...definition.columns],
                [column] = next.splice(from, 1);
            next.splice(to, 0, column);
            columnChanges(next);
            outputList.querySelector(`[data-column-index="${to}"] .report-column-move`)?.focus();
            status.textContent = `${column.label} moved to position ${to + 1}.`;
        }
        dropTarget(outputList, (payload) => {
            if (payload.type === "field") addField(payload.id);
            else if (payload.type === "column") moveColumn(payload.index, definition.columns.length - 1);
        });
        function renderColumns() {
            outputList.replaceChildren();
            outputTitle.textContent = `Report columns · ${definition.columns.length} of 16`;
            definition.columns.forEach((column, index) => {
                const row = node("div", { role: "listitem", class: "report-output-row", "data-column-index": index });
                const grip = button(`Move column ${index + 1}`, () => inspect(column), "report-column-move");
                grip.replaceChildren(icon("grip-vertical"));
                grip.setAttribute("aria-label", `Move column ${index + 1}`);
                dragSource(grip, { type: "column", index });
                grip.addEventListener("keydown", (event) => {
                    if (!event.altKey || !["ArrowUp", "ArrowDown"].includes(event.key)) return;
                    event.preventDefault();
                    moveColumn(
                        index,
                        Math.max(
                            0,
                            Math.min(definition.columns.length - 1, index + (event.key === "ArrowUp" ? -1 : 1)),
                        ),
                    );
                });
                const label = node("input", {
                    class: "form-control report-column-label",
                    maxlength: 80,
                    value: column.label,
                    "aria-label": `Column ${index + 1} label`,
                });
                label.addEventListener("input", () => {
                    if (!frozen) {
                        column.label = label.value;
                        dirty();
                    }
                });
                const source = node(
                    "small",
                    {},
                    column.calculation
                        ? `${dataset.fields[column.field].label} ${{ "+": "+", "-": "−", "*": "×", "/": "÷" }[column.calculation.operator]} ${dataset.fields[column.calculation.field].label}`
                        : `${relationLabel(dataset.fields[column.field].relation)} · ${dataset.fields[column.field].label}`,
                );
                const mode = button(summaryLabel(column.aggregate), () => inspect(column), "report-summary-button");
                mode.setAttribute("aria-label", `Column ${index + 1} settings: ${summaryLabel(column.aggregate)}`);
                mode.prepend(icon(column.aggregate === "none" ? "sliders" : "calculator"));
                const sort = definition.sort.find((s) => s.column === index);
                const order = button(
                    sort
                        ? `${sort.direction === "asc" ? "A → Z" : "Z → A"} · ${definition.sort.indexOf(sort) + 1}`
                        : "Sort",
                    () => {
                        if (frozen) return;
                        if (!sort) {
                            if (definition.sort.length === 3)
                                throw new Error("Sort by up to three columns. Turn off one sort first.");
                            definition.sort.push({ column: index, direction: "asc" });
                        } else if (sort.direction === "asc") sort.direction = "desc";
                        else definition.sort = definition.sort.filter((s) => s !== sort);
                        dirty();
                        renderColumns();
                    },
                    "report-sort-button",
                );
                order.setAttribute(
                    "aria-label",
                    `Sort column ${index + 1}: ${sort ? (sort.direction === "asc" ? "ascending" : "descending") : "off"}`,
                );
                const movement = node("div", { class: "report-column-actions" });
                [
                    [-1, "up", "arrow-up"],
                    [1, "down", "arrow-down"],
                ].forEach(([change, direction, glyph]) => {
                    const control = button(
                        `Move column ${index + 1} ${direction}`,
                        () => moveColumn(index, index + change),
                        "report-icon-button",
                    );
                    control.replaceChildren(icon(glyph));
                    control.setAttribute("aria-label", `Move column ${index + 1} ${direction}`);
                    control.disabled = index + change < 0 || index + change >= definition.columns.length;
                    movement.append(control);
                });
                const remove = button(
                    `Remove column ${index + 1}`,
                    () => columnChanges(definition.columns.filter((c) => c !== column)),
                    "report-icon-button report-remove-column",
                );
                remove.replaceChildren(icon("x-lg"));
                remove.setAttribute("aria-label", `Remove column ${index + 1}`);
                movement.append(remove);
                row.append(
                    grip,
                    node("span", { class: "report-column-number", "aria-hidden": "true" }, String(index + 1)),
                    node("div", { class: "report-column-name-cell" }, label, source),
                    mode,
                    order,
                    movement,
                );
                dropTarget(row, (payload) => {
                    if (payload.type === "field") addField(payload.id, index);
                    else if (payload.type === "column") moveColumn(payload.index, index);
                });
                outputList.append(row);
            });
            if (!definition.columns.length)
                outputList.append(
                    node(
                        "div",
                        { class: "report-output-empty" },
                        icon("plus-circle"),
                        "Your report starts here. Check a field or drag one into this space.",
                    ),
                );
        }
        function summaryLabel(mode) {
            return {
                none: "Each value",
                count: "Count",
                count_distinct: "Distinct count",
                sum: "Total",
                avg: "Average",
                min: "Lowest / earliest",
                max: "Highest / latest",
            }[mode];
        }
        function closeInspector() {
            inspector.hidden = true;
            selectedColumn = null;
            design.classList.remove("report-inspecting");
        }
        function inspect(column) {
            if (frozen) return;
            selectedColumn = column;
            inspector.hidden = false;
            design.classList.add("report-inspecting");
            inspector.replaceChildren();
            const index = definition.columns.indexOf(column),
                field = dataset.fields[column.field];
            const done = button("Done", () => {
                closeInspector();
                outputList.querySelector(`[data-column-index="${index}"] .report-summary-button`)?.focus();
            });
            inspector.append(
                node("div", { class: "report-inspector-heading" }, node("h2", {}, "Column settings"), done),
                node("p", {}, column.label),
            );
            const summaries = node("div", {
                class: "report-summary-choices",
                role: "group",
                "aria-label": `Column ${index + 1} summary`,
            });
            const choices = [
                "none",
                "count",
                "count_distinct",
                ...(["integer", "double"].includes(field.type) ? ["sum", "avg"] : []),
                ...(field.type !== "boolean" ? ["min", "max"] : []),
            ];
            choices.forEach((mode) => {
                const tile = button(
                    summaryLabel(mode),
                    () => {
                        syncFilters(true);
                        column.aggregate = mode;
                        definition.having = pruneConditions(definition.having, (id) => id !== `c${index}`);
                        destroyWidgets();
                        readers = null;
                        panels.filters.replaceChildren();
                        dirty();
                        renderColumns();
                        inspect(column);
                    },
                    "report-choice-tile",
                );
                tile.setAttribute("aria-pressed", String(column.aggregate === mode));
                summaries.append(tile);
            });
            inspector.append(
                node("h3", {}, "Show this field as"),
                summaries,
                node("p", { class: "form-text" }, "When you summarize a column, the other columns group your results."),
            );
            const duplicate = button("Duplicate column", () => addField(column.field));
            inspector.append(duplicate);
            if (["integer", "double"].includes(field.type)) {
                inspector.append(node("h3", {}, "Calculate with another field"));
                const operations = node("div", {
                    class: "report-calculation-operators",
                    role: "group",
                    "aria-label": "Calculation",
                });
                const candidates = node("div", { class: "report-calculation-fields" });
                let operator = column.calculation?.operator || "-";
                function numericFields() {
                    candidates.replaceChildren(node("p", { class: "form-text" }, "Choose the second numeric field:"));
                    Object.entries(dataset.fields)
                        .filter(([, f]) => ["integer", "double"].includes(f.type))
                        .forEach(([id, f]) => {
                            const choose = button(
                                f.label,
                                () => {
                                    column.calculation = { operator, field: id };
                                    connect(f.relation);
                                    dirty();
                                    renderColumns();
                                    renderMap();
                                    inspect(column);
                                },
                                "report-choice-tile",
                            );
                            choose.setAttribute("aria-pressed", String(column.calculation?.field === id));
                            candidates.append(choose);
                        });
                }
                [
                    ["+", "Add"],
                    ["-", "Subtract"],
                    ["*", "Multiply"],
                    ["/", "Divide"],
                ].forEach(([op, label]) => {
                    const choice = button(
                        label,
                        () => {
                            operator = op;
                            if (column.calculation) {
                                column.calculation.operator = op;
                                dirty();
                            }
                            [...operations.children].forEach((b) =>
                                b.setAttribute("aria-pressed", String(b === choice)),
                            );
                            numericFields();
                        },
                        "report-choice-tile",
                    );
                    choice.setAttribute("aria-pressed", String(operator === op));
                    operations.append(choice);
                });
                inspector.append(operations, candidates);
                numericFields();
                if (column.calculation)
                    inspector.append(
                        button("Remove calculation", () => {
                            delete column.calculation;
                            dirty();
                            renderColumns();
                            inspect(column);
                        }),
                    );
            }
            inspector.scrollIntoView({ block: "nearest" });
            inspector.querySelector("button")?.focus({ preventScroll: true });
        }
        function pruneConditions(group, keep) {
            if (!group) return null;
            const rules = group.rules.flatMap((rule) => {
                if (!rule.condition) return keep(rule.id) ? [rule] : [];
                const nested = pruneConditions(rule, keep);
                return nested ? [nested] : [];
            });
            return rules.length ? { ...group, rules } : null;
        }
        function syncFilters(relaxed = false) {
            if (!readers) return;
            definition.filters = readers.rows(relaxed);
            definition.having = readers.totals(relaxed);
            definition.join_filters = Object.fromEntries(
                Object.entries(readers.joins)
                    .map(([id, read]) => [id, read(relaxed)])
                    .filter(([, group]) => group),
            );
            definition.existence = Object.fromEntries(Object.entries(readers.existence).map(([id, read]) => [
                id, { mode: definition.existence[id].mode, filters: read(relaxed) },
            ]));
        }
        function renderFilters() {
            syncFilters(true);
            destroyWidgets();
            panels.filters.replaceChildren();
            const filtersBody = node("div", { class: "report-filters-surface" });
            panels.filters.append(filtersBody);
            const rows = node(
                "section",
                { class: "report-filter-section" },
                node("h2", {}, "Row filters"),
                node("p", {}, "Match all or any conditions. Add groups when your question needs a combination."),
            );
            const totals = node(
                "section",
                { class: "report-filter-section" },
                node("h2", {}, "Summary filters"),
                node("p", {}, "For example, show only sections with fewer than 12 registrations."),
            );
            filtersBody.append(rows);
            const measures = Object.fromEntries(
                definition.columns.flatMap((c, i) =>
                    c.aggregate === "none"
                        ? []
                        : [
                              [
                                  `c${i}`,
                                  {
                                      label: c.label,
                                      type: ["count", "count_distinct"].includes(c.aggregate)
                                          ? "integer"
                                          : c.aggregate === "avg" || c.calculation
                                            ? "double"
                                            : dataset.fields[c.field].type,
                                  },
                              ],
                          ],
                ),
            );
            if (Object.keys(measures).length) filtersBody.append(totals);
            let restoring = true;
            const changed = () => {
                if (!restoring) dirty();
            };
            const next = {
                rows: conditionBuilder(rows, dataset.fields, definition.filters, "Row conditions", changed),
                totals: Object.keys(measures).length
                    ? conditionBuilder(totals, measures, definition.having, "Summary conditions", changed)
                    : () => null,
                joins: {},
                existence: {},
            };
            matchingLogic.normalize();
            const matching = node(
                "section",
                { class: "report-filter-section" },
                node("h2", {}, "Related records"),
                node(
                    "p",
                    {},
                    "Check related records without adding duplicate rows. Combine sources using All or Any groups.",
                ),
            );
            filtersBody.append(matching);
            const picker = node("select", { class: "form-select", "data-report-match-picker": "true" });
            for (const [id, relation] of Object.entries(dataset.relationships))
                if (!definition.existence[id]) picker.append(node("option", { value: id }, relation.label));
            const mutate = (callback, focus) => {
                if (frozen) return;
                syncFilters(true);
                readers = null;
                callback();
                matchingLogic.normalize();
                dirty();
                renderFilters();
                focus?.();
            };
            const focusMatch = (id) =>
                panels.filters.querySelector(`[data-report-match="${CSS.escape(id)}"] h3`)?.focus();
            const focusGroup = (path) =>
                panels.filters.querySelector(`[data-match-group="${path.join(".")}"] h3`)?.focus();
            const add = button("Add match", () => {
                if (!picker.value || Object.keys(definition.existence).length >= 6) return;
                const id = picker.value;
                mutate(
                    () => {
                        definition.existence[id] = { mode: "any", filters: null };
                    },
                    () => focusMatch(id),
                );
            });
            add.disabled = !picker.options.length || Object.keys(definition.existence).length >= 6;
            matching.append(node("div", { class: "report-match-add" }, labeled("Record source", picker), add));
            const groupList = matchingLogic.groups();
            function renderMatch(id, path) {
                const relation = dataset.relationships[id],
                    match = definition.existence[id];
                const panel = node(
                    "section",
                    { class: "report-match-card", "data-report-match": id },
                    node("h3", { tabindex: "-1" }, relation.label),
                );
                const modes = node("div", {
                    class: "report-match-card-tools",
                    role: "group",
                    "aria-label": `${relation.label} matching requirement`,
                });
                const grip = button(
                    `Move ${relation.label} condition`,
                    () => (moveOptions.open = !moveOptions.open),
                    "btn-sm btn-light report-match-grip",
                );
                grip.replaceChildren(icon("grip-vertical"));
                grip.title = `Move ${relation.label} condition`;
                grip.setAttribute("aria-label", `Move ${relation.label} condition`);
                dragSource(grip, { type: "match", relation: id });
                modes.append(grip);
                for (const [mode, title] of [
                    ["any", "Has a match"],
                    ["none", "No match"],
                ]) {
                    const control = button(
                        title,
                        () =>
                            mutate(
                                () => {
                                    definition.existence[id].mode = mode;
                                },
                                () =>
                                    panels.filters
                                        .querySelector(
                                            `[data-report-match="${CSS.escape(id)}"] [data-match-mode="${mode}"]`,
                                        )
                                        ?.focus(),
                            ),
                        match.mode === mode ? "btn-sm btn-primary" : "btn-sm btn-outline-primary",
                    );
                    control.setAttribute("aria-pressed", String(match.mode === mode));
                    control.dataset.matchMode = mode;
                    modes.append(control);
                }
                const remove = button(
                    `Remove ${relation.label} condition`,
                    () =>
                        mutate(
                            () => {
                                delete definition.existence[id];
                            },
                            () => panels.filters.querySelector("[data-report-match-picker]")?.focus(),
                        ),
                    "btn-sm btn-outline-secondary",
                );
                remove.replaceChildren(icon("trash"));
                remove.title = `Remove ${relation.label} condition`;
                remove.setAttribute("aria-label", `Remove ${relation.label} condition`);
                modes.append(remove);
                const moveOptions = node("details", { class: "report-match-move" }, node("summary", {}, "Move"));
                const destination = node("select", {
                    class: "form-select form-select-sm",
                    "aria-label": `Move ${relation.label} to group`,
                });
                groupList.forEach((entry, index) =>
                    destination.append(
                        node(
                            "option",
                            {
                                value: String(index),
                                selected: entry.path.join(".") === path.join("."),
                            },
                            entry.label,
                        ),
                    ),
                );
                moveOptions.append(
                    labeled("Destination group", destination),
                    button(
                        "Move to group",
                        () =>
                            mutate(
                                () => matchingLogic.move(id, groupList[Number(destination.value)].path),
                                () => focusMatch(id),
                            ),
                        "btn-sm btn-outline-secondary",
                    ),
                );
                const location = matchingLogic.location(id);
                for (const [delta, label, glyph] of [
                    [-1, "up", "arrow-up"],
                    [1, "down", "arrow-down"],
                ]) {
                    const move = button(
                        `Move ${relation.label} ${label}`,
                        () =>
                            mutate(
                                () => matchingLogic.shift(id, delta),
                                () => focusMatch(id),
                            ),
                        "btn-sm btn-outline-secondary",
                    );
                    move.replaceChildren(icon(glyph));
                    move.setAttribute("aria-label", `Move ${relation.label} ${label}`);
                    move.title = `Move ${relation.label} ${label}`;
                    move.disabled =
                        location.index + delta < 0 || location.index + delta >= location.group.rules.length;
                    moveOptions.append(move);
                }
                panel.append(modes, moveOptions);
                next.existence[id] = conditionBuilder(
                    panel,
                    matchingFields(id),
                    match.filters,
                    `Matching ${relation.label.toLowerCase()}`,
                    changed,
                );
                dropTarget(panel, (payload) => {
                    if (payload.type === "match")
                        mutate(
                            () => matchingLogic.move(payload.relation, path, id),
                            () => focusMatch(payload.relation),
                        );
                });
                return panel;
            }
            function renderGroup(current, path) {
                const entry = groupList.find((item) => item.group === current);
                const group = node(
                    "section",
                    { class: "report-match-group", "data-match-group": path.join(".") },
                    node("h3", { tabindex: "-1" }, entry.label),
                );
                const tools = node("div", {
                    class: "report-match-group-tools",
                    role: "group",
                    "aria-label": `${entry.label} logic`,
                });
                for (const [value, label] of [
                    ["AND", "All"],
                    ["OR", "Any"],
                ]) {
                    const toggle = button(
                        label,
                        () =>
                            mutate(
                                () => (matchingLogic.group(path).condition = value),
                                () => focusGroup(path),
                            ),
                        current.condition === value ? "btn-sm btn-primary" : "btn-sm btn-outline-primary",
                    );
                    toggle.setAttribute("aria-pressed", String(current.condition === value));
                    tools.append(toggle);
                }
                if (path.length < 3 && groupList.length < 7)
                    tools.append(
                        button(
                            "Add group",
                            () =>
                                mutate(
                                    () => matchingLogic.addGroup(path),
                                    () => focusGroup(path),
                                ),
                            "btn-sm btn-outline-secondary",
                        ),
                    );
                if (path.length)
                    tools.append(
                        button(
                            current.rules.length ? "Ungroup" : "Remove group",
                            () =>
                                mutate(
                                    () => matchingLogic.ungroup(path),
                                    () => focusGroup(path.slice(0, -1)),
                                ),
                            "btn-sm btn-outline-secondary",
                        ),
                    );
                group.append(
                    tools,
                    node(
                        "p",
                        { class: "form-text" },
                        current.condition === "AND"
                            ? "Every condition in this group must match."
                            : "At least one condition in this group must match.",
                    ),
                );
                const contents = node("div", { class: "report-match-group-content" });
                group.append(contents);
                current.rules.forEach((rule, index) =>
                    contents.append(
                        rule.condition ? renderGroup(rule, [...path, index]) : renderMatch(rule.relation, path),
                    ),
                );
                if (!current.rules.length)
                    contents.append(
                        node(
                            "p",
                            { class: "report-match-empty" },
                            path.length
                                ? "Move a condition here, or remove this empty group before previewing."
                                : "Add a record source above to begin.",
                        ),
                    );
                dropTarget(contents, (payload) => {
                    if (payload.type === "match")
                        mutate(
                            () => matchingLogic.move(payload.relation, path),
                            () => focusMatch(payload.relation),
                        );
                });
                return group;
            }
            matching.append(renderGroup(definition.existence_logic, []));
            const advanced = node(
                "details",
                { class: "report-advanced-filters" },
                node("summary", {}, "Advanced join filters"),
            );
            advanced.append(
                node(
                    "p",
                    { class: "form-text" },
                    "Limit the related rows joined into your output. Use Related records above when you only need to check whether a match exists.",
                ),
            );
            filtersBody.append(advanced);
            Object.entries(dataset.relationships).forEach(([id, relation]) => {
                const local = Object.fromEntries(fieldsFor(id));
                if (!enabled.has(id) && !definition.join_filters[id]) return;
                if ((!relation.many && !definition.join_filters[id]) || !Object.keys(local).length) return;
                const panel = node(
                    "section",
                    { class: "report-filter-section" },
                    node("h2", {}, `Related ${relation.label.toLowerCase()} only`),
                    node(
                        "p",
                        {},
                        "These conditions limit related matches. “Keep all” still includes starting records with no matches.",
                    ),
                );
                advanced.append(panel);
                next.joins[id] = conditionBuilder(
                    panel,
                    local,
                    definition.join_filters[id] || null,
                    `${relation.label} conditions`,
                    changed,
                );
            });
            readers = next;
            restoring = false;
        }
        function collect() {
            if (!definition.columns.length) throw new Error("Choose at least one field for your report.");
            syncFilters();
            matchingLogic.normalize();
            matchingLogic.assertReady();
            return structuredClone(definition);
        }
        async function showPage(offset = 0) {
            const data = collect(),
                revision = serial,
                attempt = ++previewSerial;
            status.textContent = "Preparing your preview…";
            let result;
            try {
                result = await request("/sis/report-studio/preview/", "POST", { definition: data, offset });
            } catch (error) {
                if (!surface.isConnected || attempt !== previewSerial) return;
                if ([403, 404].includes(error.status)) {
                    chart?.destroy();
                    chart = null;
                    preview.replaceChildren(node("p", {}, "This preview is no longer available to your account."));
                }
                throw error;
            }
            if (!surface.isConnected || attempt !== previewSerial) return;
            if (revision !== serial) {
                status.textContent = "The draft changed during the preview. Preview again for current choices.";
                return;
            }
            renderResults(preview, result, showPage);
            preview.classList.remove("report-results-stale");
            selectTab("preview");
            status.textContent = "Preview updated. Save the definition when you are ready to share it.";
        }
        if (can("sis:reporting:run")) actions.append(button("Preview", () => showPage(), "btn-primary"));
        if (can("sis:reporting:manage")) {
            const save = button(
                "Save",
                async () => {
                    if (!pending) {
                        if (!name.value.trim()) throw new Error("Give this report a name before saving.");
                        const data = collect(),
                            capturedName = name.value.trim();
                        frozen = true;
                        frozenState = [
                            ...body.querySelectorAll("input, select, button, textarea"),
                            ...actions.querySelectorAll("button"),
                        ].map((control) => [
                            control,
                            control.disabled,
                        ]);
                        frozenState.forEach(([control]) => {
                            if (![save, check].includes(control)) control.disabled = true;
                        });
                        try {
                            const reportId = saved?.id || (await request("/sis/ui/operation-id/")).id;
                            const operationId = saved ? (await request("/sis/ui/operation-id/")).id : reportId;
                            pending = {
                                report_id: reportId,
                                operation_id: operationId,
                                version: saved?.version ?? -1,
                                name: capturedName,
                                definition: data,
                            };
                        } catch (error) {
                            unlock();
                            throw error;
                        }
                    }
                    check.hidden = false;
                    status.textContent = `Saving report. If the connection is interrupted, retry or check the saved report. Reference: ${pending.report_id}`;
                    try {
                        await request("/sis/report-studio/save/", "POST", pending);
                    } catch (error) {
                        if ([400, 403, 404, 409, 422].includes(error.status)) {
                            pending = null;
                            unlock();
                        }
                        throw error;
                    }
                    if (surface.isConnected) await record(pending.report_id);
                },
                "btn-outline-primary",
            );
            const check = button("Check save", async () => {
                if (!pending) throw new Error("Save this draft before checking its reference.");
                await record(pending.report_id);
            });
            check.hidden = true;
            function unlock() {
                check.hidden = true;
                frozen = false;
                frozenState?.forEach(([control, disabled]) => {
                    control.disabled = disabled;
                });
                frozenState = null;
            }
            actions.append(save, check);
        }
        renderLibrary();
        renderMap();
        renderColumns();
        selectTab("design");
    }
    function renderResults(panel, result, load, exportPage = null) {
        chart?.destroy();
        chart = null;
        panel.replaceChildren(
            node("h3", {}, "Your report preview"),
            node(
                "p",
                { role: "status" },
                `${result.items.length} rows on this page${result.next_offset !== null ? "; more are available" : ""}. ${result.lifecycle?.mode === "static" ? "Saved static results" : "Live results"} · page ${result.offset / 50 + 1}.`,
            ),
        );
        const selectionNote = rowSelectionUi.describe(result.row_selection);
        if (selectionNote) panel.append(selectionNote);
        if (!result.items.length) panel.append(node("p", {}, result.lifecycle?.mode === "static"
            ? "This static report captured no matching records."
            : "No records match yet. Try refining your conditions."));
        else
            panel.append(
                grid(
                    result.columns,
                    result.items.map((row) =>
                        Object.values(row).map((value) =>
                            value === null ? "—" : typeof value === "boolean" ? (value ? "Yes" : "No") : String(value),
                        ),
                    ),
                    "Relational report results",
                ),
            );
        if (result.grouped && result.items.length && window.Chart) {
            const numeric = Object.keys(result.items[0]).find((key) => typeof result.items[0][key] === "number");
            const label = Object.keys(result.items[0]).find((key) => typeof result.items[0][key] === "string");
            if (numeric && label) {
                const canvas = node("canvas", {
                    role: "img",
                    "aria-label": "Bar chart of this result page. Exact values are in the report table.",
                });
                panel.append(node("div", { class: "report-chart" }, canvas));
                chart = new Chart(canvas, {
                    type: "bar",
                    data: {
                        labels: result.items.map((row) => row[label] || "Not specified"),
                        datasets: [
                            {
                                label: result.columns[Number(numeric.slice(1))],
                                data: result.items.map((row) => row[numeric]),
                                backgroundColor: "#2463a6",
                                borderRadius: 5,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: false,
                        plugins: { legend: { display: false } },
                        scales: { y: { beginAtZero: true } },
                    },
                });
            }
        }
        const controls = node("div", { class: "inline-actions" });
        panel.append(controls);
        if (result.offset) controls.append(button("Previous result page", () => load(result.offset - 50)));
        if (result.next_offset !== null) controls.append(button("Next result page", () => load(result.next_offset)));
        if (exportPage) controls.append(button("Export page", () => exportPage(result.offset)));
        panel.append(
            node(
                "p",
                { class: "form-text" },
                result.lifecycle?.mode === "static"
                    ? "These captured results do not change. The preview and CSV export include one saved page."
                    : "Records may change as staff update them. The preview and CSV export include only the current page.",
            ),
        );
    }
    const populationTasks = {
        registration: {
            permissions: ["sis:registration:manage"], stage: "registration review",
            explanation: "Report access does not grant registration authority. No enrollment or charge is created here. Registration uses each student's current primary pursuit. The group remains editable and checks current eligibility, seats and fees before confirmation.",
            open: (students, back) => ui.registerPopulation(students, back),
        },
        billing: {
            permissions: ["sis:finance:post", "sis:finance:read"], stage: "billing review",
            explanation: "Report access does not grant billing authority. No charge is created here. The billing group remains editable; choose a term and review current fees, earlier postings and resulting balances before confirmation. Program fees use each student's current primary pursuit.",
            open: (students, back) => ui.billPopulation(students, back),
        },
        messaging: {
            permissions: ["sis:communications:manage", "sis:person:manage"], stage: "message composer",
            explanation: "Report access does not grant messaging authority. Current person accounts are checked before composing. Save and review your wording and recipients before queueing; sending uses a separate background-delivery confirmation. Report values are not copied into the message.",
            open: (students, back) => ui.messagePopulation(students, back),
        },
    };
    async function population(id, action, state = null) {
        if (!Object.hasOwn(populationTasks, action)) throw new Error("Choose a supported student task.");
        const workflow = populationTasks[action];
        state ||= { selected: new Map(), version: null, offset: 0 };
        const body = openSpace(`Choose students for ${action}`, "SAVED REPORT · STUDENT POPULATION");
        body.append(node("p", {}, `Choose up to 25 distinct students across this report's live population pages. Repeated program or credit rows count as one student. The selection is checked against the saved report again before opening the ${workflow.stage}.`),
            node("p", { class: "form-text" }, workflow.explanation));
        const selected = node("section", { class: "record-section", "aria-label": "Selected report students" });
        const status = node("p", { role: "status", "aria-live": "polite" });
        const results = node("div"), navigation = node("div", { class: "inline-actions" });
        let loading = false, requestNumber = 0, checks = new Map(), next = null;
        const forget = () => {
            state.selected.clear();
            state.version = null;
            checks.clear();
            results.replaceChildren();
            navigation.replaceChildren();
            next = null;
            renderSelected();
        };
        const continueButton = button(`Review selected students for ${action}`, async () => {
            if (loading) return;
            loading = true;
            renderSelected();
            const attempt = ++requestNumber;
            try {
                const result = await request(`/sis/report-studio/${id}/population/select/`, "POST", {
                    student_ids: [...state.selected.keys()], report_version: state.version, action,
                });
                if (!body.isConnected || attempt !== requestNumber) return;
                await workflow.open(result.items, () => population(id, action, state));
            } catch (error) {
                if (!body.isConnected || attempt !== requestNumber) return;
                if ([403, 404, 409].includes(error.status)) {
                    forget();
                    status.textContent = error.status === 409
                        ? "The report or matching students changed. Refresh the student population and choose again."
                        : "Student selection is unavailable. Review access and refresh the student population.";
                }
                throw error;
            } finally {
                loading = false;
                if (body.isConnected) renderSelected();
            }
        }, "btn-primary", () => { if (body.isConnected) renderSelected(); });
        function renderSelected() {
            selected.replaceChildren(node("h2", { class: "h5" }, `${state.selected.size} of 25 students selected`),
                ...[...state.selected.values()].map(student => node("p", {},
                    `${student.institutional_id || "ID not assigned"} · ${student.name} `,
                    button(`Remove ${student.institutional_id || student.name} from selection`, async () => {
                        if (loading) return;
                        state.selected.delete(student.id);
                        if (checks.has(student.id)) checks.get(student.id).checked = false;
                        renderSelected();
                        selected.tabIndex = -1;
                        selected.focus();
                    }, "btn-outline-primary"))));
            selected.querySelectorAll("button").forEach(control => { control.disabled = loading; });
            for (const control of checks.values()) control.disabled = loading;
            navigation.querySelectorAll("button").forEach(control => { control.disabled = loading; });
            continueButton.disabled = loading || !state.selected.size;
        }
        async function load(offset) {
            if (loading) return;
            loading = true;
            const attempt = ++requestNumber;
            renderSelected();
            navigation.replaceChildren();
            status.textContent = "Loading matching students…";
            try {
                const page = await request(`/sis/report-studio/${id}/population/`, "POST", { offset, action });
                if (!body.isConnected || attempt !== requestNumber) return;
                const changed = state.version !== null && state.version !== page.report_version;
                if (changed) state.selected.clear();
                state.version = page.report_version;
                state.offset = offset;
                next = page.next_offset;
                checks = new Map();
                const rows = page.items.map(student => {
                    const check = node("input", { type: "checkbox", class: "form-check-input",
                        id: `population-${student.id}` });
                    check.checked = state.selected.has(student.id);
                    check.addEventListener("change", () => {
                        if (loading) { check.checked = state.selected.has(student.id); return; }
                        if (check.checked && !state.selected.has(student.id) && state.selected.size >= 25) {
                            check.checked = false;
                            status.textContent = `Choose at most 25 students for one ${action} group.`;
                            return;
                        }
                        if (check.checked) state.selected.set(student.id, student);
                        else state.selected.delete(student.id);
                        renderSelected();
                    });
                    checks.set(student.id, check);
                    return [node("div", { class: "form-check" }, check,
                        node("label", { for: check.id, class: "form-check-label" },
                            `Select ${student.institutional_id || "student"} · ${student.name}`)),
                        student.active ? "Active" : "Inactive"];
                });
                results.replaceChildren(rows.length ? node("div", { class: "table-responsive", tabindex: "0",
                    role: "region", "aria-label": "Matching report students" },
                    table(["Student", "Activity"], rows, "Distinct students matching the saved report"))
                    : node("p", {}, "No students currently match this report."));
                status.textContent = `${changed ? "The report definition changed; the previous selection was cleared. " : ""}Page ${offset / 50 + 1} · ${page.items.length} students. ${next === null ? "End of population." : "More students are available."}`;
                if (offset) navigation.append(button("Previous population page", () => load(offset - 50)));
                if (next !== null) navigation.append(button("Next population page", () => load(next)));
            } catch (error) {
                if (!body.isConnected || attempt !== requestNumber) return;
                if ([403, 404].includes(error.status)) forget();
                status.textContent = "The student population could not be loaded. Review access or refresh it.";
                throw error;
            } finally {
                loading = false;
                if (body.isConnected) renderSelected();
            }
        }
        body.append(selected, continueButton,
            button("Refresh student population", () => load(0)), status, results, navigation,
            button("Back to saved report", () => record(id)));
        renderSelected();
        await load(state.offset);
    }
    async function settings(id) {
        const saved = await request(`/sis/report-studio/${id}/settings/`);
        const body = openSpace(`${saved.name} · Report settings`, "RESULTS & AUTOMATIC EXPIRY");
        const lifecycle = saved.lifecycle || { mode: "dynamic", expires_at: null };
        const form = node("form"), modes = node("fieldset", { class: "record-section" }, node("legend", { class: "h5" }, "How should results behave?"));
        for (const [value, label, description] of [
            ["dynamic", "Dynamic report", "Refresh results from current data each time the report runs."],
            ["static", "Static one-time report", "Capture the complete results once. Later record changes do not change the saved results."],
        ]) {
            const input = node("input", { type: "radio", name: "report_mode", id: `report-mode-${value}`,
                value, class: "form-check-input", checked: lifecycle.mode === value,
                disabled: lifecycle.mode === "static" && value === "dynamic" });
            modes.append(node("div", { class: "form-check mb-3" }, input,
                node("label", { for: input.id, class: "form-check-label" }, label),
                node("p", { class: "form-text" }, description)));
        }
        const temporary = node("input", { type: "checkbox", id: "report-temporary", class: "form-check-input",
            checked: !!lifecycle.expires_at });
        const days = node("input", { type: "number", id: "report-expiry-days", class: "form-control",
            min: "1", max: "3650", step: "1", value: lifecycle.expires_at ?
                Math.max(1, Math.ceil((Date.parse(lifecycle.expires_at) - Date.now()) / 86400000)) : 30 });
        const expiry = node("div", { class: "mb-3" },
            node("label", { for: days.id, class: "form-label" }, "Remove after this many days"), days,
            node("p", { class: "form-text" }, "Saving starts this removal period from now. Leave temporary reporting off to keep the report. Academic records and linked office cases are preserved."));
        const updateExpiry = () => { expiry.hidden = !temporary.checked; days.required = temporary.checked; };
        temporary.addEventListener("change", updateExpiry);
        updateExpiry();
        form.append(modes, node("div", { class: "form-check mb-3" }, temporary,
            node("label", { for: temporary.id, class: "form-check-label" }, "This is a temporary / one-off report; remove it automatically")), expiry);
        if (lifecycle.expires_at) form.append(node("p", {}, `Current removal time: ${lifecycle.expires_at}.`));
        body.append(node("p", {}, "Result type and automatic removal are separate choices. Either a static or dynamic report can be temporary."),
            node("p", { class: "form-text" }, "Static capture currently supports complete reports up to 5,000 rows and 8 MiB. Larger captures stop with no partial report saved. A captured report stays static; create a new report from its definition to change it."), form);
        const status = node("p", { role: "status", "aria-live": "polite" });
        const save = node("button", { type: "submit", class: "btn btn-primary" }, "Save report settings");
        form.append(save, status);
        let pending = null;
        form.addEventListener("submit", event => {
            event.preventDefault();
            task(async () => {
                try {
                    if (!form.reportValidity()) return;
                    if (!pending) pending = { operation_id: (await request("/sis/ui/operation-id/")).id,
                        version: saved.version, mode: form.querySelector('[name="report_mode"]:checked').value,
                        expires_after_days: temporary.checked ? days.value : "" };
                    for (const control of form.querySelectorAll("input")) control.disabled = true;
                    status.textContent = pending.mode === "static" ? "Preparing and protecting the complete static report…" : "Saving report settings…";
                    await request(`/sis/report-studio/${id}/settings/`, "POST", pending);
                    if (body.isConnected) await record(id);
                } catch (error) {
                    if (!body.isConnected) return;
                    status.textContent = "No completed change was confirmed. Retry this save safely, or reload settings after a conflict.";
                    if ([400, 403, 404, 409, 422].includes(error.status)) {
                        pending = null;
                        for (const control of form.querySelectorAll("input")) control.disabled = false;
                        const dynamic = form.querySelector('#report-mode-dynamic');
                        if (lifecycle.mode === "static") dynamic.disabled = true;
                    }
                    localError(error);
                }
            }, save);
        });
        body.append(button("Reload report settings", () => settings(id)), button("Back to report", () => record(id)));
    }
    async function record(id) {
        const saved = await request(`/sis/report-studio/${id}/`);
        const body = openSpace(saved.name, saved.can_manage ? "SAVED REPORT · CREATOR WORKSPACE" : "SHARED WITH YOU");
        body.classList.add("report-saved-view");
        body.append(
            node(
                "p",
                {},
                `Definition revision ${saved.version + 1}. Source access is checked each time this report runs.`,
            ),
        );
        const frozen = saved.lifecycle?.mode === "static";
        body.append(node("p", { role: "status" }, frozen
            ? `Static report · ${saved.lifecycle.row_count} captured rows · ${saved.lifecycle.captured_at}. Results do not refresh from current data.`
            : "Dynamic report · results reflect current data."));
        if (frozen) {
            const selectionNote = rowSelectionUi.describe(saved.row_selection);
            if (selectionNote) body.append(selectionNote);
        }
        if (saved.lifecycle?.expires_at) body.append(node("p", {}, `Automatic removal: ${saved.lifecycle.expires_at}.`));
        const recordTools = node("div", { class: "report-saved-tools" });
        const options = [], studentActions = [];
        body.append(recordTools);
        if (saved.can_manage) options.push(button("Report settings", () => settings(id)));
        if (saved.can_manage && !frozen)
            recordTools.append(
                button("Edit", async () => {
                    const catalog = await request("/sis/report-studio/catalog/"),
                        dataset = catalog.datasets.find((d) => d.id === saved.definition.dataset);
                    if (!dataset) throw new Error("This source is no longer available to your account.");
                    await edit(dataset, saved.definition, saved);
                }),
            );
        if (saved.can_manage && frozen) recordTools.append(button("Make a copy", async () => {
            const catalog = await request("/sis/report-studio/catalog/");
            const dataset = catalog.datasets.find(item => item.id === saved.definition.dataset);
            if (!dataset) throw new Error("This source is no longer available to your account.");
            await edit(dataset, saved.definition);
        }));
        if (can("sis:reporting:manage") && saved.can_manage)
            options.push(button("Share", () => ui.sharing(id)));
        if (can("sis:student:read") && can("sis:reporting:run")) {
            for (const [action, workflow] of Object.entries(populationTasks)) {
                if (saved.student_actions?.includes(action) && workflow.permissions.every(permission => can(permission)))
                    studentActions.push(button(`Choose students for ${action}`, () => population(id, action)));
            }
        }
        if (studentActions.length) recordTools.append(ui.actionMenu("Use students", studentActions));
        if (options.length) recordTools.append(ui.actionMenu("Report options", options));
        const panel = node("section", {
            class: "record-section report-saved-results",
            "aria-label": "Saved report results",
        });
        body.append(panel);
        if (!can("sis:reporting:run")) {
            panel.append(node("p", {}, "Executing results requires report-run permission."));
            return;
        }
        let resultGeneration = 0;
        const load = async (offset) => {
            const attempt = ++resultGeneration;
            chart?.destroy();
            chart = null;
            panel.replaceChildren(node("p", { role: "status" }, "Loading report results…"));
            let result;
            try {
                result = await request(`/sis/report-studio/${id}/run/`, "POST", { offset });
            } catch (error) {
                if (!panel.isConnected || attempt !== resultGeneration) return;
                throw error;
            }
            if (!panel.isConnected || attempt !== resultGeneration) return;
            renderResults(panel, result, load, async (cursor) => {
                try {
                    await requestFile(`/sis/report-studio/${id}/export/`, { offset: cursor }, "report-studio-page.csv",
                        { maximum: frozen ? 10 * 1024 * 1024 : 262144 });
                } catch (error) {
                    if (!panel.isConnected) return;
                    if ([403, 404].includes(error.status)) {
                        resultGeneration++;
                        chart?.destroy();
                        chart = null;
                        panel.replaceChildren(node("p", {}, "This report is no longer available to your account."));
                    }
                    throw error;
                }
            });
        };
        recordTools.prepend(button(frozen ? "Read results" : "Refresh results", () => load(0), "btn-primary"));
        await load(0);
    }
    return { start, record, settings, cleanup, isOpen: () => !!dialog };
};
