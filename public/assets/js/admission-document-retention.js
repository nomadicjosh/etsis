/* Institutional retention setup and staff-only file classification. Private drafts stay in memory. */
(() => {
    "use strict";
    let sequence = 0;
    window.createAdmissionRetention = ({
        node,
        request,
        task,
        notify,
        labels = {},
    }) => {
        const text = (value) => labels[value] || value;
        const button = (label, run, style = "btn-outline-secondary") =>
            node(
                "button",
                {
                    type: "button",
                    class: `btn ${style}`,
                    onclick: (event) => task(run, event.currentTarget),
                },
                text(label),
            );
        const field = (label, control) =>
            node(
                "div",
                { class: "mb-3" },
                node(
                    "label",
                    { for: control.id, class: "form-label" },
                    text(label),
                ),
                control,
            );
        const input = (type, value = "", attributes = {}) =>
            node("input", {
                type,
                value,
                id: `retention-${++sequence}`,
                class: "form-control",
                ...attributes,
            });
        const stamp = (value) =>
            new Date(value).toLocaleString(undefined, {
                timeZoneName: "short",
            });
        const basis = (value) =>
            node(
                "p",
                { class: "text-break" },
                value
                    ? [
                          value.category
                              ? `${value.category.code} · ${value.category.title}`
                              : text("Deployment fallback"),
                          " · ",
                          value.days,
                          " ",
                          text("days from upload"),
                          ...(value.policy_id
                              ? [
                                    " · ",
                                    text("Policy revision"),
                                    " ",
                                    value.policy_revision,
                                ]
                              : []),
                      ]
                    : text("Earlier retention basis unknown"),
            );
        const title = (label) => node("h3", { class: "h6" }, text(label));
        function reviewed(
            form,
            fields,
            values,
            endpoint,
            {
                alive,
                write,
                status,
                complete,
                summary,
                denied,
                beforeRequest = () => true,
            },
        ) {
            let proof = null,
                pending = false;
            const preview = node("section", {
                class: "record-section",
                "aria-label": text("Retention review"),
                hidden: true,
            });
            const review = node(
                "button",
                { type: "submit", class: "btn btn-primary" },
                text("Review retention change"),
            );
            const actions = node(
                "div",
                { class: "record-form-actions" },
                review,
            );
            form.append(actions, preview);
            const initial = JSON.stringify(values());
            const guard = () => {
                if (
                    !alive() ||
                    (!pending && !proof && JSON.stringify(values()) === initial)
                )
                    return true;
                status.textContent = text(
                    "Finish or discard retention changes before starting another task.",
                );
                (proof
                    ? preview.querySelector("button")
                    : fields.querySelector("input,select,textarea")
                )?.focus();
                return false;
            };
            async function run(action) {
                if (pending) return;
                pending = true;
                fields.disabled = true;
                review.disabled = true;
                try {
                    await (write ? write(action) : action());
                } catch (error) {
                    if (alive()) {
                        status.textContent = text(
                            "The request did not finish. Your review is still available.",
                        );
                        if (error.status === 403) {
                            if (denied) denied();
                            else form.replaceChildren();
                            status.textContent = text(
                                "Retention access is no longer available.",
                            );
                        }
                    }
                    throw error;
                } finally {
                    pending = false;
                    fields.disabled = !!proof;
                    review.disabled = false;
                }
            }
            form.addEventListener("submit", (event) => {
                event.preventDefault();
                if (!beforeRequest() || !form.reportValidity() || proof) return;
                task(async () => {
                    await run(async () => {
                        status.textContent = text(
                            "Reviewing retention change…",
                        );
                        proof = await request(
                            `${endpoint}review/`,
                            "POST",
                            values(),
                        );
                        if (!alive()) return;
                        const accepted = proof;
                        review.hidden = true;
                        preview.hidden = false;
                        preview.replaceChildren(
                            ...summary(accepted),
                            node(
                                "div",
                                { class: "record-form-actions" },
                                button(
                                    "Confirm retention change",
                                    () =>
                                        beforeRequest() &&
                                        run(async () => {
                                            if (!alive() || proof !== accepted)
                                                return;
                                            await request(
                                                `${endpoint}confirm/`,
                                                "POST",
                                                accepted,
                                            );
                                            if (alive()) {
                                                await complete();
                                                proof = null;
                                                notify(
                                                    text(
                                                        "Retention change saved.",
                                                    ),
                                                );
                                            }
                                        }),
                                    "btn-primary",
                                ),
                                button("Revise retention change", async () => {
                                    if (pending || !alive()) return;
                                    proof = null;
                                    fields.disabled = false;
                                    preview.replaceChildren();
                                    preview.hidden = true;
                                    review.hidden = false;
                                    fields.querySelector("textarea")?.focus();
                                }),
                            ),
                        );
                        status.textContent = text(
                            "Review ready. Confirm or revise this change.",
                        );
                    });
                    await new Promise((resolve) =>
                        requestAnimationFrame(resolve),
                    );
                    if (alive()) {
                        const confirm = preview.querySelector("button");
                        confirm?.focus({ preventScroll: true });
                        confirm?.scrollIntoView({
                            block: "center",
                            inline: "nearest",
                        });
                    }
                }, review);
            });
            return { beforeLeave: guard, pending: () => pending, actions };
        }
        function history(
            container,
            endpoint,
            status,
            { alive, write, beforeChange, policy = false, denied },
        ) {
            let opened = false,
                cursor = null,
                trail = [];
            const content = node("section", {
                hidden: true,
                id: `retention-history-${++sequence}`,
                "aria-label": text("Retained retention decisions"),
            });
            const toggle = button(
                policy ? "Earlier policies" : "Classification history",
                async () => {
                    if (!beforeChange()) return;
                    if (opened) {
                        opened = false;
                        content.hidden = true;
                        toggle.setAttribute("aria-expanded", "false");
                        return;
                    }
                    await load(null, []);
                },
            );
            toggle.setAttribute("aria-controls", content.id);
            toggle.setAttribute("aria-expanded", "false");
            async function load(next, previous) {
                if (!beforeChange()) return;
                const action = async () => {
                    status.textContent = text("Loading retention history…");
                    const data = await request(
                        `${endpoint}${next === null ? "" : `?before=${encodeURIComponent(next)}`}`,
                    );
                    if (!alive()) return;
                    cursor = next;
                    trail = previous;
                    opened = true;
                    content.hidden = false;
                    toggle.setAttribute("aria-expanded", "true");
                    const list = node("div");
                    for (const record of data.items) {
                        const card = node(
                            "article",
                            { class: "border-top pt-3 mt-3" },
                            title(
                                policy
                                    ? "Retained policy"
                                    : record.source === "upload"
                                      ? "Initial upload policy"
                                      : "Staff classification",
                            ),
                            node(
                                "p",
                                { class: "form-text text-break" },
                                stamp(record.recorded_at),
                                " · ",
                                record.actor_name ||
                                    text("Unavailable account"),
                            ),
                        );
                        if (policy) {
                            card.append(
                                node(
                                    "p",
                                    {},
                                    text("Policy revision"),
                                    " ",
                                    record.revision,
                                ),
                            );
                            for (const category of record.definition.categories)
                                card.append(
                                    node(
                                        "p",
                                        { class: "text-break" },
                                        category.code,
                                        " · ",
                                        category.title,
                                        " · ",
                                        category.days,
                                        " ",
                                        text("days from upload"),
                                        category.active
                                            ? ""
                                            : ` · ${text("Retired")}`,
                                    ),
                                );
                        } else
                            card.append(
                                basis(record.before),
                                basis(record.after),
                                node(
                                    "p",
                                    { class: "form-text" },
                                    text("Retain through"),
                                    " ",
                                    stamp(record.expires_after),
                                ),
                            );
                        if (record.reason)
                            card.append(
                                node(
                                    "p",
                                    { class: "private-text text-break" },
                                    record.reason,
                                ),
                            );
                        list.append(card);
                    }
                    if (!data.items.length)
                        list.append(
                            node(
                                "p",
                                {},
                                text(
                                    "Earlier retention decisions were not recorded.",
                                ),
                            ),
                        );
                    const paging = node("div", {
                        class: "record-form-actions",
                    });
                    if (trail.length)
                        paging.append(
                            button("Newer decisions", () =>
                                load(trail.at(-1), trail.slice(0, -1)),
                            ),
                        );
                    if (data.next_cursor !== null)
                        paging.append(
                            button("Earlier decisions", () =>
                                load(data.next_cursor, [...trail, cursor]),
                            ),
                        );
                    content.replaceChildren(list, paging);
                    status.textContent = text("Retention history loaded.");
                };
                try {
                    if (write) await write(action);
                    else await action();
                } catch (error) {
                    if (alive() && error.status === 403) {
                        if (denied) denied();
                        else container.replaceChildren();
                        status.textContent = text(
                            "Retention access is no longer available.",
                        );
                    }
                    throw error;
                }
            }
            container.append(
                node("div", { class: "record-form-actions" }, toggle),
                content,
            );
        }
        function mountPolicy(container) {
            let editor = null,
                loading = false,
                generation = 0;
            const status = node("p", {
                role: "status",
                "aria-atomic": "true",
                class: "form-text",
            });
            const body = node("div", { class: "retention-policy-panel" });
            container.append(status, body);
            const current = (ticket) =>
                container.isConnected && ticket === generation;
            const guard = () => !loading && (editor?.beforeLeave() ?? true);
            async function load() {
                const ticket = ++generation;
                loading = true;
                status.textContent = text("Loading document retention…");
                try {
                    const data = await request("/sis/document-retention/");
                    if (!current(ticket)) return;
                    render(data, ticket);
                } catch (error) {
                    if (current(ticket)) {
                        status.textContent = text(
                            "Document retention could not be loaded.",
                        );
                        if (error.status === 403) body.replaceChildren();
                    }
                    throw error;
                } finally {
                    loading = false;
                }
            }
            function render(data, ticket) {
                const form = node("form", { class: "record-form" });
                const fields = node("fieldset", {
                    class: "retention-policy-fields",
                });
                const entries = node("div"),
                    choices = [],
                    ids = [...data.new_ids];
                const defaultInput = node("select", {
                    id: `retention-default-${++sequence}`,
                    class: "form-select",
                });
                const reason = node("textarea", {
                    id: `retention-reason-${++sequence}`,
                    class: "form-control",
                    rows: 2,
                    maxlength: 1000,
                    required: true,
                });
                function defaults(selected = defaultInput.value) {
                    defaultInput.replaceChildren(
                        node(
                            "option",
                            { value: "" },
                            text("Deployment fallback (uncategorized)"),
                        ),
                        ...choices
                            .filter((choice) => choice.active.checked)
                            .map((choice) =>
                                node(
                                    "option",
                                    { value: choice.id },
                                    choice.code.value || text("New category"),
                                    " · ",
                                    choice.name.value ||
                                        text("Untitled category"),
                                ),
                            ),
                    );
                    defaultInput.value = choices.some(
                        (choice) =>
                            choice.id === selected && choice.active.checked,
                    )
                        ? selected
                        : "";
                }
                function add(category, existing = false) {
                    const code = input("text", category.code, {
                        required: true,
                        maxlength: 30,
                        pattern: "[A-Z](?:[A-Z0-9._]|-){0,29}",
                        readOnly: existing,
                    });
                    const name = input("text", category.title, {
                        required: true,
                        maxlength: 120,
                    });
                    const days = input("number", category.days, {
                        required: true,
                        min: 1,
                        max: 3650,
                        step: 1,
                    });
                    const active = input("checkbox", "", {
                        checked: category.active,
                        class: "form-check-input",
                    });
                    const item = { id: category.id, code, name, days, active };
                    const card = node(
                        "fieldset",
                        { class: "record-form-section retention-category" },
                        node("legend", {}, text("Retention category")),
                        node(
                            "div",
                            { class: "row g-3" },
                            node(
                                "div",
                                { class: "col-12 col-md-4" },
                                field("Category code", code),
                            ),
                            node(
                                "div",
                                { class: "col-12 col-md-8" },
                                field("Category name", name),
                            ),
                        ),
                        field("Retention days from upload", days),
                        node(
                            "div",
                            { class: "form-check" },
                            active,
                            node(
                                "label",
                                { class: "form-check-label", for: active.id },
                                text("Available for new selections"),
                            ),
                        ),
                    );
                    for (const control of [code, name])
                        control.addEventListener("input", () => defaults());
                    active.addEventListener("change", () => defaults());
                    if (!existing)
                        card.append(
                            button(
                                "Remove unsaved category",
                                async () => {
                                    choices.splice(choices.indexOf(item), 1);
                                    ids.push(item.id);
                                    card.remove();
                                    defaults();
                                },
                                "btn-link",
                            ),
                        );
                    choices.push(item);
                    entries.append(card);
                    defaults();
                    return code;
                }
                for (const category of data.definition.categories)
                    add(category, true);
                defaults(data.definition.default_id || "");
                const addButton = button("Add retention category", async () => {
                    if (!ids.length) {
                        status.textContent = text(
                            "The 25-category limit is reached. Existing identities must be retained.",
                        );
                        return;
                    }
                    add({
                        id: ids.shift(),
                        code: "",
                        title: "",
                        days: "",
                        active: true,
                    }).focus();
                });
                fields.append(
                    node(
                        "p",
                        { class: "form-text" },
                        text(
                            "Categories set future upload defaults. Existing files keep their recorded basis and deadlines.",
                        ),
                    ),
                    node(
                        "p",
                        { class: "form-text" },
                        text(
                            "Upload availability and scanner approval remain deployment controls.",
                        ),
                    ),
                    field("Default for new uploads", defaultInput),
                    node(
                        "p",
                        { class: "form-text" },
                        text("Deployment fallback days"),
                        ": ",
                        data.deployment_days,
                    ),
                    entries,
                    node("div", { class: "record-form-actions" }, addButton),
                    field("Reason for policy change", reason),
                );
                form.append(fields);
                body.replaceChildren(form);
                editor = reviewed(
                    form,
                    fields,
                    () => ({
                        revision: data.revision,
                        reason: reason.value,
                        definition: {
                            default_id: defaultInput.value || null,
                            categories: choices.map((choice) => ({
                                id: choice.id,
                                code: choice.code.value,
                                title: choice.name.value,
                                days: Number(choice.days.value),
                                active: choice.active.checked,
                            })),
                        },
                    }),
                    "/sis/document-retention/",
                    {
                        alive: () => current(ticket) && form.isConnected,
                        status,
                        complete: load,
                        denied: () => body.replaceChildren(),
                        summary: (proof) => [
                            title("Review institutional retention"),
                            node(
                                "p",
                                {},
                                text(
                                    "Existing file deadlines and holds will not change.",
                                ),
                            ),
                            node(
                                "p",
                                { class: "text-break" },
                                text("Default for new uploads"),
                                ": ",
                                proof.definition.default_id === null
                                    ? text(
                                          "Deployment fallback (uncategorized)",
                                      )
                                    : proof.definition.categories.find(
                                          (category) =>
                                              category.id ===
                                              proof.definition.default_id,
                                      ).title,
                            ),
                            ...proof.definition.categories.map((category) =>
                                node(
                                    "p",
                                    { class: "text-break" },
                                    category.code,
                                    " · ",
                                    category.title,
                                    " · ",
                                    category.days,
                                    " ",
                                    text("days from upload"),
                                    category.id === proof.definition.default_id
                                        ? ` · ${text("Default")}`
                                        : "",
                                    category.active
                                        ? ""
                                        : ` · ${text("Retired")}`,
                                ),
                            ),
                            node(
                                "p",
                                { class: "private-text text-break" },
                                proof.reason,
                            ),
                        ],
                    },
                );
                editor.actions.append(
                    button(
                        "Discard retention changes",
                        async () => {
                            if (!editor.pending()) render(data, ticket);
                        },
                        "btn-link",
                    ),
                );
                const extra = node("div");
                body.append(extra);
                history(extra, "/sis/document-retention/history/", status, {
                    alive: () => current(ticket) && form.isConnected,
                    beforeChange: guard,
                    policy: true,
                    denied: () => body.replaceChildren(),
                });
                extra.append(
                    button("Refresh retention policy", async () => {
                        if (guard()) await load();
                    }),
                );
                status.textContent = text("Current policy revision");
                status.append(" ", data.revision);
            }
            const refresh = button("Retry document retention", load);
            body.append(refresh);
            task(load, refresh);
            return { beforeLeave: guard };
        }
        function mountFile(
            container,
            application,
            item,
            {
                alive,
                write,
                reload,
                beforeChange,
                beforeRequest = () => true,
                status,
            },
        ) {
            const base = `/sis/applications/${application}/documents/${item.id}/category/`;
            let editor = null,
                editing = false,
                epoch = 0;
            const section = node(
                "section",
                { class: "record-section document-retention-section" },
                title("Document category"),
                basis(item.retention_basis),
            );
            const formArea = node("div");
            const guard = () => !editing || (editor?.beforeLeave() ?? false);
            if (!["purging", "purged"].includes(item.status))
                section.append(
                    button("Classify document", async () => {
                        if (!beforeChange()) return;
                        editing = true;
                        const ticket = ++epoch;
                        await write(async () => {
                            const data = await request(
                                "/sis/document-retention/",
                            );
                            if (
                                !alive() ||
                                ticket !== epoch ||
                                !section.isConnected
                            )
                                return;
                            const form = node("form", { class: "record-form" });
                            const fields = node("fieldset");
                            const category = node(
                                "select",
                                {
                                    id: `retention-category-${++sequence}`,
                                    class: "form-select",
                                    required: true,
                                },
                                node(
                                    "option",
                                    { value: "" },
                                    text("Choose a retention category"),
                                ),
                                ...data.definition.categories
                                    .filter((choice) => choice.active)
                                    .map((choice) =>
                                        node(
                                            "option",
                                            { value: choice.id },
                                            choice.code,
                                            " · ",
                                            choice.title,
                                        ),
                                    ),
                            );
                            category.value =
                                item.retention_basis?.category?.id || "";
                            const reason = node("textarea", {
                                id: `retention-category-reason-${++sequence}`,
                                class: "form-control",
                                maxlength: 1000,
                                rows: 2,
                                required: true,
                            });
                            fields.append(
                                field("Retention category", category),
                                field("Reason for classification", reason),
                                node(
                                    "p",
                                    { class: "form-text" },
                                    text(
                                        "Days run from the original upload. A shorter category never reduces the existing deadline.",
                                    ),
                                ),
                            );
                            form.append(fields);
                            formArea.replaceChildren(form);
                            const current = () =>
                                alive() &&
                                section.isConnected &&
                                ticket === epoch;
                            editor = reviewed(
                                form,
                                fields,
                                () => ({
                                    version: item.version,
                                    category_id: category.value,
                                    reason: reason.value,
                                }),
                                base,
                                {
                                    alive: current,
                                    write,
                                    status,
                                    complete: reload,
                                    beforeRequest,
                                    summary: (proof) => [
                                        title("Review document classification"),
                                        basis(proof.before),
                                        basis(proof.after),
                                        node(
                                            "p",
                                            {},
                                            text("Current deadline"),
                                            ": ",
                                            stamp(proof.expires_before),
                                        ),
                                        node(
                                            "p",
                                            {},
                                            text("Proposed deadline"),
                                            ": ",
                                            stamp(proof.expires_after),
                                        ),
                                        node(
                                            "p",
                                            { class: "form-text" },
                                            text(
                                                "File access, existing holds and decision evidence stay unchanged.",
                                            ),
                                        ),
                                        node(
                                            "p",
                                            {
                                                class: "private-text text-break",
                                            },
                                            proof.reason,
                                        ),
                                    ],
                                },
                            );
                            editor.actions.append(
                                button(
                                    "Cancel classification",
                                    async () => {
                                        if (editor.pending()) return;
                                        epoch++;
                                        editing = false;
                                        editor = null;
                                        formArea.replaceChildren();
                                    },
                                    "btn-link",
                                ),
                            );
                        });
                        if (alive()) formArea.querySelector("select")?.focus();
                    }),
                );
            section.append(formArea);
            history(section, base, status, {
                alive: () => alive() && section.isConnected,
                write,
                beforeChange,
            });
            container.append(section);
            return { beforeLeave: guard };
        }
        return { mountPolicy, mountFile };
    };
})();
