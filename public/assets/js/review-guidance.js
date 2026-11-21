/* Captured-review guidance. Fixed text and existing flags only; no requests, storage or decisions. */
(() => {
    "use strict";
    const step = (title, detail, target = null) => ({ title, detail, target });
    const model = (title, steps) => ({ title, steps });
    const unavailable = (title) =>
        model(title, [
            step(
                "Refresh this review",
                "The captured review is incomplete. Reload it before relying on these steps.",
            ),
        ]);
    const registration = (review, planned = false) => {
        const title = "Registration guide";
        const rows = planned ? review?.sections : review?.students;
        if (!Array.isArray(rows) || !rows.length || rows.length > (planned ? 10 : 25))
            return unavailable(title);
        const people = planned ? rows.map((row) => row.student) : rows;
        if (people.some((person) => !person || !Array.isArray(person.issues))) return unavailable(title);
        const steps = [];
        if (people.some((person) => person.enrollment_id && !["A", "N"].includes(person.enrollment_status)))
            steps.push(
                step(
                    "Arrange a closed-attempt review",
                    "A previous registration is closed. An authorized registrar must review that attempt; a new registration must not silently reopen it.",
                ),
            );
        if (people.some((person) => person.enrollment_id && ["A", "N"].includes(person.enrollment_status)))
            steps.push(
                step(
                    "Keep existing registrations",
                    "Existing active registrations are kept. They do not create another charge when this selection is confirmed.",
                ),
            );
        if (
            people.some(
                (person) =>
                    person.issues.length ||
                    person.readiness?.some((check) => !["met", "not_applicable"].includes(check.status)),
            )
        )
            steps.push(
                step(
                    "Resolve registration checks",
                    "Read the listed issues and registration checks. The responsible office must resolve restrictions or missing evidence before a new review.",
                    "checks",
                ),
            );
        if (people.some((person) => person.prerequisites?.status === "missing"))
            steps.push(
                step(
                    "Review prerequisite evidence",
                    "Required prior coursework is missing from this review. Ask the registrar to verify pursuit-scoped evidence or an authorized exception. Selecting the prerequisite now does not make it completed.",
                ),
            );
        if (people.some((person) => person.corequisites?.status === "missing"))
            steps.push(
                step(
                    "Review companion courses",
                    "Check the co-requisite details. The institution’s policy determines whether qualifying prior completion counts or the companion must be registered in the same term.",
                ),
            );
        const schedules = people.map((person) => person.schedule);
        if (review.planned_schedule?.blocked || schedules.some((value) => value?.blocked))
            steps.push(
                step(
                    "Resolve overlapping meetings",
                    "This timetable assessment blocks registration. Review the conflicting published meetings and revise the selection.",
                    "timetable",
                ),
            );
        else if (
            review.planned_schedule?.conflicts?.length ||
            schedules.some((value) => value?.conflict_count)
        )
            steps.push(
                step(
                    "Review timetable warnings",
                    "Institution policy permits the reported overlaps. Review their times with the student before confirming.",
                    "timetable",
                ),
            );
        if (
            review.planned_schedule?.unpublished_count ||
            schedules.some(
                (value) =>
                    !value?.status ||
                    ["not_recorded", "not_fully_checked"].includes(value.status) ||
                    value.unpublished_count,
            )
        )
            steps.push(
                step(
                    "Check unpublished or unavailable schedules",
                    "Some meeting evidence is unavailable or unpublished. A missing schedule is not proof that the student has no timetable conflict.",
                    "timetable",
                ),
            );
        if (people.some((person) => person.charges?.total_minor == null || person.charges?.problems?.length))
            steps.push(
                step(
                    "Resolve pricing first",
                    "The proposed charges need review. Ask the appropriate office to resolve the pricing issues, then refresh the review.",
                    "charges",
                ),
            );
        else
            steps.push(
                step(
                    "Review the new charges",
                    "These amounts are proposed registration charges, not the student’s account balance, aid or payment due. Term-wide fees are counted once in a schedule.",
                    "charges",
                ),
            );
        steps.push(
            step(
                "Review the selection before confirming",
                "Seats are not reserved by this preview. Check the displayed eligibility, capacity and charges; refresh after changes. Confirmation checks the selection again.",
            ),
        );
        return model(title, steps);
    };
    const admissions = (review) => {
        const status = review?.application?.status;
        const steps = [];
        if (status === "submitted") {
            steps.push(
                step(
                    "Read the submitted application",
                    review.submission
                        ? "Review the submitted responses and their retained form revision before deciding."
                        : "No online-form responses are attached. Review the application and supporting information before deciding.",
                ),
            );
            steps.push(
                step(
                    "Review checklist and follow-up",
                    "Open casework to inspect saved checklist readiness and the institution’s acceptance policy. Unfinished edits do not count as saved evidence.",
                    "checklist",
                ),
            );
            steps.push(
                step(
                    "Check supporting documents",
                    "Review available documents and their verification status. A received document is not automatically verified.",
                    "documents",
                ),
            );
            steps.push(
                step(
                    "Record the admissions decision",
                    "Use the existing decision review and give a reason. Acceptance is the institution’s decision; it does not itself create the student record.",
                ),
            );
        } else if (status === "accepted") {
            steps.push(
                step(
                    "Review the admission offer",
                    "Check the current offer, intake and response. Applicant acceptance may be required by the institution’s offer policy; admissions acceptance and applicant acceptance are different steps.",
                    "offer",
                ),
            );
            steps.push(
                step(
                    "Confirm program and entry term",
                    "Review the intended intake before matriculation. A deferment needs the existing entry-term review and any new offer required by policy.",
                ),
            );
            steps.push(
                step(
                    "Review matriculation",
                    "An authorized confirmation creates the student capability under this same person. Pending withdrawals, changed intake or unmet offer policy must be resolved first.",
                ),
            );
        } else if (status === "withdrawn") {
            steps.push(
                step(
                    "Review the withdrawal history",
                    "This closes the application, not an existing student’s enrollment at the institution. An authorized return to review requires a fresh admissions decision.",
                ),
            );
        } else if (status === "rejected") {
            steps.push(
                step(
                    "Review the recorded decision",
                    "Authorized admissions corrections retain the earlier decision and require their own reason and review.",
                ),
            );
        } else if (status === "matriculated") {
            steps.push(
                step(
                    "Continue from the student record",
                    "Matriculation is already recorded. The person remains the parent identity; student/program changes follow their own governed workflows.",
                ),
            );
            steps.push(
                step(
                    "Keep the application history",
                    "Use the application and matriculation history for the original intake and decision. A later program change does not rewrite this application.",
                ),
            );
        } else return unavailable("Admissions guide");
        return model("Admissions guide", steps);
    };
    const meetingPlan = (snapshot, reviewed = false) => {
        const title = "Meeting plan guide";
        const definition = reviewed ? snapshot?.definition : snapshot?.working;
        if (!definition || !Array.isArray(definition.meetings)) return unavailable(title);
        const steps = [];
        if (!reviewed && snapshot.pending_booking_ids?.length)
            steps.push(
                step(
                    "Resolve pending meeting requests",
                    "Approve or cancel the listed requests before reviewing publication. Approval and publication are separate actions.",
                    "pending",
                ),
            );
        const assessment = snapshot.faculty_assessment;
        if (!assessment || assessment.status === "unassigned" || assessment.unassigned_meetings)
            steps.push(
                step(
                    "Review faculty coverage",
                    "Some faculty assignments have not been checked. An unassigned meeting is not proof of an available instructor.",
                    "faculty",
                ),
            );
        if (assessment?.pairs)
            steps.push(
                step(
                    assessment.blocked ? "Resolve faculty overlaps" : "Acknowledge faculty warnings",
                    assessment.blocked
                        ? "The faculty policy blocks publication. Review the other published classes and resolve the schedule."
                        : "The institution permits these overlaps. Review the displayed UTC intervals and acknowledge the warnings before publishing.",
                    "faculty",
                ),
            );
        steps.push(
            step(
                definition.meetings.length ? "Check times and locations" : "Confirm the empty schedule",
                definition.meetings.length
                    ? "Check the displayed timezone, dates, physical locations and room-free online meetings. Working reservation edits need a new publication to change the academic timetable."
                    : "An empty published plan records no synchronous meetings. Confirm that this is intended; it is different from an unpublished schedule.",
                definition.meetings.length ? "meetings" : null,
            ),
        );
        if (!reviewed && snapshot.changed === false)
            steps.push(
                step(
                    "The approved plan already matches",
                    "Use the retained publication or history for its original evidence. A policy change does not rewrite an earlier review.",
                    "published",
                ),
            );
        else
            steps.push(
                step(
                    reviewed ? "Confirm the reviewed plan" : "Review approved changes",
                    reviewed
                        ? "Check the reason and replacement summary before confirming. An expired review or changed schedule or policy requires a fresh review."
                        : "Enter the publication reason after resolving pending requests and blocking conflicts. Review before confirming; publishing does not move bookings or change student registration.",
                ),
            );
        return model(title, steps);
    };
    window.capturedReviewGuidance = Object.freeze({ registration, admissions, meetingPlan });
    window.createReviewGuidance = ({ node }, value, targets = {}) => {
        const details = node("details", { class: "review-guide", "data-review-guide": "" });
        const summary = node(
            "summary",
            {},
            node(
                "span",
                { class: "review-guide-title" },
                node("i", { class: "bi bi-compass", "aria-hidden": "true" }),
                value.title,
            ),
            node("i", { class: "bi bi-chevron-down", "aria-hidden": "true" }),
        );
        const steps = node("ol", { class: "review-guide-steps" });
        for (const item of value.steps) {
            const card = node("li", {}, node("h3", {}, item.title), node("p", {}, item.detail));
            if (item.target && typeof targets[item.target]?.find === "function") {
                const target = targets[item.target];
                const button = node(
                    "button",
                    { type: "button", class: "btn btn-sm btn-outline-primary" },
                    target.label,
                );
                button.addEventListener("click", () => {
                    if (!details.isConnected) return;
                    const element = target.find();
                    if (!element?.isConnected || element.closest("[hidden], [inert]")) return;
                    for (let parent = element.parentElement; parent; parent = parent.parentElement) {
                        if (parent.tagName === "DETAILS") parent.open = true;
                    }
                    element.focus();
                });
                card.append(button);
            }
            steps.append(card);
        }
        details.append(
            summary,
            node(
                "div",
                { class: "review-guide-body" },
                node(
                    "p",
                    { class: "review-guide-caption" },
                    "From the review on this screen. Refresh after records change.",
                ),
                steps,
            ),
        );
        return details;
    };
})();
