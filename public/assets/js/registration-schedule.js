/* Shared, text-only registration readiness, timetable and price review for staff and students. */
window.createRegistrationSchedule = function (ui) {
    "use strict";
    const { node } = ui;
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
                    !student.schedule.blocked &&
                    !student.charges.problems.length &&
                    student.charges.total_minor != null,
            )
        );
    };
    function readiness(checks = []) {
        if (!checks.length) return null;
        const details = node("details", { class: "mb-3" }, node("summary", {}, "Registration checks"));
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
        const details = node("details", { class: "mb-3" }, node("summary", {}, "Required courses"));
        let rendered = false;
        details.addEventListener("toggle", () => {
            if (!details.open || rendered) return;
            rendered = true;
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

    function charges(value, money) {
        return node(
            "section",
            { class: "border rounded p-3 mb-3", "aria-label": "Registration charges" },
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
    function registration(review, money) {
        const student = review.students[0];
        return node(
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
                `Prerequisites: ${{ met: "Verified for this program pursuit", missing: "Required coursework is missing", not_required: "No course prerequisites", not_rechecked: "Not rechecked for this record" }[student.prerequisites.status]}`,
            ),
            prerequisites(student.prerequisites, review.section.prerequisite_courses),
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
                    { class: planned.blocked ? "alert alert-danger" : "alert alert-warning" },
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
                    registration({ section: row.section, students: [row.student] }, money),
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
        return body;
    }
    return { text, panel, charges, registration, prerequisites, readiness, canConfirm, plan };
};

/* The same reviewed confirmation for staff and owned student schedules. Drafts remain in memory. */
window.createRegistrationPlanReview = function (ui) {
    const { node, request, money, open, button, isCurrent = () => true } = ui;
    const presenter = window.createRegistrationSchedule({ node });
    return async function reviewPlan({ student, sections, reviewUrl, commitUrl, onComplete, onRevise }) {
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
            confirm,
            button("Refresh schedule review", () =>
                reviewPlan({ student, sections, reviewUrl, commitUrl, onComplete, onRevise }),
            ),
            button("Revise selection", onRevise),
            node(
                "p",
                { class: "form-text" },
                "If confirmation is interrupted, retry Confirm schedule to check the same request. Previously accepted requests cannot add duplicate registrations or charges.",
            ),
        );
    };
};
