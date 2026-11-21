/* Reviewed class-wide grade observations. Drafts stay in this drawer's memory. */
window.createGradeCertification = function (ui) {
    "use strict";
    const { node, request, task, can, table, openDrawer } = ui;
    const action = (label, run, primary = false) =>
        node(
            "button",
            {
                type: "button",
                class: `btn ${primary ? "btn-primary" : "btn-outline-primary"}`,
                onclick: (event) => {
                    const control = event.currentTarget;
                    if (control.dataset.blocked) return;
                    return task(run, control).finally(() => {
                        if (control.dataset.blocked) control.disabled = true;
                    });
                },
            },
            label,
        );
    const rows = (data) =>
        node(
            "div",
            {
                class: "table-responsive",
                tabindex: "0",
                role: "region",
                "aria-label": "Class final grades",
            },
            table(
                [
                    "Student (current identity)",
                    "Institutional ID (current)",
                    "Recorded course",
                    "Registration",
                    "Final grade",
                    "Readiness",
                ],
                data.rows.map((row) => [
                    row.current_name,
                    row.current_institutional_id,
                    `${row.course_code} · ${row.course_title}`,
                    { A: "Staff registered", N: "Self registered", W: "Withdrawn" }[row.status] ||
                        row.status,
                    row.grade_code || "Missing",
                    {
                        unrecorded_history: "Retained history is missing",
                        pending_grade_change: "Grade correction pending",
                        missing_final_grade: "Final grade needed",
                    }[data.issues?.find((issue) => issue.student_id === row.student_id)?.status] ||
                        "Posted",
                ]),
                "Class final grades",
            ),
        );
    const scope = () =>
        node(
            "p",
            { class: "form-text" },
            "Includes every current enrolled or withdrawn student, up to fifty. Early drops and cancelled registrations are excluded. Certification retains this review; later grade corrections remain available. Names and institutional IDs shown here are current values.",
        );
    async function open(section, back) {
        const body = openDrawer("Class grade certification");
        body.append(node("p", { role: "status" }, "Checking class grades…"));
        const base = `/sis/sections/${section.id}/grade-certification/`;
        const data = await request(base);
        if (!body.isConnected) return;
        const reason = node("textarea", {
            id: "certification-reason",
            class: "form-control",
            maxlength: 1000,
            required: true,
            rows: 3,
        });
        const ack = node("input", {
            id: "certification-gaps",
            type: "checkbox",
            class: "form-check-input",
        });
        const error = node("div", { role: "alert", class: "text-danger" });
        const history = action("Certification history", () =>
            listing(section, () => open(section, back)),
        );
        const form = node(
            "form",
            { class: "record-form", "aria-label": "Certify class grades" },
            node("label", { for: reason.id, class: "form-label" }, "Certification reason"),
            reason,
            data.history_gaps
                ? node(
                      "div",
                      { class: "form-check" },
                      ack,
                      node(
                          "label",
                          { for: ack.id, class: "form-check-label" },
                          "I acknowledge that earlier course, offering or grading history is incomplete. This certification covers the recorded facts shown here.",
                      ),
                  )
                : null,
            error,
        );
        const submit = node(
            "button",
            { type: "submit", class: "btn btn-primary", disabled: !data.ready },
            "Review certification",
        );
        form.append(submit);
        const edit = () => {
            body.replaceChildren(
                node("h3", {}, `${data.section.code} · ${data.section.term_code}`),
                scope(),
                node(
                    "p",
                    {
                        role: "status",
                        class: data.ready ? "alert alert-info" : "alert alert-warning",
                    },
                    data.ready
                        ? "Every covered student has a retained final grade."
                        : "Certification is blocked. Resolve missing grades, unrecorded history or pending corrections. An empty class cannot be certified.",
                ),
                data.latest
                    ? node(
                          "p",
                          { role: "status" },
                          data.latest.matches_current === true
                              ? `Certification ${data.latest.version} matches the current recorded grades and roster.`
                              : "The previous certification remains retained. Current grades or the roster have changed, or readiness is incomplete.",
                      )
                    : null,
                rows(data),
                form,
                history,
                action("Back to section", back),
            );
        };
        edit();
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                error.textContent = "";
                try {
                    const review = await request(`${base}review/`, "POST", {
                        reason: reason.value,
                        acknowledged_history_gaps: ack.checked,
                    });
                    if (!body.isConnected) return;
                    const confirmation = node("div", { role: "alert", class: "text-danger" });
                    const revise = action("Revise certification", edit);
                    const confirm = action(
                        "Confirm certification",
                        async () => {
                            confirmation.textContent = "";
                            revise.disabled = true;
                            try {
                                await request(`${base}confirm/`, "POST", {
                                    operation_id: review.operation_id,
                                    revision: review.revision,
                                    expected_version: review.expected_version,
                                    reason: review.reason,
                                    acknowledged_history_gaps: review.acknowledged_history_gaps,
                                    review_token: review.review_token,
                                });
                                if (!body.isConnected) return;
                                body.replaceChildren(
                                    node(
                                        "p",
                                        { role: "status", class: "alert alert-success" },
                                        "Class grade certification recorded.",
                                    ),
                                    action("Refresh readiness", () => open(section, back)),
                                    action("Certification history", () =>
                                        listing(section, () => open(section, back)),
                                    ),
                                    action("Back to section", back),
                                );
                            } catch (failure) {
                                if (!body.isConnected) return;
                                confirmation.textContent = failure.message;
                                if ([409, 422].includes(failure.status)) {
                                    confirm.dataset.blocked = "true";
                                    confirm.disabled = true;
                                    confirmation.append(
                                        action("Refresh readiness and keep reason", async () => {
                                            const draft = reason.value;
                                            await open(section, back);
                                            const current =
                                                document.getElementById("certification-reason");
                                            if (current) current.value = draft;
                                        }),
                                    );
                                }
                            } finally {
                                revise.disabled = false;
                            }
                        },
                        true,
                    );
                    body.replaceChildren(
                        node("h3", {}, "Review class grade certification"),
                        scope(),
                        rows(review),
                        node("p", {}, `Reason: ${review.reason}`),
                        review.history_gaps
                            ? node("p", {}, "Earlier-history gaps acknowledged.")
                            : null,
                        node(
                            "p",
                            {},
                            "This review expires after twenty minutes. Changed grades or roster require a new review.",
                        ),
                        confirmation,
                        confirm,
                        revise,
                    );
                    confirm.focus();
                } catch (failure) {
                    if (form.isConnected) error.textContent = failure.message;
                }
            }, submit);
        });
    }
    async function listing(section, back, before = 0, previous = []) {
        const body = openDrawer("Class grade certification history");
        body.append(node("p", { role: "status" }, "Loading certification history…"));
        const page = await request(
            `/sis/sections/${section.id}/grade-certification/history/${before ? `?before=${before}` : ""}`,
        );
        if (!body.isConnected) return;
        body.replaceChildren(
            node(
                "p",
                { class: "form-text" },
                "Each certification is a retained review. Later roster or grade changes do not rewrite it. Archived roster details require student-record and audit access.",
            ),
            ...page.items.map((item) =>
                node(
                    "section",
                    { class: "record-section", "aria-label": `Certification ${item.version}` },
                    node("h3", { class: "h6" }, `Certification ${item.version}`),
                    node(
                        "p",
                        {},
                        `${item.recorded_at} · ${item.student_count} students${item.history_gaps ? " · Earlier-history gaps acknowledged" : ""}`,
                    ),
                    can("sis:audit:read") && can("sis:student:read")
                        ? action("Open retained roster", () =>
                              detail(section, item.operation_id, () =>
                                  listing(section, back, before, previous),
                              ),
                          )
                        : null,
                ),
            ),
            !page.items.length
                ? node("p", {}, "No class certifications have been recorded.")
                : null,
            previous.length
                ? action("Previous page", () =>
                      listing(section, back, previous.at(-1), previous.slice(0, -1)),
                  )
                : null,
            page.next_before
                ? action("Next page", () =>
                      listing(section, back, page.next_before, [...previous, before]),
                  )
                : null,
            action("Back to readiness", back),
        );
    }
    async function detail(section, operation, back) {
        const body = openDrawer("Retained class certification");
        body.append(node("p", { role: "status" }, "Loading retained roster…"));
        const result = await request(
            `/sis/sections/${section.id}/grade-certification/history/${operation}/`,
        );
        if (!body.isConnected) return;
        body.replaceChildren(
            node("h3", {}, `${result.section.code} · Certification ${result.version}`),
            node("p", {}, `Observed ${result.recorded_at}. Reason: ${result.reason}`),
            node(
                "p",
                { class: "form-text" },
                "Grades and course labels are retained from the reviewed record. Student names and institutional IDs are current values; this roster may include later early drops.",
            ),
            rows(result),
            action("Back to certification history", back),
        );
    }
    return { open };
};
