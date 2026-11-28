window.createPaymentActivity = function (ui) {
    "use strict";
    const { node, request, task, labels: L } = ui;
    const button = (label, run, icon) => node("button", { type: "button", class: "btn btn-outline-primary",
        onclick: (event) => task(run, event.currentTarget) }, node("i", { class: `bi bi-${icon} me-2`, "aria-hidden": "true" }), label);
    const money = (minor, currency) => new Intl.NumberFormat(undefined, { style: "currency", currency })
        .format(minor / 10 ** new Intl.NumberFormat(undefined, { style: "currency", currency }).resolvedOptions().maximumFractionDigits);
    async function open(kind, id, back = null, after = null) {
        const body = ui.open(L.title);
        if (back) body.append(button(L.back, back, "arrow-left"));
        body.append(node("p", { class: "form-text" }, L.intro));
        const path = `${ui.prefix}/payment-activity/${kind}/${encodeURIComponent(id)}/`;
        let data;
        try { data = await request(path + (after ? `?after=${encodeURIComponent(after)}` : "")); }
        catch (error) {
            if (body.isConnected) body.replaceChildren(node("p", { class: "alert alert-warning", role: "status" }, L.denied));
            throw error;
        }
        if (!body.isConnected) return;
        const again = () => open(kind, id, back, after);
        body.append(button(L.refresh, again, "arrow-clockwise"));
        if (data.held_minor > 0) body.append(node("p", { class: "alert alert-info mt-3", role: "status" }, L.held));
        for (const row of data.items) {
            const inner = node("div", { class: "card-body" }, node("h3", { class: "h6" }, L[row.state]));
            const list = node("dl", { class: "detail-list" });
            for (const [key, label] of [["authorized_minor", L.quoted], ["capture_minor", L.capture], ["received_minor", L.received_amount]]) {
                if (row[key] !== null) list.append(node("dt", {}, label), node("dd", {}, money(row[key], row.currency)));
            }
            inner.append(list);
            if (row.state === "review") inner.append(node("p", { class: "form-text" }, L.review_help));
            if (row.can_retry) inner.append(button(L.retry, async () => {
                await request(`${ui.prefix}/payment-activity/${encodeURIComponent(row.id)}/retry/`, "POST", {});
                if (body.isConnected) {
                    inner.append(node("p", { class: "alert alert-info mt-2", role: "status" }, L.queued));
                }
            }, "arrow-repeat"));
            body.append(node("section", { class: "card bg-white border mt-3" }, inner));
        }
        if (!data.items.length) body.append(node("p", { class: "mt-3" }, L.empty));
        if (data.next_after) body.append(button(L.next, () => open(kind, id, back, data.next_after), "chevron-right"));
    }
    return { open };
};
