/* Reference setup uses bounded searches; unsaved course choices stay in page memory. */
window.createWorkspaceClassifications = function (ui) {
    "use strict";
    const { node, request, task, buildForm, formData, openDrawer } = ui;
    const kinds = {
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
        const data = await request(`/sis/catalog/classifications/${kind}/page/`, "POST", {
            search,
            ...(after ? { after } : {}),
        });
        const body = openDrawer(
            choose
                ? `Choose ${category.singular.toLowerCase()}`
                : kind === "award"
                  ? "Degrees & awards"
                  : "Departments & levels",
            "ACADEMIC CATALOG",
        );
        if (!choose) {
            const navigation = node("div", {
                class: "d-flex flex-wrap gap-2 mb-3",
                role: "group",
                "aria-label": "Classification category",
            });
            Object.entries(kinds).forEach(([key, value]) => {
                const tab = button(
                    value.plural,
                    () => library(key),
                    key === kind ? "btn-primary" : "btn-outline-primary",
                );
                tab.setAttribute("aria-pressed", key === kind ? "true" : "false");
                navigation.append(tab);
            });
            body.append(navigation);
        }
        body.append(
            node("h3", { class: "h5" }, category.plural),
            node("p", {}, category.detail),
            node(
                "p",
                { class: "text-secondary" },
                "Retire a definition to stop new assignments. Earlier published course and student records keep their original labels.",
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
            node("label", { for: input.id, class: "form-label" }, `Find ${category.plural.toLowerCase()}`),
            input,
            node("button", { type: "submit", class: "btn btn-primary mt-2" }, "Search classifications"),
        );
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(() => library(kind, input.value, null, [], choose, back, backLabel));
        });
        const refresh = () => library(kind, search, after, stack, choose, back, backLabel);
        body.append(
            form,
            button(`Add ${category.singular.toLowerCase()}`, () =>
                editor(kind, null, choose || refresh, refresh),
            ),
        );
        if (!data.items.length)
            body.append(
                node("p", { role: "status" }, "No matching classifications. Add one or try another search."),
            );
        data.items.forEach((row) => {
            const card = node(
                "div",
                { class: "surface p-3 mb-2" },
                node("h4", { class: "h5" }, `${row.code} — ${row.title}`),
                node("p", {}, row.active ? "Active" : "Retired"),
                button(`Edit ${row.code}`, () => editor(kind, row, refresh, refresh)),
            );
            if (choose && row.active)
                card.append(button(`Choose ${row.code}`, () => choose(row), "btn-primary"));
            body.append(card);
        });
        if (stack.length)
            body.append(
                button("Previous classifications", () =>
                    library(kind, search, stack.at(-1), stack.slice(0, -1), choose, back, backLabel),
                ),
            );
        if (data.next_after)
            body.append(
                button("More classifications", () =>
                    library(kind, search, data.next_after, [...stack, after], choose, back, backLabel),
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
        const save = node("button", { type: "submit", class: "btn btn-primary" }, "Save classification");
        form.append(save);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const result = await request(`/sis/catalog/classifications/${kind}/save/`, "POST", {
                    ...formData(form, fields),
                    id: row?.id ?? null,
                    revision: row?.revision ?? null,
                });
                await saved(result);
            }, save);
        });
        body.append(form, button("Back to classifications", back));
    }

    async function course(id, saved = false) {
        const current = await request(`/sis/courses/${id}/classifications/`);
        const choices = { ...current.classifications };
        function render() {
            const changed = JSON.stringify(choices) !== JSON.stringify(current.classifications);
            const body = openDrawer("Course departments & levels", "DRAFT CLASSIFICATIONS");
            body.append(
                node("p", { class: "h5" }, `${current.course.code} — ${current.course.title}`),
                node(
                    "p",
                    {},
                    "Choose the classifications together, save the draft, then publish the course to retain them. Earlier publications and student records stay unchanged.",
                ),
            );
            if (saved && !changed)
                body.append(
                    node(
                        "p",
                        { role: "status", class: "alert alert-success" },
                        "Draft classifications saved.",
                    ),
                );
            const cards = node("div", { class: "row g-3 mb-3" });
            ["department", "academic_level", "course_level"].forEach((kind) => {
                const category = kinds[kind];
                const value = choices[kind];
                const card = node(
                    "section",
                    { class: "surface p-3 h-100", "aria-label": category.singular },
                    node("h3", { class: "h5" }, category.singular),
                    node("p", { class: "text-secondary" }, category.detail),
                    node("p", {}, value ? `${value.code} — ${value.title}` : "Not specified"),
                    button(`Choose ${category.singular.toLowerCase()}`, () =>
                        library(
                            kind,
                            "",
                            null,
                            [],
                            (row) => {
                                choices[kind] = { id: row.id, code: row.code, title: row.title };
                                render();
                            },
                            render,
                        ),
                    ),
                );
                if (value)
                    card.append(
                        button(`Clear ${category.singular.toLowerCase()}`, () => {
                            choices[kind] = null;
                            render();
                        }),
                    );
                cards.append(node("div", { class: "col-12 col-xl-4" }, card));
            });
            if (changed)
                body.append(
                    node("p", { role: "status" }, "Save your draft choices before reviewing publication."),
                );
            const save = button(
                "Save draft classifications",
                async () => {
                    await request(`/sis/courses/${id}/classifications/`, "POST", {
                        department_id: choices.department?.id ?? null,
                        academic_level_id: choices.academic_level?.id ?? null,
                        course_level_id: choices.course_level?.id ?? null,
                        revision: current.revision,
                    });
                    await course(id, true);
                },
                "btn-primary",
            );
            save.disabled = !changed;
            const publication = button("Review course publication", () => ui.publications(id));
            publication.disabled = changed;
            body.append(cards, save, publication);
        }
        render();
    }
    return { library, course };
};
