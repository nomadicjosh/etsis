/* Reviewed catalog setup. Drafts stay private in memory until an explicit save. */
window.createWorkspaceCatalog = function (ui) {
    "use strict";
    const { node, request, task, buildForm, formData, openDrawer, table, money } = ui;
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
    const submit = (label) => node("button", { type: "submit", class: "btn btn-primary" }, label);
    const reference = (id) => node("p", {}, "Reference: ", node("code", {}, id));
    async function clone(source) {
        const review = await request(`/sis/courses/${source}/clone/review/`);
        await cloneDetails(
            source,
            review,
            {
                title: review.source.title,
                credits_milli: review.source.credits_milli / 1000,
            },
            [],
        );
    }
    async function cloneDetails(source, review, draft, selected) {
        const body = openDrawer("Clone course as a draft", "DETAILS → REVIEW → SAVE DRAFT");
        const fields = [
            ["code", "New course code"],
            ["title", "New course title"],
            ["credits_milli", "Credits", "credits"],
        ];
        const form = await buildForm(fields, draft);
        const chosen = new Set(selected);
        const prerequisites = node(
            "fieldset",
            { class: "mb-4" },
            node("legend", { class: "h5" }, "Prerequisites to copy"),
        );
        review.prerequisites.forEach((item) => {
            const id = `clone-prerequisite-${item.id}`;
            const check = node("input", {
                type: "checkbox",
                class: "form-check-input me-2",
                id,
                checked: chosen.has(item.id),
            });
            check.addEventListener("change", () =>
                check.checked ? chosen.add(item.id) : chosen.delete(item.id),
            );
            prerequisites.append(
                node(
                    "div",
                    { class: "form-check" },
                    check,
                    node(
                        "label",
                        { for: id, class: "form-check-label" },
                        `${item.code} — ${item.title}${item.active ? "" : " (inactive)"}`,
                    ),
                ),
            );
        });
        if (!review.prerequisites.length)
            prerequisites.append(node("p", {}, "The source has no prerequisites."));
        const save = submit("Review course clone");
        form.append(prerequisites, save);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const data = formData(form, fields);
                clonePlan(source, review, data, [...chosen]);
            }, save);
        });
        body.append(
            node(
                "p",
                {},
                `Source: ${review.source.code} — ${review.source.title}. Choose a new code and the prerequisites to retain. The new course is inactive until deliberately activated; student records, sections and charges are never copied.`,
            ),
            form,
        );
    }
    function clonePlan(source, review, draft, prerequisites) {
        const body = openDrawer("Review course clone", "DETAILS → REVIEW → SAVE DRAFT");
        body.append(
            button(
                "Change clone details",
                () =>
                    cloneDetails(
                        source,
                        review,
                        { ...draft, credits_milli: draft.credits_milli / 1000 },
                        prerequisites,
                    ),
                "btn-link",
            ),
            node("h3", {}, `${draft.code} — ${draft.title}`),
            node(
                "p",
                {},
                `${draft.credits_milli / 1000} credits. ${prerequisites.length} prerequisites selected. New status: inactive draft.`,
            ),
            grid(
                ["Code", "Prerequisite"],
                review.prerequisites
                    .filter((item) => prerequisites.includes(item.id))
                    .map((item) => [item.code, item.title]),
                "Selected prerequisites",
            ),
            reference(review.operation_id),
            node(
                "p",
                { class: "form-text" },
                "After an interrupted response, keep this review to retry or check the saved clone.",
            ),
            node(
                "div",
                { class: "inline-actions" },
                button(
                    "Save course draft",
                    async () => {
                        const saved = await request(`/sis/courses/${source}/clone/commit/`, "POST", {
                            ...draft,
                            prerequisites,
                            revision: review.revision,
                            operation_id: review.operation_id,
                        });
                        cloneReceipt(saved);
                        await ui.refreshDirectory();
                    },
                    "btn-primary",
                ),
                button("Check saved clone", async () =>
                    cloneReceipt(await request(`/sis/catalog/clones/${review.operation_id}/`)),
                ),
            ),
        );
    }
    function cloneReceipt(saved) {
        const body = openDrawer("Course draft saved", "CATALOG RECEIPT");
        body.append(
            node(
                "p",
                { role: "status" },
                `${saved.code} — ${saved.title} was created as an inactive draft with ${saved.prerequisites_copied} prerequisites.`,
            ),
            reference(saved.id),
            button("Open course draft", () => ui.openRecord("courses", saved), "btn-primary"),
        );
    }
    async function start() {
        const body = openDrawer("Build a course offering plan", "TERM → SECTIONS → REVIEW → SAVE");
        const fields = [["term_id", "Offering term", "lookup", "terms"]];
        const form = await buildForm(fields),
            save = submit("Choose sections");
        form.append(save);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (form.reportValidity()) await addSection(formData(form, fields).term_id, []);
            }, save);
        });
        body.append(
            node(
                "p",
                {},
                "Prepare up to 25 sections for one term, review their details and save them as inactive drafts. Publication is a separate confirmation. Choose in-person, online or hybrid delivery for each section. Rooms are optional and separate from delivery mode. This setup does not charge students or reserve rooms.",
            ),
            form,
            button("Choose an offering template", () => ui.templates().page()),
            button("Open saved offering plans", () => plans()),
        );
    }
    async function addSection(term, rows, preset = {}, editing = null, templateTarget = null) {
        const body = openDrawer(
            editing === null ? "Add a section to the plan" : "Edit planned section",
            "TERM → SECTIONS → REVIEW → SAVE",
        );
        const fields = [
            ["course_id", "Course", "lookup", "courses"],
            ["code", "Section code"],
            ["capacity", "Seats", "number"],
            ["faculty_user_id", "Faculty account (optional)", "lookup", "faculty", false],
            ["delivery_mode", "Delivery mode", "choice", ui.deliveryModes],
            ["fee_minor", "Section fee", "money"],
            ["currency", "Currency", "currency"],
            ["web_registration", "Enable self-registration when published", "checkbox"],
        ];
        const form = await buildForm(fields, {
                capacity: 25,
                delivery_mode: "unspecified",
                fee_minor: 0,
                currency: "USD",
                ...preset,
            }),
            save = submit("Keep section in plan");
        form.elements.code.maxLength = 80;
        form.append(save);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const data = formData(form, fields),
                    copy = [...rows];
                data.faculty_user_id ||= null;
                data.id = editing === null ? (await request("/sis/ui/operation-id/")).id : rows[editing].id;
                if (editing === null) copy.push(data);
                else copy[editing] = data;
                draftList(term, copy, templateTarget);
            }, save);
        });
        body.append(
            node(
                "p",
                { class: "form-text" },
                "Codes can use institutional punctuation, such as MATH.101.26FA.A. Faculty and pricing belong to this section. You can revise them before saving.",
            ),
            form,
            button("Back to planned sections", () => draftList(term, rows, templateTarget), "btn-link"),
        );
    }
    function draftList(term, rows, templateTarget = null) {
        const body = openDrawer(
            templateTarget ? "Template section defaults" : "Planned sections",
            templateTarget ? "EDIT DEFAULTS → REVIEW → SAVE TEMPLATE" : "TERM → SECTIONS → REVIEW → SAVE",
        );
        body.append(
            node(
                "p",
                { role: "status" },
                templateTarget
                    ? `${rows.length} of 25 reusable defaults. Changes have not been saved to the template.`
                    : `${rows.length} of 25 sections prepared. These changes are still in memory and have not been saved.`,
            ),
            rows.length
                ? grid(
                      ["Section", "Seats", "Delivery", "Fee", "Self-registration", "Actions"],
                      rows.map((row, index) => [
                          row.code,
                          row.capacity,
                          ui.deliveryModes.find((mode) => mode.value === row.delivery_mode)?.label ||
                              "Delivery to be confirmed",
                          money(row.fee_minor, row.currency),
                          row.web_registration ? "On publication" : "No",
                          node(
                              "div",
                              { class: "inline-actions" },
                              button(`Edit ${row.code}`, () =>
                                  addSection(
                                      term,
                                      rows,
                                      {
                                          ...row,
                                          fee_minor: row.fee_minor / 100,
                                      },
                                      index,
                                      templateTarget,
                                  ),
                              ),
                              button(
                                  `Remove ${row.code}`,
                                  () =>
                                      draftList(
                                          term,
                                          rows.filter((_, i) => i !== index),
                                          templateTarget,
                                      ),
                                  "btn-outline-danger",
                              ),
                          ),
                      ]),
                      "Planned course sections",
                  )
                : node("p", {}, "Add at least one section."),
        );
        if (rows.length < 25)
            body.append(
                button("Add another section", () => addSection(term, rows, {}, null, templateTarget)),
            );
        if (rows.length)
            body.append(
                button(templateTarget ? "Review template changes" : "Save as reusable template", () =>
                    ui.templates().fromPlan(rows, templateTarget),
                ),
            );
        if (rows.length && templateTarget === null)
            body.append(
                button(
                    "Review offering plan",
                    async () => {
                        const review = await request("/sis/catalog/offerings/review/", "POST", {
                            term_id: term,
                            sections: rows,
                        });
                        offeringReview(term, rows, review);
                    },
                    "btn-primary",
                ),
            );
    }
    function summary(review) {
        return node(
            "section",
            { class: "record-section", "aria-label": "Offering details" },
            node("h3", {}, `Term ${review.term.code}`),
            node(
                "p",
                {},
                `${review.term.starts_on} through ${review.term.ends_on}. Add/drop deadline: ${review.term.add_drop_ends_on}.`,
            ),
            grid(
                ["Section", "Course", "Seats", "Faculty", "Delivery", "Fee", "Self-registration"],
                review.sections.map((row) => [
                    row.code,
                    `${row.course_code} — ${row.course_title}`,
                    row.capacity,
                    row.faculty_name || "Unassigned",
                    ui.deliveryModes.find((mode) => mode.value === row.delivery_mode)?.label ||
                        "Delivery to be confirmed",
                    money(row.fee_minor, row.currency),
                    row.web_registration ? "Enabled on publication" : "Disabled",
                ]),
                "Reviewed offering sections",
            ),
        );
    }
    function offeringReview(term, rows, review) {
        const body = openDrawer("Review offering plan", "TERM → SECTIONS → REVIEW → SAVE");
        body.append(
            button("Change planned sections", () => draftList(term, rows), "btn-link"),
            summary(review),
            node(
                "p",
                { class: "alert alert-info" },
                "Saving creates inactive drafts with self-registration disabled. Review and publish separately when setup is complete. Room reservations remain a separate scheduling task; this plan does not reserve a room or post charges.",
            ),
            reference(review.operation_id),
            node(
                "p",
                { class: "form-text" },
                "After an interrupted response, keep the plan to retry or check its saved outcome.",
            ),
            node(
                "div",
                { class: "inline-actions" },
                button(
                    "Save section drafts",
                    async () => {
                        const saved = await request("/sis/catalog/offerings/save/", "POST", {
                            term_id: term,
                            sections: rows,
                            operation_id: review.operation_id,
                            revision: review.revision,
                        });
                        receipt(saved);
                        await ui.refreshDirectory();
                    },
                    "btn-primary",
                ),
                button("Check saved offering plan", () => savedPlan(review.operation_id)),
            ),
        );
    }
    function receipt(saved) {
        const body = openDrawer(
            saved.published_at ? "Offering plan published" : "Offering drafts saved",
            "CATALOG RECEIPT",
        );
        body.append(
            node(
                "p",
                { role: "status" },
                saved.published_at
                    ? `Published ${saved.review.sections.length} sections at ${saved.published_at}.`
                    : `${saved.review.sections.length} section drafts saved. They are inactive and unavailable for registration.`,
            ),
            summary(saved.review),
            reference(saved.operation_id),
        );
        if (saved.published_at) {
            for (const row of saved.review.sections.filter((row) => row.section_version_id)) {
                body.append(
                    node(
                        "details",
                        { class: "surface p-3 mb-3" },
                        node("summary", {}, `${row.code} · retained offering version`),
                        offeringDetails(row.section_definition, row.faculty_name ?? "Not assigned"),
                        reference(row.section_version_id),
                    ),
                );
            }
        }
        if (!saved.published_at)
            body.append(
                button("Review publication", () => offeringDefinitions(saved.operation_id), "btn-primary"),
            );
        body.append(
            button("Save reviewed defaults as template", () =>
                ui.templates().fromPlan(saved.review.sections),
            ),
            button("Saved offering plans", () => plans()),
        );
    }
    async function savedPlan(id) {
        receipt(await request(`/sis/catalog/offerings/${id}/`));
    }
    async function offeringDefinitions(id, draft = { reason: "", choices: new Map() }) {
        const setup = await request(`/sis/catalog/offerings/${id}/publication-setup/`, "POST", {});
        draft.dates ??= new Map();
        const body = openDrawer("Choose offering definitions", "DEFINITIONS & DATES → REVIEW → PUBLISH");
        const form = node("form", {}),
            groups = new Map();
        const review = submit("Review definitions & publication");
        const missing = () =>
            setup.sections.some(
                (row) => row.has_student_records || (!row.was_bound && !draft.choices.has(row.id)),
            );
        const update = () => {
            review.disabled = missing();
        };
        body.append(
            node(
                "p",
                {},
                "Choose the published definition for each course and review each section's instructional dates. Confirmed publication retains the course and offering versions and activates the empty drafts in one step.",
            ),
        );
        const dates = node(
            "fieldset",
            { class: "surface p-3 mb-3" },
            node("legend", { class: "h5" }, "Instructional dates"),
            node(
                "p",
                { class: "form-text" },
                `Term: ${setup.term.starts_on} through ${setup.term.ends_on}. Adjust individual sections when teaching dates differ. Registration and drop deadlines still follow term policy.`,
            ),
        );
        for (const row of setup.sections) {
            if (!draft.dates.has(row.id))
                draft.dates.set(row.id, {
                    instruction_starts_on: row.instruction_starts_on,
                    instruction_ends_on: row.instruction_ends_on,
                });
            const values = draft.dates.get(row.id);
            const title = node("summary", {});
            const warning = node("p", { class: "form-text", role: "status" });
            const entry = node("details", { class: "mb-3" }, title);
            const controls = {};
            for (const [name, label] of [
                ["instruction_starts_on", "Instruction starts"],
                ["instruction_ends_on", "Instruction ends"],
            ]) {
                const input = node("input", {
                    type: "date",
                    class: "form-control mb-2",
                    required: true,
                    id: `offering-${row.id}-${name}`,
                    readonly: Boolean(row.selected_offering),
                });
                input.value = values[name];
                input.addEventListener("invalid", () => {
                    entry.open = true;
                });
                controls[name] = input;
                entry.append(
                    node("label", { for: input.id, class: "form-label" }, `${label} for ${row.code}`),
                    input,
                );
                input.addEventListener("input", () => {
                    values[name] = input.value;
                    updateDates();
                });
            }
            function updateDates() {
                title.textContent = `${row.code} · ${values.instruction_starts_on} through ${values.instruction_ends_on}`;
                controls.instruction_ends_on.setCustomValidity(
                    values.instruction_ends_on < values.instruction_starts_on
                        ? "Instruction must end on or after its start date."
                        : "",
                );
                warning.textContent =
                    values.instruction_starts_on < setup.term.starts_on ||
                    values.instruction_ends_on > setup.term.ends_on
                        ? "These instructional dates extend beyond the term. Check them before confirming."
                        : "";
            }
            updateDates();
            entry.append(warning);
            if (row.selected_offering)
                entry.append(
                    node(
                        "p",
                        { class: "form-text" },
                        "These dates belong to the already selected offering version. Review an offering amendment separately to change them.",
                    ),
                );
            dates.append(entry);
        }
        form.append(dates);
        for (const row of setup.sections) {
            if (row.has_student_records) {
                form.append(
                    node(
                        "p",
                        { class: "alert alert-warning" },
                        `${row.code} has student records. Offering-plan activation requires empty drafts; review those records separately.`,
                    ),
                );
                continue;
            }
            if (row.was_bound) {
                form.append(
                    node(
                        "details",
                        { class: "surface p-3 mb-3" },
                        node(
                            "summary",
                            {},
                            `${row.code} · retained ${row.course_code} — ${row.course_title}`,
                        ),
                        definitionDetails(row.course_definition.definition),
                        reference(row.course_version_id),
                    ),
                );
                continue;
            }
            if (!groups.has(row.course_id)) groups.set(row.course_id, []);
            groups.get(row.course_id).push(row);
        }
        for (const [course, rows] of groups) {
            const status = node("p", { role: "status", class: "form-text" });
            const list = node("div", {});
            const field = node(
                "fieldset",
                { class: "surface p-3 mb-3" },
                node("legend", { class: "h5" }, `${rows[0].course_code} — ${rows[0].course_title}`),
                node("p", {}, `Sections: ${rows.map((row) => row.code).join(", ")}`),
                status,
                list,
            );
            form.append(field);
            const chosen = () => {
                const item = draft.choices.get(rows[0].id);
                status.textContent = item
                    ? `Selected version ${item.version}: ${item.definition.title}. Applies to ${rows.length} sections.`
                    : "Choose a published version.";
            };
            async function page(cursor = null, stack = []) {
                const result = await request(
                    `/sis/sections/${rows[0].id}/course-definition/page/`,
                    "POST",
                    cursor ? { cursor } : {},
                );
                if (result.bound || result.has_student_records) {
                    throw new Error("This section changed. Reopen publication setup before continuing.");
                }
                list.replaceChildren();
                for (const item of result.versions.items) {
                    const key = `offering-definition-${course}-${item.version_id}`;
                    const radio = node("input", {
                        type: "radio",
                        class: "form-check-input me-2",
                        name: `offering-definition-${course}`,
                        id: key,
                        value: item.version_id,
                        checked: draft.choices.get(rows[0].id)?.version_id === item.version_id,
                    });
                    radio.addEventListener("change", () => {
                        rows.forEach((row) => draft.choices.set(row.id, item));
                        chosen();
                        update();
                    });
                    list.append(
                        node(
                            "div",
                            { class: "mb-3" },
                            node(
                                "div",
                                { class: "form-check" },
                                radio,
                                node(
                                    "label",
                                    { for: key, class: "form-check-label" },
                                    `Version ${item.version} · ${item.definition.code} · ${item.definition.title}`,
                                ),
                            ),
                            node(
                                "details",
                                {},
                                node("summary", {}, "Inspect course definition"),
                                definitionDetails(item.definition),
                            ),
                        ),
                    );
                }
                if (!result.versions.items.length)
                    list.append(
                        node(
                            "p",
                            {},
                            "Publish a course definition before publishing these offerings. Saved section drafts remain available.",
                        ),
                        button("Open course publications", () =>
                            publications(course, null, [], () => offeringDefinitions(id, draft)),
                        ),
                    );
                if (stack.length)
                    list.append(button("Previous versions", () => page(stack.at(-1), stack.slice(0, -1))));
                if (result.versions.next_cursor)
                    list.append(
                        button("More versions", () => page(result.versions.next_cursor, [...stack, cursor])),
                    );
                chosen();
            }
            await page();
        }
        const reason = node("textarea", {
            id: "offering-publication-reason",
            class: "form-control",
            required: true,
            maxlength: 1000,
            rows: 3,
            "aria-describedby": "offering-publication-reason-help",
        });
        reason.value = draft.reason;
        reason.addEventListener("input", () => {
            draft.reason = reason.value;
        });
        form.append(
            node("label", { for: reason.id, class: "form-label" }, "Reason for offering publication"),
            reason,
            node(
                "p",
                { id: "offering-publication-reason-help", class: "form-text" },
                "The authorized decision and reason are retained privately. Definitions become effective for these sections when publication succeeds.",
            ),
            review,
        );
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity() || missing()) return;
                const choices = setup.sections
                    .filter((row) => !row.was_bound)
                    .map((row) => ({ section_id: row.id, version_id: draft.choices.get(row.id).version_id }));
                const reviewed = await request(`/sis/catalog/offerings/${id}/publication-review/`, "POST", {
                    course_versions: choices,
                    section_dates: setup.sections.map((row) => ({
                        section_id: row.id,
                        ...draft.dates.get(row.id),
                    })),
                    reason: draft.reason,
                });
                publication(reviewed, () => offeringDefinitions(id, draft));
            }, review);
        });
        update();
        body.append(form);
    }
    function publication(review, back) {
        const body = openDrawer("Confirm offering publication", "REVIEW CURRENT DRAFTS → PUBLISH");
        body.append(
            summary(review),
            reference(review.operation_id),
            node("p", {}, `Reason: ${review.reason}`),
            ...review.sections.map((row) =>
                node(
                    "details",
                    { class: "surface p-3 mb-3" },
                    node(
                        "summary",
                        {},
                        `${row.code} · ${row.instruction_starts_on} through ${row.instruction_ends_on}`,
                    ),
                    offeringDetails(row.section_definition, row.faculty_name ?? "Not assigned"),
                    node("h3", { class: "h5" }, "Retained course definition"),
                    definitionDetails(row.course_definition.definition),
                    reference(row.course_version_id),
                    node(
                        "p",
                        { class: "form-text" },
                        row.selected_offering
                            ? "The already selected offering version will be retained."
                            : "Publication will retain this offering version for future registrations.",
                    ),
                ),
            ),
            node(
                "p",
                { class: "alert alert-info" },
                "Publication retains these reviewed offering versions, activates the sections and applies the reviewed self-registration choices. Term registration windows and normal eligibility checks still apply. Publication does not reserve rooms or charge students.",
            ),
            button(
                "Publish reviewed sections",
                async () => {
                    receipt(
                        await request(`/sis/catalog/offerings/${review.operation_id}/publish/`, "POST", {
                            review_token: review.review_token,
                        }),
                    );
                    await ui.refreshDirectory();
                },
                "btn-primary",
            ),
            button("Change definition choices", back),
            button("Back to saved offering plan", () => savedPlan(review.operation_id)),
        );
    }
    async function plans(after = "", stack = []) {
        const result = await request("/sis/catalog/offerings/", "POST", {
            after,
        });
        const body = openDrawer("Saved offering plans", "DRAFTS & PUBLICATION");
        body.append(
            button("Build another offering plan", () => start(), "btn-primary"),
            result.items.length
                ? grid(
                      ["Term", "Sections", "Status", "Saved", "Action"],
                      result.items.map((item) => [
                          item.term_code,
                          item.section_count,
                          item.published_at ? "Published" : "Draft",
                          item.created_at,
                          button("Open offering plan", () => savedPlan(item.operation_id)),
                      ]),
                      "Saved offering plans",
                  )
                : node("p", {}, "No offering plans have been saved."),
        );
        if (stack.length)
            body.append(
                button("Previous offering plans page", () => plans(stack.at(-1), stack.slice(0, -1))),
            );
        if (result.next_after)
            body.append(
                button("Next offering plans page", () => plans(result.next_after, [...stack, after])),
            );
    }
    async function courseText(course) {
        const draft = await request(`/sis/courses/${course}/text/`);
        const body = openDrawer("Course titles & description", "ACADEMIC CATALOG");
        const form = node("form", {});
        const title = node("input", {
            class: "form-control",
            type: "text",
            id: "course-transcript-title",
            name: "transcript_title",
            maxlength: 80,
            value: draft.transcript_title || "",
            "aria-describedby": "course-text-help",
        });
        const description = node("textarea", {
            class: "form-control",
            id: "course-catalog-description",
            name: "description",
            maxlength: 10000,
            rows: 8,
            "aria-describedby": "course-text-help",
        });
        description.value = draft.description || "";
        form.append(
            node("label", { class: "form-label", for: title.id }, "Transcript title (optional)"),
            title,
            node(
                "label",
                { class: "form-label mt-3", for: description.id },
                "Catalog description (optional)",
            ),
            description,
            node(
                "p",
                { id: "course-text-help", class: "form-text" },
                "Use plain text. Leave fields blank when unspecified. Saving keeps a draft; review and publish a definition to use this text in the portal and for new transcript records.",
            ),
        );
        const save = submit("Save course text draft");
        form.append(save);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                await request(`/sis/courses/${course}/text/`, "POST", {
                    transcript_title: title.value,
                    description: description.value,
                    revision: draft.revision,
                });
                const done = openDrawer("Course text draft saved", "READY FOR REVIEW");
                done.append(
                    node(
                        "p",
                        { role: "status" },
                        "Your text is saved as a draft. Earlier published definitions remain available.",
                    ),
                    button("Review publication", () => publications(course), "btn-primary"),
                    button("Continue editing text", () => courseText(course)),
                );
            }, save);
        });
        body.append(node("p", {}, `${draft.code} · ${draft.title}`), form);
    }
    function definitionDetails(definition) {
        const subject = definition.subject
            ? `${definition.subject.code} — ${definition.subject.title}`
            : "Not specified";
        const classifications = Object.entries(definition.classifications || {}).map(([kind, value]) =>
            node("div", {},
                node("dt", {}, { department: "Department", academic_level: "Academic level", course_level: "Course level" }[kind]),
                node("dd", {}, value ? `${value.code} — ${value.title}` : "Not specified"),
            ),
        );
        return node(
            "dl",
            { class: "detail-list" },
            node("div", {}, node("dt", {}, "Course code"), node("dd", {}, definition.code)),
            node("div", {}, node("dt", {}, "Course title"), node("dd", {}, definition.title)),
            node("div", {}, node("dt", {}, "Subject"), node("dd", {}, subject)),
            node(
                "div",
                {},
                node("dt", {}, "Transcript title"),
                node("dd", {}, definition.transcript_title || "Not specified"),
            ),
            node(
                "div",
                {},
                node("dt", {}, "Catalog description"),
                node("dd", { class: "course-description-text" }, definition.description || "Not specified"),
            ),
            node("div", {}, node("dt", {}, "Credits"), node("dd", {}, definition.credits_milli / 1000)),
            ...classifications,
            node(
                "div",
                {},
                node("dt", {}, "Prerequisite course references"),
                node(
                    "dd",
                    {},
                    definition.prerequisites.length ? definition.prerequisites.join(", ") : "None",
                ),
            ),
        );
    }
    async function publications(course, cursor = null, stack = [], returnTo = null, draft = { reason: "" }) {
        const data = await request(
            `/sis/courses/${course}/publication/page/`,
            "POST",
            cursor ? { cursor } : {},
        );
        const body = openDrawer("Published course definitions", "ACADEMIC CATALOG");
        body.append(
            node(
                "p",
                {},
                "Keep a dated catalog definition for institutional reference. Saving course edits does not replace earlier published versions.",
            ),
        );
        const form = node("form", {});
        const reason = node("textarea", {
            id: "course-publication-reason",
            name: "reason",
            class: "form-control",
            required: true,
            maxlength: 1000,
            rows: 3,
            "aria-describedby": "course-publication-help",
        });
        form.append(
            node("label", { for: reason.id, class: "form-label" }, "Reason for publication"),
            reason,
            node(
                "p",
                { id: "course-publication-help", class: "form-text" },
                "Explain this publication or revision. The reason is retained privately with the decision.",
            ),
        );
        reason.value = draft.reason;
        reason.addEventListener("input", () => {
            draft.reason = reason.value;
        });
        const review = submit("Review current definition");
        form.append(review);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const reviewed = await request(`/sis/courses/${course}/publication/review/`, "POST", {
                    reason: reason.value,
                });
                reviewDefinition(course, reviewed, returnTo, draft);
            }, review);
        });
        body.append(form, node("h3", { class: "h5 mt-4" }, "Retained versions (oldest first)"));
        if (!data.items.length) body.append(node("p", {}, "No definition has been published yet."));
        for (const item of data.items) {
            const details = node(
                "details",
                { class: "surface p-3 mb-3" },
                node(
                    "summary",
                    {},
                    `Version ${item.version} · ${item.definition.code} · ${item.definition.title}`,
                ),
            );
            details.append(
                definitionDetails(item.definition),
                node(
                    "p",
                    { class: "form-text" },
                    `Effective: ${item.effective_at}. Recorded: ${item.recorded_at}`,
                ),
                reference(item.version_id),
            );
            body.append(details);
        }
        if (stack.length)
            body.append(
                button("Previous definitions page", () =>
                    publications(course, stack.at(-1), stack.slice(0, -1), returnTo, draft),
                ),
            );
        if (data.next_cursor)
            body.append(
                button("Next definitions page", () =>
                    publications(course, data.next_cursor, [...stack, cursor], returnTo, draft),
                ),
            );
        if (returnTo) body.append(button("Back to offering definitions", returnTo));
    }
    function reviewDefinition(course, reviewed, returnTo = null, draft = { reason: reviewed.reason }) {
        const body = openDrawer("Review course publication", "REVIEW → PUBLISH");
        body.append(
            node(
                "p",
                {},
                `This creates version ${reviewed.expected_version + 1}. Review the saved definition before publishing.`,
            ),
            definitionDetails(reviewed.definition),
            node(
                "p",
                { class: "alert alert-info" },
                "Publication takes effect when you confirm it. An unconfirmed review expires after 20 minutes.",
            ),
            node("p", {}, `Reason: ${reviewed.reason}`),
        );
        body.append(
            button(
                "Publish reviewed definition",
                async () => {
                    const saved = await request(`/sis/courses/${course}/publication/commit/`, "POST", {
                        review_token: reviewed.review_token,
                    });
                    const result = openDrawer("Course definition published", "PUBLICATION SAVED");
                    result.append(
                        node(
                            "p",
                            { role: "status" },
                            `Version ${saved.last_version} is retained. Later course edits can be published as a new version.`,
                        ),
                        reference(saved.operation_id),
                        button(
                            "View published definitions",
                            () => publications(course, null, [], returnTo),
                            "btn-primary",
                        ),
                    );
                    if (returnTo) result.append(button("Back to offering definitions", returnTo));
                },
                "btn-primary",
            ),
            button("Refresh course review", async () => {
                const current = await request(`/sis/courses/${course}/publication/review/`, "POST", {
                    reason: draft.reason,
                });
                reviewDefinition(course, current, returnTo, draft);
            }),
            button("Return to definitions", () => publications(course, null, [], returnTo, draft)),
        );
    }
    async function sectionDefinition(
        section,
        cursor = null,
        stack = [],
        draft = { reason: "", selected: null },
    ) {
        const data = await request(
            `/sis/sections/${section}/course-definition/page/`,
            "POST",
            cursor ? { cursor } : {},
        );
        const body = openDrawer("Section course definition", "CHOOSE → REVIEW → RETAIN");
        body.append(node("p", {}, `Section: ${data.section.code}`));
        if (data.bound) {
            body.append(
                node(
                    "p",
                    { role: "status" },
                    "This section retains the course definition below. Later catalog edits do not replace it.",
                ),
                definitionDetails(data.bound.definition),
                reference(data.bound.version_id),
                node("p", {}, "Create another offering to use a different course version."),
                button("Publish section details", () => sectionPublications(section)),
            );
            return;
        }
        if (data.has_student_records) {
            body.append(
                node(
                    "p",
                    {},
                    "This section has student records and no retained course version. Its past definition is unknown. Use a new section rather than assigning a definition retrospectively.",
                ),
            );
            return;
        }
        body.append(
            node(
                "p",
                {},
                "Choose a published course definition before registering students. The selected code, titles, credits, description and prerequisite list stay with this section. Capacity, fees and scheduling keep their separate controls.",
            ),
        );
        const form = node("form", {});
        const choices = node("fieldset", {}, node("legend", { class: "h5" }, "Published course versions"));
        const review = submit("Review section definition");
        review.disabled = !draft.selected;
        for (const item of data.versions.items) {
            const id = `section-course-version-${item.version_id}`;
            const radio = node("input", {
                type: "radio",
                name: "course_version",
                id,
                class: "form-check-input me-2",
                value: item.version_id,
                checked: draft.selected?.version_id === item.version_id,
            });
            radio.addEventListener("change", () => {
                draft.selected = item;
                review.disabled = false;
            });
            choices.append(
                node(
                    "div",
                    { class: "surface p-3 mb-3" },
                    node(
                        "div",
                        { class: "form-check" },
                        radio,
                        node(
                            "label",
                            { for: id, class: "form-check-label" },
                            `Version ${item.version} · ${item.definition.code} · ${item.definition.title}`,
                        ),
                    ),
                    node(
                        "details",
                        {},
                        node("summary", {}, "Inspect definition"),
                        definitionDetails(item.definition),
                    ),
                ),
            );
        }
        if (!data.versions.items.length)
            choices.append(
                node(
                    "p",
                    {},
                    "No course definition has been published yet. Publish the course first, then return here.",
                ),
            );
        const reason = node("textarea", {
            id: "section-course-reason",
            class: "form-control",
            required: true,
            maxlength: 1000,
            rows: 3,
            "aria-describedby": "section-course-reason-help",
        });
        reason.value = draft.reason;
        reason.addEventListener("input", () => {
            draft.reason = reason.value;
        });
        form.append(
            choices,
            node("label", { for: reason.id, class: "form-label" }, "Reason for this section definition"),
            reason,
            node(
                "p",
                { id: "section-course-reason-help", class: "form-text" },
                "This decision and reason are retained privately. Review carefully; a bound section cannot switch versions.",
            ),
            review,
        );
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity() || !draft.selected) return;
                const reviewed = await request(`/sis/sections/${section}/course-definition/review/`, "POST", {
                    version_id: draft.selected.version_id,
                    reason: draft.reason,
                });
                reviewSectionDefinition(section, reviewed, () =>
                    sectionDefinition(section, cursor, stack, draft),
                );
            }, review);
        });
        body.append(form);
        if (draft.selected)
            body.append(
                node(
                    "p",
                    {},
                    `Selected: version ${draft.selected.version} · ${draft.selected.definition.title}`,
                ),
            );
        if (stack.length)
            body.append(
                button("Previous versions page", () =>
                    sectionDefinition(section, stack.at(-1), stack.slice(0, -1), draft),
                ),
            );
        if (data.versions.next_cursor)
            body.append(
                button("Next versions page", () =>
                    sectionDefinition(section, data.versions.next_cursor, [...stack, cursor], draft),
                ),
            );
    }
    function reviewSectionDefinition(section, reviewed, back) {
        const body = openDrawer("Review section definition", "REVIEW → RETAIN");
        body.append(
            node("p", {}, `Section: ${reviewed.section_code}`),
            definitionDetails(reviewed.selected.definition),
            reference(reviewed.selected.version_id),
            node("p", {}, `Reason: ${reviewed.reason}`),
            node(
                "p",
                {},
                "Retain this definition for the section. Use a new offering for a different version; existing student records are never rebound.",
            ),
            button(
                "Retain reviewed definition",
                async () => {
                    await request(`/sis/sections/${section}/course-definition/commit/`, "POST", {
                        review_token: reviewed.review_token,
                    });
                    await sectionDefinition(section);
                },
                "btn-primary",
            ),
            button("Change selection", back),
        );
    }
    function offeringDetails(definition, facultyLabel = null) {
        const fields = [
            ["Section", definition.code],
            [
                "Instructional dates",
                `${definition.instruction_starts_on} through ${definition.instruction_ends_on}`,
            ],
            ["Term", `${definition.term_code}: ${definition.term_starts_on} through ${definition.term_ends_on}`],
            ["Capacity", definition.capacity],
            [
                "Delivery",
                {
                    in_person: "In person",
                    online: "Online",
                    hybrid: "Hybrid",
                    unspecified: "To be confirmed",
                }[definition.delivery_mode],
            ],
            ["Section fee", money(definition.fee_minor, definition.currency)],
        ];
        if (facultyLabel !== null) fields.push(["Faculty", facultyLabel]);
        const details = node("dl", { class: "row g-2" });
        for (const [label, value] of fields) {
            details.append(
                node("dt", { class: "col-sm-4" }, label),
                node("dd", { class: "col-sm-8 text-break" }, value),
            );
        }
        details.append(
            node("dt", { class: "col-sm-4" }, "Course publication"),
            node("dd", { class: "col-sm-8 text-break" }, node("code", {}, definition.course_version_id)),
        );
        if (facultyLabel === null && definition.faculty_person_id) {
            details.append(
                node("dt", { class: "col-sm-4" }, "Retained faculty person"),
                node("dd", { class: "col-sm-8 text-break" }, node("code", {}, definition.faculty_person_id)),
            );
        }
        return details;
    }
    async function sectionPublications(section, cursor = null, stack = [], draft = null) {
        const setup = await request(`/sis/sections/${section}/publication/setup/`, "POST", {});
        const page = await request(
            `/sis/sections/${section}/publication/page/`,
            "POST",
            cursor ? { cursor } : {},
        );
        let currentDraft = draft;
        const body = openDrawer("Published section details", "ACADEMIC CATALOG");
        body.append(node("p", {}, `Section: ${setup.section.code}`));
        body.append(
            node(
                "p",
                {},
                "Retain the reviewed offering and instructional dates as a dated version. Earlier publications remain available when today's section settings change. Registration and drop deadlines continue to follow term policy.",
            ),
        );
        if (setup.publication_status === "unavailable") {
            body.append(node("p", { class: "alert alert-warning", role: "status" },
                "The latest published details are temporarily unavailable. Review the recorded history before confirming new dates. Suggested term dates may differ from the latest publication."));
        }
        if (setup.selected_offering) {
            body.append(node("p", { class: "alert alert-info", role: "status" },
                `New registrations retain section version ${setup.selected_offering.version}, with instructional dates ${setup.selected_offering.definition.instruction_starts_on} through ${setup.selected_offering.definition.instruction_ends_on}. Later publications do not replace this selection.`));
            body.append(button("Offering selection history", () => offeringSelectionHistory(section,
                () => sectionPublications(section, cursor, stack, currentDraft))));
        } else if (setup.has_student_records) {
            body.append(node("p", {}, "Initial offering selection requires an empty section. Earlier student records keep their original facts."));
        }
        if (!setup.course_definition) {
            body.append(
                node(
                    "p",
                    { role: "status" },
                    "Choose and retain the course definition first, then return here to publish the section details.",
                ),
            );
            body.append(button("Choose course definition", () => sectionDefinition(section)));
        } else {
            const initial = draft || {
                instruction_starts_on: setup.latest?.definition.instruction_starts_on || setup.term.starts_on,
                instruction_ends_on: setup.latest?.definition.instruction_ends_on || setup.term.ends_on,
                reason: "",
            };
            const fields = [
                ["instruction_starts_on", "Instruction starts on", "date"],
                ["instruction_ends_on", "Instruction ends on", "date"],
                ["reason", "Reason for section publication", "textarea"],
            ];
            const form = await buildForm(fields, initial);
            currentDraft = initial;
            form.addEventListener("input", () => {
                currentDraft = formData(form, fields);
            });
            const reason = form.querySelector('[name="reason"]');
            reason.maxLength = 1000;
            const help = node(
                "p",
                { id: "section-publication-date-help", class: "form-text" },
                setup.latest
                    ? "Dates start with the latest publication. Check them for this revision; the original versions stay retained."
                    : "Term dates are suggested starting values. Confirm the actual instructional dates; short sessions may differ.",
            );
            for (const name of ["instruction_starts_on", "instruction_ends_on"]) {
                form.querySelector(`[name="${name}"]`).setAttribute("aria-describedby", help.id);
            }
            const review = submit("Review section publication");
            form.append(help, review);
            form.addEventListener("submit", (event) => {
                event.preventDefault();
                task(async () => {
                    if (!form.reportValidity()) return;
                    const values = formData(form, fields);
                    const reviewed = await request(
                        `/sis/sections/${section}/publication/review/`,
                        "POST",
                        values,
                    );
                    const target = openDrawer("Review section publication", "REVIEW → PUBLISH");
                    target.append(
                        node(
                            "p",
                            {},
                            `Version ${reviewed.expected_version + 1} records the offering reviewed now.`,
                        ),
                        offeringDetails(reviewed.definition, reviewed.faculty_label),
                        node("p", {}, `Reason: ${reviewed.reason}`),
                        node(
                            "p",
                            {},
                            "Publication takes effect when confirmed. Existing registrations, grades and charges keep their current records.",
                        ),
                    );
                    if (
                        reviewed.definition.instruction_starts_on < reviewed.definition.term_starts_on ||
                        reviewed.definition.instruction_ends_on > reviewed.definition.term_ends_on
                    ) {
                        target.append(
                            node(
                                "p",
                                { class: "alert alert-warning", role: "status" },
                                "These instructional dates extend beyond the selected term. Confirm that this is the intended offering. Term deadlines stay as configured.",
                            ),
                        );
                    }
                    target.append(
                        button("Revise section publication", () =>
                            sectionPublications(section, cursor, stack, values),
                        ),
                        button(
                            "Publish reviewed section details",
                            async () => {
                                const result = await request(
                                    `/sis/sections/${section}/publication/commit/`,
                                    "POST",
                                    { review_token: reviewed.review_token },
                                );
                                const saved = openDrawer("Section details published", "PUBLICATION SAVED");
                                saved.append(
                                    node(
                                        "p",
                                        { role: "status" },
                                        `Version ${result.last_version} is retained with its instructional dates and reviewed offering details.`,
                                    ),
                                    reference(result.operation_id),
                                    button(
                                        "View section publications",
                                        () => sectionPublications(section),
                                        "btn-primary",
                                    ),
                                );
                            },
                            "btn-primary",
                        ),
                    );
                }, review);
            });
            body.append(form);
        }
        body.append(node("h3", { class: "h5 mt-4" }, "Retained section versions (oldest first)"));
        if (!page.items.length)
            body.append(
                node("p", {}, "No section details have been published yet. Earlier offering history is unknown."),
            );
        for (const item of page.items) {
            body.append(
                node(
                    "details",
                    { class: "surface p-3 mb-3" },
                    node(
                        "summary",
                        {},
                        `Version ${item.version} · ${item.definition.code} · ${item.definition.instruction_starts_on}`,
                    ),
                    offeringDetails(item.definition),
                    node(
                        "p",
                        { class: "form-text" },
                        `Effective: ${item.effective_at}. Recorded: ${item.recorded_at}`,
                    ),
                    reference(item.version_id),
                setup.selected_offering && item.version_id !== setup.selected_offering.version_id
                    ? button("Use this version for future registrations", () => selectOffering(section, item,
                        () => sectionPublications(section, cursor, stack, currentDraft), "", true))
                    : !setup.selected_offering && !setup.has_student_records
                        ? button("Use this version for new registrations", () => selectOffering(section, item,
                            () => sectionPublications(section, cursor, stack, currentDraft))) : null,
                ),
            );
        }
        if (stack.length)
            body.append(
                button("Previous section versions page", () =>
                    sectionPublications(section, stack.at(-1), stack.slice(0, -1), currentDraft),
                ),
            );
        if (page.next_cursor)
            body.append(
                button("Next section versions page", () =>
                    sectionPublications(section, page.next_cursor, [...stack, cursor], currentDraft),
                ),
            );
    }
    async function offeringSelectionHistory(section, back, cursor = null, stack = []) {
        const data = await request(
            `/sis/sections/${section}/publication/selection-page/`,
            "POST",
            cursor ? { cursor } : {},
        );
        const body = openDrawer("Offering selection history", "RETAINED DECISIONS");
        if (!data.items.length) body.append(node("p", {}, "No offering selection has been recorded."));
        for (const item of data.items) {
            body.append(
                node(
                    "details",
                    { class: "surface p-3 mb-3" },
                    node(
                        "summary",
                        {},
                        `${item.action === "amended" ? "Future offering amendment" : "Initial selection"} · publication version ${item.selected.version}`,
                    ),
                    offeringDetails(item.selected.definition),
                    node(
                        "p",
                        { class: "form-text" },
                        `Effective: ${item.effective_at}. Recorded: ${item.recorded_at}`,
                    ),
                ),
            );
        }
        if (stack.length)
            body.append(
                button("Previous selection history page", () =>
                    offeringSelectionHistory(section, back, stack.at(-1), stack.slice(0, -1)),
                ),
            );
        if (data.next_cursor)
            body.append(
                button("Next selection history page", () =>
                    offeringSelectionHistory(section, back, data.next_cursor, [...stack, cursor]),
                ),
            );
        body.append(button("Back to section publications", back));
    }
    async function selectOffering(section, item, back, reason = "", amend = false) {
        const body = openDrawer(
            amend ? "Amend future offering version" : "Choose section version for registration",
            "DETAILS → REVIEW → CONFIRM",
        );
        body.append(
            offeringDetails(item.definition),
            node(
                "p",
                {},
                amend
                    ? "Choose a published version for future registrations. Existing enrollment, academic and financial records keep their original facts."
                    : "Select the initial offering version for new registrations. This is available for an empty section; prior academic records are never assigned a version retrospectively.",
            ),
        );
        const fields = [
            [
                "reason",
                amend ? "Reason for future offering amendment" : "Reason for offering selection",
                "textarea",
            ],
        ];
        const form = await buildForm(fields, { reason });
        form.querySelector('[name="reason"]').maxLength = 1000;
        const review = submit(amend ? "Review future offering amendment" : "Review offering selection");
        form.append(review);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const draft = formData(form, fields);
                const reviewed = await request(
                    `/sis/sections/${section}/publication/${amend ? "amendment-review" : "selection-review"}/`,
                    "POST",
                    {
                        version_id: item.version_id,
                        reason: draft.reason,
                    },
                );
                const target = openDrawer(
                    amend ? "Review future offering amendment" : "Review offering selection",
                    "REVIEW → CONFIRM",
                );
                if (amend)
                    target.append(
                        node("h3", { class: "h5" }, "Previously selected offering"),
                        offeringDetails(reviewed.previous.definition),
                        node(
                            "p",
                            {},
                            `${reviewed.enrolled} currently enrolled students keep their recorded offering references and charges.`,
                        ),
                        node("h3", { class: "h5" }, "Offering for future registrations"),
                    );
                target.append(
                    offeringDetails(reviewed.selected.definition, reviewed.faculty_label),
                    node("p", {}, `Selected publication: version ${reviewed.selected.version}.`),
                    node("p", {}, `Reason: ${reviewed.reason}`),
                    node(
                        "p",
                        {},
                        "New registrations will retain this offering version. Earlier academic records and charges remain unchanged.",
                    ),
                    button(amend ? "Revise future offering amendment" : "Revise offering selection", () =>
                        selectOffering(section, item, back, draft.reason, amend),
                    ),
                    button(
                        amend ? "Confirm future offering amendment" : "Confirm offering selection",
                        async () => {
                            await request(
                                `/sis/sections/${section}/publication/${amend ? "amend" : "select"}/`,
                                "POST",
                                { review_token: reviewed.review_token },
                            );
                            await sectionPublications(section);
                        },
                        "btn-primary",
                    ),
                );
            }, review);
        });
        body.append(form, button("Back to section publications", back));
    }
    async function subjects(search = "", after = null, stack = [], choose = null) {
        const data = await request("/sis/catalog/subjects/", "POST", { search, ...(after ? { after } : {}) });
        const body = openDrawer(choose ? "Choose course subject" : "Course subjects", "ACADEMIC CATALOG");
        body.append(
            node(
                "p",
                {},
                "Subjects classify courses independently of their course codes. Retiring a subject preserves its earlier published uses.",
            ),
        );
        const form = node("form", {});
        const input = node("input", {
            type: "search",
            id: "subject-search",
            class: "form-control",
            maxlength: 100,
            value: search,
        });
        form.append(
            node("label", { for: input.id, class: "form-label" }, "Find subjects"),
            input,
            submit("Search subjects"),
        );
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(() => subjects(input.value, null, [], choose));
        });
        body.append(
            form,
            button("Add subject", () => subjectEditor(null, () => subjects(search, after, stack, choose))),
        );
        if (!data.items.length) body.append(node("p", {}, "No matching subjects. Add a subject to begin."));
        for (const row of data.items) {
            const card = node(
                "div",
                { class: "surface p-3 mb-2" },
                node("h3", { class: "h5" }, `${row.code} — ${row.title}`),
                node("p", {}, row.active ? "Active" : "Retired"),
                button(`Edit ${row.code}`, () =>
                    subjectEditor(row, () => subjects(search, after, stack, choose)),
                ),
            );
            if (choose && row.active)
                card.append(button(`Choose ${row.code}`, () => choose(row), "btn-primary"));
            body.append(card);
        }
        if (stack.length)
            body.append(
                button("Previous subjects", () => subjects(search, stack.at(-1), stack.slice(0, -1), choose)),
            );
        if (data.next_after)
            body.append(
                button("More subjects", () => subjects(search, data.next_after, [...stack, after], choose)),
            );
        if (choose) body.append(button("Back to course subject", () => choose(null)));
    }
    async function subjectEditor(row, back) {
        const body = openDrawer(row ? "Edit subject" : "Add subject", "REFERENCE SETUP");
        const fields = [
            ["code", "Subject code", "text"],
            ["title", "Subject name", "text"],
            ["active", "Active subject", "checkbox"],
        ];
        const form = await buildForm(fields, row ?? { active: true });
        form.elements.namedItem("code").maxLength = 40;
        const save = submit("Save subject");
        form.append(save);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                await request("/sis/catalog/subjects/save/", "POST", {
                    ...formData(form, fields),
                    id: row?.id ?? null,
                    revision: row?.revision ?? null,
                });
                await back();
            }, save);
        });
        body.append(form, button("Back to subjects", back));
    }
    async function courseSubject(course) {
        const current = await request(`/sis/courses/${course}/subject/`);
        const body = openDrawer("Course subject", "DRAFT CLASSIFICATION");
        body.append(
            node("p", {}, `${current.course.code} — ${current.course.title}`),
            node(
                "p",
                { role: "status" },
                current.subject
                    ? `${current.subject.code} — ${current.subject.title}`
                    : "No subject assigned",
            ),
            node(
                "p",
                {},
                "Save a draft subject, then review and publish the course to retain it. Earlier publications and student records stay unchanged.",
            ),
            button("Choose subject", () =>
                subjects("", null, [], async (row) => {
                    if (row) {
                        await request(`/sis/courses/${course}/subject/`, "POST", {
                            subject_id: row.id,
                            revision: current.revision,
                        });
                    }
                    await courseSubject(course);
                }),
            ),
            button("Review course publication", () => publications(course)),
        );
        if (current.subject)
            body.append(
                button("Clear draft subject", async () => {
                    await request(`/sis/courses/${course}/subject/`, "POST", {
                        subject_id: null,
                        revision: current.revision,
                    });
                    await courseSubject(course);
                }),
            );
    }
    return { subjects, courseSubject, clone, start, plans, publications, courseText, sectionDefinition, sectionPublications, templateDraft: draftList };
};
