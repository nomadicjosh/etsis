/* Reasoned restoration of the selected withdrawn attempt, using the shared registration checks. */
window.createEnrollmentReinstatement = function (ui) {
    "use strict";
    const { node, request, task, openDrawer } = ui;
    const button = (label, run, primary = false) =>
        node(
            "button",
            {
                type: "button",
                class: `btn ${primary ? "btn-primary" : "btn-outline-primary"}`,
                onclick: (event) => task(run, event.currentTarget),
            },
            label,
        );
    const guidance = () =>
        node(
            "p",
            {},
            "Reinstatement restores this same attempt and its original ungraded academic credit. Earlier withdrawal decisions and issued documents remain retained. Existing tuition, fee, payment and refund postings stay unchanged; this confirmation creates no new account posting.",
        );
    async function open(student, record, back) {
        const body = openDrawer("Reinstate withdrawn enrollment", "REVIEWED REGISTRAR DECISION");
        const base = `/sis/students/${student}/sections/${record.section_id}/enrollments/${record.enrollment_id}/reinstatement/`;
        const reason = node("textarea", {
            id: "enrollment-reinstatement-reason",
            name: "reason",
            class: "form-control",
            required: true,
            maxlength: 1000,
            rows: 4,
        });
        const error = node("div", { role: "alert", class: "text-danger" });
        const submit = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review reinstatement",
        );
        const form = node(
            "form",
            { class: "record-form", "aria-label": "Withdrawal reinstatement" },
            node(
                "label",
                { for: reason.id, class: "form-label" },
                "Reason for reinstating this withdrawal",
            ),
            reason,
            error,
            submit,
        );
        let generation = 0;
        const edit = () => {
            generation++;
            body.replaceChildren(guidance(), form, button("Back to registration history", back));
            reason.focus();
        };
        function failure(target, e, version) {
            if (!body.isConnected || generation !== version) return;
            if (e.status === 403) {
                body.replaceChildren(
                    node(
                        "p",
                        { role: "alert" },
                        "Access to reinstatement changed. Reopen the task after checking your access.",
                    ),
                );
                return;
            }
            target.textContent =
                e.fields
                    ?.map((field) => field.message)
                    .filter(Boolean)
                    .join(" ") || e.message;
        }
        edit();
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                const version = generation;
                error.textContent = "";
                try {
                    const r = await request(base + "review/", "POST", { reason: reason.value });
                    if (!body.isConnected || generation !== version) return;
                    const outcome = node("div", { role: "alert", class: "text-danger" });
                    const checks = window.createRegistrationSchedule({ node });
                    const confirm = button(
                        "Confirm reinstatement",
                        async () => {
                            try {
                                await request(base + "confirm/", "POST", {
                                    operation_id: r.operation_id,
                                    revision: r.revision,
                                    reason: r.reason,
                                    review_token: r.review_token,
                                });
                                if (!body.isConnected || generation !== version) return;
                                body.replaceChildren(
                                    node(
                                        "p",
                                        { role: "status", class: "alert alert-success" },
                                        "Enrollment reinstated. Original attempt and academic-credit references are preserved.",
                                    ),
                                    button("View registration history", back),
                                );
                            } catch (e) {
                                failure(outcome, e, version);
                            }
                        },
                        true,
                    );
                    confirm.disabled = !r.can_confirm;
                    body.replaceChildren(
                        node(
                            "h3",
                            {},
                            `${r.section.code} · ${r.student.given_name} ${r.student.family_name}`,
                        ),
                        guidance(),
                        node(
                            "p",
                            {},
                            `Withdrawn ${r.withdrawn_at}. Restore ${r.restored_status === "A" ? "staff-registration" : "self-registration"} origin with ${r.attempted_milli / 1000} attempted credits, no final grade and zero earned credit. This takes effect when accepted.`,
                        ),
                        r.registered_credit_restoration
                            ? node("p", {class: "alert alert-info"},
                                `Keep ${r.registered_credit_restoration.registered_milli / 1000} registered credits. Accepted corrections: ${r.registered_credit_restoration.correction_count}. Originally registered: ${r.registered_credit_restoration.original_milli / 1000} credits. This quantity is used for current enrollment load; it does not replace the academic attempted or earned credits. Earlier load determinations remain unchanged.`)
                            : null,
                        !r.section.section_version_id
                            ? node(
                                  "p",
                                  { class: "alert alert-info" },
                                  "An original offering publication was not recorded. The captured offering facts must remain unchanged, and the original course publication is required.",
                              )
                            : null,
                        checks.readiness(r.student.readiness),
                        checks.panel(r.student.schedule),
                        checks.prerequisites(
                            r.student.prerequisites,
                            r.section.prerequisite_courses,
                        ),
                        checks.corequisites(r.student.corequisites, r.section.corequisite_courses),
                        !r.can_confirm
                            ? node(
                                  "p",
                                  { role: "status" },
                                  "Reinstatement is blocked. Resolve eligibility, timetable, companion-course, waitlist-priority or seat issues, then refresh this review.",
                              )
                            : null,
                        node("p", {}, r.reason),
                        node(
                            "p",
                            {},
                            "This review expires in twenty minutes. Retry the same confirmation after an interrupted response to recover its original result.",
                        ),
                        outcome,
                        confirm,
                        button("Revise reason", edit),
                        button("Refresh reinstatement review", () => {
                            edit();
                            form.requestSubmit();
                        }),
                        button("Back to registration history", back),
                    );
                } catch (e) {
                    failure(error, e, version);
                }
            }, submit);
        });
    }
    return { open };
};
