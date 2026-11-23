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
        recordSummary,
        actionMenu,
    } = ui;
    const button = (label, callback, style = "btn-outline-primary", accessibleName = label) =>
        node(
            "button",
            {
                type: "button",
                class: `btn ${style}`,
                "aria-label": accessibleName,
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
    async function pages(
        container,
        kind,
        fetchPage,
        render,
        { initial = null, remember = () => {}, onDenied = null } = {},
    ) {
        let after = initial?.after || "";
        let nextAfter = null;
        let serial = 0;
        let busy = false;
        let previousCursors = [...(initial?.trail || [])];
        const content = node("div");
        const status = node("p", {
            class: "form-text",
            role: "status",
            "aria-live": "polite",
            tabindex: "-1",
        });
        const readButton = (label, run) =>
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
        const previous = readButton(`Previous ${kind} page`, () => {
            return load(previousCursors.at(-1) || "", previousCursors.slice(0, -1), true);
        });
        const next = readButton(`Next ${kind} page`, () =>
            load(nextAfter, [...previousCursors, after], true),
        );
        const refresh = readButton(`Refresh ${kind} page`, () =>
            load(after, previousCursors, true),
        );
        previous.disabled = true;
        next.disabled = true;
        container.append(
            status,
            node(
                "nav",
                { class: "inline-actions mb-3", "aria-label": `${kind} pages` },
                previous,
                next,
                refresh,
            ),
            content,
        );
        function controls(pending) {
            busy = pending;
            previous.disabled = pending || !previousCursors.length;
            next.disabled = pending || !nextAfter;
            refresh.disabled = pending;
            content.inert = pending;
            content.setAttribute("aria-busy", String(pending));
        }
        async function load(
            candidateAfter = after,
            candidateTrail = previousCursors,
            focus = false,
        ) {
            const token = ++serial;
            controls(true);
            let result;
            try {
                result = await fetchPage(candidateAfter);
            } catch (error) {
                if (container.isConnected && token === serial) {
                    status.textContent =
                        "This page could not be refreshed. The previous page remains displayed. Try again.";
                    if (error.status === 403) {
                        content.replaceChildren();
                        nextAfter = null;
                        previousCursors = [];
                        status.textContent = "Access to this list is no longer available.";
                        onDenied?.();
                    }
                }
                throw error;
            } finally {
                if (container.isConnected && token === serial) controls(false);
            }
            if (!container.isConnected || token !== serial) return;
            after = candidateAfter;
            previousCursors = [...candidateTrail];
            nextAfter = result.next_after;
            remember({ after, trail: [...previousCursors] });
            previous.disabled = !previousCursors.length;
            next.disabled = !nextAfter;
            status.textContent = `${result.items.length} records on this page.${nextAfter ? " More are available." : ""}`;
            content.replaceChildren(render(result.items, result));
            if (focus) status.focus({ preventScroll: true });
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
                request(`/sis/persons/${student.person_id}/restrictions/`, "POST", {
                    after,
                }),
            (rows) =>
                rows.length
                    ? grid(
                          ["Code", "Severity", "State", "Starts", "Ends", "Action"],
                          rows.map((row) => [
                              row.code,
                              row.severity,
                              badge(row.state),
                              row.starts_on,
                              row.ends_on || "Open",
                              button("Review restriction", () => view(row.id, student)),
                          ]),
                          "Student restrictions",
                      )
                    : node("p", {}, "No restrictions have been recorded."),
        );
    }
    async function create(student) {
        const pendingView = openDrawer("Place restriction", "CONNECTED WORKSPACE");
        pendingView.append(node("p", { role: "status", class: "form-text" }, "Loading record details…"));
        const setup = await request("/sis/offices/", "POST", {});
        if (!pendingView.isConnected) return;
        const offices = setup.items.filter((row) => Number(row.active) === 1);
        if (!offices.length) {
            if (!pendingView.isConnected) return;
            const body = openDrawer("An owning office is needed", "HOLD SETUP");
            body.append(
                node(
                    "p",
                    {},
                    "An administrator must set up an active office and delegate access before a hold can be placed.",
                ),
            );
            if (setup.can_administer) body.append(button("Set up offices", officeSetup));
            return;
        }
        const operation = await request("/sis/ui/operation-id/");
        if (!pendingView.isConnected) return;
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
                ["severity", "Severity (99+ blocks student registration)", "number"],
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
            { layout: "restriction" },
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
        const body = openDrawer("Hold details", "RESTRICTION & STAFF NOTES");
        body.append(node("p", { role: "status" }, "Loading hold details…"));
        const record = await request(`/sis/restrictions/${id}/`);
        if (!body.isConnected) return;
        document.getElementById("drawer-title").textContent = record.code;
        body.replaceChildren(
            student?.id && can("sis:student:read")
                ? button("Back to student", () => openRecord("students", student), "btn-link mb-3")
                : button(
                      "Back to holds",
                      async () => {
                          ui.closeDrawer();
                          await load();
                      },
                      "btn-link mb-3",
                  ),
            recordSummary([
                ["Status", badge(record.state)],
                ["Severity", record.severity],
                ["Starts", record.starts_on],
                ["Ends", record.ends_on || "Open-ended"],
            ]),
        );
        const personName =
            record.person_name ||
            [student?.given_name, student?.family_name].filter(Boolean).join(" ");
        if (personName)
            body.insertBefore(
                node(
                    "p",
                    { class: "hold-person-context" },
                    node("strong", {}, personName),
                    node("span", {}, record.institutional_id || student?.institutional_id || ""),
                ),
                body.querySelector("dl"),
            );
        body.append(
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
            node("p", {}, `Owning office: ${record.office_name || "Not assigned"}`),
        );
        const actions = node("div", {
            class: "inline-actions",
            role: "group",
            "aria-label": "Hold tasks",
        });
        const more = [button("Review hold history", () => history(id, student))];
        body.append(actions);
        if (can("sis:audit:read"))
            more.push(
                button("View restriction audit", () =>
                    showAudit(student?.id || record.person_id, id),
                ),
            );
        if (record.office_id && can("sis:restrictions:handoff"))
            more.push(
                button(
                    "Hand off",
                    () => handoff(id, student, record),
                    "btn-outline-primary",
                    "Hand off to another office",
                ),
            );
        if (record.office_id) {
            const followUpSummary = recordSummary([
                ["Assigned to", record.follow_up?.assignee_name || "Unassigned"],
                ["Follow-up date", record.follow_up?.follow_up_on || "No date set"],
            ]);
            body.insertBefore(followUpSummary, actions);
            actions.append(button("Follow-up & assignment", () => followUp(id, student)));
        }
        if (!record.office_id && can("admin:access:manage"))
            actions.append(
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
            actions.append(
                button(
                    "End date",
                    async () => {
                        const operation = await request("/sis/ui/operation-id/");
                        const form = await flow(
                            "Save restriction end date",
                            [
                                ["ends_on", "Ends on", "date"],
                                ["reason", "Reason for ending the hold", "textarea"],
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
                            { layout: "restriction_end" },
                        );
                        form.elements.reason.autocomplete = "off";
                        form.elements.ends_on.min = record.starts_on;
                        if (record.ends_on) form.elements.ends_on.max = record.ends_on;
                    },
                    "btn-outline-primary",
                    "Set or shorten end date",
                ),
            );
        actions.prepend(
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
                    { layout: "staff_note" },
                );
                form.elements.note.autocomplete = "off";
            }),
        );
        if (more.length > 1)
            actions.append(actionMenu("More", more, { context: "More hold actions" }));
        else actions.append(more[0]);
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
            (after) => request(`/sis/restrictions/${id}/notes/`, "POST", { after }),
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
                const result = await request(`/sis/restrictions/${id}/history/`, "POST", {
                    cursor,
                });
                return { items: result.items, next_after: result.next_cursor };
            },
            (rows) =>
                rows.length
                    ? grid(
                          ["Decision", "Effective", "Starts", "Ends", "Office", "Recorded"],
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
        const pendingView = openDrawer("Restriction follow-up", "CONNECTED WORKSPACE");
        pendingView.append(node("p", { role: "status", class: "form-text" }, "Loading record details…"));
        const work = await request(`/sis/restrictions/${id}/follow-up/`);
        if (!pendingView.isConnected) return;
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
            actions.append(button("Assign to me", () => choose(work.self_user_id, "You")));
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
            node("button", { type: "submit", class: "btn btn-outline-primary" }, "Find colleagues"),
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
                            button(`Choose ${row.name}`, () => choose(row.id, row.name)),
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
        const save = node("button", { type: "submit", class: "btn btn-primary" }, "Save follow-up");
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
                await request(`/sis/restrictions/${id}/follow-up/save/`, "POST", {
                    office_id: work.office_id,
                    work_revision: Number(work.work_revision),
                    assigned_user_id: assignee,
                    follow_up_on: formData(form, fields).follow_up_on,
                });
                notify("Office follow-up saved.");
                await view(id, student);
            }, save);
        });
        body.append(form);
        await find();
    }
    async function handoff(id, student, record, preset = {}) {
        const pendingView = openDrawer("Office handoff", "CONNECTED WORKSPACE");
        pendingView.append(node("p", { role: "status", class: "form-text" }, "Loading record details…"));
        const setup = await request("/sis/offices/", "POST", {});
        if (!pendingView.isConnected) return;
        const choices = setup.items
            .filter((row) => Number(row.active) === 1 && row.id !== record.office_id)
            .map((row) => ({ value: row.id, label: row.name }));
        if (!pendingView.isConnected) return;
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
                node("p", {}, "No other active office is available within your current access."),
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
        const body = openDrawer("Review office handoff", "CONFIRM RESPONSIBILITY");
        body.append(
            button("Change handoff", () => handoff(id, student, record, draft)),
            node("h3", {}, review.person.name),
            node("p", {}, `${review.person.institutional_id} · ${review.hold.code}`),
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
            node("p", { class: "text-break", style: "white-space: pre-wrap" }, draft.reason),
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
                await request(`/sis/restrictions/${id}/handoff/confirm/`, "POST", {
                    review_token: review.review_token,
                    acknowledge_access: formData(form, fields).acknowledge_access,
                });
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
                    personSearch(false, "Find a person for this hold", (person) =>
                        create({ person_id: person.id }),
                    ),
                "btn-primary",
            ),
        );
        if (can("admin:access:manage")) heading.append(button("Offices & delegation", officeSetup));
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
            node("label", { for: "holds-due", class: "form-check-label" }, "Due today or earlier"),
        );
        const form = node(
            "form",
            { class: "hold-queue-search mb-3" },
            node("label", { class: "form-label" }, "Find office holds", search),
            node("label", { class: "form-label" }, "Assignment filter", assigned),
            node(
                "div",
                { class: "hold-queue-options" },
                dueField,
                node("button", { type: "submit", class: "btn btn-outline-primary" }, "Find holds"),
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
                        : node("p", {}, "No holds match your current office access and search."),
            );
        }
        await render();
    }
    async function personSearch(
        accountsOnly,
        title,
        choose,
        { back = null, backLabel = "Back to office delegates", context = "" } = {},
    ) {
        const body = openDrawer(title, accountsOnly ? "OFFICE DELEGATION" : "PLACE A HOLD");
        if (back) body.append(button(backLabel, back, "btn-link mb-3"));
        if (context) body.append(node("p", { class: "form-text" }, context));
        const search = node("input", {
            type: "search",
            class: "form-control",
            minlength: 2,
            maxlength: 80,
            required: true,
            "aria-label": accountsOnly ? "Find an enabled account" : "Find a person for a hold",
        });
        const form = node(
            "form",
            { class: "office-person-search mb-3" },
            search,
            node("button", { class: "btn btn-primary", type: "submit" }, "Search people"),
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
                results.inert = true;
                let result;
                try {
                    result = await request(
                        `/sis/offices/${accountsOnly ? "accounts" : "people"}/`,
                        "POST",
                        { search: search.value.trim() },
                    );
                } catch (error) {
                    if (body.isConnected && token === serial && error.status === 403) {
                        results.replaceChildren();
                        status.textContent = "Access to this search is no longer available.";
                    }
                    throw error;
                } finally {
                    if (body.isConnected && token === serial) results.inert = false;
                }
                if (!body.isConnected || token !== serial) return;
                status.textContent = `${result.items.length} matches. Up to 25 are shown; narrow your search to find another person.`;
                results.replaceChildren(
                    ...result.items.map((person) =>
                        node(
                            "div",
                            { class: "office-person-match" },
                            button(
                                `${person.given_name} ${person.family_name}${person.institutional_id ? ` · ${person.institutional_id}` : ""}`,
                                () => choose(person, body),
                            ),
                        ),
                    ),
                );
            }, form.querySelector("button"));
        });
        search.focus();
    }
    async function officeSetup(origin = null) {
        const back = () => officeSetup(origin);
        const body = openDrawer("Offices & delegation", "WORK SCOPE", { record: null });
        const returnButton = button(
            origin?.label || "Back to holds",
            origin?.run ||
                (async () => {
                    ui.closeDrawer();
                    await load();
                }),
            "btn-link mb-3",
        );
        const status = node("p", { role: "status" }, "Loading offices…");
        body.append(returnButton, status);
        let result;
        try {
            result = await request("/sis/offices/", "POST", {});
        } catch (error) {
            if (body.isConnected)
                status.textContent =
                    error.status === 403
                        ? "Office setup access is no longer available."
                        : "Office setup could not be loaded. Return to the workspace and try again.";
            throw error;
        }
        if (!body.isConnected) return;
        body.replaceChildren(returnButton);
        body.append(
            node(
                "p",
                { class: "form-text" },
                "Office delegation narrows access. Staff also need the separate hold-management permission through Roles & access. It does not grant registration, grades, finance or general student-record access.",
            ),
        );
        if (result.can_administer) body.append(button("Add office", () => officeForm(null, back)));
        body.append(
            node(
                "p",
                { class: "form-text mt-3", role: "status" },
                `${result.items.length} offices.`,
            ),
        );
        const cards = node("ul", {
            class: "office-record-list",
            "aria-label": "Institution offices",
        });
        for (const row of result.items) {
            const actions = node("div", { class: "inline-actions office-record-actions" });
            if (result.can_administer)
                actions.append(
                    button("Manage delegates", () => delegates(row.id, { offices: back })),
                    actionMenu(
                        "Office actions",
                        [button("Edit office", () => officeForm(row, back))],
                        { context: `Office actions for ${row.name}` },
                    ),
                );
            cards.append(
                node(
                    "li",
                    { class: "office-record-card" },
                    node(
                        "div",
                        { class: "office-record-heading" },
                        node("h3", {}, row.name),
                        badge(Number(row.active) === 1 ? "Active" : "Retired"),
                    ),
                    node("p", { class: "form-text" }, row.code),
                    actions,
                ),
            );
        }
        body.append(
            result.items.length
                ? cards
                : node(
                      "p",
                      {},
                      "No offices have been added. Add an office before configuring delegation.",
                  ),
        );
    }
    async function officeForm(row = null, back = officeSetup) {
        const loading = openDrawer(row ? "Edit office" : "Add office", "OFFICE SETUP");
        loading.append(node("p", { role: "status" }, "Preparing office details…"));
        const operation = row ? null : await request("/sis/ui/operation-id/");
        if (!loading.isConnected) return;
        await flow(
            row ? "Save office" : "Create office",
            [
                ["code", "Office code"],
                ["name", "Office name"],
                ["active", "Active office", "checkbox"],
            ],
            `/sis/offices/${row?.id || operation.id}/save/`,
            (data) => ({ ...data, revision: row ? Number(row.revision) : -1 }),
            back,
            row ? { ...row, active: Number(row.active) === 1 } : { active: true },
            "Retirement prevents delegated work and new holds. Existing holds keep their registration effects and remain available to authorized administrators. No records are deleted.",
            { backLabel: "Back to offices" },
        );
    }
    async function delegates(officeId, { initial = null, offices = officeSetup } = {}) {
        let state = initial;
        let officeName = "";
        const back = () => delegates(officeId, { initial: state, offices });
        const body = openDrawer("Office delegates", "DATED ACCESS", {
            record: { key: `office:${officeId}`, label: "Office delegates", run: back },
        });
        const officeLabel = node("div", { class: "office-delegation-context" });
        body.append(
            button("Back to offices", offices, "btn-link mb-3"),
            officeLabel,
            node(
                "p",
                { class: "form-text" },
                "Delegation limits which office holds a staff member may work with. It adds no roles or permissions. Enabled delegates still need hold-management permission and an eligible date range.",
            ),
            button("Add delegate", () =>
                personSearch(
                    true,
                    "Choose a delegate",
                    async (person, searchBody) => {
                        const context = await request(
                            `/sis/offices/${officeId}/delegates/`,
                            "POST",
                            {},
                        );
                        if (!searchBody.isConnected) return;
                        await delegationForm(
                            officeId,
                            {
                                user_id: person.owner_user_id,
                                first_name: person.given_name,
                                last_name: person.family_name,
                                starts_on: context.today,
                                active: true,
                                revision: -1,
                            },
                            back,
                            context.office,
                        );
                    },
                    { back, context: officeName },
                ),
            ),
        );
        await pages(
            body,
            "delegate",
            async (after) => {
                const result = await request(`/sis/offices/${officeId}/delegates/`, "POST", {
                    after,
                });
                return result;
            },
            (rows, result) => {
                officeName = result.office.name;
                officeLabel.replaceChildren(
                    node("h3", {}, result.office.name),
                    node(
                        "p",
                        { class: "form-text mb-0" },
                        `${result.office.code} · ${Number(result.office.active) === 1 ? "Active office" : "Retired office"}`,
                    ),
                );
                return rows.length
                    ? node(
                          "ul",
                          { class: "office-record-list", "aria-label": "Office delegates" },
                          ...rows.map((row) =>
                              node(
                                  "li",
                                  { class: "office-record-card" },
                                  node(
                                      "div",
                                      { class: "office-record-heading" },
                                      node("h3", {}, `${row.first_name} ${row.last_name}`),
                                      badge(Number(row.active) === 1 ? "Enabled" : "Disabled"),
                                  ),
                                  recordSummary([
                                      ["Starts", row.starts_on],
                                      ["Ends (inclusive)", row.ends_on || "Open-ended"],
                                  ]),
                                  node(
                                      "div",
                                      { class: "inline-actions office-record-actions" },
                                      button("Edit delegation", () =>
                                          delegationForm(officeId, row, back, result.office),
                                      ),
                                  ),
                              ),
                          ),
                      )
                    : node("p", {}, "No delegates have been added.");
            },
            {
                initial,
                remember: (value) => {
                    state = value;
                },
                onDenied: () => {
                    body.replaceChildren(
                        button("Back to offices", offices, "btn-link mb-3"),
                        node(
                            "p",
                            { role: "status" },
                            "Office delegation access is no longer available.",
                        ),
                    );
                },
            },
        );
    }
    async function delegationForm(officeId, row, back, office) {
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
            back,
            { ...row, active: row.active === true || Number(row.active) === 1 },
            `${row.first_name} ${row.last_name} · ${office.name}. Both dates are inclusive and follow the institution calendar. Disable access to revoke it now. This does not add roles or permissions; each action still checks current authority.`,
            { backLabel: "Back to office delegates" },
        );
        const dates = () => {
            form.elements.ends_on.min = form.elements.starts_on.value;
        };
        form.elements.starts_on.addEventListener("input", dates);
        dates();
    }
    return { list, load, officeSetup };
};
