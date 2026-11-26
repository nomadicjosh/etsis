/* Reference setup uses bounded searches; unsaved course choices stay in page memory. */
window.createWorkspaceClassifications = function (ui) {
    "use strict";
    const { node, request, task, buildForm, formData, openDrawer, actionMenu, badge } = ui;
    const reportingText = (key, fallback) => ui.reportingLabels?.[key] || fallback;
    const groupText = (kind, key, fallback) => kind === 'reporting_group' ? reportingText(key, fallback) : fallback;
    const kinds = {
        reporting_group: {
            singular: reportingText('group', 'Reporting group'),
            plural: reportingText('groups', 'Reporting groups'),
            detail: reportingText('group_help', 'An optional institutional reporting period or group shared by related sections. It does not change instructional terms, dates, credits, charges or census rules.'),
        },
        school: {
            singular: "School/college",
            plural: "Schools & colleges",
            detail: "An optional school, college or faculty offering a program. Assign it explicitly; it is not inferred from the department.",
        },
        field_of_study: {
            singular: "Field of study",
            plural: "Fields of study",
            detail: "Institution-defined program classifications, such as CIP or ISCED codes. Enter the code and label your institution uses; no national code list is assumed.",
        },
        department: {
            singular: "Department",
            plural: "Departments",
            detail: "An academic department can serve undergraduate and graduate study. Assign its school/college explicitly; majors and minors can name their responsible department.",
        },
        academic_level: {
            singular: "Academic level",
            plural: "Academic levels",
            detail: "The study level, such as undergraduate or graduate.",
        },
        course_level: {
            singular: "Course level",
            plural: "Course levels",
            detail: "The course's place in the curriculum, such as introductory or advanced.",
        },
        instructional_method: {
            singular: "Instructional method",
            plural: "Instructional methods",
            detail: "How a section is taught, such as lecture, laboratory or seminar. Delivery mode separately identifies online, hybrid or in-person teaching.",
        },
        campus: {
            singular: "Campus/site",
            plural: "Campuses & sites",
            detail: "An optional academic campus or site. Programs and sections can share this reference library. Selecting a campus does not reserve a room or restrict online delivery; leave it blank when it does not apply.",
        },
        award: {
            singular: "Award",
            plural: "Degrees & awards",
            detail: "The credential a program offers, such as a bachelor's degree or certificate. This definition does not award a credential to a student.",
        },
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
    const glyphs = {
        school: "buildings", field_of_study: "bookmark", department: "diagram-3",
        academic_level: "mortarboard", course_level: "layers", instructional_method: "easel2",
        campus: "geo-alt", award: "award", reporting_group: "collection",
    };

    async function library(
        kind = "department",
        search = "",
        after = null,
        stack = [],
        choose = null,
        back = null,
        backLabel = "Back to course classifications",
    ) {
        const category = kinds[kind];
        const sectionKind = ["instructional_method", "campus", "reporting_group"].includes(kind);
        const extendedProgramKind = ["school", "field_of_study"].includes(kind);
        const visibleKinds = sectionKind ? ["instructional_method", "campus", "reporting_group"] : extendedProgramKind
            ? ["school", "field_of_study"] : ["department", "academic_level", "course_level", "award"];
        const body = openDrawer(
            choose
                ? groupText(kind, "choose_group", `Choose ${category.singular.toLowerCase()}`)
                : sectionKind
                  ? category.plural
                  : extendedProgramKind ? "Schools & fields" : kind === "award"
                    ? category.plural
                    : "Departments & levels",
            "ACADEMIC CATALOG",
        );
        const loading = node("p", { role: "status", class: "form-text" }, "Loading classifications…");
        body.append(loading);
        const data = await request(
            `/sis/catalog/classifications/${kind}/page/`,
            "POST",
            {
                search,
                ...(after ? { after } : {}),
            },
        );
        if (!body.isConnected) return;
        loading.remove();
        const heading = node("div", { class: "classification-library-heading" },
            node("h3", { class: "h5 text-break" }, category.plural));
        if (!choose) {
            const navigation = node("div", {
                role: "group",
                "aria-label": "Classification category",
            });
            const entries = Object.entries(kinds)
                .filter(
                    ([key]) => visibleKinds.includes(key),
                )
                .map(([key, value]) => {
                    const tab = button(
                        value.plural,
                        () => library(key),
                        key === kind ? "btn-primary" : "btn-outline-primary",
                    );
                    tab.setAttribute(
                        "aria-pressed",
                        key === kind ? "true" : "false",
                    );
                    tab.prepend(node("i", { class: `bi bi-${key === kind ? "check2" : glyphs[key]}`, "aria-hidden": "true" }));
                    return tab;
                });
            navigation.append(actionMenu("Change category", entries, {
                icon: "collection",
            }));
            heading.append(navigation);
        }
        body.append(
            heading,
            node("p", { class: "form-text" }, category.detail),
            node(
                "p",
                { class: "text-secondary" },
                "Retire a definition to stop new assignments. Earlier published academic definitions and student records keep their original labels.",
            ),
        );
        const form = node("form", { class: "classification-library-search" });
        const input = node("input", {
            id: "classification-search",
            type: "search",
            class: "form-control",
            maxlength: 100,
            value: search,
        });
        const field = node("div", { class: "classification-search-field" },
            node("label", { for: input.id, class: "form-label" }, groupText(kind, "find_groups", `Find ${category.plural.toLowerCase()}`)),
            input,
        );
        const clear = button("Clear", () => library(kind, "", null, [], choose, back, backLabel), "btn-outline-secondary");
        clear.setAttribute("aria-label", "Clear classification search");
        clear.classList.add("workspace-icon-control");
        clear.prepend(node("i", { class: "bi bi-x-lg", "aria-hidden": "true" }));
        clear.disabled = !search;
        input.addEventListener("input", () => { clear.disabled = !input.value && !search; });
        form.append(field, node("div", { class: "classification-search-actions" },
            node(
                "button",
                { type: "submit", class: "btn btn-primary", "aria-label": "Search classifications" },
                "Search",
            ), clear));
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(() =>
                library(kind, input.value, null, [], choose, back, backLabel),
            );
        });
        const refresh = () =>
            library(kind, search, after, stack, choose, back, backLabel);
        body.append(
            form,
            node("div", { class: "inline-actions mb-3" }, button(groupText(kind, "add_group", `Add ${category.singular.toLowerCase()}`), () =>
                editor(kind, null, choose || refresh, refresh),
            )),
            node("p", { class: "form-text", role: "status" }, `${data.items.length} ${data.items.length === 1 ? "classification" : "classifications"} on this page.`),
        );
        const pages = node("nav", { class: "inline-actions mb-3", "aria-label": "Classification pages" });
        if (stack.length) pages.append(button("Previous classifications", () =>
            library(kind, search, stack.at(-1), stack.slice(0, -1), choose, back, backLabel)));
        if (data.next_after) pages.append(button("More classifications", () =>
            library(kind, search, data.next_after, [...stack, after], choose, back, backLabel)));
        if (pages.childElementCount) body.append(pages);
        if (!data.items.length)
            body.append(
                node(
                    "p",
                    { role: "status" },
                    "No matching classifications. Add one or try another search.",
                ),
            );
        data.items.forEach((row) => {
            const active = row.active === true || Number(row.active) === 1;
            const actions = node("div", { class: "classification-record-actions" },
                button(`${groupText(kind, "edit", "Edit")} ${row.code}`, () => editor(kind, row, refresh, refresh), "btn-outline-secondary"));
            const card = node(
                "article",
                { class: "classification-record-card", "aria-label": `${category.singular} ${row.code}` },
                node("div", { class: "classification-record-heading" },
                    node("span", { class: "icon-soft", "aria-hidden": "true" }, node("i", { class: `bi bi-${glyphs[kind]}` })),
                    node("h4", {}, node("strong", {}, row.code), node("span", {}, row.title)),
                    badge(active ? "Active" : "Retired")),
                kind === "department" ? node("p", { class: "form-text" }, row.school ? `School/college: ${row.school.code} — ${row.school.title}` : "School/college not assigned") : null,
                !active ? node("p", { class: "form-text" }, "Unavailable for new assignments. Earlier recorded uses retain their labels.") : null,
                actions,
            );
            if (choose && active)
                actions.prepend(
                    button(
                        `${groupText(kind, "choose", "Choose")} ${row.code}`,
                        () => choose(row),
                        "btn-primary",
                    ),
                );
            body.append(card);
        });
        if (back) body.append(button(backLabel, back));
    }

    async function editor(kind, row, saved, back, draft = null, school = row?.school || null) {
        const category = kinds[kind];
        const body = openDrawer(
            groupText(kind, row ? "edit_group" : "add_group", `${row ? "Edit" : "Add"} ${category.singular.toLowerCase()}`),
            "REFERENCE SETUP",
        );
        const fields = [
            ["code", groupText(kind, "group_code", `${category.singular} code`)],
            ["title", groupText(kind, "group_name", `${category.singular} name`)],
            ["active", "Available for new assignments", "checkbox"],
        ];
        const form = await buildForm(fields, draft || row || { active: true });
        if (!body.isConnected) return;
        if (kind === "department") {
            const restore = (choice = school) => editor(kind, row, saved, back, formData(form, fields), choice);
            form.append(node("section", { class: "record-section", "aria-label": "Department school/college" },
                node("h3", { class: "h6" }, "School/college affiliation"),
                node("p", {}, school ? `${school.code} — ${school.title}` : "No school/college assigned"),
                node("p", { class: "form-text" }, "This is the department's current organization. New curriculum publications retain it; earlier publications stay unchanged. It grants no additional access."),
                button("Choose department school/college", () => library("school", "", null, [], restore, () => restore(), "Back to department")),
                school ? button("Clear department school/college", () => restore(null)) : null));
        }
        form.elements.namedItem("code").maxLength = 40;
        const save = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Save classification",
        );
        form.append(save);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const result = await request(
                    `/sis/catalog/classifications/${kind}/save/`,
                    "POST",
                    {
                        ...formData(form, fields),
                        id: row?.id ?? null,
                        revision: row?.revision ?? null,
                        school_id: kind === "department" ? school?.id ?? null : null,
                    },
                );
                if (!body.isConnected) return;
                await saved(result);
            }, save);
        });
        body.append(form, button(groupText(kind, "back_groups", `Back to ${category.plural.toLowerCase()}`), back));
    }

    async function termDefault(id, back) {
        const loading = openDrawer(reportingText('term_default', 'Reporting-group default'), reportingText('term_context', 'ACADEMIC TERM'));
        loading.append(node('p', { role: 'status' }, reportingText('loading_default', 'Loading term default…')));
        let current = await request(`/sis/terms/${id}/reporting-default/`);
        if (!loading.isConnected) return;
        let choice = current.reporting_group;
        let saved = false;
        function render() {
            const body = openDrawer(reportingText('term_default', 'Reporting-group default'), current.term.code);
            const changed = choice?.id !== current.reporting_group?.id;
            const card = node('section', { class: 'surface p-3 mb-3', 'aria-label': reportingText('term_default', 'Reporting-group default') },
                node('h3', { class: 'h5' }, reportingText('term_default', 'Reporting-group default')),
                node('p', { class: 'text-break' }, choice ? `${choice.code} — ${choice.title}` : reportingText('no_default', 'No term default')),
                node('p', { class: 'form-text' }, reportingText('default_help', 'This is a preset for section drafts. Staff must copy it into a section and save their choice before publication. Changing or clearing this default leaves existing sections and student history unchanged.')));
            if (choice && !choice.active) card.append(node('p', { role: 'status', class: 'alert alert-warning' },
                reportingText('default_retired', 'This reporting group is retired. Choose an active group before using the preset on another section.')));
            const controls = node('div', { class: 'd-flex flex-wrap gap-2' },
                button(reportingText('choose_group', 'Choose reporting group'), () => library('reporting_group', '', null, [],
                    row => { choice = row; saved = false; render(); }, render, reportingText('back_default', 'Back to term default'))));
            if (choice) controls.append(button(reportingText('clear_default', 'Clear term default'), () => { choice = null; saved = false; render(); }));
            card.append(controls);
            const save = button(reportingText('save_default', 'Save term default'), async () => {
                const result = await request(`/sis/terms/${id}/reporting-default/`, 'POST', {
                    reporting_group_id: choice?.id ?? null, revision: current.revision,
                });
                if (!body.isConnected) return;
                current = result; choice = result.reporting_group; saved = true; render();
            }, 'btn-primary');
            save.disabled = !changed;
            const returnButton = button(reportingText('back_record', 'Back to record'), back);
            if (saved) body.append(node('p', { role: 'status', class: 'alert alert-success' }, reportingText('default_saved', 'Term default saved. Existing section choices are unchanged.')));
            body.append(card, node('div', { class: 'd-flex flex-wrap gap-2' }, save, returnButton));
        }
        render();
    }

    async function assignment(target, id, saved = false) {
        const isSection = target === "section";
        const categoryKeys = isSection
            ? ["instructional_method", "campus", "reporting_group"]
            : ["department", "academic_level", "course_level"];
        const path = `/sis/${isSection ? "sections" : "courses"}/${id}/classifications/`;
        const loading = openDrawer(isSection ? reportingText("section_setup", "Section classifications") : "Course departments & levels", "DRAFT CLASSIFICATIONS");
        loading.append(node("p", { role: "status", class: "form-text" }, "Loading current classifications…"));
        const current = await request(path);
        if (!loading.isConnected) return;
        let termPreset = isSection && current.section.term_id
            ? await request(`/sis/terms/${current.section.term_id}/reporting-default/`) : null;
        if (!loading.isConnected) return;
        let copiedPreset = false;
        const choices = { ...current.classifications };
        function render() {
            const changed =
                JSON.stringify(choices) !==
                JSON.stringify(current.classifications);
            const body = openDrawer(
                isSection ? reportingText("section_setup", "Section classifications") : "Course departments & levels",
                isSection ? "SECTION SETUP" : "DRAFT CLASSIFICATIONS",
            );
            body.append(
                node(
                    "h3",
                    { class: "h5 text-break" },
                    isSection
                        ? current.section.code
                        : `${current.course.code} — ${current.course.title}`,
                ),
                node(
                    "p",
                    {},
                    isSection
                        ? "Save these optional draft choices, then publish the section to retain them. For a selected offering, new registrations pause until changed assignments are published and chosen for future registrations. Earlier student records stay unchanged."
                        : "Choose the classifications together, save the draft, then publish the course to retain them. Earlier publications and student records stay unchanged.",
                ),
            );
            if (saved && !changed)
                body.append(
                    node(
                        "p",
                        { role: "status", class: "alert alert-success" },
                        isSection
                            ? "Section setup saved."
                            : "Draft classifications saved.",
                    ),
                );
            const cards = node("div", { class: "row g-3 mb-3" });
            for (const kind of categoryKeys) {
                const category = kinds[kind],
                    value = choices[kind];
                const controls = node("div", { class: "d-flex flex-wrap gap-2 mt-auto" });
                const card = node(
                    "section",
                    {
                        class: "surface p-3 h-100 d-flex flex-column",
                        "aria-label": category.singular,
                    },
                    node("h3", { class: "h5 text-break" }, category.singular),
                    node("p", { class: "text-secondary flex-grow-1" }, category.detail),
                    node(
                        "p",
                        { class: "text-break" },
                        value
                            ? `${value.code} — ${value.title}`
                            : "Not specified",
                    ),
                    controls,
                );
                if (kind === 'reporting_group' && termPreset) {
                    const preset = termPreset.reporting_group;
                    const copy = button(reportingText('use_default', 'Copy term default'), async () => {
                        const fresh = await request(`/sis/terms/${current.section.term_id}/reporting-default/`);
                        if (!body.isConnected) return;
                        termPreset = fresh;
                        if (fresh.reporting_group?.active) {
                            const { id, code, title } = fresh.reporting_group;
                            choices.reporting_group = { id, code, title };
                            copiedPreset = true;
                        }
                        render();
                    });
                    copy.prepend(node('i', { class: 'bi bi-copy', 'aria-hidden': 'true' }));
                    copy.disabled = !preset?.active;
                    card.insertBefore(node('p', { class: 'form-text text-break' },
                        reportingText('term_default', 'Reporting-group default'), ': ',
                        preset ? `${preset.code} — ${preset.title}` : reportingText('no_default', 'No term default')), controls);
                    card.insertBefore(node('p', { class: 'form-text' }, reportingText('copy_help', 'Copying fills this draft only. Save section setup, then review publication. Later term-default changes do not update this choice.')), controls);
                    if (preset && !preset.active) card.insertBefore(node('p', { class: 'form-text' },
                        reportingText('default_retired', 'This reporting group is retired. Choose an active group before using the preset on another section.')), controls);
                    if (copiedPreset) card.insertBefore(node('p', { role: 'status', class: 'text-success' }, reportingText('default_copied', 'Term default copied into this draft.')), controls);
                    controls.append(copy);
                }
                controls.append(
                    button(
                        groupText(kind, "choose_group", `Choose ${category.singular.toLowerCase()}`),
                        () => library(
                            kind,
                            "",
                            null,
                            [],
                            (row) => {
                                if (kind === 'reporting_group') copiedPreset = false;
                                choices[kind] = {
                                    id: row.id,
                                    code: row.code,
                                    title: row.title,
                                };
                                render();
                            },
                            render,
                            isSection
                                ? "Back to section setup"
                                : "Back to course classifications",
                        ),
                    ),
                );
                if (value)
                    controls.append(
                        button(
                            groupText(kind, "clear_group", `Clear ${category.singular.toLowerCase()}`),
                            () => {
                                if (kind === 'reporting_group') copiedPreset = false;
                                choices[kind] = null;
                                render();
                            },
                        ),
                    );
                cards.append(
                    node(
                        "div",
                        {
                            class: "col-12 col-xl-4",
                        },
                        card,
                    ),
                );
            }
            const save = button(
                isSection ? "Save section setup" : "Save draft classifications",
                async () => {
                    const values = Object.fromEntries(
                        categoryKeys.map((kind) => [
                            `${kind}_id`,
                            choices[kind]?.id ?? null,
                        ]),
                    );
                    await request(path, "POST", {
                        ...values,
                        revision: current.revision,
                    });
                    if (!body.isConnected) return;
                    await assignment(target, id, true);
                },
                "btn-primary",
            );
            save.disabled = !changed;
            const publication = button(
                isSection ? "Review publication" : "Review course publication",
                () =>
                    isSection
                        ? ui.sectionPublications(id)
                        : ui.publications(id),
            );
            publication.disabled = changed;
            if (changed)
                body.append(
                    node(
                        "p",
                        { role: "status" },
                        "Save your draft choices before reviewing publication.",
                    ),
                );
            body.append(
                cards,
                node(
                    "div",
                    { class: "d-flex flex-wrap gap-2" },
                    save,
                    publication,
                ),
            );
        }
        render();
    }
    const course = (id, saved = false) => assignment("course", id, saved);
    const section = (id, saved = false) => assignment("section", id, saved);
    return { library, course, section, termDefault };
};
