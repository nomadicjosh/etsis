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
        const body = openDrawer("Choose offering definitions", "DEFINITIONS → REVIEW → PUBLISH");
        const form = node("form", {}),
            groups = new Map();
        const review = submit("Review definitions & publication");
        const missing = () =>
            setup.sections.some(
                (row) => !row.was_bound && (!draft.choices.has(row.id) || row.has_student_records),
            );
        const update = () => {
            review.disabled = missing();
        };
        body.append(
            node(
                "p",
                {},
                "Choose the published definition for each course. One selection applies to the empty drafts listed together. Confirmed publication retains those definitions and activates the sections in one step.",
            ),
        );
        for (const row of setup.sections) {
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
            if (row.has_student_records) {
                form.append(
                    node(
                        "p",
                        { class: "alert alert-warning" },
                        `${row.code} already has student records and cannot receive a retrospective definition. Use a new offering.`,
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
                    node("summary", {}, `${row.code} · retained course definition`),
                    definitionDetails(row.course_definition.definition),
                    reference(row.course_version_id),
                ),
            ),
            node(
                "p",
                { class: "alert alert-info" },
                "Publication activates these sections and applies the reviewed self-registration choices. Term registration windows and normal eligibility checks still apply. Publication does not reserve rooms or charge students.",
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
        return node(
            "dl",
            { class: "detail-list" },
            node("div", {}, node("dt", {}, "Course code"), node("dd", {}, definition.code)),
            node("div", {}, node("dt", {}, "Course title"), node("dd", {}, definition.title)),
            node(
                "div",
                {},
                node("dt", {}, "Transcript title"),
                node(
                    "dd",
                    {},
                    Object.hasOwn(definition, "transcript_title")
                        ? definition.transcript_title || "Not specified"
                        : "Not recorded in this earlier definition",
                ),
            ),
            node(
                "div",
                {},
                node("dt", {}, "Catalog description"),
                node(
                    "dd",
                    { class: "course-description-text" },
                    Object.hasOwn(definition, "description")
                        ? definition.description || "Not specified"
                        : "Not recorded in this earlier definition",
                ),
            ),
            node("div", {}, node("dt", {}, "Credits"), node("dd", {}, definition.credits_milli / 1000)),
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
    async function publications(course, cursor = null, stack = [], returnTo = null) {
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
        const review = submit("Review current definition");
        form.append(review);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const reviewed = await request(`/sis/courses/${course}/publication/review/`, "POST", {
                    reason: reason.value,
                });
                reviewDefinition(course, reviewed, returnTo);
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
                node("p", { class: "form-text" }, `Recorded: ${item.recorded_at}`),
                reference(item.version_id),
            );
            body.append(details);
        }
        if (stack.length)
            body.append(
                button("Previous definitions page", () =>
                    publications(course, stack.at(-1), stack.slice(0, -1), returnTo),
                ),
            );
        if (data.next_cursor)
            body.append(
                button("Next definitions page", () =>
                    publications(course, data.next_cursor, [...stack, cursor], returnTo),
                ),
            );
        if (returnTo) body.append(button("Back to offering definitions", returnTo));
    }
    function reviewDefinition(course, reviewed, returnTo = null) {
        const body = openDrawer("Review course publication", "REVIEW → PUBLISH");
        body.append(
            node(
                "p",
                {},
                `This creates version ${reviewed.expected_version + 1}. Review the saved definition before publishing.`,
            ),
            definitionDetails(reviewed.definition),
            node("p", {}, `Effective from this review: ${reviewed.effective_at}`),
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
            button("Return to definitions", () => publications(course, null, [], returnTo)),
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
    return { clone, start, plans, publications, courseText, sectionDefinition, sectionPublications, templateDraft: draftList };
};
