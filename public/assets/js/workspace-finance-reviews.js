/* Office hand-offs share progress; encrypted finance notes stay in the finance workspace. */
window.createWorkspaceFinanceReviews = function (ui) {
    "use strict";
    const {
        node,
        request,
        task,
        table,
        badge,
        buildForm,
        formData,
        openDrawer,
    } = ui;
    const button = (label, callback, style = "btn-outline-primary") =>
        node(
            "button",
            {
                type: "button",
                class: `btn ${style}`,
                onclick: (event) => task(callback, event.currentTarget),
            },
            label,
        );
    const grid = (headers, rows, caption) =>
        node(
            "div",
            {
                class: "table-responsive",
                tabindex: "0",
                role: "region",
                "aria-label": caption,
            },
            table(headers, rows, caption),
        );
    async function handoff(student, value, container) {
        const result = await request(
            `/sis/students/${student}/hiatuses/${value.id}/finance-review/`,
        );
        if (!container.isConnected) return;
        container.replaceChildren(node("h3", {}, "Finance hand-off"));
        if (result.review)
            container.append(
                node(
                    "p",
                    { role: "status" },
                    `Responsible office: Finance. Status: ${result.review.status.replaceAll("_", " ")}.`,
                ),
                node(
                    "p",
                    {},
                    "Review reference: ",
                    node("code", {}, result.review.id),
                ),
                node(
                    "p",
                    { class: "form-text" },
                    "Progress is shared here. Finance notes, balances and posting authority stay in the finance workspace.",
                ),
            );
        else if (!value.voided)
            container.append(
                node(
                    "p",
                    {},
                    "Request a finance review of this academic case. This shares a case reference without private hiatus comments, and does not post a refund or send a message.",
                ),
                button("Request finance review", async () => {
                    await request(
                        `/sis/students/${student}/hiatuses/${value.id}/finance-review/`,
                        "POST",
                        {},
                    );
                    await handoff(student, value, container);
                }),
            );
        else
            container.append(
                node("p", {}, "This voided case has no finance review."),
            );
    }
    async function queue(status = "open", after = "", stack = []) {
        const result = await request("/sis/finance-reviews/", "POST", {
            ...(status ? { status } : {}),
            after,
        });
        const body = openDrawer("Finance review queue", "OFFICE HAND-OFFS");
        const fields = [
            [
                "status",
                "Review status",
                "choice",
                ["open", "in_review", "completed", "cancelled", "all"],
            ],
        ];
        const form = await buildForm(fields, { status: status || "all" });
        const submit = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Filter finance reviews",
        );
        form.append(submit);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(
                () =>
                    queue(
                        form.elements.status.value === "all"
                            ? ""
                            : form.elements.status.value,
                    ),
                submit,
            );
        });
        body.append(
            form,
            result.items.length
                ? grid(
                      ["Student", "ID", "Status", "Requested", "Action"],
                      result.items.map((item) => [
                          `${item.given_name} ${item.family_name}`,
                          item.institutional_id,
                          badge(item.status),
                          item.created_at,
                          button("Review finance task", () => record(item.id)),
                      ]),
                      "Finance review tasks",
                  )
                : node("p", {}, "No reviews match this status."),
        );
        if (stack.length)
            body.append(
                button("Previous finance reviews page", () =>
                    queue(status, stack.at(-1), stack.slice(0, -1)),
                ),
            );
        if (result.next_after)
            body.append(
                button("Next finance reviews page", () =>
                    queue(status, result.next_after, [...stack, after]),
                ),
            );
    }
    async function record(id) {
        const value = await request(`/sis/finance-reviews/${id}/`);
        const body = openDrawer("Financial review", "FINANCE OFFICE");
        body.append(
            button("Back to finance queue", () => queue(), "btn-link"),
            node(
                "p",
                {},
                `${value.student.given_name} ${value.student.family_name} · ${value.student.institutional_id}`,
            ),
            badge(value.status),
            node(
                "p",
                {},
                "Academic case reference: ",
                node("code", {}, value.source.id),
            ),
            node(
                "p",
                {},
                `Effective dates: ${value.source.starts_on} through ${value.source.ends_on || "open-ended"}.`,
            ),
            value.source.voided
                ? node(
                      "p",
                      { class: "alert alert-warning" },
                      "The source academic case is now voided. Review the history before taking further action.",
                  )
                : null,
            node(
                "p",
                { class: "alert alert-info" },
                "This task tracks a financial review. Review the student's accounts and post any authorized adjustments through the financial posting workflow. Completing this task does not create a refund, change charges or send a message.",
            ),
            node(
                "p",
                {},
                value.assigned_to_me
                    ? "Assigned to you."
                    : value.assigned
                      ? "Assigned to another finance reviewer."
                      : "Awaiting a finance reviewer.",
            ),
        );
        if (["open", "in_review"].includes(value.status))
            body.append(
                button(
                    value.assigned_to_me
                        ? "Record review progress / keep assignment"
                        : "Claim finance review",
                    () => change(id, value, "claim"),
                ),
            );
        if (value.status === "in_review" && value.assigned_to_me)
            body.append(
                button(
                    "Complete finance review",
                    () => change(id, value, "complete"),
                    "btn-primary",
                ),
            );
        if (["open", "in_review"].includes(value.status))
            body.append(
                button("Cancel finance review", () =>
                    change(id, value, "cancel"),
                ),
            );
        else
            body.append(
                button("Reopen finance review", () =>
                    change(id, value, "reopen"),
                ),
            );
        const panel = node("section", {
            class: "record-section",
            "aria-label": "Private finance review history",
        });
        body.append(panel);
        await history(id, panel);
    }
    async function change(id, value, action) {
        const operation = await request("/sis/ui/operation-id/");
        const body = openDrawer(
            "Confirm finance review change",
            "PRIVATE FINANCE NOTES",
        );
        const fields = [
            ["note", "Private finance reason / outcome", "textarea"],
        ];
        const form = await buildForm(fields),
            submit = node(
                "button",
                { type: "submit", class: "btn btn-primary" },
                "Save finance review change",
            );
        form.append(submit);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                await request(`/sis/finance-reviews/${id}/change/`, "POST", {
                    ...formData(form, fields),
                    version: value.version,
                    action,
                    operation_id: operation.id,
                });
                await record(id);
            }, submit);
        });
        body.append(
            node(
                "p",
                {},
                `Action: ${action}. The encrypted reason and status history remain available to authorized finance reviewers. Registrars see progress only. After an interrupted response, keep this form to retry the same change.`,
            ),
            action === "complete"
                ? node(
                      "p",
                      { class: "alert alert-info" },
                      "Record the actual review outcome, including references for any separately posted adjustments. This confirmation closes the review task only.",
                  )
                : null,
            form,
            button("Reload finance task", () => record(id), "btn-link"),
        );
    }
    async function history(id, container, after = "", stack = []) {
        const result = await request(
            `/sis/finance-reviews/${id}/history/`,
            "POST",
            { after },
        );
        if (!container.isConnected) return;
        container.replaceChildren(node("h3", {}, "Private finance history"));
        result.items.forEach((item) =>
            container.append(
                node(
                    "article",
                    { class: "record-section" },
                    node(
                        "h4",
                        {},
                        `${item.previous_status.replaceAll("_", " ")} → ${item.status.replaceAll("_", " ")}`,
                    ),
                    node(
                        "p",
                        { class: "form-text" },
                        `${item.created_at} · ${item.actor_name || "Finance reviewer"}`,
                    ),
                    node(
                        "p",
                        { class: "text-break", style: "white-space: pre-wrap" },
                        item.note,
                    ),
                ),
            ),
        );
        if (!result.items.length)
            container.append(
                node("p", {}, "No finance decisions have been recorded yet."),
            );
        if (stack.length)
            container.append(
                button("Previous finance history page", () =>
                    history(id, container, stack.at(-1), stack.slice(0, -1)),
                ),
            );
        if (result.next_after)
            container.append(
                button("Next finance history page", () =>
                    history(id, container, result.next_after, [
                        ...stack,
                        after,
                    ]),
                ),
            );
    }
    return { queue, record, handoff };
};
