window.createWorkspaceStudentLedger = function (ui) {
    "use strict";
    const { node, request, task, can, table, buildForm, formData, openDrawer, openRecord, actionMenu } = ui;
    const L = ui.labels;
    const sourceKeys = ["manual_charge", "section_fee", "term_tuition", "term_fee", "credit_tuition", "credit_fee"];
    const methodKeys = ["cash", "check", "card", "bank_transfer", "wire_transfer", "money_order", "paypal", "loan", "scholarship", "grant", "other"];
    const button = (label, run, icon = "arrow-right", primary = false) => node("button", {
        type: "button", class: `btn ${primary ? "btn-primary" : "btn-outline-primary"}`,
        onclick: (event) => task(run, event.currentTarget),
    }, node("i", { class: `bi bi-${icon} me-2`, "aria-hidden": "true" }), label);
    const card = (title, ...content) => node("section", { class: "card border bg-white shadow-sm mb-3" },
        node("div", { class: "card-body" }, node("h3", { class: "h6 mb-3" }, title), ...content));
    const controls = (...children) => node("div", { class: "d-flex flex-wrap gap-2" }, ...children);
    const text = (value) => node("p", { class: "form-text" }, value);
    async function call(body, path, method = "GET", data) {
        try { return await request(path, method, data); }
        catch (error) {
            if ([401, 403].includes(error.status) && body.isConnected) body.replaceChildren(
                node("p", { class: "alert alert-warning", role: "status" }, L.denied));
            throw error;
        }
    }
    function amount(value, currency) {
        const formatter = new Intl.NumberFormat(undefined, { style: "currency", currency });
        const places = formatter.resolvedOptions().maximumFractionDigits;
        const minor = BigInt(value), absolute = minor < 0n ? -minor : minor, divisor = 10n ** BigInt(places);
        const whole = new Intl.NumberFormat().format(absolute / divisor);
        const decimal = new Intl.NumberFormat().formatToParts(1.1).find((p) => p.type === "decimal")?.value || ".";
        const fraction = places ? decimal + String(absolute % divisor).padStart(places, "0") : "";
        return `${currency} ${minor < 0n ? "−" : ""}${whole}${fraction}`;
    }
    function reviewPanel(body, form, fields, endpoint, values, after) {
        const preview = node("div", { "aria-live": "polite" });
        const submit = node("button", { type: "submit", class: "btn btn-primary" }, L.review);
        form.append(submit);
        body.append(form, preview);
        let proof = null, revision = 0;
        const invalidate = () => { revision += 1; proof = null; preview.replaceChildren(); };
        form.addEventListener("input", invalidate);
        form.addEventListener("change", invalidate);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            task(async () => {
                const requestedRevision = revision;
                const draft = values(formData(form, fields));
                const reviewed = await call(body, endpoint, "POST", draft);
                if (!body.isConnected || revision !== requestedRevision) return;
                proof = reviewed.review_token;
                const details = node("dl", { class: "detail-list" });
                const d = reviewed.definition;
                for (const key of ["receivable", "charge", "payment", "refund"]) {
                    if (d[key]) details.append(node("dt", {}, L[key]), node("dd", {}, `${d[key].code} · ${d[key].title}`));
                }
                for (const [field, prefix] of [["charge_sources", "source"], ["payment_methods", "method"]]) {
                    for (const [key, account] of Object.entries(d[field] || {})) details.append(
                        node("dt", {}, L[`${prefix}_${key}`]), node("dd", {}, `${account.code} · ${account.title}`));
                }
                for (const [key, label] of [["label", L.label], ["starts_on", L.start], ["ends_on", L.end], ["timezone", L.timezone]]) {
                    if (d[key]) details.append(node("dt", {}, label), node("dd", {}, d[key]));
                }
                details.append(node("dt", {}, L.reason), node("dd", {}, draft.reason));
                const confirm = button(L.confirm, async () => {
                    if (!proof) return;
                    try {
                        await call(body, "/sis/accounting/confirm/", "POST", { review_token: proof });
                        if (body.isConnected) { ui.notify(L.saved); await after(); }
                    } catch (error) {
                        proof = null;
                        preview.replaceChildren();
                        throw error;
                    }
                }, "check2-circle", true);
                preview.replaceChildren(card(L.review_title, text(L.review_help), details, confirm));
                confirm.focus();
            }, submit);
        });
    }
    async function mapping(saved, currency, after) {
        const body = openDrawer(L.mapping, L.kicker);
        body.append(button(L.back, after, "arrow-left"), text(L.mapping_help));
        const d = saved.book?.definition;
        const fields = [["timezone", L.timezone], ["receivable_code", L.ar_code], ["receivable_title", L.ar_title],
            ["charge", L.charge, "lookup", "ledger"], ["payment", L.payment, "lookup", "ledger"],
            ["refund", L.refund, "lookup", "ledger"], ["reason", L.reason, "textarea"]];
        const preset = { timezone: d?.timezone || saved.default_timezone || "UTC", receivable_code: d?.receivable.code || `AR.${currency}`,
            receivable_title: d?.receivable.title || L.ar_default, charge: d?.charge.id, payment: d?.payment.id, refund: d?.refund.id };
        const form = await buildForm(fields, preset);
        if (!body.isConnected) return;
        const overrides = node("details", { class: "card border bg-white mb-3" },
            node("summary", { class: "card-header bg-white" }, L.overrides));
        const inner = node("div", { class: "card-body" }, text(L.overrides_help));
        overrides.append(inner);
        const extra = [];
        for (const [prefix, keys, values] of [["source", sourceKeys, d?.charge_sources], ["method", methodKeys, d?.payment_methods]]) {
            for (const key of keys) {
                const name = `${prefix}_${key}`, options = [[name, L[name], "lookup", "ledger", false]];
                const nested = await buildForm(options, { [name]: values?.[key]?.id });
                if (!body.isConnected) return;
                extra.push(options[0]);
                inner.append(...nested.children);
            }
        }
        form.append(overrides);
        reviewPanel(body, form, [...fields, ...extra], "/sis/accounting/mapping/review/", (value) => {
            const output = { currency, timezone: value.timezone, receivable_code: value.receivable_code,
                receivable_title: value.receivable_title, charge: value.charge, payment: value.payment,
                refund: value.refund, reason: value.reason, charge_sources: [], payment_methods: [] };
            for (const [prefix, keys, field] of [["source", sourceKeys, "charge_sources"], ["method", methodKeys, "payment_methods"]]) {
                for (const key of keys) if (value[`${prefix}_${key}`]) output[field].push({ key, account_id: value[`${prefix}_${key}`] });
            }
            return output;
        }, after);
    }
    async function createPeriod(currency, after) {
        const body = openDrawer(L.open_period, L.kicker);
        body.append(button(L.back, after, "arrow-left"), text(L.period_help));
        const fields = [["label", L.label], ["starts_on", L.start, "date"], ["ends_on", L.end, "date"], ["reason", L.reason, "textarea"]];
        const form = await buildForm(fields);
        if (!body.isConnected) return;
        reviewPanel(body, form, fields, "/sis/accounting/periods/review/", (value) => ({ ...value, currency }), after);
    }
    async function closing(period, scan, currency, after) {
        const body = openDrawer(period.definition.closed ? L.reopen : L.close, L.kicker);
        body.append(button(L.back, after, "arrow-left"), card(period.definition.label,
            text(`${period.definition.starts_on} – ${period.definition.ends_on}`), text(L.close_help)));
        const fields = [["reason", L.reason, "textarea"]], form = await buildForm(fields);
        if (!body.isConnected) return;
        reviewPanel(body, form, fields, `/sis/accounting/periods/${period.id}/review/`, (value) => ({ ...value,
            action: period.definition.closed ? "reopen" : "close", ...(scan ? { reconciliation: scan.continuation } : {}) }), after);
    }
    async function verification(period, currency, back, state = null, validate = false) {
        const body = openDrawer(L.verification, L.kicker);
        body.append(button(L.back, back, "arrow-left"), card(period.definition.label, text(L.scan_help)));
        const panel = node("div", { "aria-live": "polite" });
        body.append(panel);
        let current = state;
        async function load(token = null) {
            const data = await call(body, `/sis/accounting/periods/${period.id}/reconcile/`, "POST",
                token ? { continuation: token } : {});
            if (!body.isConnected) return;
            current = data;
            render();
        }
        function render() {
            panel.replaceChildren();
            panel.append(card(L.verification, text(L.progress.replace("{checked}", current.checked).replace("{total}", current.total)),
                text(`${L.net}: ${amount(current.ar_net_minor, currency)}`)));
            const rows = current.items.map((item) => [item.reference, item.posted_on, amount(item.ar_effect_minor, currency),
                button(L.view_journal, () => openRecord("journals", { id: item.journal_id, reference: item.reference,
                    posted_on: item.posted_on, currency }, { backLabel: L.back_scan,
                    back: () => verification(period, currency, back, current, true) }), "journal-text")]);
            if (rows.length) panel.append(node("div", { class: "table-responsive" }, table([L.journal, L.date, L.effect, L.actions], rows, L.verification)));
            else panel.append(text(current.total === 0 ? L.empty : L.verified));
            if (current.done) {
                panel.append(node("p", { class: "alert alert-success", role: "status" }, L.verified));
                if (!period.definition.closed && can("sis:finance:close")) panel.append(button(L.close,
                    () => closing(period, current, currency, back), "lock", true));
            } else panel.append(button(L.scan_next, () => load(current.continuation), "arrow-right", true));
            panel.append(button(L.scan_restart, () => verification(period, currency, back), "arrow-clockwise"));
        }
        if (current && validate) {
            await call(body, `/sis/accounting/periods/${period.id}/reconcile/`, "POST", { continuation: current.continuation });
            if (body.isConnected) render();
        } else await load();
    }
    async function open(currency = "USD", after = null) {
        const body = openDrawer(L.title, L.kicker);
        body.append(text(L.intro));
        const currencyFields = [["currency", L.currency, "currency"]], form = await buildForm(currencyFields, { currency });
        if (!body.isConnected) return;
        const refresh = node("button", { type: "submit", class: "btn btn-outline-primary" }, L.refresh);
        form.append(refresh);
        form.addEventListener("submit", (event) => { event.preventDefault(); if (form.reportValidity()) task(() =>
            open(formData(form, currencyFields).currency), refresh); });
        body.append(form);
        const saved = await call(body, `/sis/accounting/?currency=${encodeURIComponent(currency)}${after ? `&after=${encodeURIComponent(after)}` : ""}`);
        if (!body.isConnected) return;
        const back = () => open(currency, after);
        if (!saved.book) body.append(node("p", { class: "alert alert-info" }, L.inactive));
        const mappingCard = card(L.mapping, text(L.mapping_help));
        if (saved.book) {
            const list = node("dl", { class: "detail-list" });
            for (const key of ["receivable", "charge", "payment", "refund"]) {
                const value = saved.book.definition[key];
                list.append(node("dt", {}, L[key]), node("dd", {}, `${value.code} · ${value.title}`));
            }
            list.append(node("dt", {}, L.timezone), node("dd", {}, saved.book.definition.timezone));
            mappingCard.firstElementChild.append(list);
        }
        mappingCard.firstElementChild.append(button(L.configure, () => mapping(saved, currency, back), "diagram-3"));
        body.append(mappingCard);
        if (!saved.book) return;
        const periods = card(L.periods, text(L.period_help), button(L.open_period, () => createPeriod(currency, back), "calendar-plus"));
        for (const p of saved.periods) {
            const actions = controls();
            if (can("sis:finance:read")) actions.append(button(L.reconcile, () => verification(p, currency, back), "check2-square"));
            if (p.definition.closed && can("sis:finance:close") && can("sis:finance:read")) actions.append(actionMenu(L.more,
                [{ label: L.reopen, icon: "unlock", run: () => closing(p, null, currency, back) }], { context: p.definition.label }));
            periods.firstElementChild.append(card(p.definition.label,
                text(`${p.definition.starts_on} – ${p.definition.ends_on}`),
                node("p", {}, node("span", { class: `badge ${p.definition.closed ? "text-bg-secondary" : "text-bg-success"}` },
                    p.definition.closed ? L.closed : L.open)), actions));
        }
        if (!saved.periods.length) periods.firstElementChild.append(text(L.no_periods));
        if (saved.next_after) periods.firstElementChild.append(button(L.more_periods, () => open(currency, saved.next_after), "arrow-right"));
        if (after) periods.firstElementChild.append(button(L.back, () => open(currency), "arrow-left"));
        body.append(periods);
    }
    return { open };
};
