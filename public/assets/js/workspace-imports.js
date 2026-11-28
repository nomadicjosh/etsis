/* One reviewed catalog-import workflow. CSV and approvals remain only in page memory. */
window.createWorkspaceImports = function (ui) {
    "use strict";
    const { node, table, request, notify, number, task, can, subjects, classifications, recordSummary, jobs, labels: importLabels = {} } = ui;
    const one = (selector) => document.querySelector(selector);
    const all = (selector) => [...document.querySelectorAll(selector)];
    const target = () => one("#import-target").value;
    const program = () => target() === "program";
    const course = () => target() === "course";
    const reference = () => !program() && !course();
    const referenceNames = {
        subject: ["Subject", "Subjects"],
        department: ["Department", "Departments"],
        academic_level: ["Academic level", "Academic levels"],
        course_level: ["Course level", "Course levels"],
        award: ["Award", "Degrees & awards"],
        instructional_method: ["Instructional method", "Instructional methods"],
        campus: ["Campus/site", "Campuses & sites"],
        school: ["School/college", "Schools & colleges"],
        field_of_study: ["Field of study", "Fields of study"],
    };
    const name = () => (program() ? "Program" : course() ? "Course" : referenceNames[target()][0]);
    const plural = () =>
        program() ? "programs" : course() ? "courses" : referenceNames[target()][1].toLowerCase();
    const idField = () => (reference() && target() !== "subject" ? "classification_id" : `${target()}_id`);
    const requiredFields = () => ["external_id", "code", "title", ...(course() ? ["credits"] : [])];
    function destination() {
        one("#import-sample").href = `/assets/examples/${reference() ? "catalog-references" : plural()}.csv`;
        one("#import-description").textContent =
            `Start with a ${name().toLowerCase()} CSV. UTF-8, up to 2 MB and 5,000 rows.`;
        one("#import-boundary").textContent = reference()
            ? "Create active reference entries or explicitly link matching active entries. Existing entries are never overwritten or reactivated. Historical publications keep their original labels."
            : program()
              ? "Create working program definitions or explicitly link matching existing programs. Imports do not publish an academic year, replace student cohorts or assign application forms. Existing program details and publications are preserved."
              : "New courses are inactive drafts. Approve matching course links explicitly, or leave rows out. Existing courses and historical publications are preserved.";
        one("#import-commit-label").textContent = importLabels.catalog_queue;
        one("#import-reference-subjects").hidden = !course();
        ["program-fields", "campus", "award"].forEach((kind) => {
            one(`#import-reference-${kind}`).hidden = !program();
        });
        one("#import-catalog-setup").hidden = reference() || !can("sis:catalog:manage");
        one('#import-plan-filter option[value="create"]').textContent = reference()
            ? "New entries"
            : "New drafts";
        const link = one("#import-draft-link");
        link.hidden = reference() || !can("sis:catalog:manage");
        link.href = `/admin/workspace/${plural()}/`;
        link.dataset.workspace = plural();
        one("#import-draft-label").textContent = program()
            ? "Review program details"
            : "Review course drafts";
        one("#import-reference-open").hidden = !reference() || !can("sis:catalog:manage");
        one("#import-reference-open").textContent = `Review ${plural()}`;
    }
    let latestJob = null;
    let state,
        busy = false,
        page = 0,
        serial = 0;
    const labels = {
        create: "New draft",
        match: "Link decision needed",
        link: "Approved link",
        skip: "Previously imported",
        omitted: "Left out",
        conflict: "Conflict",
    };
    const reset = () => {
        serial++;
        state = {
            csv: "",
            mapping: {},
            choices: new Map(),
            reviewedChoices: new Map(),
            preview: null,
            reviewedRequest: null,
        };
        page = 0;
    };
    reset();
    function step(value) {
        all("[data-import-panel]").forEach((panel) => {
            panel.hidden = Number(panel.dataset.importPanel) !== value;
        });
        all("[data-import-step]").forEach((item) => {
            const active = Number(item.dataset.importStep) === value;
            item.classList.toggle("active", active);
            if (active) item.setAttribute("aria-current", "step");
            else item.removeAttribute("aria-current");
        });
        const heading = one(`[data-import-panel="${value}"] h2`);
        heading?.setAttribute("tabindex", "-1");
        if (!one('[data-panel="imports"]').hidden) {
            one(".import-steps").scrollIntoView({
                block: "start",
                behavior: "instant",
            });
            heading?.focus({ preventScroll: true });
        }
    }
    function payload() {
        return {
            target: one("#import-target").value,
            source: one("#import-source").value,
            csv: state.csv,
            mapping: { ...state.mapping },
            delimiter: one("#import-delimiter").value === "tab" ? "\t" : one("#import-delimiter").value,
            choices: [...state.choices].map(([external_id, choice]) => ({
                external_id,
                ...choice,
            })),
        };
    }
    const pending = () =>
        JSON.stringify([...state.choices].sort()) !== JSON.stringify([...state.reviewedChoices].sort());
    function synchronize() {
        all(
            "[data-import-back], #import-inspect, #import-review, #import-refresh, #import-again, #import-reference-subjects, #import-reference-classes, #import-reference-program-fields, #import-reference-campus, #import-reference-award, .import-choice-action, #import-target, #import-source, #import-file, #import-delimiter, #import-mapping select, #import-plan-filter, #import-plan-search",
        ).forEach((control) => {
            control.disabled = busy;
        });
        one("#import-commit").disabled =
            busy || !state.preview?.ready || !state.preview?.review_token || pending();
        one("#import-choice-status").textContent = pending()
            ? "Choices changed. Select Review choices before confirming the import."
            : state.preview?.review_token
              ? "This review expires in 20 minutes. Catalog changes require a fresh review."
              : "";
    }
    function action(selector, callback) {
        one(selector)?.addEventListener("click", (event) => {
            if (busy) return;
            const button = event.currentTarget;
            task(async () => {
                busy = true;
                button.setAttribute("aria-busy", "true");
                synchronize();
                try {
                    await callback();
                } finally {
                    busy = false;
                    button.removeAttribute("aria-busy");
                    synchronize();
                }
            });
        });
    }
    function choose(row, choice) {
        if (busy) return;
        if (choice) state.choices.set(row.external_id, choice);
        else state.choices.delete(row.external_id);
        render();
        synchronize();
    }
    function render() {
        const plan = state.preview?.plan || [],
            filter = one("#import-plan-filter").value;
        const query = one("#import-plan-search").value.trim().toLowerCase();
        const rows = plan.filter(
            (row) =>
                (filter === "all" ||
                    (filter === "unresolved"
                        ? ["match", "conflict"].includes(row.action)
                        : row.action === filter)) &&
                `${row.external_id} ${row.code} ${row.title}`.toLowerCase().includes(query),
        );
        page = Math.max(0, Math.min(page, Math.ceil(rows.length / 25) - 1));
        const controls = (row) => {
            const box = node("div", { class: "inline-actions" }),
                choice = state.choices.get(row.external_id);
            const button = (label, callback, accessibleName) =>
                node(
                    "button",
                    {
                        type: "button",
                        class: "btn btn-sm btn-outline-primary import-choice-action",
                        disabled: busy,
                        onclick: callback,
                        "aria-label": accessibleName,
                    },
                    label,
                );
            if (choice)
                box.append(
                    button(
                        choice.action === "skip" ? "Include" : "Undo link",
                        () => choose(row, null),
                        choice.action === "skip" ? `Include row ${row.row}` : `Undo link for row ${row.row}`,
                    ),
                );
            else if (["match", "link"].includes(row.action))
                box.append(
                    button(
                        `Link ${name().toLowerCase()}`,
                        () =>
                            choose(row, {
                                action: "link",
                                [idField()]: row[idField()],
                            }),
                        `Link ${name().toLowerCase()} for row ${row.row}`,
                    ),
                );
            if (choice?.action !== "skip")
                box.append(
                    button(
                        "Leave out",
                        () => choose(row, { action: "skip", [idField()]: null }),
                        `Leave out row ${row.row}`,
                    ),
                );
            return box;
        };
        const content = rows.slice(page * 25, page * 25 + 25).map((row) => {
            const changed =
                JSON.stringify(state.choices.get(row.external_id) || null) !==
                JSON.stringify(state.reviewedChoices.get(row.external_id) || null);
            const review = node(
                "div",
                {},
                node(
                    "strong",
                    {},
                    changed
                        ? "Choice changed — review needed"
                        : row.action === "create" && reference()
                          ? "New entry"
                          : labels[row.action],
                ),
                node("p", { class: "mb-1" }, row.message),
            );
            const matched = row[`matched_${target()}`];
            if (matched)
                review.append(
                    node(
                        "p",
                        { class: "form-text mb-0" },
                        !course()
                            ? `Existing: ${matched.code} · ${matched.title}`
                            : `Existing: ${matched.code} · ${matched.title} · ${number(matched.credits_milli / 1000)} credits · Number: ${matched.course_number || 'Not specified'} · ${matched.active ? "Active" : "Inactive"}`,
                    ),
                );
            const record = node(
                "div",
                {},
                node("strong", {}, row.code),
                node("div", {}, row.title),
                node("small", {}, `Source ID: ${row.external_id}`),
            );
            if (Object.keys(row.source_references || {}).length) {
                const names = {
                    subject: "Subject",
                    department: "Department",
                    academic_level: "Academic level",
                    course_level: "Course level",
                    school: "School/college",
                    field_of_study: "Field of study",
                    campus: "Campus/site",
                    award: "Award",
                };
                const list = node("ul", { class: "mb-0 mt-2" });
                for (const [kind, code] of Object.entries(row.source_references)) {
                    const resolved = row.references?.[kind];
                    list.append(
                        node("li", {}, `${names[kind]}: ${code}${resolved ? " · " + resolved.title : ""}`),
                    );
                }
                record.append(
                    node("details", { class: "mt-2" }, node("summary", {}, "Catalog classifications"), list),
                );
            }
            if (course() && row.number_mapped) record.append(node('p', {}, `Course number: ${row.course_number || 'Not specified'}`));
            if (program() && row.description_mapped)
                record.append(
                    node(
                        "details",
                        { class: "mt-2" },
                        node("summary", {}, "Program description"),
                        node("p", { class: "course-description-text" }, row.description || "Not specified"),
                    ),
                );
            return [
                String(row.row),
                record,
                ...(course() ? [number(row.credits_milli / 1000)] : []),
                review,
                controls(row),
            ];
        });
        one("#import-plan-table").replaceChildren(
            table(
                ["Row", name(), ...(course() ? ["Credits"] : []), "Review", "Action"],
                content,
                `Reviewed ${name().toLowerCase()} import rows`,
            ),
        );
        one("#import-plan-status").textContent = rows.length
            ? `${page * 25 + 1}–${Math.min(rows.length, (page + 1) * 25)} of ${number(rows.length)} matching rows.`
            : "No import rows match this filter.";
        one("#import-plan-previous").disabled = page === 0;
        one("#import-plan-next").disabled = (page + 1) * 25 >= rows.length;
    }
    async function review() {
        const input = payload(),
            token = ++serial;
        const preview = await request("/sis/imports/preview/", "POST", input);
        if (token !== serial) return;
        state.preview = preview;
        state.reviewedRequest = input;
        state.reviewedChoices = new Map(state.choices);
        page = 0;
        one("#import-review-title").textContent = preview.ready
            ? "Your import is ready for confirmation."
            : "Review the rows that need attention.";
        const summary = preview.summary;
        one("#import-plan-summary").replaceChildren(
            ...(summary
                ? [
                      [reference() ? "New entries" : "New drafts", summary.create],
                      ["Approved links", summary.link],
                      ["Prior imports", summary.skip],
                      ["Left out", summary.omitted],
                      ["Needs attention", summary.match + summary.conflict],
                  ].map(([label, count]) =>
                      node("div", {}, node("dt", {}, label), node("dd", {}, number(count))),
                  )
                : []),
        );
        one("#import-catalog-setup").open = (preview.plan || []).some((row) => row.reference_errors?.length);
        const errors = one("#import-review-errors");
        errors.replaceChildren();
        if (preview.errors.length)
            errors.append(
                node(
                    "div",
                    { class: "alert alert-danger" },
                    node("strong", {}, "Correct these source rows and review the file again."),
                    node(
                        "ul",
                        { class: "mb-0" },
                        ...preview.errors.map((error) => node("li", {}, error.message)),
                    ),
                ),
            );
        one("#import-preview-table").replaceChildren(
            table(preview.headers, preview.samples, "First five source CSV records"),
        );
        one("#import-plan-filter").value = preview.ready ? "all" : "unresolved";
        render();
        synchronize();
        step(3);
    }
    action("#import-inspect", async () => {
        const file = one("#import-file").files[0];
        if (!file || file.size > 2097152 || !file.name.toLowerCase().endsWith(".csv"))
            throw new Error("Choose a CSV file up to 2 MB.");
        if (!one("#import-source").reportValidity()) return;
        reset();
        state.csv = await file.text();
        const preview = await request("/sis/imports/preview/", "POST", payload());
        const mapping = one("#import-mapping");
        mapping.replaceChildren();
        one("#import-file-summary").replaceChildren(
            recordSummary([
                ["File", file.name],
                ["Source", one("#import-source").value],
                [
                    "Source rows",
                    preview.row_count === undefined ? "Not available" : number(preview.row_count),
                ],
            ]),
        );
        const optionalFields = node("div", { class: "mapping-list mt-3" });
        const optional = node(
            "details",
            { class: "mt-4", id: "import-optional-fields" },
            node("summary", {}, program() ? "Add program details" : "Add course details & classifications"),
            node(
                "p",
                { class: "form-text mt-2" },
                "Map existing catalog codes when available. Unmapped course numbers preserve existing values; a mapped blank number matches only an unspecified number. Imports never clear existing details.",
            ),
            node(
                "a",
                {
                    href: program()
                        ? "/assets/examples/programs.csv"
                        : "/assets/examples/courses-classifications.csv",
                    download: true,
                },
                "Example CSV with classifications",
            ),
            optionalFields,
        );
        const core = reference()
            ? [
                  ["external_id", "External ID", "A stable source-system ID; preserve its letter case"],
                  ["code", `${name()} code`, "Up to 40 characters; letters are normalized to uppercase"],
                  ["title", `${name()} name`, "A descriptive name, up to 191 characters"],
              ]
            : program()
              ? [
                    ["external_id", "External ID", "A stable source-system ID; preserve its letter case"],
                    ["code", "Program code", "For example BA.ENG; letters are normalized to uppercase"],
                    ["title", "Program title", "The program name"],
                ]
              : [
                    ["external_id", "External ID", "A stable source-system ID; preserve its letter case"],
                    ["code", "Course code", "For example MATH.101"],
                    ["title", "Course title", "The course name"],
                    ["credits", "Credits", "Decimal credits, such as 3 or 3.5"],
                ];
        const references = reference()
            ? []
            : program()
              ? [
                    ["description", "Program description", "Optional plain text up to 10,000 characters"],
                    ...[
                        ["department", "Department"],
                        ["academic_level", "Academic level"],
                        ["award", "Award"],
                        ["school", "School/college"],
                        ["field_of_study", "Field of study"],
                        ["campus", "Campus/site"],
                    ].map(([kind, label]) => [
                        `${kind}_code`,
                        `${label} code`,
                        "Use an active code from the catalog reference library",
                    ]),
                ]
              : [
                    ['course_number', 'Course number', 'Optional text, such as 001A; preserve leading zeros and suffixes'],
                    [
                        "subject_code",
                        "Subject code",
                        "For example ENG; use an active subject from the catalog",
                    ],
                    ["department_code", "Department code", "Use an active department code"],
                    [
                        "academic_level_code",
                        "Academic level code",
                        "For example UG or GR; use the academic level library",
                    ],
                    ["course_level_code", "Course level code", "Use an active course-level code"],
                ];
        for (const [target, label, help] of [...core, ...references]) {
            const required = core.some((field) => field[0] === target);
            const select = node(
                "select",
                {
                    class: "form-select",
                    id: `map-${target}`,
                    name: target,
                    required,
                    "aria-describedby": `map-${target}-sample`,
                },
                node("option", { value: "" }, required ? "Choose a source column" : "Leave unspecified"),
                ...preview.headers.map((header, index) => node("option", { value: String(index) }, header)),
            );
            const normalized = (value) => value.toLowerCase().replace(/[^a-z0-9]/g, "");
            const index = preview.headers.findIndex(
                (header) =>
                    normalized(header) === normalized(target) || normalized(header) === normalized(label),
            );
            if (index >= 0) {
                select.value = String(index);
                if (!required) optional.open = true;
            }
            const sample = node("small", {
                id: `map-${target}-sample`,
                class: "mapping-sample",
            });
            const showSample = () => {
                const value =
                    select.value === ""
                        ? ""
                        : (preview.samples || [])
                              .map((row) => String(row[Number(select.value)] ?? "").trim())
                              .find(Boolean) || "";
                sample.textContent =
                    select.value === ""
                        ? "No source column selected."
                        : value
                          ? `Example: ${value.slice(0, 120)}${value.length > 120 ? "…" : ""}`
                          : "No example value in the source sample.";
            };
            select.addEventListener("change", showSample);
            showSample();
            (required ? mapping : optionalFields).append(
                node(
                    "div",
                    { class: "mapping-row" },
                    node("label", { for: select.id }, label, node("small", {}, help)),
                    node("i", {
                        class: "bi bi-arrow-right",
                        "aria-hidden": "true",
                    }),
                    node("div", { class: "mapping-source" }, select, sample),
                ),
            );
        }
        if (references.length) mapping.append(optional);
        step(2);
    });
    action("#import-review", async () => {
        const mapping = {};
        for (const select of one("#import-mapping").querySelectorAll("select")) {
            if (!select.reportValidity()) return;
            if (select.value !== "") mapping[select.name] = Number(select.value);
        }
        if (new Set(requiredFields().map((field) => mapping[field])).size !== requiredFields().length)
            throw new Error(`Choose distinct columns for the ${requiredFields().length} required fields.`);
        state.mapping = mapping;
        await review();
    });
    action("#import-refresh", review);
    action("#import-reference-subjects", async () => {
        if (can("sis:catalog:manage")) await subjects();
    });
    action("#import-reference-classes", async () => {
        if (can("sis:catalog:manage")) await classifications();
    });
    for (const [control, kind] of [
        ["program-fields", "school"],
        ["campus", "campus"],
        ["award", "award"],
    ]) {
        action(`#import-reference-${control}`, async () => {
            if (can("sis:catalog:manage")) await classifications(kind);
        });
    }
    action("#import-reference-open", async () => {
        if (!can("sis:catalog:manage")) return;
        if (target() === "subject") await subjects();
        else await classifications(target());
    });
    action("#import-commit", async () => {
        if (!state.preview?.review_token || pending()) return;
        try {
            const result = await request("/sis/imports/commit/", "POST", {
                review_token: state.preview.review_token,
            });
            latestJob = result.id;
            one("#import-summary").textContent = importLabels.queued_summary.replace("{total}", number(result.total));
            reset();
            one("#import-file").value = "";
            step(4);
            notify(importLabels.queued_help, { tone: "info" });
        } catch (error) {
            if ([409, 422].includes(error.status)) state.preview.review_token = null;
            throw error;
        }
    });
    action("#import-job-progress", () => latestJob ? jobs.detail(latestJob) : jobs.activity());
    action("#import-again", () => {
        reset();
        one("#import-file").value = "";
        step(1);
    });
    all("[data-import-back]").forEach((button) =>
        button.addEventListener("click", () => {
            if (!busy) step(Number(button.dataset.importBack));
        }),
    );
    one("#import-plan-filter").addEventListener("change", () => {
        page = 0;
        render();
    });
    one("#import-plan-search").addEventListener("input", () => {
        page = 0;
        render();
    });
    one("#import-plan-previous").addEventListener("click", () => {
        page--;
        render();
    });
    one("#import-plan-next").addEventListener("click", () => {
        page++;
        render();
    });
    one("#import-target").addEventListener("change", () => {
        if (busy) return;
        reset();
        one("#import-file").value = "";
        one("#import-source").value = `${target().replaceAll("_", "-")}-catalog`;
        destination();
        step(1);
        synchronize();
    });
    one("#import-people").hidden = !can("sis:person:manage");
    one("#import-photos").hidden = !(can("sis:person:manage") && can("sis:person:photo:manage"));
    action("#import-photos", () => ui.photoImports.open());
    action("#import-people", () => jobs.people());
    action("#import-activity", () => jobs.activity());
    destination();
    synchronize();
    return { clear: reset };
};
