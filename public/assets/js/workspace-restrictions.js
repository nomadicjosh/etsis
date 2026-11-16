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
        buildForm,
        formData,
        notify,
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
                    {
                        after,
                    },
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
        const setup = await request("/sis/offices/", "POST", {});
        const offices = setup.items.filter((row) => Number(row.active) === 1);
        if (!offices.length) {
            const body = openDrawer("An owning office is needed", "HOLD SETUP");
            body.append(
                node(
                    "p",
                    {},
                    "An administrator must set up an active office and delegate access before a hold can be placed.",
                ),
            );
            if (setup.can_administer)
                body.append(button("Set up offices", officeSetup));
            return;
        }
        const operation = await request("/sis/ui/operation-id/");
        const form = await flow(
            "Place restriction",
            [
                [
                    "office_id",
                    "Owning office",
                    "choice",
                    offices.map((row) => ({ value: row.id, label: row.name })),
                ],
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
            {
                severity: 99,
                office_id: offices.length === 1 ? offices[0].id : "",
            },
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
            student?.id && can("sis:student:read")
                ? button("Back to student", () =>
                      openRecord("students", student),
                  )
                : button("Back to holds", async () => {
                      ui.closeDrawer();
                      await load();
                  }),
            node(
                "p",
                {},
                `Owning office: ${record.office_name || "Not assigned"}`,
            ),
            button("Review hold history", () => history(id, student)),
        );
        if (can("sis:audit:read"))
            body.append(
                button("View restriction audit", () =>
                    showAudit(student?.id || record.person_id, id),
                ),
            );
        if (record.office_id && can("sis:restrictions:handoff"))
            body.append(
                button("Hand off to another office", () =>
                    handoff(id, student, record),
                ),
            );
        if (record.office_id) {
            body.append(
                node(
                    "p",
                    {},
                    `Follow-up: ${record.follow_up?.assignee_name || "Unassigned"} · ${record.follow_up?.follow_up_on || "No date set"}`,
                ),
                button("Follow-up & assignment", () => followUp(id, student)),
            );
        }
        if (!record.office_id && can("admin:access:manage"))
            body.append(
                button("Set owning office", async () => {
                    const setup = await request("/sis/offices/", "POST", {});
                    const choices = setup.items
                        .filter((row) => Number(row.active) === 1)
                        .map((row) => ({ value: row.id, label: row.name }));
                    const operation = await request("/sis/ui/operation-id/");
                    await flow(
                        "Assign owning office",
                        [
                            ["office_id", "Owning office", "choice", choices],
                            ["reason", "Routing reason", "textarea"],
                        ],
                        `/sis/restrictions/${id}/office/`,
                        (data) => ({
                            ...data,
                            operation_id: operation.id,
                            revision: record.revision,
                        }),
                        () => view(id, student),
                        {},
                        "Route this earlier hold to its current responsible office. Its original office remains unknown; this does not change registration rules or rewrite history.",
                    );
                }),
            );
        if (record.state !== "ended" && record.office_id)
            body.append(
                button("Set or shorten end date", async () => {
                    const operation = await request("/sis/ui/operation-id/");
                    const form = await flow(
                        "Save restriction end date",
                        [
                            ["ends_on", "Ends on", "date"],
                            [
                                "reason",
                                "Reason for ending the hold",
                                "textarea",
                            ],
                        ],
                        `/sis/restrictions/${id}/end/`,
                        (data) => ({
                            ...data,
                            revision: record.revision,
                            operation_id: operation.id,
                        }),
                        () => view(id, student),
                        {
                            ends_on:
                                record.ends_on ||
                                (record.today < record.starts_on
                                    ? record.starts_on
                                    : record.today),
                        },
                        "The end date is inclusive. Explain why the hold is ending. Your decision and reason are retained; earlier decisions and notes remain available. This form safely reuses its operation ID when retried.",
                    );
                    form.elements.reason.autocomplete = "off";
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
    async function history(id, student) {
        const body = openDrawer("Hold history", "RETAINED DECISIONS");
        body.append(
            node(
                "p",
                { class: "form-text" },
                "Earlier decisions remain unchanged. An observed record describes the setup found at that time; it does not identify when or by whom an older hold was placed.",
            ),
            button("Back to restriction", () => view(id, student)),
        );
        await pages(
            body,
            "history",
            async (cursor) => {
                const result = await request(
                    `/sis/restrictions/${id}/history/`,
                    "POST",
                    { cursor },
                );
                return { items: result.items, next_after: result.next_cursor };
            },
            (rows) =>
                rows.length
                    ? grid(
                          [
                              "Decision",
                              "Effective",
                              "Starts",
                              "Ends",
                              "Office",
                              "Recorded",
                          ],
                          rows.map((row) => [
                              {
                                  observed: "Existing record observed",
                                  placed: "Hold placed",
                                  ended: "End date changed",
                                  office_changed: "Office handoff",
                              }[row.action],
                              row.effective_on,
                              row.facts?.starts_on || "—",
                              row.facts ? row.facts.ends_on || "Open" : "—",
                              row.action === "office_changed"
                                  ? `${row.from_office} → ${row.to_office}`
                                  : row.office_name || "—",
                              new Date(row.recorded_at).toLocaleString(),
                          ]),
                          "Retained hold decisions",
                      )
                    : node(
                          "p",
                          {},
                          "No changes for this hold on this page. Other holds share the person's history; use Next history page if available.",
                      ),
        );
    }
    async function followUp(id, student) {
        const work = await request(`/sis/restrictions/${id}/follow-up/`);
        const body = openDrawer("Follow-up & assignment", "OFFICE WORK");
        let assignee = work.assigned_user_id,
            name = work.assignee_name;
        const selected = node("p", { role: "status", class: "fw-semibold" });
        const show = () => {
            selected.textContent = `Responsible colleague: ${name || "Unassigned"}`;
        };
        const choose = (value, label) => {
            assignee = value;
            name = label;
            show();
        };
        const actions = node(
            "div",
            { class: "inline-actions mb-3" },
            button("Leave unassigned", () => choose(null, null)),
        );
        if (work.can_assign_self)
            actions.append(
                button("Assign to me", () => choose(work.self_user_id, "You")),
            );
        body.append(
            button("Back to restriction", () => view(id, student)),
            node(
                "p",
                { class: "form-text" },
                "Choose a current office colleague and an optional follow-up date. Assignments never grant access or change registration effects. Clear the date when follow-up is finished.",
            ),
            selected,
            actions,
        );
        if (!work.assignee_eligible)
            body.append(
                node(
                    "p",
                    { class: "alert alert-warning", role: "status" },
                    "The assigned colleague no longer has current office access. Choose another colleague or leave this work unassigned.",
                ),
            );
        show();
        const search = node("input", {
            type: "search",
            class: "form-control",
            maxlength: 80,
            "aria-label": "Find a responsible colleague",
        });
        const searchForm = node(
            "form",
            { class: "inline-actions mb-3" },
            search,
            node(
                "button",
                { type: "submit", class: "btn btn-outline-primary" },
                "Find colleagues",
            ),
        );
        const results = node("div"),
            lookupStatus = node("p", { class: "form-text", role: "status" });
        body.append(searchForm, lookupStatus, results);
        let query = "",
            serial = 0;
        const find = async () => {
            const token = ++serial;
            const list = node("div");
            results.replaceChildren(list);
            await pages(
                list,
                "colleague",
                async (after) => {
                    const page = await request(
                        `/sis/restrictions/${id}/follow-up/people/`,
                        "POST",
                        { search: query, after },
                    );
                    if (!body.isConnected || token !== serial)
                        return { items: [], next_after: null };
                    return page;
                },
                (rows) =>
                    grid(
                        ["Colleague", "Action"],
                        rows.map((row) => [
                            row.name,
                            button(`Choose ${row.name}`, () =>
                                choose(row.id, row.name),
                            ),
                        ]),
                        "Responsible colleague choices",
                    ),
            );
            if (body.isConnected && token === serial)
                lookupStatus.textContent =
                    "Only current delegates with hold-management permission are listed. Use the next page if no eligible colleague appears here.";
        };
        searchForm.addEventListener("submit", (event) => {
            event.preventDefault();
            query = search.value.trim();
            task(find, searchForm.querySelector("button"));
        });
        const fields = [["follow_up_on", "Follow-up date", "date", "", false]];
        const form = await buildForm(fields, {
            follow_up_on: work.follow_up_on || "",
        });
        const save = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Save follow-up",
        );
        const clear = button("Clear follow-up", () => {
            choose(null, null);
            form.elements.follow_up_on.value = "";
        });
        form.append(
            node(
                "p",
                { class: "form-text" },
                `Dates follow the institution calendar. Today is ${work.today}.`,
            ),
            node("div", { class: "inline-actions" }, save, clear),
        );
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                await request(
                    `/sis/restrictions/${id}/follow-up/save/`,
                    "POST",
                    {
                        office_id: work.office_id,
                        work_revision: Number(work.work_revision),
                        assigned_user_id: assignee,
                        follow_up_on: formData(form, fields).follow_up_on,
                    },
                );
                notify("Office follow-up saved.");
                await view(id, student);
            }, save);
        });
        body.append(form);
        await find();
    }
    async function handoff(id, student, record, preset = {}) {
        const setup = await request("/sis/offices/", "POST", {});
        const choices = setup.items
            .filter(
                (row) =>
                    Number(row.active) === 1 && row.id !== record.office_id,
            )
            .map((row) => ({ value: row.id, label: row.name }));
        const body = openDrawer("Hand off this hold", "OFFICE RESPONSIBILITY");
        body.append(
            button("Back to restriction", () => view(id, student)),
            node(
                "p",
                { class: "form-text" },
                `Current office: ${record.office_name}. Choose a receiving office you are authorized to work with and explain the handoff.`,
            ),
        );
        if (!choices.length) {
            body.append(
                node(
                    "p",
                    {},
                    "No other active office is available within your current access.",
                ),
            );
            return;
        }
        const fields = [
            ["to_office_id", "Receiving office", "choice", choices],
            ["reason", "Reason for the handoff", "textarea"],
        ];
        const form = await buildForm(fields, preset);
        const reviewButton = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review office handoff",
        );
        form.append(reviewButton);
        body.append(form);
        form.elements.reason.autocomplete = "off";
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const data = formData(form, fields);
                const review = await request(
                    `/sis/restrictions/${id}/handoff/review/`,
                    "POST",
                    data,
                );
                await confirmHandoff(id, student, record, data, review);
            }, reviewButton);
        });
    }
    async function confirmHandoff(id, student, record, draft, review) {
        const body = openDrawer(
            "Review office handoff",
            "CONFIRM RESPONSIBILITY",
        );
        body.append(
            button("Change handoff", () => handoff(id, student, record, draft)),
            node("h3", {}, review.person.name),
            node(
                "p",
                {},
                `${review.person.institutional_id} · ${review.hold.code}`,
            ),
            grid(
                ["Current office", "Receiving office"],
                [[review.from_office, review.to_office]],
                "Office handoff comparison",
            ),
            node(
                "p",
                {},
                `${review.hold.starts_on} through ${review.hold.ends_on || "open-ended"} · Severity ${review.hold.severity}`,
            ),
            node("h3", {}, "Reason"),
            node(
                "p",
                { class: "text-break", style: "white-space: pre-wrap" },
                draft.reason,
            ),
            node(
                "p",
                { class: "form-text" },
                `This review includes ${review.notes_count} internal staff notes and expires in ${review.review_minutes} minutes. Hold dates and registration effects remain in force.`,
            ),
            node(
                "p",
                {},
                "The receiving office’s authorized delegates will be able to read this hold, its existing notes and its history. The earlier office and handoff decision remain in history.",
            ),
            node(
                "p",
                { class: "form-text" },
                `Current follow-up: ${review.follow_up?.assignee_name || "Unassigned"} · ${review.follow_up?.follow_up_on || "No date set"}. Confirming this handoff clears the individual assignment and follow-up date so the receiving office can plan its own work.`,
            ),
        );
        const fields = [
            [
                "acknowledge_access",
                "I approve the receiving office’s access to this hold, its notes and its history.",
                "checkbox",
            ],
        ];
        const form = await buildForm(fields);
        form.elements.acknowledge_access.required = true;
        const save = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Confirm office handoff",
        );
        form.append(save);
        body.append(form);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                await request(
                    `/sis/restrictions/${id}/handoff/confirm/`,
                    "POST",
                    {
                        review_token: review.review_token,
                        acknowledge_access: formData(form, fields)
                            .acknowledge_access,
                    },
                );
                notify("Office handoff recorded.");
                await view(id, student);
            }, save);
        });
    }
    let query = "",
        assignment = "all",
        due = false;
    async function load() {
        const container = document.getElementById("hold-queue");
        container.replaceChildren();
        const heading = node(
            "div",
            { class: "inline-actions mb-3" },
            button(
                "Place a hold",
                () =>
                    personSearch(
                        false,
                        "Find a person for this hold",
                        (person) => create({ person_id: person.id }),
                    ),
                "btn-primary",
            ),
        );
        if (can("admin:access:manage"))
            heading.append(button("Offices & delegation", officeSetup));
        container.append(
            node("h2", {}, "Your office holds"),
            node(
                "p",
                { class: "form-text" },
                "Work within your delegated offices. Ending a hold retains its decisions and notes. Registration checks all offices independently.",
            ),
            heading,
        );
        const search = node("input", {
            type: "search",
            class: "form-control",
            maxlength: 80,
            "aria-label": "Find office holds",
            value: query,
        });
        const assigned = node(
            "select",
            { class: "form-select", "aria-label": "Assignment filter" },
            ...[
                ["all", "All office work"],
                ["mine", "Assigned to me"],
                ["unassigned", "Unassigned"],
            ].map(([value, label]) => node("option", { value }, label)),
        );
        assigned.value = assignment;
        const dueInput = node("input", {
            type: "checkbox",
            class: "form-check-input",
            id: "holds-due",
            checked: due,
        });
        const dueField = node(
            "div",
            { class: "form-check" },
            dueInput,
            node(
                "label",
                { for: "holds-due", class: "form-check-label" },
                "Due today or earlier",
            ),
        );
        const form = node(
            "form",
            { class: "inline-actions mb-3" },
            search,
            assigned,
            dueField,
            node(
                "button",
                { type: "submit", class: "btn btn-outline-primary" },
                "Find holds",
            ),
        );
        const results = node("div");
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            query = search.value.trim();
            assignment = assigned.value;
            due = dueInput.checked;
            task(render, form.querySelector("button"));
        });
        container.append(form, results);
        async function render() {
            results.replaceChildren();
            await pages(
                results,
                "hold",
                (after) =>
                    request("/sis/restrictions/queue/", "POST", {
                        search: query,
                        after,
                        assignment,
                        due,
                    }),
                (rows) =>
                    rows.length
                        ? grid(
                              [
                                  "Person",
                                  "Institutional ID",
                                  "Hold",
                                  "Office",
                                  "Follow-up",
                                  "State",
                                  "Action",
                              ],
                              rows.map((row) => [
                                  row.person_name,
                                  row.institutional_id || "—",
                                  row.code,
                                  row.office_name || "Not assigned",
                                  `${row.assignee_name || "Unassigned"} · ${row.follow_up_on || "No date set"}`,
                                  badge(row.state),
                                  button("Review hold", () => view(row.id)),
                              ]),
                              "Office hold worklist",
                          )
                        : node(
                              "p",
                              {},
                              "No holds match your current office access and search.",
                          ),
            );
        }
        await render();
    }
    async function personSearch(accountsOnly, title, choose) {
        const body = openDrawer(
            title,
            accountsOnly ? "OFFICE DELEGATION" : "PLACE A HOLD",
        );
        const search = node("input", {
            type: "search",
            class: "form-control",
            minlength: 2,
            maxlength: 80,
            required: true,
            "aria-label": accountsOnly
                ? "Find an enabled account"
                : "Find a person for a hold",
        });
        const form = node(
            "form",
            { class: "inline-actions mb-3" },
            search,
            node(
                "button",
                { class: "btn btn-primary", type: "submit" },
                "Search people",
            ),
        );
        const results = node("div"),
            status = node(
                "p",
                { class: "form-text", role: "status" },
                "Enter at least two characters of a name or institutional ID.",
            );
        body.append(form, status, results);
        let serial = 0;
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const token = ++serial;
                const result = await request(
                    `/sis/offices/${accountsOnly ? "accounts" : "people"}/`,
                    "POST",
                    { search: search.value.trim() },
                );
                if (!body.isConnected || token !== serial) return;
                status.textContent = `${result.items.length} matches. Up to 25 are shown; narrow your search to find another person.`;
                results.replaceChildren(
                    ...result.items.map((person) =>
                        node(
                            "p",
                            {},
                            button(
                                `${person.given_name} ${person.family_name}${person.institutional_id ? ` · ${person.institutional_id}` : ""}`,
                                () => choose(person),
                            ),
                        ),
                    ),
                );
            }, form.querySelector("button"));
        });
        search.focus();
    }
    async function officeSetup() {
        const result = await request("/sis/offices/", "POST", {});
        const body = openDrawer("Offices & delegation", "WORK SCOPE");
        body.append(
            node(
                "p",
                { class: "form-text" },
                "Office delegation narrows access. Staff also need the separate hold-management permission through Roles & access. It does not grant registration, grades, finance or general student-record access.",
            ),
            button("Add office", () => officeForm()),
            grid(
                ["Code", "Office", "State", "Actions"],
                result.items.map((row) => [
                    row.code,
                    row.name,
                    Number(row.active) === 1 ? "Active" : "Retired",
                    node(
                        "div",
                        { class: "inline-actions" },
                        button("Edit office", () => officeForm(row)),
                        button("Manage delegates", () => delegates(row.id)),
                    ),
                ]),
                "Institution offices",
            ),
        );
    }
    async function officeForm(row = null) {
        const operation = row ? null : await request("/sis/ui/operation-id/");
        await flow(
            row ? "Save office" : "Create office",
            [
                ["code", "Office code"],
                ["name", "Office name"],
                ["active", "Active office", "checkbox"],
            ],
            `/sis/offices/${row?.id || operation.id}/save/`,
            (data) => ({ ...data, revision: row ? Number(row.revision) : -1 }),
            officeSetup,
            row
                ? { ...row, active: Number(row.active) === 1 }
                : { active: true },
            "Retirement prevents delegated work and new holds. Existing holds keep their registration effects and remain available to authorized administrators. No records are deleted.",
        );
    }
    async function delegates(officeId) {
        const body = openDrawer("Office delegates", "DATED ACCESS");
        const officeLabel = node("p", { class: "fw-semibold" });
        body.append(
            officeLabel,
            button("Back to offices", officeSetup),
            button("Add delegate", () =>
                personSearch(true, "Choose a delegate", async (person) => {
                    const context = await request(
                        `/sis/offices/${officeId}/delegates/`,
                        "POST",
                        {},
                    );
                    await delegationForm(officeId, {
                        user_id: person.owner_user_id,
                        first_name: person.given_name,
                        last_name: person.family_name,
                        starts_on: context.today,
                        active: true,
                        revision: -1,
                    });
                }),
            ),
        );
        await pages(
            body,
            "delegate",
            async (after) => {
                const result = await request(
                    `/sis/offices/${officeId}/delegates/`,
                    "POST",
                    {
                        after,
                    },
                );
                officeLabel.textContent = result.office.name;
                return result;
            },
            (rows) =>
                rows.length
                    ? grid(
                          [
                              "Staff member",
                              "Starts",
                              "Ends",
                              "Enabled",
                              "Action",
                          ],
                          rows.map((row) => [
                              `${row.first_name} ${row.last_name}`,
                              row.starts_on,
                              row.ends_on || "Open",
                              Number(row.active) === 1 ? "Yes" : "No",
                              button("Edit delegation", () =>
                                  delegationForm(officeId, row),
                              ),
                          ]),
                          "Office delegates",
                      )
                    : node("p", {}, "No delegates have been added."),
        );
    }
    async function delegationForm(officeId, row) {
        const form = await flow(
            "Save office delegation",
            [
                ["starts_on", "Delegation starts", "date"],
                ["ends_on", "Delegation ends", "date", "", false],
                ["active", "Enable this delegation", "checkbox"],
            ],
            `/sis/offices/${officeId}/delegates/save/`,
            (data) => ({
                ...data,
                user_id: row.user_id,
                revision: Number(row.revision),
            }),
            () => delegates(officeId),
            { ...row, active: row.active === true || Number(row.active) === 1 },
            `${row.first_name} ${row.last_name}. Both dates are inclusive and follow the institution calendar. Disable access to revoke it now. This does not add roles or permissions; each action still checks current authority.`,
        );
        const dates = () => {
            form.elements.ends_on.min = form.elements.starts_on.value;
        };
        form.elements.starts_on.addEventListener("input", dates);
        dates();
    }
    return { list, load, officeSetup };
};
