/* Configure term templates, generate academic years, maintain terms, and read derived cohorts. */
window.createWorkspaceAcademicCalendar = function (ui) {
    "use strict";
    const {
        node,
        request: transport,
        task,
        buildForm,
        formData,
        openDrawer,
        table,
        badge,
        notify,
        recordSummary,
    } = ui;
    let activeBody = null;
    let exampleStart = new Date().getFullYear();
    async function request(...args) {
        const scope = activeBody;
        try {
            return await transport(...args);
        } catch (error) {
            if (error.status === 403 && scope?.isConnected) {
                document.querySelector("#drawer-title").textContent = "Academic calendar";
                scope.replaceChildren(
                    node("p", {}, "This record is no longer available with your current access."),
                );
            }
            throw error;
        }
    }
    function openPanel(title, label) {
        const drawer = openDrawer(title, label);
        activeBody = node("div", { class: "academic-calendar-panel" });
        activeBody.append(
            node("p", { role: "status", class: "form-text" }, "Loading academic calendar…"),
        );
        drawer.append(activeBody);
        return activeBody;
    }
    window.addEventListener("pagehide", () => {
        activeBody?.replaceChildren();
        activeBody = null;
    });
    const button = (label, run, primary = false) =>
        node(
            "button",
            {
                type: "button",
                class: `btn ${primary ? "btn-primary" : "btn-outline-primary"}`,
                onclick: (event) => task(run, event.currentTarget),
            },
            label,
        );
    const kinds = [
        { value: "standard", label: "Standard — no overlapping standard terms" },
        { value: "non_standard", label: "Non-standard — overlap permitted" },
    ];
    const anchors = () => [
        { value: "START_YEAR", label: `First year — ${exampleStart} (Fall example)` },
        { value: "END_YEAR", label: `Second year — ${exampleStart + 1} (Spring / Summer example)` },
    ];
    const termFields = [
        [
            "academic_year_id",
            "Academic year (required for new terms)",
            "lookup",
            "academic_years",
            false,
        ],
        ["code", "Term code"],
        ["name", "Term display name"],
        ["kind", "Term category", "choice", kinds],
        ["starts_on", "First day", "date"],
        ["ends_on", "Last day", "date"],
        ["add_drop_ends_on", "Last day to add or drop", "date"],
    ];
    function groupForm(form, sections) {
        for (const [label, keys, description = ""] of sections) {
            const fields = node("div", { class: "record-form-grid" });
            const section = node(
                "fieldset",
                { class: "record-form-section" },
                node("legend", {}, label),
                ...(description ? [node("p", { class: "form-text" }, description)] : []),
                fields,
            );
            for (const key of keys) {
                const wrapper = form.elements[key]?.closest(".mb-4");
                if (wrapper) {
                    wrapper.classList.add("record-form-field");
                    fields.append(wrapper);
                }
            }
            form.append(section);
        }
    }
    const cards = (label, items) =>
        node("ul", { class: "calendar-record-list", "aria-label": label }, items);
    const card = (title, content) =>
        node(
            "li",
            {},
            node(
                "article",
                { class: "surface calendar-record-card" },
                node("h3", { class: "h6" }, title),
                ...content,
            ),
        );
    async function library(kind, after, stack, origin = null) {
        const isYear = kind === "years",
            label = isYear ? "academic year" : "term type";
        const title = isYear ? "Academic years" : "Term types";
        const body = openPanel(title, isYear ? "ACADEMIC CALENDAR" : "REUSABLE CALENDAR TEMPLATES");
        let accepted = { after, stack: [...stack] },
            nextAfter = null,
            busy = false;
        const reopen = () => library(kind, accepted.after, accepted.stack, origin);
        const actions = node(
            "div",
            { class: "inline-actions mb-3" },
            isYear
                ? button("Create academic year", () => createYear(reopen), true)
                : button("Add term type", () => editType(null, reopen), true),
            isYear
                ? button("Set up term types", () =>
                      types(null, [], { back: reopen, label: "Back to academic years" }),
                  )
                : button("Academic years", () => years()),
        );
        const status = node(
            "p",
            { class: "form-text", role: "status", tabindex: "-1" },
            "Loading…",
        );
        const content = node("div");
        const control = (label, run) =>
            node(
                "button",
                {
                    type: "button",
                    class: "btn btn-outline-primary",
                    onclick: () => {
                        if (!busy) task(run);
                    },
                },
                label,
            );
        const previous = control(`Previous ${label} page`, () =>
            load(accepted.stack.at(-1), accepted.stack.slice(0, -1), true),
        );
        const next = control(`Next ${label} page`, () =>
            load(nextAfter, [...accepted.stack, accepted.after], true),
        );
        const refresh = control(`Refresh ${title.toLowerCase()}`, () =>
            load(accepted.after, accepted.stack, true),
        );
        body.replaceChildren(
            ...(origin ? [button(origin.label, origin.back)] : []),
            node(
                "p",
                { class: "form-text" },
                isYear
                    ? "Set up term types, enter a start year, then review and confirm its terms. Matriculation cohorts derive from the academic year; they have no separate setup form."
                    : "Active templates generate future academic years. Editing a template preserves existing term dates, classifications and academic records.",
            ),
            actions,
            status,
            node(
                "nav",
                { class: "inline-actions mb-3", "aria-label": `${title} pages` },
                previous,
                next,
                refresh,
            ),
            content,
        );
        function controls(pending) {
            busy = pending;
            previous.disabled = pending || !accepted.stack.length;
            next.disabled = pending || !nextAfter;
            refresh.disabled = pending;
            content.inert = pending;
            content.setAttribute("aria-busy", String(pending));
        }
        async function load(cursor, trail, focus = false) {
            controls(true);
            let result;
            try {
                result = await request(
                    isYear ? "/sis/academic-years/" : "/sis/term-types/",
                    "POST",
                    cursor ? { after: cursor } : {},
                );
            } catch (error) {
                if (status.isConnected)
                    status.textContent =
                        "This page could not be loaded. Any previous page remains displayed. Use Refresh to try again.";
                throw error;
            } finally {
                if (content.isConnected) controls(false);
            }
            if (!body.isConnected || !content.isConnected) return;
            accepted = { after: cursor, stack: [...trail] };
            nextAfter = result.next_after;
            status.textContent = `${result.items.length} ${isYear ? "academic years" : "term types"} on this page.${nextAfter ? " More are available." : ""}`;
            content.replaceChildren(
                result.items.length
                    ? cards(
                          title,
                          result.items.map((item) =>
                              isYear
                                  ? card(item.code, [
                                        recordSummary([
                                            [
                                                "Term dates",
                                                `${item.starts_on} through ${item.ends_on}`,
                                            ],
                                            ["Terms", item.term_count ?? "Not supplied"],
                                            [
                                                "Origin",
                                                item.legacy
                                                    ? "Existing calendar — review Terms classifications"
                                                    : "Generated from term types",
                                            ],
                                        ]),
                                    ])
                                  : card(item.code, [
                                        recordSummary([
                                            ["Display template", item.name_pattern],
                                            ["Code template", item.code_pattern],
                                            [
                                                "Category",
                                                item.kind === "standard"
                                                    ? "Standard"
                                                    : "Non-standard",
                                            ],
                                            ["Status", badge(item.active ? "Active" : "Inactive")],
                                        ]),
                                        button(`Edit ${item.code}`, () => editType(item, reopen)),
                                    ]),
                          ),
                      )
                    : node(
                          "p",
                          {},
                          isYear
                              ? "No academic years yet. Configure active term types, then create your first year."
                              : "Add your usual terms, such as Fall and Spring. Use non-standard types for overlapping sessions.",
                      ),
            );
            controls(false);
            if (focus) status.focus({ preventScroll: true });
        }
        await load(after, stack);
    }
    const years = (after = null, stack = []) => library("years", after, stack);
    const types = (after = null, stack = [], origin = null) =>
        library("types", after, stack, origin);
    async function editType(value = null, back = () => types()) {
        const body = openPanel(
            value ? "Edit term type" : "Add term type",
            "FUTURE TERM GENERATION",
        );
        const backControl = button("Back to term types", back);
        body.prepend(backControl);
        const id = value?.id || (await request("/sis/ui/operation-id/")).id;
        if (!body.isConnected) return;
        const fields = [
            ["code", "Term type code"],
            ["name_pattern", "Display name template"],
            ["code_pattern", "Term code template"],
            ["kind", "Term category", "choice", kinds],
        ];
        const preset = {
            code: "FA",
            name_pattern: "Fall {{START_YEAR}}",
            code_pattern: "{{START_YEAR_SHORT}}/FA",
            kind: "standard",
            active: true,
            ...value,
        };
        const dateFields = [
            ["starts_on", "Term starts", "08-24"],
            ["ends_on", "Term ends", "12-18"],
            ["add_drop_ends_on", "Drop/add deadline", "09-07"],
        ];
        for (const [key, label, fallback] of dateFields) {
            fields.push(
                [`${key}_month_day`, `${label}: month and day (MM-DD)`],
                [`${key}_year`, `${label}: year`, "choice", anchors()],
            );
            preset[`${key}_month_day`] = value?.dates[key]?.month_day || fallback;
            preset[`${key}_year`] = value?.dates[key]?.year || "START_YEAR";
        }
        fields.push(["active", "Use this type when generating new academic years", "checkbox"]);
        const form = await buildForm(fields, preset);
        if (!body.isConnected) return;
        groupForm(form, [
            ["Template identity", ["code", "name_pattern", "code_pattern", "kind"]],
            ...dateFields.map(([key, label]) => [label, [`${key}_month_day`, `${key}_year`]]),
            ["Future generation", ["active"]],
        ]);
        const exampleInput = node("input", {
            type: "number",
            id: `term-type-example-${id}`,
            name: "example_start_year",
            class: "form-control",
            min: 1000,
            max: 2998,
            step: 1,
            required: true,
            value: exampleStart,
        });
        const context = node("p", { class: "form-text", id: `term-type-year-help-${id}` });
        const preview = node("div", { role: "status", "aria-live": "polite" });
        let editVersion = 0;
        const updateContext = () => {
            const year = Number(exampleInput.value);
            if (Number.isInteger(year) && year >= 1000 && year <= 2998) exampleStart = year;
            for (const [key] of dateFields) {
                form.elements[`${key}_year`].setAttribute("aria-describedby", context.id);
                for (const option of form.elements[`${key}_year`].options) {
                    const definition = anchors().find((item) => item.value === option.value);
                    if (definition) option.textContent = definition.label;
                }
            }
            context.textContent =
                `For academic year ${exampleStart}–${exampleStart + 1}, first year means ${exampleStart} ` +
                `and second year means ${exampleStart + 1}. Fall normally uses the first year for all its dates; ` +
                "J-Term, Spring and Summer normally use the second year. These choices refer to the academic year, not the start and end of each term.";
        };
        exampleInput.addEventListener("input", updateContext);
        updateContext();
        const presets = node("div", {
            class: "inline-actions",
            role: "group",
            "aria-label": "Term template starting points",
        });
        const optional = node(
            "details",
            { class: "mb-4" },
            node("summary", {}, "Optional January and summer sessions"),
        );
        const january = node("div", {
            class: "inline-actions",
            role: "group",
            "aria-label": "January term starting points",
        });
        const summer = node("div", {
            class: "inline-actions",
            role: "group",
            "aria-label": "Summer term starting points",
        });
        optional.append(
            node(
                "p",
                { class: "form-text" },
                "Add only the sessions your institution offers. J-Term normally uses January of the second year; Spring can then start in February. Choose one Summer template or separate Summer 1 and Summer 2 templates, and adjust their dates.",
            ),
            january,
            summer,
        );
        for (const [label, season, code, anchor, start, end, drop, kind, group] of [
            ["Fall", "Fall", "FA", "START_YEAR", "08-24", "12-18", "09-07", "standard", presets],
            ["Spring", "Spring", "SP", "END_YEAR", "01-04", "05-31", "01-18", "standard", presets],
            ["J-Term", "January", "J", "END_YEAR", "01-04", "01-31", "01-10", "standard", january],
            [
                "February Spring",
                "Spring",
                "SP",
                "END_YEAR",
                "02-01",
                "05-31",
                "02-15",
                "standard",
                january,
            ],
            [
                "Summer",
                "Summer",
                "SU",
                "END_YEAR",
                "06-01",
                "08-30",
                "06-08",
                "non_standard",
                summer,
            ],
            [
                "Summer 1",
                "Summer 1",
                "S1",
                "END_YEAR",
                "06-01",
                "07-15",
                "06-08",
                "non_standard",
                summer,
            ],
            [
                "Summer 2",
                "Summer 2",
                "S2",
                "END_YEAR",
                "07-16",
                "08-30",
                "07-23",
                "non_standard",
                summer,
            ],
        ]) {
            group.append(
                button(`Use ${label} starting point`, () => {
                    if (!value) form.elements.code.value = code;
                    form.elements.name_pattern.value = `${season} {{${anchor}}}`;
                    form.elements.code_pattern.value = `{{${anchor}_SHORT}}/${code}`;
                    form.elements.kind.value = kind;
                    for (const [index, [key]] of dateFields.entries()) {
                        form.elements[`${key}_month_day`].value = [start, end, drop][index];
                        form.elements[`${key}_year`].value = anchor;
                    }
                    form.dispatchEvent(new Event("input", { bubbles: true }));
                }),
            );
        }
        form.prepend(
            presets,
            optional,
            node(
                "p",
                { class: "form-text" },
                "Starting points replace the unsaved template settings with example dates. Adjust every date and deadline to your institution’s policy. Summer starts as non-standard for overlapping sessions.",
            ),
            node(
                "div",
                { class: "mb-4" },
                node(
                    "label",
                    { class: "form-label", for: exampleInput.id },
                    "Example academic year starts in",
                ),
                exampleInput,
                context,
            ),
        );
        form.addEventListener("input", () => {
            editVersion++;
            preview.replaceChildren(
                node(
                    "p",
                    { class: "form-text" },
                    "Settings changed. Preview the generated term again.",
                ),
            );
        });
        const previewButton = button("Preview generated term", async () => {
            if (!form.reportValidity()) return;
            const version = editVersion;
            const result = await request(`/sis/term-types/${id}/preview/`, "POST", {
                ...formData(form, fields),
                version: value?.version ?? -1,
                start_year: Number(exampleInput.value),
            });
            if (version !== editVersion || !body.isConnected) return;
            preview.replaceChildren(
                node(
                    "p",
                    {},
                    `Academic year ${result.academic_year}: ${result.name} (${result.code}).`,
                ),
                node(
                    "section",
                    { role: "region", "aria-label": "Example generated term dates" },
                    recordSummary([
                        ["First day", result.starts_on],
                        ["Last day", result.ends_on],
                        ["Drop/add through", result.add_drop_ends_on],
                    ]),
                ),
                node(
                    "p",
                    { class: "form-text" },
                    "This preview saves nothing. Review the year and dates before saving the template.",
                ),
            );
        });
        const patternPreview = node("p", {
            class: "form-text",
            role: "status",
            "aria-live": "polite",
        });
        const updatePatternPreview = () => {
            const pattern = form.elements.code_pattern.value;
            const code = (start) =>
                pattern
                    .replaceAll("{{START_YEAR}}", String(start))
                    .replaceAll("{{END_YEAR}}", String(start + 1))
                    .replaceAll("{{START_YEAR_SHORT}}", String(start).slice(-2))
                    .replaceAll("{{END_YEAR_SHORT}}", String(start + 1).slice(-2));
            patternPreview.textContent =
                `Example for ${exampleStart}–${exampleStart + 1}: ${code(exampleStart)}. For ${exampleStart + 1}–${exampleStart + 2}: ${code(exampleStart + 1)}. ` +
                (/\{\{(?:START|END)_YEAR(?:_SHORT)?\}\}/.test(pattern)
                    ? "Year placeholders give each year's term its own code."
                    : "This code stays the same every year and may conflict with an existing term. Include a year placeholder for recurring terms.");
        };
        form.addEventListener("input", updatePatternPreview);
        updatePatternPreview();
        form.elements.code_pattern.closest(".mb-4").append(patternPreview);
        const save = node("button", { type: "submit", class: "btn btn-primary" }, "Save term type");
        let command = null;
        form.append(
            node(
                "p",
                { class: "form-text" },
                "Use {{START_YEAR}} and {{END_YEAR}} for full years. Short forms {{START_YEAR_SHORT}} and {{END_YEAR_SHORT}} preserve codes such as 26/FA. Dates use the selected year and an exact month/day. Invalid leap-day dates require a template review.",
            ),
            node("div", { class: "inline-actions mb-3" }, previewButton, save),
            preview,
        );
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                command ??= { ...formData(form, fields), version: value?.version ?? -1 };
                try {
                    await request(`/sis/term-types/${id}/save/`, "POST", command);
                } catch (error) {
                    if (error.status && error.status < 500) command = null;
                    throw error;
                }
                if (!body.isConnected || !form.isConnected) return;
                notify("Term type saved. Existing terms were preserved.");
                await back();
            }, save);
        });
        body.replaceChildren(backControl, form);
    }
    async function createYear(back = () => years(), start = exampleStart) {
        const body = openPanel("Create academic year", "START YEAR → GENERATED TERMS → CONFIRM");
        const backControl = button("Back to academic years", back);
        body.prepend(backControl);
        const form = await buildForm([["start_year", "Start year", "number"]], {
            start_year: start,
        });
        if (!body.isConnected) return;
        groupForm(form, [["Academic year", ["start_year"]]]);
        form.elements.start_year.min = 1000;
        form.elements.start_year.max = 2998;
        const status = node("p", { role: "status", "aria-live": "polite" });
        const update = () => {
            const year = Number(form.elements.start_year.value);
            if (Number.isInteger(year) && year >= 1000 && year <= 2998) exampleStart = year;
            status.textContent = `Academic year ${form.elements.start_year.value}–${year + 1}. The end year is automatic.`;
        };
        form.elements.start_year.addEventListener("input", update);
        update();
        const next = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review generated terms",
        );
        form.append(status, next);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                const startYear = Number(form.elements.start_year.value);
                const plan = await request("/sis/academic-years/review/", "POST", {
                    start_year: startYear,
                });
                if (!body.isConnected || Number(form.elements.start_year.value) !== startYear)
                    return;
                confirmYear(plan, back);
            }, next);
        });
        body.replaceChildren(
            backControl,
            node(
                "p",
                {},
                "Every active term type is included. Matching existing terms with identical codes and dates can be linked without replacing their academic or financial records.",
            ),
            form,
            button("Review term types", () => {
                const draft = Number(form.elements.start_year.value);
                return types(null, [], {
                    label: "Back to academic year setup",
                    back: () => createYear(back, draft),
                });
            }),
        );
    }
    function termGrid(terms) {
        return cards(
            "Generated academic terms",
            terms.map((t) =>
                card(`${t.name} · ${t.code}`, [
                    recordSummary([
                        ["Category", t.kind === "standard" ? "Standard" : "Non-standard"],
                        ["Action", t.adopt_existing ? "Link existing term" : "Generate new term"],
                        ["First day", t.starts_on],
                        ["Last day", t.ends_on],
                        ["Drop/add through", t.add_drop_ends_on],
                    ]),
                ]),
            ),
        );
    }
    function confirmYear(plan, returnTo = () => years()) {
        const body = openPanel(
            `Review academic year ${plan.code}`,
            "ONE REVIEW · ONE CALENDAR TRANSACTION",
        );
        const command = {
            id: plan.id,
            operation_id: plan.operation_id,
            start_year: plan.start_year,
            revision: plan.revision,
            terms: plan.terms.map((t) => ({ term_type_id: t.term_type_id, id: t.id })),
        };
        const back = button("Change start year", () => createYear(returnTo, plan.start_year));
        body.replaceChildren(
            node(
                "p",
                {},
                `Academic year ${plan.code}. Active terms cover ${plan.starts_on} through ${plan.ends_on}.`,
            ),
            node(
                "p",
                { class: "form-text" },
                `Fall normally starts in ${plan.start_year}; Spring and Summer normally start in ${plan.end_year}. ` +
                    "Coverage follows the active templates. Summer extends it only when summer templates are included. Review term years and deadlines below.",
            ),
            termGrid(plan.terms),
            button("Review term types and dates", () => {
                exampleStart = plan.start_year;
                return types(null, [], {
                    label: "Back to academic year setup",
                    back: () => createYear(returnTo, plan.start_year),
                });
            }),
            node(
                "p",
                { class: "alert alert-info" },
                "Confirmation saves the year and its terms together. Standard terms cannot overlap. Courses, registrations and charges are created separately. A student’s program keeps its assigned curriculum version when later years are created.",
            ),
            back,
            button(
                "Confirm academic year and terms",
                async () => {
                    back.disabled = true;
                    try {
                        await request("/sis/academic-years/create/", "POST", command);
                    } catch (error) {
                        if (error.status && error.status < 500) back.disabled = false;
                        throw error;
                    }
                    if (!body.isConnected) return;
                    notify("Academic year and terms created.");
                    await returnTo();
                },
                true,
            ),
            button("Check saved academic year", async () => {
                const saved = await request(
                    `/sis/academic-years/${plan.id}/receipts/${plan.operation_id}/`,
                );
                if (!body.isConnected) return;
                const result = openPanel(`Academic year ${saved.code}`, "SAVED CALENDAR");
                result.append(termGrid(saved.terms), button("Academic years", returnTo));
            }),
        );
    }
    async function termForm(id = null, back = null) {
        const body = openPanel(
            id ? "Maintain academic term" : "Add an academic term",
            "TERMS · ACADEMIC YEAR MEMBERSHIP",
        );
        const backControl = button(
            back ? "Back to record" : "Back to academic terms",
            back || ui.refreshTerms,
        );
        body.prepend(backControl);
        const existing = id
            ? await request(`/sis/calendar/terms/${id}/`)
            : { kind: "non_standard" };
        if (!body.isConnected) return;
        const operation = id ? null : (await request("/sis/ui/operation-id/")).id;
        if (!body.isConnected) return;
        const preset = Object.fromEntries(
            Object.entries(existing).filter(([, value]) => value !== null),
        );
        const form = await buildForm(termFields, preset);
        if (!body.isConnected) return;
        groupForm(form, [
            ["Calendar membership", ["academic_year_id"]],
            ["Term identity", ["code", "name", "kind"]],
            ["Instructional dates", ["starts_on", "ends_on", "add_drop_ends_on"]],
        ]);
        form.elements.academic_year_id.required = !id;
        if (existing.academic_year_id) form.elements.academic_year_id.disabled = true;
        const save = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Save academic term",
        );
        let command = null;
        form.append(
            node(
                "p",
                { class: "form-text" },
                "Standard terms cannot overlap other standard terms. Non-standard sessions can overlap. Once assigned, a term’s academic year stays fixed to protect matriculation history. Term-type edits do not rewrite generated terms.",
            ),
            save,
        );
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                command ??= {
                    ...formData(form, termFields),
                    ...(id ? { revision: existing.revision } : { operation_id: operation }),
                };
                try {
                    await request(
                        id ? `/sis/calendar/terms/${id}/save/` : "/sis/calendar/terms/create/",
                        "POST",
                        command,
                    );
                } catch (error) {
                    if (error.status && error.status < 500) command = null;
                    throw error;
                }
                if (!body.isConnected || !form.isConnected) return;
                notify("Academic term saved.");
                await ui.refreshTerms();
            }, save);
        });
        body.replaceChildren(backControl, form);
    }
    async function studentCatalog(student, assignment, back = null) {
        const path = `/sis/students/${student}/programs/${assignment}/catalog/`;
        const body = openPanel("Matriculation cohort", "STUDENT PROGRAM");
        const backControl = back ? button("Back to student programs", back) : null;
        if (backControl) body.prepend(backControl);
        let value = await request(path, "POST", {});
        if (!body.isConnected) return;
        let selected = null,
            reason = "",
            after = null,
            sequence = 0;
        const title = (catalog) =>
            catalog ? `${catalog.year_code} · revision ${catalog.revision}` : "No cohort assigned";
        const explanation = () =>
            node(
                "p",
                { class: "form-text" },
                "Changing a cohort preserves earlier assignments and academic work. Degree requirements and specializations stay assigned until separately reviewed.",
            );
        function focus(heading) {
            if (heading.isConnected) heading.focus();
        }
        function overview() {
            sequence++;
            if (!body.isConnected) return;
            body.replaceChildren(
                ...(backControl ? [backControl] : []),
                node("h3", { class: "h5" }, `${value.program.code} · ${value.program.title}`),
                node(
                    "p",
                    {},
                    `Entry term: ${value.entry_term.code}. Current cohort: ${title(value.current)}.`,
                ),
                explanation(),
                node(
                    "p",
                    { class: "form-text" },
                    value.earlier_history_unknown
                        ? "Some earlier cohort decisions were not recorded in protected history. Existing receipts remain available."
                        : "The initial cohort was retained with the program assignment.",
                ),
                button("Cohort history", () => history()),
            );
            if (value.status === "active")
                body.append(button("Review cohort change", () => editor(), true));
            else
                body.append(
                    node(
                        "p",
                        { class: "form-text" },
                        "This pursuit is closed. Its cohort and history remain available.",
                    ),
                );
        }
        async function editor(cursor = null, previous = []) {
            const current = ++sequence;
            const pending = node(
                "p",
                { role: "status", class: "form-text" },
                "Loading published curricula…",
            );
            body.querySelector("[data-calendar-pending]")?.remove();
            pending.dataset.calendarPending = "";
            body.append(pending);
            let page;
            try {
                page = await request(path + "choices/", "POST", cursor ? { after: cursor } : {});
            } finally {
                pending.remove();
            }
            if (!body.isConnected || current !== sequence) return;
            const heading = node(
                "h3",
                { class: "h5", tabindex: "-1" },
                "Choose a published curriculum",
            );
            const form = node("form", {});
            const choices = node(
                "fieldset",
                { class: "d-grid gap-2 my-3" },
                node("legend", { class: "h6" }, "Available cohorts"),
            );
            for (const item of page.items) {
                const id = `cohort-${item.catalog_id}`;
                const control = node("input", {
                    id,
                    type: "radio",
                    name: "catalog_id",
                    class: "form-check-input me-2",
                    checked: selected?.catalog_id === item.catalog_id,
                    disabled: item.catalog_id === value.current?.catalog_id,
                    onchange: () => {
                        selected = item;
                    },
                });
                choices.append(
                    node(
                        "label",
                        { for: id, class: "surface calendar-cohort-choice" },
                        control,
                        `${title(item)} · ${item.program_title}${item.catalog_id === value.current?.catalog_id ? " · Current" : ""}`,
                    ),
                );
            }
            if (!page.items.length)
                choices.append(node("p", {}, "No published curricula on this page."));
            const picked = node(
                "p",
                { class: "form-text", role: "status", "aria-live": "polite" },
                selected ? `Selected: ${title(selected)}.` : "Choose a curriculum above.",
            );
            choices.addEventListener("change", () => {
                picked.textContent = `Selected: ${title(selected)}.`;
            });
            const note = node("textarea", {
                id: "cohort-reason",
                name: "note",
                class: "form-control",
                required: true,
                maxlength: "1000",
                rows: "3",
                oninput: (event) => {
                    reason = event.target.value;
                },
            });
            note.value = reason;
            const review = node(
                "button",
                { type: "submit", class: "btn btn-primary" },
                "Review change",
            );
            form.append(
                choices,
                picked,
                node(
                    "label",
                    { for: "cohort-reason", class: "form-label" },
                    "Reason for cohort change",
                ),
                note,
                explanation(),
                review,
            );
            form.addEventListener("submit", (event) => {
                event.preventDefault();
                task(async () => {
                    if (!form.reportValidity()) return;
                    if (!selected) throw new Error("Choose a published curriculum first.");
                    const command = {
                        catalog_id: selected.catalog_id,
                        version: value.version,
                        note: reason,
                    };
                    const current = ++sequence;
                    const reviewed = await request(path + "review/", "POST", command);
                    if (!body.isConnected || current !== sequence) return;
                    confirmation(reviewed, command, () => editor(cursor, previous));
                }, review);
            });
            const paging = node("div", { class: "inline-actions my-3" });
            if (previous.length)
                paging.append(
                    button("Previous", () => editor(previous.at(-1), previous.slice(0, -1))),
                );
            if (page.next_after)
                paging.append(button("Next", () => editor(page.next_after, [...previous, cursor])));
            body.replaceChildren(
                heading,
                button("Back to cohort", overview),
                paging,
                form,
                button("Refresh cohort", async () => {
                    const current = ++sequence;
                    const fresh = await request(path, "POST", {});
                    if (!body.isConnected || current !== sequence) return;
                    value = fresh;
                    if (value.status !== "active") overview();
                    else await editor(cursor, previous);
                }),
            );
            focus(heading);
        }
        function confirmation(reviewed, command, revise) {
            sequence++;
            const heading = node("h3", { class: "h5", tabindex: "-1" }, "Confirm cohort change");
            body.replaceChildren(
                heading,
                node(
                    "p",
                    {},
                    `Before: ${title(reviewed.before)}. After: ${title(reviewed.after)}.`,
                ),
                node("p", { class: "private-text" }, command.note),
                explanation(),
                button("Revise change", revise),
                button(
                    "Confirm change",
                    async () => {
                        const current = ++sequence;
                        await request(path + "commit/", "POST", {
                            ...command,
                            operation_id: reviewed.operation_id,
                            review_token: reviewed.review_token,
                        });
                        if (!body.isConnected || current !== sequence) return;
                        notify("Cohort updated. Review assigned requirements separately.");
                        const fresh = await request(path, "POST", {});
                        if (!body.isConnected || current !== sequence) return;
                        value = fresh;
                        overview();
                    },
                    true,
                ),
            );
            focus(heading);
        }
        async function history(cursor = null, previous = [], receiptsAfter = after) {
            const current = ++sequence;
            const pending = node(
                "p",
                { role: "status", class: "form-text" },
                "Loading cohort history…",
            );
            body.querySelector("[data-calendar-pending]")?.remove();
            pending.dataset.calendarPending = "";
            body.append(pending);
            let page;
            try {
                page = await request(path + "history/", "POST", { cursor, after: receiptsAfter });
            } finally {
                pending.remove();
            }
            if (!body.isConnected || current !== sequence) return;
            after = receiptsAfter;
            const heading = node("h3", { class: "h5", tabindex: "-1" }, "Cohort history");
            body.replaceChildren(
                heading,
                button("Back to cohort", overview),
                node(
                    "p",
                    { class: "form-text" },
                    "Protected decisions retain their original labels. Reasons, actor details and raw events are excluded.",
                ),
                page.items.length
                    ? node(
                          "section",
                          { role: "region", "aria-label": "Protected cohort decisions" },
                          cards(
                              "Cohort decisions",
                              page.items.map((item) =>
                                  card(title(item.catalog), [
                                      recordSummary([
                                          ["Before", title(item.previous_catalog)],
                                          ["After", title(item.catalog)],
                                          ["Effective", item.effective_at],
                                          ["Recorded", item.recorded_at],
                                      ]),
                                  ]),
                              ),
                          ),
                      )
                    : node("p", {}, "No cohort changes on this history page."),
            );
            const paging = node("div", { class: "inline-actions my-3" });
            if (previous.length)
                paging.append(
                    button("Previous", () => history(previous.at(-1), previous.slice(0, -1))),
                );
            if (page.next_cursor)
                paging.append(
                    button("Next", () => history(page.next_cursor, [...previous, cursor])),
                );
            body.append(
                paging,
                node("h4", { class: "h6" }, "Earlier receipts"),
                node(
                    "p",
                    { class: "form-text" },
                    "These receipts are preserved as originally recorded. They are not reconstructed events, and their calendar labels may reflect current reference data.",
                ),
                page.earlier_receipts.length
                    ? node(
                          "section",
                          { role: "region", "aria-label": "Earlier cohort receipts" },
                          cards(
                              "Earlier receipts",
                              page.earlier_receipts.map((item) =>
                                  card(item.year_code, [
                                      recordSummary([
                                          ["Revision", item.catalog_revision],
                                          ["Recorded", item.recorded_at],
                                      ]),
                                  ]),
                              ),
                          ),
                      )
                    : node("p", {}, "No earlier receipts on this page."),
            );
            if (page.next_after)
                body.append(
                    button("More earlier receipts", () => {
                        return history(cursor, previous, page.next_after);
                    }),
                );
            focus(heading);
        }
        overview();
    }
    return { years, types, studentCatalog, newTerm: () => termForm(), editTerm: termForm };
};
