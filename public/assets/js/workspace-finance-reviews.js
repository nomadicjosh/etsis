/* Office hand-offs share progress; encrypted finance notes stay in the finance workspace. */
window.createWorkspaceFinanceReviews = function (ui) {
    "use strict";
    const {
        node,
        request: transport,
        task,
        badge,
        money,
        buildForm,
        formData,
        openDrawer,
        recordSummary,
        actionMenu,
    } = ui;
    let activeBody = null;
    async function privateRequest(scope, ...args) {
        try {
            return await transport(...args);
        } catch (error) {
            if (error.status === 403 && scope?.isConnected)
                scope.replaceChildren(
                    node(
                        "p",
                        { role: "status" },
                        "This finance review is no longer available with your current access.",
                    ),
                );
            throw error;
        }
    }
    const request = (...args) => privateRequest(activeBody, ...args);
    function openPanel(title, label) {
        const surface = openDrawer(title, label);
        const body = node(
            "div",
            { class: "finance-review-panel" },
            node("p", { role: "status", class: "form-text" }, "Loading finance review…"),
        );
        activeBody = body;
        surface.append(body);
        return body;
    }
    const list = (label, rows) =>
        node("ul", { class: "finance-record-list", "aria-label": label }, rows);
    const card = (title, ...content) =>
        node(
            "li",
            {},
            node(
                "article",
                { class: "surface finance-record-card" },
                node("h3", { class: "h6" }, title),
                ...content,
            ),
        );
    const statusLabel = (value) => value.replaceAll("_", " ");
    async function pages(
        container,
        label,
        initial,
        read,
        render,
        remember = () => {},
        extras = [],
        ready = () => {},
    ) {
        let state = { after: "", stack: [], ...initial },
            nextAfter = null,
            busy = false;
        const content = node("div"),
            status = node("p", { role: "status", class: "form-text", tabindex: "-1" }, "Loading…");
        const control = (label, run) =>
            node(
                "button",
                {
                    type: "button",
                    class: "btn btn-outline-primary",
                    onclick: () => {
                        if (!busy) task(run);
                    },
                },
                label,
            );
        const previous = control(`Previous ${label}`, () =>
            load({ ...state, after: state.stack.at(-1), stack: state.stack.slice(0, -1) }, true),
        );
        const next = control(`Next ${label}`, () =>
            load({ ...state, after: nextAfter, stack: [...state.stack, state.after] }, true),
        );
        const refresh = control(`Refresh ${label}`, () => load(state, true));
        container.append(
            status,
            node(
                "nav",
                { class: "inline-actions mb-3", "aria-label": `${label} pages` },
                previous,
                next,
                refresh,
            ),
            content,
        );
        function controls(pending) {
            busy = pending;
            previous.disabled = pending || !state.stack.length;
            next.disabled = pending || !nextAfter;
            refresh.disabled = pending;
            extras.forEach((item) => {
                item.disabled = pending;
            });
            content.inert = pending;
            content.setAttribute("aria-busy", String(pending));
        }
        async function load(candidate, focus = false) {
            controls(true);
            let result;
            try {
                result = await read(candidate);
            } catch (error) {
                if (status.isConnected)
                    status.textContent =
                        "This page could not be loaded. Any previous page remains displayed. Use Refresh to try again.";
                throw error;
            } finally {
                if (container.isConnected) controls(false);
            }
            if (!container.isConnected || !content.isConnected) return;
            state = { ...candidate, stack: [...candidate.stack] };
            nextAfter = result.next_after;
            remember({ ...state, stack: [...state.stack] });
            content.replaceChildren(render(result.items, { ...state, stack: [...state.stack] }));
            status.textContent = `${result.items.length} ${label} on this page.${nextAfter ? " More are available." : ""}`;
            controls(false);
            if (focus) status.focus({ preventScroll: true });
        }
        ready(load);
        await load(state);
        return load;
    }
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
    async function handoff(student, value, container) {
        const result = await privateRequest(
            container,
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
                    await privateRequest(
                        container,
                        `/sis/students/${student}/hiatuses/${value.id}/finance-review/`,
                        "POST",
                        {},
                    );
                    if (container.isConnected) await handoff(student, value, container);
                }),
            );
        else container.append(node("p", {}, "This voided case has no finance review."));
    }
    async function queue(status = "open", after = "", stack = []) {
        const body = openPanel("Finance review queue", "OFFICE HAND-OFFS");
        const fields = [
            [
                "status",
                "Review status",
                "choice",
                [
                    { value: "open", label: "Open" },
                    { value: "in_review", label: "In review" },
                    { value: "completed", label: "Completed" },
                    { value: "cancelled", label: "Cancelled" },
                    { value: "all", label: "All statuses" },
                ],
            ],
        ];
        const form = await buildForm(fields, { status: status || "all" });
        if (!body.isConnected) return;
        form.classList.add("finance-queue-filter");
        const submit = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Filter finance reviews",
        );
        form.append(submit);
        let accepted = { status, after, stack: [...stack] },
            load;
        const reopen = () => queue(accepted.status, accepted.after, accepted.stack);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            if (load)
                task(() =>
                    load(
                        {
                            status:
                                form.elements.status.value === "all"
                                    ? ""
                                    : form.elements.status.value,
                            after: "",
                            stack: [],
                        },
                        true,
                    ),
                );
        });
        const content = node("div");
        body.replaceChildren(
            node(
                "p",
                { class: "form-text" },
                "Review academic hand-offs, record progress and return to the same filtered queue. Account entries and adjustments use their own authorization.",
            ),
            form,
            content,
        );
        load = await pages(
            content,
            "finance reviews page",
            accepted,
            (state) =>
                request("/sis/finance-reviews/", "POST", {
                    ...(state.status ? { status: state.status } : {}),
                    after: state.after,
                }),
            (rows) =>
                rows.length
                    ? list(
                          "Finance review tasks",
                          rows.map((item) =>
                              card(
                                  `${item.given_name} ${item.family_name}`,
                                  recordSummary([
                                      ["Institutional ID", item.institutional_id],
                                      ["Status", badge(statusLabel(item.status))],
                                      ["Requested", item.created_at],
                                  ]),
                                  button("Review finance task", () => record(item.id, reopen)),
                              ),
                          ),
                      )
                    : node("p", {}, "No reviews match this status."),
            (state) => {
                accepted = state;
            },
            [submit, form.elements.status],
            (reader) => {
                load = reader;
            },
        );
    }
    async function record(id, back = () => queue(), historyPage = { after: "", stack: [] }) {
        const body = openPanel("Financial review", "FINANCE OFFICE");
        const backControl = button("Back to finance queue", back, "btn-link");
        body.prepend(backControl);
        const value = await request(`/sis/finance-reviews/${id}/`);
        if (!body.isConnected) return;
        const reopen = () => record(id, back, historyPage);
        body.replaceChildren(
            backControl,
            node(
                "p",
                {},
                `${value.student.given_name} ${value.student.family_name} · ${value.student.institutional_id}`,
            ),
            recordSummary([
                ["Status", badge(statusLabel(value.status))],
                ["Academic case reference", value.source.id],
                ["Effective from", value.source.starts_on],
                ["Effective through", value.source.ends_on || "Open-ended"],
            ]),
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
        if (value.can_view_accounts)
            body.append(button("Review student accounts", () => accounts(id, "", [], reopen)));
        if (["open", "in_review"].includes(value.status))
            body.append(
                button(
                    value.assigned_to_me
                        ? "Record review progress / keep assignment"
                        : "Claim finance review",
                    () => change(id, value, "claim", reopen),
                ),
            );
        if (value.status === "in_review" && value.assigned_to_me)
            body.append(
                button(
                    "Complete finance review",
                    () => change(id, value, "complete", reopen),
                    "btn-primary",
                ),
            );
        body.append(
            actionMenu("More review actions", [
                {
                    label: ["open", "in_review"].includes(value.status)
                        ? "Cancel finance review"
                        : "Reopen finance review",
                    icon: ["open", "in_review"].includes(value.status)
                        ? "x-circle"
                        : "arrow-counterclockwise",
                    run: () =>
                        change(
                            id,
                            value,
                            ["open", "in_review"].includes(value.status) ? "cancel" : "reopen",
                            reopen,
                        ),
                },
            ]),
        );
        const panel = node("section", {
            class: "record-section",
            "aria-label": "Private finance review history",
        });
        body.append(panel);
        await history(
            id,
            panel,
            historyPage,
            (state) => {
                historyPage = state;
            },
            reopen,
        );
    }
    async function change(id, value, action, back = () => record(id)) {
        const body = openPanel("Review outcome", "FINANCE REVIEW");
        const backControl = button("Back to finance task", back, "btn-link");
        body.prepend(backControl);
        const operation = await request("/sis/ui/operation-id/");
        if (!body.isConnected) return;
        const fields = [["note", "Private finance reason / outcome", "textarea"]];
        const form = await buildForm(fields),
            submit = node(
                "button",
                { type: "submit", class: "btn btn-primary" },
                "Save finance review change",
            );
        if (!body.isConnected) return;
        const editor = node(
            "fieldset",
            { class: "record-form-section" },
            node("legend", { class: "h5" }, "Review outcome"),
        );
        editor.append(...form.children);
        form.append(editor, submit);
        body.replaceChildren(
            backControl,
            recordSummary([
                ["Student", `${value.student.given_name} ${value.student.family_name}`],
                ["Institutional ID", value.student.institutional_id],
                ["Current status", statusLabel(value.status)],
                ["Action", statusLabel(action)],
            ]),
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
        );
        submit.disabled = true;
        const selectedPostings =
            action === "complete" && value.can_view_accounts
                ? await postingPicker(id, value.student.id, editor)
                : () => [];
        if (!body.isConnected || !form.isConnected) return;
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
                    if (!body.isConnected || !form.isConnected) return;
                    await back();
                } finally {
                    editor.disabled = false;
                }
            }, submit);
        });
    }
    async function accounts(id, after = "", stack = [], back = () => record(id)) {
        const body = openPanel("Student accounts for this review", "FINANCE OFFICE");
        let accepted = { after, stack: [...stack] };
        const reopen = () => accounts(id, accepted.after, accepted.stack, back);
        const content = node("div");
        body.replaceChildren(
            button("Back to finance task", back),
            node(
                "p",
                { class: "form-text" },
                "Open a statement to review charges, payments, refunds and reversals. Posting remains a separate, authorized action.",
            ),
            content,
        );
        await pages(
            content,
            "billing accounts",
            accepted,
            (state) =>
                request(`/sis/finance-reviews/${id}/accounts/`, "POST", { after: state.after }),
            (rows) =>
                rows.length
                    ? list(
                          "Student accounts for the finance review",
                          rows.map((item) =>
                              card(
                                  item.term_code,
                                  recordSummary([
                                      ["Billing term", item.term_code],
                                      ["Currency", item.currency],
                                  ]),
                                  button(`Open ${item.term_code} account`, () =>
                                      ui.account(item.id, reopen, "Back to billing accounts"),
                                  ),
                              ),
                          ),
                      )
                    : node("p", {}, "This student has no billing accounts."),
            (state) => {
                accepted = state;
            },
        );
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
                        selected.set(identity, {
                            ...item,
                            account_id: current.id,
                            position: current.position,
                        });
                    else selected.delete(identity);
                    renderSelected();
                });
                return node(
                    "div",
                    { class: "form-check" },
                    control,
                    node(
                        "label",
                        { class: "form-check-label", for: control.id },
                        `Link ${describe(item)}`,
                    ),
                );
            });
            entries.replaceChildren(
                node(
                    "div",
                    {
                        role: "group",
                        "aria-label": "Existing account entries to link",
                        class: "finance-entry-choices",
                    },
                    rows,
                ),
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
            if (data.student_id !== student)
                throw new Error("Choose an account belonging to this student.");
            return data;
        }
        async function load(after = "", stack = []) {
            await read(async () => {
                const page = await request(`/sis/finance-reviews/${id}/accounts/`, "POST", {
                    after,
                });
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
                if (!page.items.length)
                    choices.append(node("p", {}, "This student has no billing accounts."));
                if (stack.length)
                    choices.append(
                        button("Previous account choices", () =>
                            load(stack.at(-1), stack.slice(0, -1)),
                        ),
                    );
                if (page.next_after)
                    choices.append(
                        button("More account choices", () =>
                            load(page.next_after, [...stack, after]),
                        ),
                    );
                disable();
            });
        }
        const refresh = button("Refresh linked entries", () =>
            read(async () => {
                const snapshots = new Map();
                for (const account of new Set([...selected.values()].map((p) => p.account_id))) {
                    snapshots.set(account, await statement(account));
                    if (!panel.isConnected) return;
                }
                if (!panel.isConnected) return;
                const updated = [];
                for (const [identity, p] of selected) {
                    const data = snapshots.get(p.account_id),
                        entry = data.entries.find((item) => item.operation_id === p.operation_id);
                    if (!entry)
                        throw new Error("A linked entry is unavailable. Reload the finance task.");
                    updated.push([
                        identity,
                        { ...entry, account_id: data.id, position: data.position },
                    ]);
                }
                selected.clear();
                updated.forEach(([identity, p]) => selected.set(identity, p));
                if (current && snapshots.has(current.id)) {
                    current = snapshots.get(current.id);
                    renderEntries();
                }
                renderSelected();
                status.textContent =
                    "Linked entries refreshed. Review their current status before completing.";
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
    async function history(id, container, initial, remember, back) {
        container.append(node("h3", { class: "h5" }, "Private finance history"));
        await pages(
            container,
            "finance history page",
            initial,
            (state) =>
                request(`/sis/finance-reviews/${id}/history/`, "POST", { after: state.after }),
            (rows) =>
                rows.length
                    ? list(
                          "Private finance decisions",
                          rows.map((item) =>
                              card(
                                  `${statusLabel(item.previous_status)} → ${statusLabel(item.status)}`,
                                  recordSummary([
                                      ["Recorded", item.created_at],
                                      ["Reviewer", item.actor_name || "Finance reviewer"],
                                  ]),
                                  node("p", { class: "private-text text-break" }, item.note),
                                  ...(item.postings || []).map((p) =>
                                      node(
                                          "div",
                                          { class: "finance-linked-entry" },
                                          node(
                                              "p",
                                              {},
                                              `${statusLabel(p.type)} · ${money(p.type === "charge_credit" ? -p.minor : p.minor, p.currency)} · ${p.reversed ? "Reversed when linked" : "Posted when linked"} · ${p.operation_id.slice(-8)}`,
                                          ),
                                          button("Open current statement", () =>
                                              ui.account(p.account_id, back),
                                          ),
                                      ),
                                  ),
                              ),
                          ),
                      )
                    : node("p", {}, "No finance decisions have been recorded yet."),
            remember,
        );
    }
    return { queue, record, handoff };
};
