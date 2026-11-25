/* Reviewed catalog setup. Drafts stay private in memory until an explicit save. */
window.createWorkspaceCatalog = function (ui) {
    "use strict";
    const { node, request, task, buildForm, formData, openDrawer, table, money } = ui;
    const prerequisiteOriginText = key => ui.prerequisiteOriginLabels?.[key] ||
        { either: 'Institutional or transfer', institutional: 'Institutional work only', transfer: 'Transfer work only' }[key];
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
    async function enrollmentReview(scope = { state: "below", search: "", include_inactive: false }, after = null, stack = []) {
        const body = openDrawer("Section enrollment targets", "REVIEW OFFERINGS");
        const view = node("div");
        body.append(view);
        const choices = ["below — Below target", "met — Target met", "unset — No target", "all — All sections"];
        const fields = [["term_id", "Academic term", "lookup", "terms", false],
            ["state", "Enrollment review", "choice", choices], ["search", "Find a section", "search", "", false],
            ["include_inactive", "Include inactive sections", "checkbox"]];
        const form = await buildForm(fields, { ...scope, state: choices.find(value => value.startsWith(scope.state + " — ")) });
        if (!view.isConnected) return;
        form.elements.search.maxLength = 80;
        form.append(submit("Review sections"));
        form.addEventListener("submit", event => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const next = formData(form, fields);
                next.state = next.state.split(" — ")[0];
                await enrollmentReview(next);
            }, form.querySelector('[type="submit"]'));
        });
        view.append(node("p", { class: "form-text" },
            "Compare current enrollment with each section's planning target. Only active registrations count; drops and withdrawals do not. Targets are advisory and never cancel a section or change a student's registration or bill."), form);
        const data = await request("/sis/sections/enrollment-review/", "POST", { ...scope, ...(after ? { after } : {}) });
        if (!view.isConnected) return;
        const content = data.items.length ? grid(["Section", "Course", "Enrolled", "Current target", "Selected offering"],
            data.items.map(row => [button(row.code, () => ui.openRecord("sections", row, {
                back: () => enrollmentReview(scope, after, stack), backLabel: "Back to enrollment targets",
            })), `${row.course_code} · ${row.course_title}`, `${row.enrolled} of ${row.capacity} seats`,
                row.minimum_enrollment ? `${row.minimum_enrollment}${row.below_target ? ` · ${row.minimum_enrollment - row.enrolled} needed` : " · Met"}` : "No target",
                row.published_minimum === null ? "Not recorded" :
                    `${row.published_minimum || "No target"}${row.target_changed ? " · Draft target changed" : ""}`]), "Section enrollment review")
            : node("p", { role: "status", class: "alert alert-info" }, "No sections match this review. Choose All sections or another academic term to review their setup.");
        view.append(content, node("p", { class: "form-text", role: "status" }, `${data.items.length} ${data.items.length === 1 ? "section" : "sections"} on this page. Counts describe current records; selected targets come from retained offering projections. Open a section to review and publish setup changes.`),
            node("div", { class: "inline-actions" },
                ...(stack.length ? [button("Previous sections", () => enrollmentReview(scope, stack.at(-1), stack.slice(0, -1)))] : []),
                ...(data.next_after ? [button("More sections", () => enrollmentReview(scope, data.next_after, [...stack, after]))] : [])));
    }
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
        const form = await buildForm(fields, draft, { layout: 'courses' });
        const chosen = new Set(selected);
        const prerequisites = node(
            "fieldset",
            { class: "mb-4" },
            node("legend", { class: "h5" }, "Entry requirements to copy"),
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
                        `${item.code} — ${item.title}${review.corequisites?.includes(item.id) ? " · Co-requisite" : ""}${item.active ? "" : " (inactive)"}`,
                    ),
                ),
            );
        });
        if (!review.prerequisites.length)
            prerequisites.append(node("p", {}, "The source has no entry requirements."));
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
                `Source: ${review.source.code} — ${review.source.title}. Choose a new code and the entry requirements to retain. The new course is inactive until deliberately activated; student records, sections and charges are never copied.`,
            ),
            form,
        );
    }
    function clonePlan(source, review, draft, prerequisites) {
        const body = openDrawer("Review course clone", "DETAILS → REVIEW → SAVE DRAFT");
        const groups = (review.prerequisite_groups || []).map((group, index) => ({
            selected: group.filter(id => prerequisites.includes(id)), minimum: review.prerequisite_minimums?.[index] || 1, grade: review.prerequisite_grade_points_milli?.[index] ?? null,
            origin: review.prerequisite_requirement_credit_origins?.[index] ?? review.prerequisite_credit_origin ?? 'either',
            completionWindow: review.prerequisite_completion_windows?.[index] || null,
        })).filter(group => group.selected.length);
        const overallMinimum = groups.length ? review.prerequisite_requirement_minimum : null;
        const invalidChoices = groups.some(group => group.selected.length < group.minimum) ||
            (groups.length > 0 && overallMinimum != null && groups.length < overallMinimum) ||
            (groups.length > 0 && review.prerequisite_rule && groups.length !== review.prerequisite_groups.length);

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
            node("p", {}, `${ui.ceuLabels?.course_title || "Course CEUs"}: ${window.continuingEducationText(review.source.continuing_education, ui.ceuLabels)}`),
            node(
                "p",
                {},
                `${draft.credits_milli / 1000} credits. ${prerequisites.length} entry requirement courses selected. New status: inactive draft.`,
            ),
            grid(
                ["Code", "Required course"],
                review.prerequisites
                    .filter((item) => prerequisites.includes(item.id))
                    .map((item) => [item.code, item.title]),
                "Selected entry requirements",
            ),
            review.prerequisite_groups ? node("p", { class: "form-text" }, !groups.length ? "No prerequisite requirements retained." :
                `${review.prerequisite_rule ? "Retained nested rule" : overallMinimum == null ? "All retained requirements" : `Meet ${overallMinimum} of ${groups.length} retained requirements`}: ` + groups.map(group => `${group.minimum > 1 ? `At least ${group.minimum} of: ` : ""}` +
                    group.selected.map(id => review.prerequisites.find(row => row.id === id)?.code || "Course")
                        .join(group.minimum > 1 ? "; " : " OR ") +
                    (group.grade === null ? "" : ` · Minimum ${group.grade / 1000} grade points per counted course`) +
                    (review.prerequisite_requirement_credit_origins ? ` · ${prerequisiteOriginText(group.origin)}` : '') +
                    (group.completionWindow ? ` · ${ui.prerequisiteOriginLabels.completion_from}: ${group.completionWindow.from || '—'}; ${ui.prerequisiteOriginLabels.completion_through}: ${group.completionWindow.through || '—'}` +
                        (group.completionWindow.max_age_months != null
                            ? ` · ${ui.prerequisiteOriginLabels.max_age_months}: ${group.completionWindow.max_age_months}` : '') : '')).join(overallMinimum == null ? " AND " : "; ") +
                    (review.prerequisite_rule ? ". Keep every nested requirement or clear all prerequisites; restructure the cloned draft afterwards." : ". Unselected groups are omitted from the cloned draft.")) : null,
            groups.length && review.prerequisite_rule ? ui.prerequisites().ruleView(review.prerequisite_rule) : null,
            review.corequisites?.some(id => prerequisites.includes(id)) ? node("p", { class: "form-text" },
                "Co-requisites — all selected courses required: " + review.corequisites.filter(id => prerequisites.includes(id))
                    .map(id => review.prerequisites.find(row => row.id === id)?.code || "Course").join(", ")) : null,
            invalidChoices ? node("p", { class: "alert alert-warning", role: "status" },
                "Keep enough courses and requirements to meet the retained minima, or omit prerequisite requirements entirely.") : null,
            reference(review.operation_id),
            node(
                "p",
                { class: "form-text" },
                "After an interrupted response, keep this review to retry or check the saved clone.",
            ),
            node(
                "div",
                { class: "inline-actions" },
                Object.assign(button(
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
                ), { disabled: invalidChoices }),
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
                `${saved.code} — ${saved.title} was created as an inactive draft with ${saved.prerequisites_copied} prerequisites and ${saved.corequisites_copied || 0} co-requisites.`,
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
            ["minimum_enrollment", "Minimum enrollment target", "number", "", false],
            ["faculty_user_id", "Faculty account (optional)", "lookup", "faculty", false],
            ["delivery_mode", "Delivery mode", "choice", ui.deliveryModes],
            ["fee_minor", "Section fee", "money"],
            ["currency", "Currency", "currency"],
            ["web_registration", "Enable self-registration when published", "checkbox"],
        ];
        const form = await buildForm(
                fields,
                {
                    capacity: 25,
                    delivery_mode: "unspecified",
                    fee_minor: 0,
                    currency: "USD",
                    ...preset,
                },
                { layout: "sections" },
            ),
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
                if (!data.minimum_enrollment) delete data.minimum_enrollment;
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
                        offeringDetails(row.section_definition, row.faculty_name ?? "Not assigned", row.classifications ?? null),
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
        const pendingView = openDrawer("Choose offering definitions", "ACADEMIC CATALOG");
        pendingView.append(node("p", { role: "status", class: "form-text" }, "Loading catalog details…"));
        const setup = await request(`/sis/catalog/offerings/${id}/publication-setup/`, "POST", {});
        if (!pendingView.isConnected) return;
        draft.dates ??= new Map();
        if (!pendingView.isConnected) return;
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
                    offeringDetails(row.section_definition, row.faculty_name ?? "Not assigned", row.classifications ?? null),
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
        const pendingView = openDrawer("Saved offering plans", "ACADEMIC CATALOG");
        pendingView.append(node("p", { role: "status", class: "form-text" }, "Loading catalog details…"));
        const result = await request("/sis/catalog/offerings/", "POST", {
            after,
        });
        if (!pendingView.isConnected) return;
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
        const pendingView = openDrawer("Course titles & description", "ACADEMIC CATALOG");
        pendingView.append(node("p", { role: "status", class: "form-text" }, "Loading catalog details…"));
        const draft = await request(`/sis/courses/${course}/text/`);
        if (!pendingView.isConnected) return;
        const body = openDrawer("Course titles & description", "ACADEMIC CATALOG");
        const form = node("form", { class: "record-form" });
        const title = node("input", {
            class: "form-control",
            type: "text",
            id: "course-transcript-title",
            name: "transcript_title",
            maxlength: 80,
            value: draft.transcript_title || "",
            "aria-describedby": "course-text-help",
        });
        const courseNumber = node('input', { id: 'course-number', name: 'course_number',
            class: 'form-control', type: 'text', maxlength: 32, value: draft.course_number || '',
            'aria-describedby': 'course-number-help' });
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
            node('label', { class: 'form-label', for: courseNumber.id }, 'Course number (optional)'), courseNumber,
            node('p', { id: 'course-number-help', class: 'form-text' },
                'Use up to 32 characters, including leading zeros or suffixes. The full course code remains its catalog identifier; no number is inferred from it.'),
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
        const textFields = node("fieldset", { class: "record-form-section" }, node("legend", {}, "Course display text"));
        textFields.append(...form.childNodes);
        const save = submit("Save course text draft");
        form.append(textFields, save);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                await request(`/sis/courses/${course}/text/`, "POST", {
                    transcript_title: title.value,
                    description: description.value,
                    course_number: courseNumber.value,
                    revision: draft.revision,
                });
                if (!body.isConnected || !form.isConnected) return;
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
        let assistantPanel = null;
        body.append(
            button("Optional AI help", async () => {
                if (
                    courseNumber.value !== (draft.course_number || '') ||
                    description.value !== (draft.description || "") ||
                    title.value !== (draft.transcript_title || "")
                ) {
                    throw new Error("Save your text changes before reviewing optional assistance.");
                }
                const preview = await request(`/sis/courses/${course}/assistance/`);
                if (!body.isConnected || !form.isConnected) return;
                if (assistantPanel) assistantPanel.remove();
                assistantPanel = node(
                    "section",
                    { class: "record-section", "aria-label": "Optional course assistance" },
                    node("h3", {}, "Optional course assistance"),
                );
                body.append(assistantPanel);
                if (!preview.enabled || !preview.tasks.length) {
                    assistantPanel.append(
                        node(
                            "p",
                            { role: "status" },
                            "Optional assistance is disabled. Your normal editing and publication workflow remains available.",
                        ),
                    );
                    return;
                }
                if (
                    (preview.draft.description || "") !== description.value ||
                    (preview.draft.transcript_title || "") !== title.value ||
                    preview.draft.code !== draft.code ||
                    preview.draft.title !== draft.title
                ) {
                    throw new Error(
                        "The saved course changed. Reload this editor before requesting assistance.",
                    );
                }
                assistantPanel.append(
                    node(
                        "p",
                        {},
                        `Provider: ${preview.label}. Only the displayed saved course facts will be sent for this request. Inspect free text and remove any personal or confidential information before consenting. Suggestions are optional and may be incorrect.`,
                    ),
                    node(
                        "p",
                        {},
                        "No student records, publication reasons, internal identifiers or histories are selected. Saving and publication still require your normal review.",
                    ),
                );
                const input = node(
                    "details",
                    {},
                    node("summary", {}, "Inspect exactly what will be sent"),
                    node("pre", { class: "text-wrap" }, JSON.stringify(preview.draft, null, 2)),
                );
                const consentId = "course-assistance-consent";
                const consent = node("input", {
                    type: "checkbox",
                    id: consentId,
                    class: "form-check-input",
                    required: true,
                });
                const results = node("div", { "aria-live": "polite" });
                const progress = node("p", { role: "status", class: "form-text" });
                const run = async (task) => {
                    if (!consent.reportValidity()) return;
                    if (
                        description.value !== (preview.draft.description || "") ||
                        title.value !== (preview.draft.transcript_title || "")
                    ) {
                        throw new Error(
                            "Your text changed. Save it and inspect a fresh preview before requesting assistance.",
                        );
                    }
                    controls.disabled = true;
                    consent.checked = false;
                    progress.textContent = "Requesting suggestions… Your draft is not being changed.";
                    results.replaceChildren();
                    try {
                        const response = await request(`/sis/courses/${course}/assistance/`, "POST", {
                            task,
                            consent: true,
                            revision: preview.revision,
                        });
                        if (!results.isConnected) return;
                        results.append(
                            node("h4", {}, "Suggestions to review"),
                            node("p", {}, response.advice.summary),
                        );
                        const suggestions = node("ul");
                        response.advice.suggestions.forEach((value) =>
                            suggestions.append(node("li", {}, value)),
                        );
                        results.append(suggestions);
                        if (task !== "readiness" && response.advice.description) {
                            results.append(
                                node("p", {}, response.advice.description),
                                button("Use suggested text", () => {
                                    if (description.value !== (preview.draft.description || "")) {
                                        throw new Error(
                                            "Your description has changed. Review it before replacing any text.",
                                        );
                                    }
                                    description.value = response.advice.description;
                                    description.dispatchEvent(new Event("input", { bubbles: true }));
                                    description.focus();
                                    progress.textContent =
                                        "Suggestion copied into the editor. Review it and use Save course text draft when ready; publication is a separate decision.";
                                }),
                            );
                        }
                        progress.textContent = "Suggestions received. No text has been saved or published.";
                    } finally {
                        controls.disabled = false;
                        if (!results.childNodes.length)
                            progress.textContent =
                                "No suggestion was applied. Normal editing remains available; another request requires fresh consent.";
                    }
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
                            { for: consentId, class: "form-check-label" },
                            `I agree to send the displayed saved course draft to ${preview.label} for this request.`,
                        ),
                    ),
                    ...(preview.tasks.includes("readiness")
                        ? [button("Suggest publication checks", () => run("readiness"))]
                        : []),
                    ...(preview.tasks.includes("description")
                        ? [button("Suggest a course description", () => run("description"))]
                        : []),
                    ...(preview.tasks.includes("rewrite")
                        ? [button("Rewrite description", () => run("rewrite"))]
                        : []),
                    ...(preview.tasks.includes("expand")
                        ? [button("Expand description", () => run("expand"))]
                        : []),
                );
                assistantPanel.append(input, controls, progress, results);
            }),
        );
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
            node('div', {}, node('dt', {}, 'Course number'), node('dd', {},
                Object.hasOwn(definition, 'course_number') ? definition.course_number || 'Not specified' : 'Not recorded in this publication')),
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
            node('div', {}, node('dt', {}, ui.creditLabels?.course_title || 'Course credit policy'),
                node('dd', {}, creditPolicyText(definition.credit_policy))),
            node("div", {}, node("dt", {}, ui.ceuLabels?.course_title || "Course CEUs"),
                node("dd", {}, window.continuingEducationText(definition.continuing_education, ui.ceuLabels))),
            ...classifications,
            node(
                "div",
                {},
                node("dt", {}, "Prerequisite course references"),
                node(
                    "dd",
                    {},
                    definition.prerequisite_groups ? ui.prerequisites().describe(definition) :
                        definition.prerequisites.length ? definition.prerequisites.join(", ") : "None",
                ),
            ),
        );
    }
    async function publications(course, cursor = null, stack = [], returnTo = null, draft = { reason: "" }, returnLabel = "Back to offering definitions") {
        const pendingView = openDrawer("Published course definitions", "ACADEMIC CATALOG");
        pendingView.append(node("p", { role: "status", class: "form-text" }, "Loading catalog details…"));
        const data = await request(
            `/sis/courses/${course}/publication/page/`,
            "POST",
            cursor ? { cursor } : {},
        );
        if (!pendingView.isConnected) return;
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
                reviewDefinition(course, reviewed, returnTo, draft, returnLabel);
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
                    publications(course, stack.at(-1), stack.slice(0, -1), returnTo, draft, returnLabel),
                ),
            );
        if (data.next_cursor)
            body.append(
                button("Next definitions page", () =>
                    publications(course, data.next_cursor, [...stack, cursor], returnTo, draft, returnLabel),
                ),
            );
        if (returnTo) body.append(button(returnLabel, returnTo));
    }
    function reviewDefinition(course, reviewed, returnTo = null, draft = { reason: reviewed.reason }, returnLabel = "Back to offering definitions") {
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
                            () => publications(course, null, [], returnTo, draft, returnLabel),
                            "btn-primary",
                        ),
                    );
                    if (returnTo) result.append(button(returnLabel, returnTo));
                },
                "btn-primary",
            ),
            button("Refresh course review", async () => {
                const current = await request(`/sis/courses/${course}/publication/review/`, "POST", {
                    reason: draft.reason,
                });
                reviewDefinition(course, current, returnTo, draft, returnLabel);
            }),
            button("Return to definitions", () => publications(course, null, [], returnTo, draft, returnLabel)),
        );
    }
    async function sectionDefinition(
        section,
        cursor = null,
        stack = [],
        draft = { reason: "", selected: null },
    ) {
        const pendingView = openDrawer("Section course definition", "ACADEMIC CATALOG");
        pendingView.append(node("p", { role: "status", class: "form-text" }, "Loading catalog details…"));
        const data = await request(
            `/sis/sections/${section}/course-definition/page/`,
            "POST",
            cursor ? { cursor } : {},
        );
        if (!pendingView.isConnected) return;
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
    function offeringDetails(definition, facultyLabel = null, classifications = null) {
        const fields = [
            ["Section", definition.code],
            [
                "Instructional dates",
                `${definition.instruction_starts_on} through ${definition.instruction_ends_on}`,
            ],
            ["Term", `${definition.term_code}: ${definition.term_starts_on} through ${definition.term_ends_on}`],
            ["Capacity", definition.capacity],
            [ui.creditLabels?.section_title || 'Offering credits', definition.credits_milli === undefined
                ? ui.creditLabels?.unrecorded || 'Not recorded in this publication' : definition.credits_milli / 1000],
            [ui.ceuLabels?.section_title || "Offered CEUs", window.continuingEducationText(definition.continuing_education, ui.ceuLabels)],
            ["Minimum enrollment target", definition.minimum_enrollment === undefined ? "Not recorded in this publication"
                : definition.minimum_enrollment ? `${definition.minimum_enrollment} students` : "No target"],
            ["Student self-registration", typeof definition.web_registration === "boolean"
                ? (definition.web_registration ? "Allowed" : "Staff registration only") : "Not recorded in this publication"],
            [
                "Delivery",
                {
                    in_person: "In person",
                    online: "Online",
                    hybrid: "Hybrid",
                    unspecified: "To be confirmed",
                }[definition.delivery_mode],
            ],
            ["Weekly contact hours", definition.contact_hours === undefined ? "Not recorded in this publication"
                : definition.contact_hours === null ? "Unknown"
                : `${(definition.contact_hours.weekly_contact_milli / 1000).toFixed(3)} (${definition.contact_hours.weekly_instruction_minutes} instructional min/week ÷ ${definition.contact_hours.unit_minutes}; rounding ${definition.contact_hours.rounding}; ${definition.contact_hours.policy_version ? `policy ${definition.contact_hours.policy_version}` : "50-minute default"})`],
            ["Section fee", money(definition.fee_minor, definition.currency)],
        ];
        if (facultyLabel !== null) fields.push(["Faculty", facultyLabel]);
        for (const [key, label] of [["instructional_method", "Instructional method"], ["campus", "Campus/site"], ["reporting_group", ui.reportingLabels?.group || "Reporting group"]]) {
            const value = classifications?.[key];
            fields.push([label, classifications === null || !Object.hasOwn(classifications, key) ? "Not recorded in this publication" : value ? `${value.code} — ${value.title}` : "Not specified"]);
        }
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
    async function sectionPublications(section, cursor = null, stack = [], draft = null, back = null) {
        const pendingView = openDrawer("Published section details", "ACADEMIC CATALOG");
        pendingView.append(node("p", { role: "status", class: "form-text" }, "Loading catalog details…"));
        const setup = await request(`/sis/sections/${section}/publication/setup/`, "POST", {});
        if (!pendingView.isConnected) return;
        const page = await request(
            `/sis/sections/${section}/publication/page/`,
            "POST",
            cursor ? { cursor } : {},
        );
        let currentDraft = draft;
        if (!pendingView.isConnected) return;
        const body = openDrawer("Published section details", "ACADEMIC CATALOG");
        if (back) body.append(button("Back to section", back, "btn-link"));
        body.append(node("p", {}, `Section: ${setup.section.code}`));
        if (setup.moodle?.linked) body.append(node("p", { role: "status" }, "This section is linked to Moodle. The background worker manages participant access."));
        else if (setup.moodle?.request_state) {
            const courseStatus = { queued: "Moodle course creation is queued; select its reviewed publication for registration.", processing: "Moodle course creation is processing or waiting for a retry.",
                review_required: "Moodle course creation needs review. Check the original request and institutional settings.", completed: "Moodle course creation completed." };
            body.append(node("p", { role: "status" }, courseStatus[setup.moodle.request_state] || "Review Moodle course setup."));
        }
        let assistancePanel = null;
        body.append(
            button("Optional section AI help", async () => {
                const preview = await request(`/sis/sections/${section}/assistance/`);
                if (!body.isConnected) return;
                if (assistancePanel) assistancePanel.remove();
                assistancePanel = node(
                    "section",
                    { "aria-label": "Optional section assistance", class: "record-section" },
                    node("h3", { tabindex: "-1" }, "Saved section readiness"),
                );
                body.append(assistancePanel);
                assistancePanel.querySelector("h3").focus();
                if (!preview.enabled) {
                    assistancePanel.append(
                        node(
                            "p",
                            { role: "status" },
                            "Optional assistance is disabled. Normal publication remains available.",
                        ),
                    );
                    return;
                }
                const consentId = "section-assistance-consent";
                const consent = node("input", {
                    type: "checkbox",
                    id: consentId,
                    class: "form-check-input",
                    required: true,
                });
                const results = node("div", { "aria-live": "polite" });
                const status = node("p", { role: "status", class: "form-text" });
                const controls = node(
                    "fieldset",
                    { class: "border-0 p-0" },
                    node("legend", { class: "h5" }, "Consent for one section request"),
                    node(
                        "div",
                        { class: "form-check mb-3" },
                        consent,
                        node(
                            "label",
                            { for: consentId, class: "form-check-label" },
                            `I agree to send the displayed saved section facts to ${preview.label} for this request.`,
                        ),
                    ),
                    button("Suggest saved section checks", async () => {
                        if (!consent.reportValidity()) return;
                        controls.disabled = true;
                        consent.checked = false;
                        results.replaceChildren();
                        status.textContent = "Requesting suggestions… No section settings are being changed.";
                        try {
                            const response = await request(`/sis/sections/${section}/assistance/`, "POST", {
                                consent: true,
                                revision: preview.revision,
                            });
                            if (!results.isConnected) return;
                            const list = node("ul");
                            response.advice.suggestions.forEach((value) => list.append(node("li", {}, value)));
                            results.append(
                                node("h4", {}, "Suggestions to review"),
                                node("p", {}, response.advice.summary),
                                list,
                            );
                            status.textContent =
                                "Suggestions received. Publication, offering selection and registration are unchanged.";
                        } finally {
                            controls.disabled = false;
                            if (!results.childNodes.length)
                                status.textContent =
                                    "No suggestion was applied. Another request requires fresh consent.";
                        }
                    }),
                );
                assistancePanel.append(
                    node(
                        "p",
                        {},
                        `Provider: ${preview.label}. Inspect the displayed saved facts. Do not consent if they contain confidential content. Suggestions may be incorrect.`,
                    ),
                    node(
                        "p",
                        {},
                        "This checks saved setup only. Unsaved instructional dates and publication reasons below are excluded; verify them separately. Student records, faculty identities and fees are not sent. No changes are made automatically.",
                    ),
                    node(
                        "details",
                        {},
                        node("summary", {}, "Inspect saved section facts to be sent"),
                        node("pre", { class: "text-wrap" }, JSON.stringify(preview.draft, null, 2)),
                    ),
                    controls,
                    status,
                    results,
                );
            }),
        );
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
                () => sectionPublications(section, cursor, stack, currentDraft, back))));
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
                currentDraft = { ...formData(form, fields), create_in_moodle: !!form.querySelector("#section-create-moodle")?.checked };
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
            const createMoodle = node("input", { type: "checkbox", id: "section-create-moodle", class: "form-check-input" });
            createMoodle.checked = initial.create_in_moodle === true;
            if (setup.moodle?.available && !setup.moodle.category_source) form.append(node("p", { class: "form-text", role: "status" }, "Choose Subjects or Departments under Moodle integration before requesting course creation."));
            if (setup.moodle?.available && setup.moodle.category_source) {
                form.append(node("div", { class: "form-check my-3" }, createMoodle,
                    node("label", { for: createMoodle.id, class: "form-check-label" }, "Create this section’s course in Moodle")),
                    node("p", { class: "form-text" }, "Created in the background after this publication is selected for registration. Eligible instructors and registered students are set up automatically when institutional account provisioning is enabled."));
            }
            const review = submit("Review section publication");
            form.append(help, review);
            form.addEventListener("submit", (event) => {
                event.preventDefault();
                task(async () => {
                    if (!form.reportValidity()) return;
                    const values = { ...formData(form, fields), create_in_moodle: !!setup.moodle?.available && !!setup.moodle.category_source && createMoodle.checked };
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
                        offeringDetails(reviewed.definition, reviewed.faculty_label, reviewed.classifications ?? null),
                        node("p", {}, `Reason: ${reviewed.reason}`),
                        node(
                            "p",
                            {},
                            "Publication takes effect when confirmed. Existing registrations, grades and charges keep their current records.",
                        ),
                    );
                    if (reviewed.moodle_category) target.append(node("p", {}, `Moodle category: ${reviewed.moodle_category.code} · ${reviewed.moodle_category.title} (${reviewed.moodle_category.source}).`));
                    if (reviewed.create_in_moodle) target.append(node("p", { class: "alert alert-info" }, "This confirmation also queues creation and linking of the Moodle course. Moodle availability will not delay the SIS publication."));
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
                            sectionPublications(section, cursor, stack, values, back),
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
                                    ...(result.moodle_creation_queued ? [node("p", { role: "status" }, "Moodle course creation is queued. Select this publication for registration to let the background worker create and link the course.")] : []),
                                    reference(result.operation_id),
                                    button(
                                        "View section publications",
                                        () => sectionPublications(section, null, [], null, back),
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
                    offeringDetails(item.definition, null, item.classifications ?? null),
                    node(
                        "p",
                        { class: "form-text" },
                        `Effective: ${item.effective_at}. Recorded: ${item.recorded_at}`,
                    ),
                    reference(item.version_id),
                setup.selected_offering && item.version_id !== setup.selected_offering.version_id
                    ? button("Use this version for future registrations", () => selectOffering(section, item,
                        () => sectionPublications(section, cursor, stack, currentDraft, back), "", true))
                    : !setup.selected_offering && !setup.has_student_records
                        ? button("Use this version for new registrations", () => selectOffering(section, item,
                            () => sectionPublications(section, cursor, stack, currentDraft, back))) : null,
                ),
            );
        }
        if (stack.length)
            body.append(
                button("Previous section versions page", () =>
                    sectionPublications(section, stack.at(-1), stack.slice(0, -1), currentDraft, back),
                ),
            );
        if (page.next_cursor)
            body.append(
                button("Next section versions page", () =>
                    sectionPublications(section, page.next_cursor, [...stack, cursor], currentDraft, back),
                ),
            );
    }
    async function offeringSelectionHistory(section, back, cursor = null, stack = []) {
        const pendingView = openDrawer("Offering selection history", "ACADEMIC CATALOG");
        pendingView.append(node("p", { role: "status", class: "form-text" }, "Loading catalog details…"));
        const data = await request(
            `/sis/sections/${section}/publication/selection-page/`,
            "POST",
            cursor ? { cursor } : {},
        );
        if (!pendingView.isConnected) return;
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
                    offeringDetails(item.selected.definition, null, item.selected.classifications ?? null),
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
            offeringDetails(item.definition, null, item.classifications ?? null),
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
                        offeringDetails(reviewed.previous.definition, null, reviewed.previous.classifications ?? null),
                        node(
                            "p",
                            {},
                            `${reviewed.enrolled} currently enrolled students keep their recorded offering references and charges.`,
                        ),
                        node("h3", { class: "h5" }, "Offering for future registrations"),
                    );
                target.append(
                    offeringDetails(reviewed.selected.definition, reviewed.faculty_label, reviewed.selected.classifications ?? null),
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
                            await back();
                        },
                        "btn-primary",
                    ),
                );
            }, review);
        });
        body.append(form, button("Back to section publications", back));
    }
    async function subjects(search = "", after = null, stack = [], choose = null) {
        const pendingView = openDrawer("Course subject", "ACADEMIC CATALOG");
        pendingView.append(node("p", { role: "status", class: "form-text" }, "Loading catalog details…"));
        const data = await request("/sis/catalog/subjects/", "POST", { search, ...(after ? { after } : {}) });
        if (!pendingView.isConnected) return;
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
        const pendingView = openDrawer("Course subject", "ACADEMIC CATALOG");
        pendingView.append(node("p", { role: "status", class: "form-text" }, "Loading catalog details…"));
        const current = await request(`/sis/courses/${course}/subject/`);
        if (!pendingView.isConnected) return;
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
    const contactHours = (section, back) => window.createSectionContactHours(ui).open(section, back);
    function creditPolicyText(policy) {
        if (!policy) return ui.creditLabels?.unrecorded || 'Not recorded in this publication';
        if (policy.minimum_milli === policy.maximum_milli) return (ui.creditLabels?.fixed_help
            || 'The retained course allows {value} academic credits.').replace('{value}', policy.minimum_milli / 1000);
        return (ui.creditLabels?.range_help || 'Allowed: {min}–{max} academic credits, in increments of {step}.')
            .replace('{min}', policy.minimum_milli / 1000).replace('{max}', policy.maximum_milli / 1000)
            .replace('{step}', policy.increment_milli / 1000);
    }
    function ceus(kind, row, back) {
        return window.createWorkspaceCeus({ node, request, task, openDrawer, labels: ui.ceuLabels })
            .open(kind, row.id, back, () => kind === "courses"
                ? publications(row.id, null, [], back, undefined, ui.ceuLabels?.back || "Back to record")
                : sectionPublications(row.id, null, [], null, back));
    }
    function credits(kind, row, back) {
        return window.createWorkspaceCreditPolicy({ node, request, task, openDrawer, labels: ui.creditLabels,
            saved: result => { row.credits_milli = result.offering_credits_milli ?? result.credits_milli;
                row.offering_credits_milli = result.offering_credits_milli; },
        }).open(kind, row.id, back, () => kind === 'courses' ? publications(row.id, null, [], back, undefined, ui.creditLabels?.back || 'Back to record')
            : sectionPublications(row.id, null, [], null, back));
    }
    return { ceus, credits, contactHours, enrollmentReview, subjects, courseSubject, clone, start, plans, publications, courseText, sectionDefinition, sectionPublications, templateDraft: draftList };
};
