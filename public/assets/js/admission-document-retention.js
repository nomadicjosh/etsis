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
        const fallbackLabels = {
            add_category: "Add retention category",
            category_available: "Available for new selections",
            cancel_classification: "Cancel classification",
            categories_help: "Categories set future upload defaults. Existing files keep their recorded basis and deadlines.",
            category_code: "Category code",
            category_name: "Category name",
            choose_category: "Choose a retention category",
            classification_history: "Classification history",
            classify_document: "Classify document",
            confirm_change: "Confirm retention change",
            current_deadline: "Current deadline",
            current_revision: "Current policy revision",
            classification_help: "Days run from the original upload. A shorter category never reduces the existing deadline.",
            default: "Default",
            upload_default: "Default for new uploads",
            fallback: "Deployment fallback",
            uncategorized_fallback: "",
            fallback_days: "Deployment fallback days",
            discard_changes: "Discard retention changes",
            document_category: "Document category",
            load_failed: "Document retention could not be loaded.",
            earlier_decisions: "Earlier decisions",
            earlier_policies: "Earlier policies",
            unknown_basis: "Earlier retention basis unknown",
            history_empty: "Earlier retention decisions were not recorded.",
            existing_files_help: "Existing file deadlines and holds will not change.",
            file_access_help: "File access, existing holds and decision evidence stay unchanged.",
            finish_changes: "Finish or discard retention changes before starting another task.",
            upload_policy: "Initial upload policy",
            loading_retention: "Loading document retention…",
            loading_history: "Loading retention history…",
            new_category: "New category",
            newer_decisions: "Newer decisions",
            policy_revision: "Policy revision",
            proposed_deadline: "Proposed deadline",
            classification_reason: "Reason for classification",
            policy_reason: "Reason for policy change",
            refresh_policy: "Refresh retention policy",
            remove_category: "Remove unsaved category",
            retain_through: "Retain through",
            retained_policy: "Retained policy",
            retained_decisions: "Retained retention decisions",
            access_unavailable: "Retention access is no longer available.",
            retention_category: "Retention category",
            saved: "Retention change saved.",
            retention_days: "Retention days from upload",
            history_loaded: "Retention history loaded.",
            review_heading: "Retention review",
            retired: "Retired",
            retry_retention: "Retry document retention",
            review_classification: "Review document classification",
            review_policy: "Review institutional retention",
            review_ready: "Review ready. Confirm or revise this change.",
            review_change: "Review retention change",
            reviewing: "Reviewing retention change…",
            revise_change: "Revise retention change",
            staff_classification: "Staff classification",
            category_limit: "The 25-category limit is reached. Existing identities must be retained.",
            request_incomplete: "The request did not finish. Your review is still available.",
            unavailable_account: "Unavailable account",
            untitled_category: "Untitled category",
            upload_help: "Upload availability and scanner approval remain deployment controls.",
            days_from_upload: "days from upload",
        };
        const text = key => labels[key] || fallbackLabels[key];
        const button = (key, run, style = "btn-outline-secondary") =>
            node(
                "button",
                {
                    type: "button",
                    class: `btn ${style}`,
                    onclick: (event) => task(run, event.currentTarget),
                },
                text(key),
            );
        const field = (key, control) =>
            node(
                "div",
                { class: "mb-3" },
                node(
                    "label",
                    { for: control.id, class: "form-label" },
                    text(key),
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
                              : text("fallback"),
                          " · ",
                          value.days,
                          " ",
                          text("days_from_upload"),
                          ...(value.policy_id
                              ? [
                                    " · ",
                                    text("policy_revision"),
                                    " ",
                                    value.policy_revision,
                                ]
                              : []),
                      ]
                    : text("unknown_basis"),
            );
        const title = (key) => node("h3", { class: "h6" }, text(key));
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
                "aria-label": text("review_heading"),
                hidden: true,
            });
            const review = node(
                "button",
                { type: "submit", class: "btn btn-primary" },
                text("review_change"),
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
                    "finish_changes",
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
                            "request_incomplete",
                        );
                        if (error.status === 403) {
                            if (denied) denied();
                            else form.replaceChildren();
                            status.textContent = text(
                                "access_unavailable",
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
                            "reviewing",
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
                                    "confirm_change",
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
                                                        "saved",
                                                    ),
                                                );
                                            }
                                        }),
                                    "btn-primary",
                                ),
                                button("revise_change", async () => {
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
                            "review_ready",
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
                "aria-label": text("retained_decisions"),
            });
            const toggle = button(
                policy ? "earlier_policies" : "classification_history",
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
                    status.textContent = text("loading_history");
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
                                    ? "retained_policy"
                                    : record.source === "upload"
                                      ? "upload_policy"
                                      : "staff_classification",
                            ),
                            node(
                                "p",
                                { class: "form-text text-break" },
                                stamp(record.recorded_at),
                                " · ",
                                record.actor_name ||
                                    text("unavailable_account"),
                            ),
                        );
                        if (policy) {
                            card.append(
                                node(
                                    "p",
                                    {},
                                    text("policy_revision"),
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
                                        text("days_from_upload"),
                                        category.active
                                            ? ""
                                            : ` · ${text("retired")}`,
                                    ),
                                );
                        } else
                            card.append(
                                basis(record.before),
                                basis(record.after),
                                node(
                                    "p",
                                    { class: "form-text" },
                                    text("retain_through"),
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
                                    "history_empty",
                                ),
                            ),
                        );
                    const paging = node("div", {
                        class: "record-form-actions",
                    });
                    if (trail.length)
                        paging.append(
                            button("newer_decisions", () =>
                                load(trail.at(-1), trail.slice(0, -1)),
                            ),
                        );
                    if (data.next_cursor !== null)
                        paging.append(
                            button("earlier_decisions", () =>
                                load(data.next_cursor, [...trail, cursor]),
                            ),
                        );
                    content.replaceChildren(list, paging);
                    status.textContent = text("history_loaded");
                };
                try {
                    if (write) await write(action);
                    else await action();
                } catch (error) {
                    if (alive() && error.status === 403) {
                        if (denied) denied();
                        else container.replaceChildren();
                        status.textContent = text(
                            "access_unavailable",
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
                status.textContent = text("loading_retention");
                try {
                    const data = await request("/sis/document-retention/");
                    if (!current(ticket)) return;
                    render(data, ticket);
                } catch (error) {
                    if (current(ticket)) {
                        status.textContent = text(
                            "load_failed",
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
                            text("uncategorized_fallback"),
                        ),
                        ...choices
                            .filter((choice) => choice.active.checked)
                            .map((choice) =>
                                node(
                                    "option",
                                    { value: choice.id },
                                    choice.code.value || text("new_category"),
                                    " · ",
                                    choice.name.value ||
                                        text("untitled_category"),
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
                        node("legend", {}, text("retention_category")),
                        node(
                            "div",
                            { class: "row g-3" },
                            node(
                                "div",
                                { class: "col-12 col-md-4" },
                                field("category_code", code),
                            ),
                            node(
                                "div",
                                { class: "col-12 col-md-8" },
                                field("category_name", name),
                            ),
                        ),
                        field("retention_days", days),
                        node(
                            "div",
                            { class: "form-check" },
                            active,
                            node(
                                "label",
                                { class: "form-check-label", for: active.id },
                                text("category_available"),
                            ),
                        ),
                    );
                    for (const control of [code, name])
                        control.addEventListener("input", () => defaults());
                    active.addEventListener("change", () => defaults());
                    if (!existing)
                        card.append(
                            button(
                                "remove_category",
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
                const addButton = button("add_category", async () => {
                    if (!ids.length) {
                        status.textContent = text(
                            "category_limit",
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
                            "categories_help",
                        ),
                    ),
                    node(
                        "p",
                        { class: "form-text" },
                        text(
                            "upload_help",
                        ),
                    ),
                    field("upload_default", defaultInput),
                    node(
                        "p",
                        { class: "form-text" },
                        text("fallback_days"),
                        ": ",
                        data.deployment_days,
                    ),
                    entries,
                    node("div", { class: "record-form-actions" }, addButton),
                    field("policy_reason", reason),
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
                            title("review_policy"),
                            node(
                                "p",
                                {},
                                text(
                                    "existing_files_help",
                                ),
                            ),
                            node(
                                "p",
                                { class: "text-break" },
                                text("upload_default"),
                                ": ",
                                proof.definition.default_id === null
                                    ? text(
                                          "uncategorized_fallback",
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
                                    text("days_from_upload"),
                                    category.id === proof.definition.default_id
                                        ? ` · ${text("default")}`
                                        : "",
                                    category.active
                                        ? ""
                                        : ` · ${text("retired")}`,
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
                        "discard_changes",
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
                    button("refresh_policy", async () => {
                        if (guard()) await load();
                    }),
                );
                status.textContent = text("current_revision");
                status.append(" ", data.revision);
            }
            const refresh = button("retry_retention", load);
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
                title("document_category"),
                basis(item.retention_basis),
            );
            const formArea = node("div");
            const guard = () => !editing || (editor?.beforeLeave() ?? false);
            if (!["purging", "purged"].includes(item.status))
                section.append(
                    button("classify_document", async () => {
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
                                    text("choose_category"),
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
                                field("retention_category", category),
                                field("classification_reason", reason),
                                node(
                                    "p",
                                    { class: "form-text" },
                                    text(
                                        "classification_help",
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
                                        title("review_classification"),
                                        basis(proof.before),
                                        basis(proof.after),
                                        node(
                                            "p",
                                            {},
                                            text("current_deadline"),
                                            ": ",
                                            stamp(proof.expires_before),
                                        ),
                                        node(
                                            "p",
                                            {},
                                            text("proposed_deadline"),
                                            ": ",
                                            stamp(proof.expires_after),
                                        ),
                                        node(
                                            "p",
                                            { class: "form-text" },
                                            text(
                                                "file_access_help",
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
                                    "cancel_classification",
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
