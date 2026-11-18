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
                              `${item.name} · ${money(item.minor, item.currency)}${item.basis === "registered_credit" ? ` · ${item.credits_milli / 1000} credits at ${money(item.rate_minor, item.currency)} per credit` : ""} · ${item.minor === 0 ? "No charge" : item.status === "new" ? "Added on confirmation" : "Not added with this registration"}`,
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
    return { text, panel, charges, registration, canConfirm };
};
