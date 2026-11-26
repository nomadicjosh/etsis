/* Shared, text-only presentation of working gradebook feedback. */
window.createGradebookCalculation = function (ui) {
    "use strict";
    const { node } = ui;
    const t = key => ui.labels[key];
    const percent = (value) => `${(Number(value) / 100).toFixed(2)}%`;
    function policyText(policy, context = null) {
        if (!policy) return "Final-grade comparison policy was not recorded with this decision.";
        const labels = {
            half_up: "nearest, ties up",
            half_even: "nearest, ties to even",
            floor: "round down",
            ceiling: "round up",
        };
        const origin = !context
            ? ""
            : context.scope === "section"
              ? " Section override."
              : context.version
                ? ` Institutional policy version ${context.version}.`
                : " Built-in institutional default.";
        return `Final-grade comparison: ${policy.cap_at_hundred ? "cap at 100% first; " : "no 100% cap; "}${policy.rounding === "none" ? "exact percentage, without rounding" : `${labels[policy.rounding]} to ${policy.decimal_places} decimal places`}. Running averages retain the original percentage. Official grades require separate confirmation.${origin}`;
    }
    function comparisonText(summary) {
        const score = summary.grading_display == null
            ? summary.score_display
            : `${summary.score_display} raw · ${summary.grading_display} for grade comparison`;
        const rules = summary.category_rules;
        const summaryText = rules ? `${score} · ${t('summary').replace('{caps}', rules.capped_categories)
            .replace('{protected}', rules.protected_assignments)}` : score;
        const bonusText = rules?.bonus_limit_categories ? `${summaryText} ${t('summary_bonus_limits')
            .replace('{count}', rules.bonus_limit_categories)}` : summaryText;
        return rules?.regular_bonus_limit_categories ? `${bonusText} ${t('summary_regular_limits')
            .replace('{count}', rules.regular_bonus_limit_categories)}` : bonusText;
    }
    function policyEditor(setup) {
        const policy = setup.grade_policy;
        const scope = setup.institutional_grade_policy
            ? node(
                  "select",
                  { id: "gradebook-policy-scope", class: "form-select" },
                  node("option", { value: "institution" }, "Use institutional policy"),
                  node("option", { value: "section" }, "Use a section override"),
              )
            : null;
        if (scope) scope.value = setup.grade_policy_scope;
        const rounding = node(
            "select",
            { id: "gradebook-rounding", class: "form-select" },
            ...Object.entries({
                none: "Exact percentage (no rounding)",
                half_up: "Nearest, ties up",
                half_even: "Nearest, ties to even",
                floor: "Round down",
                ceiling: "Round up",
            }).map(([value, label]) => node("option", { value }, label)),
        );
        rounding.value = policy.rounding;
        const places = node(
            "select",
            { id: "gradebook-decimals", class: "form-select" },
            ...[0, 1, 2, 3].map((value) => node("option", { value: String(value) }, String(value))),
        );
        places.value = String(policy.decimal_places ?? 0);
        const cap = node("input", {
            id: "gradebook-cap",
            type: "checkbox",
            class: "form-check-input",
        });
        cap.checked = policy.cap_at_hundred;
        const help = node("p", {
            class: "form-text",
            id: "gradebook-policy-help",
            role: "status",
            "aria-live": "polite",
        });
        const element = node(
            "fieldset",
            {
                class: "record-form-section grade-comparison-fields my-3",
                "aria-describedby": "gradebook-policy-help",
            },
            node("legend", { class: "h6" }, "Final-grade comparison policy"),
            scope
                ? node(
                      "div",
                      { class: "mb-3" },
                      node(
                          "label",
                          { for: scope.id, class: "form-label" },
                          "Comparison policy source",
                      ),
                      scope,
                  )
                : null,
            node(
                "div",
                { class: "row g-3" },
                node(
                    "div",
                    { class: "col-sm-8" },
                    node("label", { for: rounding.id, class: "form-label" }, "Rounding rule"),
                    rounding,
                ),
                node(
                    "div",
                    { class: "col-sm-4" },
                    node("label", { for: places.id, class: "form-label" }, "Decimal places"),
                    places,
                ),
            ),
            node(
                "div",
                { class: "form-check mt-3" },
                cap,
                node(
                    "label",
                    { for: cap.id, class: "form-check-label" },
                    "Cap the final-grade percentage at 100% before rounding",
                ),
            ),
            help,
            !setup.can_manage_grade_policy
                ? node(
                      "p",
                      { class: "form-text" },
                      "Changing these rules requires the coursework grading policy duty. You can still save assignment and category weights.",
                  )
                : null,
        );
        element.disabled = !setup.can_manage_grade_policy;
        function serialize() {
            if (scope?.value === "institution")
                return { ...setup.institutional_grade_policy.rules };
            return {
                rounding: rounding.value,
                decimal_places: rounding.value === "none" ? null : Number(places.value),
                cap_at_hundred: cap.checked,
            };
        }
        function update() {
            const inherited = scope?.value === "institution";
            rounding.disabled = inherited;
            cap.disabled = inherited;
            places.disabled = inherited || rounding.value === "none";
            const context = scope
                ? {
                      scope: scope.value,
                      version: inherited ? setup.institutional_grade_policy.version : null,
                  }
                : null;
            help.textContent = policyText(serialize(), context);
        }
        [rounding, places, cap].forEach((control) => control.addEventListener("change", update));
        scope?.addEventListener("change", update);
        update();
        return { element, serialize, scope: () => scope?.value ?? "section" };
    }
    function scoreLabel(score) {
        if (score.earned_hundredths == null) return "Not graded";
        return score.possible_hundredths === 0
            ? `${score.earned_hundredths / 100} bonus points`
            : `${score.earned_hundredths / 100} / ${score.possible_hundredths / 100} points`;
    }
    function assignmentLabel(score) {
        let label;
        if (score.category_title != null) {
            label = `${score.dropped ? "Dropped from average · " : ""}${score.category_title} · ${percent(score.category_weight_hundredths)} category${score.category_weight_hundredths === 0 ? " · Excluded" : ""}${score.category_drop_lowest ? ` · Drop lowest ${score.category_drop_lowest}` : ""}`;
        } else if (score.excluded && score.weight_hundredths == null) label = "Excluded";
        else if (score.weight_hundredths === 0) label = "Excluded · 0.00%";
        else
            label =
                score.weight_hundredths == null
                    ? "By points"
                    : score.possible_hundredths === 0
                      ? `Up to ${Number(score.weight_hundredths) / 100} bonus percentage points`
                      : percent(score.weight_hundredths);
        if (score.possible_hundredths === 0) label = `Bonus only · ${label}`;
        if (score.excused) label = `Excused · ${label}`;
        if (score.drop_protected) label += ` · ${t('protected')}`;
        if (score.bonus_hundredths > 0)
            label += ` · Bonus limit +${score.bonus_hundredths / 100} points`;
        if (score.earned_hundredths > score.possible_hundredths)
            label += ` · ${(score.earned_hundredths - score.possible_hundredths) / 100} bonus points recorded`;
        return label;
    }
    function panel(summary, headingLevel = 4) {
        return node(
            "section",
            { class: "alert alert-light border", "aria-label": "Gradebook average" },
            node(headingLevel === 3 ? 'h3' : 'h4', { class: "h6" }, "Gradebook average"),
            node(
                "p",
                { class: "mb-1 fw-semibold" },
                summary.percent_hundredths == null
                    ? summary.bonus_only
                        ? "No graded regular work yet"
                        : "No included scores yet"
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
                      summary.categories?.some(category => category.cap_at_hundred || category.bonus_only_cap_hundredths !== undefined || category.regular_bonus_cap_hundredths !== undefined)
                          ? t('bonus_caps')
                          : `${Number(summary.bonus_earned_hundredths || 0) / 100} bonus points in counted work. Possible points remain the denominator, so this running average can exceed 100%. Official grades are recorded separately.`,
                  )
                : null,
            summary.bonus_only
                ? node(
                      "p",
                      { class: "small" },
                      `${summary.bonus_only} bonus-only ${summary.bonus_only === 1 ? "assignment" : "assignments"}${summary.bonus_pending ? ` · ${summary.bonus_pending} optional scores not entered` : ""}. These add no possible points or required grading coverage. Bonus-only work is never dropped by a drop-lowest rule.`,
                  )
                : null,
            summary.unapplied_bonus_hundredths
                ? node(
                      "p",
                      { class: "small fw-semibold" },
                      `${summary.unapplied_bonus_hundredths / 100} recorded bonus points are not applied yet. Grade regular work in the same points pool or category first; bonus-only work cannot establish an average by itself.`,
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
                                      node(headingLevel === 3 ? 'h4' : 'h5', { class: "h6 mb-1 text-break" }, category.title),
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
                                      category.unapplied_bonus_hundredths
                                          ? node(
                                                "p",
                                                { class: "small fw-semibold mb-0" },
                                                `${category.unapplied_bonus_hundredths / 100} bonus points awaiting graded regular work in this category.`,
                                            )
                                          : null,
                                      category.drop_lowest
                                          ? node(
                                                "p",
                                                { class: "small mb-0 mt-1" },
                                                `Drop up to ${category.drop_lowest} lowest graded ${category.drop_lowest === 1 ? "score" : "scores"} · ${category.dropped || 0} currently dropped. At least one graded score stays included.`,
                                            )
                                          : null,
                                      category.cap_at_hundred ? node('p', { class: 'small mb-0 mt-1' },
                                          t(category.capped ? 'cap_used' : 'cap_ready'),
                                          category.raw_percent_hundredths == null ? ''
                                              : ` ${t('raw_average')}: ${percent(category.raw_percent_hundredths)}.`) : null,
                                      category.bonus_only_cap_hundredths !== undefined ? node('p', { class: 'small mb-0 mt-1' },
                                          t('bonus_limit_feedback').replace('{limit}', (category.bonus_only_cap_hundredths / 100).toFixed(2)),
                                          category.bonus_only_raw_percent_hundredths == null ? ` ${t('bonus_limit_awaiting')}`
                                              : ` ${t('bonus_limit_applied').replace('{raw}', (category.bonus_only_raw_percent_hundredths / 100).toFixed(2))
                                                  .replace('{applied}', (category.bonus_only_applied_percent_hundredths / 100).toFixed(2))}`) : null,
                                      category.regular_bonus_cap_hundredths !== undefined ? node('p', { class: 'small mb-0 mt-1' },
                                          t('regular_limit_feedback').replace('{limit}', (category.regular_bonus_cap_hundredths / 100).toFixed(2)),
                                          category.regular_bonus_raw_percent_hundredths == null ? ` ${t('bonus_limit_awaiting')}`
                                              : ` ${t('bonus_limit_applied').replace('{raw}', (category.regular_bonus_raw_percent_hundredths / 100).toFixed(2))
                                                  .replace('{applied}', (category.regular_bonus_applied_percent_hundredths / 100).toFixed(2))}`) : null,
                                      category.protected_assignments ? node('p', { class: 'small mb-0 mt-1' },
                                          `${t('protected_count')}: ${category.protected_assignments}.`) : null,
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
                                  `${score.title} · ${score.earned_hundredths == null ? "No mark recorded" : `Saved mark ${scoreLabel(score)}`} · omitted from average`,
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
            node(
                "p",
                { class: "small mt-2 mb-0" },
                policyText(summary.grade_policy, summary.grade_policy_context),
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
        const announcement = node("p", {
            class: "form-text",
            role: "status",
            "aria-live": "polite",
        });
        const list = node("div", { class: "vstack gap-2" });
        const search = node("input", {
            id: "gradebook-assignment-search",
            type: "search",
            class: "form-control mb-3",
            placeholder: "Find an assignment",
        });
        const paging = node("p", {
            class: "form-text",
            role: "status",
            "aria-live": "polite",
        });
        const previous = node(
            "button",
            { type: "button", class: "btn btn-outline-secondary btn-sm" },
            "Previous",
        );
        const next = node(
            "button",
            { type: "button", class: "btn btn-outline-secondary btn-sm" },
            "Next",
        );
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
                            node(
                                "option",
                                { value: c.key },
                                c.title.value.trim() || "Unnamed category",
                            ),
                        ),
                    );
                    select.value = members.get(a.id) || "";
                    select.disabled = !enabled;
                    select.addEventListener("change", () => {
                        let cleared = false;
                        for (const category of categories) cleared = category.protected.delete(a.id) || cleared;
                        members.set(a.id, select.value);
                        protect.checked = false;
                        protect.disabled = !enabled || !select.value || a.possible_hundredths === 0;
                        if (cleared) announcement.textContent = t('protection_cleared');
                        changed();
                    });
                    const category = categories.find(value => value.key === members.get(a.id));
                    const protect = node('input', { id: `${id}-protect`, type: 'checkbox', class: 'form-check-input',
                        checked: category?.protected.has(a.id) || false,
                        disabled: !enabled || !category || a.possible_hundredths === 0,
                        'aria-label': `${t('protect')}: ${a.title}`, 'aria-describedby': 'gradebook-protection-help' });
                    protect.addEventListener('change', () => {
                        const chosen = categories.find(value => value.key === members.get(a.id));
                        if (!enabled || !chosen || a.possible_hundredths === 0) return;
                        protect.checked ? chosen.protected.add(a.id) : chosen.protected.delete(a.id);
                        changed();
                    });
                    return node(
                        "div",
                        { class: "row align-items-center g-2 border rounded p-3 bg-white m-0" },
                        node(
                            "div",
                            { class: "col-md-7" },
                            node(
                                "label",
                                { for: id, class: "form-label mb-0" },
                                a.possible_hundredths === 0 ? `${a.title} · Bonus only` : a.title,
                            ),
                        ),
                        node("div", { class: "col-md-5" }, select),
                        node('div', { class: 'col-12' }, node('div', { class: 'form-check' }, protect,
                            node('label', { for: protect.id, class: 'form-check-label' }, t('protect')))),
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
                {
                    id: `${key}-drop-error`,
                    class: "invalid-feedback",
                    role: "alert",
                    hidden: true,
                },
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
            const cap = node('input', { id: `${key}-cap`, type: 'checkbox', class: 'form-check-input',
                checked: value.cap_at_hundred ?? false, 'aria-describedby': `${key}-cap-help`,
                'aria-label': `${t('cap')}: ${value.title || t('rules')}` });
            const capLabel = node('label', { for: cap.id, class: 'form-check-label' }, t('cap'));
            cap.addEventListener('change', changed);
            const bonusLimit = node('input', {
                id: `${key}-bonus-limit`, type: 'number', class: 'form-control', min: '0', max: '100', step: '0.01',
                value: value.bonus_only_cap_hundredths === undefined ? '' : (value.bonus_only_cap_hundredths / 100).toFixed(2),
                'aria-label': `${t('bonus_limit')}: ${value.title || t('rules')}`,
                'aria-describedby': `${key}-bonus-help ${key}-bonus-error`,
            });
            const bonusError = node('div', { id: `${key}-bonus-error`, class: 'invalid-feedback', role: 'alert', hidden: true }, t('bonus_limit_error'));
            bonusLimit.addEventListener('input', changed);
            const regularLimit = node('input', {
                id: `${key}-regular-limit`, type: 'number', class: 'form-control', min: '0', max: '100', step: '0.01',
                value: value.regular_bonus_cap_hundredths === undefined ? '' : (value.regular_bonus_cap_hundredths / 100).toFixed(2),
                'aria-label': `${t('regular_limit')}: ${value.title || t('rules')}`,
                'aria-describedby': `${key}-regular-help ${key}-regular-error`,
            });
            const regularError = node('div', { id: `${key}-regular-error`, class: 'invalid-feedback', role: 'alert', hidden: true }, t('bonus_limit_error'));
            regularLimit.addEventListener('input', changed);
            const row = node(
                "div",
                { class: "border rounded p-3 bg-white" },
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
                node('div', { class: 'form-check mt-3' }, cap, capLabel),
                node('p', { id: `${key}-cap-help`, class: 'form-text mb-0' }, t('cap_help')),
                node('div', { class: 'mt-3' },
                    node('label', { for: bonusLimit.id, class: 'form-label' }, t('bonus_limit')),
                    bonusLimit, bonusError,
                    node('p', { id: `${key}-bonus-help`, class: 'form-text mb-0' }, t('bonus_limit_help'))),
                node('div', { class: 'mt-3' },
                    node('label', { for: regularLimit.id, class: 'form-label' }, t('regular_limit')),
                    regularLimit, regularError,
                    node('p', { id: `${key}-regular-help`, class: 'form-text mb-0' }, t('regular_limit_help'))),
            );
            const entry = { key, title, weight, drop, dropError, remove, row, cap, bonusLimit, bonusError, regularLimit, regularError,
                protected: new Set(value.protected_assignments || []) };
            categories.push(entry);
            value.assignments.forEach((id) => {
                if (members.has(id)) members.set(id, key);
            });
            title.addEventListener("input", () => {
                cap.setAttribute('aria-label', `${t('cap')}: ${title.value.trim() || t('rules')}`);
                regularLimit.setAttribute('aria-label', `${t('regular_limit')}: ${title.value.trim() || t('rules')}`);
                bonusLimit.setAttribute('aria-label', `${t('bonus_limit')}: ${title.value.trim() || t('rules')}`);
                remove.setAttribute(
                    "aria-label",
                    `Remove category ${title.value.trim() || "new category"}`,
                );
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
            const entry = addCategory({
                title: "",
                weight_hundredths: 0,
                assignments: [],
            });
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
                "Use up to 20 categories with weights totaling 100%. Choose a category for each included assignment. New or unassigned work is excluded. Within a category, regular assignments contribute by possible points. Place bonus-only work with regular assignments; its earned points add no denominator or required coverage.",
            ),
            rows,
            node('p', { id: 'gradebook-protection-help', class: 'form-text' }, t('protect_help')),
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
                assignments: assignments
                    .filter((a) => members.get(a.id) === c.key)
                    .map((a) => a.id),
                ...(c.cap.checked ? { cap_at_hundred: true } : {}),
                ...(c.protected.size ? { protected_assignments: [...c.protected].sort() } : {}),
                ...(c.bonusLimit.value.trim() === '' ? {} : { bonus_only_cap_hundredths: parseBonusLimit(c.bonusLimit.value) }),
                ...(c.regularLimit.value.trim() === '' ? {} : { regular_bonus_cap_hundredths: parseBonusLimit(c.regularLimit.value) }),
            }));
        }
        function parseBonusLimit(value) {
            const text = value.trim();
            return /^\d{1,3}(?:\.\d{1,2})?$/.test(text) && Number(text) <= 100
                ? Math.round(Number(text) * 100) : NaN;
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
                const bonusValid = category.bonusLimit.value.trim() === '' ||
                    Number.isInteger(parseBonusLimit(category.bonusLimit.value));
                category.bonusLimit.setAttribute('aria-invalid', String(!bonusValid));
                category.bonusLimit.classList.toggle('is-invalid', !bonusValid);
                category.bonusLimit.setCustomValidity(bonusValid ? '' : t('bonus_limit_error'));
                category.bonusError.hidden = bonusValid;
                const regularValid = category.regularLimit.value.trim() === '' ||
                    Number.isInteger(parseBonusLimit(category.regularLimit.value));
                category.regularLimit.setAttribute('aria-invalid', String(!regularValid));
                category.regularLimit.classList.toggle('is-invalid', !regularValid);
                category.regularLimit.setCustomValidity(regularValid ? '' : t('bonus_limit_error'));
                category.regularError.hidden = regularValid;
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
                        c.drop_lowest <= 499 &&
                        (c.bonus_only_cap_hundredths === undefined || Number.isInteger(c.bonus_only_cap_hundredths)) &&
                        (c.regular_bonus_cap_hundredths === undefined || Number.isInteger(c.regular_bonus_cap_hundredths)),
                ) &&
                values.reduce((sum, c) => sum + c.weight_hundredths, 0) === 10000
            );
        }
        setEnabled(false);
        return { element, serialize, setEnabled, valid };
    }
    return { panel, assignmentLabel, scoreLabel, editor, policyEditor, policyText, comparisonText };
};

/* Explicit institutional publication; grade decisions retain their own applied policy evidence. */
window.createGradeComparisonPolicy = function (ui) {
    "use strict";
    const { node, request, task, openDrawer } = ui;
    let generation = 0;
    const button = (label, run, style = "btn-outline-primary") =>
        node(
            "button",
            { type: "button", class: `btn ${style}`, onclick: (e) => task(run, e.currentTarget) },
            label,
        );
    const returnAction = () =>
        ui.closeDrawer ? button("Back to students", ui.closeDrawer, "btn-outline-secondary") : null;
    const calculation = window.createGradebookCalculation({ node });
    const comparison = (title, text) =>
        node(
            "section",
            { class: "comparison-policy-summary" },
            node("h3", { class: "h6" }, title),
            node("p", { class: "mb-0" }, text),
        );
    async function open() {
        const current = ++generation;
        const body = openDrawer(
            "Institutional grade comparison policy",
            "REVIEWED POLICY PUBLICATION",
        );
        body.classList.add("comparison-policy-panel");
        body.append(
            returnAction(),
            node("p", { role: "status", class: "form-text" }, "Loading comparison policy…"),
        );
        const data = await request("/sis/grade-comparison-policy/");
        if (!body.isConnected || current !== generation) return;
        let rules = structuredClone(data.rules),
            reason = "",
            draftVersion = data.version,
            currentRules = data.rules;
        const guidance = () =>
            node(
                "p",
                {},
                "This policy applies to sections using institutional inheritance. Explicit section overrides and posted grades keep their recorded rules. Publishing does not post grades or change running averages.",
            );
        function failure(error, target) {
            if (error.status === 403) {
                ++generation;
                rules = null;
                reason = "";
                body.replaceChildren(
                    returnAction(),
                    node(
                        "p",
                        { role: "alert" },
                        "Policy access changed. Reopen the task after checking your access.",
                    ),
                );
                return;
            }
            target.textContent = error.fields?.map((f) => f.message).join(" ") || error.message;
        }
        function edit() {
            ++generation;
            let pending = false;
            const editor = window
                .createGradebookCalculation({ node })
                .policyEditor({ grade_policy: rules, can_manage_grade_policy: true });
            const input = node("textarea", {
                id: "grade-comparison-reason",
                class: "form-control",
                rows: 4,
                required: true,
                maxlength: 1000,
            });
            input.value = reason;
            const alert = node("p", { role: "alert", class: "text-danger" });
            const submit = node(
                "button",
                { type: "submit", class: "btn btn-primary" },
                "Review institutional policy",
            );
            const form = node(
                "form",
                { class: "comparison-policy-form" },
                editor.element,
                node(
                    "fieldset",
                    { class: "record-form-section" },
                    node("legend", {}, "Publication reason"),
                    node(
                        "p",
                        { class: "form-text", id: "grade-comparison-reason-help" },
                        "Explain why this institutional policy is being published. The reason is retained with the decision.",
                    ),
                    node(
                        "label",
                        { for: input.id, class: "form-label" },
                        "Reason for policy publication",
                    ),
                    input,
                ),
                alert,
                node("div", { class: "record-form-actions" }, submit),
            );
            input.setAttribute("aria-describedby", "grade-comparison-reason-help");
            const changed = () => {
                rules = editor.serialize();
                reason = input.value;
                if (pending) {
                    ++generation;
                    pending = false;
                }
            };
            form.addEventListener("input", changed);
            form.addEventListener("change", changed);
            form.addEventListener("submit", (e) => {
                e.preventDefault();
                if (!form.reportValidity()) return;
                rules = editor.serialize();
                reason = input.value;
                const version = ++generation;
                pending = true;
                task(async () => {
                    try {
                        const review = await request(
                            "/sis/grade-comparison-policy/review/",
                            "POST",
                            { rules, version: draftVersion, reason },
                        );
                        if (!body.isConnected || generation !== version) return;
                        pending = false;
                        const status = node("p", { role: "alert", class: "text-danger" });
                        body.replaceChildren(
                            returnAction(),
                            node("h3", { class: "h5" }, "Review institutional comparison policy"),
                            guidance(),
                            comparison(
                                `Current publication: ${draftVersion || "Built-in default"}`,
                                calculation.policyText(currentRules),
                            ),
                            comparison("Proposed:", calculation.policyText(review.rules)),
                            comparison("Reason for policy publication", review.reason),
                            node(
                                "p",
                                {},
                                "The review expires in twenty minutes. Retry the same confirmation after an interrupted response to recover its original result.",
                            ),
                            status,
                            node(
                                "div",
                                { class: "record-form-actions" },
                                button(
                                    "Publish comparison policy",
                                    async () => {
                                        try {
                                            const result = await request(
                                                "/sis/grade-comparison-policy/publish/",
                                                "POST",
                                                review,
                                            );
                                            if (!body.isConnected || generation !== version) return;
                                            ++generation;
                                            rules = null;
                                            reason = "";
                                            body.replaceChildren(
                                                returnAction(),
                                                node(
                                                    "p",
                                                    {
                                                        role: "status",
                                                        class: "alert alert-success",
                                                    },
                                                    `Institutional comparison policy version ${result.version} published.`,
                                                ),
                                                button("Open current policy", open),
                                            );
                                        } catch (error) {
                                            if (body.isConnected && generation === version)
                                                failure(error, status);
                                        }
                                    },
                                    "btn-primary",
                                ),
                                button("Revise policy", edit, "btn-outline-secondary"),
                            ),
                        );
                    } catch (error) {
                        pending = false;
                        if (body.isConnected && generation === version) failure(error, alert);
                    }
                }, submit);
            });
            body.replaceChildren(
                returnAction(),
                comparison(
                    `Current publication: ${draftVersion ? `Version ${draftVersion}` : "Built-in default"}`,
                    calculation.policyText(currentRules),
                ),
                guidance(),
                form,
            );
            form.querySelector(".record-form-actions").append(
                button("Refresh policy source", async () => {
                    rules = editor.serialize();
                    reason = input.value;
                    const revision = ++generation;
                    try {
                        const fresh = await request("/sis/grade-comparison-policy/");
                        if (!body.isConnected || generation !== revision) return;
                        currentRules = fresh.rules;
                        draftVersion = fresh.version;
                        edit();
                    } catch (error) {
                        if (body.isConnected && generation === revision) failure(error, alert);
                    }
                }),
            );
        }
        edit();
    }
    return { open };
};
