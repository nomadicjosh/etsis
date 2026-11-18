/* Shared, text-only presentation of working gradebook feedback. */
window.createGradebookCalculation = function (ui) {
    "use strict";
    const { node } = ui;
    const percent = (value) => `${(Number(value) / 100).toFixed(2)}%`;
    function assignmentLabel(score) {
        let label;
        if (score.category_title != null) {
            label = `${score.dropped ? "Dropped from average · " : ""}${score.category_title} · ${percent(score.category_weight_hundredths)} category${score.category_weight_hundredths === 0 ? " · Excluded" : ""}${score.category_drop_lowest ? ` · Drop lowest ${score.category_drop_lowest}` : ""}`;
        } else if (score.excluded && score.weight_hundredths == null) label = "Excluded";
        else if (score.weight_hundredths === 0) label = "Excluded · 0.00%";
        else label = score.weight_hundredths == null ? "By points" : percent(score.weight_hundredths);
        if (score.excused) label = `Excused · ${label}`;
        if (score.bonus_hundredths > 0) label += ` · Bonus limit +${score.bonus_hundredths / 100} points`;
        if (score.earned_hundredths > score.possible_hundredths)
            label += ` · ${(score.earned_hundredths - score.possible_hundredths) / 100} bonus points recorded`;
        return label;
    }
    function panel(summary) {
        return node(
            "section",
            { class: "alert alert-light border", "aria-label": "Gradebook average" },
            node("h4", { class: "h6" }, "Gradebook average"),
            node(
                "p",
                { class: "mb-1 fw-semibold" },
                summary.percent_hundredths == null
                    ? "No included scores yet"
                    : percent(summary.percent_hundredths),
            ),
            node(
                "p",
                { class: "mb-1" },
                `${{ weighted: "Assignment weights", category: "Category weights", points: "Total points" }[summary.mode]} · ${percent(summary.coverage_hundredths)} graded · ${summary.pending} awaiting scores${summary.excused ? ` · ${summary.excused} excused` : ""}${summary.excluded ? ` · ${summary.excluded} excluded` : ""}${summary.dropped ? ` · ${summary.dropped} dropped from average` : ""}`,
            ),
            summary.bonus_enabled
                ? node(
                      "p",
                      { class: "small" },
                      `${Number(summary.bonus_earned_hundredths || 0) / 100} bonus points in counted work. Possible points remain the denominator, so this running average can exceed 100%. Official grades are recorded separately.`,
                  )
                : null,
            summary.mode === "category"
                ? node(
                      "div",
                      {},
                      node(
                          "p",
                          { class: "small" },
                          "Each category uses total points from its graded assignments after any drop rule. The average uses categories with scores; coverage also accounts for ungraded assignments and empty categories.",
                      ),
                      summary.awaiting_categories
                          ? node(
                                "p",
                                { class: "small fw-semibold" },
                                `${summary.awaiting_categories} positive-weight ${summary.awaiting_categories === 1 ? "category has" : "categories have"} no scores yet.`,
                            )
                          : null,
                      node(
                          "ul",
                          { class: "row list-unstyled g-2 mb-3" },
                          summary.categories.map((category) =>
                              node(
                                  "li",
                                  { class: "col-md-6" },
                                  node(
                                      "div",
                                      { class: "border rounded p-2 bg-body text-body h-100" },
                                      node("h5", { class: "h6 mb-1 text-break" }, category.title),
                                      node(
                                          "p",
                                          { class: "mb-1 fw-semibold small" },
                                          `${category.percent_hundredths == null ? "No scores" : `${percent(category.percent_hundredths)} average`} · ${percent(category.weight_hundredths)} of grade`,
                                      ),
                                      node(
                                          "p",
                                          { class: "small mb-0" },
                                          `${category.graded}/${category.assignments} assignments graded · ${percent(category.coverage_hundredths)} of counted points graded`,
                                      ),
                                      category.assignments === 0
                                          ? node(
                                                "span",
                                                { class: "badge text-bg-light border mt-2" },
                                                "No assignments",
                                            )
                                          : null,
                                      category.weight_hundredths === 0
                                          ? node(
                                                "span",
                                                { class: "badge text-bg-light border mt-2" },
                                                "Excluded",
                                            )
                                          : null,
                                      category.drop_lowest
                                          ? node(
                                                "p",
                                                { class: "small mb-0 mt-1" },
                                                `Drop up to ${category.drop_lowest} lowest graded ${category.drop_lowest === 1 ? "score" : "scores"} · ${category.dropped || 0} currently dropped. At least one graded score stays included.`,
                                            )
                                          : null,
                                      category.excused
                                          ? node(
                                                "p",
                                                { class: "small mb-0 mt-1" },
                                                `${category.excused} excused · omitted from this average and grading coverage.`,
                                            )
                                          : null,
                                      category.fully_excused
                                          ? node(
                                                "span",
                                                { class: "badge text-bg-light border mt-2" },
                                                "Excused for this student",
                                            )
                                          : null,
                                  ),
                              ),
                          ),
                      ),
                  )
                : null,
            summary.dropped_assignments?.length
                ? node(
                      "details",
                      { class: "mb-3" },
                      node(
                          "summary",
                          { class: "fw-semibold" },
                          `View ${summary.dropped_assignments.length} dropped ${summary.dropped_assignments.length === 1 ? "score" : "scores"}`,
                      ),
                      node(
                          "p",
                          { class: "small mt-2" },
                          "Lowest percentages are omitted from the average, including their possible points. Scores stay recorded. Ungraded work is never dropped. Equal percentages drop the larger-point task first, then use a fixed assignment order.",
                      ),
                      node(
                          "ul",
                          { class: "mb-0" },
                          summary.dropped_assignments.map((score) =>
                              node(
                                  "li",
                                  { class: "text-break" },
                                  `${score.title} · ${score.earned_hundredths / 100}/${score.possible_hundredths / 100} points · ${percent((10000 * score.earned_hundredths) / score.possible_hundredths)}`,
                              ),
                          ),
                      ),
                  )
                : null,
            summary.excused_assignments?.length
                ? node(
                      "details",
                      { class: "mb-3" },
                      node(
                          "summary",
                          { class: "fw-semibold" },
                          `View ${summary.excused_assignments.length} excused ${summary.excused_assignments.length === 1 ? "assignment" : "assignments"}`,
                      ),
                      node(
                          "ul",
                          { class: "mb-0 mt-2" },
                          summary.excused_assignments.map((score) =>
                              node(
                                  "li",
                                  { class: "text-break" },
                                  `${score.title} · ${score.earned_hundredths == null ? "No mark recorded" : `Saved mark ${score.earned_hundredths / 100}/${score.possible_hundredths / 100}`} · omitted from average`,
                              ),
                          ),
                      ),
                  )
                : null,
            node(
                "p",
                { class: "small mb-0" },
                "Excused and ungraded work are omitted from this running average. Excused work is also omitted from grading coverage; remaining weights are normalized. Recorded zero scores count unless excused or dropped by a configured rule. This is coursework feedback; your official final grade is recorded separately.",
            ),
        );
    }
    function editor(assignments, initial, changed) {
        const element = node("fieldset", {}, node("legend", { class: "h6" }, "Category weights"));
        const rows = node("div", { class: "vstack gap-3 mb-3" });
        const categories = [];
        const members = new Map(assignments.map((a) => [a.id, ""]));
        let sequence = 0,
            page = 0,
            enabled;
        const announcement = node("p", { class: "form-text", role: "status", "aria-live": "polite" });
        const list = node("div", { class: "vstack gap-2" });
        const search = node("input", {
            id: "gradebook-assignment-search",
            type: "search",
            class: "form-control mb-3",
            placeholder: "Find an assignment",
        });
        const paging = node("p", { class: "form-text", role: "status", "aria-live": "polite" });
        const previous = node(
            "button",
            { type: "button", class: "btn btn-outline-secondary btn-sm" },
            "Previous",
        );
        const next = node("button", { type: "button", class: "btn btn-outline-secondary btn-sm" }, "Next");
        function renderAssignments() {
            const term = search.value.trim().toLocaleLowerCase();
            const matches = assignments.filter((a) => a.title.toLocaleLowerCase().includes(term));
            page = Math.max(0, Math.min(page, Math.ceil(matches.length / 50) - 1));
            const visible = matches.slice(page * 50, (page + 1) * 50);
            list.replaceChildren(
                ...visible.map((a) => {
                    const id = `gradebook-category-${a.id}`;
                    const select = node(
                        "select",
                        { id, class: "form-select" },
                        node("option", { value: "" }, "Excluded"),
                        categories.map((c) =>
                            node("option", { value: c.key }, c.title.value.trim() || "Unnamed category"),
                        ),
                    );
                    select.value = members.get(a.id) || "";
                    select.disabled = !enabled;
                    select.addEventListener("change", () => {
                        members.set(a.id, select.value);
                        changed();
                    });
                    return node(
                        "div",
                        { class: "row align-items-center g-2 border-bottom pb-2" },
                        node(
                            "div",
                            { class: "col-md-7" },
                            node("label", { for: id, class: "form-label mb-0" }, a.title),
                        ),
                        node("div", { class: "col-md-5" }, select),
                    );
                }),
            );
            paging.textContent = matches.length
                ? `${page * 50 + 1}–${page * 50 + visible.length} of ${matches.length} assignments`
                : "No matching assignments.";
            previous.disabled = !enabled || page === 0;
            next.disabled = !enabled || (page + 1) * 50 >= matches.length;
        }
        search.addEventListener("input", () => {
            page = 0;
            renderAssignments();
        });
        previous.addEventListener("click", () => {
            --page;
            renderAssignments();
            list.querySelectorAll("select")[0]?.focus();
        });
        next.addEventListener("click", () => {
            ++page;
            renderAssignments();
            list.querySelectorAll("select")[0]?.focus();
        });
        function addCategory(value) {
            const key = `category-${sequence++}`;
            const title = node("input", {
                id: `${key}-name`,
                type: "text",
                class: "form-control",
                required: true,
                maxlength: "80",
            });
            title.value = value.title;
            const weight = node("input", {
                id: `${key}-weight`,
                type: "number",
                class: "form-control",
                required: true,
                min: "0",
                max: "100",
                step: "0.01",
            });
            weight.value = (value.weight_hundredths / 100).toFixed(2);
            const drop = node("input", {
                id: `${key}-drop`,
                type: "number",
                class: "form-control",
                min: "0",
                max: "499",
                step: "1",
                required: true,
                "aria-describedby": `gradebook-drop-help ${key}-drop-error`,
            });
            drop.value = String(value.drop_lowest ?? 0);
            const dropError = node(
                "div",
                { id: `${key}-drop-error`, class: "invalid-feedback", role: "alert", hidden: true },
                "Use a whole number from 0 to 499.",
            );
            const remove = node(
                "button",
                {
                    type: "button",
                    class: "btn btn-outline-danger btn-sm",
                    "aria-label": `Remove category ${value.title || "new category"}`,
                },
                "Remove",
            );
            const row = node(
                "div",
                { class: "border rounded p-3" },
                node(
                    "div",
                    { class: "row g-2 align-items-end" },
                    node(
                        "div",
                        { class: "col-md-5" },
                        node("label", { for: title.id, class: "form-label" }, "Category name"),
                        title,
                    ),
                    node(
                        "div",
                        { class: "col-md-2" },
                        node("label", { for: weight.id, class: "form-label" }, "Weight %"),
                        weight,
                    ),
                    node(
                        "div",
                        { class: "col-md-3" },
                        node("label", { for: drop.id, class: "form-label" }, "Drop lowest"),
                        drop,
                        dropError,
                    ),
                    node("div", { class: "col-md-2" }, remove),
                ),
            );
            const entry = { key, title, weight, drop, dropError, remove, row };
            categories.push(entry);
            value.assignments.forEach((id) => {
                if (members.has(id)) members.set(id, key);
            });
            title.addEventListener("input", () => {
                remove.setAttribute("aria-label", `Remove category ${title.value.trim() || "new category"}`);
                renderAssignments();
                changed();
            });
            weight.addEventListener("input", changed);
            drop.addEventListener("input", changed);
            remove.addEventListener("click", () => {
                const removed = [...members.values()].filter((value) => value === key).length;
                for (const [id, group] of members) if (group === key) members.set(id, "");
                categories.splice(categories.indexOf(entry), 1);
                row.remove();
                renderAssignments();
                add.disabled = !enabled || categories.length >= 20;
                announcement.textContent = `${title.value || "Category"} removed. ${removed} ${removed === 1 ? "assignment is" : "assignments are"} now excluded until reassigned.`;
                add.focus();
                changed();
            });
            rows.append(row);
            return entry;
        }
        const add = node(
            "button",
            { type: "button", class: "btn btn-outline-primary btn-sm mb-3" },
            "Add category",
        );
        add.addEventListener("click", () => {
            if (categories.length >= 20) return;
            const entry = addCategory({ title: "", weight_hundredths: 0, assignments: [] });
            renderAssignments();
            add.disabled = categories.length >= 20;
            entry.title.focus();
            changed();
        });
        initial.forEach(addCategory);
        element.append(
            node(
                "p",
                { class: "form-text" },
                "Use up to 20 categories with weights totaling 100%. Choose a category for each included assignment. New or unassigned work is excluded. Within a category, assignments contribute by possible points.",
            ),
            rows,
            node(
                "p",
                { id: "gradebook-drop-help", class: "form-text" },
                "Drop lowest is optional: 0 keeps every score. A category can omit up to this many lowest graded percentages while retaining at least one graded score. Pending work is never dropped. Stored scores and official final grades stay unchanged.",
            ),
            add,
            announcement,
            node("label", { for: search.id, class: "form-label" }, "Find assignments"),
            search,
            list,
            paging,
            node("div", { class: "inline-actions" }, previous, next),
        );
        function serialize() {
            return categories.map((c) => ({
                title: c.title.value.trim(),
                weight_hundredths: Math.round(Number(c.weight.value) * 100),
                drop_lowest: c.drop.value.trim() === "" ? NaN : Number(c.drop.value),
                assignments: assignments.filter((a) => members.get(a.id) === c.key).map((a) => a.id),
            }));
        }
        function setEnabled(value) {
            if (enabled === value) return;
            enabled = value;
            element.hidden = !enabled;
            for (const tag of ["input", "select", "button"]) {
                element.querySelectorAll(tag).forEach((input) => {
                    input.disabled = !enabled;
                });
            }
            add.disabled = !enabled || categories.length >= 20;
            renderAssignments();
        }
        function valid() {
            const values = serialize();
            categories.forEach((category, index) => {
                const count = values[index].drop_lowest;
                const valid = Number.isInteger(count) && count >= 0 && count <= 499;
                category.drop.setAttribute("aria-invalid", String(!valid));
                category.drop.setAttribute("class", `form-control${valid ? "" : " is-invalid"}`);
                category.drop.setCustomValidity(valid ? "" : "Use a whole number from 0 to 499.");
                category.dropError.hidden = valid;
            });
            const titles = values.map((c) => c.title.toLocaleLowerCase());
            return (
                values.length > 0 &&
                values.length <= 20 &&
                new Set(titles).size === titles.length &&
                values.every(
                    (c) =>
                        c.title.length > 0 &&
                        c.title.length <= 80 &&
                        Number.isInteger(c.weight_hundredths) &&
                        c.weight_hundredths >= 0 &&
                        c.weight_hundredths <= 10000 &&
                        Number.isInteger(c.drop_lowest) &&
                        c.drop_lowest >= 0 &&
                        c.drop_lowest <= 499,
                ) &&
                values.reduce((sum, c) => sum + c.weight_hundredths, 0) === 10000
            );
        }
        setEnabled(false);
        return { element, serialize, setEnabled, valid };
    }
    return { panel, assignmentLabel, editor };
};
