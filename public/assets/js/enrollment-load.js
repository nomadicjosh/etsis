/* Shared, on-demand working review. Private results stay in the current page only. */
window.createEnrollmentLoadReview = function (ui) {
    "use strict";
    const { node, request, task } = ui;
    const credits = milli => (milli / 1000).toLocaleString(undefined, { maximumFractionDigits: 3 });
    let sequence = 0;
    return function mount(container, base) {
        const root = node("section", { class: "surface border rounded p-3 my-3", "aria-label": "Enrollment load" });
        container.append(root);
        let serial = 0;
        const alive = token => root.isConnected && token === serial;
        const button = (label, run) => node("button", { type: "button", class: "btn btn-outline-primary campus-button secondary",
            onclick: event => task(run, event.currentTarget) }, label);
        async function choose(search = "", after = null, stack = []) {
            const token = ++serial;
            const data = await request(`${base}terms/`, "POST", { search, after });
            if (!alive(token)) return;
            const id = `load-term-${++sequence}`;
            const input = node("input", { id, type: "search", maxlength: "80", class: "form-control", value: search });
            const form = node("form", { class: "mb-3" }, node("label", { for: id, class: "form-label" }, "Find a term"), input,
                node("button", { type: "submit", class: "btn btn-primary campus-button mt-2" }, "Search"));
            form.addEventListener("submit", event => { event.preventDefault(); task(() => choose(input.value)); });
            root.replaceChildren(node("h2", { class: "h5" }, "Enrollment load"), form);
            for (const term of data.items) root.append(node("div", { class: "mb-2" }, button(`${term.code} · ${term.starts_on}–${term.ends_on}`, () => review(term))));
            if (!data.items.length) root.append(node("p", { role: "status" }, "No terms match your search."));
            if (stack.length) root.append(button("Previous terms", () => choose(search, stack.at(-1), stack.slice(0, -1))));
            if (data.next_after) root.append(button("More terms", () => choose(search, data.next_after, [...stack, after])));
        }
        async function review(term) {
            const token = ++serial;
            const data = await request(`${base}review/`, "POST", { term_id: term.id });
            if (!alive(token)) return;
            const message = data.status === "limited" ? "This review needs a larger historical review by the registrar. No partial total is shown." :
                data.status === "needs_review" ? "Some registration records could not be verified. Ask the registrar to review them before relying on a total." :
                    data.status === "not_registered" ? "No active registrations in this term." : `${credits(data.credits_milli)} registered credits · ${data.classification?.label || "No load classification configured"}`;
            root.replaceChildren(node("h2", { class: "h5" }, `Enrollment load · ${data.term.code}`),
                node("p", { role: "status", class: "fw-semibold" }, message),
                node("p", { class: "form-text campus-muted" }, `${data.program.code} · ${data.program.title}. All active registrations in this term count, including registrations made before a program change.`),
                node("p", { class: "form-text campus-muted" }, "Working review of current registrations, not an official census or financial-aid determination. Credits come from the accepted registrations; later catalog and grade changes do not replace them."));
            if (data.policy?.source && data.policy.source !== "not_configured") root.append(node("p", { class: "form-text campus-muted" },
                data.policy.source === "program" ? "Using this program's load policy." : "Using the institutional load policy."));
            const list = node("ul", { class: "list-unstyled" });
            for (const item of data.items) list.append(node("li", { class: "border-bottom py-2" },
                `${item.course_code} · ${item.course_title} · ${item.credits_milli === null ? "Credits need review" : `${credits(item.credits_milli)} credits`}`));
            root.append(list, node("div", { class: "d-flex flex-wrap gap-2" }, button("Refresh review", () => review(term)), button("Change term", () => choose())));
        }
        root.append(node("h2", { class: "h5" }, "Enrollment load"), button("Review enrollment load", () => choose()));
        return root;
    };
};

window.createWorkspaceEnrollmentLoad = function (ui) {
    "use strict";
    const { node, request, task, openDrawer, can } = ui;
    const mount = window.createEnrollmentLoadReview(ui);
    const button = (label, run, style = "btn-outline-primary") => node("button", { type: "button", class: `btn ${style}`,
        onclick: event => task(run, event.currentTarget) }, label);
    const field = (label, input) => node("div", { class: "mb-3" }, node("label", { for: input.id, class: "form-label" }, label), input);
    const showBands = definition => definition === null ? "No separate credit bands" : definition.bands.map((b, i, all) =>
        `${b.label}: ${b.min_credits_milli / 1000}${all[i + 1] ? ` to under ${all[i + 1].min_credits_milli / 1000}` : " or more"} credits`).join("; ");
    async function programs(search = "", after = null, stack = []) {
        const body = openDrawer("Program load policies", "INSTITUTION SETUP");
        const page = await request("/sis/enrollment-load/policy/programs/", "POST", { search, after });
        if (!body.isConnected) return;
        const input = node("input", { id: "load-program-search", class: "form-control", type: "search", maxlength: "80", value: search });
        const form = node("form", {}, field("Find a program", input), node("button", { type: "submit", class: "btn btn-primary" }, "Search"));
        form.addEventListener("submit", event => { event.preventDefault(); task(() => programs(input.value)); });
        body.append(form);
        for (const item of page.items) body.append(node("div", { class: "my-2" }, button(`${item.code} · ${item.title}`, () => policy(item.id))));
        if (!page.items.length) body.append(node("p", { role: "status" }, "No programs match."));
        if (stack.length) body.append(button("Previous programs", () => programs(search, stack.at(-1), stack.slice(0, -1))));
        if (page.next_after) body.append(button("More programs", () => programs(search, page.next_after, [...stack, after])));
        body.append(button("Institution policy", () => policy()));
    }
    async function policy(program = null) {
        const waiting = openDrawer("Enrollment load policy", "INSTITUTION SETUP");
        const saved = await request("/sis/enrollment-load/policy/view/", "POST", { program_id: program });
        if (!waiting.isConnected) return;
        let enabled = saved.state.definition !== null;
        let rows = (saved.state.definition?.bands || saved.global.definition?.bands || [{ code: "", label: "", min_credits_milli: 0 }])
            .map(b => ({ code: b.code, label: b.label, minimum: String(b.min_credits_milli / 1000) }));
        let reason = "";
        function render() {
            const body = openDrawer("Enrollment load policy", saved.program ? `${saved.program.code} · ${saved.program.title}` : "INSTITUTION POLICY");
            const checkbox = node("input", { id: "load-enabled", type: "checkbox", class: "form-check-input", checked: enabled });
            const cards = node("div"), reasonInput = node("textarea", { id: "load-reason", class: "form-control", required: true, maxlength: "1000" }, reason);
            const form = node("form", {}, node("p", { class: "form-text" }, "Define your institution's credit bands. Start at zero; each next minimum ends the previous band. Published changes apply to working reviews and do not rewrite earlier academic decisions."),
                node("div", { class: "form-check mb-3" }, checkbox, node("label", { for: checkbox.id, class: "form-check-label" },
                    program ? "Use separate program bands" : "Classify enrollment load")), cards);
            if (program) form.append(node("p", { class: "form-text" }, `Otherwise inherit the institution policy: ${showBands(saved.global.definition)}.`));
            checkbox.addEventListener("change", () => { enabled = checkbox.checked; render(); });
            if (enabled) rows.forEach((row, i) => {
                const code = node("input", { id: `load-code-${i}`, class: "form-control", required: true, maxlength: "32", value: row.code });
                const label = node("input", { id: `load-label-${i}`, class: "form-control", required: true, maxlength: "120", value: row.label });
                const min = node("input", { id: `load-min-${i}`, class: "form-control", inputmode: "decimal", required: true, value: row.minimum });
                code.addEventListener("input", () => { row.code = code.value.toUpperCase().trim(); });
                label.addEventListener("input", () => { row.label = label.value; });
                min.addEventListener("input", () => { row.minimum = min.value; min.setCustomValidity(""); });
                cards.append(node("fieldset", { class: "surface border rounded p-3 mb-3" }, node("legend", { class: "h6" }, `Band ${i + 1}`),
                    field("Code", code), field("Name", label), field("Minimum credits", min),
                    button("Remove band", () => { rows.splice(i, 1); render(); }, "btn-outline-danger")));
            });
            if (enabled && rows.length < 12) form.append(button("Add band", () => { rows.push({ code: "", label: "", minimum: "" }); render(); }));
            reasonInput.addEventListener("input", () => { reason = reasonInput.value; });
            const submit = node("button", { type: "submit", class: "btn btn-primary" }, "Review policy");
            form.append(field("Reason for change", reasonInput), node("div", { class: "inline-actions" }, submit,
                button(program ? "Institution policy" : "Program policies", () => program ? policy() : programs()),
                can("sis:audit:read") ? button("Publication history", () => history(program, render)) : null));
            form.addEventListener("submit", event => { event.preventDefault(); task(async () => {
                if (!form.reportValidity()) return;
                const bands = (enabled ? rows : []).map((r, i) => {
                    if (!/^(?:0|[1-9]\d{0,3})(?:\.\d{1,3})?$/.test(r.minimum)) {
                        const input = form.querySelector(`#load-min-${i}`); input.setCustomValidity("Use credits with up to three decimal places."); input.reportValidity();
                        throw new Error("Review the credit minimums.");
                    }
                    const [whole, fraction = ""] = r.minimum.split(".");
                    return { code: r.code, label: r.label, min_credits_milli: Number(whole) * 1000 + Number(fraction.padEnd(3, "0")) };
                });
                const reviewed = await request("/sis/enrollment-load/policy/review/", "POST", { program_id: program, definition: enabled ? { bands } : null,
                    revision: saved.revision, reason });
                if (!body.isConnected) return;
                const confirmation = openDrawer("Review load policy", "REVIEW → PUBLISH");
                confirmation.append(node("h3", { class: "h6" }, "Current bands"), node("p", {}, showBands(reviewed.before)),
                    node("h3", { class: "h6" }, "Proposed bands"), node("p", {}, showBands(reviewed.after)),
                    node("p", {}, reviewed.after === null ? (program ? "Use the institution's policy." : "Stop assigning a load classification.") : "Use these credit bands for working reviews."),
                    node("p", {}, reviewed.reason), node("div", { class: "inline-actions" }, button("Publish policy", async () => {
                        await request("/sis/enrollment-load/policy/publish/", "POST", reviewed);
                        if (confirmation.isConnected) await policy(program);
                    }, "btn-primary"), button("Back to editing", render)));
            }, submit); });
            body.append(form);
        }
        render();
    }
    async function history(program, back, cursor = null) {
        const body = openDrawer("Load policy history", "PUBLISHED POLICIES");
        const page = await request("/sis/enrollment-load/policy/history/", "POST", { program_id: program, cursor });
        if (!body.isConnected) return;
        for (const row of page.items) body.append(node("article", { class: "surface border p-3 mb-3" },
            node("h3", { class: "h6" }, `Publication ${row.version}`), node("p", {}, row.published_at),
            node("p", {}, showBands(row.definition)), node("p", {}, row.reason)));
        if (!page.items.length) body.append(node("p", {}, "No published policy yet."));
        if (page.next_cursor) body.append(button("Earlier publications", () => history(program, back, page.next_cursor)));
        body.append(button("Back", back));
    }
    return { policy, open(student, pursuit) {
        const body = openDrawer("Enrollment load", "STUDENT PROGRAM");
        mount(body, `/sis/students/${student}/programs/${pursuit}/load/`);
    } };
};
