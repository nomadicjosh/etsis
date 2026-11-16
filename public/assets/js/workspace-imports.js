/* One reviewed course-import workflow. CSV and approvals remain only in page memory. */
window.createWorkspaceImports = function (ui) {
    "use strict";
    const {
        node,
        table,
        request,
        notify,
        number,
        task,
        can,
        subjects,
        classifications,
    } = ui;
    const one = (selector) => document.querySelector(selector);
    const all = (selector) => [...document.querySelectorAll(selector)];
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
        heading?.focus({ preventScroll: true });
    }
    function payload() {
        return {
            source: one("#import-source").value,
            csv: state.csv,
            mapping: { ...state.mapping },
            delimiter:
                one("#import-delimiter").value === "tab"
                    ? "\t"
                    : one("#import-delimiter").value,
            choices: [...state.choices].map(([external_id, choice]) => ({
                external_id,
                ...choice,
            })),
        };
    }
    const pending = () =>
        JSON.stringify([...state.choices].sort()) !==
        JSON.stringify([...state.reviewedChoices].sort());
    function synchronize() {
        all(
            "[data-import-back], #import-inspect, #import-review, #import-refresh, #import-again, #import-reference-subjects, #import-reference-classes, .import-choice-action",
        ).forEach((button) => {
            button.disabled = busy;
        });
        one("#import-commit").disabled =
            busy ||
            !state.preview?.ready ||
            !state.preview?.review_token ||
            pending();
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
                `${row.external_id} ${row.code} ${row.title}`
                    .toLowerCase()
                    .includes(query),
        );
        page = Math.max(0, Math.min(page, Math.ceil(rows.length / 25) - 1));
        const controls = (row) => {
            const box = node("div", { class: "inline-actions" }),
                choice = state.choices.get(row.external_id);
            const button = (label, callback) =>
                node(
                    "button",
                    {
                        type: "button",
                        class: "btn btn-sm btn-outline-primary import-choice-action",
                        disabled: busy,
                        onclick: callback,
                    },
                    label,
                );
            if (choice)
                box.append(
                    button(
                        choice.action === "skip"
                            ? `Include row ${row.row}`
                            : `Undo link for row ${row.row}`,
                        () => choose(row, null),
                    ),
                );
            else if (["match", "link"].includes(row.action))
                box.append(
                    button(`Link course for row ${row.row}`, () =>
                        choose(row, {
                            action: "link",
                            course_id: row.course_id,
                        }),
                    ),
                );
            if (choice?.action !== "skip")
                box.append(
                    button(`Leave out row ${row.row}`, () =>
                        choose(row, { action: "skip", course_id: null }),
                    ),
                );
            return box;
        };
        const content = rows.slice(page * 25, page * 25 + 25).map((row) => {
            const changed =
                JSON.stringify(state.choices.get(row.external_id) || null) !==
                JSON.stringify(
                    state.reviewedChoices.get(row.external_id) || null,
                );
            const review = node(
                "div",
                {},
                node(
                    "strong",
                    {},
                    changed
                        ? "Choice changed — review needed"
                        : labels[row.action],
                ),
                node("p", { class: "mb-1" }, row.message),
            );
            if (row.matched_course)
                review.append(
                    node(
                        "p",
                        { class: "form-text mb-0" },
                        `Existing: ${row.matched_course.code} · ${row.matched_course.title} · ${number(row.matched_course.credits_milli / 1000)} credits · ${row.matched_course.active ? "Active" : "Inactive"}`,
                    ),
                );
            const course = node(
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
                };
                const list = node("ul", { class: "mb-0 mt-2" });
                for (const [kind, code] of Object.entries(
                    row.source_references,
                )) {
                    const resolved = row.references?.[kind];
                    list.append(
                        node(
                            "li",
                            {},
                            `${names[kind]}: ${code}${resolved ? " · " + resolved.title : ""}`,
                        ),
                    );
                }
                course.append(
                    node(
                        "details",
                        { class: "mt-2" },
                        node("summary", {}, "Catalog classifications"),
                        list,
                    ),
                );
            }
            return [
                String(row.row),
                course,
                number(row.credits_milli / 1000),
                review,
                controls(row),
            ];
        });
        one("#import-plan-table").replaceChildren(
            table(
                ["Row", "Course", "Credits", "Review", "Action"],
                content,
                "Reviewed course import rows",
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
        one("#import-plan-summary").textContent = summary
            ? `New drafts: ${number(summary.create)} · Approved links: ${number(summary.link)} · Prior imports: ${number(summary.skip)} · Left out: ${number(summary.omitted)} · Needs attention: ${number(summary.match + summary.conflict)}.`
            : "";
        const errors = one("#import-review-errors");
        errors.replaceChildren();
        if (preview.errors.length)
            errors.append(
                node(
                    "div",
                    { class: "alert alert-danger" },
                    node(
                        "strong",
                        {},
                        "Correct these source rows and review the file again.",
                    ),
                    node(
                        "ul",
                        { class: "mb-0" },
                        ...preview.errors.map((error) =>
                            node("li", {}, error.message),
                        ),
                    ),
                ),
            );
        one("#import-preview-table").replaceChildren(
            table(
                preview.headers,
                preview.samples,
                "First five source CSV records",
            ),
        );
        one("#import-plan-filter").value = preview.ready ? "all" : "unresolved";
        render();
        synchronize();
        step(3);
    }
    action("#import-inspect", async () => {
        const file = one("#import-file").files[0];
        if (
            !file ||
            file.size > 1048576 ||
            !file.name.toLowerCase().endsWith(".csv")
        )
            throw new Error("Choose a CSV file up to 1 MB.");
        if (!one("#import-source").reportValidity()) return;
        reset();
        state.csv = await file.text();
        const preview = await request(
            "/sis/imports/preview/",
            "POST",
            payload(),
        );
        const mapping = one("#import-mapping");
        mapping.replaceChildren();
        const optionalFields = node("div", { class: "mapping-list mt-3" });
        const optional = node(
            "details",
            { class: "mt-4", id: "import-optional-fields" },
            node("summary", {}, "Add catalog classifications"),
            node(
                "p",
                { class: "form-text mt-2" },
                "Map existing catalog codes when available. Blank or unmapped values stay unspecified; they never clear existing course classifications.",
            ),
            node(
                "a",
                {
                    href: "/assets/examples/courses-classifications.csv",
                    download: true,
                },
                "Example CSV with classifications",
            ),
            optionalFields,
        );
        const core = [
            [
                "external_id",
                "External ID",
                "A stable source-system ID; preserve its letter case",
            ],
            ["code", "Course code", "For example MATH.101"],
            ["title", "Course title", "The course name"],
            ["credits", "Credits", "Decimal credits, such as 3 or 3.5"],
        ];
        const references = [
            [
                "subject_code",
                "Subject code",
                "For example ENG; use an active subject from the catalog",
            ],
            [
                "department_code",
                "Department code",
                "Use an active department code",
            ],
            [
                "academic_level_code",
                "Academic level code",
                "For example UG or GR; use the academic level library",
            ],
            [
                "course_level_code",
                "Course level code",
                "Use an active course-level code",
            ],
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
                },
                node(
                    "option",
                    { value: "" },
                    required ? "Choose a source column" : "Leave unspecified",
                ),
                ...preview.headers.map((header, index) =>
                    node("option", { value: String(index) }, header),
                ),
            );
            const normalized = (value) =>
                value.toLowerCase().replace(/[^a-z0-9]/g, "");
            const index = preview.headers.findIndex(
                (header) =>
                    normalized(header) === normalized(target) ||
                    normalized(header) === normalized(label),
            );
            if (index >= 0) {
                select.value = String(index);
                if (!required) optional.open = true;
            }
            (required ? mapping : optionalFields).append(
                node(
                    "div",
                    { class: "mapping-row" },
                    node(
                        "label",
                        { for: select.id },
                        label,
                        node("small", {}, help),
                    ),
                    node("i", {
                        class: "bi bi-arrow-right",
                        "aria-hidden": "true",
                    }),
                    select,
                ),
            );
        }
        mapping.append(optional);
        step(2);
    });
    action("#import-review", async () => {
        const mapping = {};
        for (const select of one("#import-mapping").querySelectorAll(
            "select",
        )) {
            if (!select.reportValidity()) return;
            if (select.value !== "")
                mapping[select.name] = Number(select.value);
        }
        if (
            new Set(
                ["external_id", "code", "title", "credits"].map(
                    (field) => mapping[field],
                ),
            ).size !== 4
        )
            throw new Error(
                "Choose distinct columns for the four required fields.",
            );
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
    action("#import-commit", async () => {
        if (!state.preview?.review_token || pending()) return;
        try {
            const result = await request("/sis/imports/commit/", "POST", {
                ...state.reviewedRequest,
                review_token: state.preview.review_token,
            });
            one("#import-summary").textContent =
                `Inactive drafts created: ${number(result.imported)} · Courses linked: ${number(result.linked)} · Prior imports: ${number(result.skipped)} · Left out: ${number(result.omitted)}. Existing course details and publications were preserved.`;
            reset();
            one("#import-file").value = "";
            step(4);
            notify("Course import complete.");
        } catch (error) {
            if ([409, 422].includes(error.status))
                state.preview.review_token = null;
            throw error;
        }
    });
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
    synchronize();
    return { clear: reset };
};
