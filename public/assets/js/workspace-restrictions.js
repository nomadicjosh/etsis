/* Staff-only holds and encrypted append-only notes. Never persist private data in browser storage. */
window.createWorkspaceRestrictions = function (ui) {
    "use strict";
    const {
        node,
        request,
        task,
        can,
        openDrawer,
        openRecord,
        table,
        badge,
        flow,
        showAudit,
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
    async function pages(container, kind, fetchPage, render) {
        let after = "";
        let nextAfter = null;
        let serial = 0;
        const previousCursors = [],
            content = node("div");
        const status = node("p", {
            class: "form-text",
            role: "status",
            "aria-live": "polite",
        });
        const previous = button(`Previous ${kind} page`, async () => {
            after = previousCursors.pop() || "";
            await load();
        });
        const next = button(`Next ${kind} page`, async () => {
            previousCursors.push(after);
            after = nextAfter;
            await load();
        });
        container.append(
            status,
            content,
            node("div", { class: "inline-actions" }, previous, next),
        );
        async function load() {
            const token = ++serial;
            const result = await fetchPage(after);
            if (!container.isConnected || token !== serial) return;
            nextAfter = result.next_after;
            previous.disabled = !previousCursors.length;
            next.disabled = !nextAfter;
            status.textContent = `${result.items.length} records on this page.${nextAfter ? " More are available." : ""}`;
            content.replaceChildren(render(result.items));
        }
        await load();
    }
    async function list(container, student) {
        const panel = node(
            "section",
            { class: "record-section", "aria-label": "Restrictions and holds" },
            node("h3", {}, "Restrictions & holds"),
            node(
                "p",
                { class: "form-text" },
                "Restrictions follow institutional calendar dates, including the whole end date. Staff notes are internal and encrypted at rest.",
            ),
        );
        container.append(panel);
        panel.append(button("Place a restriction", () => create(student)));
        await pages(
            panel,
            "restriction",
            (after) =>
                request(
                    `/sis/persons/${student.person_id}/restrictions/`,
                    "POST",
                    { after },
                ),
            (rows) =>
                rows.length
                    ? grid(
                          [
                              "Code",
                              "Severity",
                              "State",
                              "Starts",
                              "Ends",
                              "Action",
                          ],
                          rows.map((row) => [
                              row.code,
                              row.severity,
                              badge(row.state),
                              row.starts_on,
                              row.ends_on || "Open",
                              button("Review restriction", () =>
                                  view(row.id, student),
                              ),
                          ]),
                          "Student restrictions",
                      )
                    : node("p", {}, "No restrictions have been recorded."),
        );
    }
    async function create(student) {
        const operation = await request("/sis/ui/operation-id/");
        const form = await flow(
            "Place restriction",
            [
                ["code", "Restriction code"],
                [
                    "severity",
                    "Severity (99+ blocks student registration)",
                    "number",
                ],
                ["starts_on", "Starts on", "date"],
                ["ends_on", "Ends on (optional)", "date", "", false],
                ["blocks_staff", "Also block staff registration", "checkbox"],
                ["note", "Initial staff note", "textarea"],
            ],
            "/sis/restrictions/",
            (data) => ({
                ...data,
                person_id: student.person_id,
                operation_id: operation.id,
            }),
            () => view(operation.id, student),
            { severity: 99 },
            "Explain the reason for the restriction in the internal note. The note is not shown to the student. This form reuses its operation ID when retried. End dates include the entire day.",
        );
        form.elements.code.maxLength = 22;
        form.elements.note.autocomplete = "off";
        const dates = () => {
            form.elements.ends_on.min = form.elements.starts_on.value;
        };
        form.elements.starts_on.addEventListener("input", dates);
        dates();
    }
    async function view(id, student) {
        const record = await request(`/sis/restrictions/${id}/`);
        const body = openDrawer(record.code, "RESTRICTION & STAFF NOTES");
        body.append(
            badge(record.state),
            node(
                "p",
                {},
                `Severity: ${record.severity} · ${record.starts_on} through ${record.ends_on || "open-ended"}`,
            ),
            node(
                "p",
                { class: "form-text" },
                `Dates follow ${record.timezone}. End dates include the entire day. A restriction ending today remains active today.`,
            ),
            node(
                "p",
                {},
                `When active: student registration ${record.severity >= 99 ? "blocked" : "not blocked"}; staff registration ${record.blocks_staff ? "blocked" : "not blocked"}.`,
            ),
            button("Back to student", () => openRecord("students", student)),
        );
        if (can("sis:audit:read"))
            body.append(
                button("View restriction audit", () =>
                    showAudit(student.id, id),
                ),
            );
        if (record.state !== "ended")
            body.append(
                button("Set or shorten end date", async () => {
                    const form = await flow(
                        "Save restriction end date",
                        [["ends_on", "Ends on", "date"]],
                        `/sis/restrictions/${id}/end/`,
                        (data) => ({ ...data, revision: record.revision }),
                        () => view(id, student),
                        {
                            ends_on:
                                record.ends_on ||
                                (record.today < record.starts_on
                                    ? record.starts_on
                                    : record.today),
                        },
                        "The end date is inclusive. Scheduled ends can be shortened. Historical ended restrictions stay unchanged; this does not delete the restriction or its notes.",
                    );
                    form.elements.ends_on.min = record.starts_on;
                    if (record.ends_on)
                        form.elements.ends_on.max = record.ends_on;
                }),
            );
        body.append(
            button("Add staff note", async () => {
                const operation = await request("/sis/ui/operation-id/");
                const form = await flow(
                    "Save staff note",
                    [["note", "Staff note", "textarea"]],
                    `/sis/restrictions/${id}/notes/append/`,
                    (data) => ({ ...data, operation_id: operation.id }),
                    () => view(id, student),
                    {},
                    "Notes are appended with your account and a timestamp. Existing notes cannot be edited or deleted. Record only information needed for this restriction.",
                );
                form.elements.note.autocomplete = "off";
            }),
        );
        const notes = node(
            "section",
            { class: "record-section", "aria-label": "Internal staff notes" },
            node("h3", {}, "Internal staff notes"),
            node(
                "p",
                { class: "form-text" },
                "Staff-only content. Timestamps below use your browser’s local timezone.",
            ),
        );
        body.append(notes);
        await pages(
            notes,
            "note",
            (after) =>
                request(`/sis/restrictions/${id}/notes/`, "POST", { after }),
            (rows) =>
                rows.length
                    ? node(
                          "div",
                          {},
                          rows.map((note) =>
                              node(
                                  "article",
                                  { class: "surface p-3 mb-3" },
                                  node(
                                      "p",
                                      { class: "form-text" },
                                      `${note.actor_name || `Staff account …${note.actor_id.slice(-8)}`} · ${new Date(note.created_at).toLocaleString()}`,
                                  ),
                                  node(
                                      "p",
                                      {
                                          class: "text-break mb-0",
                                          style: "white-space: pre-wrap",
                                      },
                                      note.content,
                                  ),
                              ),
                          ),
                      )
                    : node("p", {}, "No staff notes have been recorded."),
        );
    }
    return { list };
};
