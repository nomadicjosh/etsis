/* Request a useful answer, keep the conversation together, deliberately share a live report. */
window.createWorkspaceReportRequests = function (ui) {
    "use strict";
    const {
        node,
        request: transport,
        task,
        can,
        table,
        badge,
        buildForm,
        formData,
        openDrawer,
        notify,
    } = ui;
    let activeBody = null;
    async function request(...args) {
        const scope = activeBody;
        try {
            return await transport(...args);
        } catch (error) {
            if (error.status === 403 && scope?.isConnected) {
                document.querySelector("#drawer-title").textContent =
                    "Report requests";
                scope.replaceChildren(
                    node(
                        "p",
                        {},
                        "Access to this request is no longer available.",
                    ),
                );
            }
            throw error;
        }
    }
    function openCase(title, label) {
        const drawer = openDrawer(title, label);
        activeBody = node("div");
        drawer.append(activeBody);
        return activeBody;
    }
    const editable = (form) =>
        [...form.elements].forEach((control) => {
            if (control.tagName !== "BUTTON") control.disabled = false;
        });
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
    const labels = {
        open: "New request",
        in_progress: "In progress",
        waiting: "Awaiting reply",
        delivered: "Report shared",
        cancelled: "Cancelled",
    };
    const actionLabels = {
        claim: "Take this request",
        clarify: "Ask for clarification",
        reply: "Reply to question",
        deliver: "Share finished report",
        cancel: "Cancel request",
        reopen: "Reopen request",
        note: "Add a message",
        created: "Request submitted",
    };
    const operation = async () => (await request("/sis/ui/operation-id/")).id;
    const protect = (form) =>
        [...form.elements].forEach((control) => {
            if (control.tagName !== "BUTTON") control.disabled = true;
        });
    const help = () =>
        node(
            "p",
            { class: "form-text" },
            "Messages are visible to the requester and the authorized report team. Keep the question focused; report results and attachments are not stored in this conversation.",
        );
    function recovery(body, id, op, completed) {
        body.append(
            button("Check saved action", async () => {
                await request(`/sis/report-requests/${id}/receipts/${op}/`);
                await completed();
            }),
            node(
                "p",
                { class: "form-text" },
                "After an interrupted response, retry the same confirmation or check its saved action before making another change.",
            ),
        );
    }
    async function create() {
        const op = await operation();
        const body = openCase("Request a report", "ASK A QUESTION");
        body.append(
            node(
                "p",
                {},
                "Tell the report team what decision you need to make. They can clarify the question, build the report and share it here.",
            ),
        );
        const fields = [
            ["title", "Short title"],
            ["question", "What do you need to know?", "textarea"],
            ["purpose", "How will you use the answer?", "textarea"],
            ["due_on", "Date needed (optional)", "date", "", false],
        ];
        const form = await buildForm(fields);
        form.elements.title.maxLength = 120;
        form.elements.question.maxLength = 5000;
        form.elements.purpose.maxLength = 2000;
        form.append(
            help(),
            node(
                "p",
                { class: "form-text" },
                "The date is a requested deadline, not a delivery guarantee. Report creation and data access need separate permission.",
            ),
            node(
                "button",
                { type: "submit", class: "btn btn-primary" },
                "Submit report request",
            ),
        );
        let command = null;
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!command) {
                    command = { ...formData(form, fields), operation_id: op };
                    protect(form);
                }
                try {
                    await request("/sis/report-requests/", "POST", command);
                } catch (error) {
                    if (error.status === 422) {
                        command = null;
                        editable(form);
                    }
                    throw error;
                }
                notify("Your report request was submitted.");
                await record(op);
            }, form.querySelector('[type="submit"]'));
        });
        body.append(
            form,
            button("Check saved request", () => record(op)),
            node(
                "p",
                { class: "form-text" },
                `Request reference: ${op}. After an interruption, retry this same submission or check the saved request.`,
            ),
        );
    }
    async function queue(
        team = false,
        status = team ? "open" : null,
        after = null,
        stack = [],
    ) {
        const page = await request("/sis/report-requests/page/", "POST", {
            queue: team,
            status,
            ...(after ? { after } : {}),
        });
        const body = openCase(
            team ? "Report request queue" : "My report requests",
            "REPORT REQUESTS",
        );
        body.append(
            node(
                "p",
                {},
                team
                    ? "Take a question, keep the conversation together and share a reviewed report with its requester."
                    : "Follow your questions from request to answer. You can reply here and open a report once it is shared.",
            ),
        );
        const filters = node("div", {
            class: "d-flex flex-wrap gap-2 mb-3",
            role: "group",
            "aria-label": "Filter requests",
        });
        for (const [value, label] of [
            [null, "All"],
            ["open", "New"],
            ["in_progress", "In progress"],
            ["waiting", "Needs a reply"],
            ["delivered", "Shared"],
            ["cancelled", "Cancelled"],
        ]) {
            const control = button(label, () => queue(team, value));
            control.setAttribute("aria-pressed", String(status === value));
            filters.append(control);
        }
        body.append(filters, button("Request a report", create));
        if (can("sis:reporting:manage"))
            body.append(
                button(team ? "My requests" : "Team queue", () => queue(!team)),
            );
        if (page.items.length)
            body.append(
                node(
                    "div",
                    {
                        class: "table-responsive",
                        role: "region",
                        "aria-label": "Report requests",
                        tabindex: "0",
                    },
                    table(
                        ["Question", "Requester", "Progress", "Date needed"],
                        page.items.map((row) => [
                            button(row.title, () => record(row.id)),
                            row.owned ? "You" : row.requester_name,
                            node(
                                "div",
                                {},
                                badge(labels[row.status]),
                                node(
                                    "p",
                                    { class: "form-text" },
                                    row.assigned_to_me
                                        ? "Assigned to you"
                                        : row.assignee_name || "Unassigned",
                                ),
                            ),
                            row.due_on || "No date requested",
                        ]),
                        "Report requests",
                    ),
                ),
            );
        else
            body.append(
                node(
                    "p",
                    { class: "py-4" },
                    "No requests in this view. Choose another status or ask the report team a question.",
                ),
            );
        const controls = node("div", { class: "d-flex flex-wrap gap-2 mt-3" });
        if (stack.length)
            controls.append(
                button("Previous request page", () =>
                    queue(team, status, stack.at(-1), stack.slice(0, -1)),
                ),
            );
        if (page.next_after)
            controls.append(
                button("Next request page", () =>
                    queue(team, status, page.next_after, [...stack, after]),
                ),
            );
        body.append(controls);
    }
    async function record(id) {
        const value = await request(`/sis/report-requests/${id}/`);
        const body = openCase(value.title, "REPORT REQUEST");
        body.append(
            badge(labels[value.status]),
            node(
                "p",
                { class: "mt-3" },
                `${value.owned ? "Requested by you" : `Requested by ${value.requester_name}`} · ${value.assignee_name ? `Assigned to ${value.assigned_to_me ? "you" : value.assignee_name}` : "Waiting for a report specialist"}`,
            ),
            node("p", {}, `Date needed: ${value.due_on || "Not specified"}`),
            node("h3", {}, "The question"),
            node(
                "p",
                { class: "text-break report-request-message" },
                value.question,
            ),
            node("h3", {}, "The decision it supports"),
            node(
                "p",
                { class: "text-break report-request-message" },
                value.purpose,
            ),
        );
        const controls = node("div", { class: "d-flex flex-wrap gap-2 mb-4" });
        for (const key of [
            "claim",
            "clarify",
            "reply",
            "deliver",
            "note",
            "cancel",
            "reopen",
        ])
            if (value.actions[key])
                controls.append(
                    button(
                        key === "claim" && value.assigned
                            ? "Take over request"
                            : actionLabels[key],
                        () =>
                            key === "deliver"
                                ? delivery(value)
                                : action(value, key),
                        ["reply", "claim", "deliver"].includes(key),
                    ),
                );
        if (value.report_id)
            controls.append(
                button("Open linked report", () =>
                    ui.reports().record(value.report_id),
                ),
            );
        if (value.actions.deliver)
            controls.append(
                button("Build a report in Report Studio", () =>
                    ui.builder().start(),
                ),
            );
        controls.append(button("My requests", () => queue()));
        if (can("sis:reporting:manage"))
            controls.append(button("Team queue", () => queue(true)));
        body.append(
            controls,
            node("h3", {}, "Conversation & progress"),
            help(),
        );
        const history = node("div");
        let cursor = null;
        const more = button("Load more progress", load);
        async function load() {
            const page = await request(
                `/sis/report-requests/${id}/history/`,
                "POST",
                cursor === null ? {} : { after_version: cursor },
            );
            for (const item of page.items)
                history.append(
                    node(
                        "article",
                        { class: "border rounded-3 p-3 mb-3" },
                        node(
                            "h4",
                            { class: "h6" },
                            `${actionLabels[item.action] || item.action} · ${item.actor}`,
                        ),
                        node("p", { class: "form-text" }, item.created_at),
                        node(
                            "p",
                            { class: "text-break report-request-message" },
                            item.note ||
                                "The question was submitted to the report team.",
                        ),
                    ),
                );
            cursor = page.next_after_version;
            more.hidden = cursor === null;
        }
        body.append(
            history,
            more,
            node(
                "p",
                { class: "form-text" },
                `Case reference: ${id}. Cancelling or reopening this case does not revoke existing report shares. Linked reports show live results and recheck access when opened.`,
            ),
        );
        await load();
    }
    async function action(value, kind) {
        const op = await operation();
        const body = openCase(
            kind === "claim" && value.assigned
                ? "Take over request"
                : actionLabels[kind],
            "REPORT REQUEST",
        );
        body.append(
            node("h3", {}, value.title),
            node(
                "p",
                {},
                kind === "claim"
                    ? value.assigned
                        ? "Explain why you are taking over this request. Its previous messages remain in the case."
                        : "You will be responsible for clarifying this question and sharing the finished report."
                    : "Your message and this progress change will be saved together.",
            ),
            help(),
        );
        const fields = [
            [
                "note",
                kind === "clarify"
                    ? "What needs clarification?"
                    : kind === "reply"
                      ? "Your reply"
                      : "Message or reason",
                "textarea",
                "",
                kind !== "claim" || value.assigned,
            ],
        ];
        const form = await buildForm(fields);
        form.elements.note.maxLength = 5000;
        const send = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            kind === "claim" ? "Confirm assignment" : actionLabels[kind],
        );
        let command = null;
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!command) {
                    command = {
                        ...formData(form, fields),
                        action: kind,
                        version: value.version,
                        operation_id: op,
                    };
                    protect(form);
                }
                try {
                    await request(
                        `/sis/report-requests/${value.id}/actions/`,
                        "POST",
                        command,
                    );
                } catch (error) {
                    if (error.status === 422) {
                        command = null;
                        editable(form);
                    }
                    throw error;
                }
                notify("Request updated.");
                await record(value.id);
            }, send);
        });
        form.append(send);
        body.append(
            form,
            button("Back to request", () => record(value.id)),
        );
        recovery(body, value.id, op, () => record(value.id));
    }
    async function delivery(value) {
        const body = openCase("Share a finished report", "REPORT HANDOFF");
        body.append(
            node("h3", {}, value.title),
            node(
                "p",
                {},
                "Select a saved report you manage. Review its name and requester before sharing. No email, attachment or result snapshot is sent.",
            ),
        );
        const fields = [
            ["report_id", "Finished report", "lookup", "reports"],
            [
                "note",
                "Explain the answer or how to use this report",
                "textarea",
            ],
        ];
        const form = await buildForm(fields);
        form.elements.note.maxLength = 5000;
        const next = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review report handoff",
        );
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                const draft = formData(form, fields);
                const plan = await request(
                    `/sis/report-requests/${value.id}/delivery-review/`,
                    "POST",
                    {
                        report_id: draft.report_id,
                    },
                );
                await confirmDelivery(value, draft, plan);
            }, next);
        });
        form.append(next);
        body.append(
            form,
            help(),
            button("Back to request", () => record(value.id)),
        );
    }
    async function confirmDelivery(value, draft, plan) {
        const body = openCase("Review report handoff", "EXPLICIT SHARING");
        const command = {
            action: "deliver",
            report_id: plan.report.id,
            note: draft.note,
            operation_id: plan.operation_id,
            version: plan.version,
            delivery_revision: plan.delivery_revision,
        };
        body.append(
            node("h3", {}, plan.report.name),
            node(
                "p",
                {},
                `Share with ${plan.requester_name} for “${value.title}”.`,
            ),
            node(
                "p",
                { class: "text-break report-request-message" },
                draft.note,
            ),
            node(
                "p",
                { class: "alert alert-info" },
                "This grants access to the selected live report, not report editing. Report execution and source-data permissions are checked separately every time. Later report edits can change the results. A requester who lacks required data access must obtain it from an administrator.",
            ),
            node(
                "div",
                { class: "d-flex flex-wrap gap-2" },
                button("Back to request", () => record(value.id)),
                button(
                    "Confirm and share report",
                    async () => {
                        await request(
                            `/sis/report-requests/${value.id}/actions/`,
                            "POST",
                            command,
                        );
                        notify("Report shared with the requester.");
                        await record(value.id);
                    },
                    true,
                ),
            ),
        );
        recovery(body, value.id, plan.operation_id, () => record(value.id));
    }
    return { create, queue, record };
};
