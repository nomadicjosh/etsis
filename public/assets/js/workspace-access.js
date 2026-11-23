/* Bounded access editors: one definition page and its assignment state per request. */
window.createWorkspaceAccess = function (ui) {
    "use strict";
    const { node, request, task, openDrawer, badge, notify, definitionActions, offices } = ui;
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
            initial = null,
            remember = () => {},
            onDenied = null,
        },
    ) {
        const unique = ++serial;
        let after = initial?.after || "";
        let query = initial?.query || "";
        let trail = [...(initial?.trail || [])];
        let assignedOnly = Boolean(subject && initial?.assignedOnly);
        let requestVersion = 0;
        let busy = false;
        const snapshot = () => ({ after, query, trail: [...trail], assignedOnly });
        const denied = () => {
            if (onDenied) onDenied();
            else {
                container.replaceChildren(
                    node(
                        "p",
                        { role: "status" },
                        "Access is no longer available. Reopen this workspace after your access has been restored.",
                    ),
                );
                if (container.closest("#record-drawer")) {
                    document.getElementById("drawer-title").textContent = "Access unavailable";
                    const location = document.querySelector("#record-drawer .record-task-location");
                    if (location) location.textContent = "Access unavailable";
                }
            }
            document
                .getElementById("access-roles")
                ?.replaceChildren(
                    node(
                        "p",
                        { role: "status" },
                        "This access view is no longer available. Reopen Roles & access to check your current access.",
                    ),
                );
        };
        const search = node("input", {
            id: `access-search-${unique}`,
            type: "search",
            class: "form-control",
            maxlength: 80,
            value: query,
        });
        const status = node(
            "p",
            {
                id: `access-status-${unique}`,
                class: "form-text",
                role: "status",
                "aria-live": "polite",
            },
            "Loading definitions…",
        );
        const assigned = node("input", {
            type: "checkbox",
            id: `access-assigned-${unique}`,
            class: "form-check-input",
            checked: assignedOnly,
        });
        const readButton = (label, callback, style = "btn-outline-primary") =>
            node(
                "button",
                {
                    type: "button",
                    class: `btn ${style}`,
                    onclick: () => task(callback),
                },
                label,
            );
        const find = node("button", { type: "submit", class: "btn btn-primary" }, "Find");
        const clear = readButton(
            "Clear search",
            () => {
                search.value = "";
                return load(search.id, { ...snapshot(), query: "", after: "", trail: [] });
            },
            "btn-outline-secondary",
        );
        const refresh = readButton(
            "Refresh",
            () => load(status.id, snapshot()),
            "btn-outline-secondary",
        );
        refresh.setAttribute("aria-label", `Refresh ${title.toLowerCase()}`);
        const form = node(
            "form",
            { class: "access-browser-search mb-3" },
            node("label", { class: "form-label", for: search.id }, `Search ${title.toLowerCase()}`),
            node(
                "div",
                { class: "access-browser-search-controls" },
                search,
                node("div", { class: "inline-actions" }, find, clear, refresh),
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
        const results = node(choose ? "div" : "fieldset", {
            class: choose
                ? kind === "permissions"
                    ? "access-definition-list"
                    : "role-grid access-definition-grid"
                : "permission-picker",
        });
        const previous = readButton("Previous page", () =>
            load(status.id, {
                ...snapshot(),
                after: trail.at(-1) || "",
                trail: trail.slice(0, -1),
            }),
        );
        const next = readButton("Next page", () =>
            load(status.id, { ...snapshot(), after: next.dataset.after, trail: [...trail, after] }),
        );
        previous.disabled = true;
        next.disabled = true;
        if (!choose && !disabled)
            container.append(
                node(
                    "p",
                    { class: "form-text" },
                    "Direct assignment changes save immediately. Removing a direct grant can leave inherited access in place.",
                ),
            );
        container.append(
            form,
            status,
            node(
                "nav",
                { class: "inline-actions mb-3", "aria-label": `${title} pages` },
                previous,
                next,
            ),
            results,
        );
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            if (busy) return;
            task(() =>
                load(status.id, {
                    ...snapshot(),
                    query: search.value.trim(),
                    after: "",
                    trail: [],
                }),
            );
        });
        assigned.addEventListener("change", () => {
            if (busy) return;
            task(() =>
                load(status.id, {
                    ...snapshot(),
                    assignedOnly: assigned.checked,
                    after: "",
                    trail: [],
                }),
            );
        });
        function controls(pending) {
            busy = pending;
            find.disabled = pending;
            refresh.disabled = pending;
            clear.disabled = pending || (!search.value && !query);
            previous.disabled = pending || !trail.length;
            next.disabled = pending || !next.dataset.after;
            assigned.disabled = pending;
            if (choose) results.inert = pending;
            else results.disabled = pending || disabled;
        }
        search.addEventListener("input", () => {
            clear.disabled = busy || (!search.value && !query);
        });
        async function load(focusId = null, candidate = snapshot()) {
            const version = ++requestVersion;
            controls(true);
            results.setAttribute("aria-busy", "true");
            let page;
            try {
                page = await request(`/sis/access/directory/${kind}/`, "POST", {
                    search: candidate.query,
                    after: candidate.after,
                    limit: 50,
                    grant,
                    subject,
                    assigned_only: Boolean(subject && candidate.assignedOnly),
                });
            } catch (error) {
                if (version === requestVersion && container.isConnected) {
                    if (error.status === 403) denied();
                    else {
                        assigned.checked = assignedOnly;
                        status.textContent =
                            "Definitions could not be refreshed. The previous page remains displayed. Try again.";
                    }
                }
                throw error;
            } finally {
                if (version === requestVersion && container.isConnected) {
                    controls(false);
                    results.removeAttribute("aria-busy");
                }
            }
            if (version !== requestVersion || !container.isConnected) return;
            after = candidate.after;
            query = candidate.query;
            trail = [...candidate.trail];
            assignedOnly = Boolean(subject && candidate.assignedOnly);
            assigned.checked = assignedOnly;
            remember(snapshot());
            results.replaceChildren();
            if (!choose) results.append(node("legend", { class: "visually-hidden" }, title));
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
                    const card = click(item.name, () => choose(item, snapshot()), "role-card");
                    if (kind === "permissions") {
                        card.classList.add("access-permission-definition");
                        card.replaceChildren(
                            node(
                                "span",
                                { class: "access-definition-identity" },
                                node("strong", { class: "access-identifier" }, item.name),
                                node("small", {}, item.description || "No description provided."),
                            ),
                            node(
                                "span",
                                { class: "access-definition-type" },
                                badge(bool(item.is_system) ? "System" : "Custom"),
                            ),
                            node("i", {
                                class: "bi bi-chevron-right access-definition-arrow",
                                "aria-hidden": "true",
                            }),
                        );
                        results.append(card);
                        continue;
                    }
                    card.replaceChildren(
                        badge(bool(item.is_system) ? "System" : "Custom"),
                        node("strong", { class: "access-identifier" }, item.name),
                        node("small", {}, item.description || "No description provided."),
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
                    node("span", { class: "access-identifier" }, item.name),
                    node("small", {}, item.description),
                );
                if (bool(item.assigned))
                    label.append(node("span", { class: "access-grant-state" }, badge("Direct")));
                if (bool(item.effective) && !bool(item.assigned))
                    label.append(
                        node("span", { class: "access-grant-state" }, badge("Inherited")),
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
                            if (!container.isConnected) return;
                            notify(
                                checked
                                    ? "Access granted."
                                    : "Direct assignment removed. Inherited access may still apply.",
                            );
                            await load(input.id);
                        } catch (error) {
                            input.checked = !checked;
                            if (error.status === 403 && container.isConnected) denied();
                            throw error;
                        }
                    }, input),
                );
                results.append(node("div", { class: "form-check access-grant" }, input, label));
            }
            controls(false);
            if (focusId) (document.getElementById(focusId) || status).focus();
        }
        status.tabIndex = -1;
        await load();
    }
    async function role(record, initial = null) {
        let view = initial;
        const back = () => role(record, view);
        const body = openDrawer(
            record.name,
            bool(record.is_system) ? "PROTECTED SYSTEM ROLE" : "CUSTOM ROLE",
            { record: { key: `access-role:${record.id}`, label: record.name, run: back } },
        );
        body.append(
            node(
                "div",
                { class: "access-record-context" },
                badge(bool(record.is_system) ? "Protected system role" : "Custom role"),
            ),
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
        if (!bool(record.is_system)) definitionActions(body, "roles", record, { back });
        await browser(body, {
            kind: "permissions",
            title: "Permissions",
            grant: "role_permission",
            subject: record.id,
            disabled: bool(record.is_system),
            initial: view,
            remember: (state) => {
                view = state;
            },
        });
    }
    async function permission(record, state) {
        const back = () => permission(record, state);
        const surface = openDrawer(
            record.name,
            bool(record.is_system) ? "PROTECTED SYSTEM PERMISSION" : "CUSTOM PERMISSION",
            { record: { key: `access-permission:${record.id}`, label: record.name, run: back } },
        );
        surface.append(
            click(
                "Back to permission definitions",
                () => permissions(state),
                "btn btn-outline-secondary",
            ),
            node(
                "div",
                { class: "access-record-context" },
                badge(bool(record.is_system) ? "Protected system permission" : "Custom permission"),
            ),
            node("p", { class: "muted-text" }, record.description || "No description provided."),
        );
        if (!bool(record.is_system))
            definitionActions(surface, "permissions", record, {
                back,
                complete: () => permissions(state),
            });
    }
    async function permissions(initial = null) {
        const body = openDrawer("Permission definitions", "ROLES & ACCESS", { record: null });
        await browser(body, {
            kind: "permissions",
            title: "Permissions",
            initial,
            choose: permission,
        });
    }
    async function load() {
        const container = document.getElementById("access-roles");
        container.replaceChildren();
        const surface = node("div", { class: "access-directory" });
        container.append(surface);
        surface.append(
            node(
                "div",
                { class: "inline-actions mb-4", role: "group", "aria-label": "Access setup" },
                click("Permission definitions", permissions),
                click("Offices & delegation", offices, "btn btn-outline-secondary"),
            ),
        );
        await browser(surface, { kind: "roles", title: "Roles", choose: (record) => role(record) });
    }
    async function person(container, userId) {
        const onDenied = () =>
            container.replaceChildren(
                node(
                    "p",
                    { role: "status" },
                    "Access is no longer available. Reopen this person workspace after your access has been restored.",
                ),
            );
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
            onDenied,
        });
        if (!container.isConnected) return;
        await browser(permissions, {
            kind: "permissions",
            title: "Permissions",
            grant: "user_permission",
            subject: userId,
            onDenied,
        });
    }
    return { load, role, permissions, person };
};
