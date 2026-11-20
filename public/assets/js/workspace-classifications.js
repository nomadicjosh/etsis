/* Reference setup uses bounded searches; unsaved course choices stay in page memory. */
window.createWorkspaceClassifications = function (ui) {
    "use strict";
    const { node, request, task, buildForm, formData, openDrawer } = ui;
    const kinds = {
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
            detail: "The academic unit responsible for a course.",
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
        const sectionKind = ["instructional_method", "campus"].includes(kind);
        const extendedProgramKind = ["school", "field_of_study"].includes(kind);
        const visibleKinds = sectionKind ? ["instructional_method", "campus"] : extendedProgramKind
            ? ["school", "field_of_study"] : ["department", "academic_level", "course_level", "award"];
        const body = openDrawer(
            choose
                ? `Choose ${category.singular.toLowerCase()}`
                : sectionKind
                  ? category.plural
                  : extendedProgramKind ? "Schools & fields" : kind === "award"
                    ? category.plural
                    : "Departments & levels",
            "ACADEMIC CATALOG",
        );
        const data = await request(
            `/sis/catalog/classifications/${kind}/page/`,
            "POST",
            {
                search,
                ...(after ? { after } : {}),
            },
        );
        if (!body.isConnected) return;
        if (!choose) {
            const navigation = node("div", {
                class: "d-flex flex-wrap gap-2 mb-3",
                role: "group",
                "aria-label": "Classification category",
            });
            Object.entries(kinds)
                .filter(
                    ([key]) => visibleKinds.includes(key),
                )
                .forEach(([key, value]) => {
                    const tab = button(
                        value.plural,
                        () => library(key),
                        key === kind ? "btn-primary" : "btn-outline-primary",
                    );
                    tab.setAttribute(
                        "aria-pressed",
                        key === kind ? "true" : "false",
                    );
                    navigation.append(tab);
                });
            body.append(navigation);
        }
        body.append(
            node("h3", { class: "h5 text-break" }, category.plural),
            node("p", {}, category.detail),
            node(
                "p",
                { class: "text-secondary" },
                "Retire a definition to stop new assignments. Earlier published academic definitions and student records keep their original labels.",
            ),
        );
        const form = node("form", { class: "mb-3" });
        const input = node("input", {
            id: "classification-search",
            type: "search",
            class: "form-control",
            maxlength: 100,
            value: search,
        });
        form.append(
            node(
                "label",
                { for: input.id, class: "form-label" },
                `Find ${category.plural.toLowerCase()}`,
            ),
            input,
            node(
                "button",
                { type: "submit", class: "btn btn-primary mt-2" },
                "Search classifications",
            ),
        );
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
            button(`Add ${category.singular.toLowerCase()}`, () =>
                editor(kind, null, choose || refresh, refresh),
            ),
        );
        if (!data.items.length)
            body.append(
                node(
                    "p",
                    { role: "status" },
                    "No matching classifications. Add one or try another search.",
                ),
            );
        data.items.forEach((row) => {
            const card = node(
                "div",
                { class: "surface p-3 mb-2" },
                node("h4", { class: "h5 text-break" }, `${row.code} — ${row.title}`),
                node("p", {}, row.active ? "Active" : "Retired"),
                button(`Edit ${row.code}`, () =>
                    editor(kind, row, refresh, refresh),
                ),
            );
            if (choose && row.active)
                card.append(
                    button(
                        `Choose ${row.code}`,
                        () => choose(row),
                        "btn-primary",
                    ),
                );
            body.append(card);
        });
        if (stack.length)
            body.append(
                button("Previous classifications", () =>
                    library(
                        kind,
                        search,
                        stack.at(-1),
                        stack.slice(0, -1),
                        choose,
                        back,
                        backLabel,
                    ),
                ),
            );
        if (data.next_after)
            body.append(
                button("More classifications", () =>
                    library(
                        kind,
                        search,
                        data.next_after,
                        [...stack, after],
                        choose,
                        back,
                        backLabel,
                    ),
                ),
            );
        if (back) body.append(button(backLabel, back));
    }

    async function editor(kind, row, saved, back) {
        const category = kinds[kind];
        const body = openDrawer(
            `${row ? "Edit" : "Add"} ${category.singular.toLowerCase()}`,
            "REFERENCE SETUP",
        );
        const fields = [
            ["code", `${category.singular} code`],
            ["title", `${category.singular} name`],
            ["active", "Available for new assignments", "checkbox"],
        ];
        const form = await buildForm(fields, row || { active: true });
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
                    },
                );
                await saved(result);
            }, save);
        });
        body.append(form, button("Back to classifications", back));
    }

    async function assignment(target, id, saved = false) {
        const isSection = target === "section";
        const categoryKeys = isSection
            ? ["instructional_method", "campus"]
            : ["department", "academic_level", "course_level"];
        const path = `/sis/${isSection ? "sections" : "courses"}/${id}/classifications/`;
        const current = await request(path);
        const choices = { ...current.classifications };
        function render() {
            const changed =
                JSON.stringify(choices) !==
                JSON.stringify(current.classifications);
            const body = openDrawer(
                isSection ? "Method & campus" : "Course departments & levels",
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
                const card = node(
                    "section",
                    {
                        class: "surface p-3 h-100",
                        "aria-label": category.singular,
                    },
                    node("h3", { class: "h5 text-break" }, category.singular),
                    node("p", { class: "text-secondary" }, category.detail),
                    node(
                        "p",
                        { class: "text-break" },
                        value
                            ? `${value.code} — ${value.title}`
                            : "Not specified",
                    ),
                    button(`Choose ${category.singular.toLowerCase()}`, () =>
                        library(
                            kind,
                            "",
                            null,
                            [],
                            (row) => {
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
                    card.append(
                        button(
                            `Clear ${category.singular.toLowerCase()}`,
                            () => {
                                choices[kind] = null;
                                render();
                            },
                        ),
                    );
                cards.append(
                    node(
                        "div",
                        {
                            class: isSection
                                ? "col-12 col-xl-6"
                                : "col-12 col-xl-4",
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
    return { library, course, section };
};
