/* Course choice groups are draft setup; publication and registration retain their accepted meanings. */
window.createWorkspacePrerequisites = function (ui) {
    "use strict";
    const { node, request, task, openDrawer } = ui;
    const button = (label, run, style = "btn-outline-primary", aria = label) => node("button", {
        type: "button", class: `btn ${style}`, "aria-label": aria,
        onclick: event => task(run, event.currentTarget),
    }, label);
    async function open(course) {
        const body = openDrawer("Course prerequisites", "ACADEMIC CATALOG");
        const saved = await request(`/sis/courses/${course}/prerequisite-groups/`);
        if (!body.isConnected) return;
        const state = { groups: saved.groups.map(group => [...group]),
            labels: new Map(saved.courses.map(row => [row.id, row])) };
        function render(notice = "") {
            const body = openDrawer("Course prerequisites", "EDIT DRAFT → REVIEW PUBLICATION");
            const cards = node("div"), status = node("p", { role: "status", class: "form-text" });
            const save = button("Save prerequisites", async () => {
                if (state.groups.some(group => !group.length)) return;
                const submitted = JSON.stringify(state.groups);
                const result = await request(`/sis/courses/${course}/prerequisite-groups/`, "POST", {
                    revision: saved.revision, groups: state.groups.map(group => [...group]),
                });
                const laterEdits = JSON.stringify(state.groups) !== submitted;
                Object.assign(saved, result);
                if (!laterEdits) state.groups = result.groups.map(group => [...group]);
                if (body.isConnected) render("Prerequisites saved.");
            }, "btn-primary");
            const publish = button("Review course publication", () =>
                ui.catalog().publications(course, null, [], render));
            const add = button("Add requirement", () => {
                if (state.groups.length >= 100) return;
                state.groups.push([]); draw();
                cards.lastElementChild?.querySelector("h3")?.focus();
            });
            function draw() {
                const total = state.groups.reduce((count, group) => count + group.length, 0);
                const incomplete = state.groups.some(group => !group.length);
                const changed = JSON.stringify(state.groups) !== JSON.stringify(saved.groups);
                save.disabled = !changed || incomplete;
                publish.disabled = changed || incomplete;
                add.disabled = state.groups.length >= 100 || total >= 100;
                status.textContent = incomplete ? "Add a course to every requirement, or remove the empty requirement."
                    : changed ? "Save your choices before reviewing publication." : notice || "Saved choices are ready for publication review.";
                cards.replaceChildren(...state.groups.map((group, index) => {
                    const heading = node("h3", { class: "h5 mb-1", tabindex: "-1" }, `Requirement ${index + 1}`);
                    const list = node("ul", { class: "list-unstyled mb-3" }, ...group.map(id => {
                        const label = state.labels.get(id), text = label ? `${label.code} · ${label.title}` : "Course label unavailable";
                        return node("li", { class: "d-flex flex-wrap align-items-center justify-content-between gap-2 border-bottom py-2" },
                            node("span", { class: "text-break" }, text),
                            button("Remove", () => { group.splice(group.indexOf(id), 1); draw(); }, "btn-outline-secondary btn-sm",
                                `Remove ${label?.code || "course"} from requirement ${index + 1}`));
                    }));
                    return node("section", { class: "surface border p-3 mb-3", "aria-label": `Requirement ${index + 1}` }, heading,
                        node("p", { class: "form-text" }, group.length > 1 ? "Complete any one of these courses." : "Complete this course."),
                        list, node("div", { class: "inline-actions" },
                            button("Add course", () => choose(index), "btn-outline-primary", `Add course to requirement ${index + 1}`),
                            button("Remove requirement", () => { state.groups.splice(index, 1); draw(); }, "btn-outline-danger btn-sm",
                                `Remove requirement ${index + 1}`)));
                }));
                if (!state.groups.length) cards.append(node("p", { class: "alert alert-info" }, "No prerequisite courses are required."));
            }
            body.append(node("h3", { class: "h5" }, `${saved.course.code} · ${saved.course.title}`),
                node("p", {}, "Complete one course from every requirement. Add alternatives within a requirement; add another requirement when both are needed."),
                node("p", { class: "form-text" }, "For example: MATH.101 or MATH.110, plus ENG.101. Only verified passing coursework allowed for the student's program pursuit can satisfy these choices. " +
                    "Draft edits leave earlier publications and student records unchanged."), cards, status,
                node("div", { class: "inline-actions" }, add, save, publish, button("Reload saved choices", () => open(course), "btn-link")));
            draw();
            async function choose(index, search = "", after = null, stack = []) {
                const picker = openDrawer("Choose a prerequisite course", "COURSE CHOICES");
                const data = await request("/sis/ui/data/courses/", "POST", { search, ...(after ? { after } : {}) });
                if (!picker.isConnected) return;
                const input = node("input", { type: "search", id: "prerequisite-course-search", class: "form-control",
                    maxlength: "80", value: search });
                const form = node("form", { class: "mb-3" }, node("label", { for: input.id, class: "form-label" }, "Find a course"), input,
                    node("button", { type: "submit", class: "btn btn-primary mt-2" }, "Search courses"));
                form.addEventListener("submit", event => { event.preventDefault(); task(() => choose(index, input.value)); });
                const used = new Set(state.groups.flat()), options = data.items.filter(row => row.id !== course && row.active && !used.has(row.id));
                picker.append(form, node("p", { class: "form-text" }, "Each course can appear once. Your unsaved choices are kept while searching."));
                if (!options.length) picker.append(node("p", {}, "No available courses on this page. Try another search or page."));
                for (const row of options) picker.append(node("article", { class: "surface p-3 mb-2" },
                    node("h3", { class: "h6 text-break" }, `${row.code} · ${row.title}`),
                    button(`Choose ${row.code}`, () => {
                        if (state.groups.flat().length >= 100) throw new Error("Use up to 100 prerequisite courses.");
                        state.labels.set(row.id, row); state.groups[index].push(row.id); render();
                    })));
                picker.append(node("div", { class: "inline-actions" },
                    ...(stack.length ? [button("Previous courses", () => choose(index, search, stack.at(-1), stack.slice(0, -1)))] : []),
                    ...(data.next_after ? [button("More courses", () => choose(index, search, data.next_after, [...stack, after]))] : []),
                    button("Back to prerequisites", render, "btn-link")));
            }
        }
        render();
    }
    function describe(definition) {
        const groups = definition.prerequisite_groups;
        if (!groups.length) return node("span", {}, "None");
        const view = node("details", {}, node("summary", {}, `Complete one course from each of ${groups.length} requirements`));
        let loading = false, loaded = false;
        view.addEventListener("toggle", () => {
            if (!view.open || loading || loaded) return;
            loading = true;
            task(async () => {
                const data = await request("/sis/prerequisite-labels/", "POST", { ids: definition.prerequisites });
                if (!view.isConnected) return;
                const labels = new Map(data.items.map(row => [row.id, `${row.code} · ${row.title}`]));
                view.append(node("p", { class: "form-text" }, "Current catalog labels; this publication retains the course identities and choice groups."),
                    node("ol", {}, ...groups.map(group => node("li", {}, group.map(id => labels.get(id) || "Course label unavailable").join(" OR ")))));
                loaded = true;
            }).finally(() => { loading = false; });
        });
        return view;
    }
    return { open, describe };
};
