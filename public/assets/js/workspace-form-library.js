/* Bounded live metadata search. Filtering and paging never replace the open draft. */
"use strict";
/* Display-only preferences. Never accept or serialize a form definition or answers. */
window.createApplicationFormPreferences = function () {
    "use strict";
    const key = "etsis.form-library.collapses";
    const buckets = ["fields", "settings", "containers"];
    let memory = { forms: {} },
        available = true;
    function display(value = {}) {
        return Object.fromEntries(
            buckets.map((bucket) => {
                const items = value?.[bucket];
                return [
                    bucket,
                    Array.isArray(items) && items.every((item) => typeof item === "boolean")
                        ? items.slice(0, 40)
                        : [],
                ];
            }),
        );
    }
    function clean(value) {
        const entries =
            value?.forms && typeof value.forms === "object" && !Array.isArray(value.forms)
                ? Object.entries(value.forms)
                      .filter(([name]) => /^[a-f0-9]{64}$/.test(name))
                      .slice(-25)
                : [];
        return {
            ...(typeof value?.library === "boolean" ? { library: value.library } : {}),
            forms: Object.fromEntries(entries.map(([name, state]) => [name, display(state)])),
        };
    }
    function read() {
        if (!available) return memory;
        let raw;
        try {
            raw = window.localStorage.getItem(key);
        } catch {
            available = false;
            return memory;
        }
        try {
            memory = raw && raw.length <= 65536 ? clean(JSON.parse(raw)) : { forms: {} };
        } catch {
            memory = { forms: {} };
        }
        return memory;
    }
    function write(value) {
        memory = clean(value);
        if (!available) return;
        try {
            window.localStorage.setItem(key, JSON.stringify(memory));
        } catch {
            available = false;
        }
    }
    function handle(scope = null) {
        const stored = scope ? read().forms[scope] : null;
        let state = display(stored);
        return {
            stored: !!stored,
            snapshot: () => display(state),
            write(value) {
                state = display(value);
                if (!scope) return;
                const current = read();
                if (JSON.stringify(current.forms[scope]) === JSON.stringify(state)) return;
                const forms = { ...current.forms };
                delete forms[scope];
                forms[scope] = state;
                write({ ...current, forms });
            },
        };
    }
    return {
        library: (fallback) => {
            const state = read();
            return typeof state.library === "boolean" ? state.library : fallback;
        },
        showLibrary: (open) => write({ ...read(), library: !!open }),
        transient: () => handle(),
        async record(id) {
            if (typeof id !== "string" || !id || id.length > 128 || !window.crypto?.subtle)
                return handle();
            try {
                const digest = await window.crypto.subtle.digest(
                    "SHA-256",
                    new TextEncoder().encode(`etsis:form-collapse:${id}`),
                );
                const scope = [...new Uint8Array(digest)]
                    .map((value) => value.toString(16).padStart(2, "0"))
                    .join("");
                return handle(scope);
            } catch {
                return handle();
            }
        },
    };
};

window.createApplicationFormLibrary = ({
    node,
    request,
    task,
    badge,
    can,
    emptyState,
    openForm,
    removeDefault,
    active,
}) => {
    const search = document.getElementById("form-library-search");
    const state = document.getElementById("form-library-state");
    const results = document.getElementById("form-library-list");
    const status = document.getElementById("form-library-status");
    const previous = document.getElementById("form-library-previous");
    const next = document.getElementById("form-library-next");
    const find = document.getElementById("form-library-find");
    let applied = { search: "", state: "all", after: null, cursors: [] };
    let nextAfter = null,
        serial = 0,
        selected = null,
        loading = false;
    const criteria = () => ({
        search: search.value.trim(),
        state: state.value,
        after: null,
        cursors: [],
    });
    const filtersChanged = () =>
        search.value.trim() !== applied.search || state.value !== applied.state;
    function paging() {
        previous.disabled = loading || filtersChanged() || !applied.cursors.length;
        next.disabled = loading || filtersChanged() || !nextAfter;
    }
    function select(id) {
        selected = id;
        results.querySelectorAll("[data-form-id]").forEach((button) => {
            const current = button.dataset.formId === id;
            button.classList.toggle("active", current);
            if (current) button.setAttribute("aria-current", "true");
            else button.removeAttribute("aria-current");
        });
    }
    function render(items) {
        results.replaceChildren();
        if (!items.length)
            results.append(
                emptyState(
                    applied.search || applied.state !== "all"
                        ? "No forms match."
                        : "Your first welcome starts here.",
                    applied.search || applied.state !== "all"
                        ? "Try another name, program or academic level."
                        : "Create a form for an academic level or a specific program.",
                    "ui-checks",
                ),
            );
        for (const form of items) {
            const button = node(
                "button",
                {
                    class: "form-library-item",
                    type: "button",
                    "data-form-id": form.id,
                    onclick: (event) =>
                        task(async () => {
                            if (await openForm(form.id, form.program)) select(form.id);
                        }, event.currentTarget),
                },
                node("strong", {}, form.name),
                badge(form.enabled_id ? "Enabled" : form.status),
                node(
                    "small",
                    {},
                    `${form.program} · ${form.status === "published" ? "Open a new revision" : "Continue draft"}`,
                ),
            );
            const item = node("div", {}, button);
            if (form.enabled_id && can("sis:forms:publish"))
                item.append(
                    node(
                        "button",
                        {
                            class: "btn btn-sm btn-outline-secondary ms-3 mb-3",
                            type: "button",
                            "aria-label": `Remove default: ${form.name}`,
                            onclick: () => removeDefault(form),
                        },
                        "Remove default",
                    ),
                );
            results.append(item);
        }
        select(selected);
    }
    async function load(candidate = applied) {
        const version = active();
        if (version === null) return;
        const token = ++serial;
        const preserveScroll = candidate === applied;
        const current = () => token === serial && version !== null && active() === version;
        loading = true;
        paging();
        results.setAttribute("aria-busy", "true");
        status.textContent = "Loading forms…";
        const query = new URLSearchParams();
        if (candidate.search) query.set("search", candidate.search);
        if (candidate.state !== "all") query.set("state", candidate.state);
        if (candidate.after) query.set("after", candidate.after);
        try {
            const data = await request(`/sis/forms/${query.size ? `?${query}` : ""}`);
            if (!current()) return;
            if (!Array.isArray(data.items))
                throw new Error("The form library could not be loaded. Try again.");
            applied = candidate;
            nextAfter = data.next_after || null;
            render(data.items);
            if (!preserveScroll) results.scrollTop = 0;
            status.textContent = `Page ${applied.cursors.length + 1} · ${data.items.length} ${data.items.length === 1 ? "form" : "forms"} shown.${nextAfter ? " More are available." : ""}${filtersChanged() ? " Search changed. Select Find to update results." : ""}`;
        } catch (error) {
            if (!current()) return;
            status.textContent =
                "Forms could not be loaded. Earlier results are still shown. Select Find to retry.";
            throw error;
        } finally {
            if (current()) {
                loading = false;
                results.removeAttribute("aria-busy");
                paging();
            }
        }
    }
    document.getElementById("form-library-search-form").addEventListener("submit", (event) => {
        event.preventDefault();
        task(() => load(criteria()), find);
    });
    search.addEventListener("input", () => {
        paging();
        if (!loading && filtersChanged())
            status.textContent = "Search changed. Select Find to update results.";
    });
    state.addEventListener("change", () => task(() => load(criteria()), find));
    document.getElementById("form-library-clear").addEventListener("click", (event) => {
        search.value = "";
        state.value = "all";
        task(() => load(criteria()), event.currentTarget);
    });
    previous.addEventListener("click", () =>
        task(() =>
            load({
                ...applied,
                after: applied.cursors.at(-1),
                cursors: applied.cursors.slice(0, -1),
            }),
        ),
    );
    next.addEventListener("click", () =>
        task(() =>
            load({ ...applied, after: nextAfter, cursors: [...applied.cursors, applied.after] }),
        ),
    );
    return { load, select };
};
