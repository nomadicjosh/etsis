/* Shared local guidance: no network calls, storage or academic calculations. */
window.createDegreeProgressGuide = function (ui, layout, steps, slot, data, state, links) {
    const { node, button, actions } = ui;
    const heading = node(
        "h2",
        { id: "degree-progress-guide-title", tabindex: "-1" },
        "Degree progress guide",
    );
    const panel = node("aside", {
        class: "progress-guide-panel",
        "aria-labelledby": heading.id,
        "data-progress-guide": "",
    });
    const content = node("div", { class: "progress-guide-answer", "aria-live": "polite" });
    const announcement = node("p", { class: "visually-hidden", role: "status" });
    const launch = button("Open degree progress guide", () => open(true));
    launch.setAttribute("aria-controls", "degree-progress-guide");
    launch.setAttribute("aria-expanded", "false");
    panel.id = "degree-progress-guide";
    const topics = [
        ["steps", "What should I review next?"],
        ...(data.status === "review"
            ? [
                  ["credits", "Understand credits and GPA"],
                  ["groups", "Understand course placement"],
                  ["work", "Understand unused and pending work"],
              ]
            : []),
        ["meaning", "What can this review confirm?"],
    ];
    if (!topics.some(([key]) => key === state.topic)) state.topic = "steps";
    const localButton = (label, run) =>
        node("button", { type: "button", class: "btn btn-outline-primary", onclick: run }, label);
    const choices = new Map();
    const topicsBox = node("div", {
        class: "progress-guide-topics",
        role: "group",
        "aria-label": "Degree guide topics",
    });
    const paragraph = (value) => node("p", {}, value);
    function answer() {
        steps.remove();
        for (const [key, choice] of choices) choice.setAttribute("aria-pressed", String(key === state.topic));
        if (state.topic === "steps") {
            steps.open = true;
            content.replaceChildren(steps);
        } else if (state.topic === "credits") {
            content.replaceChildren(
                node("h3", {}, "Credits and GPA answer different questions"),
                paragraph(
                    "Earned credits, residency credits and GPA are separate checks. A course may affect one without satisfying a course group.",
                ),
                paragraph(
                    "Cumulative GPA uses institutional work. Overall GPA uses the retained transfer-GPA policy. Each grade's recorded treatment and the selected repeat rules determine its contribution.",
                ),
                paragraph(
                    data.minimums.some((check) => check.status === "unknown")
                        ? "Some minimum checks need review. Missing evidence or a GPA not yet established must not be treated as zero."
                        : "Use the minimum cards to compare this review's results with the retained requirements. GPA is not rounded upward to meet a minimum.",
                ),
                button("Show minimum cards", links.minimums),
                button("Open credit and GPA detail", links.contributions),
            );
        } else if (state.topic === "groups") {
            const explanations = {
                feasible:
                    "This review found a complete placement for the supported course-group rules. Another valid plan may use different courses.",
                infeasible:
                    "This review did not find a complete placement. Inspect the recorded shortfalls and competing course uses with the registrar.",
                undetermined:
                    "The placement search reached its limit. This does not prove that completing the groups is impossible.",
                needs_review:
                    "Course-group evidence needs review. The review does not offer a speculative partial placement.",
                unsupported:
                    "Some retained rules are not supported by the current placement review. Ask the registrar to review those requirements.",
            };
            content.replaceChildren(
                node("h3", {}, "Placement follows the selected requirements"),
                paragraph(explanations[data.allocation.outcome] || explanations.needs_review),
                paragraph(
                    "Approved waivers, substitutions, grade thresholds, repeat treatment and course-sharing rules affect placement. Earned-credit and GPA treatment remains separate.",
                ),
                button("Open course-group detail", links.groups),
            );
        } else if (state.topic === "work") {
            content.replaceChildren(
                node("h3", {}, "Unused does not mean unnecessary"),
                paragraph(
                    data.work_summary.placement_established
                        ? "Unused completed work was not selected in this proposed complete group plan. It may still count toward earned credits or GPA, and another valid plan may use it."
                        : "Unused completed work cannot be identified until a complete placement is established. Unplaced courses are not treated as unnecessary.",
                ),
                paragraph(
                    "Attempts awaiting a final grade do not predict earned credits, GPA or completed requirements. Their attempted-credit summary covers all posting terms in this review, not current-term enrollment load.",
                ),
                button("Open unused and pending work", links.contributions),
            );
        } else {
            content.replaceChildren(
                node("h3", {}, "A review supports the next conversation"),
                paragraph(
                    data.status === "review"
                        ? "This review compares available recorded work with selected general minimums and supported course groups. It does not certify that earlier academic history is complete."
                        : "This review is not ready to provide completion comparisons. Follow the next steps to resolve the missing selection or arrange a controlled review.",
                ),
                paragraph(
                    "Meeting displayed minimums or finding a group placement is not a graduation decision. The registrar must verify remaining institutional requirements and the final award process.",
                ),
            );
        }
    }
    topics.forEach(([key, label]) => {
        const choice = localButton(label, () => {
            state.topic = key;
            answer();
        });
        choices.set(key, choice);
        topicsBox.append(choice);
    });
    const move = localButton("", () => {
        state.side = state.side === "left" ? "right" : "left";
        dock();
        announcement.textContent = `Guide moved to the ${state.side} of the review. On narrow screens it appears ${state.side === "left" ? "before" : "after"} the review.`;
        move.focus({ preventScroll: true });
    });
    function dock() {
        layout.dataset.guideSide = state.side;
        if (state.side === "left") layout.prepend(panel);
        else layout.append(panel);
        move.textContent = state.side === "left" ? "Move guide right" : "Move guide left";
    }
    function open(focus) {
        if (!state.open) state.inlineOpen = steps.open;
        state.open = true;
        launch.setAttribute("aria-expanded", "true");
        dock();
        answer();
        if (focus) heading.focus();
    }
    function dismiss() {
        state.open = false;
        steps.open = Boolean(state.inlineOpen);
        slot.append(steps);
        panel.remove();
        delete layout.dataset.guideSide;
        launch.setAttribute("aria-expanded", "false");
        launch.focus({ preventScroll: true });
    }
    panel.addEventListener("keydown", (event) => {
        if (event.key !== "Escape") return;
        event.preventDefault();
        event.stopPropagation();
        dismiss();
    });
    panel.append(
        node("p", { class: "progress-guide-eyebrow" }, "YOUR REVIEW · LOCAL GUIDANCE"),
        heading,
        paragraph(
            "Uses the review already on this screen. Refresh degree progress after records change. Nothing is sent to an AI provider.",
        ),
        actions(move, button("Close progress guide", dismiss)),
        announcement,
        topicsBox,
        content,
    );
    if (state.open) open(true);
    return launch;
};

window.createDegreeProgressNextSteps = function (node, data) {
    const steps = [];
    const add = (title, description) =>
        steps.push(
            node(
                "li",
                { class: "list-group-item" },
                node("strong", {}, title),
                node("p", { class: "mb-0 mt-1" }, description),
            ),
        );
    if (data.status === "needs_selection") {
        add(
            "Arrange a requirements selection",
            "Ask an authorized registrar to review and select published requirements for this program pursuit, then refresh degree progress.",
        );
    } else if (data.status !== "review") {
        add(
            "Arrange a controlled record review",
            data.status === "inconsistent"
                ? "The academic facts could not be verified. Ask the registrar to resolve the discrepancy before relying on progress results."
                : "This review reached its work limit. Ask the registrar to arrange a bounded review; no partial results should be used to make a decision.",
        );
    } else {
        if (data.input_status === "history_gaps")
            add(
                "Verify earlier history",
                "Ask the registrar to check missing earlier evidence. Available current records do not establish that the historical archive is complete.",
            );
        if (data.context?.cohort_changed)
            add(
                "Confirm the selected requirements",
                "The matriculation context changed. Ask an authorized registrar to review the retained requirements selection; do not assume today's program requirements apply.",
            );
        const checks = data.minimums || [];
        if (checks.some((check) => check.status === "unknown"))
            add(
                "Resolve unavailable minimum checks",
                "Review the affected minimum cards and credit/GPA contributions with the registrar. Missing evidence or a GPA not yet established must not be treated as zero.",
            );
        if (checks.some((check) => check.status === "below_minimum"))
            add(
                "Review minimum shortfalls",
                "Use the minimum cards and credit/GPA contributions to discuss the known shortfalls with the appropriate academic office. This guide does not prescribe courses or override institutional policy.",
            );
        if (data.allocation?.outcome !== "feasible")
            add(
                "Review course-group placement",
                "Open course-group details to review shortages, competing uses or unavailable evidence. A search limit or unsupported rule does not prove that completion is impossible.",
            );
        if ((data.work_summary?.awaiting_grade_count || 0) > 0)
            add(
                "Review pending grades",
                "Open unused and pending work to inspect recorded ungraded attempts. Refresh after grades are posted; attempted credits do not predict earned credits or GPA.",
            );
        if ((data.work_summary?.unused_completed_count || 0) > 0)
            add(
                "Inspect unused completed work",
                "These records were not used in the proposed complete group plan. They may still count toward earned credits or GPA, and another valid plan may use them.",
            );
        add(
            "Confirm remaining requirements with the registrar",
            "Review group contents, specializations, residency policy and final award requirements with the appropriate office. Meeting displayed minimums or finding a group plan is not a graduation decision.",
        );
    }
    return node(
        "details",
        { class: "record-section", "data-progress-next-steps": "" },
        node("summary", { class: "h5" }, "Next steps for this review"),
        node(
            "p",
            {},
            "Local guidance from this captured review. No AI provider is used, no extra records are loaded and no academic changes are saved.",
        ),
        node("ol", { class: "list-group list-group-numbered" }, ...steps),
    );
};

window.degreeProgressCalculationReasons = Object.freeze({
    included: "Included once in this total",
    no_earned_credit: "No eligible earned credit",
    not_included: "Not included in GPA",
    in_progress: "No final grade recorded",
    grade_unknown: "The retained grade definition is unavailable",
    late_drop: "Late drop earns no credit",
    voided: "Voided award",
    transfer_excluded: "Transfer awards are excluded by the selected policy",
    default_drop_marker: "Ungraded drop marker adds no GPA weight",
    grade_excludes_gpa: "The retained grade is excluded from GPA",
    no_gpa_credits: "No attempted credits to weight in GPA",
    policy_unknown: "The required policy choice was not recorded",
    grading_policy_unknown: "The retained grading publication is unknown",
    grading_policy_differs: "The grade uses a different publication; compatibility has not been established",
    repeat_not_selected: "Another eligible repeat supplies the contribution",
    equivalent_credit_alternative: "Tied earned-credit alternative; counted once for this course",
    equivalent_gpa_alternative: "Equivalent repeat alternative; one GPA contribution",
    undetermined: "The complete total cannot be established",
    eligible: "Eligible for review",
});

/* Shared presentation of captured evidence; no reads, calculations, storage or academic decisions. */
window.degreeProgressGradeText = function (record, milli) {
    if (record?.grade_state === "retained" && record.retained_grade) {
        const grade = record.retained_grade;
        return `Recorded grade: ${grade.code} · ${milli(grade.points_milli)} grade points${grade.grading_publication_retained ? "" : " · Grading publication not retained"}`;
    }
    return record?.grade_state === "awaiting_grade" ? "Awaiting a final grade" : "Grade evidence unavailable";
};

window.createDegreeProgressEvidence = function (ui, options, resolve) {
    const { node, button, milli } = ui;
    if (!options?.length) return null;
    const detail = node("details", { class: "my-2" }, node("summary", { class: "py-2" }, "Supporting coursework"));
    let loaded = false;
    detail.addEventListener("toggle", () => {
        if (!detail.open || loaded) return;
        loaded = true;
        const content = node("div");
        detail.append(
            node(
                "p",
                {},
                options.length > 1
                    ? "These are equally eligible earned-credit evidence alternatives for this placement. No overall GPA or residency winner is selected here."
                    : "These recorded awards support the proposed placement under the retained repeat-credit policy. Recorded credit is not necessarily applied to every requirement card.",
            ),
            content,
        );
        function show(alternative = 0, offset = 0, focus = false) {
            content.replaceChildren();
            const rows = options[alternative];
            const status = node(
                "p",
                { role: "status", tabindex: "-1" },
                `${options.length > 1 ? `Evidence alternative ${alternative + 1} of ${options.length} · ` : ""}Showing supporting records ${offset + 1}–${Math.min(offset + 20, rows.length)} of ${rows.length}.`,
            );
            const table = node(
                "table",
                { class: "table table-striped align-middle" },
                node("caption", {}, "Retained course records supporting this proposed placement"),
                node(
                    "thead",
                    {},
                    node(
                        "tr",
                        {},
                        ...[
                            "Course",
                            "Posting term",
                            "Source",
                            "Recorded grade",
                            "Recorded earned credits",
                            "Completion date",
                        ].map((label) => node("th", { scope: "col" }, label)),
                    ),
                ),
            );
            const body = node("tbody");
            for (const reference of rows.slice(offset, offset + 20)) {
                const record = resolve(reference);
                if (!record) {
                    body.append(
                        node(
                            "tr",
                            {},
                            node(
                                "td",
                                { colspan: "6" },
                                "Supporting record unavailable. Ask the registrar to review this record.",
                            ),
                        ),
                    );
                    continue;
                }
                body.append(
                    node(
                        "tr",
                        {},
                        ...[
                            `${record.course_code} · ${record.course_title}`,
                            record.term_label_retained && record.term_code
                                ? record.term_code
                                : "Posting term label not retained",
                            record.credit_type === "institutional"
                                ? "Institutional"
                                : record.credit_type === "transfer"
                                  ? "Transfer"
                                  : "Source not retained",
                            window.degreeProgressGradeText(record, milli),
                            record.recorded_earned_milli == null
                                ? "Unavailable"
                                : milli(record.recorded_earned_milli),
                            record.completed_on || "Not recorded",
                        ].map((value) => node("td", {}, value)),
                    ),
                );
            }
            table.append(body);
            const controls = node("div", { class: "d-flex flex-wrap gap-2 mb-3" });
            if (alternative > 0)
                controls.append(
                    button("Previous evidence alternative", () => show(alternative - 1, 0, true)),
                );
            if (alternative + 1 < options.length)
                controls.append(button("Next evidence alternative", () => show(alternative + 1, 0, true)));
            if (offset > 0)
                controls.append(
                    button("Previous supporting records", () => show(alternative, offset - 20, true)),
                );
            if (offset + 20 < rows.length)
                controls.append(
                    button("More supporting records", () => show(alternative, offset + 20, true)),
                );
            content.append(
                status,
                node(
                    "div",
                    {
                        class: "table-responsive",
                        tabindex: "0",
                        role: "region",
                        "aria-label": "Supporting coursework table",
                    },
                    table,
                ),
                controls,
            );
            if (focus) status.focus();
        }
        show();
    });
    return detail;
};
