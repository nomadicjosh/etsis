/* Reviewed, immutable transcript documents and separately recorded delivery evidence. */
window.createWorkspaceTranscripts = function (ui) {
    "use strict";
    const {
        node,
        request,
        requestFile,
        task,
        can,
        table,
        badge,
        buildForm,
        formData,
        openDrawer,
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
    const units = (value) => (value / 1000).toFixed(3);
    function academic(snapshot) {
        return node(
            "section",
            {
                class: "record-section",
                "aria-label": "Reviewed academic transcript",
            },
            node("h3", {}, snapshot.student.name),
            node(
                "p",
                {},
                `Institutional ID: ${snapshot.student.institutional_id}. Program: ${snapshot.program.code} — ${snapshot.program.title}.`,
            ),
            grid(
                ["Term", "Course", "Title", "Attempted", "Earned", "Grade"],
                snapshot.credits.map((row) => [
                    row.term_code,
                    row.course_code,
                    row.course_title,
                    units(row.attempted_milli),
                    units(row.completed_milli),
                    row.grade_code || "In progress",
                ]),
                "Transcript credit history",
            ),
            node(
                "p",
                {},
                `Attempted: ${units(snapshot.totals.attempted_milli)}. Earned: ${units(snapshot.totals.completed_milli)}. GPA: ${snapshot.totals.gpa_milli === null ? "Not yet available" : units(snapshot.totals.gpa_milli)}.`,
            ),
        );
    }
    async function record(id) {
        const original = await request(`/portal/api/transcripts/${id}/`);
        const body = openDrawer(
            "Official transcript fulfillment",
            "REGISTRAR WORKSPACE",
        );
        body.append(
            badge(original.status),
            node(
                "p",
                {},
                `Requested delivery: ${original.delivery}. Recipient: ${original.recipient || "Student pickup"}.`,
            ),
        );
        if (!can("sis:student:read")) {
            body.append(
                node(
                    "p",
                    {},
                    "Preparing or viewing transcript documents also requires student academic read permission. Request that office grant before continuing.",
                ),
            );
            return;
        }
        const current = await request(
            `/sis/transcript-requests/${id}/fulfillment/`,
        );
        if (!body.isConnected) return;
        if (["pending", "ready"].includes(current.status))
            body.append(
                button(
                    current.document
                        ? "Review an updated document revision"
                        : "Review document preparation",
                    () => review(id),
                    "btn-primary",
                ),
            );
        if (current.document)
            body.append(
                button("Review prepared transcript document", () =>
                    document(id, current.document.id),
                ),
            );
        body.append(
            node(
                "p",
                { class: "form-text" },
                "Preparation creates an encrypted snapshot. A PDF download alone never marks a transcript as delivered. Record actual delivery evidence after using the institution's approved process; this screen does not send email.",
            ),
        );
    }
    async function review(id) {
        const reviewed = await request(
            `/sis/transcript-requests/${id}/review/`,
            "POST",
            {},
        );
        const body = openDrawer(
            "Review transcript preparation",
            "ACADEMIC REVIEW → PREPARE PDF",
        );
        const consent = node("input", {
            type: "checkbox",
            id: "transcript-release-reviewed",
            class: "form-check-input",
        });
        const prepare = button(
            "Prepare protected transcript PDF",
            async () => {
                const saved = await request(
                    `/sis/transcript-requests/${id}/prepare/`,
                    "POST",
                    {
                        operation_id: reviewed.operation_id,
                        revision: reviewed.revision,
                        release_reviewed: consent.checked,
                    },
                );
                await document(id, saved.id);
                await ui.refreshDirectory();
            },
            "btn-primary",
        );
        prepare.disabled = true;
        consent.addEventListener("change", () => {
            prepare.disabled =
                !consent.checked || !reviewed.snapshot.issuer.name;
        });
        body.append(
            node(
                "p",
                {},
                `Issuer: ${reviewed.snapshot.issuer.name || "Not configured"}. Intended delivery: ${reviewed.delivery}.`,
            ),
            academic(reviewed.snapshot),
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
            node(
                "p",
                {},
                "Preparation reference: ",
                node("code", {}, reviewed.operation_id),
            ),
            node(
                "p",
                { class: "form-text" },
                "After an interrupted response, retain this review to retry or check the saved document.",
            ),
            node(
                "div",
                { class: "inline-actions" },
                prepare,
                button("Check prepared document", () =>
                    document(id, reviewed.operation_id),
                ),
                button("Back to request", () => record(id), "btn-link"),
            ),
        );
    }
    async function document(id, documentId) {
        const value = await request(
            `/sis/transcript-requests/${id}/documents/${documentId}/`,
        );
        const current = await request(
            `/sis/transcript-requests/${id}/fulfillment/`,
        );
        const body = openDrawer(
            "Prepared transcript document",
            "IMMUTABLE ACADEMIC SNAPSHOT",
        );
        body.append(
            academic(value.snapshot),
            node(
                "p",
                {},
                `Prepared at ${value.created_at}. Reference: ${value.id}.`,
            ),
            value.academic_changed
                ? node(
                      "p",
                      { class: "alert alert-warning" },
                      "The academic record or institutional details changed after preparation. Review and prepare an updated revision before recording delivery.",
                  )
                : null,
            !value.is_latest
                ? node(
                      "p",
                      { class: "alert alert-info" },
                      "This historical document has been superseded by a later revision.",
                  )
                : null,
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
                },
                "Open accessible document view",
            ),
        );
        if (
            current.status === "ready" &&
            value.is_latest &&
            !value.academic_changed
        )
            body.append(
                button(
                    "Record completed delivery",
                    () => delivery(id, documentId, current.version),
                    "btn-primary",
                ),
            );
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
        body.append(
            button("Back to transcript request", () => record(id), "btn-link"),
        );
    }
    async function delivery(id, documentId, version) {
        const operation = await request("/sis/ui/operation-id/");
        const body = openDrawer(
            "Record completed transcript delivery",
            "DELIVERY EVIDENCE → CONFIRM",
        );
        const fields = [
            ["delivered_at", "Actual delivery time", "datetime-local"],
            [
                "evidence",
                "Delivery evidence / institutional reference",
                "textarea",
            ],
        ];
        const form = await buildForm(fields),
            submit = node(
                "button",
                { type: "submit", class: "btn btn-primary" },
                "Confirm recorded delivery",
            );
        form.elements.evidence.maxLength = 2000;
        form.append(submit);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                await request(
                    `/sis/transcript-requests/${id}/documents/${documentId}/deliver/`,
                    "POST",
                    {
                        ...formData(form, fields),
                        operation_id: operation.id,
                        version,
                    },
                );
                await document(id, documentId);
                await ui.refreshDirectory();
            }, submit);
        });
        body.append(
            node(
                "p",
                { class: "alert alert-info" },
                "Confirm only after actual delivery through the approved institutional process. Record an appropriate collection or delivery reference. This action records evidence; it does not send a document or email.",
            ),
            form,
            node(
                "p",
                { class: "form-text" },
                "After an interrupted response, keep this form to retry the same operation, or reload the saved document to check delivery.",
            ),
            button(
                "Check saved document",
                () => document(id, documentId),
                "btn-link",
            ),
        );
    }
    return { record };
};
