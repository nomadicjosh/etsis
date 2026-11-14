/* Program draft editing and explicit academic-year publication. No browser storage. */
window.createWorkspacePrograms = function (ui) {
    "use strict";
    const { node, request, task, buildForm, formData, openDrawer } = ui;
    const categories = {
        department: "Department",
        academic_level: "Academic level",
        award: "Award",
    };
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
    function details(definition) {
        return node(
            "div",
            { class: "surface p-3 mb-3" },
            node("h3", { class: "h5" }, `${definition.code} — ${definition.title}`),
            node(
                "p",
                { class: "course-description-text" },
                definition.description || "No description specified.",
            ),
            node(
                "dl",
                { class: "detail-list" },
                ...Object.entries(categories).map(([kind, label]) => {
                    const value = definition.classifications[kind];
                    return node(
                        "div",
                        {},
                        node("dt", {}, label),
                        node("dd", {}, value ? `${value.code} — ${value.title}` : "Not specified"),
                    );
                }),
            ),
        );
    }

    async function open(id, notice = false) {
        const current = await request(`/sis/programs/${id}/details/`);
        const draft = {
            ...current.definition,
            classifications: { ...current.definition.classifications },
        };
        async function render() {
            const body = openDrawer(
                "Program details",
                "EDIT TOGETHER → REVIEW → PUBLISH FOR AN ACADEMIC YEAR",
            );
            body.append(
                node(
                    "p",
                    {},
                    "Save the working details here. Publishing creates a new program catalog revision for an academic year; existing students keep their earlier references.",
                ),
            );
            const fields = [
                ["code", "Program code"],
                ["title", "Program title"],
                ["description", "Program description", "textarea", "", false],
            ];
            const form = await buildForm(fields, draft);
            form.elements.namedItem("code").maxLength = 80;
            form.elements.namedItem("description").maxLength = 10000;
            const cards = node("div", { class: "row g-3 mb-3" });
            const capture = () =>
                Object.assign(draft, formData(form, fields), {
                    description: form.elements.namedItem("description").value || null,
                });
            Object.entries(categories).forEach(([kind, label]) => {
                const value = draft.classifications[kind];
                const card = node(
                    "section",
                    { class: "surface p-3 h-100", "aria-label": label },
                    node("h3", { class: "h5" }, label),
                    node("p", {}, value ? `${value.code} — ${value.title}` : "Not specified"),
                    button(`Choose ${label.toLowerCase()}`, () => {
                        capture();
                        return ui.references().library(
                            kind,
                            "",
                            null,
                            [],
                            async (row) => {
                                draft.classifications[kind] = {
                                    id: row.id,
                                    code: row.code,
                                    title: row.title,
                                };
                                await render();
                            },
                            render,
                            "Back to program details",
                        );
                    }),
                );
                if (value)
                    card.append(
                        button(`Clear ${label.toLowerCase()}`, () => {
                            capture();
                            draft.classifications[kind] = null;
                            return render();
                        }),
                    );
                cards.append(node("div", { class: "col-12 col-xl-4" }, card));
            });
            const save = node("button", { type: "submit", class: "btn btn-primary" }, "Save program details");
            const publish = button("Publish for an academic year", () => setup(id));
            const status = node("p", { role: "status" });
            function state() {
                capture();
                const changed = JSON.stringify(draft) !== JSON.stringify(current.definition);
                save.disabled = !changed;
                publish.disabled = changed;
                status.textContent = changed
                    ? "Save your draft changes before reviewing publication."
                    : notice
                      ? "Program details saved."
                      : "Your saved details are ready for review.";
            }
            form.addEventListener("input", state);
            form.addEventListener("submit", (event) => {
                event.preventDefault();
                task(async () => {
                    if (!form.reportValidity()) return;
                    capture();
                    await request(`/sis/programs/${id}/details/`, "POST", {
                        code: draft.code,
                        title: draft.title,
                        description: draft.description,
                        department_id: draft.classifications.department?.id ?? null,
                        academic_level_id: draft.classifications.academic_level?.id ?? null,
                        award_id: draft.classifications.award?.id ?? null,
                        revision: current.revision,
                    });
                    await ui.refreshDirectory();
                    await open(id, true);
                }, save);
            });
            form.append(cards, save);
            state();
            body.append(
                form,
                status,
                publish,
                button("Published program details", () => {
                    capture();
                    return history(id, render);
                }),
                button("Program specializations", () => {
                    capture();
                    return specializations(id, render);
                }),
            );
            let assistantPanel = null;
            body.append(
                button("Optional AI help", async () => {
                    capture();
                    const unchanged = () => {
                        capture();
                        return JSON.stringify(draft) === JSON.stringify(current.definition);
                    };
                    if (!unchanged())
                        throw new Error("Save your program changes before reviewing optional assistance.");
                    const preview = await request(`/sis/programs/${id}/assistance/`);
                    if (!body.isConnected) return;
                    if (assistantPanel) assistantPanel.remove();
                    const heading = node("h3", { tabindex: "-1" }, "Optional program assistance");
                    assistantPanel = node(
                        "section",
                        {
                            class: "record-section",
                            "aria-label": "Optional program assistance",
                        },
                        heading,
                    );
                    body.append(assistantPanel);
                    heading.focus();
                    if (!preview.enabled || !preview.tasks.length) {
                        assistantPanel.append(
                            node(
                                "p",
                                { role: "status" },
                                "Optional assistance is disabled or no action is assigned to your account. Normal editing remains available.",
                            ),
                        );
                        return;
                    }
                    if (preview.saved_revision !== current.revision || !unchanged()) {
                        throw new Error(
                            "The saved program changed. Reload this editor before requesting assistance.",
                        );
                    }
                    const consent = node("input", {
                        type: "checkbox",
                        id: "program-assistance-consent",
                        class: "form-check-input",
                        required: true,
                    });
                    const results = node("div", { "aria-live": "polite" });
                    const progress = node("p", { role: "status", class: "form-text" });
                    const run = async (action) => {
                        if (!consent.reportValidity()) return;
                        if (!unchanged())
                            throw new Error(
                                "Your program changed. Save it and inspect a fresh preview before requesting assistance.",
                            );
                        controls.disabled = true;
                        consent.checked = false;
                        results.replaceChildren();
                        progress.textContent = "Requesting suggestions… Your draft is not being changed.";
                        try {
                            const response = await request(`/sis/programs/${id}/assistance/`, "POST", {
                                task: action,
                                consent: true,
                                revision: preview.revision,
                            });
                            if (!results.isConnected) return;
                            const suggestions = node("ul");
                            response.advice.suggestions.forEach((value) =>
                                suggestions.append(node("li", {}, value)),
                            );
                            results.append(
                                node("h4", {}, "Suggestions to review"),
                                node("p", {}, response.advice.summary),
                                suggestions,
                            );
                            if (response.advice.description) {
                                results.append(
                                    node(
                                        "p",
                                        { class: "course-description-text" },
                                        response.advice.description,
                                    ),
                                    button("Use suggested description in this editor", () => {
                                        if (!unchanged())
                                            throw new Error(
                                                "Your program has changed. Review it before replacing any text.",
                                            );
                                        const description = form.elements.namedItem("description");
                                        description.value = response.advice.description;
                                        description.dispatchEvent(new Event("input", { bubbles: true }));
                                        description.focus();
                                        progress.textContent =
                                            "Suggestion copied into the editor. Review it and use Save program details when ready; publication is a separate decision.";
                                    }),
                                );
                            }
                            progress.textContent =
                                "Suggestions received. No text has been saved or published.";
                        } finally {
                            controls.disabled = false;
                            if (!results.childNodes.length)
                                progress.textContent =
                                    "No suggestion was applied. Normal editing remains available; another request requires fresh consent.";
                        }
                    };
                    const labels = {
                        description: "Suggest a program description",
                        rewrite: "Rewrite description",
                        expand: "Expand description",
                    };
                    const controls = node(
                        "fieldset",
                        { class: "border-0 p-0" },
                        node("legend", { class: "h5" }, "Consent for one request"),
                        node(
                            "div",
                            { class: "form-check mb-3" },
                            consent,
                            node(
                                "label",
                                { for: consent.id, class: "form-check-label" },
                                `I agree to send the displayed saved program draft to ${preview.label} for this request.`,
                            ),
                        ),
                        ...Object.entries(labels)
                            .filter(([action]) => preview.tasks.includes(action))
                            .map(([action, label]) => button(label, () => run(action))),
                    );
                    assistantPanel.append(
                        node(
                            "p",
                            {},
                            `Provider: ${preview.label}. Only the displayed saved program facts will be sent. Inspect free text and remove personal or confidential information before consenting. Suggestions are optional and may be incorrect.`,
                        ),
                        node(
                            "p",
                            {},
                            "No student records, internal identifiers, publication reasons or histories are selected. Saving and publication require your normal review.",
                        ),
                        node(
                            "details",
                            {},
                            node("summary", {}, "Inspect exactly what will be sent"),
                            node(
                                "pre",
                                { class: "text-wrap text-break" },
                                JSON.stringify(preview.draft, null, 2),
                            ),
                        ),
                        controls,
                        progress,
                        results,
                    );
                }),
            );
        }
        await render();
    }

    async function setup(id, draft = { year: null, reason: "" }) {
        const current = await request(`/sis/programs/${id}/details/`);
        const body = openDrawer("Publish program details", "ACADEMIC YEAR → REVIEW → CONFIRM");
        body.append(
            details(current.definition),
            node(
                "p",
                {},
                "Choose the academic year whose entering students should use this revision. Existing students retain their assigned revision. This does not award a degree or replace degree-audit requirements.",
            ),
        );
        const yearLabel = node(
            "p",
            { class: "h5" },
            draft.year ? `Academic year ${draft.year.code}` : "No academic year chosen",
        );
        const reason = node("textarea", {
            id: "program-publication-reason",
            class: "form-control",
            rows: 3,
            maxlength: 1000,
            required: true,
        });
        reason.value = draft.reason;
        const capture = () => {
            draft.reason = reason.value;
        };
        body.append(
            yearLabel,
            button("Choose academic year", () => {
                capture();
                return years(id, draft);
            }),
        );
        const form = node("form", { class: "mt-3" });
        const review = node(
            "button",
            { type: "submit", class: "btn btn-primary mt-3", disabled: !draft.year },
            "Review program publication",
        );
        form.append(
            node("label", { for: reason.id, class: "form-label" }, "Reason for program publication"),
            reason,
            review,
        );
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                capture();
                await reviewProgram(id, draft);
            }, review);
        });
        body.append(
            form,
            button("Back to program details", () => open(id)),
        );
    }

    async function years(id, draft, search = "", after = null, stack = []) {
        const data = await request("/sis/academic-years/", "POST", {
            search,
            ...(after ? { after } : {}),
        });
        const body = openDrawer("Choose program academic year", "EXISTING ACADEMIC YEARS");
        body.append(
            node(
                "p",
                {},
                "Academic years are set up in Academic catalog. Catalog cohorts are derived from their terms; there is no separate catalog-year entry.",
            ),
        );
        const form = node("form", { class: "mb-3" });
        const input = node("input", {
            type: "search",
            id: "program-year-search",
            class: "form-control",
            maxlength: 80,
            value: search,
        });
        form.append(
            node("label", { for: input.id, class: "form-label" }, "Find academic year"),
            input,
            node("button", { type: "submit", class: "btn btn-primary mt-2" }, "Search academic years"),
        );
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(() => years(id, draft, input.value));
        });
        body.append(form);
        data.items.forEach((year) =>
            body.append(
                node(
                    "div",
                    { class: "surface p-3 mb-2" },
                    node("h3", { class: "h5" }, year.code),
                    button(`Use academic year ${year.code}`, () => {
                        draft.year = { id: year.id, code: year.code };
                        return setup(id, draft);
                    }),
                ),
            ),
        );
        if (!data.items.length)
            body.append(
                node(
                    "p",
                    { role: "status" },
                    "No matching academic years. Set one up in Academic catalog first, or clear this search.",
                ),
            );
        if (stack.length)
            body.append(
                button("Previous academic years", () =>
                    years(id, draft, search, stack.at(-1), stack.slice(0, -1)),
                ),
            );
        if (data.next_after)
            body.append(
                button("More academic years", () =>
                    years(id, draft, search, data.next_after, [...stack, after]),
                ),
            );
        body.append(button("Back to program publication", () => setup(id, draft)));
    }

    async function reviewProgram(id, draft) {
        const review = await request(`/sis/programs/${id}/publication/review/`, "POST", {
            academic_year_id: draft.year.id,
            reason: draft.reason,
        });
        const body = openDrawer("Review program publication", "REVIEW → CONFIRM");
        body.append(
            node(
                "p",
                { class: "h5" },
                `${review.academic_year_code} · Catalog revision ${review.catalog_revision}`,
            ),
            details(review.definition),
            specializationPublication(review.specializations),
            node("p", { class: "course-description-text" }, review.reason),
            node(
                "p",
                {},
                "New assignments for this academic year will use this revision. Existing students, earned credits and earlier publications stay unchanged. An unconfirmed review expires after 20 minutes.",
            ),
            button("Revise program publication", () => setup(id, draft)),
            button("Refresh program review", () => reviewProgram(id, draft)),
            button(
                "Confirm program publication",
                async () => {
                    await request(`/sis/programs/${id}/publication/commit/`, "POST", {
                        review_token: review.review_token,
                    });
                    const saved = openDrawer("Program publication saved", "ACADEMIC CATALOG");
                    saved.append(
                        node(
                            "p",
                            { role: "status", class: "alert alert-success" },
                            `${review.academic_year_code} · Catalog revision ${review.catalog_revision} published.`,
                        ),
                        details(review.definition),
                        specializationPublication(review.specializations),
                        button("Published program details", () => history(id, () => open(id))),
                        button("Back to program details", () => open(id)),
                    );
                },
                "btn-primary",
            ),
        );
    }

    async function specializations(program, back, state = { search: "", after: null, stack: [] }, notice = "") {
        const base = `/sis/programs/${program}/specializations/`;
        const data = await request(`${base}page/`, "POST", { search: state.search, after: state.after });
        const body = openDrawer("Program specializations", "FOCUSED PATHS WITHIN AN ACADEMIC PROGRAM");
        const restore = (notice = "") => specializations(program, back, state, notice);
        body.append(
            node("h3", { class: "h5" }, `${data.program.code} — ${data.program.title}`),
            node(
                "p",
                {},
                "Maintain specialization names and availability for this program. This setup does not change a student's recorded program or degree requirements.",
            ),
            node(
                "div",
                { class: "d-flex flex-wrap gap-2 mb-3" },
                button("Add a specialization", () => editSpecialization(program, null, restore)),
                button("Specialization selection policy", () => specializationPolicy(program, restore)),
                button("Back to program details", back),
            ),
        );
        if (notice) body.append(node("p", { role: "status", class: "alert alert-success" }, notice));
        const search = node("input", {
            id: "program-specialization-search",
            type: "search",
            class: "form-control",
            maxlength: "100",
            value: state.search,
        });
        const form = node(
            "form",
            { class: "mb-3" },
            node("label", { for: search.id, class: "form-label" }, "Search this program's specializations"),
            node(
                "div",
                { class: "d-flex flex-wrap gap-2" },
                search,
                node("button", { type: "submit", class: "btn btn-outline-primary" }, "Search specializations"),
            ),
        );
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(
                () => specializations(program, back, { search: search.value, after: null, stack: [] }),
                form.querySelector("button"),
            );
        });
        body.append(form);
        if (!data.items.length)
            body.append(node("p", { role: "status" }, "No specializations match this search."));
        const cards = node("div", { class: "row g-3" });
        data.items.forEach((item) =>
            cards.append(
                node(
                    "div",
                    { class: "col-12" },
                    node(
                        "article",
                        { class: "surface p-3" },
                        node("h3", { class: "h5" }, `${item.code} — ${item.title}`),
                        node("p", {}, item.active ? "Active" : "Inactive — retained for reference"),
                        button(`Edit specialization ${item.code}`, () =>
                            editSpecialization(program, item.id, restore),
                        ),
                        button(`Requirements for ${item.code}`, () =>
                            ui.degreeAudit().launch(program, null, [], item.id, restore),
                        ),
                    ),
                ),
            ),
        );
        body.append(cards);
        const controls = node("div", { class: "d-flex flex-wrap gap-2 mt-3" });
        if (state.stack.length)
            controls.append(
                button("Previous specializations", () =>
                    specializations(program, back, {
                        ...state,
                        after: state.stack.at(-1),
                        stack: state.stack.slice(0, -1),
                    }),
                ),
            );
        if (data.next_after)
            controls.append(
                button("More specializations", () =>
                    specializations(program, back, {
                        ...state,
                        after: data.next_after,
                        stack: [...state.stack, state.after],
                    }),
                ),
            );
        body.append(controls);
    }

    async function editSpecialization(program, id, back) {
        const base = `/sis/programs/${program}/specializations/`;
        const value = id
            ? await request(`${base}${id}/`)
            : { id: null, revision: null, code: "", title: "", description: null, active: true };
        const body = openDrawer(
            id ? "Edit specialization" : "Add specialization",
            "PROGRAM SPECIALIZATION SETUP",
        );
        body.append(
            node(
                "p",
                {},
                "Use a clear code and name. Deactivate a specialization when it should no longer be offered; its reference is preserved.",
            ),
        );
        const fields = [
            ["code", "Specialization code"],
            ["title", "Specialization title"],
            ["description", "Specialization description", "textarea", "", false],
        ];
        const form = await buildForm(fields, value);
        form.elements.namedItem("code").maxLength = 80;
        form.elements.namedItem("code").pattern = "[A-Za-z0-9][A-Za-z0-9._-]{0,79}";
        form.elements.namedItem("code").title = "Use letters, numbers, dots, underscores or hyphens.";
        form.elements.namedItem("description").maxLength = 10000;
        const active = node("input", {
            id: "program-specialization-active",
            type: "checkbox",
            class: "form-check-input",
        });
        active.checked = value.active;
        const save = node("button", { type: "submit", class: "btn btn-primary" }, "Save specialization");
        form.append(
            node(
                "div",
                { class: "form-check my-3" },
                active,
                node("label", { for: active.id, class: "form-check-label" }, "Active specialization"),
            ),
            node("div", { class: "d-flex flex-wrap gap-2" }, save, button("Cancel specialization changes", back)),
        );
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const draft = formData(form, fields);
                await request(`${base}save/`, "POST", {
                    id: value.id,
                    revision: value.revision,
                    code: draft.code,
                    title: draft.title,
                    description: form.elements.namedItem("description").value || null,
                    active: active.checked,
                });
                if (!body.isConnected) return;
                await back("Specialization saved.");
            }, save);
        });
        body.append(form);
    }
    async function specializationPolicy(program, back, notice = "") {
        const path = `/sis/programs/${program}/specializations/policy/`;
        const data = await request(path);
        const body = openDrawer(
            "Specialization selection policy",
            "INSTITUTION BOUNDARIES → PROGRAM REQUIREMENTS",
        );
        body.append(
            node("h3", { class: "h5" }, `${data.program.code} — ${data.program.title}`),
            node(
                "p",
                {},
                "Set how many specializations a student may pursue within this program. Offered choices and student selections are separate. Publish the program for an academic year to retain these rules; saving here changes no existing student selection.",
            ),
            button("Back to specializations", back),
        );
        if (notice) body.append(node("p", { role: "status", class: "alert alert-success" }, notice));
        if (data.errors.length)
            body.append(
                node(
                    "p",
                    { role: "alert", class: "alert alert-warning" },
                    "These draft settings conflict with the institutional policy. Correct them before program publication.",
                ),
            );
        const field = (name, label, value, minimum, maximum) => {
            const input = node("input", {
                id: `specialization-policy-${name}`,
                name,
                type: "number",
                min: String(minimum),
                max: String(maximum),
                step: "1",
                required: true,
                value: value == null ? "" : String(value),
                class: "form-control",
            });
            return {
                input,
                group: node(
                    "div",
                    { class: "mb-3" },
                    node("label", { for: input.id, class: "form-label" }, label),
                    input,
                ),
            };
        };
        const minimum = field(
            "minimum",
            "Required specializations (zero means optional)",
            data.settings.minimum,
            0,
            100,
        );
        const maximum = field(
            "maximum",
            "Program maximum specializations",
            data.settings.maximum ?? data.institution.default_maximum,
            0,
            data.institution.maximum,
        );
        const inherit = node("input", {
            id: "specialization-policy-inherit",
            type: "checkbox",
            class: "form-check-input",
            checked: data.settings.maximum === null,
        });
        const status = node("p", { role: "status" });
        function refresh() {
            maximum.input.disabled = inherit.checked;
            if (inherit.checked) maximum.input.value = data.institution.default_maximum;
            status.textContent = `Institutional ceiling: ${data.institution.maximum}. Institutional default maximum: ${data.institution.default_maximum}. Program maximum: ${inherit.checked ? data.institution.default_maximum : maximum.input.value || "not entered"}.`;
        }
        const sharing = node("select", {
            id: "specialization-component-sharing", name: "component_sharing", class: "form-select", required: true,
        });
        for (const [value, label] of [
            ["one_component", "Apply a course to one component"],
            ["multiple_components", "Allow a course in degree and specialization requirements"],
        ]) sharing.append(node("option", { value }, label));
        sharing.value = data.settings.component_sharing || "one_component";
        const form = node(
            "form",
            { class: "surface p-3 my-3" },
            node("h3", { class: "h5" }, "Program limits"),
            minimum.group,
            node(
                "div",
                { class: "form-check mb-3" },
                inherit,
                node(
                    "label",
                    { for: inherit.id, class: "form-check-label" },
                    "Use institutional default maximum",
                ),
            ),
            maximum.group,
            node(
                "p",
                {},
                "A maximum of zero disables specialization selection for this program. A required number above zero needs enough active offered choices before publication.",
            ),
            status,
            node("h3", { class: "h5" }, "Course sharing between curriculum components"),
            node("label", { for: sharing.id, class: "form-label" }, "Course sharing between degree and specializations"),
            sharing,
            node("p", { class: "form-text" },
                "Choose whether the same completed course can satisfy the degree and selected specializations. Sharing across groups inside a component is configured in its requirements. Overall credits and GPA are counted once. Publish the program to retain this choice; earlier publications stay unchanged. Independent component reviews do not apply this cross-component rule."),
        );
        const save = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Save program specialization policy",
        );
        form.append(save);
        form.addEventListener("input", refresh);
        refresh();
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                await request(path, "POST", {
                    revision: data.settings.revision,
                    minimum: Number(minimum.input.value),
                    maximum: inherit.checked ? null : Number(maximum.input.value),
                    component_sharing: sharing.value,
                });
                if (body.isConnected)
                    await specializationPolicy(
                        program,
                        back,
                        "Program specialization policy saved. Publish the program to retain it for an academic year.",
                    );
            }, save);
        });
        body.append(form);
        if (data.can_manage_institution) {
            const panel = node(
                "details",
                { class: "surface p-3 my-3" },
                node("summary", { class: "h5" }, "Institution-wide boundaries"),
            );
            const ceiling = field(
                "institution_maximum",
                "Institution-wide maximum specializations",
                data.institution.maximum,
                1,
                100,
            );
            const defaultMaximum = field(
                "institution_default",
                "Default maximum for programs",
                data.institution.default_maximum,
                1,
                100,
            );
            const globalForm = node(
                "form",
                {},
                node(
                    "p",
                    {},
                    "These boundaries govern future publication reviews across programs. A lower ceiling or default can require changes to program drafts. Earlier publications keep their recorded policy.",
                ),
                ceiling.group,
                defaultMaximum.group,
            );
            const commit = node(
                "button",
                { type: "submit", class: "btn btn-outline-primary" },
                "Save institutional specialization boundaries",
            );
            globalForm.append(commit);
            globalForm.addEventListener("submit", (event) => {
                event.preventDefault();
                task(async () => {
                    if (!globalForm.reportValidity()) return;
                    await request("/sis/catalog/specialization-policy/", "POST", {
                        revision: data.institution.revision,
                        maximum: Number(ceiling.input.value),
                        default_maximum: Number(defaultMaximum.input.value),
                    });
                    if (body.isConnected)
                        await specializationPolicy(
                            program,
                            back,
                            "Institutional specialization boundaries saved. Earlier publications are unchanged.",
                        );
                }, commit);
            });
            panel.append(globalForm);
            body.append(panel);
        }
    }

    function specializationPublication(snapshot) {
        if (!snapshot) return node("p", {}, "Specialization policy was not recorded.");
        const section = node(
            "section",
            { class: "surface p-3 mb-3", "aria-label": "Published specialization choices and policy" },
            node("h3", { class: "h5" }, "Specialization choices & policy"),
            node(
                "p",
                {},
                `Required: ${snapshot.effective.minimum} · Maximum per pursuit: ${snapshot.effective.maximum} · Institutional ceiling: ${snapshot.institution.maximum}. ${snapshot.program.maximum == null ? "Uses the recorded institutional default." : "Uses the recorded program maximum."}`,
            ),
        );
        section.append(node("p", {}, "Course sharing between components: " + ({
            one_component: "Apply a completed course to one component.",
            multiple_components: "A completed course may satisfy degree and specialization requirements.",
        }[snapshot.component_sharing] || "Not recorded in this earlier publication.")));
        if (!snapshot.offered.length) {
            section.append(node("p", {}, "No specialization choices offered in this publication."));
            return section;
        }
        const detail = node(
            "details",
            {},
            node(
                "summary",
                {},
                `${snapshot.offered.length} offered specialization${snapshot.offered.length === 1 ? "" : "s"}`,
            ),
        );
        let loaded = false;
        detail.addEventListener("toggle", () => {
            if (!detail.open || loaded) return;
            loaded = true;
            const content = node("div");
            detail.append(content);
            function show(offset = 0, focus = false) {
                const heading = node(
                    "p",
                    { tabindex: "-1", role: "status" },
                    `Offered specializations ${offset + 1}–${Math.min(offset + 20, snapshot.offered.length)} of ${snapshot.offered.length}`,
                );
                content.replaceChildren(heading);
                snapshot.offered
                    .slice(offset, offset + 20)
                    .forEach((choice) =>
                        content.append(
                            node(
                                "article",
                                { class: "border-top py-2" },
                                node("h4", { class: "h6" }, `${choice.code} — ${choice.title}`),
                                node(
                                    "p",
                                    { class: "course-description-text" },
                                    choice.description || "No description specified.",
                                ),
                            ),
                        ),
                    );
                const controls = node("div", { class: "d-flex flex-wrap gap-2" });
                if (offset > 0)
                    controls.append(button("Previous offered specializations", () => show(offset - 20, true)));
                if (offset + 20 < snapshot.offered.length)
                    controls.append(button("More offered specializations", () => show(offset + 20, true)));
                content.append(controls);
                if (focus) heading.focus();
            }
            show();
        });
        section.append(detail);
        return section;
    }
    async function history(id, back, cursor = null, stack = []) {
        const data = await request(`/sis/programs/${id}/publication/page/`, "POST", cursor ? { cursor } : {});
        const body = openDrawer("Published program details", "RETAINED ACADEMIC DEFINITIONS");
        body.append(
            node(
                "p",
                {},
                "These are the details actually published. Earlier unversioned curriculum references have no inferred descriptions or classifications.",
            ),
        );
        if (!data.items.length)
            body.append(node("p", { role: "status" }, "No program details have been published yet."));
        data.items.forEach((item) => {
            const card = node(
                "details",
                { class: "surface p-3 mb-2" },
                node(
                    "summary",
                    {},
                    `${item.academic_year_code} · Revision ${item.catalog_revision} · ${item.definition.title}`,
                ),
            );
            card.append(
                details(item.definition),
                specializationPublication(item.specializations),
                node("p", {}, `Published ${new Date(item.effective_at).toLocaleString()}`),
            );
            body.append(card);
        });
        if (stack.length)
            body.append(
                button("Previous publications", () => history(id, back, stack.at(-1), stack.slice(0, -1))),
            );
        if (data.next_cursor)
            body.append(
                button("More publications", () => history(id, back, data.next_cursor, [...stack, cursor])),
            );
        body.append(button("Back to program details", back));
    }
    return { open };
};
