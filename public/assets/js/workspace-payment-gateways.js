window.createWorkspacePaymentGateways = function (ui) {
    "use strict";
    const { node, request, task, openDrawer, notify, actionMenu } = ui, L = ui.labels;
    const purposeKeys = ["account", "application", "transcript"];
    const kindKeys = ["asset", "liability", "equity", "income", "expense"];
    let serial = 0;
    const surface = (title) => openDrawer(title, L.kicker, { record: null });
    const button = (label, run, icon = "arrow-right", primary = false) => node("button", {
        type: "button", class: `btn ${primary ? "btn-primary" : "btn-outline-primary"}`,
        onclick: (event) => task(run, event.currentTarget),
    }, node("i", { class: `bi bi-${icon} me-2`, "aria-hidden": "true" }), label);
    const text = (value) => node("p", { class: "form-text" }, value);
    const controls = (...children) => node("div", { class: "d-flex flex-wrap gap-2 mb-3" }, ...children);
    const card = (title, ...children) => node("section", { class: "card border bg-white shadow-sm mb-3" },
        node("div", { class: "card-body" }, node("h3", { class: "h6 mb-3 text-break" }, title), ...children));
    const values = (form) => Object.fromEntries(new FormData(form));
    const cleanSecrets = (form) => form.querySelectorAll('input[type="password"]').forEach((input) => { input.value = ""; });
    async function call(body, path, method = "GET", data) {
        try { return await request(path, method, data); }
        catch (error) {
            if ([401, 403].includes(error.status) && body.isConnected) {
                cleanSecrets(body);
                body.replaceChildren(node("p", { class: "alert alert-warning", role: "status" }, L.denied));
            }
            throw error;
        }
    }
    function field(form, name, label, value = "", type = "text", choices = null, required = true) {
        const id = `gateway-field-${++serial}`;
        const input = choices ? node("select", { id, name, class: "form-select", required },
            ...choices.map(([v, title]) => node("option", { value: v }, title)))
            : node("input", { id, name, type, class: "form-control", required, value,
                ...(type === "password" ? { autocomplete: "new-password", maxlength: 512 } : { maxlength: 191 }) });
        input.value = String(value ?? "");
        const wrap = node("div", { class: "mb-3" }, node("label", { for: id, class: "form-label" }, label), input);
        form.append(wrap);
        return input;
    }
    function reason(form, value = "") {
        const input = field(form, "reason", L.reason, value);
        input.minLength = 8; input.maxLength = 1000;
    }
    function details(preview) {
        const list = node("dl", { class: "detail-list text-break mb-0" });
        for (const key of ["name", "provider", "merchant", "live", "enabled", "purpose", "currency", "code", "title", "kind", "connection_id"]) {
            if (!(key in preview)) continue;
            let value = preview[key];
            if (key === "live") value = value ? L.live : L.test;
            else if (key === "enabled") value = value ? L.yes : L.no;
            else if (key === "purpose" || key === "kind") value = L[value];
            else if (value === null) value = L.unassigned;
            list.append(node("dt", {}, (key === "title" ? L.title_account : key === "live" ? L.mode : L[key]) || key), node("dd", {}, String(value)));
        }
        if (preview.asset) list.append(node("dt", {}, L.asset_account),
            node("dd", {}, `${preview.asset.code} · ${preview.asset.title}`));
        if (preview.selected_connection) list.append(node("dt", {}, L.connection),
            node("dd", {}, details(preview.selected_connection)));
        return list;
    }
    function reviewable(body, form, kind, makeValues, after) {
        const panel = node("div", { "aria-live": "polite" });
        let revision = 0, review = null;
        const reset = () => { revision += 1; review = null; panel.replaceChildren(); };
        form.addEventListener("input", reset); form.addEventListener("change", reset);
        const submit = node("button", { class: "btn btn-primary", type: "submit" },
            node("i", { class: "bi bi-eye me-2", "aria-hidden": "true" }), L.review);
        form.append(submit); body.append(form, panel);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            task(async () => {
                const at = ++revision, data = makeValues(values(form));
                review = null; panel.replaceChildren(); cleanSecrets(form);
                const result = await call(body, `/sis/payment-setup/review/${kind}/`, "POST", data);
                if (!body.isConnected || at !== revision) return;
                review = result;
                const confirm = button(L.confirm, async () => {
                    if (!review) return;
                    const sent = review;
                    try {
                        const saved = await call(body, "/sis/payment-setup/confirm/", "POST",
                            { review_id: sent.review_id, review_token: sent.review_token });
                        if (body.isConnected) { notify(L.saved); await after(saved); }
                    } catch (error) {
                        if ([400, 409, 422].includes(error.status)) { review = null; panel.replaceChildren(); }
                        throw error;
                    }
                }, "check2-circle", true);
                panel.replaceChildren(card(L.review_title, details(result.preview), text(result.reason), controls(confirm)));
                confirm.focus();
            }, submit);
        });
    }
    async function open(tab = "connections", currency = "USD", after = null, back = null) {
        const body = surface(L.title);
        if (back) body.append(button(L.back_record, back, "arrow-left"));
        body.append(text(L.intro), controls(...["connections", "routes", "assets"].map((name) =>
            button(L[name], () => open(name, currency, null, back), name === "assets" ? "bank" : "diagram-3", name === tab))));
        const current = () => open(tab, currency, after, back);
        if (tab === "assets") {
            const filter = node("form", { class: "mb-3" });
            const c = field(filter, "currency", L.currency, currency); c.pattern = "[A-Z]{3}"; c.maxLength = 3;
            const apply = node("button", { type: "submit", class: "btn btn-outline-primary" }, L.refresh);
            filter.append(apply); filter.addEventListener("submit", (event) => {
                event.preventDefault(); if (filter.reportValidity()) task(() => open(tab, c.value, null, back), apply);
            });
            body.append(filter, controls(button(L.create_asset, () => asset(currency, current), "plus-lg")), text(L.asset_help));
            const data = await call(body, `/sis/payment-setup/assets/?currency=${encodeURIComponent(currency)}${after ? `&after=${encodeURIComponent(after)}` : ""}`);
            if (!body.isConnected) return;
            for (const a of data.items) body.append(card(`${a.code} · ${a.title}`, details(a),
                ...(a.kind === null ? [button(L.classify, () => classify(a, current), "tag")] : [])));
            if (!data.items.length) body.append(text(L.empty));
            paging(body, data, (next) => open(tab, currency, next, back));
            return;
        }
        body.append(controls(button(tab === "connections" ? L.add_connection : L.assign_route,
            () => tab === "connections" ? connection(null, current, { currency }) : routeStart(current, currency), "plus-lg")));
        const data = await call(body, `/sis/payment-setup/directory/${tab}/${after ? `?after=${encodeURIComponent(after)}` : ""}`);
        if (!body.isConnected) return;
        if (!data.enabled) body.append(node("p", { class: "alert alert-info" }, L.disabled));
        if (data.test_allowed) body.append(node("p", { class: "alert alert-warning" }, L.test_notice));
        for (const item of data.items) {
            const run = () => tab === "connections" ? record(item.id, current) : route(item, current);
            body.append(card(tab === "connections" ? item.name : `${L[item.purpose]} · ${item.currency}`, details(item),
                controls(button(tab === "connections" ? L.view : L.edit_route, run, "pencil-square"))));
        }
        if (!data.items.length) body.append(text(L.empty));
        paging(body, data, (next) => open(tab, currency, next, back));
    }
    function paging(body, data, load) {
        if (data.next_after) body.append(button(L.more, () => load(data.next_after), "chevron-down"));
    }
    async function record(id, back) {
        const body = surface(L.connection);
        body.append(button(L.back, back, "arrow-left"));
        const data = await call(body, `/sis/payment-setup/connections/${encodeURIComponent(id)}/`);
        if (!body.isConnected) return;
        body.append(card(data.name, details(data), text(L.credentials_private), controls(
            button(L.edit, () => connection(id, () => record(id, back), data), "pencil-square"),
            actionMenu(L.more_actions, [{ label: L.routes, icon: "diagram-3", run: () => open("routes", data.currency, null,
                () => record(id, back)) }]))));
    }
    async function asset(currency, back, draft = {}) {
        const body = surface(L.create_asset), form = node("form", { class: "card card-body bg-white border" });
        body.append(button(L.back, back, "arrow-left"), text(L.asset_help));
        field(form, "code", L.code, draft.code || "").maxLength = 80;
        field(form, "title", L.title_account, draft.title || "");
        field(form, "currency", L.currency, currency).maxLength = 3; reason(form, draft.reason);
        reviewable(body, form, "asset", (v) => v, back);
    }
    async function classify(a, back) {
        const body = surface(L.classify), form = node("form", { class: "card card-body bg-white border" });
        body.append(button(L.back, back, "arrow-left"), card(`${a.code} · ${a.title}`, text(L.classify_help)));
        field(form, "kind", L.kind, "asset", "select", kindKeys.map((k) => [k, L[k]])); reason(form);
        reviewable(body, form, "classification", (v) => ({ ...v, id: a.id }), back);
    }
    async function connection(id, back, draft = {}) {
        const body = surface(id ? L.edit : L.add_connection);
        body.append(button(id ? L.back_record : L.back, back, "arrow-left"), text(L.connection_help));
        const form = node("form", { class: "card card-body bg-white border" });
        field(form, "name", L.name, draft.name || "").maxLength = 120;
        const currency = field(form, "currency", L.currency, draft.currency || "USD"); currency.maxLength = 3; currency.pattern = "[A-Z]{3}";
        const live = field(form, "live", L.mode, draft.live === false ? "false" : "true", "select", [["true", L.live], ["false", L.test]]);
        currency.readOnly = !!id; live.disabled = !!id;
        const frame = body.closest(".offcanvas-body"), drawer = body.closest(".offcanvas");
        const clear = () => cleanSecrets(form);
        const observer = new MutationObserver(() => {
            if (!body.isConnected) { clear(); observer.disconnect(); drawer?.removeEventListener("hide.bs.offcanvas", clear); }
        });
        if (frame) observer.observe(frame, { childList: true });
        drawer?.addEventListener("hide.bs.offcanvas", clear);
        field(form, "enabled", L.enabled, draft.enabled ? "true" : "false", "select", [["false", L.no], ["true", L.yes]]);
        const select = field(form, "asset_id", L.asset_account, draft.asset?.id || draft.asset_id || "", "select", [["", L.choose_asset]]);
        let assetRevision = 0, assetInitialized = false;
        const assetsPanel = node("div", { class: "mb-3" }); form.append(assetsPanel);
        async function loadAssets(after = null) {
            const at = ++assetRevision, requested = currency.value;
            const data = await call(body, `/sis/payment-setup/assets/?currency=${encodeURIComponent(requested)}${after ? `&after=${encodeURIComponent(after)}` : ""}`);
            if (!body.isConnected || at !== assetRevision || currency.value !== requested) return;
            const chosen = select.value;
            if (!after) select.replaceChildren(node("option", { value: "" }, L.choose_asset));
            if (!after && draft.asset && draft.asset.currency === requested) select.append(
                node("option", { value: draft.asset.id }, `${draft.asset.code} · ${draft.asset.title}`));
            for (const a of data.items.filter((a) => a.kind === "asset")) {
                if (![...select.options].some((o) => o.value === a.id)) select.append(node("option", { value: a.id }, `${a.code} · ${a.title}`));
            }
            const saved = assetInitialized ? chosen : (draft.asset?.currency === requested ? draft.asset?.id : null) || draft.asset_id;
            assetInitialized = true;
            if (saved && [...select.options].some((o) => o.value === saved)) select.value = saved;
            assetsPanel.replaceChildren();
            if (data.next_after) assetsPanel.append(button(L.more_assets, () => loadAssets(data.next_after), "chevron-down"));
        }
        currency.addEventListener("change", () => {
            if (/^[A-Z]{3}$/.test(currency.value)) task(() => loadAssets());
        });
        form.append(button(L.create_asset, () => {
            const saved = values(form); cleanSecrets(form);
            const next = { ...saved, version: draft.version, live: id ? draft.live : saved.live === "true", enabled: saved.enabled === "true" };
            return asset(currency.value, () => connection(id, back, next));
        }, "plus-lg"));
        field(form, "secret", L.secret, "", "password", null, !id);
        field(form, "webhook", L.webhook, "", "password", null, !id);
        form.append(text(L.credentials_help), text(L.webhook_help)); reason(form, draft.reason);
        reviewable(body, form, "connection", (v) => ({ ...v, id, version: draft.version || 0,
            live: id ? draft.live : v.live === "true", enabled: v.enabled === "true" }), async (saved) => record(saved.id, back));
        await loadAssets();
    }
    async function routeStart(back, currency) {
        const body = surface(L.assign_route), form = node("form", { class: "card card-body bg-white border" });
        body.append(button(L.back, back, "arrow-left"), text(L.route_help));
        field(form, "purpose", L.purpose, "account", "select", purposeKeys.map((k) => [k, L[k]]));
        field(form, "currency", L.currency, currency).maxLength = 3;
        const next = node("button", { type: "submit", class: "btn btn-primary" }, L.next);
        form.append(next); body.append(form);
        form.addEventListener("submit", (event) => {
            event.preventDefault(); if (!form.reportValidity()) return;
            task(async () => {
                const v = values(form), data = await call(body, `/sis/payment-setup/routing/?purpose=${encodeURIComponent(v.purpose)}&currency=${encodeURIComponent(v.currency)}`);
                if (body.isConnected) await route(data, back);
            }, next);
        });
    }
    async function route(saved, back) {
        const body = surface(L.assign_route), form = node("form", { class: "card card-body bg-white border" });
        body.append(button(L.back, back, "arrow-left"), card(`${L[saved.purpose]} · ${saved.currency}`, text(L.route_help)));
        const select = field(form, "connection_id", L.connection, "", "select", [["", L.unassigned]], false);
        if (saved.selected_connection) {
            const c = saved.selected_connection;
            select.append(node("option", { value: c.id }, `${c.name} · ${c.live ? L.live : L.test} · ${c.merchant}`));
            select.value = c.id;
        }
        const extra = node("div", { class: "mb-3" }); form.append(extra);
        async function choices(after = null) {
            const data = await call(body, `/sis/payment-setup/directory/connections/${after ? `?after=${encodeURIComponent(after)}` : ""}`);
            if (!body.isConnected) return;
            for (const c of data.items.filter((c) => c.enabled && c.currency === saved.currency)) {
                if (![...select.options].some((o) => o.value === c.id)) select.append(node("option", { value: c.id }, `${c.name} · ${c.live ? L.live : L.test} · ${c.merchant}`));
            }

            extra.replaceChildren();
            if (data.next_after) extra.append(button(L.more, () => choices(data.next_after), "chevron-down"));
        }
        reason(form);
        reviewable(body, form, "route", (v) => ({ ...v, purpose: saved.purpose, currency: saved.currency,
            version: saved.version || 0, connection_id: v.connection_id || null }), back);
        await choices();
    }
    return { open };
};
