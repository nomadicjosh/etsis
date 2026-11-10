/* Staff operations share the workspace transport, controls and permission checks.
 * The factory receives scoped helpers; private records are never exported or stored. */
window.createWorkspaceOperations = function (ui) {
    "use strict";
    const {
        node,
        request,
        task,
        can,
        table,
        badge,
        money,
        buildForm,
        formData,
        openDrawer,
        notify,
        openRecord,
        createRecord,
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
    const fields = {
        institutions: [
            ["code", "Institution code"],
            ["name", "Institution name"],
        ],
        external: [
            ["institution_id", "Sending institution", "lookup", "institutions"],
            ["code", "External course code"],
            ["title", "External course title"],
        ],
        equivalencies: [
            ["institution_id", "Sending institution", "lookup", "institutions"],
            ["external_course_id", "External course", "lookup", "external"],
            ["course_id", "Equivalent course", "lookup", "courses"],
            ["starts_on", "Effective from", "date"],
            ["ends_on", "Effective through", "date", "", false],
            [
                "minimum_grade_rank",
                "Minimum accepted grade rank (0–10000)",
                "number",
            ],
            ["credits_milli", "Credits awarded", "credits"],
            ["awarded_grade", "Institutional grade code"],
        ],
        billing: [
            ["term_id", "Term", "lookup", "terms"],
            ["program_id", "Program (optional)", "lookup", "programs", false],
            ["name", "Rule name"],
            ["kind", "Type", "choice", ["tuition", "fee"]],
            ["amount_minor", "Amount", "money"],
            ["currency", "Currency", "currency"],
        ],
        ledger: [
            ["code", "Ledger account code"],
            ["title", "Account title"],
            ["currency", "Currency", "currency"],
        ],
    };
    const definition = (
        title,
        permission,
        endpoint,
        action,
        columns,
        description,
    ) => ({
        title,
        permission,
        endpoint,
        action,
        columns,
        description,
        icon: "journal-check",
    });
    const datasets = {
        sharedreports: definition(
            "Shared reports",
            "sis:reporting:run",
            "",
            "",
            [["Report", "name"]],
            "Reports you own or that have been explicitly shared with your account.",
        ),
        grades: definition(
            "Grade definitions",
            "sis:catalog:manage",
            "",
            "Manage grading scale",
            [
                ["Grade", "code"],
                ["GPA points × 1000", "points_milli"],
            ],
            "Set up the global grade scale and use explicit program overrides where grading policies differ.",
        ),
        teaching: definition(
            "My teaching",
            "",
            "",
            "",
            [
                ["Section", "code"],
                ["Seats", "capacity"],
            ],
            "Your assigned sections, rosters, assignments, attendance and final grades.",
        ),
        billing: definition(
            "Term billing",
            "sis:finance:post",
            "/sis/billing-rules/",
            "Define a billing rule",
            [["Rule", "short_id"]],
            "Review immutable billing rules and generate fees for a selected student batch.",
        ),
        ledger: definition(
            "Chart of accounts",
            "sis:finance:ledger",
            "/sis/ledger-accounts/",
            "Add a ledger account",
            [
                ["Account", "title"],
                ["Code", "code"],
                ["Currency", "currency"],
            ],
            "Accounts used by balanced general-ledger journals.",
        ),
        institutions: definition(
            "Transfer institutions",
            "sis:transfer:manage",
            "/sis/external-institutions/",
            "Add an institution",
            [
                ["Institution", "name"],
                ["Code", "code"],
            ],
            "Connect sending institutions, external courses and effective transfer equivalencies.",
        ),
        external: definition(
            "External courses",
            "sis:transfer:manage",
            "/sis/external-courses/",
            "Add an external course",
            [
                ["Course", "title"],
                ["Code", "code"],
            ],
            "Courses offered by your sending institutions.",
        ),
        equivalencies: definition(
            "Transfer equivalencies",
            "sis:transfer:manage",
            "/sis/transfer-equivalencies/",
            "Define an equivalency",
            [
                ["Equivalency", "short_id"],
                ["Effective", "starts_on"],
                ["Credits", "credits_milli"],
            ],
            "Award approved credit using the effective dates and grade rank defined here.",
        ),
    };
    for (const [key, value] of Object.entries(fields))
        datasets[key].fields = value;

    function section(title, ...children) {
        return node(
            "section",
            { class: "record-section", "aria-label": title },
            node("h3", {}, title),
            ...children,
        );
    }
    function scrollTable(headers, rows, caption) {
        return node(
            "div",
            {
                class: "table-responsive",
                tabindex: "0",
                role: "region",
                "aria-label": caption,
            },
            table(headers, rows, caption),
        );
    }
    async function flow(
        title,
        descriptors,
        path,
        transform,
        done,
        preset = {},
        explanation = "",
    ) {
        const body = openDrawer(title, "CAMPUS OPERATIONS");
        if (done) body.append(button("Back to record", done, "btn-link mb-3"));
        if (explanation)
            body.append(node("p", { class: "form-text" }, explanation));
        const form = await buildForm(descriptors, preset);
        const save = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            title,
        );
        form.append(save);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const data = transform
                    ? await transform(formData(form, descriptors))
                    : formData(form, descriptors);
                await request(path, "POST", data);
                await ui.refreshDirectory();
                form.reset();
                notify("Saved. The connected record has been updated.");
                if (done) await done();
            }, save);
        });
        body.append(form);
        return form;
    }
    async function paged(
        container,
        context,
        id,
        render,
        empty = "There are no records here yet.",
        endpoint = null,
    ) {
        let after = "";
        let items = [];
        const content = node("div");
        const more = button("Load more", load, "btn-outline-secondary mt-3");
        container.append(content, more);
        async function load() {
            const page = await request(
                endpoint || `/sis/ui/context/${context}/${id}/`,
                "POST",
                {
                    after,
                },
            );
            items.push(...page.items);
            after = page.next_after;
            content.replaceChildren(
                items.length
                    ? render(items, page)
                    : node("p", { class: "muted-text" }, empty),
            );
            more.hidden = !after;
        }
        await load();
    }
    async function registration(body, row) {
        const back = () => openRecord("students", row);
        const actions = section("Registration & restrictions");
        body.append(actions);
        if (row.active !== false && row.active !== 0)
            actions.append(
                button("Register for a section", () =>
                    flow(
                        "Register student",
                        [
                            [
                                "section_id",
                                "Course section",
                                "lookup",
                                "sections",
                            ],
                        ],
                        "/sis/registrations/",
                        (data) => ({
                            ...data,
                            student_id: row.id,
                            by_staff: true,
                        }),
                        back,
                        {},
                        "This checks seats, prerequisites and staff-blocking restrictions. Section and institutional fees may be posted.",
                    ),
                ),
            );
        await paged(actions, "enrollments", row.id, (rows) =>
            scrollTable(
                ["Course / section", "Status", "Action"],
                rows.map((item) => [
                    `${item.course_code} · ${item.section_code}`,
                    badge(item.status),
                    ["A", "N"].includes(item.status)
                        ? button("Drop / withdraw", () =>
                              ui.courseDrops().review(row.id, item.section_id, back),
                          )
                        : "—",
                ]),
                "Student registrations",
            ),
        );
        if (row.person_id) await ui.restrictions().list(body, row);
    }

    async function roster(body, row, sectionName) {
        return ui.teaching().section(body, row, sectionName);
    }
    async function account(body, row) {
        const data = await request(`/sis/accounts/${row.id}/`);
        const back = () => openRecord("accounts", row);
        body.append(
            section(
                "Account statement",
                node(
                    "p",
                    { class: "fs-4" },
                    `Balance: ${money(data.balance_minor, data.currency)}`,
                ),
                node("p", {}, `Institutional ID: ${data.institutional_id}`),
            ),
        );
        const actions = section("Transactions");
        body.append(actions);
        if (can("sis:finance:post"))
            actions.append(
                button(
                    "Post a transaction",
                    async () => {
                        const operation = await request(
                            "/sis/ui/operation-id/",
                        );
                        await flow(
                            "Post transaction",
                            [
                                [
                                    "type",
                                    "Transaction type",
                                    "choice",
                                    ["charge", "payment", "refund"],
                                ],
                                [
                                    "amount_minor",
                                    `Amount (${data.currency})`,
                                    "money",
                                ],
                            ],
                            `/sis/accounts/${row.id}/entries/`,
                            (values) => ({
                                ...values,
                                amount_minor: String(values.amount_minor),
                                currency: data.currency,
                                operation_id: operation.id,
                            }),
                            back,
                            {},
                            "Review the amount and transaction type before posting. A payment reduces the amount owed. Refunds require available credit. Retrying this form reuses its operation ID to prevent duplicate entries.",
                        );
                    },
                    "btn-primary",
                ),
            );
        actions.append(
            scrollTable(
                ["Entry", "Amount", "Status", "Correction"],
                data.entries.map((item) => [
                    item.type,
                    money(item.minor, item.currency),
                    item.reversed ? "Reversed" : "Posted",
                    !item.reversed && can("sis:finance:post")
                        ? button("Reverse entry", () =>
                              flow(
                                  "Confirm entry reversal",
                                  [],
                                  `/sis/accounts/${row.id}/entries/${item.operation_id}/reverse/`,
                                  null,
                                  back,
                                  {},
                                  "This records a compensating event. The original entry remains in the financial history.",
                              ),
                          )
                        : "—",
                ]),
                "Account entries",
            ),
        );
    }
    async function journal(body, row) {
        const data = await request(`/sis/journals/${row.id}/`);
        body.append(
            section(
                "Journal lines",
                badge(data.reversed ? "Reversed" : "Posted"),
                scrollTable(
                    ["Account", "Amount"],
                    data.lines.map((item) => [
                        item.account_id,
                        money(item.amount_minor, data.currency),
                    ]),
                    "Journal lines",
                ),
            ),
        );
        if (!data.reversed)
            body.append(
                button("Reverse journal", () =>
                    flow(
                        "Confirm journal reversal",
                        [],
                        `/sis/journals/${row.id}/reverse/`,
                        null,
                        () => openRecord("journals", row),
                        {},
                        "The original journal is retained. Reversal posts compensating ledger events.",
                    ),
                ),
            );
    }
    async function createJournal() {
        const body = openDrawer("Post a balanced journal", "GENERAL LEDGER");
        const base = [
            ["reference", "Unique journal reference"],
            ["posted_on", "Posting date", "date"],
            ["currency", "Currency", "currency"],
        ];
        const form = await buildForm(base);
        const lines = node("div");
        let lineNumber = 0;
        const total = node(
            "p",
            { role: "status", "aria-live": "polite", class: "form-text" },
            "Debits and credits must balance.",
        );
        async function addLine() {
            if (lines.children.length >= 100) return;
            const serial = ++lineNumber;
            const descriptors = [
                [`account_${serial}`, "Ledger account", "lookup", "ledger"],
                [
                    `amount_${serial}`,
                    "Amount (positive debit, negative credit)",
                    "signedmoney",
                ],
            ];
            const controls = await buildForm(descriptors);
            const wrapper = section(`Line ${serial}`);
            wrapper.dataset.serial = serial;
            wrapper.append(
                ...controls.children,
                button(
                    `Remove line ${serial}`,
                    async () => {
                        wrapper.remove();
                        balance();
                    },
                    "btn-outline-secondary",
                ),
            );
            lines.append(wrapper);
        }
        function balance() {
            const sum = [...lines.querySelectorAll('[name^="amount_"]')].reduce(
                (result, field) =>
                    result + Math.round(Number(field.value || 0) * 100),
                0,
            );
            total.textContent = `Journal difference: ${money(sum, form.elements.currency.value)}. ${sum === 0 ? "Balanced." : "Adjust the lines before posting."}`;
        }
        await addLine();
        await addLine();
        lines.addEventListener("input", balance);
        form.append(lines, button("Add journal line", addLine), total);
        const submit = node(
            "button",
            { type: "submit", class: "btn btn-primary mt-4" },
            "Post balanced journal",
        );
        form.append(submit);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const data = formData(form, base);
                data.lines = [...lines.children].map((wrapper) => {
                    const serial = wrapper.dataset.serial;
                    return {
                        account_id: form.elements[`account_${serial}`].value,
                        amount_minor: formData(form, [
                            [`amount_${serial}`, "Amount", "signedmoney"],
                        ])[`amount_${serial}`],
                    };
                });
                if (
                    data.lines.length < 2 ||
                    data.lines.reduce(
                        (sum, line) => sum + line.amount_minor,
                        0,
                    ) !== 0
                )
                    throw new Error(
                        "Use at least two lines whose signed amounts total zero.",
                    );
                const saved = await request("/sis/journals/", "POST", data);
                notify("Balanced journal posted.");
                await ui.refreshDirectory();
                await openRecord("journals", {
                    id: saved.id,
                    reference: data.reference,
                });
            }, submit);
        });
        body.append(form);
    }
    async function billing(body, row) {
        const rule = await request(`/sis/billing-rules/${row.id}/`);
        body.append(
            section(
                rule.name,
                badge(rule.retired ? "Retired" : "Active"),
                node(
                    "p",
                    {},
                    `${rule.kind} · ${money(rule.amount_minor, rule.currency)}`,
                ),
            ),
        );
        if (!rule.retired && can("sis:finance:post"))
            body.append(
                button("Retire billing rule", () =>
                    flow(
                        "Confirm rule retirement",
                        [],
                        `/sis/billing-rules/${row.id}/retire/`,
                        null,
                        () => openRecord("billing", row),
                        {},
                        "Existing charges remain unchanged. New billing will skip this rule.",
                    ),
                ),
            );
    }
    async function billingBatch() {
        const chosen = new Map();
        const descriptors = [
            ["term_id", "Billing term", "lookup", "terms"],
            ["student_id", "Find a student", "lookup", "students"],
        ];
        const form = await flow(
            "Generate selected student bills",
            descriptors,
            "/sis/billing-batches/",
            (data) => {
                if (!chosen.size)
                    throw new Error(
                        "Add at least one student to the reviewed batch.",
                    );
                return {
                    term_id: data.term_id,
                    students: [...chosen.keys()].map((student_id) => ({
                        student_id,
                    })),
                };
            },
            async () => {
                ui.closeDrawer();
                await ui.navigate("billing");
            },
            {},
            "Only selected students are billed, up to 100 per batch. Active rules for the term and program apply once. Previously posted charges remain unchanged.",
        );
        form.elements.student_id.required = false;
        const selected = node("div", {
            class: "record-section",
            "aria-live": "polite",
        });
        const add = button("Add student to batch", async () => {
            const select = form.elements.student_id;
            if (!select.value) return;
            if (chosen.size >= 100)
                throw new Error("A batch supports up to 100 students.");
            chosen.set(select.value, select.selectedOptions[0].textContent);
            render();
        });
        function render() {
            selected.replaceChildren(
                node("h3", {}, `${chosen.size} selected students`),
                ...[...chosen].map(([id, label]) =>
                    node(
                        "p",
                        {},
                        label,
                        " ",
                        button(
                            "Remove",
                            async () => {
                                chosen.delete(id);
                                render();
                            },
                            "btn-link",
                        ),
                    ),
                ),
            );
        }
        form.insertBefore(add, form.lastElementChild);
        form.insertBefore(selected, form.lastElementChild);
        render();
    }
    async function campaign(body, row) {
        const data = await request(`/sis/campaigns/${row.id}/`);
        const back = () => openRecord("campaigns", row);
        body.append(
            section(
                data.subject,
                node("p", { class: "message-preview" }, data.body),
                badge(data.status),
            ),
        );
        if (data.status === "draft")
            body.append(
                button("Edit draft", () =>
                    flow(
                        "Save message draft",
                        [
                            ["subject", "Subject"],
                            ["body", "Message", "textarea"],
                        ],
                        `/sis/campaigns/${row.id}/draft/`,
                        (values) => ({
                            ...values,
                            version: Number(data.version),
                        }),
                        back,
                        data,
                    ),
                ),
            );
        if (data.status === "draft")
            body.append(
                button(
                    "Choose recipients",
                    async () => {
                        const form = await flow(
                            "Queue reviewed recipients",
                            [
                                [
                                    "person_id",
                                    "Person with sign-in access",
                                    "lookup",
                                    "people",
                                ],
                            ],
                            `/sis/campaigns/${row.id}/queue/`,
                            () => {
                                if (!recipients.size)
                                    throw new Error(
                                        "Add at least one recipient.",
                                    );
                                return {
                                    recipients: [...recipients.keys()].map(
                                        (user_id) => ({
                                            user_id,
                                        }),
                                    ),
                                };
                            },
                            back,
                            {},
                            "Queueing stores references only. Sending is a separate deliberate action.",
                        );
                        form.elements.person_id.required = false;
                        const recipients = new Map();
                        const selected = node("div", {
                            class: "record-section",
                            "aria-live": "polite",
                        });
                        const add = button("Add recipient", async () => {
                            const id = form.elements.person_id.value;
                            if (!id) return;
                            const person = (
                                await request(`/sis/ui/people/${id}/`)
                            ).person;
                            if (!person.ownerUserId)
                                throw new Error(
                                    "Enable sign-in access on this person before adding them.",
                                );
                            if (recipients.size >= 500)
                                throw new Error(
                                    "Select no more than 500 recipients.",
                                );
                            recipients.set(
                                person.ownerUserId.value,
                                `${person.givenName} ${person.familyName}`,
                            );
                            render();
                        });
                        function render() {
                            selected.replaceChildren(
                                node("h3", {}, `${recipients.size} recipients`),
                                ...[...recipients].map(([id, label]) =>
                                    node(
                                        "p",
                                        {},
                                        label,
                                        " ",
                                        button(
                                            "Remove",
                                            async () => {
                                                recipients.delete(id);
                                                render();
                                            },
                                            "btn-link",
                                        ),
                                    ),
                                ),
                            );
                        }
                        form.insertBefore(add, form.lastElementChild);
                        form.insertBefore(selected, form.lastElementChild);
                        render();
                    },
                    "btn-primary",
                ),
            );
        if (["queued", "paused"].includes(data.status))
            body.append(
                button(
                    data.status === "paused"
                        ? "Resume campaign"
                        : "Pause campaign",
                    async () => {
                        await request(
                            `/sis/campaigns/${row.id}/pause/`,
                            "POST",
                            {
                                pause: data.status !== "paused",
                            },
                        );
                        await back();
                    },
                ),
            );
        if (!data.can_dispatch)
            body.append(
                node(
                    "p",
                    { class: "alert alert-info" },
                    "Mail delivery is disabled or its sender configuration needs attention. Drafts and the queue remain available. Ask an administrator to configure delivery before sending.",
                ),
            );
        const deliveries = section("Delivery queue");
        body.append(deliveries);
        deliveries.append(
            node(
                "p",
                { class: "form-text" },
                "Only queued deliveries can be sent. An uncertain delivery stays held for reconciliation and cannot be automatically retried.",
            ),
        );
        await paged(deliveries, "deliveries", row.id, (rows) =>
            scrollTable(
                ["Recipient account", "Status", "Action"],
                rows.map((item) => [
                    [item.given_name, item.family_name]
                        .filter(Boolean)
                        .join(" ") ||
                        `Account …${item.recipient_user_id.slice(-8)}`,
                    badge(item.status),
                    item.status === "queued" &&
                    data.status === "queued" &&
                    data.can_dispatch
                        ? button("Review and send", () =>
                              flow(
                                  "Confirm send to this recipient",
                                  [],
                                  `/sis/deliveries/${item.id}/dispatch/`,
                                  null,
                                  back,
                                  {},
                                  "This sends the saved campaign to the selected account through the configured mail transport. Verify the content and recipient before confirming.",
                              ),
                          )
                        : "—",
                ]),
                "Campaign deliveries",
            ),
        );
    }
    async function editCatalog(kind, row) {
        if (kind === "terms") return ui.calendar().editTerm(row.id);
        const saved = await request(`/sis/ui/catalog/${kind}/${row.id}/`);
        let descriptors = ui.datasets[kind].fields.map((field) => [...field]);
        if (kind === "sections")
            descriptors = descriptors.filter(
                ([key]) => !["course_id", "term_id"].includes(key),
            );
        if (["courses", "sections"].includes(kind))
            descriptors.push(["active", "Active", "checkbox"]);
        const preset = { ...saved };
        if ("credits_milli" in preset)
            preset.credits_milli = Number(preset.credits_milli) / 1000;
        if ("fee_minor" in preset)
            preset.fee_minor = Number(preset.fee_minor) / 100;
        await flow(
            "Save catalog changes",
            descriptors,
            `/sis/ui/catalog/${kind}/${row.id}/`,
            (data) => ({
                ...data,
                revision: saved.revision,
                faculty_user_id: data.faculty_user_id || null,
            }),
            async () => {
                const fresh = await request(
                    `/sis/ui/catalog/${kind}/${row.id}/`,
                );
                await openRecord(kind, fresh);
            },
            preset,
            "Existing academic credits and posted charges keep their recorded values. Term dates affect future registration decisions. A concurrent edit requires you to reload before saving.",
        );
    }
    async function audit(sectionName, row, subjectId = row.id) {
        const surface = openDrawer("Record audit history", "AUDIT METADATA");
        surface.append(
            button(
                "Back to record",
                () => openRecord(sectionName, row),
                "btn-link",
            ),
        );
        await paged(surface, "audit", subjectId, (rows) =>
            scrollTable(
                ["Action", "Recorded at (UTC)", "Actor account"],
                rows.map((item) => [
                    item.action,
                    item.recorded_at,
                    item.actor_id,
                ]),
                "Audit events",
            ),
        );
    }
    async function enhance(sectionName, row, body) {
        if (sectionName === "programs" && can("sis:catalog:manage"))
            body.append(
                button("Degree audit requirements", () => ui.degreeAudit().launch(row.id)),
                button("Program grading scale", () => ui.degreeAudit().gradingScale(row.id)),
            );
        if (sectionName === "programs" && can("sis:catalog:manage"))
            body.append(button("Academic years", () => ui.calendar().years()));
        if (sectionName === "courses" && can("sis:catalog:manage"))
            body.append(button("Clone as a course draft", () => ui.catalog().clone(row.id)));
        if (sectionName === "courses" && can("sis:catalog:manage"))
            body.append(button("Published definitions", () => ui.catalog().publications(row.id)));
        if (sectionName === "courses" && can("sis:catalog:manage"))
            body.append(button("Course subject", () => ui.catalog().courseSubject(row.id)));
            body.append(button("Titles & description", () => ui.catalog().courseText(row.id)));
        if (sectionName === "sections" && can("sis:catalog:manage"))
            body.append(button("Course definition", () => ui.catalog().sectionDefinition(row.id)));
        if (sectionName === "sections" && can("sis:catalog:manage"))
            body.append(button("Published section details", () => ui.catalog().sectionPublications(row.id)));
        if (["sections", "teaching"].includes(sectionName) && can("sis:registration:manage"))
            body.append(button("Register a group into this section", () => ui.batchRegistration().start(row.id)));
        if (
            ["courses", "sections", "programs", "terms"].includes(
                sectionName,
            ) &&
            can("sis:catalog:manage")
        )
            body.append(
                button("Edit catalog record", () =>
                    editCatalog(sectionName, row),
                ),
            );
        if (can("sis:audit:read"))
            body.append(
                button("View audit history", () => audit(sectionName, row)),
            );
        if (sectionName === "students" && can("sis:registration:manage"))
            await registration(body, row);
        if (["sections", "teaching"].includes(sectionName))
            await roster(body, row, sectionName);
        if (
            sectionName === "reports" &&
            can("sis:reporting:manage") &&
            can("sis:person:manage")
        )
            body.append(
                button("Share report", async () => {
                    await flow(
                        "Share report with account",
                        [
                            [
                                "person_id",
                                "Person with sign-in access",
                                "lookup",
                                "people",
                            ],
                        ],
                        `/sis/reports/${row.id}/shares/`,
                        async (data) => {
                            const person = (
                                await request(
                                    `/sis/ui/people/${data.person_id}/`,
                                )
                            ).person;
                            if (!person.ownerUserId)
                                throw new Error(
                                    "This person needs sign-in access before a report can be shared.",
                                );
                            return { user_id: person.ownerUserId.value };
                        },
                        () => openRecord(sectionName, row),
                        {},
                        "The recipient must also hold permission to run reports. Sharing gives access to this report’s student results.",
                    );
                }),
            );
        if (sectionName === "reports" && can("sis:reporting:manage"))
            body.append(
                button("Manage report sharing", async () => {
                    const surface = openDrawer(
                        "Report sharing",
                        "CONTROL ACCESS TO THIS REPORT",
                    );
                    surface.append(
                        button(
                            "Back to report",
                            () => openRecord(sectionName, row),
                            "btn-link",
                        ),
                    );
                    surface.append(
                        node(
                            "p",
                            { class: "form-text" },
                            "Revoke a share to remove that account’s shared access immediately. The report owner and explicitly authorized report administrators retain their own access.",
                        ),
                    );
                    await paged(
                        surface,
                        "",
                        row.id,
                        (rows) =>
                            scrollTable(
                                ["Account", "Action"],
                                rows.map((item) => [
                                    [item.given_name, item.family_name]
                                        .filter(Boolean)
                                        .join(" ") ||
                                        `Account …${item.user_id.slice(-8)}`,
                                    button("Revoke share", () =>
                                        flow(
                                            "Confirm share revocation",
                                            [],
                                            `/sis/reports/${row.id}/shares/${item.user_id}/revoke/`,
                                            null,
                                            () => openRecord(sectionName, row),
                                            {},
                                            "This removes this report share. It does not change the account’s roles or grants for other reports.",
                                        ),
                                    ),
                                ]),
                                "Report shares",
                            ),
                        "This report has no explicit shares.",
                        `/sis/reports/${row.id}/shares/list/`,
                    );
                }),
            );
        if (sectionName === "accounts") await account(body, row);
        if (sectionName === "journals") await journal(body, row);
        if (sectionName === "billing") await billing(body, row);
        if (sectionName === "campaigns") await campaign(body, row);
        if (sectionName === "terms" && can("sis:catalog:manage")) {
            body.append(button("Drop / withdrawal policy", () => ui.courseDrops().policy(row, () => openRecord(sectionName, row))));
            const panel = section("Registration window");
            body.append(panel);
            await paged(panel, "window", row.id, (rows) =>
                scrollTable(
                    ["Opens", "Closes", "Course limit"],
                    rows.map((item) => [
                        item.opens_at,
                        item.closes_at,
                        item.course_limit,
                    ]),
                    "Registration window",
                ),
            );
            panel.append(
                button("Configure registration window", () =>
                    flow(
                        "Save registration window",
                        [
                            [
                                "opens_at",
                                "Registration opens (local time)",
                                "datetime-local",
                            ],
                            [
                                "closes_at",
                                "Registration closes (local time)",
                                "datetime-local",
                            ],
                            [
                                "course_limit",
                                "Maximum registered courses",
                                "number",
                            ],
                        ],
                        `/sis/terms/${row.id}/registration-window/`,
                        null,
                        () => openRecord(sectionName, row),
                    ),
                ),
            );
        }
        if (sectionName === "courses" && can("sis:catalog:manage")) {
            const panel = section("Prerequisites");
            body.append(panel);
            await paged(panel, "prerequisites", row.id, (rows) =>
                scrollTable(
                    ["Required course", "Action"],
                    rows.map((item) => [
                        `${item.code} · ${item.title}`,
                        button("Remove prerequisite", () =>
                            flow(
                                "Confirm prerequisite removal",
                                [],
                                `/sis/courses/${row.id}/prerequisites/${item.required_course_id}/remove/`,
                                null,
                                () => openRecord(sectionName, row),
                                {},
                                "This changes eligibility for future registrations. Existing academic credits remain unchanged.",
                            ),
                        ),
                    ]),
                    "Course prerequisites",
                ),
            );
            panel.append(
                button("Add prerequisite", () =>
                    flow(
                        "Add prerequisite",
                        [
                            [
                                "required_course_id",
                                "Required course",
                                "lookup",
                                "courses",
                            ],
                        ],
                        `/sis/courses/${row.id}/prerequisites/`,
                        null,
                        () => openRecord(sectionName, row),
                    ),
                ),
            );
        }
    }
    return {
        datasets,
        enhance,
        createJournal,
        billingBatch,
        flow,
        audit,
        registration,
    };
};
