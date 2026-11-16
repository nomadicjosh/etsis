/* Office hand-offs share progress; encrypted finance notes stay in the finance workspace. */
window.createWorkspaceFinanceReviews = function (ui) {
    "use strict";
    const { node, request, task, table, badge, money, buildForm, formData, openDrawer } = ui;
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
        const result = await request(`/sis/students/${student}/hiatuses/${value.id}/finance-review/`);
        if (!container.isConnected) return;
        container.replaceChildren(node("h3", {}, "Finance hand-off"));
        if (result.review)
            container.append(
                node(
                    "p",
                    { role: "status" },
                    `Responsible office: Finance. Status: ${result.review.status.replaceAll("_", " ")}.`,
                ),
                node("p", {}, "Review reference: ", node("code", {}, result.review.id)),
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
                    await request(`/sis/students/${student}/hiatuses/${value.id}/finance-review/`, "POST", {});
                    await handoff(student, value, container);
                }),
            );
        else container.append(node("p", {}, "This voided case has no finance review."));
    }
    async function queue(status = "open", after = "", stack = []) {
        const result = await request("/sis/finance-reviews/", "POST", {
            ...(status ? { status } : {}),
            after,
        });
        const body = openDrawer("Finance review queue", "OFFICE HAND-OFFS");
        const fields = [["status", "Review status", "choice", ["open", "in_review", "completed", "cancelled", "all"]]];
        const form = await buildForm(fields, { status: status || "all" });
        const submit = node("button", { type: "submit", class: "btn btn-primary" }, "Filter finance reviews");
        form.append(submit);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(() => queue(form.elements.status.value === "all" ? "" : form.elements.status.value), submit);
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
            body.append(button("Previous finance reviews page", () => queue(status, stack.at(-1), stack.slice(0, -1))));
        if (result.next_after)
            body.append(button("Next finance reviews page", () => queue(status, result.next_after, [...stack, after])));
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
            node("p", {}, "Academic case reference: ", node("code", {}, value.source.id)),
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
        if (value.can_view_accounts) body.append(button("Review student accounts", () => accounts(id)));
        if (["open", "in_review"].includes(value.status))
            body.append(
                button(value.assigned_to_me ? "Record review progress / keep assignment" : "Claim finance review", () =>
                    change(id, value, "claim"),
                ),
            );
        if (value.status === "in_review" && value.assigned_to_me)
            body.append(button("Complete finance review", () => change(id, value, "complete"), "btn-primary"));
        if (["open", "in_review"].includes(value.status))
            body.append(button("Cancel finance review", () => change(id, value, "cancel")));
        else body.append(button("Reopen finance review", () => change(id, value, "reopen")));
        const panel = node("section", {
            class: "record-section",
            "aria-label": "Private finance review history",
        });
        body.append(panel);
        await history(id, panel);
    }
    async function change(id, value, action) {
        const operation = await request("/sis/ui/operation-id/");
        const body = openDrawer("Confirm finance review change", "PRIVATE FINANCE NOTES");
        const fields = [["note", "Private finance reason / outcome", "textarea"]];
        const form = await buildForm(fields),
            submit = node("button", { type: "submit", class: "btn btn-primary" }, "Save finance review change");
        const editor = node("fieldset", {}, node("legend", { class: "h5" }, "Review outcome"));
        editor.append(...form.children);
        form.append(editor, submit);
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
        submit.disabled = true;
        const selectedPostings =
            action === "complete" && value.can_view_accounts
                ? await postingPicker(id, value.student.id, editor)
                : () => [];
        submit.disabled = false;
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const note = formData(form, fields);
                const postings = selectedPostings();
                editor.disabled = true;
                try {
                    await request(`/sis/finance-reviews/${id}/change/`, "POST", {
                        ...note,
                        version: value.version,
                        action,
                        operation_id: operation.id,
                        ...(postings.length ? { postings } : {}),
                    });
                    await record(id);
                } finally {
                    editor.disabled = false;
                }
            }, submit);
        });
    }
    async function accounts(id, after = "", stack = []) {
        const result = await request(`/sis/finance-reviews/${id}/accounts/`, "POST", { after });
        const body = openDrawer("Student accounts for this review", "FINANCE OFFICE");
        body.append(
            button("Back to finance task", () => record(id)),
            node(
                "p",
                {},
                "Open a statement to review charges, payments, refunds and reversals. Posting remains a separate, authorized action.",
            ),
            result.items.length
                ? grid(
                      ["Billing term", "Currency", "Statement"],
                      result.items.map((item) => [
                          item.term_code,
                          item.currency,
                          button(`Open ${item.term_code} account`, () => ui.account(item.id, () => record(id))),
                      ]),
                      "Student accounts for the finance review",
                  )
                : node("p", {}, "This student has no billing accounts."),
        );
        if (stack.length)
            body.append(button("Previous billing accounts", () => accounts(id, stack.at(-1), stack.slice(0, -1))));
        if (result.next_after)
            body.append(button("Next billing accounts", () => accounts(id, result.next_after, [...stack, after])));
    }
    async function postingPicker(id, student, container) {
        const selected = new Map();
        const panel = node(
            "section",
            { class: "record-section", "aria-label": "Linked account entries" },
            node("h3", { class: "h5" }, "Link existing entries"),
            node(
                "p",
                { class: "form-text" },
                "Optionally link up to ten entries as evidence of the outcome. This does not post money or certify an external settlement. Refresh linked entries if an account changes.",
            ),
        );
        const choices = node("div"),
            entries = node("div"),
            shortlist = node("div", { "aria-live": "polite" });
        const status = node("p", { role: "status", class: "form-text" });
        let busy = false,
            current = null,
            offset = 0;
        const key = (account, operation) => `${account}/${operation}`;
        const describe = (p) =>
            `${p.type.replaceAll("_", " ")} · ${money(p.type === "charge_credit" ? -p.minor : p.minor, p.currency)} · ${p.reversed ? "Reversed" : "Posted"} · ${p.operation_id.slice(-8)}`;
        function disable() {
            panel.querySelectorAll("button, input").forEach((control) => {
                control.disabled = busy;
            });
        }
        async function read(callback) {
            if (busy) return;
            busy = true;
            disable();
            try {
                await callback();
            } finally {
                busy = false;
                if (panel.isConnected) disable();
            }
        }
        function renderSelected() {
            shortlist.replaceChildren(
                node("h4", { class: "h6" }, `${selected.size} of 10 entries linked`),
                ...[...selected].map(([identity, p]) =>
                    node(
                        "p",
                        {},
                        describe(p),
                        " ",
                        button(
                            `Remove ${p.operation_id.slice(-8)} link`,
                            () => {
                                selected.delete(identity);
                                renderSelected();
                                if (current) renderEntries();
                            },
                            "btn-link",
                        ),
                    ),
                ),
            );
            disable();
        }
        function renderEntries() {
            const rows = current.entries.slice(offset, offset + 25).map((item) => {
                const identity = key(current.id, item.operation_id);
                const control = node("input", {
                    type: "checkbox",
                    class: "form-check-input",
                    id: `finance-posting-${item.operation_id}`,
                    checked: selected.has(identity),
                });
                control.addEventListener("change", () => {
                    if (busy) {
                        control.checked = selected.has(identity);
                        return;
                    }
                    if (control.checked && selected.size >= 10 && !selected.has(identity)) {
                        control.checked = false;
                        status.textContent = "Link at most ten entries.";
                        return;
                    }
                    if (control.checked)
                        selected.set(identity, { ...item, account_id: current.id, position: current.position });
                    else selected.delete(identity);
                    renderSelected();
                });
                return [
                    node(
                        "div",
                        { class: "form-check" },
                        control,
                        node("label", { class: "form-check-label", for: control.id }, `Link ${describe(item)}`),
                    ),
                ];
            });
            entries.replaceChildren(
                grid(["Account entry"], rows, "Existing account entries to link"),
                node(
                    "p",
                    { role: "status" },
                    `${current.entries.length ? offset + 1 : 0}–${Math.min(offset + 25, current.entries.length)} of ${current.entries.length} entries`,
                ),
            );
            if (offset)
                entries.append(
                    button("Previous entries to link", () => {
                        offset -= 25;
                        renderEntries();
                    }),
                );
            if (offset + 25 < current.entries.length)
                entries.append(
                    button("Next entries to link", () => {
                        offset += 25;
                        renderEntries();
                    }),
                );
            disable();
        }
        async function statement(account) {
            const data = await request(`/sis/accounts/${account}/`);
            if (data.student_id !== student) throw new Error("Choose an account belonging to this student.");
            return data;
        }
        async function load(after = "", stack = []) {
            await read(async () => {
                const page = await request(`/sis/finance-reviews/${id}/accounts/`, "POST", { after });
                if (!panel.isConnected) return;
                choices.replaceChildren(
                    node("h4", { class: "h6" }, "Choose a billing account"),
                    ...page.items.map((item) =>
                        button(`Show ${item.term_code} · ${item.currency} entries`, () =>
                            read(async () => {
                                const data = await statement(item.id);
                                if (!panel.isConnected) return;
                                current = data;
                                offset = 0;
                                renderEntries();
                                status.textContent = `${item.term_code} · Balance: ${money(data.balance_minor, data.currency)}`;
                            }),
                        ),
                    ),
                );
                if (!page.items.length) choices.append(node("p", {}, "This student has no billing accounts."));
                if (stack.length)
                    choices.append(button("Previous account choices", () => load(stack.at(-1), stack.slice(0, -1))));
                if (page.next_after)
                    choices.append(button("More account choices", () => load(page.next_after, [...stack, after])));
                disable();
            });
        }
        const refresh = button("Refresh linked entries", () =>
            read(async () => {
                const snapshots = new Map();
                for (const account of new Set([...selected.values()].map((p) => p.account_id))) {
                    snapshots.set(account, await statement(account));
                }
                if (!panel.isConnected) return;
                const updated = [];
                for (const [identity, p] of selected) {
                    const data = snapshots.get(p.account_id),
                        entry = data.entries.find((item) => item.operation_id === p.operation_id);
                    if (!entry) throw new Error("A linked entry is unavailable. Reload the finance task.");
                    updated.push([identity, { ...entry, account_id: data.id, position: data.position }]);
                }
                selected.clear();
                updated.forEach(([identity, p]) => selected.set(identity, p));
                if (current && snapshots.has(current.id)) {
                    current = snapshots.get(current.id);
                    renderEntries();
                }
                renderSelected();
                status.textContent = "Linked entries refreshed. Review their current status before completing.";
            }),
        );
        panel.append(choices, status, entries, shortlist, refresh);
        container.append(panel);
        renderSelected();
        await load();
        return () => {
            if (busy) throw new Error("Wait for account statements to finish loading.");
            return [...selected.values()].map((p) => ({
                account_id: p.account_id,
                operation_id: p.operation_id,
                position: p.position,
            }));
        };
    }
    async function history(id, container, after = "", stack = []) {
        const result = await request(`/sis/finance-reviews/${id}/history/`, "POST", { after });
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
                    node("p", { class: "form-text" }, `${item.created_at} · ${item.actor_name || "Finance reviewer"}`),
                    node("p", { class: "text-break", style: "white-space: pre-wrap" }, item.note),
                    ...(item.postings || []).map((p) =>
                        node(
                            "p",
                            {},
                            `${p.type.replaceAll("_", " ")} · ${money(p.type === "charge_credit" ? -p.minor : p.minor, p.currency)} · ${p.reversed ? "Reversed when linked" : "Posted when linked"} · ${p.operation_id.slice(-8)} `,
                            button("Open current statement", () => ui.account(p.account_id, () => record(id))),
                        ),
                    ),
                ),
            ),
        );
        if (!result.items.length) container.append(node("p", {}, "No finance decisions have been recorded yet."));
        if (stack.length)
            container.append(
                button("Previous finance history page", () => history(id, container, stack.at(-1), stack.slice(0, -1))),
            );
        if (result.next_after)
            container.append(
                button("Next finance history page", () => history(id, container, result.next_after, [...stack, after])),
            );
    }
    return { queue, record, handoff };
};
