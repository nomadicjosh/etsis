/* Bounded access editors: one definition page and its assignment state per request. */
window.createWorkspaceAccess = function (ui) {
    "use strict";
    const {
        node,
        request,
        task,
        openDrawer,
        badge,
        notify,
        definitionActions,
    } = ui;
    let serial = 0;
    const bool = (value) => value === true || Number(value) === 1;
    const click = (label, callback, classes = "btn btn-outline-primary") =>
        node(
            "button",
            {
                type: "button",
                class: classes,
                onclick: (event) => task(callback, event.currentTarget),
            },
            label,
        );

    async function browser(
        container,
        {
            kind,
            title,
            grant = "",
            subject = null,
            disabled = false,
            choose = null,
        },
    ) {
        const unique = ++serial;
        let after = "";
        let query = "";
        let trail = [];
        let requestVersion = 0;
        const search = node("input", {
            id: `access-search-${unique}`,
            type: "search",
            class: "form-control",
            maxlength: 80,
        });
        const status = node("p", {
            class: "form-text",
            role: "status",
            "aria-live": "polite",
        });
        const assigned = node("input", {
            type: "checkbox",
            id: `access-assigned-${unique}`,
            class: "form-check-input",
        });
        const form = node(
            "form",
            { class: "mb-3" },
            node(
                "label",
                { class: "form-label", for: search.id },
                `Search ${title.toLowerCase()}`,
            ),
            node(
                "div",
                { class: "input-group" },
                search,
                node(
                    "button",
                    { type: "submit", class: "btn btn-outline-primary" },
                    "Find",
                ),
            ),
        );
        if (subject)
            form.append(
                node(
                    "div",
                    { class: "form-check mt-3" },
                    assigned,
                    node(
                        "label",
                        { class: "form-check-label", for: assigned.id },
                        "Show direct assignments only",
                    ),
                ),
            );
        const results = node("div", {
            class: choose ? "role-grid" : "permission-picker",
        });
        const previous = click("Previous page", async () => {
            after = trail.pop() || "";
            await load();
        });
        const next = click("Next page", async () => {
            trail.push(after);
            after = next.dataset.after;
            await load();
        });
        container.append(
            form,
            status,
            results,
            node("div", { class: "inline-actions mt-3" }, previous, next),
        );
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            query = search.value.trim();
            after = "";
            trail = [];
            task(load, form.querySelector('[type="submit"]'));
        });
        assigned.addEventListener("change", () => {
            after = "";
            trail = [];
            task(load, assigned);
        });
        async function load(focusId = null) {
            const version = ++requestVersion;
            results.setAttribute("aria-busy", "true");
            let page;
            try {
                page = await request(`/sis/access/directory/${kind}/`, "POST", {
                    search: query,
                    after,
                    limit: 50,
                    grant,
                    subject,
                    assigned_only: Boolean(subject && assigned.checked),
                });
            } finally {
                if (version === requestVersion)
                    results.removeAttribute("aria-busy");
            }
            if (version !== requestVersion || !container.isConnected) return;
            results.replaceChildren();
            status.textContent = `${page.items.length} ${title.toLowerCase()} on this page.${page.next_after ? " More are available." : ""}`;
            previous.disabled = trail.length === 0;
            next.disabled = !page.next_after;
            next.dataset.after = page.next_after || "";
            if (!page.items.length)
                results.append(
                    node(
                        "p",
                        {},
                        "No matching definitions. Adjust your search or assignment filter.",
                    ),
                );
            for (const item of page.items) {
                if (choose) {
                    const card = click(
                        item.name,
                        () => choose(item),
                        "role-card",
                    );
                    card.replaceChildren(
                        badge(
                            bool(item.is_system)
                                ? "Protected system definition"
                                : "Custom definition",
                        ),
                        node("strong", {}, item.name),
                        node(
                            "small",
                            {},
                            item.description || "No description provided.",
                        ),
                    );
                    results.append(card);
                    continue;
                }
                const input = node("input", {
                    type: "checkbox",
                    id: `access-${unique}-${item.id}`,
                    class: "form-check-input",
                    checked: bool(item.assigned),
                    disabled: disabled || item.assignable === false,
                });
                const label = node(
                    "label",
                    { class: "form-check-label", for: input.id },
                    item.name,
                    node("small", {}, item.description),
                );
                if (bool(item.effective) && !bool(item.assigned))
                    label.append(
                        node(
                            "small",
                            { class: "d-block" },
                            "Effective through a role or permission group.",
                        ),
                    );
                input.addEventListener("change", () =>
                    task(async () => {
                        const checked = input.checked;
                        const path =
                            grant === "role_permission"
                                ? `/sis/access/roles/${subject}/permissions/${item.id}/`
                                : `/sis/access/users/${subject}/${grant === "user_role" ? "roles" : "permissions"}/${item.id}/`;
                        try {
                            await request(path, checked ? "PUT" : "DELETE");
                            notify(
                                checked
                                    ? "Access granted."
                                    : "Direct assignment removed. Inherited access may still apply.",
                            );
                            await load(input.id);
                        } catch (error) {
                            input.checked = !checked;
                            throw error;
                        }
                    }, input),
                );
                results.append(
                    node("div", { class: "form-check" }, input, label),
                );
            }
            if (focusId) (document.getElementById(focusId) || status).focus();
        }
        status.tabIndex = -1;
        await load();
    }
    async function role(record) {
        const body = openDrawer(
            record.name,
            bool(record.is_system) ? "PROTECTED SYSTEM ROLE" : "CUSTOM ROLE",
        );
        body.append(node("p", { class: "muted-text" }, record.description));
        if (record.name === "super") {
            body.append(
                node(
                    "p",
                    { class: "alert alert-info" },
                    "This protected role passes every permission check, including future permissions.",
                ),
            );
            return;
        }
        body.append(
            node(
                "p",
                { class: "form-text" },
                bool(record.is_system)
                    ? "System role definitions and grants are protected. Create a custom role to tailor access."
                    : "Checkboxes represent direct grants. Permission groups may also provide inherited access.",
            ),
        );
        await browser(body, {
            kind: "permissions",
            title: "Permissions",
            grant: "role_permission",
            subject: record.id,
            disabled: bool(record.is_system),
        });
        if (!bool(record.is_system)) definitionActions(body, "roles", record);
    }
    async function permissions() {
        const body = openDrawer("Permission definitions", "ROLES & ACCESS");
        await browser(body, {
            kind: "permissions",
            title: "Permissions",
            choose: async (record) => {
                const surface = openDrawer(
                    record.name,
                    bool(record.is_system)
                        ? "PROTECTED SYSTEM PERMISSION"
                        : "CUSTOM PERMISSION",
                );
                surface.append(node("p", {}, record.description));
                if (!bool(record.is_system))
                    definitionActions(surface, "permissions", record);
            },
        });
    }
    async function load() {
        const container = document.getElementById("access-roles");
        container.replaceChildren();
        const surface = node("div", { class: "access-directory" });
        container.append(surface);
        surface.append(
            click(
                "Manage permission definitions",
                permissions,
                "btn btn-outline-primary mb-4",
            ),
        );
        await browser(surface, { kind: "roles", title: "Roles", choose: role });
    }
    async function person(container, userId) {
        container.append(
            node(
                "p",
                { class: "muted-text" },
                "Roles provide the baseline. Direct permissions add access. Removing a direct grant leaves access inherited through roles or groups intact.",
            ),
        );
        const roles = node(
            "section",
            { class: "record-section" },
            node("h3", {}, "Assigned roles"),
        );
        const permissions = node(
            "section",
            { class: "record-section" },
            node("h3", {}, "Additional individual permissions"),
        );
        container.append(roles, permissions);
        await browser(roles, {
            kind: "roles",
            title: "Roles",
            grant: "user_role",
            subject: userId,
        });
        await browser(permissions, {
            kind: "permissions",
            title: "Permissions",
            grant: "user_permission",
            subject: userId,
        });
    }
    return { load, role, permissions, person };
};
