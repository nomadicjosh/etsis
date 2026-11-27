/* Registrar corrections preserve the posted award and an encrypted history of every reviewed change. */
window.createWorkspaceTransferAwards = function (ui) {
    "use strict";
    const { node, request, task, table, recordSummary, actionMenu, buildForm, formData, openDrawer } = ui;
    let receivingSearchSerial = 0;
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
                role: "region",
                tabindex: "0",
                "aria-label": caption,
            },
            table(headers, rows, caption),
        );
    const state = (credit) => (credit.status === "V" ? "Voided" : "Awarded");
    const title = (action) =>
        ({ amend: "Correct credits or grade", void: "Void this award", reinstate: "Reinstate this award" })[
            action
        ];
    const endpoint = (student, award) => `/sis/students/${student}/transfer-awards/${award}/`;
    function identity(record) {
        return node(
            "div",
            { class: "record-section" },
            node("h3", {}, `${record.given_name} ${record.family_name}`),
            recordSummary([
                ["Institutional ID", record.institutional_id],
                ["Institutional course", `${record.credit.course_code} · ${record.credit.course_title}`],
                ["Sending institution", record.institution_name],
                ["External course", record.external_code],
            ]),
            node(
                "p",
                { class: "form-text" },
                `Completed ${record.completed_on} · Posting term ${record.term_code}`,
            ),
            node(
                "p",
                { class: "form-text" },
                record.course_version_id
                    ? "Accepted course publication: retained with this award."
                    : "Accepted course publication: unknown; no definition has been inferred from today's catalog.",
            ),
            node(
                "p",
                { class: "form-text" },
                `Sending institution's period start: ${record.academic_on || "Unknown"}`,
            ),
        );
    }
    function comparison(before, after) {
        return grid(
            ["Academic record", "Current", "After confirmation"],
            [
                ["Status", state(before), state(after)],
                ["Attempted credits", before.attempted_milli / 1000, after.attempted_milli / 1000],
                ["Earned credits", before.completed_milli / 1000, after.completed_milli / 1000],
                [
                    "Grade",
                    before.status === "V" ? "Voided" : before.grade_code || "—",
                    after.status === "V" ? "Voided" : after.grade_code || "—",
                ],
                ["Quality points", before.grade_points_milli / 1000, after.grade_points_milli / 1000],
                ["Counts toward GPA", before.counts_gpa ? "Yes" : "No", after.counts_gpa ? "Yes" : "No"],
            ],
            "Transfer correction comparison",
        );
    }
    async function record(student, award) {
        const body = openDrawer("Posted transfer credit", "REGISTRAR REVIEW", {
            record: { key: `transfer-award:${student}:${award}`, label: "Posted transfer credit", run: () => record(student, award) },
        });
        body.append(node("p", { role: "status" }, "Loading the retained award…"));
        const value = await request(endpoint(student, award));
        if (!body.isConnected) return;
        if (value.source_kind === 'same_institution') {
            return ui.internalTransfers().detail(student, value.receiving_assignment_id, award, () => ui.openStudent(student));
        }
        const actions = node("div", {
            class: "inline-actions",
            role: "group",
            "aria-label": "Transfer award tasks",
        });
        body.replaceChildren(
            button("Back to equivalency", () => ui.back(value.equivalency_id), "btn-link mb-3"),
            identity(value),
            node(
                "section",
                { class: "record-section", "aria-label": "Current transfer award" },
                node("h3", {}, "Current award"),
                recordSummary([
                    ["Earned credits", value.credit.completed_milli / 1000],
                    ["Grade", value.credit.status === "V" ? "Voided" : value.grade_code || "—"],
                    ["Status", state(value.credit)],
                ]),
            ),
            actions,
            node(
                "p",
                { class: "form-text" },
                "Correct this student’s awarded credits or grade with a reviewed reason. Catalog equivalencies and other students’ awards keep their values.",
            ),
        );
        body.append(
            node(
                "p",
                { class: "alert alert-info" },
                value.earlier_history_unknown
                    ? "Earlier award decisions are unknown. Existing facts are preserved as an observation when a correction is confirmed; earlier saved receipts remain available."
                    : "Source details and grading policy are retained with the award’s recorded history.",
            ),
        );
        const action = value.credit.status === "V" ? "reinstate" : "amend";
        actions.append(button(title(action), () => edit(student, award, value, action), "btn-primary"));
        actions.append(
            ...(value.credit.status !== "V"
                ? [button("Academic details", () => editDetails(student, award, value))]
                : []),
            ...(value.credit.status !== "V"
                ? [button("Review accepted course", () => editCourse(student, award, value))]
                : []),
        );
        const more = [
            button("Correction history", () => history(student, award)),
            button("Course definition history", () => courseHistory(student, award)),
            button("Academic history", () => periodHistory(student, award)),
        ];
        if (value.credit.status !== "V")
            more.push(
                button("Void this award", () => edit(student, award, value, "void"), "btn-outline-danger"),
            );
        if (ui.historyChecks && ["sis:history:verify", "sis:audit:read", "sis:student:read"].every(ui.can)) {
            more.push(
                button(ui.historyCheckLabels.check_credit, () =>
                    ui.historyChecks().open("credit", value.credit.id, () => record(student, award)),
                ),
            );
        }
        actions.append(actionMenu("More", more, { context: "More transfer award actions" }));
    }
    async function editCourse(student, award, value, target = null, draft = null) {
        const base = endpoint(student, award) + "accepted-course/";
        const selected = target || {
            id: value.credit.course_id,
            code: value.credit.course_code,
            title: value.credit.course_title,
        };
        let form = null,
            fields = [];
        const draftValues = () =>
            form
                ? {
                      ...formData(form, fields),
                      _publication_label:
                          form.elements.course_version_id.selectedOptions?.[0]?.textContent ||
                          "Selected publication",
                  }
                : draft;

        const label = (item) =>
            `Version ${item.version} · ${item.code} · ${item.transcript_title} · ${item.credits_milli / 1000} catalog credits`;
        const body = openDrawer("Accepted course definition", "REASONED TRANSFER REVIEW");
        body.append(
            identity(value),
            node(
                "p",
                {},
                "Select the receiving course and publication supported by your evaluation. Its course identity, code and transcript title will apply to this award. Credits, grade, pursuit, sending institution, source dates and posting term stay unchanged. The original equivalency and earlier decisions remain preserved.",
            ),
            button("Back to award", () => record(student, award)),
        );
        body.append(
            node("p", { class: "fw-semibold" }, `Receiving course: ${selected.code} · ${selected.title}`),
        );
        const searchId = `receiving-course-search-${++receivingSearchSerial}`;
        const search = node("input", {
            type: "search",
            id: searchId,
            class: "form-control",
            maxlength: 80,
            required: true,
            placeholder: "Course code or title",
        });
        const results = node("div", { class: "d-grid gap-2 mt-3" });
        const searchStatus = node(
            "p",
            { role: "status", class: "form-text" },
            "Search for a course before choosing a publication.",
        );
        let searchRequest = 0,
            after = null;
        const moreResults = button("More courses", () => findCourses(after), "btn-outline-secondary btn-sm");
        moreResults.hidden = true;
        async function findCourses(continuation = null) {
            const query = search.value.trim(),
                sequence = ++searchRequest;
            if (!continuation) results.replaceChildren();
            moreResults.hidden = true;
            searchStatus.textContent = "Searching courses…";
            const found = await request(base + "search/", "POST", { search: query, after: continuation });
            if (!body.isConnected || sequence !== searchRequest || search.value.trim() !== query) return;
            found.items.forEach((item) =>
                results.append(
                    button(
                        `${item.code} · ${item.title}`,
                        () =>
                            editCourse(student, award, value, item, {
                                ...(draftValues() || {}),
                                course_version_id: "",
                            }),
                        "btn-outline-primary text-start",
                    ),
                ),
            );
            after = found.next_after;
            moreResults.hidden = !after;
            searchStatus.textContent = found.search_required
                ? "Enter a course code or title."
                : found.items.length
                  ? "Choose a course, then select its published definition."
                  : "No matching courses found.";
        }
        const searchForm = node(
            "form",
            { role: "search", "aria-label": "Receiving courses", class: "mt-3" },
            node("label", { for: searchId, class: "form-label" }, "Find receiving course"),
            search,
        );
        const find = node(
            "button",
            { type: "submit", class: "btn btn-outline-primary mt-2" },
            "Find courses",
        );
        searchForm.append(find, searchStatus, results, moreResults);
        searchForm.addEventListener("submit", (event) => {
            event.preventDefault();
            if (searchForm.reportValidity()) task(() => findCourses(), find);
        });
        body.append(
            node("details", { class: "mb-4" }, node("summary", {}, "Choose another course"), searchForm),
        );
        const choices = await request(base + "choices/", "POST", { course_id: selected.id });
        if (!body.isConnected) return;
        if (!choices.items.length) {
            body.append(
                node(
                    "p",
                    { class: "alert alert-info", role: "status" },
                    "Catalog staff must publish the receiving course before reviewing its acceptance. Choose another course or return to the award.",
                ),
            );
            return;
        }
        if (!value.course_version_id)
            body.append(
                node(
                    "p",
                    { class: "alert alert-info" },
                    "The earlier accepted publication is unknown. This records a new reviewed acceptance; it does not reconstruct an earlier decision.",
                ),
            );
        fields = [
            [
                "course_version_id",
                "Accepted course publication",
                "choice",
                choices.items.map((item) => ({ value: item.version_id, label: label(item) })),
            ],
            ["effective_on", "Effective date", "date"],
            ["reason", "Reason and acceptance evidence", "textarea"],
        ];
        form = await buildForm(
            fields,
            { effective_on: value.effective_on_default, ...(draft || {}) },
            { layout: "transfer_course" },
        );
        if (!body.isConnected) return;
        if (
            draft?.course_version_id &&
            ![...form.elements.course_version_id.options].some(
                (option) => option.value === draft.course_version_id,
            )
        ) {
            form.elements.course_version_id.append(
                node(
                    "option",
                    { value: draft.course_version_id },
                    draft._publication_label || "Previously selected publication",
                ),
            );
            form.elements.course_version_id.value = draft.course_version_id;
        }
        body.append(
            button(
                "Refresh award",
                async () => {
                    const fresh = await request(endpoint(student, award));
                    if (body.isConnected) await editCourse(student, award, fresh, selected, draftValues());
                },
                "btn-outline-secondary btn-sm",
            ),
        );
        form.elements.effective_on.min = value.completed_on;
        form.elements.effective_on.max = value.effective_on_default;
        form.elements.reason.maxLength = 1000;
        let cursor = choices.next_cursor;
        const status = node(
            "p",
            { role: "status", class: "form-text" },
            `${choices.items.length} publications loaded.`,
        );
        form.elements.course_version_id.parentElement.append(status);
        if (cursor) {
            const more = button("More course choices", async () => {
                const next = await request(base + "choices/", "POST", { cursor, course_id: selected.id });
                if (!form.isConnected) return;
                next.items.forEach((item) =>
                    form.elements.course_version_id.append(
                        node("option", { value: item.version_id }, label(item)),
                    ),
                );
                cursor = next.next_cursor;
                status.textContent = `${form.elements.course_version_id.options.length - 1} publications loaded.`;
                if (!cursor) {
                    more.remove();
                    form.elements.course_version_id.focus();
                }
            });
            form.elements.course_version_id.parentElement.append(more);
        }
        const submit = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review accepted definition",
        );
        form.append(submit);
        body.append(form);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const data = formData(form, fields);
                const review = await request(base + "review/", "POST", {
                    ...data,
                    course_id: selected.id,
                    version: value.credit.version,
                });
                if (!form.isConnected) return;
                const confirm = openDrawer("Confirm accepted course", "PRESERVE ORIGINAL ACCEPTANCE");
                confirm.append(
                    grid(
                        ["Record", "Current", "After confirmation"],
                        [
                            ["Course code", review.before.course_code, review.after.course_code],
                            ["Transcript title", review.before.course_title, review.after.course_title],
                            [
                                "Publication",
                                review.previous_course_version_id ? "Retained" : "Unknown",
                                "Explicitly selected",
                            ],
                            [
                                "Awarded credits",
                                value.credit.completed_milli / 1000,
                                value.credit.completed_milli / 1000,
                            ],
                            ["Grade", value.grade_code || "—", value.grade_code || "—"],
                        ],
                        "Accepted course comparison",
                    ),
                    review.replacement
                        ? node(
                              "p",
                              { class: "alert alert-warning" },
                              "This replaces the receiving course for this award only. It does not create a reusable equivalency or re-award catalog credits. Course matching and repeat-course calculations may change.",
                          )
                        : null,
                    node("p", {}, `Effective ${review.effective_on}`),
                    node("p", {}, review.reason),
                    node(
                        "p",
                        {},
                        "This can change course-group matching and the current transcript labels. Existing issued documents and earlier decisions remain preserved; a prepared transcript may need a fresh review.",
                    ),
                    node(
                        "p",
                        { class: "text-muted small" },
                        "This review expires after 20 minutes. Interrupted confirmations retry the same review.",
                    ),
                    button("Revise accepted course", () =>
                        editCourse(student, award, value, selected, draftValues()),
                    ),
                    button(
                        "Confirm course acceptance",
                        async () => {
                            await request(base + "commit/", "POST", { review_token: review.review_token });
                            if (confirm.isConnected) await record(student, award);
                        },
                        "btn-primary",
                    ),
                );
            }, submit);
        });
    }
    async function courseHistory(student, award, cursor = null, stack = []) {
        const pendingView = openDrawer("Accepted-course history", "CONNECTED WORKSPACE");
        pendingView.append(node("p", { role: "status", class: "form-text" }, "Loading record details…"));
        const data = await request(endpoint(student, award) + "accepted-course/history/", "POST", { cursor });
        if (!pendingView.isConnected) return;
        const body = openDrawer("Accepted course history", "RETAINED TRANSFER DECISIONS");
        body.append(button("Back to award", () => record(student, award)));
        if (data.earlier_history_unknown)
            body.append(
                node(
                    "p",
                    { class: "alert alert-info" },
                    "Earlier acceptance decisions remain unknown. Observed facts and later reviewed acceptances are recorded separately.",
                ),
            );
        body.append(
            data.items.length
                ? grid(
                      ["Decision", "Accepted course", "Publication", "Effective", "Recorded"],
                      data.items.map((item) => [
                          {
                              award: "Original award",
                              observed_existing: "Earlier facts observed",
                              course_accepted: "Course acceptance reviewed",
                              course_replaced: "Receiving course replaced",
                          }[item.action],
                          item.action === "course_replaced"
                              ? `${item.previous_course_code} · ${item.previous_course_title} → ${item.course_code} · ${item.course_title}`
                              : `${item.course_code} · ${item.course_title}`,
                          item.course_version_id ? "Retained" : "Unknown",
                          item.effective_at || "Unknown",
                          item.recorded_at,
                      ]),
                      "Accepted course decisions",
                  )
                : node("p", {}, "No course acceptance decisions on this page."),
        );
        const actions = node("div", { class: "inline-actions" });
        if (stack.length)
            actions.append(
                button("Previous course history page", () =>
                    courseHistory(student, award, stack.at(-1), stack.slice(0, -1)),
                ),
            );
        if (data.next_cursor)
            actions.append(
                button("Next course history page", () =>
                    courseHistory(student, award, data.next_cursor, [...stack, cursor]),
                ),
            );
        body.append(actions);
    }
    async function editDetails(student, award, value, draft = null) {
        const body = openDrawer("Transfer academic details", "REVIEWED SENDING-INSTITUTION FACT");
        body.append(
            identity(value),
            node(
                "p",
                {},
                "Record the sending institution's actual course completion and optional period start. The period must not start after completion. A blank start explicitly marks it unknown. Choose the receiving institution's posting term independently from these source dates.",
            ),
            button("Back to award", () => record(student, award)),
        );
        const fields = [
            ["term_id", "Posting term", "lookup", "terms"],
            ["completed_on", "External completion date", "date"],
            ["academic_on", "Source period start", "date", "", false],
            ["effective_on", "Effective date", "date"],
            ["reason", "Reason and source evidence", "textarea"],
        ];
        const form = await buildForm(
            fields,
            {
                term_id: value.term_id,
                academic_on: value.academic_on || "",
                completed_on: value.completed_on,
                effective_on: value.effective_on_default,
                ...(draft || {}),
            },
            { layout: "transfer_details" },
        );
        if (!body.isConnected) return;
        form.elements.completed_on.max = value.effective_on_default;
        form.elements.effective_on.max = value.effective_on_default;
        const bounds = () => {
            form.elements.academic_on.max = form.elements.completed_on.value || value.effective_on_default;
            form.elements.effective_on.min = form.elements.completed_on.value || "";
        };
        form.elements.completed_on.addEventListener("input", bounds);
        bounds();
        body.append(
            button(
                "Refresh details",
                async () => {
                    const fresh = await request(endpoint(student, award));
                    if (!form.isConnected) return;
                    await editDetails(student, award, fresh, formData(form, fields));
                },
                "btn-outline-secondary btn-sm",
            ),
        );
        form.elements.reason.maxLength = 1000;
        const submit = node("button", { type: "submit", class: "btn btn-primary" }, "Review details");
        form.append(submit);
        body.append(form);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const data = formData(form, fields);
                const base = endpoint(student, award) + "source-period/";
                const review = await request(base + "review/", "POST", {
                    term_id: data.term_id,
                    academic_on: data.academic_on || null,
                    completed_on: data.completed_on,
                    effective_on: data.effective_on,
                    reason: data.reason,
                    version: value.credit.version,
                });
                if (!form.isConnected) return;
                const confirm = openDrawer("Confirm academic details", "PRESERVE EARLIER SOURCE EVIDENCE");
                confirm.append(
                    node(
                        "p",
                        {},
                        `Period start: ${review.previous_academic_on || "Unknown"} → ${review.academic_on || "Unknown"}`,
                    ),
                    node("p", {}, `Posting term: ${review.previous_term_code} → ${review.term_code}`),
                    node("p", {}, `Completion: ${review.previous_completed_on} → ${review.completed_on}`),
                    node("p", {}, `Effective: ${review.effective_at}`),
                    node("p", {}, review.reason),
                    review.earlier_history_unknown
                        ? node(
                              "p",
                              { class: "alert alert-info" },
                              "Existing facts will be observed now. Earlier decision times and authors remain unknown.",
                          )
                        : null,
                    node(
                        "p",
                        {},
                        "Credits, grade, course, pursuit and charges stay unchanged. Corrected dates can change repeat-course ordering and require fresh transcript preparation. Check institutional award policy when changing completion.",
                    ),
                    node(
                        "p",
                        { class: "text-muted small" },
                        "This review expires after 20 minutes. An interrupted confirmation retries the same review.",
                    ),
                    button("Revise details", () => editDetails(student, award, value, data)),
                    button(
                        "Confirm details",
                        async () => {
                            await request(base + "commit/", "POST", { review_token: review.review_token });
                            if (confirm.isConnected) await record(student, award);
                        },
                        "btn-primary",
                    ),
                );
            }, submit);
        });
    }
    async function periodHistory(student, award, cursor = null, stack = []) {
        const pendingView = openDrawer("Transfer academic dates", "CONNECTED WORKSPACE");
        pendingView.append(node("p", { role: "status", class: "form-text" }, "Loading record details…"));
        const data = await request(endpoint(student, award) + "source-period/history/", "POST", { cursor });
        if (!pendingView.isConnected) return;
        const body = openDrawer("Academic history", "PRIVATE TRANSFER CHRONOLOGY");
        body.append(
            button("Back to award", () => record(student, award)),
            node(
                "p",
                {},
                "Award and credit decisions are included for context. Date decisions retain earlier source dates. Protected reasons and actors are omitted.",
            ),
        );
        if (data.earlier_history_unknown)
            body.append(
                node(
                    "p",
                    { class: "alert alert-info" },
                    "Earlier source history is unknown. No period start has been reconstructed from today's calendar.",
                ),
            );
        body.append(
            data.items.length
                ? grid(
                      ["Decision", "Posting term", "Period start", "Completion", "Effective", "Recorded"],
                      data.items.map((item) => [
                          {
                              course_accepted: "Accepted course reviewed",
                              course_replaced: "Receiving course replaced",
                              period_recorded: "Source period recorded",
                              dates_recorded: "Academic dates recorded",
                              posting_term_changed: "Posting term corrected",
                              award: "Award established",
                              observed_existing: "Earlier facts observed",
                              amend: "Award corrected",
                              void: "Award voided",
                              reinstate: "Award reinstated",
                          }[item.action],
                          item.action === "posting_term_changed"
                              ? `${item.previous_term_code} → ${item.term_code}`
                              : "No term change",
                          ["period_recorded", "dates_recorded", "posting_term_changed"].includes(item.action)
                              ? `${item.previous_academic_on || "Unknown"} → ${item.academic_on || "Unknown"}`
                              : "No period change",
                          ["dates_recorded", "posting_term_changed"].includes(item.action)
                              ? `${item.previous_completed_on} → ${item.completed_on}`
                              : "No completion change",
                          item.effective_at || "Unknown",
                          item.recorded_at,
                      ]),
                      "Transfer academic decisions",
                  )
                : node("p", {}, "No recorded source decisions on this page."),
        );
        const actions = node("div", { class: "inline-actions" });
        if (stack.length)
            actions.append(
                button("Previous source history page", () =>
                    periodHistory(student, award, stack.at(-1), stack.slice(0, -1)),
                ),
            );
        if (data.next_cursor)
            actions.append(
                button("Next source history page", () =>
                    periodHistory(student, award, data.next_cursor, [...stack, cursor]),
                ),
            );
        body.append(actions);
    }
    async function edit(student, award, value, action, preset = null) {
        const body = openDrawer(title(action), "REVIEW → CONFIRM → SAVED RECEIPT");
        body.append(
            identity(value),
            node(
                "p",
                {},
                action === "void"
                    ? "Voiding removes this award’s attempted credits, earned credits and GPA contribution. Its history remains available."
                    : "Enter the corrected award values and a reason. The system calculates grade points from the selected grade.",
            ),
        );
        const fields = [
            ...(action === "void"
                ? []
                : [
                      ["credits_milli", "Credits awarded", "credits"],
                      ["awarded_grade", "Awarded grade code"],
                  ]),
            ["effective_on", "Effective date", "date"],
            ["reason", "Registrar reason", "textarea"],
        ];
        const initial = preset || {
            credits_milli: value.credit.status === "V" ? undefined : value.credit.completed_milli,
            awarded_grade: value.grade_code || "",
            effective_on: value.effective_on_default,
        };
        const form = await buildForm(
            fields,
            {
                ...initial,
                credits_milli:
                    initial.credits_milli == null ? undefined : Number(initial.credits_milli) / 1000,
            },
            { layout: "transfer_correction" },
        );
        if (!body.isConnected) return;
        form.elements.effective_on.min = value.completed_on;
        form.elements.effective_on.max = value.effective_on_default;
        if (action !== "void") {
            form.elements.credits_milli.min = 0.001;
            form.elements.credits_milli.max = 999.999;
            form.elements.awarded_grade.maxLength = 12;
        }
        const submit = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review proposed correction",
        );
        form.append(
            node(
                "div",
                { class: "inline-actions" },
                submit,
                button("Back to award", () => record(student, award)),
            ),
        );
        let reviewSequence = 0;
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const data = { ...formData(form, fields), action };
                const sequence = ++reviewSequence;
                const review = await request(endpoint(student, award) + "preview/", "POST", data);
                if (form.isConnected && sequence === reviewSequence) await confirm(student, award, review);
            }, submit);
        });
        body.append(form);
    }
    async function confirm(student, award, review) {
        const operation = review.operation_id;
        const payload = {
            ...review.request,
            revision: review.revision,
            operation_id: operation,
            review_token: review.review_token,
        };
        const body = openDrawer("Confirm transfer correction", "REVIEWED ACADEMIC CHANGE");
        body.append(
            identity(review.record),
            comparison(review.before, review.after),
            node("p", {}, `Effective date: ${review.request.effective_on}`),
            node("h3", {}, "Registrar reason"),
            node("p", { class: "private-text" }, review.request.reason),
            node(
                "p",
                { class: "form-text" },
                "This review expires after 20 minutes. If it expires, choose Change correction to review again; your entries are preserved.",
            ),
            node(
                "p",
                { class: "form-text" },
                "Future prerequisite checks and newly prepared transcripts use the corrected award. Existing enrollments, posted charges and previously generated documents retain their history. A prepared transcript must be reviewed again before delivery.",
            ),
        );
        const status = node("p", { role: "status", "aria-live": "polite" });
        let attempted = false;
        const change = button("Change correction", () =>
            edit(student, award, review.record, review.request.action, review.request),
        );
        const submit = button(
            "Confirm academic correction",
            async () => {
                attempted = true;
                change.disabled = true;
                status.textContent = `Saving correction ${operation}. After an interrupted connection, retry with this reference or check the saved correction.`;
                let receipt;
                try {
                    receipt = await request(endpoint(student, award) + "confirm/", "POST", payload);
                } catch (error) {
                    if ([400, 403, 404, 409, 422].includes(error.status)) {
                        attempted = false;
                        change.disabled = false;
                    }
                    throw error;
                }
                if (body.isConnected) saved(student, award, receipt);
            },
            "btn-primary",
        );
        const check = button("Check saved correction", async () => {
            if (!attempted) throw new Error("Confirm this reviewed correction before checking its receipt.");
            const receipt = await request(endpoint(student, award) + `changes/${operation}/`);
            if (body.isConnected) saved(student, award, receipt);
        });
        body.append(
            status,
            node(
                "div",
                { class: "inline-actions" },
                submit,
                check,
                change,
                button("Review current award", () => record(student, award)),
            ),
        );
    }
    function saved(student, award, receipt) {
        const body = openDrawer("Transfer correction saved", "ACADEMIC RECEIPT");
        body.append(
            node(
                "p",
                { role: "status" },
                `Saved ${receipt.action === "amend" ? "correction" : receipt.action === "void" ? "void" : "reinstatement"} at academic record revision ${receipt.version + 1}.`,
            ),
            node("p", {}, "Reference: ", node("code", {}, receipt.operation_id)),
            node(
                "div",
                { class: "inline-actions" },
                button("Review current award", () => record(student, award)),
                button("Correction history", () => history(student, award)),
            ),
        );
    }
    async function history(student, award, after = "", stack = []) {
        const pendingView = openDrawer("Transfer award history", "CONNECTED WORKSPACE");
        pendingView.append(node("p", { role: "status", class: "form-text" }, "Loading record details…"));
        const result = await request(endpoint(student, award) + "history/", "POST", { after });
        if (!pendingView.isConnected) return;
        const body = openDrawer("Transfer correction history", "PRIVATE REGISTRAR HISTORY");
        body.append(
            button("Back to award", () => record(student, award)),
            node(
                "p",
                { class: "form-text" },
                "Newest revisions first. Reasons and before/after snapshots are restricted to transfer staff with academic read access.",
            ),
        );
        result.items.forEach((item) => {
            body.append(
                node(
                    "section",
                    { class: "record-section" },
                    node(
                        "h3",
                        {},
                        `${item.action === "amend" ? "Correction" : item.action === "void" ? "Voided" : "Reinstated"} · Revision ${item.version + 1}`,
                    ),
                    node(
                        "p",
                        { class: "form-text" },
                        `${item.created_at} · Staff reference ${item.actor_id}`,
                    ),
                    node(
                        "p",
                        { class: "form-text" },
                        item.history_source === "legacy_receipt"
                            ? "Earlier saved receipt · effective time unknown"
                            : `Effective: ${item.effective_at || "Unknown"}`,
                    ),
                    node("p", { class: "private-text" }, item.reason),
                    comparison(item.before, item.after),
                ),
            );
        });
        if (!result.items.length)
            body.append(
                node("p", {}, "This award has no corrections. Its original posted values remain current."),
            );
        const navigation = node("div", { class: "inline-actions" });
        if (stack.length)
            navigation.append(
                button("Previous correction page", () =>
                    history(student, award, stack.at(-1), stack.slice(0, -1)),
                ),
            );
        if (result.next_after)
            navigation.append(
                button("Next correction page", () =>
                    history(student, award, result.next_after, [...stack, after]),
                ),
            );
        body.append(navigation);
    }
    return { record };
};
