/* Term-neutral configuration; preparing an offering never writes sections or reserves rooms. */
window.createWorkspaceOfferingTemplates = function (ui) {
    "use strict";
    const { node, request: transport, task, table, badge, money, buildForm, formData, openDrawer, notify } = ui;
    let activeBody = null;
    async function request(...args) {
        const scope = activeBody;
        try {
            return await transport(...args);
        } catch (error) {
            if (error.status === 403 && scope?.isConnected) {
                document.querySelector("#drawer-title").textContent = "Offering templates";
                scope.replaceChildren(
                    node("p", {}, "Template configuration is no longer available with your current access."),
                );
            }
            throw error;
        }
    }
    function openPanel(title, label) {
        const drawer = openDrawer(title, label);
        activeBody = node("div");
        drawer.append(activeBody);
        return activeBody;
    }
    window.addEventListener("pagehide", () => {
        activeBody?.replaceChildren();
        activeBody = null;
    });
    const base = "/sis/catalog/offering-templates/";
    const keys = [
        "course_id",
        "code",
        "capacity",
        "faculty_user_id",
        "fee_minor",
        "currency",
        "delivery_mode",
        "web_registration",
    ];
    const clean = (row) =>
        Object.fromEntries(
            keys.map((key) => [
                key,
                row[key] ?? (key === "faculty_user_id" ? null : key === "delivery_mode" ? "unspecified" : row[key]),
            ]),
        );
    const button = (label, run, primary = false) =>
        node(
            "button",
            {
                type: "button",
                class: `btn ${primary ? "btn-primary" : "btn-outline-primary"}`,
                onclick: (event) => task(run, event.currentTarget),
            },
            label,
        );
    const grid = (headers, rows, label) =>
        node(
            "div",
            { class: "table-responsive", role: "region", tabindex: "0", "aria-label": label },
            table(headers, rows, label),
        );
    function summary(rows) {
        return grid(
            ["Section", "Course", "Seats", "Delivery", "Fee", "Faculty"],
            rows.map((row) => [
                row.code,
                row.course_title ? `${row.course_code} · ${row.course_title}` : "Course to review",
                row.capacity,
                row.delivery_mode.replaceAll("_", " "),
                money(row.fee_minor, row.currency),
                row.faculty_name || "Unassigned",
            ]),
            "Reusable section defaults",
        );
    }
    function warnings(body, rows) {
        const messages = rows.flatMap((row) => (row.warnings || []).map((message) => `${row.code}: ${message}`));
        if (messages.length)
            body.append(
                node(
                    "div",
                    { class: "alert alert-warning", role: "status" },
                    node("p", {}, "Some defaults need attention before an offering can be saved."),
                    node(
                        "ul",
                        {},
                        messages.map((message) => node("li", {}, message)),
                    ),
                ),
            );
    }
    async function page(active = true, search = "", after = null, stack = []) {
        const result = await request(`${base}page/`, "POST", {
            active,
            ...(search ? { search } : {}),
            ...(after ? { after } : {}),
        });
        const body = openPanel("Offering templates", "REUSE SETUP · REVIEW EACH TERM");
        body.append(
            node(
                "p",
                {},
                "Reuse section defaults, then choose a term, edit the planned sections and review them before saving. Catalog-year requirements and student cohorts stay independent.",
            ),
        );
        const fields = [["search", "Find a template", "search", "", false]];
        const form = await buildForm(fields, { search });
        const find = node("button", { type: "submit", class: "btn btn-outline-primary" }, "Find templates");
        form.append(find);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(() => page(active, form.elements.search.value), find);
        });
        body.append(
            form,
            node(
                "div",
                { class: "d-flex flex-wrap gap-2 mb-3" },
                button(active === true ? "Show retired templates" : "Show active templates", () =>
                    page(active !== true, search),
                ),
                button("Build a new offering plan", () => ui.catalog().start()),
            ),
        );
        body.append(
            result.items.length
                ? grid(
                      ["Template", "Sections", "Status", "Action"],
                      result.items.map((row) => [
                          button(row.name, () => record(row.id)),
                          row.section_count,
                          badge(row.active ? "Active" : "Retired"),
                          row.active ? button(`Use ${row.name}`, () => chooseTerm(row), true) : "Restore before using",
                      ]),
                      "Offering templates",
                  )
                : node(
                      "p",
                      { class: "py-3" },
                      "No matching templates. Build an offering plan and choose “Save as reusable template” from its planned sections.",
                  ),
        );
        if (stack.length)
            body.append(button("Previous template page", () => page(active, search, stack.at(-1), stack.slice(0, -1))));
        if (result.next_after)
            body.append(button("Next template page", () => page(active, search, result.next_after, [...stack, after])));
    }
    async function fromPlan(rows, target = null) {
        return editor({ id: null, version: -1, name: "", active: true, ...target, sections: rows.map(clean) });
    }
    async function editor(value) {
        const body = openPanel(
            value.id ? "Revise offering template" : "Save as reusable template",
            "SECTION DEFAULTS · NO TERM OR STUDENT DATA",
        );
        body.append(
            node(
                "p",
                {},
                "These defaults include section codes, seats, faculty, delivery and fees. Codes are copied exactly; revise year-specific parts when preparing a different term. No course requirements, catalog-year assignment, booking, enrollment or charge is copied.",
            ),
            summary(value.sections),
        );
        const fields = [["name", "Template name"]];
        const form = await buildForm(fields, value);
        form.elements.name.maxLength = 120;
        const review = node("button", { type: "submit", class: "btn btn-primary" }, "Review template");
        form.append(review);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                const command = {
                    version: value.version,
                    name: formData(form, fields).name,
                    sections: value.sections.map(clean),
                    ...(value.id ? { template_id: value.id } : {}),
                };
                const plan = await request(`${base}review/`, "POST", command);
                await confirm(plan, command);
            }, review);
        });
        body.append(
            form,
            button("Back to templates", () => page()),
        );
    }
    async function confirm(plan, draft) {
        const body = openPanel("Review reusable defaults", "TEMPLATE CONFIGURATION");
        const command = {
            version: plan.version,
            name: plan.name,
            sections: draft.sections,
            operation_id: plan.operation_id,
            revision: plan.revision,
        };
        body.append(node("h3", {}, plan.name), summary(plan.sections));
        warnings(body, plan.sections);
        const change = button("Change template name", () =>
            editor({
                id: plan.version < 0 ? null : plan.id,
                version: plan.version,
                name: plan.name,
                sections: draft.sections,
            }),
        );
        body.append(
            node(
                "p",
                { class: "alert alert-info" },
                "Saving updates this template only. Existing offering plans, sections, student catalog years and financial records stay unchanged.",
            ),
            change,
            button(
                "Confirm template save",
                async () => {
                    change.disabled = true;
                    try {
                        await request(`${base}${plan.id}/save/`, "POST", command);
                    } catch (error) {
                        if (error.status && error.status < 500) change.disabled = false;
                        throw error;
                    }
                    notify("Reusable offering template saved.");
                    await record(plan.id);
                },
                true,
            ),
            button("Check saved template", async () => {
                await request(`${base}${plan.id}/receipts/${plan.operation_id}/`);
                await record(plan.id);
            }),
        );
        body.append(
            node(
                "p",
                { class: "form-text" },
                "If the connection is interrupted, retry this same confirmation or check its saved result.",
            ),
        );
    }
    async function record(id) {
        const value = await request(`${base}${id}/`);
        const body = openPanel(value.name, "REUSABLE OFFERING DEFAULTS");
        body.append(
            badge(value.active ? "Active" : "Retired"),
            node(
                "p",
                { class: "mt-3" },
                "Every use creates a fresh, editable plan. Later template edits do not rewrite plans that have already been prepared.",
            ),
            summary(value.sections),
        );
        warnings(body, value.sections);
        if (value.active) body.append(button("Use for a new term", () => chooseTerm(value), true));
        body.append(
            button("Revise defaults in offering planner", () =>
                ui.catalog().templateDraft(null, value.sections.map(clean), {
                    id: value.id,
                    version: value.version,
                    name: value.name,
                }),
            ),
            button("Rename template", () => editor(value)),
            button(value.active ? "Retire template" : "Restore template", () => status(value)),
            button("Change history", () => history(value)),
            button("All templates", () => page()),
        );
    }
    async function chooseTerm(value) {
        const current = value.sections ? value : await request(`${base}${value.id}/`);
        const body = openPanel("Prepare a new term", "TEMPLATE → EDITABLE PLAN");
        body.append(node("h3", {}, current.name));
        const fields = [["term_id", "Offering term", "lookup", "terms"]];
        const form = await buildForm(fields);
        const next = node("button", { type: "submit", class: "btn btn-primary" }, "Load editable defaults");
        form.append(next);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                const plan = await request(`${base}${current.id}/prepare/`, "POST", {
                    version: current.version,
                    term_id: formData(form, fields).term_id,
                });
                await ui.catalog().templateDraft(plan.term.id, plan.sections);
            }, next);
        });
        body.append(
            node(
                "p",
                {},
                "Nothing is saved yet. Review faculty, delivery, capacity, pricing and year-specific codes in the planner. Room booking and publication remain separate.",
            ),
            form,
            button("Back to template", () => record(current.id)),
        );
    }
    async function status(value) {
        const op = (await request("/sis/ui/operation-id/")).id;
        const body = openPanel(
            value.active ? "Retire offering template" : "Restore offering template",
            "TEMPLATE AVAILABILITY",
        );
        const fields = [["note", "Reason for this change", "textarea"]];
        const form = await buildForm(fields);
        form.elements.note.maxLength = 2000;
        const save = node("button", { type: "submit", class: "btn btn-primary" }, "Confirm status change");
        let command = null;
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                command ??= {
                    ...formData(form, fields),
                    operation_id: op,
                    version: value.version,
                    active: !value.active,
                };
                form.elements.note.disabled = true;
                try {
                    await request(`${base}${value.id}/status/`, "POST", command);
                } catch (error) {
                    if (error.status === 422) {
                        command = null;
                        form.elements.note.disabled = false;
                    }
                    throw error;
                }
                notify("Template status updated.");
                await record(value.id);
            }, save);
        });
        form.append(save);
        body.append(
            node("h3", {}, value.name),
            node(
                "p",
                {},
                value.active
                    ? "Retirement removes this template from future selections. Previously prepared plans and sections remain unchanged."
                    : "Restoring makes this template available for future plans. Each offering still needs a fresh review.",
            ),
            form,
            button("Check saved status change", async () => {
                await request(`${base}${value.id}/receipts/${op}/`);
                await record(value.id);
            }),
        );
    }
    async function history(value, cursor = null, stack = []) {
        const page = await request(
            `${base}${value.id}/history/`,
            "POST",
            cursor === null ? {} : { after_version: cursor },
        );
        const body = openPanel("Template change history", "CONFIGURATION HISTORY");
        body.append(
            node("h3", {}, value.name),
            grid(
                ["Version", "Action", "Name", "Sections", "Reason", "Saved"],
                page.items.map((row) => [
                    row.version,
                    row.action,
                    row.name,
                    row.section_count,
                    row.note || "—",
                    row.created_at,
                ]),
                "Template change history",
            ),
        );
        if (stack.length)
            body.append(button("Previous change page", () => history(value, stack.at(-1), stack.slice(0, -1))));
        if (page.next_after_version !== null)
            body.append(button("Next change page", () => history(value, page.next_after_version, [...stack, cursor])));
        body.append(button("Back to template", () => record(value.id)));
    }
    return { page, fromPlan, record };
};
