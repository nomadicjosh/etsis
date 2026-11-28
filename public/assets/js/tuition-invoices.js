window.createTuitionInvoices = function (ui) {
    "use strict";
    const { node, request, task, labels: L } = ui;
    const money = (v, currency) => {
        const format = new Intl.NumberFormat(undefined, { style: "currency", currency });
        return format.format(v / 10 ** format.resolvedOptions().maximumFractionDigits);
    };
    const button = (label, run, icon) => node("button", { type: "button", class: "btn btn-outline-primary",
        onclick: e => task(run, e.currentTarget) }, node("i", { class: `bi bi-${icon} me-2`, "aria-hidden": "true" }), label);
    const card = (title, ...children) => node("section", { class: "card bg-white border mb-3" },
        node("div", { class: "card-body text-break" }, node("h3", { class: "h6" }, title), ...children));
    async function load(body, path) {
        try { return await request(`/portal/api/tuition/${path}`); }
        catch (error) {
            if (body.isConnected) body.replaceChildren(node("p", { class: "alert alert-warning", role: "status" }, L.denied));
            return null;
        }
    }
    async function mount(body, after = null) {
        body.replaceChildren(node("h2", { class: "h5" }, L.title), node("p", { class: "form-text" }, L.pending_notice));
        const data = await load(body, `bills/${after ? `?after=${encodeURIComponent(after)}` : ""}`);
        if (!body.isConnected || !data) return;
        for (const bill of data.items) body.append(card(`${bill.student_label} · ${bill.term_label}`,
            node("p", {}, `${L.pending_total}: ${money(bill.pending_minor, bill.currency)}`),
            button(L.view, () => term(bill.id, () => mount(body, after)), "receipt")));
        if (!data.items.length) body.append(node("p", {}, L.empty));
        if (data.next_after) body.append(button(L.more, () => mount(body, data.next_after), "chevron-right"));
        if (after) body.append(button(L.back, () => mount(body), "arrow-left"));
    }
    async function term(id, back, after = null) {
        const body = ui.open(L.title); body.append(button(L.back, () => { ui.close?.(); return back(); }, "arrow-left"));
        const data = await load(body, `bills/${encodeURIComponent(id)}/${after ? `?invoice_after=${encodeURIComponent(after)}` : ""}`);
        if (!body.isConnected || !data) return;
        body.append(node("p", { class: "form-text" }, L.pending_notice), node("h3", { class: "h6" }, data.term_label));
        for (const line of data.lines) body.append(card(line.name, node("p", {}, money(line.minor, data.currency))));
        body.append(node("h3", { class: "h5" }, L.invoices));
        for (const row of data.invoices) body.append(card(row.reference,
            node("p", {}, `${row.issued_at.slice(0, 10)} · ${money(row.total_minor, data.currency)}`),
            button(L.invoice, () => invoice(row.id, () => term(id, back, after)), "file-earmark-text")));
        if (data.next_invoice_after) body.append(button(L.more, () => term(id, back, data.next_invoice_after), "chevron-right"));
    }
    async function invoice(id, back) {
        const body = ui.open(L.invoice); body.append(button(L.back_bill, back, "arrow-left"));
        const data = await load(body, `invoices/${encodeURIComponent(id)}/`);
        if (!body.isConnected || !data) return;
        const facts = node("dl", { class: "detail-list text-break" });
        for (const [label, value] of [[L.reference, data.reference], [L.student, data.recipient.name],
            [L.term, data.term_label], [L.issued, data.issued_at], [L.due_on, data.due_on],
            [L.total, money(data.total_minor, data.currency)]]) facts.append(node("dt", {}, label), node("dd", {}, value));
        body.append(facts);
        for (const line of data.lines) body.append(card(line.name, node("p", {}, money(line.minor, data.currency))));
        body.append(node("a", { class: "btn btn-outline-primary", href: `/portal/api/tuition/invoices/${encodeURIComponent(id)}/print/`,
            target: "_blank", rel: "noopener" }, node("i", { class: "bi bi-printer me-2", "aria-hidden": "true" }), L.print));
    }
    return { mount };
};
