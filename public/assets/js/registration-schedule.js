/* Shared, text-only registration readiness, timetable and price review for staff and students. */
window.createRegistrationSchedule = function (ui) {
    "use strict";
    const { node } = ui;
    const configElement = typeof document === 'undefined' ? null :
        document.getElementById('workspace-config') || document.getElementById('campus-config');
    const originLabels = ui.prerequisiteOriginLabels ||
        (configElement ? JSON.parse(configElement.textContent).prerequisite_origin_labels : null) || {};
    const originDefaults = { completion_from: 'Completed from', completion_through: 'Completed through', field: 'Qualifying prerequisite coursework', either: 'Institutional or transfer',
        institutional: 'Institutional work only', transfer: 'Transfer work only',
        scope: 'This rule does not change earned credits or decide which work counts toward a degree.' };
    const originText = key => originLabels[key] || originDefaults[key];

    const text = (value) => {
        if (!value?.status || value.status === "not_recorded") return "Timetable check not recorded";
        if (value.status === "not_rechecked") return "Existing enrollment — unchanged";
        if (value.conflict_count)
            return value.blocked ? "Meetings overlap — registration blocked" : "Meetings overlap — warning";
        if (value.unpublished_count) return "Unpublished schedules — not fully checked";
        return "No overlaps in published meetings";
    };
    const panel = (value) =>
        node(
            "section",
            {
                class: `alert ${value?.blocked ? "alert-danger" : value?.conflict_count || value?.unpublished_count ? "alert-warning" : "alert-info"}`,
                role: "status",
                "aria-live": "polite",
                "aria-label": "Registration timetable check",
                "data-timetable-attention": String(Boolean(value?.blocked || value?.conflict_count || value?.unpublished_count)),
            },
            node("h3", { class: "h6" }, text(value)),
            value?.unpublished_count
                ? node(
                      "p",
                      {},
                      `${value.unpublished_count} section ${value.unpublished_count === 1 ? "schedule is" : "schedules are"} unpublished. These meetings could not be checked.`,
                  )
                : null,
            value?.conflicts?.length
                ? node(
                      "ul",
                      {},
                      value.conflicts.map((item) =>
                          node(
                              "li",
                              {},
                              `${item.section_code}: ${item.starts_at.replace("T", " ").replace("Z", " UTC")} through ${item.ends_at.replace("T", " ").replace("Z", " UTC")}`,
                          ),
                      ),
                  )
                : null,
            node(
                "p",
                { class: "small mb-0" },
                "The check uses published meeting plans, including online meetings. Registration checks the timetable again when you confirm.",
            ),
        );
    const canConfirm = (review) => {
        const fresh = review.students.filter((student) => !student.enrollment_id);
        return (
            (!fresh.length || (review.section.active && review.section.seats_remaining >= fresh.length)) &&
            review.students.every(
                (student) =>
                    !student.issues.length &&
                    student.prerequisites.status !== "missing" &&
                    student.corequisites?.status !== "missing" &&
                    !student.schedule.blocked &&
                    !student.charges.problems.length &&
                    student.charges.total_minor != null,
            )
        );
    };
    function readiness(checks = []) {
        if (!checks.length) return null;
        const details = node("details", { class: "mb-3",
            'data-checks-attention': String(checks.some(check => !['met', 'not_applicable'].includes(check.status))),
        }, node("summary", {}, "Registration checks"));
        let rendered = false;
        details.addEventListener("toggle", () => {
            if (!details.open || rendered) return;
            rendered = true;
            details.append(
                node(
                    "ul",
                    {},
                    checks.map((check) =>
                        node(
                            "li",
                            {},
                            node("strong", {}, `${check.label}: `),
                            `${check.status === "met" ? "Passed" : check.status === "not_applicable" ? "Not applicable" : "Needs attention"}${check.status === "met" ? "" : `. ${check.message}`}`,
                            check.next_step ? node("p", { class: "mb-2" }, check.next_step) : null,
                        ),
                    ),
                ),
            );
        });
        return details;
    }

    function prerequisites(value, courses = []) {
        if (!courses.length || !value?.course_statuses?.length) return null;
        const nested = value.group_policy?.rule;
        const details = node("details", { class: "mb-3" }, node("summary", {}, nested ? "Prerequisite rule" : value.group_policy?.required_count < value.group_policy?.total_count ? "Prerequisite options" : "Required courses"));
        if (value.group_policy?.credit_origin) details.append(
            node('p', { class: 'form-text' }, `${originText('field')}: ${originText(value.group_policy.credit_origin)}`),
            node('p', { class: 'form-text' }, originText('scope')));
        if (value.permission) details.append(node("p", { class: "alert alert-info" }, `Registration permitted by a registrar prerequisite exception until ${new Date(value.permission.expires_at).toLocaleString()}. Courses below still show the academic requirements; no completion or credit is awarded by this exception.`));
        let rendered = false;
        details.addEventListener("toggle", () => {
            if (!details.open || rendered) return;
            rendered = true;
            if (value.groups) {
                if (nested) {
                    const tree = branch => branch.requirement != null
                        ? node("span", {}, `Requirement ${branch.requirement + 1} · ${branch.met ? "Met" : "Not met"}`)
                        : node("div", { class: "border-start ps-2 mb-2" }, node("strong", {},
                            `${branch.required_count === branch.children.length ? "Complete all" : branch.required_count === 1 ? "Complete any one" : `Complete at least ${branch.required_count}`} · ${branch.met ? "Met" : "Not met"}`),
                            node("ul", { class: "ps-3" }, ...branch.children.map(child => node("li", {}, tree(child)))));
                    details.append(tree(nested));
                }
                details.append(node("p", { class: "form-text" }, nested ? "Follow the rule above. Unmet alternatives may remain when another route qualifies. Each requirement keeps its course count and grade minimum; only verified coursework allowed for this program pursuit counts." : value.group_policy ? `Meet ${value.group_policy.required_count} of ${value.group_policy.total_count} prerequisite requirements; ${value.group_policy.completed_count} verified. Each requirement retains its own course count and grade minimum. Labels are current; only coursework allowed for this program pursuit counts.` : "Meet each requirement using verified coursework allowed for this program pursuit. Labels are from the current catalog. A course may satisfy more than one prerequisite requirement."),
                    node("ol", {}, ...value.groups.map(group => node("li", {},
                        node("strong", {}, group.met ? "Met: " : nested || value.group_policy?.required_count < value.group_policy?.total_count ? "Not met: " : "Needs attention: "),
                        (group.required_count || 1) > 1 ? `At least ${group.required_count} distinct courses required · ${group.completed_count} verified: ` : "",
                        group.minimum_grade_points_milli != null ? `Minimum ${group.minimum_grade_points_milli / 1000} grade points per counted course · ${group.completed_count} verified: ` : "",
                        group.completion_window ? node('p', { class: 'form-text' },
                            `${originText('completion_from')}: ${group.completion_window.from || '—'} · ${originText('completion_through')}: ${group.completion_window.through || '—'}`) : null,
                        group.credit_origin ? node('p', { class: 'form-text' },
                            `${originText('field')}: ${originText(group.credit_origin)}`) : null,
                        group.positions.map(position => {
                            const course = courses[position];
                            return `${course?.code || "Course label unavailable"}${course?.title ? ` · ${course.title}` : ""}`;
                        }).join((group.required_count || 1) > 1 ? "; " : " OR ")))));
                return;
            }
            details.append(
                node(
                    "p",
                    { class: "form-text" },
                    "Current catalog labels. Checks use verified coursework allowed for this program pursuit.",
                ),
                node(
                    "ul",
                    {},
                    courses.map((course, position) =>
                        node(
                            "li",
                            {},
                            `${course.code || "Course label unavailable"}${course.title ? ` · ${course.title}` : ""}: ${value.course_statuses[position] === "met" ? "Verified completed coursework" : "Passing coursework not verified for this pursuit"}`,
                        ),
                    ),
                ),
            );
        });
        return details;
    }

    function corequisites(value, courses = []) {
        if (!courses.length || !value?.courses) return null;
        const meanings = { completed: "Satisfied by qualifying completed coursework — no new registration or charge",
            concurrent: "Supported by a current registration in this term", planned: "Included in this selected schedule",
            coordinated: "Registered together", missing: "Select a section of this course for the same term" };
        return node("section", { class: "border rounded p-3 my-3", "aria-label": "Co-requisite readiness" },
            node("h4", { class: "h6" }, "Co-requisites"),
            node("p", { class: "form-text" }, value.prior_completion ?
                "Qualifying completion in this program pursuit can satisfy these requirements." : "These courses must be taken in the same academic term."),
            node("ul", { class: "mb-0" }, ...courses.map((course, index) => node("li", {},
                `${course.code || "Course"}${course.title ? ` · ${course.title}` : ""}: ${meanings[value.courses[index]] || "Not checked"}`))));
    }
    function charges(value, money) {
        return node(
            "section",
            { class: "border rounded p-3 mb-3", "aria-label": "Registration charges",
                'data-charges-attention': String(value.total_minor == null || value.problems.length > 0) },
            node("h3", { class: "h6" }, "New registration charges"),
            node(
                "p",
                { class: "fw-semibold" },
                value.total_minor == null ? "Pricing needs review" : money(value.total_minor, value.currency),
            ),
            value.items.length
                ? node(
                      "ul",
                      { class: "mb-2" },
                      value.items.map((item) =>
                          node(
                              "li",
                              {},
                              `${item.name} · ${money(item.minor, item.currency)}${item.basis === "registered_credit" ? ` · ${item.credits_milli / 1000} credits at ${money(item.rate_minor, item.currency)} per credit` : ""} · ${item.minor === 0 ? "No charge" : item.status === "new" ? "Added on confirmation" : item.status === "included_in_schedule" ? "Included once in this schedule" : "Not added with this registration"}`,
                          ),
                      ),
                  )
                : node("p", {}, "No new charges for this registration."),
            value.problems.length
                ? node(
                      "ul",
                      { class: "text-danger" },
                      value.problems.map((problem) => node("li", {}, problem)),
                  )
                : null,
            node(
                "p",
                { class: "small mb-0" },
                "These are the charges created by this registration, not an account balance, aid award or payment request.",
            ),
        );
    }
    function registration(review, money, { guide = true } = {}) {
        const student = review.students[0];
        const body = node(
            "div",
            {},
            node("h3", { class: "h5" }, `${review.section.course_code} · ${review.section.course_title}`),
            node(
                "p",
                {},
                `Section ${review.section.code} · ${review.section.credits_milli / 1000} credits · ${review.section.seats_remaining} seats remaining`,
            ),
            node(
                "p",
                {},
                `Academic term: ${review.section.term_starts_on} through ${review.section.term_ends_on}`,
            ),
            review.section.registration_window
                ? node(
                      "p",
                      { class: "form-text" },
                      `Registration window: ${new Date(review.section.registration_window.opens_at).toLocaleString()} through ${new Date(review.section.registration_window.closes_at).toLocaleString()}. ` +
                          `Maximum ${review.section.registration_window.course_limit} courses. ` +
                          (review.section.registration_window.published
                              ? "Published policy verified."
                              : "Earlier setup; no publication recorded."),
                  )
                : null,
            typeof review.section.web_registration === "boolean"
                ? node(
                      "p",
                      { class: "form-text" },
                      `Student self-registration: ${review.section.web_registration ? "Allowed" : "Staff registration only"}. ` +
                          (review.section.self_registration_recorded
                              ? "Retained in this offering publication."
                              : "Not recorded in the earlier publication; current setup applies."),
                  )
                : null,
            ["A", "N"].includes(student.enrollment_status)
                ? node(
                      "p",
                      { class: "alert alert-info" },
                      "This registration is already recorded. Confirming keeps it unchanged and creates no new charges.",
                  )
                : null,
            node(
                "p",
                {},
                `Prerequisites: ${{ permission: "Prerequisite exception approved", met: "Verified for this program pursuit", missing: "Required coursework is missing", not_required: "No course prerequisites", not_rechecked: "Not rechecked for this record" }[student.prerequisites.status]}`,
            ),
            prerequisites(student.prerequisites, review.section.prerequisite_courses),
            corequisites(student.corequisites, review.section.corequisite_courses),
            readiness(student.readiness),
            student.issues.length
                ? node(
                      "ul",
                      { class: "alert alert-warning" },
                      student.issues.map((issue) => node("li", {}, issue)),
                  )
                : null,
            !review.section.active && !student.enrollment_id
                ? node("p", { class: "alert alert-warning" }, "This course or section is inactive.")
                : null,
            review.section.seats_remaining < 1 && !student.enrollment_id
                ? node("p", { class: "alert alert-warning" }, "No seats remain in this section.")
                : null,
            panel(student.schedule),
            charges(student.charges, money),
            node(
                "p",
                { class: "form-text" },
                "Review expires after 20 minutes. Changes to pricing, eligibility or the selected academic definitions require another review. Confirmation saves registration, academic credit and applicable charges together.",
            ),
        );
        if (guide) body.append(guidance(review, false, body));
        return body;
    }
    function guidance(review, planned, body) {
        return window.createReviewGuidance({ node }, window.capturedReviewGuidance.registration(review, planned), {
            checks: { label: 'Show registration checks', find: () => {
                const summary = body.querySelector('[data-checks-attention="true"] > summary')
                    || [...body.querySelectorAll('summary')].find(item => item.textContent === 'Registration checks');
                return summary || null;
            } },
            timetable: { label: 'Show timetable details', find: () => {
                const target = body.querySelector('[data-schedule-conflicts]')
                    || body.querySelector('[data-timetable-attention="true"]')
                    || body.querySelector('[aria-label="Registration timetable check"]')
                    || body.querySelector('[aria-label="Reviewed registration group"]');
                if (target) target.tabIndex = -1;
                return target;
            } },
            charges: { label: 'Show proposed charges', find: () => {
                const target = body.querySelector('[data-charges-attention="true"]')
                    || body.querySelector('[data-charge-review]')
                    || body.querySelector('[aria-label="Registration charges"]');
                if (target?.tagName === 'DETAILS') return target.querySelector('summary');
                if (target) target.tabIndex = -1;
                return target;
            } },
        });
    }
    function plan(review, money) {
        const body = node(
            "div",
            {},
            node("h3", { class: "h5" }, "Review your selected schedule"),
            node(
                "p",
                {},
                "Seats are not reserved until confirmation. All selected registrations and charges save together.",
            ),
        );
        const person = review.sections[0]?.student;
        if (person?.given_name || person?.family_name)
            body.append(
                node(
                    "p",
                    { class: "fw-semibold" },
                    [person.given_name, person.family_name, person.institutional_id]
                        .filter(Boolean)
                        .join(" · "),
                ),
            );
        if (review.issues.length)
            body.append(
                node(
                    "ul",
                    { class: "alert alert-warning" },
                    review.issues.map((issue) => node("li", {}, issue)),
                ),
            );
        const planned = review.planned_schedule;
        if (planned.unpublished_count)
            body.append(
                node(
                    "p",
                    { class: "alert alert-info" },
                    "Some selected sections have unpublished meeting schedules and cannot be fully checked.",
                ),
            );
        if (planned.conflicts.length)
            body.append(
                node(
                    "ul",
                    { class: planned.blocked ? "alert alert-danger" : "alert alert-warning",
                        'data-schedule-conflicts': '', 'aria-label': 'Proposed schedule overlaps' },
                    planned.conflicts.map((item) =>
                        node(
                            "li",
                            {},
                            `${item.section_code} overlaps ${item.other_section_code}. ${planned.blocked ? "Choose another section." : "Institution policy permits registration with this warning."}`,
                        ),
                    ),
                ),
            );
        for (const row of review.sections) {
            body.append(
                node(
                    "details",
                    { class: "border rounded p-3 mb-3" },
                    node(
                        "summary",
                        {},
                        `${row.section.course_code} · ${row.section.code} · ${row.student.charges.total_minor === null ? "Pricing needs review" : money(row.student.charges.total_minor, row.student.charges.currency)}`,
                    ),
                    registration({ section: row.section, students: [row.student] }, money, { guide: false }),
                ),
            );
        }
        body.append(
            node("h4", { class: "h6" }, "New charges for this schedule"),
            ...Object.entries(review.totals).map(([currency, minor]) =>
                node(
                    "p",
                    {},
                    minor === null ? `${currency}: total needs pricing review` : money(minor, currency),
                ),
            ),
            node(
                "p",
                { class: "form-text" },
                "Term-wide fees are counted once. Each section and registered-credit price is included separately. These totals do not show your account balance. This review expires after 20 minutes.",
            ),
        );
        body.append(guidance(review, true, body));
        return body;
    }
    return { text, panel, charges, registration, prerequisites, corequisites, readiness, canConfirm, plan, guidance };
};

/* The same reviewed confirmation for staff and owned student schedules. Drafts remain in memory. */
window.createRegistrationPlanReview = function (ui) {
    const { node, request, money, open, button, isCurrent = () => true } = ui;
    const presenter = window.createRegistrationSchedule({ node });
    return async function reviewPlan({
        student,
        sections,
        reviewUrl,
        commitUrl,
        onComplete,
        onRevise,
    }) {
        const command = { student_id: student, section_ids: [...sections] };
        const body = open("Review schedule");
        body.append(
            node("p", { role: "status" }, "Checking eligibility, meetings and charges…"),
            button("Revise selection", onRevise),
        );
        const review = await request(reviewUrl, "POST", command);
        if (!body.isConnected || !isCurrent()) return;
        body.replaceChildren(presenter.plan(review, money));
        const confirm = button(
            "Confirm schedule",
            async () => {
                const result = await request(commitUrl, "POST", {
                    ...command,
                    operation_id: review.operation_id,
                    revision: review.revision,
                    review_token: review.review_token,
                });
                if (body.isConnected && isCurrent()) await onComplete(result);
            },
            true,
        );
        confirm.disabled = !review.can_confirm;
        body.append(
            node(
                "div",
                { class: "d-flex flex-wrap gap-2 my-3" },
                confirm,
                button("Refresh schedule review", () =>
                    reviewPlan({ student, sections, reviewUrl, commitUrl, onComplete, onRevise }),
                ),
                button("Revise selection", onRevise),
            ),
            node(
                "p",
                { class: "form-text" },
                "If confirmation is interrupted, retry Confirm schedule to check the same request. Previously accepted requests cannot add duplicate registrations or charges.",
            ),
        );
    };
};

/* Shared add/drop section-change flow. Only the selected target is assessed; no browser history storage. */
window.createSectionChange = function (ui) {
    "use strict";
    const { node, request, money, button, open, isCurrent = () => true } = ui;
    const presenter = window.createRegistrationSchedule({ node });
    return async function start({ student, from, baseUrl, onComplete, onBack = null }) {
        const selection = { student_id: student, from_section_id: from };
        let searchValue = "";
        async function choose(after = null) {
            const body = open("Change section");
            const busy = node(
                "p",
                { role: "status" },
                "Finding sections of the same course and term…",
            );
            body.append(busy);
            const page = await request(`${baseUrl}choices/`, "POST", {
                ...selection,
                search: searchValue,
                after,
            });
            if (!body.isConnected || !isCurrent()) return;
            const inputId = `section-change-search-${from}`;
            const input = node("input", {
                id: inputId,
                type: "search",
                class: "form-control",
                maxlength: "80",
                value: searchValue,
            });
            const form = node(
                "form",
                { class: "record-form-section mb-3" },
                node("label", { for: inputId, class: "form-label" }, "Find a section"),
                input,
            );
            form.append(
                node(
                    "div",
                    { class: "d-flex flex-wrap gap-2 mt-3" },
                    button("Search", async () => {
                        searchValue = input.value.trim();
                        await choose();
                    }),
                ),
            );
            form.addEventListener("submit", (event) => {
                event.preventDefault();
                form.querySelector("button").click();
            });
            const list = node(
                "div",
                { class: "d-grid gap-2", "aria-label": "Replacement sections" },
                page.items.map((item) =>
                    node(
                        "div",
                        { class: "border rounded p-3" },
                        node(
                            "p",
                            { class: "mb-2" },
                            `${item.code} · Capacity ${item.capacity} · Section fee ${money(item.fee_minor, item.currency)}`,
                        ),
                        button(`Review ${item.code}`, () => review(item.id)),
                    ),
                ),
            );
            body.replaceChildren(
                node(
                    "p",
                    {},
                    `Current section: ${page.from_code}. Add/drop ends ${page.grace_ends_on}.`,
                ),
                node(
                    "p",
                    {},
                    "Review a replacement before changing anything. Eligibility, seats, published meetings and charges are checked together.",
                ),
                form,
                list,
            );
            if (!page.items.length)
                body.append(
                    node(
                        "p",
                        { role: "status" },
                        "No matching sections. Try another search or contact the registrar.",
                    ),
                );
            if (page.next_after)
                body.append(button("Next sections", () => choose(page.next_after)));
            if (after) body.append(button("First page", () => choose()));
            if (onBack) body.append(button("Back", onBack));
        }
        async function review(to) {
            const body = open("Review section change");
            body.append(
                node(
                    "p",
                    { role: "status" },
                    "Checking the replacement and original charge reversal…",
                ),
                button("Choose another section", () => choose()),
            );
            const command = { ...selection, to_section_id: to };
            const result = await request(`${baseUrl}review/`, "POST", command);
            if (!body.isConnected || !isCurrent()) return;
            let completed = false;
            const pricing = result.financial;
            body.replaceChildren(
                node(
                    "p",
                    {},
                    `From ${result.from.code} to ${result.target.section.code}. Effective ${result.from.effective_on}.`,
                ),
                presenter.registration(result.target, money),
                node(
                    "div",
                    { class: "alert alert-info" },
                    node(
                        "p",
                        {},
                        `Original course-charge credit: ${money(pricing.credit_minor, pricing.currency)}`,
                    ),
                    node(
                        "p",
                        {},
                        `Replacement charges: ${pricing.new_charge_minor === null ? "Needs review" : money(pricing.new_charge_minor, pricing.currency)}`,
                    ),
                    node(
                        "p",
                        {},
                        `Account change: ${pricing.net_change_minor === null ? "Needs review" : money(pricing.net_change_minor, pricing.currency)}`,
                    ),
                ),
                node(
                    "p",
                    {},
                    "One confirmation removes the original current registration and academic credit, reverses its course charges, and registers the replacement. Term-wide fees stay in place. Restricted history is retained. Any account credit is reviewed separately for a cash refund.",
                ),
            );
            const confirm = button(
                "Confirm section change",
                async () => {
                    if (completed) return;
                    const receipt = await request(`${baseUrl}commit/`, "POST", {
                        ...command,
                        operation_id: result.operation_id,
                        revision: result.revision,
                        review_token: result.review_token,
                    });
                    completed = true;
                    if (body.isConnected && isCurrent()) await onComplete(receipt);
                },
                true,
            );
            confirm.disabled = !result.can_confirm;
            body.append(
                node(
                    "div",
                    { class: "d-flex flex-wrap gap-2 my-3" },
                    confirm,
                    button("Refresh review", () => review(to)),
                    button("Choose another section", () => choose()),
                ),
                node(
                    "p",
                    { class: "form-text" },
                    "If the response is interrupted, retry the same confirmation. An accepted request returns its original result and cannot repeat the section change or its charges.",
                ),
            );
        }
        await choose();
    };
};
