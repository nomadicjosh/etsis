/* Editable HR reference setup. Private drafts stay in page memory. */
window.createWorkspaceHrSetup = function (ui) {
    "use strict";
    const { node, request, task, openDrawer, badge, notify, actionMenu } = ui;
    let currencies = [];
    let serial = 0;
    const rateBases = [
        { value: "hourly", label: "Hourly", period: "Per hour" },
        { value: "monthly", label: "Monthly", period: "Per month" },
        { value: "annual", label: "Annual", period: "Per year" },
    ];
    const basisLabel = (value) =>
        rateBases.find((item) => item.value === value)?.period ?? "Unknown rate basis";
    const title = (kind) => (kind === "jobs" ? "Job definitions" : "Pay grades");
    const button = (label, callback, style = "btn-outline-primary") =>
        node(
            "button",
            {
                type: "button",
                class: `btn ${style}`,
                onclick: (event) => task(callback, event.currentTarget),
            },
            label,
        );
    const digits = (code) => currencies.find((item) => item.code === code)?.digits ?? 2;
    const textAmount = (minor, places) =>
        minor == null ? "" : (minor / 10 ** places).toFixed(places);
    const amountText = (minor, code) => textAmount(minor, digits(code));
    const formatAmount = (minor, code, places) =>
        minor == null
            ? "Not set"
            : new Intl.NumberFormat(undefined, {
                  style: "currency",
                  currency: code,
                  minimumFractionDigits: places,
                  maximumFractionDigits: places,
              }).format(minor / 10 ** places);
    const amount = (minor, code) => formatAmount(minor, code, digits(code));
    const parseAmount = (value, places, control) => {
        if (value.trim() === "") return null;
        if (
            !new RegExp(`^[0-9]+(?:\\.[0-9]{1,${Math.max(1, places)}})?$`).test(value) ||
            (places === 0 && value.includes("."))
        ) {
            control.setCustomValidity(`Use an amount with at most ${places} decimal places.`);
            return false;
        }
        const [whole, fraction = ""] = value.split(".");
        const result = Number(whole) * 10 ** places + Number(fraction.padEnd(places, "0"));
        if (!Number.isSafeInteger(result) || result > 2000000000) {
            control.setCustomValidity("This amount exceeds the supported range.");
            return false;
        }
        return result;
    };
    const minor = (value, code, control) => parseAmount(value, digits(code), control);
    async function library(kind = "jobs", search = "", after = null, stack = []) {
        const body = openDrawer("HR setup", title(kind), { record: null });
        let accepted = { kind, search, after, stack: [...stack] },
            page = null,
            busy = false;
        const snapshot = () => ({ ...accepted, stack: [...accepted.stack] });
        const reopen = () =>
            library(accepted.kind, accepted.search, accepted.after, accepted.stack);
        const readButton = (label, run) =>
            node(
                "button",
                {
                    type: "button",
                    class: "btn btn-outline-primary",
                    onclick: () => {
                        if (!busy) task(run);
                    },
                },
                label,
            );
        const categories = node("div", { class: "hr-library-category" });
        const add = button(
            "Add job definition",
            () =>
                edit(
                    accepted.kind,
                    {
                        id: page.new_id,
                        currency: "USD",
                        basis: "hourly",
                        active: true,
                    },
                    null,
                    reopen,
                ),
            "btn-primary",
        );
        const heading = node("div", { class: "hr-library-heading" }, categories, add);
        const input = node("input", {
            type: "search",
            class: "form-control",
            maxlength: 100,
            id: `hr-search-${++serial}`,
            value: search,
        });
        const label = node("label", { for: input.id, class: "form-label" });
        const find = readButton("Find definitions", () =>
            load({ ...snapshot(), search: input.value.trim(), after: null, stack: [] }, true),
        );
        const clear = readButton("Clear search", () =>
            load({ ...snapshot(), search: "", after: null, stack: [] }, true, true),
        );
        const refresh = readButton("Refresh setup", () => load(snapshot(), true));
        const form = node(
            "form",
            { class: "hr-library-search my-3" },
            label,
            node(
                "div",
                { class: "hr-library-search-controls" },
                input,
                node("div", { class: "inline-actions" }, find, clear, refresh),
            ),
        );
        const status = node(
            "p",
            { class: "form-text", role: "status", tabindex: "-1" },
            "Loading HR definitions…",
        );
        const previous = readButton("Previous definitions", () =>
            load(
                {
                    ...snapshot(),
                    after: accepted.stack.at(-1) ?? null,
                    stack: accepted.stack.slice(0, -1),
                },
                true,
            ),
        );
        const next = readButton("Next definitions", () =>
            load(
                {
                    ...snapshot(),
                    after: page.next_after,
                    stack: [...accepted.stack, accepted.after],
                },
                true,
            ),
        );
        const results = node("div");
        body.append(
            heading,
            node(
                "p",
                { class: "form-text" },
                "Reusable setup for HR. These defaults do not change an employee, grant access or calculate pay. Retire a definition when it should no longer be used.",
            ),
            form,
            status,
            node(
                "nav",
                { class: "inline-actions mb-3", "aria-label": "HR definition pages" },
                previous,
                next,
            ),
            results,
        );
        function controls(pending) {
            busy = pending;
            input.disabled = pending;
            add.disabled = pending || !page;
            find.disabled = refresh.disabled = pending;
            clear.disabled = pending || (!input.value && !accepted.search);
            previous.disabled = pending || !accepted.stack.length;
            next.disabled = pending || !page?.next_after;
            categories.inert = results.inert = pending;
            results.setAttribute("aria-busy", String(pending));
        }
        input.addEventListener("input", () => {
            clear.disabled = busy || (!input.value && !accepted.search);
        });
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            if (!busy)
                task(() =>
                    load(
                        { ...snapshot(), search: input.value.trim(), after: null, stack: [] },
                        true,
                    ),
                );
        });
        function category() {
            categories.replaceChildren(
                node("span", { class: "hr-library-current" }, title(accepted.kind)),
                actionMenu(
                    "Change category",
                    ["jobs", "pay-grades"].map((key) => {
                        const item = readButton(title(key), () =>
                            load({ kind: key, search: "", after: null, stack: [] }, true),
                        );
                        item.setAttribute("aria-pressed", String(key === accepted.kind));
                        return item;
                    }),
                    { context: "Change HR setup category", icon: "collection" },
                ),
            );
            label.textContent = `Search ${title(accepted.kind).toLowerCase()}`;
            add.replaceChildren(
                node("i", { class: "bi bi-plus-lg", "aria-hidden": "true" }),
                node("span", {}, accepted.kind === "jobs" ? "Add job definition" : "Add pay grade"),
            );
            document.getElementById("drawer-eyebrow").textContent = title(accepted.kind);
        }
        async function load(candidate = snapshot(), focus = false, focusSearch = false) {
            controls(true);
            let data;
            try {
                data = await request(`/sis/hr/${candidate.kind}/page/`, "POST", {
                    search: candidate.search,
                    ...(candidate.after ? { after: candidate.after } : {}),
                });
            } catch (error) {
                if (body.isConnected) {
                    status.textContent = page
                        ? "Definitions could not be refreshed. The previous page remains displayed. Try again."
                        : "Definitions could not be loaded. Try Refresh setup.";
                    if (error.status === 403) {
                        page = null;
                        accepted.stack = [];
                        results.replaceChildren();
                        heading.replaceChildren();
                        form.remove();
                        previous.parentElement.remove();
                        status.textContent = "HR setup access is no longer available.";
                    }
                }
                throw error;
            } finally {
                if (body.isConnected) controls(false);
            }
            if (!body.isConnected) return;
            accepted = { ...candidate, stack: [...candidate.stack] };
            page = data;
            currencies = data.currencies;
            input.value = accepted.search;
            category();
            status.textContent = `${data.items.length} ${title(accepted.kind).toLowerCase()} on this page.${data.next_after ? " More are available." : ""}`;
            const list = node("ul", {
                class: "hr-definition-list",
                "aria-label": title(accepted.kind),
            });
            for (const item of data.items) {
                const active = item.active === true || Number(item.active) === 1;
                list.append(
                    node(
                        "li",
                        { class: "hr-definition-card" },
                        node(
                            "div",
                            { class: "hr-definition-heading" },
                            node("h3", {}, item.title),
                            badge(active ? "Active" : "Retired"),
                        ),
                        node("p", { class: "form-text" }, item.code),
                        node(
                            "p",
                            { class: "hr-definition-rate" },
                            accepted.kind === "jobs"
                                ? amount(item.rate_minor, item.currency)
                                : `${amount(item.minimum_minor, item.currency)} – ${amount(item.maximum_minor, item.currency)}`,
                        ),
                        node("p", { class: "form-text" }, basisLabel(item.basis)),
                        button("Edit definition", () => edit(accepted.kind, item, null, reopen)),
                    ),
                );
            }
            results.replaceChildren(
                data.items.length ? list : node("p", {}, "No definitions match this search."),
            );
            controls(false);
            if (focusSearch) input.focus();
            else if (focus) status.focus({ preventScroll: true });
        }
        category();
        await load();
    }
    async function edit(kind, record, returnTo = null, backToLibrary = () => library(kind)) {
        const job = kind === "jobs";
        const body = openDrawer(
            record.version == null
                ? job
                    ? "New job definition"
                    : "New pay grade"
                : job
                  ? "Edit job definition"
                  : "Edit pay grade",
            "HR SETUP",
        );
        const back = () => (returnTo ? returnTo(null) : backToLibrary());
        const returnControl = button(
            returnTo
                ? "Back to job draft"
                : kind === "jobs"
                  ? "Back to job definitions"
                  : "Back to pay grades",
            back,
            "btn-link",
        );
        const loading = node(
            "p",
            { role: "status", class: "form-text" },
            "Preparing HR definition…",
        );
        body.append(returnControl, loading);
        const denied = () =>
            body.replaceChildren(
                returnControl,
                node("p", { role: "status" }, "HR setup access is no longer available."),
            );
        const lookupRequest = async (...args) => {
            try {
                return await request(...args);
            } catch (error) {
                if (body.isConnected) {
                    if (error.status === 403) denied();
                    else
                        loading.textContent =
                            "Pay-grade choices could not be loaded. Return to the library and try again.";
                }
                throw error;
            }
        };
        const form = node("form", { class: "record-form" });
        if (record.version != null)
            form.append(
                node(
                    "p",
                    { class: "hr-definition-context" },
                    node("strong", {}, record.code),
                    node("span", {}, record.title),
                ),
            );
        const group = (label, description = "") => {
            const fields = node("div", { class: "hr-definition-fields" });
            if (description) fields.append(node("p", { class: "form-text" }, description));
            form.append(
                node(
                    "fieldset",
                    { class: "hr-definition-section" },
                    node("legend", {}, label),
                    fields,
                ),
            );
            return fields;
        };
        let section = group("Definition");
        const controls = {};
        const field = (key, label, type = "text", value = "", required = true, choices = []) => {
            const control = node(type === "select" ? "select" : "input", {
                id: `hr-field-${++serial}`,
                name: key,
                class:
                    type === "checkbox"
                        ? "form-check-input"
                        : type === "select"
                          ? "form-select"
                          : "form-control",
                required: required && type !== "checkbox",
                ...(type === "select" ? {} : { type }),
                ...(type === "text" ? { maxlength: key === "code" ? 40 : 191 } : {}),
                ...(type === "number"
                    ? { min: 0, max: key === "weekly_extra" ? 59 : 168, step: 1 }
                    : {}),
            });
            if (type === "select")
                choices.forEach((item) =>
                    control.append(
                        node(
                            "option",
                            {
                                value: item.value,
                                disabled: item.disabled,
                            },
                            item.label,
                        ),
                    ),
                );
            if (type === "checkbox") control.checked = value === true || Number(value) === 1;
            else control.value = value ?? "";
            control.addEventListener("input", () => {
                control.setCustomValidity("");
                control.removeAttribute("aria-invalid");
                control.classList.remove("is-invalid");
            });
            controls[key] = control;
            const labelNode = node("label", { for: control.id, class: "form-label" }, label);
            section.append(
                node(
                    "div",
                    { class: type === "checkbox" ? "form-check" : "hr-definition-field" },
                    ...(type === "checkbox" ? [control, labelNode] : [labelNode, control]),
                ),
            );
            return control;
        };
        field("code", "Code", "text", record.code);
        field("title", "Title", "text", record.title);
        field("active", "Active definition", "checkbox", record.active);
        section = group(
            job ? "Pay defaults" : "Pay range",
            "Amounts use the selected currency and rate basis. Hourly, monthly and annual values are never converted automatically.",
        );
        const paySection = section;
        field(
            "currency",
            "Currency",
            "select",
            record.currency,
            true,
            currencies.map((item) => ({ value: item.code, label: item.code })),
        );
        field("basis", "Rate basis", "select", record.basis, true, rateBases);
        if (job) {
            field(
                "rate_minor",
                "Default rate",
                "text",
                record.rate_text ?? amountText(record.rate_minor, record.currency),
                false,
            );
            controls.rate_minor.inputMode = "decimal";
            field("pay_grade_id", "Pay grade", "select", "", false, [
                { value: "", label: "No pay grade" },
            ]);
            section.append(
                node(
                    "p",
                    { class: "form-text" },
                    "Leave the rate blank if it has not been defined.",
                ),
            );
            const lookup = node("input", {
                type: "search",
                maxlength: 100,
                class: "form-control",
                "aria-label": "Search pay grades",
                placeholder: "Find a pay grade by code or title",
            });
            let gradePage = null,
                loadingGrades = false;
            const find = button("Find pay grades", () => loadGrades(lookup.value));
            const more = button("More pay grades", () =>
                loadGrades(lookup.value, gradePage.next_after),
            );
            more.hidden = true;
            lookup.addEventListener("keydown", (event) => {
                if (event.key !== "Enter") return;
                event.preventDefault();
                if (!loadingGrades) task(() => loadGrades(lookup.value));
            });
            section.append(
                node("label", { class: "form-label" }, "Search pay grades", lookup),
                node("div", { class: "inline-actions my-2" }, find, more),
            );
            const gradeStatus = node("p", { class: "form-text", role: "status" });
            const createGrade = button("Create pay grade", async () => {
                const draft = snapshot();
                await edit(
                    "pay-grades",
                    {
                        id: gradePage.new_id,
                        currency: draft.currency,
                        basis: draft.basis,
                        active: true,
                    },
                    (saved) =>
                        edit(
                            "jobs",
                            {
                                ...record,
                                ...draft,
                                pay_grade_id: saved?.id ?? draft.pay_grade_id,
                            },
                            null,
                            backToLibrary,
                        ),
                    backToLibrary,
                );
            });
            section.append(gradeStatus, createGrade);
            const selectedGrade = record.pay_grade_id
                ? await lookupRequest(`/sis/hr/pay-grades/${record.pay_grade_id}/`)
                : null;
            if (!body.isConnected) return;
            if (selectedGrade)
                controls.pay_grade_id.append(
                    node(
                        "option",
                        { value: selectedGrade.id },
                        `${selectedGrade.code} · ${selectedGrade.title}${selectedGrade.active ? "" : " · Retired"}`,
                    ),
                );
            controls.pay_grade_id.value = record.pay_grade_id ?? "";
            async function loadGrades(search, after = null) {
                if (loadingGrades) return;
                loadingGrades = true;
                controls.pay_grade_id.disabled =
                    find.disabled =
                    more.disabled =
                    createGrade.disabled =
                        true;
                const selected = controls.pay_grade_id.value;
                const selectedOption = [...controls.pay_grade_id.options]
                    .find((item) => item.value === selected)
                    ?.cloneNode(true);
                let data;
                try {
                    data = await lookupRequest("/sis/hr/pay-grades/page/", "POST", {
                        search,
                        ...(after ? { after } : {}),
                    });
                } finally {
                    loadingGrades = false;
                    if (body.isConnected) {
                        controls.pay_grade_id.disabled = find.disabled = more.disabled = false;
                        createGrade.disabled = !gradePage;
                    }
                }
                if (!body.isConnected) return;
                gradePage = data;
                createGrade.disabled = false;
                if (!after) {
                    controls.pay_grade_id.replaceChildren(
                        node("option", { value: "" }, "No pay grade"),
                    );
                    if (selectedOption && selected) controls.pay_grade_id.append(selectedOption);
                }
                data.items.forEach((item) => {
                    if (
                        ![...controls.pay_grade_id.options].some(
                            (option) => option.value === item.id,
                        )
                    ) {
                        controls.pay_grade_id.append(
                            node(
                                "option",
                                { value: item.id, disabled: !item.active },
                                `${item.code} · ${item.title}${item.active ? "" : " · Retired"}`,
                            ),
                        );
                    }
                });
                controls.pay_grade_id.value = selected;
                more.hidden = !data.next_after;
                gradeStatus.textContent = `${controls.pay_grade_id.options.length - 1} choices loaded. Search to find another grade.`;
            }
            await loadGrades("");
            if (!body.isConnected) return;
            section = group(
                "Default workload",
                "Optional planning hours, not a timesheet limit or a pay calculation.",
            );
            field(
                "weekly_hours",
                "Weekly hours",
                "number",
                record.weekly_hours ??
                    (record.weekly_minutes == null ? "" : Math.floor(record.weekly_minutes / 60)),
                false,
            );
            field(
                "weekly_extra",
                "Additional minutes",
                "number",
                record.weekly_extra ??
                    (record.weekly_minutes == null ? "" : record.weekly_minutes % 60),
                false,
            );
        } else {
            field(
                "minimum_minor",
                "Minimum rate",
                "text",
                amountText(record.minimum_minor, record.currency),
            );
            field(
                "maximum_minor",
                "Maximum rate",
                "text",
                amountText(record.maximum_minor, record.currency),
            );
            controls.minimum_minor.inputMode = controls.maximum_minor.inputMode = "decimal";
        }
        const help = node("p", { class: "form-text", role: "status" });
        const unitHelp = () => {
            const places = digits(controls.currency.value);
            help.textContent = `${controls.currency.value} · ${basisLabel(controls.basis.value)} · ${places} decimal places. Changing currency or basis requires reviewing the entered amounts.`;
            ["rate_minor", "minimum_minor", "maximum_minor"].forEach((key) =>
                controls[key]?.setCustomValidity(""),
            );
        };
        controls.currency.addEventListener("change", unitHelp);
        controls.basis.addEventListener("change", unitHelp);
        unitHelp();
        paySection.append(help);
        function snapshot() {
            const draft = Object.fromEntries(
                Object.entries(controls).map(([key, control]) => [
                    key,
                    control.type === "checkbox" ? control.checked : control.value,
                ]),
            );
            if (job) {
                draft.rate_text = controls.rate_minor.value;
                delete draft.rate_minor;
            }
            return draft;
        }
        const save = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            job ? "Save job definition" : "Save pay grade",
        );
        form.append(save);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                Object.values(controls).forEach((control) => control.setCustomValidity(""));
                if (!form.isConnected || controls.pay_grade_id?.disabled || !form.reportValidity())
                    return;
                const draft = snapshot();
                const payload = {
                    id: record.id,
                    code: draft.code,
                    title: draft.title,
                    active: draft.active,
                    currency: draft.currency,
                    basis: draft.basis,
                    version: record.version ?? null,
                };
                const keys = job ? ["rate_minor"] : ["minimum_minor", "maximum_minor"];
                for (const key of keys) {
                    payload[key] = minor(controls[key].value, draft.currency, controls[key]);
                    if (payload[key] === false) {
                        form.reportValidity();
                        return;
                    }
                }
                if (job) {
                    payload.pay_grade_id = draft.pay_grade_id || null;
                    payload.weekly_minutes =
                        draft.weekly_hours === "" && draft.weekly_extra === ""
                            ? null
                            : Number(draft.weekly_hours || 0) * 60 +
                              Number(draft.weekly_extra || 0);
                    if (payload.weekly_minutes > 10080) {
                        controls.weekly_hours.setCustomValidity(
                            "Weekly hours must fit within a seven-day week.",
                        );
                        form.reportValidity();
                        return;
                    }
                }
                let saved;
                try {
                    saved = await request(`/sis/hr/${kind}/save/`, "POST", payload);
                } catch (error) {
                    if (body.isConnected && error.status === 403) denied();
                    throw error;
                }
                if (!body.isConnected) return;
                notify("HR definition saved.");
                if (returnTo) await returnTo(saved);
                else await backToLibrary();
            }, save);
        });
        body.replaceChildren(returnControl, form);
    }
    return {
        library,
        amounts: { text: textAmount, format: formatAmount, parse: parseAmount, basisLabel },
    };
};
