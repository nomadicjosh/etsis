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
            ["reason", "Reason for billing rule", "textarea"],
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
            [["Rule", "name"], ["Term", "term_code"], ["Program", "program_label"],
                ["Type", "kind"], ["Amount", "billing_amount"], ["Status", "status"]],
            "Find fees by name, term, program, currency or status. Open a rule to review or retire it; earlier charges remain unchanged.",
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
                const result = await request(path, "POST", data);
                await ui.refreshDirectory();
                form.reset();
                notify(
                    result?.status === "already_recorded"
                        ? "Already recorded. The original details and later changes are preserved."
                        : "Saved. The connected record has been updated.",
                );
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
        const actions = section("Registration");
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

    }

    async function roster(body, row, sectionName) {
        return ui.teaching().section(body, row, sectionName);
    }
    async function openAccount(id, back) {
        const body = openDrawer("Student account", "FINANCE OFFICE");
        body.append(button("Back to finance task", back, "btn-link"));
        await account(body, { id }, () => openAccount(id, back));
    }
    async function account(body, row, reopen = null) {
        const data = await request(`/sis/accounts/${row.id}/`);
        if (!body.isConnected) return;
        const back = reopen || (() => openRecord("accounts", row));
        body.append(
            section(
                "Account statement",
                node("p", { class: "fs-4" }, `Balance: ${money(data.balance_minor, data.currency)}`),
                node("p", {}, `Institutional ID: ${data.institutional_id}`),
            ),
        );
        const paymentMethods = [
            ["cash", "Cash"],
            ["check", "Check"],
            ["card", "Card"],
            ["bank_transfer", "Bank transfer"],
            ["wire_transfer", "Wire transfer"],
            ["money_order", "Money order"],
            ["paypal", "PayPal"],
            ["loan", "Loan"],
            ["grant", "Grant"],
            ["scholarship", "Scholarship"],
            ["other", "Other"],
        ].map(([value, label]) => ({ value, label }));
        function receiptText(details) {
            if (!details) return "Payment method and receipt reference were not recorded.";
            const label = paymentMethods.find((option) => option.value === details.method)?.label || "Not recorded";
            return `Payment method: ${label} · Receipt reference: ${details.reference || "Not recorded"}`;
        }
        async function reviewEntry(item = null, action = item ? "amount" : "posting") {
            const correcting = item !== null,
                receiptOnly = action === "receipt",
                reversing = action === "reversal",
                crediting = action === "credit";
            const copy = {
                posting: {
                    title: "Post transaction",
                    reason: "Reason for transaction",
                    review: "Review transaction",
                    region: "Reviewed transaction",
                    confirm: "Confirm transaction",
                    path: "",
                    introduction:
                        "A payment reduces the amount owed. Refunds require available credit. Review the amount and resulting balance before recording the transaction.",
                    explanation:
                        "This records institutional account activity only. It does not collect a payment or send a refund. Review expires in 20 minutes. Confirm only after checking the actual transaction.",
                },
                amount: {
                    title: "Correct a recorded amount",
                    reason: "Reason for amount correction",
                    review: "Review amount correction",
                    region: "Reviewed amount correction",
                    confirm: "Confirm amount correction",
                    path: `${item?.operation_id}/correction/`,
                    introduction:
                        "Correct a staff-recorded amount in its original billing account and currency. The transaction type stays the same.",
                    explanation:
                        "The original entry remains as reversed and links to its replacement. This corrects the account record only; no payment or refund is sent. Review expires in 20 minutes.",
                },
                receipt: {
                    title: "Correct receipt details",
                    reason: "Reason for receipt correction",
                    review: "Review receipt correction",
                    region: "Reviewed receipt correction",
                    confirm: "Confirm receipt correction",
                    path: `${item?.operation_id}/receipt/`,
                    introduction:
                        "Correct the optional payment method or receipt reference. The original receipt and any amount replacements share these details. Leave both blank to clear them with a recorded reason.",
                    explanation:
                        "This changes receipt information only. Earlier values remain in the restricted history. No money moves and the account balance stays the same. Review expires in 20 minutes.",
                },
                credit: {
                    title: "Credit a charge",
                    reason: "Reason for charge credit",
                    review: "Review charge credit",
                    region: "Reviewed charge credit",
                    confirm: "Confirm charge credit",
                    path: `${item?.operation_id}/credit/`,
                    introduction:
                        "Reduce or waive this specific charge. Enter the amount to credit, up to its remaining amount. The original charge and enrollment stay in history.",
                    explanation:
                        "This creates a linked account credit. It does not send a refund. Reversing the charge later also reverses its remaining linked credits. Review expires in 20 minutes.",
                },
                reversal: {
                    title: "Reverse a recorded entry",
                    reason: "Reason for reversal",
                    review: "Review entry reversal",
                    region: "Reviewed entry reversal",
                    confirm: "Confirm entry reversal",
                    path: `${item?.operation_id}/reverse/`,
                    introduction:
                        "Reverse this posting in its original billing account. Review the amount, resulting balance and reason. The original entry stays in history.",
                    explanation:
                        "This reverses the account record only. No bank payment, gateway refund or academic registration is changed. Review expires in 20 minutes.",
                },
            }[action];
            const entryBody = openDrawer(copy.title, "STUDENT ACCOUNT");
            const descriptors = [
                ...(!correcting ? [["type", "Transaction type", "choice", ["charge", "payment", "refund"]]] : []),
                ...(!receiptOnly && !reversing
                    ? [
                          [
                              "amount_minor",
                              `${crediting ? "Amount to credit" : correcting ? "Corrected amount" : "Amount"} (${data.currency})`,
                              "money",
                          ],
                      ]
                    : []),
                ...(!correcting || receiptOnly
                    ? [
                          ["payment_method", "Payment method", "choice", paymentMethods, false],
                          ["payment_reference", "Receipt or check reference", "text", "", false],
                      ]
                    : []),
                ["reason", copy.reason, "textarea"],
            ];
            const loading = node("p", { role: "status" }, "Opening transaction…");
            entryBody.append(button("Back to account statement", back, "btn-link"), loading);
            const receipt = receiptOnly
                ? await request(`/sis/accounts/${row.id}/entries/${item.operation_id}/receipt/`, "POST", {})
                : null;
            if (!loading.isConnected) return;
            // Opening another drawer while a private read is pending must never render these details there.
            if (receipt && receipt.position !== data.position) {
                entryBody.append(
                    node(
                        "p",
                        { role: "status" },
                        "This account changed. Return to the statement and reopen receipt details.",
                    ),
                );
                loading.remove();
                return;
            }
            const form = await buildForm(
                descriptors,
                receipt
                    ? {
                          payment_method: receipt.payment_details?.method || "",
                          payment_reference: receipt.payment_details?.reference || "",
                      }
                    : {},
            );
            if (!loading.isConnected) return;
            loading.remove();
            const method = form.elements.namedItem("payment_method");
            if (method) method.options[0].textContent = "Not recorded";
            const reference = form.elements.namedItem("payment_reference");
            if (reference) reference.maxLength = 120;
            const receiptControls = ["payment_method", "payment_reference"]
                .map((key) => form.elements.namedItem(key))
                .filter(Boolean);
            const receiptHint = node(
                "p",
                { class: "form-text", id: `receipt-hint-${row.id}` },
                "Optional receipt information for finance staff only. Do not enter card numbers, bank account numbers or credentials.",
            );
            receiptControls.forEach((control) => control.setAttribute("aria-describedby", receiptHint.id));
            if (receiptControls.length) form.append(receiptHint);
            function toggleReceipt() {
                const available = receiptOnly || form.elements.namedItem("type")?.value !== "charge";
                receiptControls.forEach((control) => {
                    control.disabled = !available;
                    control.closest(".mb-4").hidden = !available;
                });
                receiptHint.hidden = !available;
            }
            form.addEventListener("change", toggleReceipt);
            toggleReceipt();
            function values() {
                const result = formData(form, descriptors);
                if (result.type === "charge") {
                    delete result.payment_method;
                    delete result.payment_reference;
                }
                return result;
            }
            form.dataset.currency = data.currency;
            form.dispatchEvent(new Event("change"));
            const submit = node("button", { type: "submit", class: "btn btn-primary" }, copy.review);
            const preview = node("section", {
                class: "record-section",
                "aria-label": copy.region,
                hidden: true,
            });
            let reviewed = null,
                confirming = false,
                revision = 0;
            const clear = () => {
                revision++;
                reviewed = null;
                preview.hidden = true;
                preview.replaceChildren();
            };
            form.addEventListener("input", clear);
            form.addEventListener("change", clear);
            const base = `/sis/accounts/${row.id}/entries/${copy.path}`;
            form.append(submit);
            form.addEventListener("submit", (event) => {
                event.preventDefault();
                task(async () => {
                    if (confirming || !form.reportValidity()) return;
                    clear();
                    const entered = values(),
                        signature = JSON.stringify(entered),
                        epoch = revision;
                    const result = await request(`${base}review/`, "POST", {
                        ...entered,
                        currency: data.currency,
                        position: data.position,
                    });
                    if (!form.isConnected || epoch !== revision || signature !== JSON.stringify(values())) return;
                    reviewed = result;
                    preview.hidden = false;
                    preview.replaceChildren(
                        ...[
                            node("h3", { class: "h5" }, "Review before confirming"),
                            node(
                                "p",
                                {},
                                receiptOnly
                                    ? `${result.type}: ${money(result.amount_minor, result.currency)} · Amount unchanged`
                                    : crediting
                                      ? `Charge credit: ${money(-result.amount_minor, result.currency)}`
                                      : reversing
                                        ? `Reverse ${result.type}: ${money(result.amount_minor, result.currency)}`
                                        : correcting
                                          ? `${result.type}: ${money(result.original_minor, result.currency)} → ${money(result.corrected_minor, result.currency)}`
                                          : `${result.type}: ${money(result.amount_minor, result.currency)}`,
                            ),
                            node(
                                "p",
                                {},
                                `Account balance: ${money(result.balance_minor, result.currency)} → ${money(result.resulting_balance_minor, result.currency)}`,
                            ),
                            crediting
                                ? node(
                                      "p",
                                      {},
                                      `Original charge: ${money(result.charge_minor, result.currency)} · Previously credited: ${money(result.credited_minor, result.currency)} · Charge remaining: ${money(result.remaining_charge_minor, result.currency)}`,
                                  )
                                : null,
                            result.reversed_credit_count > 0
                                ? node(
                                      "p",
                                      { class: "fw-semibold" },
                                      `Also reverses ${result.reversed_credit_count} linked charge credits totaling ${money(result.reversed_credit_minor, result.currency)}.`,
                                  )
                                : null,
                            result.type === "refund"
                                ? node(
                                      "p",
                                      { class: "fw-semibold" },
                                      `Available credit: ${money(result.available_credit_minor, result.currency)} → ${money(result.remaining_credit_minor, result.currency)}`,
                                  )
                                : null,
                            receiptOnly
                                ? node(
                                      "p",
                                      { class: "text-break" },
                                      `Previous: ${receiptText(result.previous_payment_details)}`,
                                  )
                                : null,
                            ["charge", "charge_credit"].includes(result.type)
                                ? null
                                : node("p", { class: "text-break" }, receiptText(result.payment_details)),
                            node("p", { class: "text-break", style: "white-space: pre-wrap" }, result.reason),
                            node("p", { class: "form-text" }, copy.explanation),
                            button(
                                copy.confirm,
                                async () => {
                                    if (confirming || !reviewed) return;
                                    const accepted = reviewed;
                                    confirming = true;
                                    form.querySelectorAll("input, textarea, select, button").forEach(
                                        (control) => (control.disabled = true),
                                    );
                                    try {
                                        await request(`${base}commit/`, "POST", {
                                            review_token: accepted.review_token,
                                        });
                                        await back();
                                    } finally {
                                        confirming = false;
                                        if (form.isConnected) {
                                            form.querySelectorAll("input, textarea, select, button").forEach(
                                                (control) => (control.disabled = false),
                                            );
                                            toggleReceipt();
                                        }
                                    }
                                },
                                "btn-primary",
                            ),
                        ].filter(Boolean),
                    );
                }, submit);
            });
            entryBody.append(
                node(
                    "p",
                    {},
                    correcting
                        ? `${data.institutional_id} · ${item.type.replaceAll("_", " ")} · ${money(item.minor, item.currency)} · ${item.operation_id.slice(-8)}`
                        : `${data.institutional_id} · ${data.currency}`,
                ),
                node("p", { class: "form-text" }, copy.introduction),
                button("Refresh account balance", async () => {
                    if (confirming) return;
                    clear();
                    const latest = await request(`/sis/accounts/${row.id}/`);
                    if (!form.isConnected) return;
                    data.position = latest.position;
                    data.balance_minor = latest.balance_minor;
                    preview.hidden = false;
                    preview.replaceChildren(
                        node(
                            "p",
                            { role: "status" },
                            `Current balance: ${money(latest.balance_minor, latest.currency)}. Review ${correcting ? "the correction" : "the transaction"} again.`,
                        ),
                    );
                }),
                form,
                preview,
            );
        }
        const actions = section("Transactions");
        body.append(actions);
        async function entryHistory(operation, after = 0, through = null, stack = []) {
            const historyBody = openDrawer("Entry history", "PRIVATE FINANCE HISTORY");
            const panel = node("div", {}, node("p", { role: "status" }, "Loading entry history…"));
            historyBody.append(
                button("Back to account statement", back, "btn-link"),
                button("Refresh entry history", () => entryHistory(operation)),
                panel,
            );
            const result = await request(`/sis/accounts/${row.id}/entries/${operation}/history/`, "POST", {
                after,
                ...(through === null ? {} : { through_position: through }),
            });
            if (!panel.isConnected) return;
            const current = result.entry;
            panel.replaceChildren(
                ...[
                    node(
                        "p",
                        { class: "fw-semibold" },
                        `${data.institutional_id} · ${current.type} · ${money(current.minor, current.currency)}`,
                    ),
                    node(
                        "p",
                        {},
                        `Selected entry: ${operation.slice(-8)} · ${current.reversed ? "Reversed" : "Posted"}`,
                    ),
                    node(
                        "p",
                        { class: "form-text" },
                        "This history reflects the activity captured when opened. Refresh to include later changes. Names use current account display names; earlier attribution is labeled separately. Times use your browser’s time zone.",
                    ),
                    result.earlier_details_unknown
                        ? node(
                              "p",
                              { class: "alert alert-info" },
                              "Some earlier details were not recorded. Missing reasons, action times and attribution remain unknown.",
                          )
                        : null,
                ].filter(Boolean),
            );
            const timeline = node("section", { "aria-label": "Entry activity" });
            const dates = new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "long" });
            const timestamp = (value) => {
                const date = new Date(value);
                return node("time", { datetime: value }, Number.isFinite(date.getTime()) ? dates.format(date) : value);
            };
            result.items.forEach((item) =>
                timeline.append(
                    node(
                        "article",
                        { class: "record-section" },
                        node(
                            "h3",
                            { class: "h5" },
                            {
                                posted: "Entry posted",
                                corrected: "Amount corrected",
                                reversed: "Entry reversed",
                                receipt_corrected: "Receipt details corrected",
                                credited: "Charge credited",
                            }[item.action],
                        ),
                        node(
                            "p",
                            {},
                            item.action === "corrected"
                                ? `${item.type}: ${money(item.previous_minor, item.currency)} → ${money(item.amount_minor, item.currency)}`
                                : `${item.type.replaceAll("_", " ")}: ${money(item.type === "charge_credit" ? -item.amount_minor : item.amount_minor, item.currency)}`,
                        ),
                        node(
                            "p",
                            { class: "form-text" },
                            item.action === "credited"
                                ? `Credit for charge ${item.source_operation_id.slice(-8)} · Reference: ${item.operation_id.slice(-8)}`
                                : item.action === "corrected"
                                  ? `Reference: ${item.source_operation_id.slice(-8)} → ${item.operation_id.slice(-8)}`
                                  : `Reference: ${item.operation_id.slice(-8)}`,
                        ),
                        item.action === "receipt_corrected"
                            ? node(
                                  "p",
                                  { class: "text-break" },
                                  `Previous: ${receiptText(item.previous_payment_details)}`,
                              )
                            : null,
                        item.reversed_credit_ids?.length
                            ? node("p", {}, `Also reversed ${item.reversed_credit_ids.length} linked charge credits.`)
                            : null,
                        item.type !== "charge" && Object.hasOwn(item, "payment_details")
                            ? node("p", { class: "text-break" }, receiptText(item.payment_details))
                            : null,
                        node(
                            "p",
                            {},
                            `Recorded by: ${item.recorded_by}`,
                            item.actor_source === "earlier_event"
                                ? " · Earlier event attribution"
                                : item.actor_source === "unknown"
                                  ? " · Attribution unavailable"
                                  : "",
                        ),
                        node("p", {}, "Recorded: ", timestamp(item.recorded_at)),
                        item.effective_at
                            ? node("p", {}, "Action time: ", timestamp(item.effective_at))
                            : node("p", { class: "form-text" }, "Action time was not recorded."),
                        node(
                            "p",
                            { class: "text-break", style: "white-space: pre-wrap" },
                            item.reason || "No reason was recorded.",
                        ),
                    ),
                ),
            );
            panel.append(timeline);
            if (stack.length)
                panel.append(
                    button("Previous entry activity", () =>
                        entryHistory(operation, stack.at(-1), result.through_position, stack.slice(0, -1)),
                    ),
                );
            if (result.next_after !== null)
                panel.append(
                    button("More entry activity", () =>
                        entryHistory(operation, result.next_after, result.through_position, [...stack, after]),
                    ),
                );
        }
        if (can("sis:finance:post") && can("sis:finance:read"))
            actions.append(button("Post a transaction", () => reviewEntry(), "btn-primary"));
        function entryActions(item) {
            const controls = [];
            const canRead = can("sis:finance:read"),
                canPost = can("sis:finance:post");
            if (canRead && canPost)
                controls.push(
                    button(`View ${item.operation_id.slice(-8)} entry history`, () => entryHistory(item.operation_id)),
                );
            if (canRead && canPost && item.can_correct_payment_details)
                controls.push(button("Correct receipt details", () => reviewEntry(item, "receipt")));
            if (!item.reversed && canPost && canRead) {
                if (item.can_credit_charge) controls.push(button("Credit charge", () => reviewEntry(item, "credit")));
                if (item.can_correct_amount && canRead)
                    controls.push(button("Correct amount", () => reviewEntry(item)));
                controls.push(button("Reverse entry", () => reviewEntry(item, "reversal")));
            }
            return controls.length ? node("div", { class: "d-flex flex-wrap gap-2" }, ...controls) : "—";
        }
        const entries = node("div");
        let offset = 0;
        function renderEntries() {
            entries.replaceChildren(
                scrollTable(
                    ["Entry", "Reference", "Amount", "Status", "Actions"],
                    data.entries
                        .slice(offset, offset + 25)
                        .map((item) => [
                            item.type.replaceAll("_", " "),
                            node(
                                "div",
                                {},
                                node("code", { title: item.operation_id }, item.operation_id.slice(-8)),
                                item.source_operation_id
                                    ? node(
                                          "p",
                                          { class: "form-text mb-0" },
                                          `Credit for ${item.source_operation_id.slice(-8)}`,
                                      )
                                    : null,
                                item.linked_credit_count > 0
                                    ? node(
                                          "p",
                                          { class: "form-text mb-0" },
                                          `Credited ${money(item.credited_minor, item.currency)} · Remaining ${money(item.remaining_charge_minor, item.currency)}`,
                                      )
                                    : null,
                                item.corrects_operation_id
                                    ? node(
                                          "p",
                                          { class: "form-text mb-0" },
                                          `Corrects ${item.corrects_operation_id.slice(-8)}`,
                                      )
                                    : null,
                                item.corrected_by_operation_id
                                    ? node(
                                          "p",
                                          { class: "form-text mb-0" },
                                          `Corrected by ${item.corrected_by_operation_id.slice(-8)}`,
                                      )
                                    : null,
                            ),
                            money(item.type === "charge_credit" ? -item.minor : item.minor, item.currency),
                            item.reversed
                                ? item.reversed_with_operation_id
                                    ? "Reversed with charge"
                                    : "Reversed"
                                : "Posted",
                            entryActions(item),
                        ]),
                    "Account entries",
                ),
                node(
                    "p",
                    { role: "status" },
                    `${data.entries.length ? offset + 1 : 0}–${Math.min(offset + 25, data.entries.length)} of ${data.entries.length} entries`,
                ),
            );
            if (offset)
                entries.append(
                    button("Previous account entries", () => {
                        offset -= 25;
                        renderEntries();
                    }),
                );
            if (offset + 25 < data.entries.length)
                entries.append(
                    button("Next account entries", () => {
                        offset += 25;
                        renderEntries();
                    }),
                );
        }
        actions.append(entries);
        renderEntries();
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
                        [["reason", "Reason for journal reversal", "textarea"]],
                        `/sis/journals/${row.id}/reverse/`,
                        (values) => ({ ...values, position: data.position }),
                        () => openRecord("journals", row),
                        {},
                        "Enter why this journal must be reversed. The original journal remains in the financial history.",
                    ),
                ),
            );
    }
    async function createJournal() {
        const operation = await request("/sis/ui/operation-id/");
        const body = openDrawer("Post a balanced journal", "GENERAL LEDGER");
        const base = [
            ["reference", "Unique journal reference"],
            ["posted_on", "Posting date", "date"],
            ["currency", "Currency", "currency"],
            ["reason", "Reason for journal", "textarea"],
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
            form.dispatchEvent(new Event('change'));
        }
        function balance() {
            try {
                const sum = [...lines.querySelectorAll('[name^="amount_"]')].reduce((result, field) =>
                    result + (field.value ? formData(form, [[field.name, "Amount", "signedmoney"]])[field.name] : 0), 0);
                total.textContent = `Journal difference: ${money(sum, form.elements.currency.value)}. ${sum === 0 ? "Balanced." : "Adjust the lines before posting."}`;
            } catch (error) {
                total.textContent = error.message;
            }
        }
        await addLine();
        await addLine();
        lines.addEventListener("input", balance);
        form.elements.currency.addEventListener("change", balance);
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
                const data = { ...formData(form, base), operation_id: operation.id };
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
                    `${rule.kind} · ${rule.amount_decimal === null ? `${rule.amount_minor} minor units` : rule.amount_decimal} ${rule.currency}`,
                ),
            ),
        );
        if (!rule.retired && can("sis:finance:post"))
            body.append(
                button("Retire billing rule", () =>
                    flow(
                        "Confirm rule retirement",
                        [["reason", "Reason for rule retirement", "textarea"]],
                        `/sis/billing-rules/${row.id}/retire/`,
                        (values) => ({ ...values, position: rule.position }),
                        () => openRecord("billing", row),
                        {},
                        "Existing charges remain unchanged. New billing will skip this rule.",
                    ),
                ),
            );
    }
    async function billingBatch(presetStudents = [], back = null) {
        if (presetStudents.length > 25) throw new Error("Select no more than 25 students.");
        const chosen = new Map(presetStudents.map(student => [student.id,
            `${student.institutional_id || "ID not assigned"} · ${student.name}`]));
        const descriptors = [
            ["term_id", "Billing term", "lookup", "terms"],
            ["student_id", "Find a student", "lookup", "students", false],
            ["reason", "Reason for billing", "textarea"],
        ];
        const body = openDrawer("Review student bills", "TERM BILLING");
        body.append(node("p", { class: "form-text" },
            "Choose up to 25 students. Review current flat fees, earlier postings and resulting balances before confirming."));
        if (back) body.append(button("Back to report selection", back));
        const form = await buildForm(descriptors);
        form.elements.reason.maxLength = 2000;
        const editor = node("fieldset", {}, node("legend", { class: "h5" }, "Billing selection"));
        editor.append(...form.children);
        const selected = node("section", { class: "record-section", "aria-live": "polite",
            "aria-label": "Selected billing students" });
        const reviewArea = node("div");
        let revision = 0;
        const reviewButton = node("button", { type: "submit", class: "btn btn-primary" }, "Review selected bills");
        function invalidate() {
            revision++;
            reviewArea.replaceChildren();
        }
        function renderSelection() {
            selected.replaceChildren(node("h3", {}, `${chosen.size} selected students`),
                ...[...chosen].map(([id, label]) => node("p", {}, label, " ", button(`Remove ${label}`, () => {
                    chosen.delete(id);
                    invalidate();
                    renderSelection();
                }, "btn-link"))));
        }
        const add = button("Add student to batch", () => {
            const control = form.elements.student_id;
            if (!control.value) return;
            if (!chosen.has(control.value) && chosen.size >= 25) throw new Error("A review supports up to 25 students.");
            chosen.set(control.value, control.selectedOptions[0].textContent);
            invalidate();
            renderSelection();
        });
        editor.append(add, selected);
        form.append(editor, reviewButton);
        form.addEventListener("input", event => {
            if (event.target.name === "reason") invalidate();
        });
        form.elements.term_id.addEventListener("change", invalidate);
        form.addEventListener("submit", event => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                if (!chosen.size) throw new Error("Add at least one student before reviewing.");
                const epoch = revision;
                const response = await request("/sis/billing-batches/review/", "POST", {
                    term_id: form.elements.term_id.value, reason: form.elements.reason.value,
                    students: [...chosen.keys()].map(student_id => ({ student_id })),
                });
                if (!form.isConnected || epoch !== revision) return;
                showReview(response);
            }, reviewButton);
        });
        function showReview(review) {
            reviewArea.replaceChildren();
            const panel = section("Proposed charges",
                node("p", { class: "fw-semibold" }, `Billing term: ${review.term.code}`),
                node("p", {}, "Only new fees shown here will be posted. Earlier charges and reversals remain intact."));
            const status = node("p", { role: "status", class: review.blocked ? "alert alert-warning" : "alert alert-info" },
                review.blocked ? `${review.blocked} student records need attention before this group can be billed.`
                    : review.new_charges ? `${review.new_charges} new charge${review.new_charges === 1 ? " is" : "s are"} ready for review.`
                        : "There are no new charges to post. No confirmation is needed.");
            panel.append(status, ...review.totals.map(total => node("p", { class: "fw-semibold" },
                `New charges: ${money(total.minor, total.currency)}`)));
            const definitions = new Map(review.rules.map(rule => [rule.id, rule]));
            const page = node("div");
            let offset = 0;
            function renderPage() {
                const rows = review.students.slice(offset, offset + 10).map(row => {
                    const details = node("details", {}, node("summary", {},
                        `${row.new_charges} new · ${row.already_posted} posted · ${row.previously_reversed} reversed`));
                    details.append(node("ul", {}, ...row.items.map(item => {
                        const rule = definitions.get(item.rule_id);
                        const label = item.status === "new" ? "New charge" : item.status === "posted"
                            ? "Already posted" : "Previously reversed; not reapplied";
                        return node("li", {}, `${rule.name} · ${money(rule.minor, rule.currency)} · ${label}`);
                    })));
                    if (row.problems.length) details.append(...row.problems.map(problem => node("p", { class: "text-danger" }, problem)));
                    return [node("div", {}, node("strong", {}, row.name), node("div", { class: "small" }, row.institutional_id)),
                        row.program_label, row.problems.length ? "Review required" : row.currency ? money(row.charge_minor, row.currency) : "No new fees",
                        row.account_id ? money(row.balance_before_minor, row.currency) : "No account yet",
                        row.balance_after_minor === null ? "Review required" : row.currency
                            ? money(row.balance_after_minor, row.currency) : "No applicable fees", details];
                });
                page.replaceChildren(scrollTable(["Student", "Program", "New charges", "Current balance", "After billing", "Fee details"], rows,
                    "Reviewed student charges"), node("p", { role: "status" },
                    `Showing ${offset + 1}–${Math.min(offset + 10, review.students.length)} of ${review.students.length} students.`));
                const previous = button("Previous students", () => { offset -= 10; renderPage(); });
                const next = button("Next students", () => { offset += 10; renderPage(); });
                previous.disabled = offset === 0;
                next.disabled = offset + 10 >= review.students.length;
                page.append(previous, next);
            }
            renderPage();
            const confirm = button("Confirm reviewed charges", async () => {
                editor.disabled = true;
                reviewButton.disabled = true;
                try {
                    const result = await request("/sis/billing-batches/", "POST", { review_token: review.review_token });
                    await ui.refreshDirectory();
                    body.replaceChildren(section("Billing completed",
                        node("p", { role: "status" }, `${result.posted} charge${result.posted === 1 ? "" : "s"} posted. ${result.accounts_created} account${result.accounts_created === 1 ? "" : "s"} opened.`),
                        ...result.totals.map(total => node("p", {}, `${money(total.minor, total.currency)} in new charges`)),
                        node("p", {}, "The original confirmation can be retried without billing these fees again."),
                        ...result.students.filter(item => item.account_id).map(item => button(
                            `Open account for ${chosen.get(item.student_id) || "selected student"}`,
                            () => openRecord("accounts", { id: item.account_id }))),
                        button("Start another review", billingBatch)));
                } finally {
                    editor.disabled = false;
                    reviewButton.disabled = false;
                }
            }, "btn-primary");
            confirm.disabled = Boolean(review.blocked) || review.new_charges === 0;
            panel.append(page, node("p", { class: "form-text" },
                "This review is valid for 20 minutes. Changing the selection or reason clears it. Changed fees, programs or account activity require a fresh review."), confirm);
            reviewArea.append(panel);
            status.tabIndex = -1;
            status.focus();
        }
        renderSelection();
        body.append(form, reviewArea);
    }
    async function campaignRecipients(row, draft, back, presetRecipients = []) {
        if (presetRecipients.length > 500) throw new Error("Choose no more than 500 recipients.");
        const body = openDrawer("Choose recipients", "COMMUNICATIONS");
        const selected = new Map(presetRecipients.map(person => [person.user_id, person]));
        let generation = 0, after = null, query = "", selectedPage = 0, timer;
        const search = node("input", { type: "search", class: "form-control", id: "campaign-recipient-search",
            maxlength: 80, autocomplete: "off", placeholder: "Name or institutional ID" });
        const find = node("button", { type: "submit", class: "btn btn-outline-primary" }, "Find recipients");
        const searchForm = node("form", {}, node("label", { for: search.id, class: "form-label" },
            "Find people"), node("div", { class: "input-group" }, search, find));
        const status = node("p", { role: "status", class: "form-text" }, "Search to find people. No directory is loaded until you search.");
        const results = node("fieldset", {}, node("legend", { class: "h6" }, "Search results"));
        const choices = node("div");
        results.append(choices);
        const more = button("More matching people", () => load(true));
        more.hidden = true;
        const selection = node("section", { "aria-label": "Selected recipients" });
        const review = node("section", { hidden: true, "aria-label": "Message and recipient review" });
        const label = person => [person.given_name, person.family_name].filter(Boolean).join(" ")
            + (person.institutional_id ? ` · ${person.institutional_id}` : ` · Person …${person.id.slice(-6)}`);
        const picker = node("section", {}, button("Back to message", back, "btn-link mb-3"),
            node("p", { class: "form-text" }, "Select up to 500 people with enabled linked accounts. Review the saved message and complete recipient list before queueing. Queueing does not send mail."),
            searchForm, status, results, more, selection);
        const reviewButton = button("Review message and recipients", async () => {
            const serial = ++generation;
            const reviewed = await request(`/sis/campaigns/${row.id}/recipient-review/`, "POST", {
                version: Number(draft.version), recipients: [...selected.keys()].map(user_id => ({ user_id })),
            });
            if (!body.isConnected || serial !== generation) return;
            let page = 0;
            const heading = node("h2", { class: "h4", tabindex: "-1" }, "Review before queueing");
            const people = node("div");
            const draw = () => {
                people.replaceChildren(node("p", { role: "status" },
                    `Recipients ${page * 20 + 1}–${Math.min((page + 1) * 20, reviewed.recipients.length)} of ${reviewed.recipients.length}`),
                    node("ul", {}, ...reviewed.recipients.slice(page * 20, (page + 1) * 20)
                        .map(person => node("li", { class: "private-text" }, label(person)))));
                if (page) people.append(button("Previous reviewed recipients", () => { page--; draw(); }));
                if ((page + 1) * 20 < reviewed.recipients.length)
                    people.append(button("Next reviewed recipients", () => { page++; draw(); }));
            };
            review.replaceChildren(heading, node("h3", { class: "h5 private-text" }, reviewed.subject),
                node("p", { class: "message-preview" }, reviewed.body), people,
                node("p", { class: "form-text" }, "Confirming queues this exact draft revision. Current linked accounts and delivery addresses are checked again. Sending remains a separate action."),
                button("Back to recipient selection", () => {
                    generation++; review.hidden = true; picker.hidden = false; reviewButton.focus();
                }), button("Confirm recipient queue", async () => {
                    await request(`/sis/campaigns/${row.id}/queue/`, "POST", {
                        version: reviewed.version, recipients: reviewed.recipients.map(person => ({ user_id: person.user_id })),
                    });
                    if (!body.isConnected) return;
                    notify("Recipients queued. No messages have been sent.");
                    await ui.refreshDirectory();
                    await back();
                }, "btn-primary"));
            draw(); picker.hidden = true; review.hidden = false; heading.focus();
        }, "btn-primary");
        function renderSelection() {
            const people = [...selected.values()];
            selectedPage = Math.min(selectedPage, Math.max(0, Math.ceil(people.length / 20) - 1));
            selection.replaceChildren(node("h2", { class: "h5", "aria-live": "polite" }, `${people.length} selected recipients`),
                ...people.slice(selectedPage * 20, (selectedPage + 1) * 20).map(person =>
                    node("div", { class: "d-flex flex-wrap align-items-center gap-2 mb-2" },
                        node("span", { class: "private-text" }, label(person)),
                        button(`Remove ${label(person)}`, () => {
                            selected.delete(person.user_id); generation++;
                            const checkbox = choices.querySelector(`[value="${person.user_id}"]`);
                            if (checkbox) checkbox.checked = false;
                            renderSelection();
                        }, "btn-link btn-sm"))));
            if (selectedPage) selection.append(button("Previous selected recipients", () => { selectedPage--; renderSelection(); }));
            if ((selectedPage + 1) * 20 < people.length)
                selection.append(button("Next selected recipients", () => { selectedPage++; renderSelection(); }));
            reviewButton.disabled = !people.length;
        }
        async function load(append = false) {
            if (!append) { query = search.value.trim(); after = null; }
            const serial = ++generation;
            if (!query) { choices.replaceChildren(); more.hidden = true; status.textContent = "Enter a name or institutional ID."; return; }
            status.textContent = "Finding matching people…";
            const response = await request("/sis/campaigns/recipients/search/", "POST", { search: query, after });
            if (!body.isConnected || serial !== generation) return;
            if (!append) choices.replaceChildren();
            for (const person of response.items) {
                const checkbox = node("input", { class: "form-check-input", type: "checkbox",
                    id: `campaign-recipient-${person.id}`, value: person.user_id, checked: selected.has(person.user_id) });
                checkbox.addEventListener("change", () => task(async () => {
                    if (checkbox.checked) {
                        if (selected.size >= 500) { checkbox.checked = false; throw new Error("Choose no more than 500 recipients."); }
                        selected.set(person.user_id, person);
                    } else selected.delete(person.user_id);
                    generation++; renderSelection();
                }));
                choices.append(node("div", { class: "form-check mb-2" }, checkbox,
                    node("label", { class: "form-check-label private-text", for: checkbox.id }, label(person))));
            }
            after = response.next_after;
            more.hidden = !after;
            status.textContent = `${response.items.length} ${append ? "more " : ""}matching people.${after ? " More matches are available." : ""}`;
        }
        searchForm.addEventListener("submit", event => { event.preventDefault(); clearTimeout(timer); task(() => load(), find); });
        search.addEventListener("input", () => {
            generation++; clearTimeout(timer); more.hidden = true;
            timer = setTimeout(() => { if (body.isConnected && !picker.hidden) task(() => load()); }, 350);
        });
        picker.append(reviewButton);
        body.append(picker, review);
        renderSelection();
    }
    const messageFields = [["subject", "Subject"], ["body", "Message", "textarea"]];
    async function composeMessage(recipients, back) {
        await createRecord("campaigns", {}, {
            back, backLabel: "Back to report selection",
            explanation: `${recipients.length} recipients selected. Save your message, then review the complete audience before queueing. Sending requires a separate background-delivery confirmation.`,
            afterSave: saved => campaignRecipients({ id: saved.id }, saved,
                () => openRecord("campaigns", { id: saved.id }), recipients),
        });
    }
    async function campaignTemplates(scope = "mine", after = null, stack = []) {
        const retired = scope === "retired";
        const titles = { mine: "My message templates", submitted: "My submitted templates", retired: "My retired templates", institutional: "Institutional templates", review: "Template approvals" };
        const body = openDrawer(titles[scope], "COMMUNICATIONS");
        const result = await request("/sis/campaigns/templates/page/", "POST", { scope, after });
        if (!body.isConnected) return;
        body.append(node("p", { class: "form-text" },
            "Private wording becomes available to colleagues only after institutional approval. Each new message is an independent draft; existing drafts and deliveries keep their wording."),
            node("div", { class: "inline-actions my-3", role: "group", "aria-label": "Template libraries" },
                ...[["mine", "My private templates"], ["submitted", "My submissions"], ["institutional", "Institutional library"],
                    ...(can("sis:communications:admin") ? [["review", "Awaiting approval"]] : [])].map(([key, text]) => {
                    const choice = button(text, () => campaignTemplates(key));
                    choice.setAttribute("aria-pressed", String(scope === key)); return choice;
                })),
            button(retired ? "Show active templates" : "Show retired templates", () => campaignTemplates(retired ? "mine" : "retired")),
            button("Create reusable template", async () => {
                const form = await flow("Save reusable template", messageFields,
                    "/sis/campaigns/templates/create/", null, () => campaignTemplates(), {},
                    "Save plain-text wording for future messages. This creates a private template, not a delivery queue.");
                form.elements.subject.maxLength = 191;
                form.elements.body.maxLength = 100000;
            }, "btn-primary"));
        body.append(result.items.length ? scrollTable(["Template", "Action"], result.items.map(item => {
            const open = button("Open template", () => openRecord("campaigns", { id: item.id }));
            open.setAttribute("aria-label", `Open template ${item.subject}`);
            return [item.subject, open];
        }), titles[scope])
            : node("p", { class: "mt-3" }, retired ? "No retired templates." : scope === "mine" ? "No reusable templates yet." : "No templates in this library."));
        if (stack.length) body.append(button("Previous templates", () => {
            const previous = [...stack]; return campaignTemplates(scope, previous.pop(), previous);
        }));
        if (result.next_after) body.append(button("Next templates", () =>
            campaignTemplates(scope, result.next_after, [...stack, after])));
    }
    async function campaignDeliveryReview(row, back) {
        const body = openDrawer("Review background delivery", "COMMUNICATIONS");
        const setup = await request(`/sis/campaigns/${row.id}/delivery-setup/`);
        if (!body.isConnected) return;
        let page = 0, generation = 0, reviewed = null;
        const now = node("input", { type: "radio", name: "delivery_timing", value: "now", id: "campaign-send-now",
            class: "form-check-input", checked: true });
        const later = node("input", { type: "radio", name: "delivery_timing", value: "later", id: "campaign-send-later",
            class: "form-check-input" });
        const date = node("input", { type: "datetime-local", name: "send_at", id: "campaign-send-at", class: "form-control",
            value: setup.suggested_local, disabled: true, required: true, "aria-describedby": "campaign-send-time-help" });
        const schedule = node("div", { hidden: true, class: "mt-3" },
            node("label", { for: date.id, class: "form-label" }, `Send after · ${setup.timezone}`), date,
            node("p", { id: "campaign-send-time-help", class: "form-text" },
                "Use the institution's local date and time. Sending begins on the first worker run after this time; it may take several batches."));
        const timing = node("fieldset", {}, node("legend", { class: "h5" }, "Delivery timing"),
            node("div", { class: "form-check" }, now, node("label", { for: now.id, class: "form-check-label" }, "Send now")),
            node("div", { class: "form-check" }, later, node("label", { for: later.id, class: "form-check-label" }, "Schedule for later")), schedule);
        const update = node("button", { type: "submit", class: "btn btn-outline-primary mt-2", hidden: true }, "Update delivery review");
        const hint = node("p", { role: "status", class: "form-text" });
        const form = node("form", {}, timing, hint, update);
        const preview = node("section", { "aria-label": "Message delivery review", hidden: true });
        const people = node("section", { "aria-label": "Reviewed delivery recipients" });
        const confirm = button("Confirm background delivery", async () => {
            if (!reviewed) throw new Error("Update the delivery review before confirming.");
            const token = reviewed.review_token;
            timing.disabled = true;
            try {
                await request(`/sis/campaigns/${row.id}/delivery-confirm/`, "POST", { review_token: token });
                if (!body.isConnected) return;
                notify("Background delivery confirmed. You can leave this screen.");
                await back();
            } finally {
                timing.disabled = false;
            }
        }, "btn-primary");
        confirm.disabled = true;
        function changed() {
            generation++;
            reviewed = null;
            preview.hidden = true;
            confirm.disabled = true;
            update.hidden = false;
            schedule.hidden = !later.checked;
            date.disabled = !later.checked;
            hint.textContent = "Update the review to confirm the selected delivery time.";
        }
        now.addEventListener("change", changed);
        later.addEventListener("change", changed);
        date.addEventListener("input", changed);
        function draw() {
            people.replaceChildren(node("p", { role: "status" },
                `Recipients ${page * 25 + 1}–${Math.min((page + 1) * 25, reviewed.recipients.length)} of ${reviewed.recipients.length}`),
                node("ul", {}, ...reviewed.recipients.slice(page * 25, (page + 1) * 25).map(person =>
                    node("li", { class: "private-text" }, person.name + (person.institutional_id ? ` · ${person.institutional_id}` : "")))));
            if (page) people.append(button("Previous delivery recipients", () => { page--; draw(); }));
            if ((page + 1) * 25 < reviewed.recipients.length)
                people.append(button("Next delivery recipients", () => { page++; draw(); }));
        }
        async function review() {
            const serial = ++generation;
            date.removeAttribute("aria-invalid");
            date.classList.remove("is-invalid");
            const result = await request(`/sis/campaigns/${row.id}/delivery-review/`, "POST", {
                send_at: later.checked ? date.value : null, timezone: setup.timezone,
            });
            if (!body.isConnected || serial !== generation) return;
            reviewed = result;
            page = 0;
            preview.replaceChildren(node("h2", { class: "h4 private-text" }, reviewed.subject),
                node("p", { class: "message-preview" }, reviewed.body),
                node("p", { class: "fw-semibold" }, reviewed.send_after
                    ? `Send after ${reviewed.send_after.display}` : "Send as soon as the background worker runs."), people);
            draw();
            preview.hidden = false;
            confirm.disabled = false;
            confirm.textContent = reviewed.send_after ? "Confirm scheduled delivery" : "Confirm background delivery";
            update.hidden = true;
            hint.textContent = "Message, recipients and delivery timing are ready for your confirmation.";
        }
        form.addEventListener("submit", event => {
            event.preventDefault();
            if (form.reportValidity()) task(review, update);
        });
        body.append(form, preview,
            node("p", { class: "alert alert-info" },
                "Confirm once to send these queued messages in the background. You can leave this screen immediately. Pausing or cancelling stops new sends; a message already being sent may finish. Uncertain sends are held for review."),
            button("Back to message", back), confirm);
        await review();
    }
    async function campaignDeliveryDecision(row, item, back) {
        const body = openDrawer("Review delivery", "COMMUNICATIONS");
        const base = `/sis/campaigns/${row.id}/deliveries/${item.id}`;
        const state = await request(`${base}/review/`);
        if (!body.isConnected) return;
        const label = [item.given_name, item.family_name].filter(Boolean).join(" ") || "Selected recipient";
        const transportLabels = { queued: "Queued", sending: "In progress or interrupted", needs_review: "Unconfirmed", sent: "Confirmed sent" };
        body.append(button("Back to campaign", back, "btn-link mb-3"), node("h2", { class: "h4 private-text" }, label),
            node("p", { class: "text-break" }, `Delivery reference: ${state.delivery_id}`),
            node("p", {}, `Mail-service result: ${transportLabels[state.transport_status] || state.transport_status}`));
        if (state.decision) {
            const saved = state.decision;
            body.append(node("h3", { class: "h5" }, saved.decision === "confirmed_sent" ? "Staff confirmed sending" : "Closed without resending"),
                node("p", { class: "private-text message-preview" }, saved.reason),
                node("p", { class: "private-text" }, saved.evidence ? `Evidence reference: ${saved.evidence}` : "No evidence reference recorded."),
                node("p", { class: "form-text" }, `Recorded ${saved.decided_at} by account …${saved.actor_id.slice(-8)}.`));
            return;
        }
        if (state.worker_active) {
            body.append(node("p", { class: "alert alert-info" },
                "Return to the campaign and cancel background delivery before recording a decision. A send already in progress may finish."));
            return;
        }
        if (!["sending", "needs_review"].includes(state.transport_status)) {
            body.append(node("p", { class: "alert alert-info" }, "This delivery has no uncertain result to review."));
            return;
        }
        const options = [{ value: "closed", label: "Close without resending" }];
        if (state.transport_status === "needs_review") options.push({ value: "confirmed_sent", label: "Confirm sent using evidence" });
        const decisionFields = [
            ["decision", "Decision", "choice", options], ["reason", "Reason for this decision", "textarea"],
            ["evidence", "Mail-service receipt or institutional case reference", "text", "", false],
        ];
        const form = await buildForm(decisionFields, { decision: "closed" });
        if (!body.isConnected) return;
        form.elements.reason.maxLength = 1000;
        const evidence = form.elements.evidence;
        form.elements.decision.addEventListener("change", () => { evidence.required = form.elements.decision.value === "confirmed_sent"; });
        const preview = node("section", { hidden: true, "aria-label": "Delivery decision review" });
        const submit = node("button", { type: "submit", class: "btn btn-primary" }, "Review decision");
        form.append(submit);
        form.addEventListener("submit", event => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            task(async () => {
                const values = formData(form, decisionFields);
                const reviewed = await request(`${base}/review/`, "POST", {
                    decision: values.decision, reason: values.reason, evidence: values.evidence?.trim() || null,
                });
                if (!body.isConnected) return;
                preview.replaceChildren(node("h3", { class: "h5", tabindex: "-1" }, "Confirm this delivery decision"),
                    node("p", {}, reviewed.decision === "confirmed_sent" ? "Confirm sent using the recorded evidence." : "Close this review without another send attempt."),
                    node("p", { class: "message-preview private-text" }, reviewed.reason),
                    node("p", { class: "private-text" }, reviewed.evidence ? `Evidence reference: ${reviewed.evidence}` : "No evidence reference recorded."),
                    button("Back to decision", () => { preview.hidden = true; form.hidden = false; form.elements.decision.focus(); }),
                    button("Confirm delivery decision", async () => {
                        await request(`${base}/confirm/`, "POST", { review_token: reviewed.review_token });
                        if (!body.isConnected) return;
                        notify("Delivery decision recorded. No message was resent.");
                        await back();
                    }, "btn-primary"));
                form.hidden = true;
                preview.hidden = false;
                preview.querySelector("h3").focus();
            }, submit);
        });
        body.append(node("p", { class: "form-text" },
            "A confirmed send needs a mail-service receipt or institutional evidence reference. Record a short reason and reference; keep message text, addresses, credentials and raw provider responses out of these notes. Closing the review leaves an already claimed send free to finish. These actions never send or retry a message."), form, preview);
    }
    async function campaignProgress(body, row, data, back) {
        const progress = section("Sending progress");
        const label = node("p", { role: "status", class: "mb-2" });
        const bar = node("div", { class: "progress-bar", role: "progressbar", "aria-label": "Confirmed sends",
            "aria-valuemin": "0", "aria-valuemax": "100", "aria-valuenow": "0" });
        const summary = node("p", { class: "form-text mt-2" });
        const scheduled = node("p", { class: "form-text", hidden: true });
        const explanation = node("p", { class: "form-text" });
        let latest, signature = "";
        const review = button("Review background delivery", () => campaignDeliveryReview(row, back), "btn-primary");
        review.hidden = true;
        const cancel = button("Cancel background delivery", () => flow("Confirm delivery cancellation", [],
            `/sis/campaigns/${row.id}/delivery-cancel/`, () => ({ version: latest.version }), back, {},
            "Stop new sends under this authorization. A send already claimed may finish. Sent and uncertain delivery records are retained."));
        cancel.hidden = true;
        const refresh = button("Refresh sending progress", () => load(false));
        progress.append(label, node("div", { class: "progress" }, bar), summary, scheduled, explanation,
            node("div", { class: "d-flex flex-wrap gap-2" }, review, cancel, refresh));
        body.append(progress);
        const explanations = {
            unconfirmed: "Recipients are queued. Review and confirm background delivery to start sending.",
            active: "Sending in the background when the server worker runs. You can leave this screen and return later.",
            completed: "Background delivery has finished. Confirmed sends are retained below.",
            held: "Sending stopped because a delivery could not be confirmed. Held messages will not be retried automatically.",
            blocked: "Sending stopped before a new send could be claimed. Check current permissions, recipient accounts and institutional mail settings before reviewing the remaining queue.",
            cancelled: "Background delivery was cancelled. Remaining queued recipients require a new review and confirmation.",
        };
        async function load(automatic) {
            if (!body.isConnected) return;
            if (automatic && document.hidden) {
                window.setTimeout(() => poll(), latest?.waiting ? 60000 : 6000);
                return;
            }
            const state = await request(`/sis/campaigns/${row.id}/delivery-progress/`);
            if (!body.isConnected) return;
            latest = state;
            const next = JSON.stringify(state);
            if (next !== signature) {
                signature = next;
                const counts = state.counts;
                const percent = state.total ? Math.floor(counts.sent * 100 / state.total) : 0;
                label.textContent = `${counts.sent} of ${state.total} messages sent · ${percent}%`;
                bar.style.width = `${percent}%`;
                bar.setAttribute("aria-valuenow", String(percent));
                bar.setAttribute("aria-valuetext", `${counts.sent} of ${state.total} messages confirmed sent`);
                summary.textContent = `${counts.queued} queued · ${counts.sending} sending · ${counts.needs_review} need review`;
                if (counts.closed) summary.textContent += ` · ${counts.closed} closed without resending`;
                scheduled.hidden = !state.send_after;
                scheduled.textContent = state.send_after ? `Scheduled start: ${state.send_after.display}` : "";
                explanation.textContent = state.campaign_status === "paused" ? "Campaign paused. No new messages will be sent until it is resumed."
                    : state.status === "completed" && counts.closed ? "Delivery processing finished. Closed reviews are included in the audience total and excluded from sent totals."
                    : state.waiting ? "Scheduled and waiting. No messages will be sent before the reviewed time. You can leave this screen."
                        : explanations[state.status] || "Refresh to check delivery status.";
                cancel.hidden = state.status !== "active";
                review.hidden = state.status === "active" || state.campaign_status !== "queued" || !counts.queued
                    || !data.can_dispatch || !can("sis:person:manage");
            }
        }
        async function poll() {
            if (!body.isConnected) return;
            try {
                await load(true);
                if (body.isConnected && !document.hidden && latest?.status === "active")
                    window.setTimeout(() => poll(), latest.waiting ? 60000 : 6000);
            } catch (_) {
                if (body.isConnected) explanation.textContent = "Progress is temporarily unavailable. Refresh to check again; background delivery does not depend on this screen.";
            }
        }
        await load(false);
        if (latest.status === "active") window.setTimeout(() => poll(), latest.waiting ? 60000 : 6000);
    }
    async function campaign(body, row) {
        const data = await request(`/sis/campaigns/${row.id}/`);
        if (!body.isConnected) return;
        const back = () => openRecord("campaigns", row);
        const templateLabels = { template: "Private template", template_retired: "Retired template",
            template_review: "Awaiting institutional approval", template_shared: "Approved institutional template" };
        const template = Object.hasOwn(templateLabels, data.status);
        const canManageTemplate = data.status !== "template_shared" || data.is_owner || can("sis:communications:admin");
        body.append(
            section(
                data.subject,
                node("p", { class: "message-preview" }, data.body),
                badge(template ? templateLabels[data.status] : data.status),
            ),
        );
        if (["draft", "template"].includes(data.status))
            body.append(
                button(template ? "Edit template" : "Edit draft", async () => {
                    const form = await flow(
                        template ? "Save message template" : "Save message draft",
                        messageFields,
                        template ? `/sis/campaigns/${row.id}/template/edit/` : `/sis/campaigns/${row.id}/draft/`,
                        (values) => ({
                            ...values,
                            version: Number(data.version),
                        }),
                        back,
                        data,
                    );
                    form.elements.subject.maxLength = 191;
                    form.elements.body.maxLength = 100000;
                }),
            );
        if (template) {
            const decision = async (action, title, explanation) => {
                const form = await flow(title, [], `/sis/campaigns/${row.id}/template/review/`,
                    () => ({ version: data.version, action }), back, {}, explanation);
                form.prepend(node("h3", { class: "h5 private-text" }, data.subject),
                    node("p", { class: "message-preview" }, data.body));
            };
            body.append(node("p", { class: "form-text" },
                "Templates have no recipients and cannot send mail. Each new message keeps its own editable copy."),
                button("Back to my templates", () => campaignTemplates(data.status === "template_retired" ? "retired"
                    : data.status === "template_shared" ? "institutional" : data.status === "template_review" ? "submitted" : "mine")));
            if (["template", "template_shared"].includes(data.status)) body.append(button("Start a new message", async () => {
                const draft = await request(`/sis/campaigns/${row.id}/template/draft/`, "POST", { version: data.version });
                if (!body.isConnected) return;
                notify("Independent message draft created. Review it before choosing recipients.");
                await openRecord("campaigns", { id: draft.id });
            }, "btn-primary"));
            if (data.status === "template") body.append(button("Submit for institutional approval", () =>
                decision("submit", "Confirm template submission", "A Communications administrator will review this exact wording. Editing pauses until it is returned or withdrawn.")));
            if (["template_review", "template_shared"].includes(data.status) && canManageTemplate)
                body.append(button("Return to private editing", () => decision("withdraw", "Confirm withdrawal to private editing",
                    "This removes institutional availability or the pending submission. Approval will be required again after edits. Existing message copies stay intact.")));
            if (data.status === "template_review" && can("sis:communications:admin")) {
                body.append(button("Approve institutional template", () => decision("approve", "Confirm institutional approval",
                    "Approve this exact wording for all staff with Communications access to read and copy. This does not send a message."), "btn-primary"),
                button("Return to author", () => decision("return", "Confirm return to author", "Return this wording to its owner's private editing library.")));
            }
            if (canManageTemplate) body.append(button(data.status === "template_retired" ? "Restore template" : "Retire template", () =>
                flow(data.status === "template_retired" ? "Confirm template restoration" : "Confirm template retirement", [],
                    `/sis/campaigns/${row.id}/template/status/`,
                    () => ({ version: data.version, retired: data.status !== "template_retired" }), back, {},
                    "Retirement stops new messages from using this template. Restoration returns it to private editing, requiring new institutional approval. Existing messages keep their content.")));
            return;
        }
        if (data.status === "draft" && can("sis:person:manage"))
            body.append(button("Choose recipients", () => campaignRecipients(row, data, back), "btn-primary"));
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
        if (data.status !== "draft") await campaignProgress(body, row, data, back);
        if (!body.isConnected) return;
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
                ["Recipient account", "Status", "Review"],
                rows.map((item) => [
                    [item.given_name, item.family_name]
                        .filter(Boolean)
                        .join(" ") ||
                        `Account …${item.recipient_user_id.slice(-8)}`,
                    badge(item.status === "sent" ? "sent" : item.disposition === "confirmed_sent" ? "Staff confirmed sending"
                        : item.disposition === "closed" ? "Closed without resending" : item.status),
                    can("sis:communications:admin") && can("sis:person:manage")
                        && (item.resolution_id || ["sending", "needs_review"].includes(item.status))
                        ? button(item.resolution_id ? "View delivery decision" : "Review delivery", () => campaignDeliveryDecision(row, item, back)) : "—",
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
                button("Program details & publications", () => ui.programs().open(row.id)),
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
            body.append(
                button("Course subject", () => ui.catalog().courseSubject(row.id)),
                button("Departments & levels", () => ui.classifications().course(row.id)),
                button("Titles & description", () => ui.catalog().courseText(row.id)),
            );
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
        if (sectionName === "students" && can("sis:restrictions:manage"))
            await ui.restrictions().list(body, row);
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
        openAccount,
        campaignTemplates,
        composeMessage,
        flow,
        audit,
        registration,
    };
};
