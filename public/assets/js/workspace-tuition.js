window.createWorkspaceTuition = function (ui) {
    "use strict";
    const { node, request, task, openDrawer, buildForm, formData, can, openRecord, labels: L } = ui;
    const controls = (...c) => node("div", { class: "d-flex flex-wrap gap-2 mb-3" }, ...c);
    const button = (label, run, icon = "arrow-right", primary = false) => node("button", { type: "button",
        class: `btn ${primary ? "btn-primary" : "btn-outline-primary"}`, onclick: e => task(run, e.currentTarget) },
    node("i", { class: `bi bi-${icon} me-2`, "aria-hidden": "true" }), label);
    const card = (title, ...c) => node("section", { class: "card bg-white border mb-3" },
        node("div", { class: "card-body text-break" }, node("h3", { class: "h6" }, title), ...c));
    const money = (v, currency) => new Intl.NumberFormat(undefined, { style: "currency", currency }).format(v / scale(currency));
    const scale = currency => 10 ** new Intl.NumberFormat(undefined, { style: "currency", currency }).resolvedOptions().maximumFractionDigits;
    const setup = () => can("sis:finance:post") && can("sis:finance:ledger");
    const text = value => node("p", { class: "form-text" }, value);
    async function call(body, path, method = "GET", data) {
        try { return await request(path, method, data); }
        catch (error) {
            if ([401, 403].includes(error.status) && body.isConnected) body.replaceChildren(
                node("p", { class: "alert alert-warning", role: "status" }, L.denied));
            throw error;
        }
    }
    function reviewForm(body, form, send, describe, done) {
        for (const field of form.querySelectorAll("textarea[name=reason]")) field.maxLength = 2000;
        const preview = node("div", { "aria-live": "polite" });
        const submit = node("button", { type: "submit", class: "btn btn-primary" }, L.review);
        form.append(submit); body.append(form, preview);
        let revision = 0, proof = null;
        const changed = () => { revision++; proof = null; preview.replaceChildren(); };
        form.addEventListener("input", changed); form.addEventListener("change", changed);
        form.addEventListener("submit", e => {
            e.preventDefault(); if (!form.reportValidity()) return;
            task(async () => {
                const expected = revision;
                const result = await send();
                if (!body.isConnected || expected !== revision) return;
                proof = result.review_token;
                const accept = button(L.confirm, async () => {
                    if (!proof) return;
                    const value = proof;
                    try {
                        const saved = await call(body, "/sis/tuition/confirm/", "POST", { review_token: value });
                        if (body.isConnected) { ui.notify?.(L.saved); await done(saved); }
                    } catch (error) { proof = null; preview.replaceChildren(); throw error; }
                }, "check2-circle", true);
                preview.replaceChildren(card(L.review_title, text(L.review_help), ...describe(result),
                    node("p", {}, result.reason), accept));
                accept.focus();
            }, submit);
        });
    }
    function listFacts(rows) {
        const list = node("dl", { class: "detail-list" });
        for (const [name, value] of rows) list.append(node("dt", {}, name), node("dd", {}, String(value)));
        return list;
    }
    async function open(pending = true, after = null) {
        const body = openDrawer(L.title); body.append(text(L.intro));
        const nav = controls(button(pending ? L.all : L.pending, () => open(!pending), "list-ul"));
        if (setup()) nav.append(button(L.schedules, () => schedules(() => open(pending, after)), "collection"),
            button(L.assignment, () => assignment(() => open(pending, after)), "diagram-3"));
        body.append(nav);
        const result = await call(body, `/sis/tuition/bills/?pending=${pending ? "true" : "false"}${after ? `&after=${encodeURIComponent(after)}` : ""}`);
        if (!body.isConnected) return;
        for (const r of result.items) body.append(card(`${r.student_label} · ${r.term_label}`,
            text(r.schedule_name), node("p", {}, `${L.pending_total}: ${money(r.pending_minor, r.currency)}`),
            button(L.view, () => bill(r.id, () => open(pending, after)), "receipt")));
        if (!result.items.length) body.append(text(L.empty));
        if (result.next_after) body.append(button(L.more, () => open(pending, result.next_after), "chevron-right"));
        if (after) body.append(button(L.back, () => open(pending), "arrow-left"));
    }
    async function schedules(back, after = null) {
        const body = openDrawer(L.schedules); body.append(controls(button(L.back, back, "arrow-left"),
            button(L.new_schedule, () => schedule(null, () => schedules(back, after)), "plus-circle")));
        const result = await call(body, `/sis/tuition/schedules/${after ? `?after=${encodeURIComponent(after)}` : ""}`);
        if (!body.isConnected) return;
        for (const r of result.items) body.append(card(r.definition.name, text(r.definition.currency),
            button(L.edit, () => schedule(r, () => schedules(back, after)), "pencil")));
        if (!result.items.length) body.append(text(L.empty));
        if (result.next_after) body.append(button(L.more, () => schedules(back, result.next_after), "chevron-right"));
    }
    async function schedule(saved, back) {
        const body = openDrawer(saved ? L.edit : L.new_schedule); body.append(button(L.back, back, "arrow-left"));
        const d = saved?.definition, fields = [["name", L.name], ["currency", L.currency, "currency"],
            ["income", L.income, "lookup", "ledger"], ["reason", L.reason, "textarea"]];
        const form = await buildForm(fields, { name: d?.name || "", currency: d?.currency || "USD", income: d?.income.id });
        if (!body.isConnected) return;
        const rows = [], host = node("div"); let serial = 0;
        form.append(node("h3", { class: "h5" }, L.brackets), text(L.bracket_help), host);
        async function add(b = null) {
            if (rows.length >= 12) return;
            const n = ++serial;
            const keys = ["from_milli", "through_milli", "flat_minor", "rate_minor", "rate_from_milli"];
            const rowFields = keys.map(key => [`bracket_${n}_${key}`, L[key], key.endsWith("milli") ? "credits" : "money"]);
            const preset = {}, currency = form.elements.currency.value;
            for (const key of keys) preset[`bracket_${n}_${key}`] = key.endsWith("milli")
                ? ((b?.[key] ?? (key === "through_milli" ? 999999 : 0)) / 1000).toFixed(3)
                : ((b?.[key] ?? 0) / scale(currency)).toFixed(Math.log10(scale(currency)));
            const built = await buildForm(rowFields, preset);
            if (!body.isConnected) return;
            const legend = node("legend", { class: "h6" }, `${L.bracket} ${rows.length + 1}`);
            const box = node("fieldset", { class: "record-form-section bg-white border rounded p-3 mb-3" }, legend,
                ...built.children);
            for (const input of box.querySelectorAll("input[type=number]")) {
                input.max = input.name.endsWith("milli") ? "999.999" : String(2000000000 / scale(currency));
                if (!input.name.endsWith("milli")) input.min = "0";
            }
            const row = { box, fields: rowFields, keys, legend };
            const renumber = () => rows.forEach((r, i) => { r.legend.textContent = `${L.bracket} ${i + 1}`; });
            const move = step => { const i = rows.indexOf(row), j = i + step; if (j < 0 || j >= rows.length) return;
                [rows[i], rows[j]] = [rows[j], rows[i]]; host.replaceChildren(...rows.map(r => r.box)); renumber();
                form.dispatchEvent(new Event("change")); };
            box.append(controls(button(L.up, () => move(-1), "arrow-up"), button(L.down, () => move(1), "arrow-down"),
                button(L.remove_bracket, () => { rows.splice(rows.indexOf(row), 1); box.remove(); renumber();
                    form.dispatchEvent(new Event("change")); }, "trash")));
            rows.push(row); host.append(box); form.dispatchEvent(new Event("change"));
        }
        for (const b of d?.brackets || [null]) await add(b);
        form.append(controls(button(L.add_bracket, () => add(), "plus-circle")));
        reviewForm(body, form, () => {
            const value = formData(form, fields);
            return call(body, "/sis/tuition/schedule/review/", "POST", { ...value, id: saved?.id || null,
                version: saved?.version || 0, brackets: rows.map(r => {
                    const parsed = formData(form, r.fields); return Object.fromEntries(r.keys.map((key, i) => [key, parsed[r.fields[i][0]]]));
                }) });
        }, r => [listFacts([[L.name, r.definition.name], [L.currency, r.definition.currency],
            [L.income, `${r.definition.income.code} · ${r.definition.income.title}`]]), ...r.definition.brackets.map((b, i) =>
            card(`${L.bracket} ${i + 1}`, listFacts([[L.from_milli, b.from_milli / 1000], [L.through_milli, b.through_milli / 1000],
                [L.flat_minor, money(b.flat_minor, r.definition.currency)], [L.rate_minor, money(b.rate_minor, r.definition.currency)],
                [L.rate_from_milli, b.rate_from_milli / 1000]])))], back);
    }
    async function assignment(back, preset = {}) {
        const body = openDrawer(L.assignment); body.append(button(L.back, back, "arrow-left"), text(L.scope_help));
        const fields = [["scope", L.scope, "choice", ["student", "program", "term"].map(value => ({ value, label: L[value] }))],
            ["reason", L.reason, "textarea"]];
        const form = await buildForm(fields, { scope: preset.scope || "term" });
        if (!body.isConnected) return;
        const targetHost = node("div"), scheduleSelect = node("select", { id: "tuition-assigned-schedule", class: "form-select" },
            node("option", { value: "" }, L.fallback));
        form.append(targetHost, node("div", { class: "mb-3" }, node("label", { for: scheduleSelect.id, class: "form-label" }, L.schedule), scheduleSelect));
        const status = node("p", { class: "form-text", role: "status" }); form.append(status);
        let current = null, sequence = 0, target = null, next = null;
        async function loadSchedules(after = null) {
            const page = await call(body, `/sis/tuition/schedules/${after ? `?after=${encodeURIComponent(after)}` : ""}`);
            if (!body.isConnected) return;
            for (const r of page.items) if (![...scheduleSelect.options].some(o => o.value === r.id)) scheduleSelect.append(
                node("option", { value: r.id }, `${r.definition.name} · ${r.definition.currency}`));
            next = page.next_after; more.hidden = !next;
        }
        const more = button(L.more, () => loadSchedules(next), "chevron-right"); form.append(more);
        async function read() {
            const seq = ++sequence; current = null; scheduleSelect.disabled = true; status.textContent = L.loading;
            if (!target?.value) { status.textContent = ""; return; }
            const scope = form.elements.scope.value, value = target.value;
            const result = await call(body, `/sis/tuition/assignment/?${new URLSearchParams({ scope, scope_id: value })}`);
            if (!body.isConnected || seq !== sequence) return;
            const selected = result.record?.definition.schedule_id;
            if (selected && ![...scheduleSelect.options].some(o => o.value === selected)) {
                const s = await call(body, `/sis/tuition/schedules/${encodeURIComponent(selected)}/`);
                if (!body.isConnected || seq !== sequence) return;
                scheduleSelect.append(node("option", { value: s.id }, `${s.definition.name} · ${s.definition.currency}`));
            }
            current = result; scheduleSelect.value = selected || ""; scheduleSelect.disabled = false;
            status.textContent = L.current;
        }
        async function targetField() {
            ++sequence; current = null; targetHost.replaceChildren();
            const scope = form.elements.scope.value;
            const built = await buildForm([["scope_id", L.target, "lookup", { student: "students", program: "programs", term: "terms" }[scope]]],
                { scope_id: preset.scope === scope ? preset.scope_id : "" });
            if (!body.isConnected || form.elements.scope.value !== scope) return;
            targetHost.append(...built.children); target = form.elements.scope_id; target.addEventListener("change", () => task(read));
            if (target.value) await read();
        }
        form.elements.scope.addEventListener("change", () => task(targetField));
        await loadSchedules(); await targetField();
        reviewForm(body, form, () => {
            if (!current) throw new Error(L.loading);
            return call(body, "/sis/tuition/assignment/review/", "POST", { scope: current.scope, scope_id: current.scope_id,
                schedule_id: scheduleSelect.value || null, version: current.record?.version || 0, reason: form.elements.reason.value });
        }, () => [listFacts([[L.scope, L[current.scope]], [L.target, current.label],
            [L.schedule, scheduleSelect.selectedOptions[0].textContent]])], back);
    }
    async function bill(id, back = () => open(), after = null) {
        const body = openDrawer(L.title); body.append(button(L.back, back, "arrow-left"));
        const data = await call(body, `/sis/tuition/bills/${encodeURIComponent(id)}/${after ? `?invoice_after=${encodeURIComponent(after)}` : ""}`);
        if (!body.isConnected) return;
        body.append(card(`${data.student_label} · ${data.term_label}`, text(data.schedule_name),
            listFacts([[L.credits, data.credits_milli / 1000], [L.pending_total, money(data.pending_minor, data.currency)]])), text(L.pending_notice));
        if (data.adjustment_minor > 0) body.append(node("p", { class: "alert alert-warning", role: "status" }, L.adjustment));
        const again = () => bill(id, back, after);
        if (data.lines.length && can("sis:finance:post")) {
            body.append(button(L.issue, () => invoiceForm(data, again), "receipt", true));
        }
        if (data.can_reprice && setup()) body.append(button(L.reprice, async () => {
            const next = openDrawer(L.reprice); next.append(button(L.back_bill, again, "arrow-left"), text(L.reprice_help));
            const fields = [["reason", L.reason, "textarea"]], form = await buildForm(fields);
            if (!next.isConnected) return;
            reviewForm(next, form, () => call(next, `/sis/tuition/bills/${id}/reprice/`, "POST",
                { version: data.version, reason: form.elements.reason.value }), r => [text(r.definition.snapshot.definition.name)], again);
        }, "arrow-clockwise"));
        for (const line of data.lines) body.append(card(line.name, node("p", {}, money(line.minor, data.currency))));
        body.append(node("h3", { class: "h5" }, L.invoices));
        for (const inv of data.invoices) body.append(card(inv.reference, text(`${inv.issued_at.slice(0, 10)} · ${money(inv.total_minor, data.currency)}`),
            button(L.invoice, () => invoice(inv.id, again), "file-earmark-text")));
        if (data.next_invoice_after) body.append(button(L.more, () => bill(id, back, data.next_invoice_after), "chevron-right"));
    }
    async function invoiceForm(data, back) {
        const body = openDrawer(L.issue); body.append(button(L.back_bill, back, "arrow-left"), text(L.invoice_help));
        const fields = [["due_on", L.due_on, "date"], ["reason", L.reason, "textarea"]];
        const form = await buildForm(fields, { due_on: data.default_due_on });
        if (!body.isConnected) return;
        form.dataset.currency = data.currency;
        const rows = [];
        for (const [i, line] of data.lines.entries()) {
            const key = `invoice_line_${i}`, descriptor = [[key, L.amount, "money"]];
            const inputForm = await buildForm(descriptor, { [key]: (line.minor / scale(data.currency)).toFixed(Math.log10(scale(data.currency))) });
            if (!body.isConnected) return;
            const box = card(line.name, text(`${L.quoted}: ${money(line.quoted_minor, data.currency)}`), ...inputForm.children);
            form.append(box); const input = form.elements[key]; input.min = "0"; input.step = String(1 / scale(data.currency));
            input.max = String(2000000000 / scale(data.currency));
            box.firstElementChild.append(controls(button(L.exclude, () => { input.value = "0"; form.dispatchEvent(new Event("input")); }, "x-circle"),
                button(L.restore, () => { input.value = String(line.minor / scale(data.currency)); form.dispatchEvent(new Event("input")); }, "arrow-counterclockwise")));
            rows.push({ key, descriptor, line });
        }
        reviewForm(body, form, () => call(body, `/sis/tuition/bills/${data.id}/review/`, "POST", {
            version: data.version, due_on: form.elements.due_on.value, reason: form.elements.reason.value,
            lines: rows.map(r => ({ key: r.line.key, minor: formData(form, r.descriptor)[r.key] })) }), r => [
                listFacts([[L.reference, r.definition.reference], [L.due_on, r.definition.due_on]]),
                ...r.definition.lines.map(l => card(l.name, listFacts([[L.quoted, money(l.quoted_minor, data.currency)],
                    [L.amount, money(l.minor, data.currency)]])))], result => invoice(result.id, back));
    }
    async function invoice(id, back) {
        const body = openDrawer(L.invoice); body.append(button(L.back_bill, back, "arrow-left"));
        const r = await call(body, `/sis/tuition/invoices/${encodeURIComponent(id)}/`);
        if (!body.isConnected) return;
        body.append(listFacts([[L.reference, r.reference], [L.student, r.recipient.name], [L.term, r.term_label],
            [L.issued, r.issued_at], [L.due_on, r.due_on], [L.total, money(r.total_minor, r.currency)]]));
        for (const line of r.lines) body.append(card(line.name, node("p", {}, money(line.minor, r.currency))));
        const links = controls(node("a", { class: "btn btn-outline-primary", href: `/sis/tuition/invoices/${encodeURIComponent(id)}/print/`,
            target: "_blank", rel: "noopener" }, node("i", { class: "bi bi-printer me-2", "aria-hidden": "true" }), L.print));
        if (r.account_id) links.append(button(L.open_account, () => openRecord("accounts", { id: r.account_id },
            { backLabel: L.back_invoice, back: () => invoice(id, back) }), "wallet2"));
        body.append(links);
    }
    return { open, assignment };
};
