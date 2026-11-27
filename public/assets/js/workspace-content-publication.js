/* One review/confirmation flow for author handoff, publication windows, return and archive. */
window.createCampusContentPublication = function (ui) {
    "use strict";
    const { node, request, task, openDrawer, recordSummary, badge, edit, saved } = ui;
    let sequence = 0;
    const stamp = (value) =>
        value ? new Date(value).toLocaleString(undefined, { timeZoneName: "short" }) : null;
    const button = (label, run, style = "btn-outline-primary") =>
        node(
            "button",
            { type: "button", class: `btn ${style}`, onclick: (event) => task(run, event.currentTarget) },
            label,
        );
    const field = (label, input) =>
        node("div", { class: "mb-3" }, node("label", { for: input.id, class: "form-label" }, label), input);
    const visibility = (post) =>
        post.status !== "published"
            ? post.status === "pending"
                ? "Awaiting review"
                : post.status
            : post.visible_from && new Date(post.visible_from) > new Date()
              ? "Scheduled"
              : post.visible_until && new Date(post.visible_until) <= new Date()
                ? "Expired"
                : "Published";
    async function open(post, action) {
        const titles = {
            approve: "Review publication",
            submit: "Request review",
            return: "Return to author",
            archive: "Review archive",
        };
        const labels = {
            approve: "Confirm publication",
            submit: "Confirm review request",
            return: "Return to author",
            archive: "Confirm archive",
        };
        const body = openDrawer(titles[action], "CAMPUS PUBLISHING");
        const prefix = `content-publication-${++sequence}`;
        let serial = 0,
            proof = null,
            committing = false;
        const form = node("form", { class: "record-form" });
        const from = node("input", {
            id: `${prefix}-from`,
            type: "datetime-local",
            step: "60",
            class: "form-control",
            "aria-describedby": `${prefix}-zone`,
        });
        const until = node("input", {
            id: `${prefix}-until`,
            type: "datetime-local",
            step: "60",
            class: "form-control",
            "aria-describedby": `${prefix}-zone`,
        });
        const note = node("textarea", {
            id: `${prefix}-note`,
            required: true,
            maxlength: "1000",
            rows: "4",
            class: "form-control",
        });
        const status = node("p", { role: "status", class: "form-text" });
        const confirm = button(
            labels[action],
            async () => {
                if (!proof || committing) return;
                const reviewed = proof;
                committing = true;
                from.disabled = until.disabled = note.disabled = true;
                try {
                    await request(`/sis/portal/posts/${post.id}/publication/`, "POST", {
                        operation_id: reviewed.operation_id,
                        action: reviewed.action,
                        revision: reviewed.revision,
                        window: reviewed.window,
                        note: reviewed.note,
                        review_token: reviewed.review_token,
                    });
                    if (body.isConnected) await saved(action);
                } catch (error) {
                    if (error.status === 409 && body.isConnected) {
                        proof = null;
                        confirm.remove();
                        updateButton.remove();
                        status.textContent = "Reopen the editor to review the current content.";
                    }
                    throw error;
                } finally {
                    committing = false;
                    from.disabled = until.disabled = note.disabled = false;
                }
            },
            "btn-primary",
        );
        confirm.disabled = true;
        const updateButton = button(action === "return" ? "Review return" : "Review schedule", update);
        updateButton.hidden = action !== "return";
        function changed() {
            ++serial;
            proof = null;
            confirm.disabled = true;
            updateButton.hidden = false;
            status.textContent = "Review these changes before confirming.";
        }
        for (const input of [from, until, note]) input.addEventListener("input", changed);
        async function update() {
            if (!form.reportValidity()) return;
            const token = ++serial;
            const window = {
                visible_from: action === "approve" && from.value ? new Date(from.value).toISOString() : null,
                visible_until:
                    action === "approve" && until.value ? new Date(until.value).toISOString() : null,
            };
            const result = await request(`/sis/portal/posts/${post.id}/publication/review/`, "POST", {
                action,
                revision: post.revision,
                window,
                note: action === "return" ? note.value : null,
            });
            if (!body.isConnected || token !== serial) return;
            proof = result;
            confirm.disabled = false;
            updateButton.hidden = true;
            status.textContent =
                action === "approve"
                    ? `${stamp(result.window.visible_from) ? `Starts ${stamp(result.window.visible_from)}` : "Publish immediately"} · ${stamp(result.window.visible_until) ? `Ends ${stamp(result.window.visible_until)}` : "No end date"}. Review expires after twenty minutes.`
                    : "Review ready. Confirm this saved content and audience.";
        }
        body.append(
            button("Back to editor", () => edit(post.id), "btn-link mb-3"),
            recordSummary([
                ["Audience", ui.audience.summary(post)],
                ["Current status", badge(visibility(post))],
            ]),
            node(
                "section",
                { class: "record-section", "aria-label": "Saved content review" },
                node("h3", {}, post.title),
                window.CampusRichText.article(post, node),
            ),
            node(
                "p",
                { class: "alert alert-info" },
                action === "approve"
                    ? post.audience === "public"
                        ? "This content may be read without signing in once its publication window begins."
                        : post.audience === 'programs' ? ui.audienceLabels.program_publish
                        : post.audience === 'roles' ? ui.audienceLabels.publish
                            : "Publish this saved content for signed-in campus users."
                    : action === "archive"
                      ? "Remove this content from the portal. Its saved wording is retained."
                      : action === "submit"
                        ? "Send this draft to staff with publishing permission. It remains hidden until approved."
                        : "Return this draft with a note for its author. It remains hidden.",
            ),
        );
        const fields = node("fieldset", { class: "record-form-section" }, node("legend", {}, action === "approve" ? "Publication window" : "Author response"));
        if (action === "approve")
            fields.append(
                field("Start publishing", from),
                field("Stop publishing", until),
                node(
                    "p",
                    { class: "form-text", id: `${prefix}-zone` },
                    `Dates use your browser's time zone: ${Intl.DateTimeFormat().resolvedOptions().timeZone}. Leave the start blank for immediate publication and the end blank to keep it available.`,
                ),
            );
        if (action === "return") fields.append(field("Note to author", note));
        if (["approve", "return"].includes(action)) form.append(fields);
        body.append(form, status, node("div", { class: "d-flex flex-wrap gap-2" }, updateButton, confirm));
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(update);
        });
        if (action !== "return") await update();
    }
    return { open, visibility };
};
