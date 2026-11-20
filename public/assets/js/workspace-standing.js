/* Institution-defined standing labels and pursuit-scoped, reviewed determinations. */
window.createWorkspaceStanding = function (ui) {
    "use strict";
    const { node, request, task, openDrawer, can } = ui;
    const button = (label, run, style = "btn-outline-primary") => node("button", {
        type: "button", class: `btn ${style}`, onclick: event => task(run, event.currentTarget),
    }, label);
    const field = (label, input) => node("div", { class: "mb-3" }, node("label", {
        for: input.id, class: "form-label",
    }, label), input);
    const describe = row => `${row.code} · ${row.title} · ${row.starts_on} through ${row.ends_on || "open"}`;
    const facts = rows => rows.map(({ id, definition_id, code, title, starts_on, ends_on, policy_reference }) =>
        ({ id, definition_id, code, title, starts_on, ends_on: ends_on || null, policy_reference: policy_reference || null }));

    async function definitions(back = null, choose = null, search = "", after = null, stack = []) {
        const body = openDrawer(choose ? "Choose standing" : "Standing definitions", "INSTITUTION SETUP");
        const endpoint = choose ? "choices" : "definitions";
        const page = await request(`/sis/academic-standing/${endpoint}/`, "POST", { search, ...(after ? { after } : {}) });
        if (!body.isConnected) return;
        const input = node("input", { id: "standing-search", type: "search", class: "form-control", maxlength: "80", value: search });
        const form = node("form", { class: "mb-3" }, field("Find a standing", input),
            node("button", { type: "submit", class: "btn btn-primary" }, "Search"));
        form.addEventListener("submit", event => { event.preventDefault(); task(() => definitions(back, choose, input.value)); });
        const again = () => definitions(back, choose, search, after, stack);
        body.append(node("p", { class: "form-text" }, "Definitions are labels chosen by your institution. They do not set GPA rules, registration holds or student activity. Earlier decisions retain their accepted labels."), form);
        for (const item of page.items) body.append(node("article", { class: "surface border p-3 mb-2" },
            node("h3", { class: "h6" }, `${item.code} · ${item.title}`), node("p", { class: "form-text" }, item.active ? "Active" : "Retired"),
            choose ? button("Use standing", () => choose(item)) : button("Edit definition", () => editDefinition(item, again))));
        if (!page.items.length) body.append(node("p", { role: "status" }, "No standing definitions match. An authorized administrator can add your institution's definitions."));
        body.append(node("div", { class: "inline-actions" },
            !choose && can("sis:standing:configure") ? button("Add definition", () => editDefinition(null, again), "btn-primary") : null,
            ...(stack.length ? [button("Previous definitions", () => definitions(back, choose, search, stack.at(-1), stack.slice(0, -1)))] : []),
            ...(page.next_after ? [button("More definitions", () => definitions(back, choose, search, page.next_after, [...stack, after]))] : []),
            back ? button("Back", back, "btn-link") : null));
    }

    function editDefinition(saved, back) {
        const body = openDrawer(saved ? "Edit standing definition" : "Add standing definition", "INSTITUTION SETUP");
        const code = node("input", { id: "standing-code", class: "form-control", required: true, maxlength: "32", value: saved?.code || "" });
        const title = node("input", { id: "standing-title", class: "form-control", required: true, maxlength: "120", value: saved?.title || "" });
        const active = node("input", { id: "standing-active", type: "checkbox", class: "form-check-input", checked: saved?.active ?? true });
        const save = node("button", { type: "submit", class: "btn btn-primary" }, "Save definition");
        const form = node("form", {}, field("Code", code), field("Name", title),
            node("div", { class: "form-check mb-3" }, active, node("label", { for: active.id, class: "form-check-label" }, "Available for new decisions")),
            node("p", { class: "form-text" }, "Retire a definition instead of deleting it. Existing student standing keeps its original name and dates."),
            node("div", { class: "inline-actions" }, save, button("Cancel", back, "btn-link")));
        form.addEventListener("submit", event => { event.preventDefault(); task(async () => {
            if (!form.reportValidity()) return;
            await request("/sis/academic-standing/save/", "POST", { id: saved?.id || null, version: saved?.version ?? null,
                code: code.value, title: title.value, active: active.checked });
            if (body.isConnected) await back();
        }, save); });
        body.append(form);
    }

    async function open(student, pursuit, back) {
        const loading = openDrawer("Academic standing", "STUDENT PROGRAM");
        const base = `/sis/students/${student}/programs/${pursuit}/standing/`;
        const saved = await request(base);
        if (!loading.isConnected) return;
        let rows = saved.rows.map(row => ({ ...row })), reason = "";
        async function choose(existing = null) {
            await definitions(render, async value => {
                const chosen = { definition_id: value.id, code: value.code, title: value.title };
                if (existing) Object.assign(existing, chosen);
                else {
                    const chooser = document.getElementById("standing-search");
                    const generated = await request("/sis/ui/operation-id/");
                    if (!chooser?.isConnected) return;
                    rows.push({ id: generated.id, ...chosen, starts_on: "", ends_on: null, policy_reference: null });
                }
                render();
            });
        }
        function render() {
            const body = openDrawer("Academic standing", "EDIT → REVIEW → CONFIRM");
            const form = node("form"), cards = node("div");
            const review = node("button", { type: "submit", class: "btn btn-primary" }, "Review changes");
            const update = () => { review.disabled = !saved.editable || JSON.stringify(facts(rows)) === JSON.stringify(facts(saved.rows)); };
            for (const row of rows) {
                const id = `standing-${row.id}`, summary = node("summary", { class: "fw-semibold text-break" }, describe(row));
                const start = node("input", { id: `${id}-start`, type: "date", class: "form-control", required: true, value: row.starts_on, disabled: !saved.editable });
                const end = node("input", { id: `${id}-end`, type: "date", class: "form-control", value: row.ends_on || "", min: row.starts_on, disabled: !saved.editable });
                const policy = node("input", { id: `${id}-policy`, class: "form-control", maxlength: "200", value: row.policy_reference || "", disabled: !saved.editable });
                const changed = () => {
                    row.starts_on = start.value; row.ends_on = end.value || null; row.policy_reference = policy.value || null;
                    end.min = start.value; summary.textContent = describe(row); update();
                };
                [start, end, policy].forEach(input => input.addEventListener("input", changed));
                cards.append(node("details", { class: "surface border p-3 mb-3", open: !saved.rows.some(old => old.id === row.id) }, summary,
                    field("Effective from", start), field("Effective through", end), field("Policy reference (optional)", policy),
                    saved.editable ? node("div", { class: "inline-actions" }, button("Change standing", () => choose(row), "btn-sm btn-outline-primary"),
                        button("Remove period", () => { rows = rows.filter(item => item !== row); render(); }, "btn-sm btn-outline-danger")) : null));
            }
            if (!rows.length) cards.append(node("p", { role: "status" }, "No academic standing has been recorded for this pursuit."));
            const narrative = node("textarea", { id: "standing-reason", class: "form-control", rows: "3", required: true, maxlength: "10000", disabled: !saved.editable });
            narrative.value = reason;
            narrative.addEventListener("input", () => { reason = narrative.value; });
            form.append(cards, field("Reason for change", narrative), node("div", { class: "inline-actions" },
                saved.editable && rows.length < 50 ? button("Add period", () => choose()) : null, review,
                button("Degree progress", () => ui.degreeAudit().studentProgress(student, pursuit, render, "Back to academic standing")),
                can("sis:standing:configure") ? button("Definitions", () => definitions(render)) : null,
                can("sis:audit:read") ? button("Standing history", () => history()) : null,
                back ? button("Back to programs", back, "btn-link") : null));
            form.addEventListener("submit", event => { event.preventDefault(); task(async () => {
                if (!form.reportValidity()) return;
                const draft = JSON.stringify([facts(rows), reason]);
                const result = await request(base + "review/", "POST", { rows: facts(rows), revision: saved.revision, reason });
                if (!body.isConnected) return;
                if (draft !== JSON.stringify([facts(rows), reason])) {
                    body.prepend(node("p", { class: "alert alert-info", role: "status" }, "Your draft changed. Review the current draft again.")); return;
                }
                confirm(result);
            }, review); });
            body.append(node("h3", { class: "h5" }, `${saved.program.code} · ${saved.program.title}`),
                node("p", {}, "Record the institution's determination and inclusive dates. Standing periods cannot overlap. This does not change registration, GPA, holds or graduation."),
                node("p", { class: "form-text" }, saved.editable ? "Keep earlier periods when recording the next standing. Removing a period records a correction; earlier decisions remain in protected history."
                    : "This is a historical pursuit. Its standing remains retained; new decisions belong to the current primary pursuit."), form);
            update();
        }
        const list = values => values.length ? node("ul", {}, ...values.map(row => node("li", { class: "text-break" },
            describe(row), row.policy_reference ? ` · ${row.policy_reference}` : ""))) : node("p", {}, "No recorded standing periods.");
        function confirm(data) {
            const body = openDrawer("Review academic standing", "CONFIRM");
            body.append(node("h3", { class: "h5" }, "Before"), list(data.before), node("h3", { class: "h5" }, "After"), list(data.after),
                node("p", { class: "text-break" }, data.reason), node("p", { class: "form-text" }, "Students and current assigned advisors see the accepted label and dates. Staff reasons and policy references remain private."),
                node("div", { class: "inline-actions" }, button("Confirm changes", async () => {
                    await request(base + "commit/", "POST", { rows: data.rows, revision: data.revision, reason: data.reason,
                        operation_id: data.operation_id, review_token: data.review_token });
                    if (body.isConnected) await open(student, pursuit, back);
                }, "btn-primary"), button("Keep editing", render, "btn-link")));
        }
        async function history(cursor = null, stack = []) {
            const body = openDrawer("Standing history", "RETAINED DECISIONS");
            const page = await request(base + "history/", "POST", { ...(cursor ? { cursor } : {}) });
            if (!body.isConnected) return;
            for (const item of page.items) body.append(node("article", { class: "surface p-3 mb-3" },
                node("h3", { class: "h6" }, `Decision ${item.version} · ${item.recorded_at}`), list(item.rows), node("p", { class: "text-break" }, item.reason)));
            if (!page.items.length) body.append(node("p", {}, "No standing decisions have been recorded."));
            body.append(node("div", { class: "inline-actions" },
                ...(stack.length ? [button("Previous history", () => history(stack.at(-1), stack.slice(0, -1)))] : []),
                ...(page.next_cursor ? [button("More history", () => history(page.next_cursor, [...stack, cursor]))] : []),
                button("Back to standing", render, "btn-link")));
        }
        render();
    }
    return { open, definitions };
};
