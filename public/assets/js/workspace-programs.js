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
                        button("Published program details", () => history(id, () => open(id))),
                        button("Back to program details", () => open(id)),
                    );
                },
                "btn-primary",
            ),
        );
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
