/* Course choice groups are draft setup; publication and registration retain their accepted meanings. */
window.createWorkspacePrerequisites = function (ui) {
    "use strict";
    const { node, request, task, openDrawer } = ui;
    const originDefaults = {
        equivalents_title: 'Equivalent courses', equivalents_for: 'Equivalent courses for',
        equivalents_help: 'Choose courses that may replace this named prerequisite in this course publication only. These choices are one-way and do not chain through other equivalents. The same pursuit, coursework source, grade minimum and completion dates still apply. Co-requisites, earned credit and degree requirements are unchanged.',
        equivalents_counting: 'Within one requirement, one completed course can fill only one named course slot. The same course may still satisfy separate requirements.',
        add_equivalent: 'Add equivalent course', no_equivalents: 'No equivalent courses selected.',
        remove_equivalent: 'Remove equivalent', choose_equivalent: 'Choose an equivalent course',
        completion_from: 'Completed from', completion_through: 'Completed through',
        completion_window: 'Actual completion dates',
        completion_help: 'Optional inclusive dates for this requirement. Leave dates and the age limit blank for no date restriction. Unknown completion dates cannot meet a dated requirement; term and grade-recording dates are not substitutes.',
        max_age_months: 'Maximum age before section starts (months)',
        recency_help: 'Optional whole months from 1 through 1200; 60 means five years. Completion must fall within that many calendar months ending on the published section start, inclusive. Only coursework eligible for this pursuit is considered. Earned credit and degree requirements are unchanged.',
        invalid_completion_policy: 'Check the completion dates and optional whole-month age limit.',
        invalid_completion_window: 'Check the completion dates; from must not be after through.',

        field: 'Qualifying prerequisite coursework', either: 'Institutional or transfer',
        institutional: 'Institutional work only', transfer: 'Transfer work only',
        help: 'Default for requirements without a coursework-source override. Co-requisites use their separate policy.',
        requirement_field: 'Coursework source for this requirement', inherit: 'Use course default',
        effective: 'Effective coursework source',
        requirement_help: 'Override the default for this requirement only. Each counted course must meet this source and grade minimum together.',
        scope: 'This rule does not change earned credits or decide which work counts toward a degree.',
    };
    const originText = key => ui.prerequisiteOriginLabels?.[key] || originDefaults[key];

    const button = (label, run, style = "btn-outline-primary", aria = label) => {
        const icon = label === originText('equivalents_title') ? 'arrow-left-right'
            : label === originText('add_equivalent') ? 'plus-lg'
            : label === originText('remove_equivalent') ? 'trash3' : null;
        return node("button", {
            type: "button", class: `btn ${style}${icon ? ' workspace-icon-control' : ''}`, "aria-label": aria,
            onclick: event => task(run, event.currentTarget),
        }, ...(icon ? [node('i', { class: `bi bi-${icon}`, 'aria-hidden': 'true' }), label] : [label]));
    };
    const pointText = value => String(value / 1000);
    function pointValue(value) {
        if (value === "") return null;
        if (!/^(?:\d+|\d*\.\d{1,3})$/.test(value)) return NaN;
        const [whole, fraction = ""] = value.split(".");
        return Number(whole || "0") * 1000 + Number(fraction.padEnd(3, "0"));
    }
    const floors = value => value.grade_points_milli || value.groups.map(() => null);
    const windows = value => (value.completion_windows || value.groups.map(() => null)).map(window => window === null
        ? null : {from: window.from, ...(window.max_age_months == null ? {} : {max_age_months: window.max_age_months}), through: window.through});
    const equivalentMap = value => Object.fromEntries(Object.entries(value.equivalents || {})
        .filter(([, ids]) => ids.length).sort(([a], [b]) => a.localeCompare(b)).map(([id, ids]) => [id, [...ids].sort()]));
    const origins = value => value.requirement_credit_origins || value.groups.map(() => null);
    const ruleSize = rule => typeof rule === "number" ? 1 :
        1 + rule.children.reduce((total, child) => total + ruleSize(child), 0);
    const branchText = rule => rule.minimum === rule.children.length ? "Complete all" :
        rule.minimum === 1 ? "Complete any one" : `Complete at least ${rule.minimum}`;
    function ruleView(rule, label = index => `Requirement ${index + 1}`) {
        return node("div", { class: "border-start ps-2 mb-2 text-break" },
            node("strong", {}, branchText(rule)), node("ul", { class: "ps-3 mb-1" },
                ...rule.children.map(child => node("li", {}, typeof child === "number"
                    ? label(child) : ruleView(child, label)))));
    }
    function arrangeRule(rule, back, describe) {
        const selected = new Set();
        let selectedParent = null;
        function render(message = "") {
            const body = openDrawer("Arrange prerequisite rule", "EDIT DRAFT");
            const status = node("p", { role: "status", class: "form-text" }, message);
            let serial = 0;
            const normalize = (branch, previousCount) => {
                branch.minimum = branch.minimum === previousCount ? branch.children.length :
                    Math.min(branch.minimum, branch.children.length);
            };
            const draw = (branch, depth = 1, parent = null) => {
                const index = ++serial, id = `prerequisite-branch-${index}`;
                const mode = node("select", { id, class: "form-select" },
                    ...["all", "any", "count"].map(value => node("option", { value },
                        { all: "Complete all", any: "Complete any one", count: "Complete a chosen number" }[value])));
                mode.value = branch.minimum === branch.children.length ? "all" : branch.minimum === 1 ? "any" : "count";
                const count = node("input", { id: `${id}-count`, type: "number", class: "form-control",
                    min: "1", max: String(branch.children.length), step: "1", value: branch.minimum });
                const countField = node("div", { class: "my-2", hidden: mode.value !== "count" },
                    node("label", { for: count.id, class: "form-label" }, "Branches needed"), count);
                mode.addEventListener("change", () => {
                    branch.minimum = mode.value === "all" ? branch.children.length : 1;
                    count.setCustomValidity("");
                    count.value = String(branch.minimum); countField.hidden = mode.value !== "count";
                });
                count.addEventListener("input", () => {
                    count.setCustomValidity("");
                    if (!count.checkValidity() || count.value === "") {
                        count.setCustomValidity("Enter a count from one through the number of branches.");
                    } else branch.minimum = Number(count.value);
                });
                const section = node("section", { class: "rule-branch", "aria-label": `Rule branch ${index}` },
                    node("label", { for: id, class: "form-label fw-semibold" }, parent ? `Branch ${index}` : "Overall rule"), mode, countField);
                for (const [position, child] of branch.children.entries()) {
                    const name = typeof child === "number" ? `Requirement ${child + 1}` : `Nested branch ${index}.${position + 1}`;
                    const check = node("input", { type: "checkbox", class: "form-check-input", id: `${id}-select-${position}`,
                        ...(typeof child === "number" ? { "aria-describedby": `${id}-details-${position}` } : {}),
                        checked: selectedParent === branch && selected.has(child) });
                    check.addEventListener("change", () => {
                        if (check.checked && selected.size && selectedParent !== branch) {
                            check.checked = false; status.textContent = "Select items within the same branch to group them."; return;
                        }
                        selectedParent = branch;
                        if (check.checked) selected.add(child); else selected.delete(child);
                    });
                    const move = direction => {
                        if (![...body.querySelectorAll("input[type=number]")].every(input => input.reportValidity())) return;
                        const to = position + direction;
                        if (to < 0 || to >= branch.children.length) return;
                        [branch.children[position], branch.children[to]] = [branch.children[to], branch.children[position]];
                        render(`${name} moved.`);
                    };
                    const up = button("↑", () => move(-1), "btn-outline-secondary btn-sm", `Move ${name} up`);
                    const down = button("↓", () => move(1), "btn-outline-secondary btn-sm", `Move ${name} down`);
                    up.replaceChildren(node("i", { class: "bi bi-arrow-up", "aria-hidden": "true" }));
                    down.replaceChildren(node("i", { class: "bi bi-arrow-down", "aria-hidden": "true" }));
                    up.disabled = position === 0; down.disabled = position === branch.children.length - 1;
                    section.append(node("div", { class: "border-top pt-2 mt-2" },
                        node("div", { class: "d-flex flex-wrap align-items-center gap-2 mb-2" }, check,
                            node("label", { for: check.id, class: "form-check-label flex-grow-1" }, name), up, down),
                        typeof child === "number" ? node("p", { id: `${id}-details-${position}`, class: "form-text text-break" },
                            describe(child)) : draw(child, depth + 1, branch)));
                }
                const group = button("Group selected", () => {
                    if (![...body.querySelectorAll("input[type=number]")].every(input => input.reportValidity())) return;
                    if (selectedParent !== branch || selected.size < 2) {
                        status.textContent = "Select at least two items within this branch."; return;
                    }
                    const children = branch.children.filter(child => selected.has(child));
                    const first = branch.children.findIndex(child => selected.has(child));
                    const previousCount = branch.children.length;
                    const proposed = { minimum: children.length, children };
                    const height = child => typeof child === "number" ? 0 : 1 + Math.max(...child.children.map(height));
                    if (depth + height(proposed) > 5 || ruleSize(rule) >= 199) {
                        status.textContent = "Use no more than five rule levels and 199 rule items."; return;
                    }
                    branch.children = branch.children.filter(child => !selected.has(child));
                    branch.children.splice(first, 0, proposed); normalize(branch, previousCount);
                    selected.clear(); render("Items grouped. Choose whether all or some items are needed in the new branch.");
                });
                section.append(group);
                if (parent) section.append(button("Ungroup", () => {
                    if (![...body.querySelectorAll("input[type=number]")].every(input => input.reportValidity())) return;
                    const position = parent.children.indexOf(branch), previousCount = parent.children.length;
                    parent.children.splice(position, 1, ...branch.children); normalize(parent, previousCount);
                    selected.clear(); render("Branch ungrouped. Review the overall rule before saving.");
                }, "btn-link", `Ungroup branch ${index}`));
                return section;
            };
            body.append(node("p", {}, "Select neighboring requirements and group them to build routes such as A and either B or C. Each requirement appears once. Course counts and grade minima stay in the requirement cards."),
                draw(rule), status, button("Back to requirements", () => {
                    const invalid = [...body.querySelectorAll("input[type=number]")].find(input => !input.checkValidity() || input.value === "");
                    if (invalid) { invalid.reportValidity(); return; }
                    back("Nested rule updated in the draft. Save before reviewing publication.");
                }, "btn-primary"));
        }
        render();
    }
    async function open(course) {
        const body = openDrawer("Course prerequisites", "ACADEMIC CATALOG");
        const saved = await request(`/sis/courses/${course}/prerequisite-groups/`);
        if (!body.isConnected) return;
        const state = { equivalents: equivalentMap(saved), completion_windows: windows(saved), requirement_credit_origins: [...origins(saved)], credit_origin: saved.credit_origin ?? null, rule: saved.rule ? structuredClone(saved.rule) : null, minimum_requirements: saved.minimum_requirements ?? null, groups: saved.groups.map(group => [...group]), minimums: [...saved.minimums], grade_points_milli: [...floors(saved)], corequisites: [...(saved.corequisites || [])],
            labels: new Map(saved.courses.map(row => [row.id, row])) };
        const fingerprint = value => JSON.stringify([equivalentMap(value), windows(value), origins(value), value.credit_origin ?? null, value.rule ?? null, value.minimum_requirements ?? null, value.groups, value.minimums, floors(value), [...(value.corequisites || [])].sort()]);
        const describeRequirement = index => `Requirement ${index + 1} · ${state.minimums[index]} of: ` +
            state.groups[index].map(id => state.labels.get(id)?.code || "Course label unavailable").join(", ");
        const effectiveOrigin = index => originText(state.requirement_credit_origins[index] ?? state.credit_origin ?? 'either');
        let dateControls = [];
        const invalidWindow = () => dateControls.some(control => control.validity?.valid === false) ||
            state.completion_windows.some(window => window?.from && window?.through && window.from > window.through);
        const incomplete = () => invalidWindow() || (state.minimum_requirements !== null &&
            (!Number.isInteger(state.minimum_requirements) || state.minimum_requirements < 1 ||
                state.minimum_requirements > state.groups.length)) || state.groups.some((group, index) => !group.length ||
            !Number.isInteger(state.minimums[index]) || state.minimums[index] < 1 || state.minimums[index] > group.length ||
            (state.grade_points_milli[index] !== null && (!Number.isInteger(state.grade_points_milli[index]) ||
                state.grade_points_milli[index] < 0 || state.grade_points_milli[index] > 10000)));
        function render(notice = "") {
            const body = openDrawer("Course prerequisites", "EDIT DRAFT → REVIEW PUBLICATION");
            const corequisiteCards = node("div"), rulePreview = node("div");
            const origin = node('select', { id: 'prerequisite-credit-origin', class: 'form-select',
                'aria-describedby': 'prerequisite-credit-origin-help' },
                ...['either', 'institutional', 'transfer'].map(value => node('option', {
                    value, selected: value === (state.credit_origin || 'either'),
                }, originText(value))));
            origin.addEventListener('change', () => {
                state.credit_origin = origin.value === 'either' ? null : origin.value;
                update();
            });

            const cards = node("div"), status = node("p", { role: "status", class: "form-text" });
            const sourcePreviews = [];
            const save = button("Save requirements", async () => {
                if (incomplete()) return;
                const submitted = fingerprint(state);
                const result = await request(`/sis/courses/${course}/prerequisite-groups/`, "POST", {
                    equivalents: Object.keys(equivalentMap(state)).length ? equivalentMap(state) : null,
                    completion_windows: state.completion_windows.some(Boolean) ? structuredClone(state.completion_windows) : null,
                    credit_origin: state.groups.length ? state.credit_origin : null,
                    requirement_credit_origins: state.requirement_credit_origins.some(value => value !== null)
                        ? [...state.requirement_credit_origins] : null,
                    rule: state.rule ? structuredClone(state.rule) : null, minimum_requirements: state.minimum_requirements, revision: saved.revision, groups: state.groups.map(group => [...group]), minimums: [...state.minimums],
                    grade_points_milli: [...state.grade_points_milli], corequisites: [...state.corequisites].sort(),
                });
                const laterEdits = fingerprint(state) !== submitted;
                Object.assign(saved, result);
                if (!laterEdits) { state.equivalents = equivalentMap(result); state.completion_windows = windows(result); state.requirement_credit_origins = [...origins(result)]; state.credit_origin = result.credit_origin ?? null; state.rule = result.rule ? structuredClone(result.rule) : null; state.minimum_requirements = result.minimum_requirements ?? null; state.groups = result.groups.map(group => [...group]); state.minimums = [...result.minimums]; state.grade_points_milli = [...floors(result)]; state.corequisites = [...(result.corequisites || [])]; }
                if (body.isConnected) render("Entry requirements saved.");
            }, "btn-primary");
            const publish = button("Review course publication", () =>
                ui.catalog().publications(course, null, [], render));
            const add = button("Add requirement", () => {
                if (state.groups.length >= 100) return;
                if (state.rule) {
                    const all = state.rule.minimum === state.rule.children.length;
                    state.rule.children.push(state.groups.length);
                    if (all) state.rule.minimum = state.rule.children.length;
                }
                state.groups.push([]); state.minimums.push(1); state.grade_points_milli.push(null);
                state.completion_windows.push(null); state.requirement_credit_origins.push(null); draw();
                cards.lastElementChild?.querySelector("h3")?.focus();
            });
            const alternative = node("input", { type: "checkbox", id: "prerequisite-alternatives", class: "form-check-input",
                checked: state.minimum_requirements !== null });
            const needed = node("input", { type: "number", id: "prerequisite-required-groups", class: "form-control",
                min: "1", step: "1", value: state.minimum_requirements ?? 1,
                "aria-describedby": "prerequisite-overall-help" });
            const overallText = node("p", { id: "prerequisite-overall-help", class: "form-text", role: "status" });
            const neededField = node("div", { class: "mb-3", style: "max-width: 15rem" },
                node("label", { for: needed.id, class: "form-label" }, "Requirements needed"), needed);
            alternative.addEventListener("change", () => {
                state.minimum_requirements = alternative.checked ? 1 : null;
                needed.value = String(state.minimum_requirements ?? 1); update();
            });
            needed.addEventListener("input", () => {
                state.minimum_requirements = needed.value === "" ? NaN : Number(needed.value); update();
            });
            function update() {
                sourcePreviews.forEach(({ element, index }) => {
                    element.textContent = `${originText('effective')}: ${effectiveOrigin(index)}`;
                });
                alternative.disabled = !state.groups.length || !!state.rule;
                alternative.checked = state.minimum_requirements !== null;
                neededField.hidden = state.minimum_requirements === null;
                needed.max = String(state.groups.length);
                overallText.textContent = state.rule ? "Follow the nested rule below. Each numbered requirement keeps its course count and grade minimum; co-requisites stay separate." : state.minimum_requirements === null
                    ? "Meet every prerequisite requirement below. Co-requisites are always checked separately."
                    : `Meet at least ${Number.isInteger(state.minimum_requirements) ? state.minimum_requirements : "the chosen number"} of ${state.groups.length} requirements. Each requirement keeps its own course count and grade minimum. Co-requisites remain required.`;
                const total = state.groups.reduce((count, group) => count + group.length, 0);
                const changed = fingerprint(state) !== fingerprint(saved);
                save.disabled = !changed || incomplete();
                publish.disabled = changed || incomplete();
                add.disabled = state.groups.length >= 100 || total >= 500 || (state.rule && ruleSize(state.rule) >= 199);
                status.textContent = invalidWindow() ? originText('invalid_completion_policy') : incomplete() ? "Check the overall requirement count, each course count and optional grade-point minimum before saving."
                    : changed ? "Save your choices before reviewing publication." : notice || "Saved choices are ready for publication review.";
            }
            function removeRequirement(index) {
                if (state.rule) throw new Error("Use Simple rule before removing a requirement from a nested rule.");
                state.groups.splice(index, 1); state.minimums.splice(index, 1); state.grade_points_milli.splice(index, 1);
                state.completion_windows.splice(index, 1); state.requirement_credit_origins.splice(index, 1);
                if (!state.groups.length) state.minimum_requirements = null;
                draw();
            }
            function draw() {
                dateControls = [];
                sourcePreviews.length = 0;
                origin.disabled = state.groups.length === 0;
                if (!state.groups.length) { state.credit_origin = null; origin.value = 'either'; }

                state.equivalents = Object.fromEntries(Object.entries(state.equivalents)
                    .filter(([id]) => state.groups.flat().includes(id)));
                update();
                rulePreview.replaceChildren(...(state.rule ? [ruleView(state.rule, describeRequirement)] : []));
                cards.replaceChildren(...state.groups.map((group, index) => {
                    const heading = node("h3", { class: "h5 mb-1", tabindex: "-1" }, `Requirement ${index + 1}`);
                    const list = node("ul", { class: "list-unstyled mb-3" }, ...group.map(id => {
                        const label = state.labels.get(id), text = label ? `${label.code} · ${label.title}` : "Course label unavailable";
                        return node("li", { class: "d-flex flex-wrap align-items-center justify-content-between gap-2 border-bottom py-2" },
                            node("span", { class: "text-break" }, text),
                            node('div', { class: 'inline-actions' },
                            button(originText('equivalents_title'), () => showEquivalents(id), 'btn-outline-primary btn-sm',
                                `${originText('equivalents_for')} ${label?.code || id}`),
                            button("Remove", () => { group.splice(group.indexOf(id), 1); draw(); }, "btn-outline-secondary btn-sm",
                                `Remove ${label?.code || "course"} from requirement ${index + 1}`)));
                    }));
                    const description = node("p", { class: "form-text" }, "");
                    const describe = () => { description.textContent = state.minimums[index] > 1
                        ? `Complete at least ${state.minimums[index]} of these ${group.length} distinct courses.`
                        : group.length > 1 ? "Complete any one of these courses." : "Complete this course."; };
                    describe();
                    const count = node("input", { type: "number", id: `prerequisite-minimum-${index}`, class: "form-control",
                        min: "1", max: String(Math.max(1, group.length)), step: "1", value: state.minimums[index], required: true });
                    count.addEventListener("input", () => {
                        state.minimums[index] = count.value === "" ? null : Number(count.value);
                        describe(); update();
                    });
                    const floor = node("input", { type: "number", id: `prerequisite-grade-${index}`, class: "form-control",
                        min: "0", max: "10", step: "0.001", value: state.grade_points_milli[index] === null ? "" : pointText(state.grade_points_milli[index]),
                        "aria-describedby": `prerequisite-grade-help-${index}` });
                    floor.addEventListener("input", () => { state.grade_points_milli[index] = pointValue(floor.value); update(); });
                    const source = node('select', { id: `prerequisite-origin-${index}`, class: 'form-select',
                        'aria-describedby': `prerequisite-origin-effective-${index} prerequisite-origin-help-${index}` },
                        ...['', 'either', 'institutional', 'transfer'].map(value => node('option', { value,
                            selected: value === (state.requirement_credit_origins[index] ?? ''),
                        }, originText(value || 'inherit'))));
                    source.addEventListener('change', () => {
                        state.requirement_credit_origins[index] = source.value || null; update();
                    });
                    const sourceStatus = node('p', { id: `prerequisite-origin-effective-${index}`,
                        class: 'form-text', role: 'status' }, `${originText('effective')}: ${effectiveOrigin(index)}`);
                    sourcePreviews.push({ element: sourceStatus, index });
                    const completionFields = node('fieldset', { class: 'record-section mb-3' },
                        node('legend', { class: 'h6' }, originText('completion_window')));
                    const completionInputs = {};
                    const age = node('input', { type: 'number', class: 'form-control', min: '1', max: '1200', step: '1',
                        id: `prerequisite-completion-${index}-age`, value: state.completion_windows[index]?.max_age_months ?? '',
                        'aria-describedby': `prerequisite-recency-help-${index}` });
                    dateControls.push(age);
                    const updateWindow = () => {
                        const from = completionInputs.from.value || null, through = completionInputs.through.value || null;
                        state.completion_windows[index] = from || through || age.value !== ''
                            ? {from, ...(age.value === '' ? {} : {max_age_months: Number(age.value)}), through} : null;
                        for (const control of Object.values(completionInputs)) control.setCustomValidity(
                            from && through && from > through ? originText('invalid_completion_window') : '');
                        update();
                    };
                    age.addEventListener('input', updateWindow);
                    for (const [key, label] of [['from', 'completion_from'], ['through', 'completion_through']]) {
                        const input = node('input', { type: 'date', class: 'form-control prerequisite-completion-date',
                            id: `prerequisite-completion-${index}-${key}`, min: '0001-01-01', max: '9999-12-31',
                            value: state.completion_windows[index]?.[key] || '',
                            'aria-describedby': `prerequisite-completion-help-${index}` });
                        completionInputs[key] = input; dateControls.push(input);
                        input.addEventListener('input', updateWindow);
                        completionFields.append(node('div', { class: 'mb-2' },
                            node('label', { for: input.id, class: 'form-label' }, originText(label)), input));
                    }
                    completionFields.append(node('p', { class: 'form-text mb-0',
                        id: `prerequisite-completion-help-${index}` }, originText('completion_help')));
                    completionFields.append(node('div', { class: 'mt-3' },
                        node('label', { for: age.id, class: 'form-label' }, originText('max_age_months')), age,
                        node('p', { class: 'form-text mb-0', id: `prerequisite-recency-help-${index}` }, originText('recency_help'))));
                    return node("section", { class: "surface border p-3 mb-3", "aria-label": `Requirement ${index + 1}` }, heading,
                        description, group.length > 1 || state.minimums[index] > 1 ? node("div", { class: "mb-3", style: "max-width: 14rem" },
                            node("label", { for: count.id, class: "form-label" }, "Courses required"), count) : null,
                        node('div', { class: 'mb-3' },
                            node('label', { for: source.id, class: 'form-label' }, originText('requirement_field')), source, sourceStatus,
                            node('p', { id: `prerequisite-origin-help-${index}`, class: 'form-text' }, originText('requirement_help'))),
                        node("div", { class: "mb-3" },
                            node("label", { for: floor.id, class: "form-label" }, "Minimum grade points"), floor,
                            node("p", { id: `prerequisite-grade-help-${index}`, class: "form-text" },
                                "Optional. Leave blank for any passing grade. Use grade points from the grading definitions, not a percentage or GPA total. Applies to each course counted in this requirement.")),
                        completionFields, list, node("div", { class: "inline-actions" },
                            button("Add course", () => choose(index), "btn-outline-primary", `Add course to requirement ${index + 1}`),
                            button("Remove requirement", () => removeRequirement(index), "btn-outline-danger btn-sm",
                                `Remove requirement ${index + 1}`)));
                }));
                corequisiteCards.replaceChildren(node("h3", { class: "h5" }, "Co-requisites"),
                    node("p", { class: "form-text" }, "Require all courses listed here. Students may register for them together in the same term. Institutional registration policy controls whether qualifying prior completion also counts. Previously completed courses are not registered or charged again."),
                    node("ul", { class: "list-unstyled" }, ...state.corequisites.map(id => {
                        const label = state.labels.get(id);
                        return node("li", { class: "d-flex flex-wrap justify-content-between gap-2 border-bottom py-2" },
                            node("span", { class: "text-break" }, label ? `${label.code} · ${label.title}` : "Course label unavailable"),
                            button("Remove", () => { state.corequisites.splice(state.corequisites.indexOf(id), 1); draw(); },
                                "btn-outline-secondary btn-sm", `Remove co-requisite ${label?.code || "course"}`));
                    })), state.corequisites.length < 20 ? button("Add co-requisite", () => choose(null)) : null);
                if (!state.corequisites.length) corequisiteCards.append(node("p", { class: "form-text" }, "No co-requisites are required."));
                if (!state.groups.length) cards.append(node("p", { class: "alert alert-info" }, "No prerequisite courses are required."));
            }
            body.append(node("h3", { class: "h5" }, `${saved.course.code} · ${saved.course.title}`),
                node("p", {}, "Choose the required courses within each requirement, then decide whether all requirements or alternative routes qualify. A completed course can satisfy more than one requirement."),
                node("p", { class: "form-text" }, "For example: MATH.101 or MATH.110, plus ENG.101. Only verified passing coursework allowed for the student's program pursuit can satisfy these choices. " +
                    "Draft edits leave earlier publications and student records unchanged."),
                node("section", { class: "surface border p-3 mb-3", "aria-label": "Overall prerequisite rule" },
                    node("div", { class: "form-check mb-3" }, alternative,
                        node("label", { for: alternative.id, class: "form-check-label" }, "Allow alternative requirements")),
                    node('div', { class: 'mb-3' },
                        node('label', { for: origin.id, class: 'form-label' }, originText('field')), origin,
                        node('p', { id: 'prerequisite-credit-origin-help', class: 'form-text' }, originText('help')),
                        node('p', { class: 'form-text' }, originText('scope'))),
                    neededField, overallText, rulePreview,
                    node("div", { class: "inline-actions" },
                        button("Arrange rule", () => {
                            if (!state.groups.length) throw new Error("Add a prerequisite requirement first.");
                            state.rule ||= { minimum: state.minimum_requirements ?? state.groups.length,
                                children: state.groups.map((_, index) => index) };
                            state.minimum_requirements = null;
                            arrangeRule(state.rule, render, describeRequirement);
                        }),
                        ...(state.rule ? [button("Simple rule", () => {
                            state.rule = null; state.minimum_requirements = null; render("The draft now requires every listed requirement. Review before saving.");
                        }, "btn-outline-secondary")] : []))), cards, corequisiteCards, status,
                node("div", { class: "inline-actions" }, add, save, publish, button("Reload saved choices", () => open(course), "btn-link")));
            draw();
            function showEquivalents(required) {
                const detail = openDrawer(originText('equivalents_title'), 'EDIT DRAFT');
                const label = state.labels.get(required);
                const alternatives = state.equivalents[required] || [];
                detail.append(node('h3', { class: 'h5 text-break' }, `${originText('equivalents_for')} ${label?.code || required}`),
                    node('p', { class: 'form-text' }, originText('equivalents_help')),
                    node('p', { class: 'form-text' }, originText('equivalents_counting')));
                const list = node('ul', { class: 'list-unstyled' });
                for (const id of alternatives) {
                    const item = state.labels.get(id);
                    list.append(node('li', { class: 'record-section mb-2 d-flex flex-wrap align-items-center justify-content-between gap-2' },
                        node('span', { class: 'text-break' }, item ? `${item.code} · ${item.title}` : id),
                        button(originText('remove_equivalent'), () => {
                            state.equivalents[required] = alternatives.filter(value => value !== id);
                            if (!state.equivalents[required].length) delete state.equivalents[required];
                            showEquivalents(required);
                        }, 'btn-outline-secondary btn-sm', `${originText('remove_equivalent')} ${item?.code || id}`)));
                }
                detail.append(alternatives.length ? list : node('p', { class: 'alert alert-info' }, originText('no_equivalents')),
                    node('div', { class: 'inline-actions' },
                        button(originText('add_equivalent'), () => choose(null, '', null, [], required)),
                        button('Back to prerequisites', () => {
                            const parent = render();
                            const aria = `${originText('equivalents_for')} ${label?.code || required}`;
                            [...parent.querySelectorAll('button')].find(item => item.getAttribute('aria-label') === aria)?.focus();
                        }, 'btn-link')));
            }
            async function choose(index, search = "", after = null, stack = [], equivalentFor = null) {
                const picker = openDrawer(equivalentFor ? originText('choose_equivalent') : index === null ? "Choose a co-requisite" : "Choose a prerequisite course", "COURSE CHOICES");
                const data = await request("/sis/ui/data/courses/", "POST", { search, ...(after ? { after } : {}) });
                if (!picker.isConnected) return;
                const input = node("input", { type: "search", id: "prerequisite-course-search", class: "form-control",
                    maxlength: "80", value: search });
                const form = node("form", { class: "mb-3" }, node("label", { for: input.id, class: "form-label" }, "Find a course"), input,
                    node("button", { type: "submit", class: "btn btn-primary mt-2" }, "Search courses"));
                form.addEventListener("submit", event => { event.preventDefault(); task(() => choose(index, input.value, null, [], equivalentFor)); });
                const used = new Set(equivalentFor ? [equivalentFor, ...(state.equivalents[equivalentFor] || [])] : index === null ? state.corequisites : state.groups[index]);
                const all = new Set([...state.groups.flat(), ...state.corequisites, ...Object.values(state.equivalents).flat()]);
                const options = data.items.filter(row => row.id !== course && row.active && !used.has(row.id) &&
                    (all.has(row.id) || all.size < 100));
                picker.append(form, node("p", { class: "form-text" }, "Each course appears once within this requirement. Courses used by other requirements remain available. Your unsaved choices are kept while searching."));
                if (!options.length) picker.append(node("p", {}, "No available courses on this page. Try another search or page."));
                for (const row of options) picker.append(node("article", { class: "surface p-3 mb-2" },
                    node("h3", { class: "h6 text-break" }, `${row.code} · ${row.title}`),
                    button(`Choose ${row.code}`, () => {
                        if (state.groups.flat().length >= 500 || (!all.has(row.id) && all.size >= 100))
                            throw new Error("Use up to 100 distinct courses and 500 total choices.");
                        state.labels.set(row.id, row);
                        if (equivalentFor) {
                            if (Object.values(state.equivalents).flat().length >= 500) throw new Error(originText('equivalents_counting'));
                            (state.equivalents[equivalentFor] ||= []).push(row.id);
                            showEquivalents(equivalentFor);
                        } else {
                            if (index === null) { state.corequisites.push(row.id); state.corequisites.sort(); } else state.groups[index].push(row.id);
                            render();
                        }
                    })));
                picker.append(node("div", { class: "inline-actions" },
                    ...(stack.length ? [button("Previous courses", () => choose(index, search, stack.at(-1), stack.slice(0, -1), equivalentFor))] : []),
                    ...(data.next_after ? [button("More courses", () => choose(index, search, data.next_after, [...stack, after], equivalentFor))] : []),
                    button("Back to prerequisites", equivalentFor ? () => showEquivalents(equivalentFor) : render, "btn-link")));
            }
            return body;
        }
        render();
    }
    function describe(definition) {
        const groups = definition.prerequisite_groups;
        const required = definition.prerequisite_requirement_minimum;
        const rule = definition.prerequisite_rule;
        const corequisites = definition.corequisites || [];
        if (!groups.length && !corequisites.length) return node("span", {}, "None");
        const minimums = definition.prerequisite_group_minimums || groups.map(() => 1);
        const view = node("details", {}, node("summary", {}, rule ? "Nested prerequisite rule" : required != null ? `Meet ${required} of ${groups.length} prerequisite requirements` : !groups.length ? `${corequisites.length} co-requisite courses` : minimums.some(count => count > 1)
            ? `Meet the course counts in ${groups.length} requirements` : `Complete one course from each of ${groups.length} requirements`));
        if (definition.prerequisite_credit_origin) view.append(
            node('p', { class: 'form-text' }, `${originText('field')}: ${originText(definition.prerequisite_credit_origin)}`));
        let loading = false, loaded = false;
        view.addEventListener("toggle", () => {
            if (!view.open || loading || loaded) return;
            loading = true;
            task(async () => {
                const data = await request("/sis/prerequisite-labels/", "POST", { ids: [...new Set([...definition.prerequisites, ...Object.values(definition.prerequisite_equivalents || {}).flat(), ...corequisites])] });
                if (!view.isConnected) return;
                const labels = new Map(data.items.map(row => [row.id, `${row.code} · ${row.title}`]));
                if (rule) view.append(ruleView(rule));
                view.append(node("p", { class: "form-text" }, "Current catalog labels; this publication retains the course identities and choice groups."),
                    node("ol", {}, ...groups.map((group, index) => node("li", {}, minimums[index] > 1 ? `At least ${minimums[index]} of: ` : "",
                        group.map(id => (labels.get(id) || "Course label unavailable") +
                            (definition.prerequisite_equivalents?.[id]?.length
                                ? ` (${originText('equivalents_title')}: ${definition.prerequisite_equivalents[id].map(value => labels.get(value) || value).join('; ')})` : '')).join(minimums[index] > 1 ? "; " : " OR "),
                        definition.prerequisite_requirement_credit_origins
                            ? ` · ${originText('requirement_field')}: ${originText(definition.prerequisite_requirement_credit_origins[index] ?? definition.prerequisite_credit_origin ?? 'either')}` : '',
                        definition.prerequisite_completion_windows?.[index]
                            ? ` · ${originText('completion_from')}: ${definition.prerequisite_completion_windows[index].from || '—'}; ${originText('completion_through')}: ${definition.prerequisite_completion_windows[index].through || '—'}` +
                                (definition.prerequisite_completion_windows[index].max_age_months != null
                                    ? ` · ${originText('max_age_months')}: ${definition.prerequisite_completion_windows[index].max_age_months}` : '') : '',
                        definition.prerequisite_grade_points_milli?.[index] != null
                            ? ` · Minimum ${pointText(definition.prerequisite_grade_points_milli[index])} grade points per counted course` : ""))));
                if (corequisites.length) view.append(node("h4", { class: "h6 mt-3" }, "Co-requisites — all required"),
                    node("ul", {}, ...corequisites.map(id => node("li", {}, labels.get(id) || "Course label unavailable"))));
                loaded = true;
            }).finally(() => { loading = false; });
        });
        return view;
    }
    return { open, describe, ruleView };
};
