/* Registrar advising uses one pursuit-scoped, reviewed assignment list. Drafts remain in memory. */
window.createWorkspaceAdvising = function (ui) {
    "use strict";
    const { node, request, task, openDrawer } = ui;
    const button = (label, run, style = "btn-outline-primary") => node("button", {
        type: "button", class: `btn ${style}`, onclick: event => task(run, event.currentTarget),
    }, label);
    const facts = rows => rows.map(row => ({ id: row.id, advisor_person_id: row.advisor_person_id,
        role: row.role, starts_on: row.starts_on, ends_on: row.ends_on || null }));
    const describe = row => `${row.name || "Advisor"} · ${row.role === "primary" ? "Primary" : "Additional"} · ${row.starts_on} through ${row.ends_on || "open"}`;
    async function open(student, pursuit, back) {
        const body = openDrawer("Academic advisors", "STUDENT PROGRAM");
        const saved = await request(`/sis/students/${student}/programs/${pursuit}/advisors/`);
        if (!body.isConnected) return;
        let rows = saved.rows.map(row => ({ ...row })), reason = "";
        const base = `/sis/students/${student}/programs/${pursuit}/advisors/`;
        function render(message = "") {
            const body = openDrawer("Academic advisors", "EDIT → REVIEW → CONFIRM");
            const form = node("form");
            const live = node("p", { class: "form-text", role: "status" }, message);
            const review = node("button", { type: "submit", class: "btn btn-primary", disabled: !saved.editable }, "Review changes");
            const update = () => { review.disabled = !saved.editable || JSON.stringify(facts(rows)) === JSON.stringify(facts(saved.rows)); };
            const cards = node("div");
            for (const row of rows) {
                const summary = node("summary", { class: "fw-semibold text-break" }, describe(row));
                const id = `advisor-${row.id}`;
                const role = node("select", { id: `${id}-role`, class: "form-select", disabled: !saved.editable },
                    node("option", { value: "primary" }, "Primary advisor"), node("option", { value: "additional" }, "Additional advisor"));
                role.value = row.role;
                const start = node("input", { id: `${id}-start`, type: "date", class: "form-control", required: true,
                    value: row.starts_on, disabled: !saved.editable });
                const end = node("input", { id: `${id}-end`, type: "date", class: "form-control",
                    value: row.ends_on || "", min: row.starts_on, disabled: !saved.editable });
                const changed = () => {
                    row.role = role.value; row.starts_on = start.value; row.ends_on = end.value || null;
                    end.min = start.value; summary.textContent = describe(row); update();
                };
                [role, start, end].forEach(input => input.addEventListener("input", changed));
                const field = (label, input) => node("div", { class: "mb-3" }, node("label", { for: input.id, class: "form-label" }, label), input);
                cards.append(node("details", { class: "surface border p-3 mb-3", open: !saved.rows.some(old => old.id === row.id) },
                    summary, field("Advisor type", role), field("Starts on", start), field("Ends on", end),
                    saved.editable ? button("Remove assignment", () => { rows = rows.filter(item => item !== row); render(); }, "btn-outline-danger btn-sm") : null));
            }
            if (!rows.length) cards.append(node("p", { class: "alert alert-info", role: "status" }, "No advisor assignments are recorded for this pursuit."));
            const reasonInput = node("textarea", { id: "advisor-reason", class: "form-control", rows: "3", required: true,
                maxlength: "10000", disabled: !saved.editable });
            reasonInput.value = reason;
            reasonInput.addEventListener("input", () => { reason = reasonInput.value; });
            form.append(cards, node("label", { for: reasonInput.id, class: "form-label" }, "Reason for change"), reasonInput,
                node("div", { class: "inline-actions mt-3" },
                    saved.editable && rows.length < 50 ? button("Add advisor", () => choose()) : null, review,
                    ui.can("sis:audit:read") ? button("Assignment history", () => history()) : null,
                    ['sis:history:verify', 'sis:audit:read', 'sis:student:read', 'sis:advising:manage'].every(ui.can)
                        ? button(ui.historyCheckLabels.check_advisors, () =>
                            ui.historyChecks().open('advisors', pursuit, () => render())) : null,
                    back ? button("Back to programs", back, "btn-link") : null), live);
            form.addEventListener("submit", event => {
                event.preventDefault();
                task(async () => {
                    if (!form.reportValidity()) return;
                    const submitted = JSON.stringify([facts(rows), reason]);
                    const result = await request(base + "review/", "POST", { rows: facts(rows), reason, revision: saved.revision });
                    if (!body.isConnected) return;
                    if (submitted !== JSON.stringify([facts(rows), reason])) {
                        render("Your draft changed while the review loaded. Review the current draft again."); return;
                    }
                    confirm(result);
                }, review);
            });
            body.append(node("h3", { class: "h5" }, `${saved.program.code} · ${saved.program.title}`),
                node("p", {}, "Choose one primary advisor at a time, with additional advisors where needed. Dates are inclusive. Active assigned advisors can read this current pursuit's program and degree progress."),
                node("p", { class: "form-text" }, saved.editable
                    ? "End the previous primary assignment before the new one starts. Removing a saved row records a correction; earlier facts stay in history."
                    : "This is a historical pursuit. Its recorded assignments remain available; new advising changes belong to the current primary pursuit."), form);
            update();
        }
        async function choose(search = "", after = null, stack = []) {
            const body = openDrawer("Choose an advisor", "FACULTY & STAFF");
            const data = await request("/sis/advisors/candidates/", "POST", { search, ...(after ? { after } : {}) });
            if (!body.isConnected) return;
            const input = node("input", { type: "search", id: "advisor-search", class: "form-control", maxlength: "80", value: search });
            const form = node("form", { class: "mb-3" }, node("label", { for: input.id, class: "form-label" }, "Find a faculty or staff member"), input,
                node("button", { type: "submit", class: "btn btn-primary mt-2" }, "Search"));
            form.addEventListener("submit", event => { event.preventDefault(); task(() => choose(input.value)); });
            body.append(form);
            for (const person of data.items) body.append(node("article", { class: "surface p-3 mb-2" },
                node("h3", { class: "h6" }, `${person.given_name} ${person.family_name}`), button("Choose advisor", async () => {
                    const generated = await request("/sis/ui/operation-id/");
                    if (!body.isConnected) return;
                    rows.push({ id: generated.id, advisor_person_id: person.id, name: `${person.given_name} ${person.family_name}`,
                        role: rows.some(row => row.role === "primary" && !row.ends_on) ? "additional" : "primary",
                        starts_on: "", ends_on: null });
                    render();
                })));
            if (!data.items.length) body.append(node("p", { role: "status" }, "No eligible advisors match this search."));
            body.append(node("div", { class: "inline-actions" },
                ...(stack.length ? [button("Previous advisors", () => choose(search, stack.at(-1), stack.slice(0, -1)))] : []),
                ...(data.next_after ? [button("More advisors", () => choose(search, data.next_after, [...stack, after]))] : []),
                button("Back to assignments", render, "btn-link")));
        }
        function confirm(data) {
            const body = openDrawer("Review advisor changes", "CONFIRM");
            const list = values => node("ul", {}, ...values.map(row => node("li", {}, describe(row))));
            body.append(node("h3", { class: "h5" }, "Before"), data.before.length ? list(data.before) : node("p", {}, "No recorded assignments."),
                node("h3", { class: "h5" }, "After"), data.after.length ? list(data.after) : node("p", {}, "Clear the current assignment list."),
                node("p", { class: "text-break" }, data.reason), node("p", { class: "form-text" }, "Only current dated assignments under the student's current primary pursuit provide advising access. Earlier academic work and registrar editing rights remain separate."),
                node("div", { class: "inline-actions" }, button("Confirm changes", async () => {
                    await request(base + "commit/", "POST", { rows: data.rows, reason: data.reason, revision: data.revision,
                        operation_id: data.operation_id, review_token: data.review_token });
                    if (body.isConnected) await open(student, pursuit, back);
                }, "btn-primary"), button("Keep editing", render, "btn-link")));
        }
        async function history(cursor = null, stack = []) {
            const body = openDrawer("Advisor assignment history", "RETAINED DECISIONS");
            const data = await request(base + "history/", "POST", { ...(cursor ? { cursor } : {}) });
            if (!body.isConnected) return;
            for (const item of data.items) {
                const assignments = item.rows.map(row => node("li", {}, describe(row)));
                body.append(node("article", { class: "surface p-3 mb-3" },
                    node("h3", { class: "h6" }, `Decision ${item.version} · ${item.recorded_at}`),
                    node("p", { class: "text-break" }, item.reason), node("ul", {}, ...assignments)));
            }
            if (!data.items.length) body.append(node("p", {}, "No advisor history has been recorded for this pursuit."));
            body.append(node("div", { class: "inline-actions" },
                ...(stack.length ? [button("Previous history", () => history(stack.at(-1), stack.slice(0, -1)))] : []),
                ...(data.next_cursor ? [button("More history", () => history(data.next_cursor, [...stack, cursor]))] : []),
                button("Back to assignments", render, "btn-link")));
        }
        render();
    }
    function checkins(student, pursuit, back) {
        const body = openDrawer("Advising check-ins", "STUDENT PROGRAM");
        if (back) body.append(button("Back to programs", back, "btn-link"));
        window.createAdvisingCheckIns({ node, request }).mount(body,
            `/sis/students/${student}/programs/${pursuit}/checkins/`);
    }
    return { open, checkins };
};
