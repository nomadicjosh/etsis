/* Display only the retained group restriction; dates are inclusive actual-completion boundaries. */
window.degreeGroupEligibilityText = function (facts, context = null, evaluation = null) {
    const origin = { any: "Institutional or transfer work", institutional: "Institutional work only", transfer: "Transfer work only" };
    const institutions = facts?.transfer_institutions || [];
    const transfer = institutions.length ? `Transfer work must come from: ${institutions.map(item => `${item.code} — ${item.name}`).join("; ")}. Institutional work remains subject to the origin rule. ` : "";
    return `${origin[facts?.credit_origin || "any"]}. ` + transfer +
        (facts?.completed_from || facts?.completed_through
            ? `Actual completion ${[facts.completed_from ? `on or after ${facts.completed_from}` : "", facts.completed_through ? `on or before ${facts.completed_through}` : ""].filter(Boolean).join(" and ")}. Missing completion dates require review.`
            : (facts?.max_age_months_at_pursuit_start != null || facts?.max_age_months_at_evaluation != null || facts?.completed_since_pursuit_start) ? "No fixed completion-date window." : "No completion-date restriction.") +
        (facts?.completed_since_pursuit_start
            ? ` ${window.degreeEligibilityLabels.since_start_rule} ` +
                (context?.pursuit_started_on
                    ? window.degreeEligibilityLabels.since_start_date.replace('{date}', context.pursuit_started_on)
                    : context ? window.degreeEligibilityLabels.start_unknown : '') : '') +
        (facts?.max_age_months_at_pursuit_start != null
            ? ` Maximum age: ${facts.max_age_months_at_pursuit_start} calendar months before the recorded pursuit start. ` +
                (context?.pursuit_started_on
                    ? `Pursuit began ${context.pursuit_started_on}; actual completion must be on or after ${context.earliest_completion_on}, inclusive.`
                    : context ? "The recorded pursuit start is unavailable; review is required."
                        : "The inclusive boundary uses the pursuit start; a shorter month ends on its last valid day.")
            : "") +
        (facts?.max_age_months_at_evaluation != null
            ? ` Maximum age at review: ${facts.max_age_months_at_evaluation} calendar months. ` +
                (evaluation?.evaluated_on
                    ? `Reviewed on ${evaluation.evaluated_on}; actual completion must be on or after ${evaluation.earliest_completion_on}, inclusive.`
                    : evaluation ? "The review date is unavailable; review is required."
                        : "The inclusive boundary uses the date of each progress review; shorter months end on their last valid day.")
            : "");
};
/* Public requirement ordinals and policies; each retained card keeps its own gates. */
window.degreeRequirementPoliciesText = function (group) {
    const labels = { any: "Institutional or transfer work", institutional: "Institutional work only", transfer: "Transfer work only" };
    const origins = group?.requirement_origins || (group?.rules || []).flatMap((rule, i) =>
        Object.hasOwn(rule, "credit_origin") ? [{ requirement: i + 1, credit_origin: rule.credit_origin }] : []);
    const grades = group?.requirement_grade_floors || (group?.rules || []).flatMap((rule, i) =>
        Object.hasOwn(rule, "min_course_grade_points_milli") ? [{requirement: i + 1, minimum_points_milli: rule.min_course_grade_points_milli}] : []);
    return [...origins.map(item => `Requirement ${item.requirement}: ${labels[item.credit_origin] || "Review credit origin"}.`),
        ...grades.map(item => item.minimum_points_milli == null
            ? `Requirement ${item.requirement}: No additional per-course grade minimum. A passing grade is still required.`
            : `Requirement ${item.requirement}: Per-course minimum ${item.minimum_points_milli / 1000} grade points.`)].join(" ");
};
/* Retained curriculum labels, shared by staff and private student progress. */
window.curriculumOrganizationText = function (choice) {
    const labels = window.curriculumOwnershipLabels || {};
    const t = (key, fallback) => labels[key] || fallback;
    const department = choice?.department;
    const describe = (owner) =>
        `${owner.code} — ${owner.title}` +
        (owner.school
            ? ` (${t("school", "School/college")}: ${owner.school.code} — ${owner.school.title})`
            : "");
    const primary =
        !choice || !Object.hasOwn(choice, "department")
            ? t("unknown", "not recorded in this publication")
            : department
                ? `${department.code} — ${department.title}. ${t("school", "School/college")}: ` +
                    (!Object.hasOwn(department, 'school') ? t('unknown', 'not recorded in this publication') : department.school
                        ? `${department.school.code} — ${department.school.title}`
                        : t("unassigned", "not assigned"))
                : t("unassigned", "not assigned");
    const additional =
        !choice || !Object.hasOwn(choice, "additional_departments")
            ? t("unknown", "not recorded in this publication")
            : choice.additional_departments.length
                ? choice.additional_departments.map(describe).join("; ")
                : t("none", "none");
    return `${t("primary", "Responsible department")}: ${primary}. ${t("additional", "Additional responsible departments")}: ${additional}`;
};
window.programOrganizationText = function (classifications) {
    const department = classifications?.department;
    return window.curriculumOrganizationText({
        department: department ? { ...department,
            ...Object.hasOwn(classifications, 'department_school') ? { school: classifications.department_school } : {},
        } : null,
        ...classifications && Object.hasOwn(classifications, 'additional_departments')
            ? { additional_departments: classifications.additional_departments } : {},
    });
};
window.degreeEligibilityLabels = JSON.parse(
    document.getElementById('degree-eligibility-labels')?.textContent || '{}',
);
window.curriculumOwnershipLabels = JSON.parse(
    document.getElementById("curriculum-ownership-labels")?.textContent || "{}",
);
window.curriculumChoiceLabels = JSON.parse(
    document.getElementById('curriculum-choice-labels')?.textContent || '{}',
);
window.degreeCompetitionLabels = JSON.parse(
    document.getElementById("degree-competition-labels")?.textContent || "{}",
);
window.curriculumExclusionPairs = function (snapshot, selected = null) {
    const choices = new Map((snapshot?.offered || []).map(choice => [choice.id, choice]));
    return (snapshot?.component_exclusions || [])
        .filter(pair => selected === null || pair.every(id => selected.has(id)))
        .map(pair => pair.map(id => choices.has(id)
            ? `${choices.get(id).code} — ${choices.get(id).title}`
            : window.curriculumChoiceLabels.unavailable).join(' / '));
};
window.curriculumExclusionSummary = function (node, snapshot) {
    const labels = window.curriculumChoiceLabels;
    if (snapshot?.component_exclusions == null) return node('p', { class: 'form-text' }, labels.unknown);
    const pairs = window.curriculumExclusionPairs(snapshot);
    if (!pairs.length) return node('p', { class: 'form-text' }, labels.none);
    return node('details', { class: 'surface p-3 my-3' },
        node('summary', { class: 'fw-semibold' }, `${labels.heading} (${pairs.length})`),
        node('ul', { class: 'mt-2 mb-0' }, ...pairs.map(pair => node('li', {}, pair))));
};
window.curriculumMajorText = function (choice) {
    if (!choice || !Object.hasOwn(choice, "parent_major")) return "Required-major relationship: not recorded in this publication";
    return choice.parent_major ? `Requires major: ${choice.parent_major.code} — ${choice.parent_major.title}` : "Required major: none";
};
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
        if ([...(data.allocation?.issues || []), ...(data.allocation?.eligibility_issues || [])].includes("completion_date_unknown"))
            add("Review actual completion dates", "Ask the registrar to verify actual completion dates for this group's retained date or course-age rule. Posting terms and catalog dates cannot replace missing completion evidence.");
        if ([...(data.allocation?.issues || []), ...(data.allocation?.eligibility_issues || [])].includes("evaluation_date_unknown"))
            add("Refresh the dated progress review", "Ask the registrar to refresh progress with its explicit review date. A recorded review keeps its original date; today's date cannot replace missing historical context.");
        if ([...(data.allocation?.issues || []), ...(data.allocation?.eligibility_issues || [])].includes("pursuit_start_unknown"))
            add("Review the recorded pursuit start", "Ask the registrar to review the actual pursuit start selected with degree requirements. Today's date, term starts and anticipated completion cannot replace it. Temporary tests use that same recorded date.");
        if ([...(data.allocation?.issues || []), ...(data.allocation?.eligibility_issues || [])].includes("transfer_institution_unknown"))
            add("Review retained transfer-source evidence", "Ask the registrar to review the award's recorded sending institution. Today's catalog or equivalency labels cannot replace missing retained evidence.");
        if ([...(data.allocation?.issues || []), ...(data.allocation?.eligibility_issues || [])].includes("origin_unknown"))
            add("Review coursework origin", "Ask the registrar to verify the recorded institutional or transfer origin before applying this group's restriction.");
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
    placement_work_not_selected: "A reviewed placement selects different supporting coursework",
    equivalent_credit_alternative: "Tied earned-credit alternative; counted once for this course",
    equivalent_gpa_alternative: "Equivalent repeat alternative; one GPA contribution",
    chronology_unknown: "Dates required by the retained repeat-ordering policy are unavailable",
    ordering_policy_unknown: "The retained repeat-ordering choice is unavailable",
    ambiguous_repeat: "Tied repeat attempts have different GPA weights",
    arithmetic_limit: "The calculation reached its supported numeric limit",
    undetermined: "The complete total cannot be established",
    eligible: "Eligible for review",
});

/* Shared presentation of captured evidence; no reads, calculations, storage or academic decisions. */
window.degreeProgressGradeText = function (record, milli) {
    if (record?.grade_state === "retained" && record.retained_grade) {
        const grade = record.retained_grade;
        return `Recorded grade: ${grade.code} · ${milli(grade.points_milli)} grade points${grade.grading_publication_retained ? "" : " · Grading publication not retained"}${record.grading_republication_verified ? " · Unchanged grading re-publication verified" : ""}`;
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

/* Named obligations and their captured attempts; eligibility is separate from complete placement. */
window.createDegreeNamedCourseReview = function (ui, rows, resolve) {
    const { node, button, milli } = ui;
    const detail = node(
        "details",
        { class: "record-section my-3" },
        node("summary", { class: "h5" }, "Named-course review"),
    );
    const reasons = {
        candidate: "Eligible after this group's publication, grade and coursework eligibility checks.",
        requirement_grade_insufficient: "This retained grade does not meet any applicable requirement card’s minimum.",
        requirement_origin_excluded: "No requirement card in this group accepts this work’s recorded origin.",
        origin_excluded: "This group's recorded credit-origin restriction excludes this work.",
        transfer_institution_excluded: "The retained sending institution is not listed for this group.",
        transfer_institution_unknown: "Review the award's retained sending-institution evidence before allocating it.",
        origin_unknown: "The recorded credit origin needed by this group is unavailable.",
        completion_date_unknown: "Record the actual completion date before evaluating this group's date or age rule.",
        pursuit_start_unknown: window.degreeEligibilityLabels.start_review,
        completed_before_pursuit_start: window.degreeEligibilityLabels.before_start,
        evaluation_date_unknown: "Refresh progress with its explicit review date before evaluating expiration.",
        completion_expired: "Completed too early for this group at the retained review date.",
        completion_too_old: "Completed too early for this group's age limit at the recorded pursuit start.",
        completed_before_window: "Completed before this group's permitted window.",
        completed_after_window: "Completed after this group's permitted window.",
        placement_restricted: "An approved preference reserves this course for another group.",
        approval_conflict: "Course approvals conflict; registrar review is needed.",
        voided: "This award was voided.",
        dropped: "Dropped work cannot meet a completed-course requirement.",
        in_progress: "Awaiting a final grade and earned credit.",
        grade_unknown: "The recorded grade definition is unavailable.",
        not_passing: "The recorded grade is not passing.",
        no_credit: "No earned credit is available to apply.",
        course_version_unknown: "The accepted course publication was not retained.",
        course_version_differs: "The accepted course publication needs an equivalence review.",
        scale_unknown: "The retained grading policy needed for the grade minimum is unavailable.",
        scale_differs: "The grading publications need review before comparing the grade minimum.",
        insufficient_grade: "Below this group's required per-course grade points.",
        waived: "This course requirement is waived; no credit is created.",
    };
    let loaded = false;
    detail.addEventListener("toggle", () => {
        if (!detail.open || loaded) return;
        loaded = true;
        detail.append(
            node(
                "p",
                {},
                "Required courses and course options use the retained group definition. Matching attempts explain eligibility; they do not establish a partial placement or predict future credits. Saved-rule requirements are inspected separately.",
            ),
        );
        if (rows == null || !rows.length) {
            detail.append(
                node(
                    "p",
                    {},
                    rows == null
                        ? "Named-course evidence is unavailable for this review."
                        : "This group has no named course list.",
                ),
            );
            return;
        }
        const content = node("div");
        detail.append(content);
        function attempts(row) {
            if (!row.attempts.length) return node("p", {}, "No matching current work in this review.");
            const box = node(
                "details",
                {},
                node(
                    "summary",
                    {},
                    `${row.attempts.length} matching attempt${row.attempts.length === 1 ? "" : "s"}`,
                ),
            );
            let ready = false;
            box.addEventListener("toggle", () => {
                if (!box.open || ready) return;
                ready = true;
                const list = node("div");
                box.append(list);
                function show(offset = 0, focus = false) {
                    const heading = node(
                        "p",
                        { role: "status", tabindex: "-1" },
                        `Matching attempts ${offset + 1}–${Math.min(offset + 10, row.attempts.length)} of ${row.attempts.length}`,
                    );
                    list.replaceChildren(heading);
                    for (const attempt of row.attempts.slice(offset, offset + 10)) {
                        const record = resolve(attempt);
                        if (!record) {
                            list.append(
                                node("p", {}, "Matching record unavailable. Ask the registrar to review it."),
                            );
                            continue;
                        }
                        list.append(
                            node(
                                "div",
                                { class: "border rounded p-2 my-2" },
                                node(
                                    "p",
                                    { class: "fw-semibold mb-1" },
                                    `${record.course_code} · ${record.course_title}`,
                                ),
                                node(
                                    "p",
                                    {},
                                    record.term_label_retained && record.term_code
                                        ? `Posting term: ${record.term_code}`
                                        : "Posting term label not retained",
                                ),
                                node("p", {}, window.degreeProgressGradeText(record, milli)),
                                node(
                                    "p",
                                    {},
                                    record.recorded_earned_milli == null
                                        ? "Recorded earned credits unavailable"
                                        : `Recorded earned credits: ${milli(record.recorded_earned_milli)}`,
                                ),
                                node(
                                    "p",
                                    {},
                                    reasons[attempt.status] || "This attempt needs registrar review.",
                                ),
                                attempt.via_substitution
                                    ? node(
                                          "p",
                                          {},
                                          "Reviewed course/publication approval applies to this requirement.",
                                      )
                                    : null,
                                node(
                                    "p",
                                    {},
                                    attempt.selected === true
                                        ? "Supports this proposed placement, possibly as an alternative attempt. Earned credits and GPA retain their own repeat rules."
                                        : attempt.selected === null
                                          ? "Placement not established."
                                          : "Not selected for this named course in the proposed plan.",
                                ),
                            ),
                        );
                    }
                    const controls = node("div", { class: "d-flex flex-wrap gap-2" });
                    if (offset > 0)
                        controls.append(button("Previous matching attempts", () => show(offset - 10, true)));
                    if (offset + 10 < row.attempts.length)
                        controls.append(button("More matching attempts", () => show(offset + 10, true)));
                    list.append(controls);
                    if (focus) heading.focus();
                }
                show();
            });
            return box;
        }
        function show(offset = 0, focus = false) {
            const heading = node(
                "p",
                { role: "status", tabindex: "-1" },
                `Named courses ${offset + 1}–${Math.min(offset + 20, rows.length)} of ${rows.length}`,
            );
            content.replaceChildren(heading);
            const table = node(
                "table",
                { class: "table table-striped align-middle" },
                node("caption", {}, "Named course requirements and matching recorded work"),
                node(
                    "thead",
                    {},
                    node(
                        "tr",
                        {},
                        ...["Course", "Requirement", "Matching recorded work", "Placement"].map((label) =>
                            node("th", { scope: "col" }, label),
                        ),
                    ),
                ),
            );
            const body = node("tbody");
            for (const row of rows.slice(offset, offset + 20)) {
                body.append(
                    node(
                        "tr",
                        {},
                        node("th", { scope: "row" }, `${row.course_code} · ${row.course_title}`),
                        node(
                            "td",
                            {},
                            row.required ? "Required course" : "Course option",
                            row.waived
                                ? node(
                                      "p",
                                      {},
                                      "Course requirement waived. No earned credit is created and quantity minimums still apply.",
                                  )
                                : null,
                        ),
                        node("td", {}, attempts(row)),
                        node(
                            "td",
                            {},
                            row.placement === "proposed"
                                ? "Evidence in proposed placement"
                                : row.placement === "not_used"
                                  ? "Not used in proposed placement"
                                  : "Placement not established",
                        ),
                    ),
                );
            }
            table.append(body);
            content.append(
                node(
                    "div",
                    {
                        class: "table-responsive",
                        tabindex: "0",
                        role: "region",
                        "aria-label": "Named-course review table",
                    },
                    table,
                ),
            );
            const controls = node("div", { class: "d-flex flex-wrap gap-2" });
            if (offset > 0) controls.append(button("Previous named courses", () => show(offset - 20, true)));
            if (offset + 20 < rows.length)
                controls.append(button("More named courses", () => show(offset + 20, true)));
            content.append(controls);
            if (focus) heading.focus();
        }
        show();
    });
    return detail;
};

/* Captured combined-review explanations; no network calls, storage or academic calculations. */
window.createCurriculumCompetition = function (ui, data, inspect) {
    if (data.status !== "review" || data.outcome !== "infeasible" ||
        (!data.competition?.conflicts?.length && !data.competition?.analysis_limited)) return null;
    const { node, button } = ui;
    const hasConflicts = data.competition.conflicts.length > 0;
    const labels = window.degreeCompetitionLabels;
    const heading = hasConflicts ? "Shared-course shortages" : labels.limit_heading;
    const section = node(
        "section",
        { class: "border rounded-3 p-3 mb-3", "aria-label": heading },
        node("h2", {}, heading),
        node(
            "p",
            {},
            hasConflicts
                ? "These groups compete for eligible work under the retained sharing rules. The figures describe requirement contributions, not additional earned credits or GPA. No partial placements have been assigned."
                : labels.no_bound,
        ),
    );
    if (data.competition.analysis_limited)
        section.append(node("p", { class: "alert alert-info", role: "status" }, labels.limited));
    const credits = (value) => `${value / 1000} credit contribution${value === 1000 ? "" : "s"}`;
    for (const conflict of data.competition.conflicts) {
        const references = conflict.groups.map((reference) => {
            const component = data.components[reference.component],
                group = component.progress.allocation.groups[reference.group];
            const label =
                component.label ||
                (component.kind === "degree"
                    ? "Degree"
                    : `${component.progress.context.specialization.kind || "Component (type not recorded)"} ${component.progress.context.specialization.code}`);
            return { ...reference, label: `${label} · ${group.name}` };
        });
        const card = node(
            "article",
            { class: "mb-3" },
            node("h3", { class: "h5" }, references.map((r) => r.label).join(" / ")),
        );
        if (conflict.credit_shortfall_milli > 0)
            card.append(
                node(
                    "p",
                    {},
                    `Together these groups need ${credits(conflict.required_credits_milli)}. Under their sharing rules, current eligible work can supply at most ${credits(conflict.credit_upper_bound_milli)}. At least ${credits(conflict.credit_shortfall_milli)} more are needed.`,
                ),
            );
        if (conflict.course_shortfall > 0)
            card.append(
                node(
                    "p",
                    {},
                    `Together these groups need at least ${conflict.required_courses} course contributions. Current eligible courses can supply at most ${conflict.course_upper_bound} under their sharing rules. The shortfall is at least ${conflict.course_shortfall} course contribution${conflict.course_shortfall === 1 ? "" : "s"}.`,
                ),
            );
        const links = node("div", { class: "d-flex flex-wrap gap-2" });
        for (const reference of references)
            links.append(button(`Inspect ${reference.label}`, () => inspect(reference.component, reference.group)));
        card.append(links);
        section.append(card);
    }
    if (data.competition.more_conflicts)
        section.append(
            node(
                "p",
                { role: "status" },
                "More shared-course shortages were found. Showing the first 10 explanations.",
            ),
        );
    if (hasConflicts) section.append(
        node(
            "p",
            { class: "form-text" },
            "These are minimum shortages from the available evidence, not a recommended number of new courses or a complete diagnosis. Review eligible alternatives and other requirements with the registrar.",
        ),
    );
    return section;
};


/* Display captured bounds only; never calculate an assignment or future earned credit. */
window.createDegreeRuleSupport = function (ui, mode, gap) {
    if (!gap || mode === "pool") return null;
    const { node, milli } = ui;
    const text =
        mode === "credits"
            ? `At most ${milli(gap.credit_upper_bound_milli)} eligible credits toward this list's ${milli(gap.required_credits_milli)}-credit minimum.${gap.credit_shortfall_milli > 0 ? ` At least ${milli(gap.credit_shortfall_milli)} more eligible credits from this list are needed.` : " This bound does not establish completion; courses may compete for other requirements."}`
            : `Required course contributions: ${gap.required_courses} · ${mode === "all" ? "Required courses" : "Listed options"} with eligible evidence: ${gap.eligible_count}. At most ${gap.course_upper_bound} distinct eligible course contributions can be used.${gap.shortfall_courses > 0 ? ` At least ${gap.shortfall_courses} more distinct eligible course contribution${gap.shortfall_courses === 1 ? " is" : "s are"} needed.` : " Eligible choices may still compete for the same actual course or another group."}`;
    return node("p", {}, text);
};

window.degreeGroupSupportSummary = function (group, outcome) {
    const gaps = ["feasible", "infeasible"].includes(outcome) ? group.gaps : null;
    return !gaps
        ? "Shortfalls cannot be assessed until the retained inputs and rules are verified."
        : gaps.has_shortfall
          ? "Current eligible work falls short of this group's requirements."
          : outcome === "feasible"
            ? "The proposed placement meets this group's supported requirements."
            : "No simple shortage found. Courses must still fit together in a complete placement.";
};

window.createDegreeGroupSupport = function (ui, group, outcome, options = {}) {
    const { node, button, milli } = ui;
    const heading = options.heading || "h2";
    const gaps = ["feasible", "infeasible"].includes(outcome) ? group.gaps : null;
    const summary = window.degreeGroupSupportSummary(group, outcome);
    const section = node(
        "section",
        { class: "border rounded-3 p-3 mb-3", "data-group-support": "" },
        node(heading, {}, "What current work can support"),
        node("p", {}, summary),
    );
    if (!gaps) return section;
    section.append(
        node(
            "p",
            {},
            "These are optimistic bounds on eligible completed evidence, not applied credits or a partial placement. Alternatives, target collisions and sharing can reduce what can actually be placed. In-progress, insufficient-grade, dropped and voided work supply no completed contribution.",
        ),
    );
    if (group.required_credits_milli > 0)
        section.append(
            node(
                "p",
                {},
                `At most ${milli(gaps.credit_upper_bound_milli)} credits toward the ${milli(group.required_credits_milli)}-credit minimum.${gaps.credit_shortfall_milli > 0 ? ` At least ${milli(gaps.credit_shortfall_milli)} more eligible credits are needed.` : " This bound does not establish completion."}`,
            ),
        );
    if (group.required_courses > 0)
        section.append(
            node(
                "p",
                {},
                `At most ${gaps.course_upper_bound} distinct course contributions toward the ${group.required_courses}-course minimum.${gaps.course_shortfall > 0 ? ` At least ${gaps.course_shortfall} more eligible course contributions are needed.` : " This bound does not establish completion."}`,
            ),
        );
    section.append(
        node(
            "p",
            {},
            "Course waivers remove named obligations only. They create no credits, completed courses or reduction to choose counts. Approved quantity reductions are already included in the required minimums above.",
        ),
    );
    if (options.cards === false || outcome === "feasible" || !gaps.rules?.length) return section;
    const details = node("details", {}, node("summary", {}, "Requirement availability"));
    const content = node("div");
    details.append(content);
    section.append(details);
    let loaded = false;
    details.addEventListener("toggle", () => {
        if (!details.open || loaded) return;
        loaded = true;
        function show(offset = 0, focus = false) {
            const label = node(
                "p",
                { role: "status", tabindex: "-1" },
                `Requirements ${offset + 1}–${Math.min(offset + 20, gaps.rules.length)} of ${gaps.rules.length}`,
            );
            content.replaceChildren(label);
            for (const [index, rule] of gaps.rules.slice(offset, offset + 20).entries()) {
                const card = node(
                    "article",
                    { class: "border-top pt-2" },
                    node(
                        "p",
                        { class: "fw-semibold" },
                        `Requirement ${offset + index + 1} · ${rule.mode === "all" ? "All required courses" : rule.mode === "choose" ? "Choose courses" : rule.mode === "credits" ? "Take credits" : "Eligible course pool"}`,
                    ),
                );
                const support = window.createDegreeRuleSupport(ui, rule.mode, rule);
                card.append(
                    support ||
                        node(
                            "p",
                            {},
                            "Eligible pool · No separate minimum on this card. Eligible courses must still fit the group's requirements.",
                        ),
                );
                content.append(card);
            }
            const controls = node("div", { class: "d-flex flex-wrap gap-2" });
            if (offset > 0)
                controls.append(button("Previous availability requirements", () => show(offset - 20, true)));
            if (offset + 20 < gaps.rules.length)
                controls.append(button("More availability requirements", () => show(offset + 20, true)));
            content.append(controls);
            if (focus) label.focus();
        }
        show();
    });
    return section;
};

/* Explain only captured calculation blockers. Positions are local to this response. */
window.degreeProgressCalculationBlockers = function (data) {
  if (data?.status !== "review") return [];
  const definitions = {
    policy_unknown: [
      "Calculation policy not retained",
      "Ask the registrar to review the selected requirements and their retained repeat-credit, repeat-GPA and transfer-GPA choices. Today's defaults cannot replace a missing historical choice.",
    ],
    grade_unknown: [
      "Grade evidence unavailable",
      "Ask the registrar to verify the recorded grade and its retained definition. An unavailable definition is different from awaiting a final grade.",
    ],
    grading_policy_unknown: [
      "Grading publication unknown",
      "Ask the registrar to verify the grading publication for the selected requirements and affected grades. Matching grade labels or maximum points do not establish compatibility.",
    ],
    grading_policy_differs: [
      "Grading publications differ",
      "Ask the registrar to review compatibility with the selected requirements. No grade-scale conversion or replacement of the recorded grade has been assumed.",
    ],
    ordering_policy_unknown: [
      "Repeat ordering not retained",
      "Ask the registrar to verify the retained choice for ordering repeated attempts. A current default or correction timestamp cannot establish the missing choice.",
    ],
    chronology_unknown: [
      "Repeat dates need review",
      data.policy?.attempt_order === "completion_date"
        ? "Ask the registrar to verify actual completion dates for these competing attempts. Posting dates and grade-correction times cannot substitute for completion dates."
        : data.policy?.attempt_order === "academic_period"
          ? "Ask the registrar to verify source academic-period starts for these competing attempts. A local posting-term label cannot substitute for the retained instructional or sending-institution period."
          : "Ask the registrar to verify the retained repeat-ordering choice and the academic-period or completion dates it requires.",
    ],
    ambiguous_repeat: [
      "Repeat weights are ambiguous",
      "Tied eligible attempts have different attempted credits or quality points. Ask the registrar to review the retained repeat policy; no favorable winner has been chosen.",
    ],
    arithmetic_limit: [
      "Calculation limit reached",
      "Ask the registrar for a controlled review of these inputs. The supported numeric limit was reached; this does not mean the academic minimum was failed.",
    ],
  };
  const scopes = {
    credits: ["Earned credits", "credit"],
    institutional: ["Cumulative GPA", "cumulative_gpa"],
    overall: ["Overall GPA", "overall_gpa"],
  };
  const records = data.records || [];
  const result = [];
  for (const [scope, [label, field]] of Object.entries(scopes)) {
    for (const [code, [title, guidance]] of Object.entries(definitions)) {
      const positions = [];
      records.forEach((record, index) => {
        if (record[field] === code) positions.push(index);
      });
      if (
        !positions.length &&
        !(data.issues || []).some(
          (issue) => issue.component === scope && issue.code === code,
        )
      )
        continue;
      result.push({ scope, label, code, title, guidance, positions });
    }
  }
  return result;
};

window.createDegreeProgressBlockers = function (ui, data, headingLevel = 2) {
  const issues = window.degreeProgressCalculationBlockers(data);
  if (!issues.length) return null;
  const { node, button, milli } = ui;
  const section = node(
    "section",
    { class: "audit-preview portal-progress-detail" },
    node(`h${headingLevel}`, {}, "Why calculations need review"),
    node(
      "p",
      {},
      "These checks explain unavailable totals in this captured review. Other independently verified checks keep their own results. No partial total or graduation decision is supplied.",
    ),
  );
  for (const issue of issues) {
    const card = node(
      "article",
      { class: "my-3" },
      node(`h${headingLevel + 1}`, {}, `${issue.label}: ${issue.title}`),
      node("p", {}, issue.guidance),
    );
    if (!issue.positions.length) {
      card.append(
        node(
          "p",
          {},
          "This check concerns the retained policy or calculation as a whole; no individual coursework is identified here.",
        ),
      );
    } else {
      const detail = node(
        "details",
        {},
        node(
          "summary",
          { class: "py-2" },
          `Affected coursework (${issue.positions.length})`,
        ),
      );
      let loaded = false;
      const content = node("div");
      detail.addEventListener("toggle", () => {
        if (!detail.open || loaded) return;
        loaded = true;
        detail.append(content);
        show();
      });
      function show(offset = 0, focus = false) {
        content.replaceChildren();
        const status = node(
          "p",
          { role: "status", tabindex: "-1" },
          `Showing affected records ${offset + 1}–${Math.min(offset + 20, issue.positions.length)} of ${issue.positions.length}.`,
        );
        content.append(status);
        for (const position of issue.positions.slice(offset, offset + 20)) {
          const record = data.records[position];
          content.append(
            node(
              "article",
              { class: "my-3" },
              node(
                `h${headingLevel + 2}`,
                {},
                `${record.course_code} · ${record.course_title}`,
              ),
              node(
                "p",
                {},
                record.term_label_retained && record.term_code
                  ? `Retained posting term: ${record.term_code}`
                  : "Posting term label not retained",
              ),
              node("p", {}, window.degreeProgressGradeText(record, milli)),
              node(
                "p",
                {},
                `Completion date: ${record.completed_on || "Not recorded"}`,
              ),
            ),
          );
        }
        const pages = node("div", { class: "d-flex flex-wrap gap-2 my-2" });
        if (offset)
          pages.append(
            button("Previous affected records", () => show(offset - 20, true)),
          );
        if (offset + 20 < issue.positions.length)
          pages.append(
            button("More affected records", () => show(offset + 20, true)),
          );
        content.append(pages);
        if (focus) status.focus();
      }
      card.append(detail);
    }
    section.append(card);
  }
  return section;
};

/* Shared private, summary-only recorded progress workflow. Drafts and proofs stay in page memory. */
window.createRecordedDegreeProgress = function ({ node, request, buttonClass = "btn-outline-primary", checkHistory = null, labels = {} }, base, allowRecording = false) {
    const element = node("section", { class: "record-section", "aria-label": "Recorded progress reviews" });
    const notice = node("p", { role: "status", "aria-live": "polite" });
    const content = node("div");
    element.append(notice, content);
    let generation = 0, reason = "", review = null;
    const valid = current => current === generation && element.isConnected;
    const button = (label, action) => {
        const b = node("button", { type: "button", class: `btn ${buttonClass} me-2 mb-2` }, label);
        b.addEventListener("click", () => run(action, b));
        return b;
    };
    async function run(action, control) {
        if (control) control.disabled = true;
        try { await action(); }
        catch (error) {
            if (element.isConnected && (!control || control.isConnected)) {
                if ([401, 403].includes(error.status)) {
                    ++generation;
                    reason = "";
                    review = null;
                    content.replaceChildren();
                }
                notice.textContent = error.message || "This request could not be completed. Your draft is retained.";
            }
        }
        finally { if (control?.isConnected) control.disabled = false; }
    }
    const amount = n => n == null ? "Needs review" : String(n / 1000);
    function summary(data) {
        const box = node("section", { "aria-label": "Recorded progress summary" },
            node("h3", {}, "Recorded progress summary"),
            node("p", {}, `Review date: ${data.as_of_date}. ${data.current_record_count} academic records checked.`),
            node("p", {}, data.outcome === "feasible" ? "A complete course-group placement was found." : "A complete course-group placement was not found."),
            node("p", {}, "A progress review does not certify graduation or award a credential. Individual historical coursework is withheld from this summary."));
        const names = { min_credits_milli: "Earned credits", min_resident_milli: "Institutional credits", min_cumulative_gpa_milli: "Cumulative GPA", min_overall_gpa_milli: "Overall GPA" };
        for (const component of data.components) {
            const card = node("section", { class: "mb-3" }, node("h4", {}, component.label),
                node("p", {}, `${component.context.name} · ${component.context.academic_year_code} · Version ${component.context.version}`));
            const checks = node("ul");
            for (const check of component.minimums) {
                const actual = check.actual_minimum_milli == null ? "Needs review" :
                    check.actual_minimum_milli === check.actual_maximum_milli ? amount(check.actual_minimum_milli) : `${amount(check.actual_minimum_milli)}–${amount(check.actual_maximum_milli)}`;
                checks.append(node("li", {}, `${names[check.key] || "Minimum"}: required ${amount(check.required_milli)}; observed ${actual}. ${String(check.status).replaceAll("_", " ")}.`));
            }
            if (component.input_status === "history_gaps") card.append(node("p", {}, "Earlier history was unknown for some work at this review."));
            card.append(checks, node("p", {}, `Cumulative GPA: ${component.gpa.cumulative.ready ? amount(component.gpa.cumulative.milli) : "Needs review"}. Overall GPA: ${component.gpa.overall.ready ? amount(component.gpa.overall.milli) : "Needs review"}.`));
            for (const group of component.groups) {
                if (group.work_eligibility || group.requirement_origins?.length || group.requirement_grade_floors?.length) card.append(node("p", {}, [window.degreeGroupEligibilityText(group.work_eligibility, group.work_eligibility_context, group.work_evaluation_context), window.degreeRequirementPoliciesText(group)].filter(Boolean).join(" ")));
                card.append(node("p", {},
                `${group.name}: ${group.requirement_type === "courses" ? `${group.required_courses} required courses` : `${amount(group.required_credits_milli)} required credits`}. ${group.coverage ? `${group.coverage.applied_courses} courses / ${amount(group.coverage.applied_credits_milli)} credits in the recorded placement.` : "Placement not established."}`));
            }
            box.append(card);
        }
        return box;
    }
    async function page(after = null) {
        const current = ++generation;
        const data = await request(base + "page/", "POST", after ? { after } : {});
        if (!valid(current)) return;
        notice.textContent = "";
        content.replaceChildren(node("h3", {}, "Recorded progress reviews"),
            node("p", {}, "These dated reviews preserve earlier results. Open current degree progress for a fresh calculation."));
        if (allowRecording && data.can_record) content.append(button("Record a new progress review", editor));
        if (!data.items.length) content.append(node("p", {}, "No progress review has been recorded."));
        for (const item of data.items) content.append(node("div", {},
            node("p", {}, `${item.as_of_date} · ${item.component_count} curriculum components · Recorded ${item.recorded_at}`),
            button(`Open review · ${item.as_of_date}`, () => show(item.id))));
        if (after) content.append(button("First page", () => page()));
        if (data.next_after) content.append(button("More recorded reviews", () => page(data.next_after)));
    }
    async function show(id) {
        const current = ++generation;
        const data = await request(base + id + "/");
        if (!valid(current)) return;
        notice.textContent = "";
        content.replaceChildren(node("p", {}, `Recorded ${data.recorded_at}`), summary(data.summary), button("Back to recorded reviews", () => page()));
        if (checkHistory) {
            const check = button(labels.check_progress || "Check recorded progress history", () => checkHistory(id));
            check.prepend(node("i", { class: "bi bi-shield-check me-1", "aria-hidden": "true" }));
            content.append(check);
        }
        const heading = content.querySelector("h3");
        heading.tabIndex = -1;
        heading.focus();
    }
    function editor() {
        ++generation;
        review = null;
        notice.textContent = "";
        const id = `progress-record-reason-${++window.recordedProgressSequence}`;
        const input = node("textarea", { id, class: "form-control", required: true, maxlength: 1000, rows: 3 });
        input.value = reason;
        input.addEventListener("input", () => { reason = input.value; ++generation; });
        const submit = node("button", { type: "submit", class: "btn btn-primary my-3" }, "Review progress to record");
        const form = node("form", {}, node("label", { for: id, class: "form-label" }, "Reason for recording this review"), input, submit,
            button("Back to recorded reviews", () => page()));
        form.addEventListener("submit", event => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            const current = ++generation;
            const submitted = reason;
            run(async () => {
                const data = await request(base + "review/", "POST", { reason: submitted });
                if (!valid(current) || submitted !== reason) return;
                review = data;
                confirmation();
            }, submit);
        });
        content.replaceChildren(form);
        input.focus();
    }
    function confirmation() {
        ++generation;
        content.replaceChildren(summary(review.summary), node("p", {}, `Staff reason: ${review.reason}`),
            button("Revise recorded review", editor),
            button("Confirm recorded progress", async () => {
                const current = ++generation;
                const accepted = await request(base + "confirm/", "POST", {
                    operation_id: review.operation_id, revision: review.revision, reason: review.reason, review_token: review.review_token,
                });
                if (!valid(current)) return;
                notice.textContent = "Progress review recorded.";
                review = null;
                await show(accepted.operation_id);
                notice.textContent = "Progress review recorded.";
            }));
    }
    return { element, start: (id = null) => run(() => id ? show(id) : page()) };
};
window.recordedProgressSequence = 0;
