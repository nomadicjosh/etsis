/* Protected reviews and drafts stay in memory. Accepted academic facts remain on the server. */
window.createGraduateRegistration = function (ui) {
    "use strict";
    const { node, request, task, openDrawer } = ui;
    const t = key => ui.labels[key];
    const pending = new Set();
    const supportAllowed = policy => ui.historyChecks && ui.can && [
        'sis:history:verify', 'sis:audit:read', 'sis:registration:manage',
        ...(policy ? ['sis:registration:graduate-policy:publish'] : ['sis:student:read', 'sis:registration:graduate-override']),
    ].every(ui.can);
    const button = (key, run, style = "btn-outline-primary", icon = null) => node("button", {
        type: "button", class: `btn ${style}`, onclick: event => task(run, event.currentTarget),
    }, ...(icon ? [node("i", { class: `bi bi-${icon} me-2`, "aria-hidden": "true" })] : []), t(key));
    const paragraph = text => node("p", { class: "form-text" }, text);
    const field = (id, key, control, help = null) => {
        control.id = id;
        if (help) control.setAttribute("aria-describedby", `${id}-help`);
        return node("div", { class: "mb-3" }, node("label", { class: "form-label", for: id }, t(key)), control,
            help ? node("p", { id: `${id}-help`, class: "form-text" }, t(help)) : null);
    };
    const reasonField = () => node("textarea", { class: "form-control", required: true, maxLength: 1000, rows: 3 });
    const pointText = value => value === null ? t("none") : (value / 1000).toFixed(3);
    const parsePoints = value => value === "" ? null : Math.round(Number(value) * 1000);
    const points = value => node("input", { type: "number", class: "form-control", min: 0, max: 10,
        step: "0.001", value: value === null ? "" : (value / 1000).toFixed(3) });
    const deny = (body, failure) => {
        if (![401, 403].includes(failure.status)) return false;
        if (body.isConnected) body.replaceChildren(node("p", { role: "alert" }, t("denied")));
        return true;
    };
    const summarize = (body, rules, levels) => {
        const names = new Map(levels.map(level => [level.id, `${level.code} — ${level.title}`]));
        const pairs = [[t("enabled"), t(rules.enabled ? "active" : "disabled")],
            [t("undergraduate"), names.get(rules.undergraduate_level_id) || t("choose")],
            [t("graduate"), names.get(rules.graduate_level_id) || t("choose")],
            [t("gpa"), pointText(rules.minimum_gpa_milli)], [t("grade"), pointText(rules.minimum_graduate_grade_milli)]];
        body.append(node("dl", { class: "record-summary" }, ...pairs.map(([label, value]) =>
            node("div", {}, node("dt", {}, label), node("dd", {}, value)))));

    };
    async function policy(back = null) {
        const body = openDrawer(t("policy"), t("eyebrow"));
        body.append(paragraph(t("loading")));
        if (pending.has("policy")) {
            body.replaceChildren(paragraph(t("pending")), button("reload", () => policy(back)));
            if (back) body.append(button("back", back, "btn-link", "arrow-left"));
            return;
        }
        let saved;
        try { saved = await request("/sis/graduate-registration-policy/"); }
        catch (failure) { if (!deny(body, failure)) throw failure; return; }
        if (!body.isConnected) return;
        const levels = new Map(saved.levels.map(level => [level.id, level]));
        const draft = { ...saved.rules };
        const reason = reasonField();
        const form = node("form", { class: "record-form" });
        const enabled = node("input", { type: "checkbox", class: "form-check-input", checked: draft.enabled });
        const undergraduate = node("select", { class: "form-select" });
        const graduate = node("select", { class: "form-select" });
        const gpa = points(draft.minimum_gpa_milli);
        gpa.required = true;
        const grade = points(draft.minimum_graduate_grade_milli);
        const search = node("input", { type: "search", class: "form-control", maxLength: 80 });
        const error = node("p", { role: "alert", class: "text-danger" });
        let cursor = null, query = 0;
        const renderLevels = () => {
            for (const [control, selected] of [[undergraduate, draft.undergraduate_level_id], [graduate, draft.graduate_level_id]]) {
                const value = control.value || selected;
                control.replaceChildren(node("option", { value: "" }, t("choose")), ...[...levels.values()].map(level =>
                    node("option", { value: level.id }, `${level.code} — ${level.title}`)));
                control.value = value || "";
            }
        };
        const more = button("more", () => loadLevels(false));
        const loadLevels = async reset => {
            const own = ++query;
            const page = await request("/sis/graduate-registration-policy/levels/", "POST", {
                search: search.value.trim(), ...(reset || !cursor ? {} : { after: cursor }),
            });
            if (!bodyRef.isConnected || own !== query) return;
            // Selected labels remain available even when a search does not include their rows.
            if (reset) {
                for (const id of [...levels.keys()]) if (![undergraduate.value, graduate.value].includes(id)) levels.delete(id);
            }
            for (const level of page.items) levels.set(level.id, level);
            cursor = page.next_after;
            more.hidden = !cursor;
            renderLevels();
        };
        renderLevels();
        body.replaceChildren(paragraph(t("help")), form, error);
        form.append(node("section", { class: "record-section bg-white border rounded p-3 mb-3" }, node("div", { class: "form-check mb-3" },
            node("label", { class: "form-check-label" }, enabled, node("span", { class: "ms-2" }, t("enabled")))),
            field("graduate-level-search", "level_search", search),
            node("div", { class: "inline-actions mb-3" }, button("find", () => loadLevels(true)), more),
            field("graduate-undergraduate-level", "undergraduate", undergraduate, "levels_help"),
            field("graduate-graduate-level", "graduate", graduate),
            field("graduate-minimum-gpa", "gpa", gpa, "gpa_help"),
            field("graduate-minimum-grade", "grade", grade, "grade_help"),
            field("graduate-policy-reason", "reason", reason)));
        const review = node("button", { type: "submit", class: "btn btn-primary" }, t("review"));
        form.append(node("div", { class: "record-form-actions" }, review,
            button("reload", () => policy(back), "btn-outline-secondary", "arrow-clockwise"),
            ...(back ? [button("back", back, "btn-link", "arrow-left")] : [])));
        const edit = () => {
            const fresh = openDrawer(t("policy"), t("eyebrow"));
            fresh.append(paragraph(t("help")), form, error);
            bodyRef = fresh;
        };
        let bodyRef = body;
        if (supportAllowed(true)) {
            const check = button('check_policy', () => {
                const panel = ui.historyChecks().open('gradpolicy', saved.policy_reference, async () => {
                    if (!panel.isConnected) return;
                    try {
                        await request('/sis/graduate-registration-policy/');
                        if (!panel.isConnected) return;
                        edit();
                        check.focus();
                    } catch (failure) {
                        if (!panel.isConnected) return;
                        if ([401, 403].includes(failure.status)) {
                            reason.value = '';
                            const denied = openDrawer(t('policy'), t('eyebrow'));
                            deny(denied, failure);
                        } else throw failure;
                    }
                });
            }, 'btn-outline-secondary', 'shield-check');
            form.querySelector('.record-form-actions').append(check);
        }

        form.addEventListener("submit", event => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity() || pending.has("policy")) return;
                undergraduate.required = graduate.required = enabled.checked;
                if (!form.reportValidity()) return;
                const rules = { enabled: enabled.checked, undergraduate_level_id: undergraduate.value || null,
                    graduate_level_id: graduate.value || null, minimum_gpa_milli: parsePoints(gpa.value),
                    minimum_graduate_grade_milli: parsePoints(grade.value) };
                const source = bodyRef;
                let result;
                try {
                    result = await request("/sis/graduate-registration-policy/review/", "POST", {
                        rules, version: saved.version, reason: reason.value,
                    });
                } catch (failure) { if (!deny(source, failure) && source.isConnected) error.textContent = failure.message; return; }
                if (!source.isConnected) return;
                const panel = openDrawer(t("review_title"), t("eyebrow"));
                const summary = node("section", { class: "record-section bg-white border rounded p-3 mb-3" });
                summarize(summary, result.rules, [...levels.values()]);
                panel.append(paragraph(t("help")), summary, field("graduate-policy-reviewed-reason", "reason",
                    node("textarea", { class: "form-control", readOnly: true, rows: 3, value: result.reason })));
                const confirm = button("publish", async () => {
                    if (pending.has("policy")) return;
                    pending.add("policy");
                    for (const control of panel.querySelectorAll("button")) control.disabled = true;
                    try {
                        await request("/sis/graduate-registration-policy/publish/", "POST", result);
                        if (panel.isConnected) {
                            panel.replaceChildren(node("p", { role: "status" }, t("published")), button("reload", () => policy(back)));
                            if (back) panel.append(button("back", back, "btn-link", "arrow-left"));
                        }
                    } catch (failure) { if (!deny(panel, failure) && panel.isConnected) panel.append(node("p", { role: "alert" }, failure.message)); }
                    finally { pending.delete("policy"); if (panel.isConnected) for (const control of panel.querySelectorAll("button")) control.disabled = false; }
                }, "btn-primary", "journal-check");
                panel.append(node("div", { class: "inline-actions" }, confirm, button("edit", edit, "btn-link", "arrow-left")));
            }, review);
        });
        try { await loadLevels(false); } catch (failure) { if (!deny(body, failure) && body.isConnected) error.textContent = failure.message; }
    }

    async function open(student, section, back = null, pursuit = null, restore = null) {
        const key = `${student}:${section}`;
        const body = openDrawer(t("approvals"), t("eyebrow"));
        body.append(paragraph(t("loading")));
        if (pending.has(key)) {
            body.replaceChildren(paragraph(t("pending")), button("refresh", () => open(student, section, back, pursuit)));
            if (back) body.append(button("back", back, "btn-link", "arrow-left"));
            return;
        }
        const base = `${ui.prefix || "/sis"}/students/${student}/graduate-courses/${section}/`;
        let saved;
        try { saved = await request(`${base}current/`, "POST", pursuit ? { pursuit_id: pursuit } : {}); }
        catch (failure) {
            if (!deny(body, failure)) throw failure;
            if (restore) restore.reason = '';
            return;
        }
        if (!body.isConnected) return;
        body.replaceChildren(paragraph(t("approval_help")));
        if (!saved.required) body.append(node("p", { role: "status" }, t("not_required")));
        for (const check of saved.checks) body.append(node("section", { class: "record-section bg-white border rounded p-3 mb-3" },
            node("h3", { class: "h6" }, check.label), node("p", { role: "status", class: check.status === "met" ? "text-success" : "text-danger" }, check.message)));
        const actions = [];
        if (saved.can_advisor) {
            if (saved.required) actions.push("advisor_approve");
            if (saved.advisor_recorded) actions.push("advisor_revoke");
        }
        if (saved.can_registrar) {
            if (saved.required) actions.push("registrar_override");
            if (saved.registrar_recorded) actions.push("registrar_revoke");
        }
        let captureDraft = () => ({ pursuit: saved.pursuit_id, action: null, reason: '' });
        if (actions.length) {
            const action = node("select", { class: "form-select" }, ...actions.map(key => node("option", { value: key }, t(key))));
            const reason = reasonField();
            if (restore && restore.pursuit === saved.pursuit_id && actions.includes(restore.action)) {
                action.value = restore.action;
                reason.value = restore.reason;
            }
            const submit = node("button", { type: "submit", class: "btn btn-primary" }, t("review_decision"));
            const form = node("form", { class: "record-form" }, field("graduate-approval-action", "review_decision", action),
                field("graduate-approval-reason", "reason", reason), submit);
            const error = node("p", { role: "alert", class: "text-danger" });
            body.append(form, error);
            captureDraft = () => ({ pursuit: saved.pursuit_id, action: action.value, reason: reason.value });

            form.addEventListener("submit", event => {
                event.preventDefault();
                task(async () => {
                    if (!form.reportValidity() || pending.has(key)) return;
                    let review;
                    try { review = await request(`${base}review/`, "POST", { pursuit_id: saved.pursuit_id,
                        action: action.value, reason: reason.value, version: saved.version }); }
                    catch (failure) { if (!deny(body, failure) && body.isConnected) error.textContent = failure.message; return; }
                    if (!body.isConnected) return;
                    const panel = openDrawer(t("decision_review"), t("eyebrow"));
                    panel.append(node("h3", { class: "h5" }, t(review.action)), node("p", { class: "text-break" }, review.reason));
                    const confirm = button("confirm", async () => {
                        if (pending.has(key)) return;
                        pending.add(key);
                        for (const control of panel.querySelectorAll("button")) control.disabled = true;
                        try {
                            await request(`${base}confirm/`, "POST", { ...review, pursuit_id: saved.pursuit_id });
                            if (panel.isConnected) {
                                panel.replaceChildren(node("p", { role: "status" }, t("recorded")),
                                    button("refresh", () => open(student, section, back, saved.pursuit_id)));
                                if (back) panel.append(button("back", back, "btn-link", "arrow-left"));
                            }
                        } catch (failure) { if (!deny(panel, failure) && panel.isConnected) panel.append(node("p", { role: "alert" }, failure.message)); }
                        finally { pending.delete(key); if (panel.isConnected) for (const control of panel.querySelectorAll("button")) control.disabled = false; }
                    }, "btn-primary", "check2");
                    panel.append(node("div", { class: "inline-actions" }, confirm,
                        button("back", () => open(student, section, back, saved.pursuit_id), "btn-link", "arrow-left")));
                }, submit);
            });
        }
        const navigation = node("div", { class: "inline-actions" }, button("refresh", () => open(student, section, back, saved.pursuit_id),
            "btn-outline-secondary", "arrow-clockwise"), ...(back ? [button("back", back, "btn-link", "arrow-left")] : []));
        if (supportAllowed(false)) {
            const check = button('check_approvals', () => {
                const draft = captureDraft();
                const panel = ui.historyChecks().open('graduate', saved.pursuit_id, async () => {
                    if (!panel.isConnected) return;
                    await open(student, section, back, saved.pursuit_id, draft);
                });
            }, 'btn-outline-secondary', 'shield-check');
            navigation.append(check);
            if (restore) requestAnimationFrame(() => { if (body.isConnected) check.focus(); });
        }
        body.append(navigation);
    }

    async function choose(student, pursuit, back) {
        const body = openDrawer(t("approvals"), t("eyebrow"));
        const search = node("input", { type: "search", class: "form-control", maxLength: 80 });
        const form = node("form", { class: "record-form" }, field("graduate-section-search", "choose_section", search),
            node("button", { type: "submit", class: "btn btn-outline-primary" }, t("find_sections")));
        const list = node("section", { class: "record-section bg-white border rounded p-3 mb-3" });
        const error = node("p", { role: "alert", class: "text-danger" });
        let after = null, generation = 0;
        const more = button("more_sections", () => load(true));
        async function load(append = false) {
            const own = ++generation;
            try {
                const page = await request(`/portal/api/courses/?${new URLSearchParams({ q: search.value.trim(), after: append ? after || "" : "" })}`);
                if (!body.isConnected || own !== generation) return;
                if (!append) list.replaceChildren();
                if (!page.items.length && !append) list.append(node("p", { role: "status" }, t("no_sections")));
                for (const item of page.items) list.append(button("open_approvals", () => open(student, item.id,
                    () => choose(student, pursuit, back), pursuit)));
                // Labels are rendered as text; source titles never become markup.
                const controls = page.items.length ? [...list.querySelectorAll("button")].slice(-page.items.length) : [];
                controls.forEach((control, i) => { const item = page.items[i];
                    control.textContent = `${item.course_code} · ${item.section_code} · ${item.term_code} — ${item.title}`;
                    control.classList.add("text-start", "text-wrap", "w-100", "mb-2"); });
                after = page.next_after;
                more.hidden = !after;
            } catch (failure) { if (!deny(body, failure) && body.isConnected && own === generation) error.textContent = failure.message; }
        }
        form.addEventListener("submit", event => { event.preventDefault(); task(() => load(), form.querySelector("button")); });
        body.append(paragraph(t("approval_help")), form, list, error, more,
            ...(back ? [button("back", back, "btn-link", "arrow-left")] : []));
        await load();
    }
    return { policy, open, choose };
};
