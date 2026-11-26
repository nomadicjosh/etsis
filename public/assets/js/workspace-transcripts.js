/* Reviewed, immutable transcript documents and separately recorded delivery evidence. */
window.createWorkspaceTranscripts = function (ui) {
    "use strict";
    const fallbackLabels = {
        selection_required: "Choose at least one definition or include all standings.",
        setup_unavailable: "Transcript setup is no longer available. Reopen it after access is restored.",
        newer_edits_unsaved: "Transcript setup saved. Newer edits are still unsaved.",
        setup_selection_hint: "This setup selects particular standing definitions for new preparations.",
        document_selection_hint: "This document includes institution-selected standing definitions.",
        selected_standing_empty: "No selected academic standing is included through this date.",
    };
    const text = key => ui.standingLabels?.[key] || fallbackLabels[key];
    const {
        node,
        request,
        requestHtml,
        requestFile,
        task,
        can,
        table,
        badge,
        buildForm,
        formData,
        openDrawer,
        recordSummary,
    } = ui;
    const button = (label, callback, style = "btn-outline-primary", settled = () => {}) =>
        node(
            "button",
            {
                type: "button",
                class: `btn ${style}`,
                onclick: (event) => Promise.resolve(task(callback, event.currentTarget)).finally(settled),
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
    const units = (value) => (value / 1000).toFixed(3);
    function workflow(status) {
        const stages = { pending: 1, ready: 2, fulfilled: 3 };
        if (!Object.hasOwn(stages, status)) return null;
        const current = stages[status];
        return node("ol", { class: "transcript-workflow", "aria-label": "Transcript fulfillment progress" },
            ["Request received", "Prepare document", "Record delivery"].map((label, index) => {
                const complete = index < current;
                return node("li", {
                    class: complete ? "complete" : index === current ? "current" : "upcoming",
                    "aria-current": index === current ? "step" : null,
                }, node("i", { class: `bi bi-${complete ? "check2-circle" : index === current ? "arrow-right-circle" : "circle"}`, "aria-hidden": "true" }),
                node("span", {}, node("strong", {}, label),
                    node("small", {}, complete ? "Complete" : index === current ? "Current step" : "Next step")));
            }));
    }
    function taskCard(title, description, glyph, action) {
        return node("section", { class: "record-task-card", "aria-label": title },
            node("div", { class: "record-task-heading" },
                node("span", { class: "icon-soft", "aria-hidden": "true" }, node("i", { class: `bi bi-${glyph}` })),
                node("h3", {}, title)),
            node("p", { class: "form-text" }, description), action);
    }
    function releaseChecklist(policy, stage, changed = () => {}) {
        const section = node("fieldset", { class: "record-section" },
            node("legend", { class: "h5" }, "Institutional release checks"));
        const checks = policy.checks.filter((check) => check.stage === stage || check.stage === "both");
        const inputs = checks.map((check) => {
            const id = `transcript-${stage}-${check.id}`;
            const input = node("input", { type: "checkbox", class: "form-check-input", id,
                "aria-describedby": check.details ? `${id}-help` : undefined });
            input.addEventListener("change", changed);
            section.append(node("div", { class: "form-check mb-3" }, input,
                node("label", { for: id, class: "form-check-label fw-semibold" }, check.label),
                check.details ? node("p", { id: `${id}-help`, class: "form-text mb-0" }, check.details) : null));
            return { id: check.id, input };
        });
        section.append(node("p", { class: "form-text" }, checks.length
            ? `Confirm each check before continuing. Setup revision ${policy.revision}.`
            : "No additional checks are configured for this step."));
        return { section, complete: () => inputs.every(({ input }) => input.checked),
            confirmation: () => ({ revision: policy.revision,
                checks: inputs.filter(({ input }) => input.checked).map(({ id }) => id).sort() }) };
    }
    function releaseSummary(title, evidence) {
        if (!evidence) return null;
        return node("section", { class: "record-section", "aria-label": title },
            node("h3", { class: "h5" }, title),
            node("p", { class: "form-text" }, `Setup revision ${evidence.revision} · Confirmed ${evidence.confirmed_at}`),
            node("p", { class: "form-text" }, evidence.include_standing ? "Academic standing selected in this institutional setup." : "Academic standing not selected in this institutional setup."),
            evidence.include_standing && evidence.standing_definitions ? node('p', { class: 'form-text' },
                text('setup_selection_hint')) : null,
            evidence.include_standing ? node('p', { class: 'form-text' },
                text(evidence.standing_presentation?.periods === 'effective_at_cutoff' ? 'current_hint' : 'all_hint'),
                ' ', text(evidence.standing_presentation?.show_dates === false ? 'dates_hidden' : 'dates_shown')) : null,
            evidence.checks.length ? node("ul", {}, ...evidence.checks.map((check) =>
                node("li", {}, node("strong", {}, check.label), check.details ? ` — ${check.details}` : "")))
                : node("p", {}, "No additional institutional checks applied."));
    }
    async function policy() {
        const body = openDrawer("Transcript setup", "INSTITUTIONAL SETUP");
        const saved = await request("/sis/transcript-policy/");
        if (!body.isConnected) return;
        const standing = node("input", { id: "transcript-standing", type: "checkbox", class: "form-check-input",
            checked: saved.include_standing === true });
        const periods = node('select', { id: 'transcript-standing-periods', class: 'form-select',
            'aria-describedby': 'transcript-standing-periods-help' },
            node('option', { value: 'through_cutoff' }, text('periods_all')),
            node('option', { value: 'effective_at_cutoff' }, text('periods_current')));
        periods.value = saved.standing_presentation?.periods || 'through_cutoff';
        const dates = node('input', { id: 'transcript-standing-dates', type: 'checkbox', class: 'form-check-input',
            checked: saved.standing_presentation?.show_dates !== false });
        const periodDescription = node('p', { id: 'transcript-standing-periods-help', class: 'form-text' });
        const explainPeriod = () => { periodDescription.textContent = text(periods.value === 'effective_at_cutoff'
            ? 'current_hint' : 'all_hint'); };
        periods.addEventListener('change', explainPeriod);
        explainPeriod();
        const presentationArea = node('div', { class: 'record-section bg-white border rounded p-3 mb-3' },
            node('label', { for: periods.id, class: 'form-label' }, text('periods')), periods, periodDescription,
            node('div', { class: 'form-check mt-3' }, dates,
                node('label', { for: dates.id, class: 'form-check-label' }, text('show_dates'))),
            node('p', { class: 'form-text mb-0' }, text('presentation_help')));
        const list = node("div");
        let rows = [], adding = false, saving = false, notice = '';
        const status = node("p", { class: "form-text", role: "status" });
        const selectionArea = node('div', { class: 'record-field-group mb-3' });
        const selection = window.createTranscriptStandingSelection({ node, request, task,
            labels: ui.standingLabels, container: selectionArea, saved, enabled: () => standing.checked,
            changed: () => update(), denied });
        const draft = () => ({ checks: rows.map(row => ({ id: row.id, label: row.label.value.trim(),
            details: row.details.value.trim(), stage: row.stage.value })), include_standing: standing.checked,
            standing_definitions: selection.valid() ? selection.value() : null,
            standing_presentation: { periods: periods.value, show_dates: dates.checked } });
        function denied() {
            if (body.isConnected) body.replaceChildren(node('p', { role: 'alert', class: 'form-text' },
                text('setup_unavailable')));
        }
        const save = button("Save setup", async () => {
            const captured = draft();
            if (captured.checks.some((check) => !check.label)) {
                notice = "Give every check a title before saving.";
                rows.find((row) => !row.label.value.trim())?.label.focus();
                return;
            }
            if (standing.checked && !selection.valid()) {
                notice = text('selection_required');
                document.getElementById('transcript-standing-mode')?.focus();
                return;
            }
            saving = true; update();
            try {
                const result = await request("/sis/transcript-policy/", "POST", { revision: saved.revision, ...captured });
                if (!body.isConnected) return;
                saved.revision = result.revision;
                if (JSON.stringify(draft()) === JSON.stringify(captured)) { await policy(); return; }
                notice = text('newer_edits_unsaved');
            } catch (error) {
                if ([401, 403].includes(error.status)) denied();
                throw error;
            } finally { saving = false; update(); }
        }, "btn-primary", update);
        const add = button("Add check", async () => {
            if (adding || rows.length >= 12) return;
            adding = true;
            try {
                const operation = await request("/sis/transcript-policy/check-id/");
                if (body.isConnected && rows.length < 12) {
                    append({ id: operation.id, label: "", details: "", stage: "preparation" }, true);
                }
            } finally { adding = false; update(); }
        }, "btn-outline-primary", update);
        function update() {
            const message = `${notice ? `${notice} ` : ''}${rows.length} of 12 checks · Setup revision ${saved.revision}`;
            if (status.textContent !== message) status.textContent = message;
            add.disabled = adding || rows.length >= 12;
            save.disabled = adding || saving || selection.pending();
        }
        function append(check, focus = false) {
            const label = node("input", { id: `release-title-${check.id}`, type: "text", class: "form-control",
                value: check.label, maxlength: "160", required: true });
            const details = node("textarea", { id: `release-help-${check.id}`, class: "form-control", rows: "3",
                maxlength: "500", value: check.details });
            const stage = node("select", { id: `release-stage-${check.id}`, class: "form-select" },
                node("option", { value: "preparation" }, "Preparation"),
                node("option", { value: "delivery" }, "Delivery"), node("option", { value: "both" }, "Both steps"));
            stage.value = check.stage;
            const title = node("summary", { class: "fw-semibold" }, check.label || "New release check");
            label.addEventListener("input", () => { title.textContent = label.value.trim() || "New release check"; });
            const card = node("details", { class: "border rounded p-3 mb-3", open: focus }, title,
                node("div", { class: "mt-3" }, node("label", { for: label.id, class: "form-label" }, "Check title"), label),
                node("div", { class: "mt-3" }, node("label", { for: details.id, class: "form-label" }, "Instructions"), details),
                node("div", { class: "mt-3" }, node("label", { for: stage.id, class: "form-label" }, "Required at"), stage),
                button("Remove check", () => { rows = rows.filter((row) => row.id !== check.id); card.remove(); update(); }, "btn-outline-danger mt-3"));
            rows.push({ id: check.id, label, details, stage });
            list.append(card);
            update();
            if (focus) label.focus();
        }
        body.append(node("h3", { class: "h5" }, "Transcript content"),
            node("div", { class: "form-check mb-3" }, standing, node("label", { for: standing.id, class: "form-check-label" },
                "Include recorded academic standing")),
            node("p", { class: "form-text" }, text('content_help')),
            presentationArea, selectionArea, node("h3", { class: "h5" }, "Release checks"), node("p", {}, "Set the confirmations your office requires before preparation, delivery, or both. " +
            "These are staff checks; they do not automatically block transcripts based on balances or registration holds."),
            node("p", { class: "form-text" }, "Changes apply to new confirmations. Earlier documents and confirmations retain their original checks. " +
                "If setup changes while you edit, reload it before saving again."), status, list,
            node("div", { class: "inline-actions" }, add, save, button("Reload setup", policy, "btn-link")));
        saved.checks.forEach((check) => append(check));
        standing.addEventListener('change', () => { presentationArea.hidden = !standing.checked; selection.refresh(); });
        presentationArea.hidden = !standing.checked;
        selection.refresh();
        update();
    }

    function academic(snapshot) {
        return node(
            "section",
            {
                class: "record-section",
                "aria-label": "Reviewed academic transcript",
            },
            node("h3", {}, snapshot.student.name),
            recordSummary([
                ["Institutional ID", snapshot.student.institutional_id],
                ["Program", `${snapshot.program.code} — ${snapshot.program.title}`],
            ]),
            snapshot.academic_verification?.status === "history_gaps"
                ? node(
                      "p",
                      { class: "alert alert-warning" },
                      "Some earlier academic history was not recorded. Current-state observations are identified; missing decisions have not been reconstructed.",
                  )
                : null,
            snapshot.identity_verification?.earlier_history_unknown
                ? node(
                      "p",
                      { class: "form-text" },
                      "Earlier name and ID history was not fully recorded. This document preserves the profile reviewed at preparation.",
                  )
                : null,
            snapshot.program_verification
                ? recordSummary([
                      [
                          "Program pursuit",
                          snapshot.program_verification.status === "closed"
                              ? "Closed at preparation"
                              : "Active at preparation",
                      ],
                      [
                          "Program evidence",
                          snapshot.program_verification.definition_recorded
                              ? "Retained cohort publication"
                              : snapshot.program_verification.labels_retained
                                ? "Retained program labels"
                                : "Observed current labels",
                      ],
                  ])
                : null,
            snapshot.standing ? node("section", { class: "record-section", "aria-label": "Transcript academic standing" },
                node("h3", { class: "h5" }, "Recorded academic standing"),
                snapshot.standing.filtered ? node('p', { class: 'form-text' },
                    text('document_selection_hint')) : null,
                node("p", { class: "form-text" }, `Selected primary pursuit · Recorded periods through ${snapshot.standing.through}. Earlier unrecorded standing is not inferred.`),
                node('p', { class: 'form-text' },
                    text(snapshot.standing.presentation?.periods === 'effective_at_cutoff' ? 'current_hint' : 'all_hint')),
                snapshot.standing.periods.length ? node("ul", {}, ...snapshot.standing.periods.map(row => node("li", { class: "text-break" },
                    `${row.code} · ${row.title}${snapshot.standing.presentation?.show_dates === false
                        ? '' : ` · ${row.starts_on} through ${row.ends_on || "open"}`}`)))
                    : node("p", {}, snapshot.standing.presentation?.periods === 'effective_at_cutoff'
                        ? text(snapshot.standing.filtered ? 'current_selected_empty' : 'current_empty')
                        : snapshot.standing.filtered ? text('selected_standing_empty')
                            : "No standing is recorded through this date.")) : null,
            snapshot.purpose === 'continuing_education' ? grid(
                [ui.ceuLabels?.record_term || 'Term', ui.ceuLabels?.record_course || 'Course',
                    ui.ceuLabels?.record_title || 'Title', ui.ceuLabels?.earned_completion || 'Verified actual completion',
                    ui.ceuLabels?.earned_title || 'Earned CEUs'],
                snapshot.credits.map(row => [row.term_code, row.course_code, row.course_title,
                    row.earned_ceus.completed_on, window.earnedCeuText(row.earned_ceus, ui.ceuLabels)]),
                ui.ceuLabels?.ceu_transcript || 'Continuing education transcript',
            ) : grid(
                ["Term", "Course", "Title", "Attempted", "Earned", "Grade", "History"],
                snapshot.credits.map((row) => [
                    row.term_code,
                    row.course_code,
                    row.course_title,
                    units(row.attempted_milli),
                    units(row.completed_milli),
                    row.grade_code || "In progress",
                    node(
                        "details",
                        {},
                        node("summary", {}, "Dates & history"),
                        recordSummary([
                            [
                                "Recorded source-period start",
                                row.academic_on ||
                                    (row.section_version_id ? "Retained by offering" : "Unknown"),
                            ],
                            ["Actual completion", row.completed_on || "Not recorded"],
                            [
                                "Earlier history",
                                row.history_recorded === undefined
                                    ? "Not captured in this document"
                                    : !row.history_recorded
                                      ? "Observed current facts"
                                      : row.earlier_history_unknown
                                        ? "Some earlier decisions are unknown"
                                        : "Recorded",
                            ],
                        ]),
                    ),
                ]),
                "Transcript credit history",
            ),
            node(
                "div",
                { class: "transcript-totals" },
                snapshot.purpose === 'continuing_education' ? recordSummary([
                    [ui.ceuLabels?.earned_total || 'Total earned CEUs', units(snapshot.totals.earned_ceu_milli)],
                ]) : recordSummary([
                    ["Attempted credits", units(snapshot.totals.attempted_milli)],
                    ["Earned credits", units(snapshot.totals.completed_milli)],
                    [
                        "GPA",
                        snapshot.totals.gpa_milli === null
                            ? "Not yet available"
                            : units(snapshot.totals.gpa_milli),
                    ],
                ]),
            ),
        );
    }
    async function record(id) {
        const body = openDrawer("Transcript request", "REGISTRAR WORKSPACE", {
            record: { key: `transcript-request:${id}`, label: "Transcript request", run: () => record(id) },
        });
        const loading = node("p", { role: "status", class: "form-text" }, "Loading transcript request…");
        body.append(loading);
        const original = await request(`/portal/api/transcripts/${id}/`);
        if (!body.isConnected) return;
        const currentStatus = node("span", {}, badge(original.status));
        body.append(
            recordSummary([
                ["Request status", currentStatus],
                [
                    "Requested delivery",
                    original.delivery === "pickup"
                        ? "Student pickup"
                        : String(original.delivery || "Not recorded")
                              .replaceAll("_", " ")
                              .replace(/^./u, (value) => value.toUpperCase()),
                ],
                [
                    "Recipient",
                    original.recipient ||
                        (original.delivery === "pickup" ? "Student pickup" : "Not recorded"),
                ],
            ]),
        );
        if (!can("sis:student:read")) {
            loading.remove();
            body.append(
                node(
                    "p",
                    {},
                    "Preparing or viewing transcript documents also requires student academic read permission. Request that office grant before continuing.",
                ),
            );
            return;
        }
        const current = await request(`/sis/transcript-requests/${id}/fulfillment/`);
        if (!body.isConnected) return;
        loading.remove();
        currentStatus.replaceChildren(badge(current.status));
        if (ui.worklist) {
            const assignment = node('section', { class: 'record-section' });
            body.append(assignment);
            void ui.worklist.mount(assignment, id, message => {
                if (body.isConnected) body.replaceChildren(node('p', { class: 'alert alert-warning', role: 'alert' }, message));
            });
        }
        const progress = workflow(current.status);
        if (progress) body.append(progress);
        const actions = node("div", {
            class: "record-task-grid transcript-request-tasks",
            role: "group",
            "aria-label": "Transcript request tasks",
        });
        if (current.document)
            actions.append(
                taskCard("Prepared document", "Review the retained document, download its protected PDF or record actual delivery.",
                    "file-earmark-check", button("Review transcript", () => document(id, current.document.id), "btn-primary")),
            );
        if (["pending", "ready"].includes(current.status))
            actions.append(
                taskCard("Preparation", current.document
                    ? "Review the current academic record and prepare a new revision. Earlier prepared documents retain their values."
                    : "Review the academic record, document layout and institutional release checks before preparing a document.", "journal-check", button(
                    current.document ? "Review new revision" : "Review preparation",
                    () => review(id),
                    current.document ? "btn-outline-secondary" : "btn-primary",
                )),
            );
        if (actions.childElementCount) body.append(actions);
        body.append(
            node(
                "p",
                { class: "form-text" },
                "Preparation creates an encrypted snapshot. A PDF download alone never marks a transcript as delivered. Record actual delivery evidence after using the institution's approved process; this screen does not send email.",
            ),
        );
    }
    async function review(id, layoutCode = null) {
        const body = openDrawer("Review transcript preparation", "ACADEMIC REVIEW → PREPARE PDF");
        const loading = node("p", { role: "status", class: "form-text" }, "Loading preparation review…");
        body.append(loading);
        const reviewed = await request(`/sis/transcript-requests/${id}/review/`, "POST",
            layoutCode === null ? {} : { layout_code: layoutCode });
        if (!body.isConnected) return;
        loading.remove();
        const consent = node("input", {
            type: "checkbox",
            id: "transcript-release-reviewed",
            class: "form-check-input",
        });
        const checklist = releaseChecklist(reviewed.release_policy, "preparation", () => update());
        const update = () => { prepare.disabled = !consent.checked || !checklist.complete() || !reviewed.snapshot.issuer.name; };
        const prepare = button(
            "Prepare transcript",
            async () => {
                if (!consent.checked || !checklist.complete()) return;
                const saved = await request(`/sis/transcript-requests/${id}/prepare/`, "POST", {
                    operation_id: reviewed.operation_id,
                    revision: reviewed.revision,
                    release_reviewed: consent.checked,
                    release_confirmation: checklist.confirmation(),
                    review_token: reviewed.review_token,
                    layout_code: reviewed.layout.code,
                });
                if (!body.isConnected) return;
                await document(id, saved.id);
                await ui.refreshDirectory();
            },
            "btn-primary",
            update,
        );
        prepare.disabled = true;
        consent.addEventListener("change", () => {
            update();
        });
        const layouts = node("fieldset", { class: "record-section" }, node("legend", { class: "h5" }, "Document layout"));
        layouts.append(node("p", { class: "form-text" },
            "Choose a style and paper size. Changing the layout starts a fresh review. Academic values stay the same."));
        const choices = node("div", { class: "row g-2" });
        layouts.append(choices);
        for (const layout of reviewed.layouts) {
            const selected = layout.code === reviewed.layout.code;
            const choice = node("input", { type: "radio", class: "form-check-input m-0", name: "transcript-layout",
                id: `transcript-layout-${layout.code}`, value: layout.code, checked: layout.code === reviewed.layout.code });
            choice.addEventListener("change", () => {
                if (choice.checked && body.isConnected) task(() => review(id, layout.code), choice);
            });
            choices.append(node("div", { class: "col-sm-6" }, node("label", {
                for: choice.id, class: `d-flex align-items-center gap-2 border rounded p-3 h-100 ${selected ? "border-primary bg-primary-subtle" : "bg-body"}`,
            }, choice, node("span", {}, layout.label))));
        }
        const preview = node("section", { class: "record-section", "aria-label": "Document layout preview", hidden: true });
        const previewButton = button("Preview layout", async () => {
            const html = await requestHtml(`/sis/transcript-requests/${id}/preview/`, {
                operation_id: reviewed.operation_id, revision: reviewed.revision,
                review_token: reviewed.review_token, layout_code: reviewed.layout.code,
            });
            if (!body.isConnected) return;
            preview.hidden = false;
            const heading = node("h3", { class: "h5", tabindex: "-1" }, "Preview only");
            preview.replaceChildren(heading,
                node("p", { class: "form-text" }, "This is a layout preview. Preparation and delivery remain separate steps."),
                node("iframe", { sandbox: "", title: `${reviewed.layout.label} transcript preview`,
                    srcdoc: html, class: "w-100 border rounded", style: "height: 32rem;", tabindex: "0" }));
            heading.focus();
        });
        body.append(
            node(
                "p",
                {},
                `Issuer: ${reviewed.snapshot.issuer.name || "Not configured"}. Intended delivery: ${reviewed.delivery}.`,
            ),
            layouts,
            node("div", { class: "inline-actions" }, previewButton),
            preview,
            academic(reviewed.snapshot),
            checklist.section,
            !reviewed.snapshot.issuer.name
                ? node(
                      "p",
                      { class: "alert alert-warning" },
                      "Configure the institution's transcript issuer before preparing an official document.",
                  )
                : null,
            node(
                "p",
                {},
                "Review final grades, credit totals, current restrictions and the institution's release requirements before confirming. These checks do not automatically apply a hold or certify external policy requirements.",
            ),
            node(
                "div",
                { class: "form-check mb-3" },
                consent,
                node(
                    "label",
                    {
                        for: "transcript-release-reviewed",
                        class: "form-check-label",
                    },
                    "I reviewed the academic record and institutional release requirements",
                ),
            ),
            node("p", {}, "Preparation reference: ", node("code", {}, reviewed.operation_id)),
            node(
                "p",
                { class: "form-text" },
                "This review expires after 20 minutes. After an interrupted response, keep it to retry or check the saved document. Refresh an expired review and check the record again.",
            ),
            node(
                "div",
                { class: "inline-actions" },
                prepare,
                button("Check prepared document", () => document(id, reviewed.operation_id)),
                button("Refresh review", () => review(id, reviewed.layout.code)),
                button("Back to request", () => record(id), "btn-link"),
            ),
        );
    }
    async function document(id, documentId) {
        const body = openDrawer("Prepared transcript", "OFFICIAL TRANSCRIPT", {
            record: { key: `transcript-document:${id}:${documentId}`, label: "Prepared transcript", run: () => document(id, documentId) },
        });
        const loading = node("p", { role: "status", class: "form-text" }, "Loading prepared transcript…");
        body.append(loading);
        const value = await request(`/sis/transcript-requests/${id}/documents/${documentId}/`);
        if (!body.isConnected) return;
        const current = await request(`/sis/transcript-requests/${id}/fulfillment/`);
        if (!body.isConnected) return;
        loading.remove();
        body.append(
            node("div", { class: "transcript-document-identity" },
                node("span", { class: "icon-soft", "aria-hidden": "true" }, node("i", { class: "bi bi-file-earmark-check" })),
                node("div", {}, node("h3", {}, value.snapshot.student.name),
                    node("p", { class: "form-text" }, `Institutional ID: ${value.snapshot.student.institutional_id}`)),
                badge(value.is_latest ? "Current document" : "Earlier document")),
            recordSummary([
                ["Prepared at", value.created_at],
                ["Document layout", value.snapshot.presentation.label],
                ["Document reference", value.id],
            ]),
        );
        if (value.academic_check === "unavailable")
            body.append(
                node(
                    "p",
                    { class: "alert alert-warning", role: "status" },
                    "Current identity, program or academic history could not be verified. The prepared document remains available. Ask the records office to review the current record before preparing or recording delivery.",
                ),
            );
        if (value.academic_changed)
            body.append(
                node(
                    "p",
                    { class: "alert alert-warning" },
                    "The current record no longer matches this document’s preparation checks. Review and prepare an updated revision before recording delivery.",
                ),
            );
        if (!value.is_latest)
            body.append(
                node(
                    "p",
                    { class: "alert alert-info" },
                    "This historical document has been superseded by a later revision.",
                ),
            );
        const actions = node(
            "div",
            {
                class: "inline-actions",
                role: "group",
                "aria-label": "Transcript document tasks",
            },
            button("Download protected PDF", () =>
                requestFile(
                    `/sis/transcript-requests/${id}/documents/${documentId}/pdf/`,
                    {},
                    `transcript-${documentId}.pdf`,
                    { type: "application/pdf", maximum: 4 * 1024 * 1024 },
                ),
            ),
            node(
                "a",
                {
                    class: "btn btn-outline-primary",
                    href: `/sis/transcript-requests/${id}/documents/${documentId}/view/`,
                    target: "_blank",
                    rel: "noopener",
                    "aria-label": "Accessible view (opens in a new tab)",
                },
                "Accessible view",
            ),
        );
        if (
            current.status === "ready" &&
            value.is_latest &&
            value.academic_changed === false &&
            ["verified", "history_gaps"].includes(value.academic_check)
        )
            actions.append(
                button("Record delivery", () => delivery(id, documentId, current.version), "btn-primary"),
            );
        body.append(actions, academic(value.snapshot), releaseSummary("Preparation checks", value.preparation_release),
            releaseSummary("Delivery checks", value.delivery_release));
        if (value.delivery)
            body.append(
                node(
                    "section",
                    {
                        class: "record-section",
                        "aria-label": "Recorded delivery evidence",
                    },
                    node("h3", {}, "Delivery evidence"),
                    node("p", {}, value.delivery.delivered_at),
                    node(
                        "p",
                        { class: "text-break", style: "white-space: pre-wrap" },
                        value.delivery.evidence,
                    ),
                ),
            );
        body.append(button("Back to transcript request", () => record(id), "btn-link"));
    }
    async function delivery(id, documentId, version, draft = {}) {
        const body = openDrawer("Record transcript delivery", "DETAILS → REVIEW → CONFIRM");
        const policy = await request("/sis/transcript-policy/");
        if (!body.isConnected) return;
        const checklist = releaseChecklist(policy, "delivery", () => { submit.disabled = !checklist.complete(); });
        const fields = [
            ["delivered_at", "Actual delivery time", "datetime-local"],
            ["evidence", "Delivery evidence / institutional reference", "textarea"],
        ];
        const form = await buildForm(fields, draft, {
            layout: "transcript_delivery",
        });
        if (!body.isConnected) return;
        const submit = node("button", { type: "submit", class: "btn btn-primary" }, "Review delivery");
        form.elements.evidence.maxLength = 2000;
        submit.disabled = !checklist.complete();
        const refresh = button("Refresh checks", () => delivery(id, documentId, version, {
            delivered_at: form.elements.delivered_at.value, evidence: form.elements.evidence.value,
        }));
        form.append(checklist.section,
            node("div", { class: "inline-actions", role: "group", "aria-label": "Delivery review actions" }, submit, refresh));
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity() || !checklist.complete()) return;
                const data = { ...formData(form, fields), version, release_confirmation: checklist.confirmation() };
                const entered = {
                    delivered_at: form.elements.delivered_at.value,
                    evidence: form.elements.evidence.value,
                };
                const reviewed = await request(
                    `/sis/transcript-requests/${id}/documents/${documentId}/delivery-review/`,
                    "POST",
                    data,
                );
                if (!body.isConnected) return;
                deliveryPlan(id, documentId, reviewed, entered);
            }, submit).then(() => { submit.disabled = !checklist.complete(); },
                () => { submit.disabled = !checklist.complete(); });
        });
        body.append(
            node(
                "p",
                { class: "alert alert-info" },
                "Record evidence only after actual delivery through the institution's approved process. This screen does not send a document or email.",
            ),
            form,
            button("Back to document", () => document(id, documentId), "btn-link"),
        );
    }
    function deliveryPlan(id, documentId, reviewed, draft) {
        const body = openDrawer("Review transcript delivery", "DETAILS → REVIEW → CONFIRM");
        body.append(
            recordSummary([
                ["Document", documentId],
                ["Actual delivery time", reviewed.delivery.delivered_at],
            ]),
            node("section", { class: "record-section", "aria-label": "Reviewed delivery evidence" },
                node("h3", {}, "Delivery evidence"),
                node("p", { class: "text-break", style: "white-space: pre-wrap" }, reviewed.delivery.evidence)),
            node("section", { class: "record-section", "aria-label": "Reviewed delivery release checks" },
                node("h3", {}, "Confirmed release checks"),
                node("ul", {}, ...reviewed.release_policy.checks.filter((check) => check.stage === "delivery" || check.stage === "both")
                    .map((check) => node("li", {}, check.label)))),
            node(
                "p",
                { class: "form-text" },
                "This review expires after 20 minutes. Changing details keeps your entries. After an interrupted response, retry unchanged or return to the document to check delivery before starting another review.",
            ),
            node(
                "div",
                { class: "inline-actions" },
                button(
                    "Confirm delivery",
                    async () => {
                        await request(
                            `/sis/transcript-requests/${id}/documents/${documentId}/deliver/`,
                            "POST",
                            {
                                version: reviewed.delivery.version,
                                delivered_at: reviewed.delivery.delivered_at,
                                evidence: reviewed.delivery.evidence,
                                release_confirmation: reviewed.delivery.release_confirmation,
                                operation_id: reviewed.operation_id,
                                review_token: reviewed.review_token,
                            },
                        );
                        if (!body.isConnected) return;
                        await document(id, documentId);
                        await ui.refreshDirectory();
                    },
                    "btn-primary",
                ),
                button("Change details", () => delivery(id, documentId, reviewed.delivery.version, draft)),
                button("Back to document", () => document(id, documentId), "btn-link"),
            ),
        );
    }
    return { record, policy };
};
