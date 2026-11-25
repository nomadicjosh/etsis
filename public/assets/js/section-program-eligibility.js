/* Catalog policy drafts remain in memory; ordinary registration reads only published offering rules. */
window.createSectionProgramEligibility = function (ui) {
    "use strict";
    const { node, request, task, openDrawer } = ui;
    const t = key => ui.labels[key];
    const drafts = new Map();
    const button = (key, run, style = "btn-outline-primary", icon = null) => node("button", {
        type: "button", class: `btn ${style}`, onclick: event => task(run, event.currentTarget),
    }, ...(icon ? [node("i", { class: `bi bi-${icon} me-2`, "aria-hidden": "true" })] : []), t(key));

    async function open(section, back = null, reset = false) {
        const body = openDrawer(t("title"), t("eyebrow"));
        body.append(node("p", { role: "status" }, t("loading")));
        let saved;
        try { saved = await request(`/sis/sections/${section}/eligible-programs/current/`, "POST", {}); }
        catch (failure) {
            if ([401, 403].includes(failure.status)) {
                drafts.get(section)?.selected.clear();
                drafts.delete(section);
                if (body.isConnected) body.replaceChildren(node("p", { role: "alert" }, t("denied")));
                return;
            }
            throw failure;
        }
        if (!body.isConnected) return;
        const existing = drafts.get(section);
        if (existing?.pending) {
            body.replaceChildren(node("p", { role: "status" }, t("pending")),
                button("reload", () => open(section, back)));
            if (back) body.append(button("back", back, "btn-link", "arrow-left"));
            return;
        }
        let draft = reset ? null : existing;
        if (!draft) {
            draft = { selected: new Map(saved.programs.map(program => [program.id, program])), revision: saved.revision,
                requireCampus: saved.require_program_campus ?? false,
                search: "", after: null, stack: [] };
            drafts.set(section, draft);
        }
        body.replaceChildren();
        const status = node("p", { role: "status", class: "form-text" });
        const error = node("p", { role: "alert", class: "text-danger" });
        const selected = node("section", { class: "record-section bg-white border rounded p-3 mb-3", "aria-label": t("selected") });
        const campus = node('input', { id: 'section-program-campus', type: 'checkbox', class: 'form-check-input',
            checked: draft.requireCampus, 'aria-describedby': 'section-program-campus-help' });
        campus.addEventListener('change', () => {
            if (busy) { campus.checked = draft.requireCampus; return; }
            draft.requireCampus = campus.checked;
        });
        const campusRule = node('section', { class: 'record-section bg-white border rounded p-3 mb-3',
            'aria-label': t('campus_heading') }, node('h3', { class: 'h6' }, t('campus_heading')),
            node('div', { class: 'form-check' }, campus,
                node('label', { for: campus.id, class: 'form-check-label' }, t('campus_rule'))),
            node('p', { id: 'section-program-campus-help', class: 'form-text mb-0' }, t('campus_help')));
        const results = node("section", { class: "record-section bg-white border rounded p-3 mb-3", "aria-label": t("search") });
        const search = node("input", { id: "section-program-search", type: "search", class: "form-control", maxLength: 80,
            value: draft.search });
        const form = node("form", { class: "record-form", "aria-label": t("search") },
            node("label", { class: "form-label", for: search.id }, t("search")), search,
            node("button", { type: "submit", class: "btn btn-outline-primary" }, t("find")));
        let query = 0;
        let busy = false;
        const deny = failure => {
            if (![401, 403].includes(failure.status)) return false;
            drafts.delete(section);
            draft.selected.clear();
            draft.requireCampus = false;
            query++;
            if (body.isConnected) body.replaceChildren(node("p", { role: "alert" }, t("denied")));
            return true;
        };
        const redraw = () => {
            selected.replaceChildren(node("h3", {}, t("selected")), node("p", { class: "form-text" }, t("current_labels")));
            if (!draft.selected.size) selected.append(node("p", {}, t("all")));
            for (const program of draft.selected.values()) {
                const remove = button("remove", () => {
                    if (busy) return;
                    draft.selected.delete(program.id);
                    redraw();
                    load();
                }, "btn-outline-secondary", "x");
                remove.setAttribute("aria-label", `${t("remove")}: ${program.code}`);
                selected.append(node("div", { class: "d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2" },
                    node("span", { class: "text-break" }, `${program.code} — ${program.title}`), remove));
            }
        };
        const load = async () => {
            const own = ++query;
            draft.search = search.value.trim();
            try {
                const page = await request(`/sis/sections/${section}/eligible-programs/choices/`, "POST", {
                    search: draft.search, ...(draft.after ? { after: draft.after } : {}),
                });
                if (!body.isConnected || own !== query) return;
                results.replaceChildren();
                if (!page.items.length) results.append(node("p", { role: "status" }, t("none")));
                for (const program of page.items) {
                    const check = node("input", { type: "checkbox", class: "form-check-input mt-1 flex-shrink-0",
                        checked: draft.selected.has(program.id), disabled: busy });
                    check.addEventListener("change", () => {
                        if (busy) { check.checked = draft.selected.has(program.id); return; }
                        if (check.checked && draft.selected.size >= 20) {
                            check.checked = false;
                            error.textContent = t("limit");
                            return;
                        }
                        check.checked ? draft.selected.set(program.id, program) : draft.selected.delete(program.id);
                        error.textContent = "";
                        redraw();
                    });
                    results.append(node("label", { class: "d-flex align-items-start gap-2 text-start mb-3" }, check,
                        node("span", { class: "text-break" }, `${program.code} — ${program.title}`)));
                }
                const navigation = node("div", { class: "inline-actions" });
                if (draft.stack.length) navigation.append(button("previous", async () => {
                    draft.after = draft.stack.pop();
                    await load();
                }));
                if (page.next_after) navigation.append(button("more", async () => {
                    draft.stack.push(draft.after);
                    draft.after = page.next_after;
                    await load();
                }));
                results.append(navigation);
            } catch (failure) {
                if (!body.isConnected || own !== query || deny(failure)) return;
                error.textContent = failure.message;
            }
        };
        form.addEventListener("submit", event => {
            event.preventDefault();
            draft.after = null;
            draft.stack = [];
            task(load, form.querySelector('[type="submit"]'));
        });
        const save = button("save", async () => {
            if (busy || draft.pending) return;
            const ids = [...draft.selected.keys()].sort();
            busy = draft.pending = true;
            for (const control of body.querySelectorAll("input, button")) control.disabled = true;
            try {
                const result = await request(`/sis/sections/${section}/eligible-programs/save/`, "POST", {
                    program_ids: ids, revision: draft.revision,
                    require_program_campus: draft.requireCampus,
                });
                draft.revision = result.revision;
                if (!body.isConnected) return;
                status.textContent = t("saved");
                error.textContent = "";
            } catch (failure) {
                if (deny(failure) || !body.isConnected) return;
                error.textContent = `${failure.message} ${t("reload_help")}`;
            } finally {
                busy = draft.pending = false;
                if (body.isConnected) for (const control of body.querySelectorAll("input, button")) control.disabled = false;
            }
        }, "btn-primary", "check2");
        body.append(node("p", { class: "fw-semibold" }, saved.section_code), node("p", {}, t("help")),
            campusRule, selected, form, results, error, status, node("div", { class: "inline-actions" }, save,
                button("reload", () => open(section, back, true), "btn-outline-secondary", "arrow-clockwise"),
                button("publish", () => ui.publish(section, () => open(section, back)), "btn-outline-primary", "journal-check"),
                ...(back ? [button("back", back, "btn-link", "arrow-left")] : [])));
        redraw();
        await load();
    }
    return { open };
};
