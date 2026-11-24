/* Shared working review and retained determinations. Private results stay in the current page only. */
window.createEnrollmentLoadReview = function (ui) {
    "use strict";
    const { node, request, task, historyCheckLabels: labels = {} } = ui;
    const policyLabels = JSON.parse(document.getElementById('enrollment-load-labels')?.textContent || '{}');
    const dates = new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short' });
    const publication = policy => policyLabels.publication?.replace('{version}', policy.publication_version).replace('{date}', dates.format(new Date(policy.published_at)));
    const threshold = band => policyLabels.threshold?.replace('{label}', band.label).replace('{credits}', credits(band.min_credits_milli));
    const policyBasis = policy => node('div', { class: 'small mb-2' },
        node('p', { class: 'mb-1' }, policy.basis === 'selected_publication' ? policyLabels.selected
            : policy.basis === 'current_policy' ? policyLabels.effective : policyLabels.unrecorded),
        policy.publication_version ? node('p', { class: 'mb-1 text-break' }, publication(policy)) : null);
    const credits = milli => (milli / 1000).toLocaleString(undefined, { maximumFractionDigits: 3 });
    let sequence = 0;
    return function mount(container, base, { canDetermine = false, canSelectPolicy = false, canReadDeterminations = true, correctCredits = null, checkDetermination = null, initialHistory = null } = {}) {
        const root = node("section", { class: "surface border rounded p-3 my-3", "aria-label": "Enrollment load" });
        container.append(root);
        let serial = 0;
        const alive = token => root.isConnected && token === serial;
        const button = (label, run) => node("button", { type: "button", class: "btn btn-outline-primary campus-button secondary",
            onclick: event => task(run, event.currentTarget) }, label);
        async function choose(search = "", after = null, stack = [], records = false) {
            const token = ++serial;
            const data = await request(`${base}terms/`, "POST", { search, after, ...(records ? { for_records: true } : {}) });
            if (!alive(token)) return;
            const id = `load-term-${++sequence}`;
            const input = node("input", { id, type: "search", maxlength: "80", class: "form-control", value: search });
            const form = node("form", { class: "mb-3" }, node("label", { for: id, class: "form-label" }, "Find a term"), input,
                node("button", { type: "submit", class: "btn btn-primary campus-button mt-2" }, "Search"));
            form.addEventListener("submit", event => { event.preventDefault(); task(() => choose(input.value, null, [], records)); });
            root.replaceChildren(node("h2", { class: "h5" }, "Enrollment load"), form);
            for (const term of data.items) root.append(node("div", { class: "mb-2" }, button(`${term.code} · ${term.starts_on}–${term.ends_on}`, () => records ? history(term) : review(term))));
            if (!data.items.length) root.append(node("p", { role: "status" }, "No terms match your search."));
            if (stack.length) root.append(button("Previous terms", () => choose(search, stack.at(-1), stack.slice(0, -1), records)));
            if (data.next_after) root.append(button("More terms", () => choose(search, data.next_after, [...stack, after], records)));
        }
        async function review(term) {
            const token = ++serial;
            const data = await request(`${base}review/`, "POST", { term_id: term.id });
            if (!alive(token)) return;
            const message = data.status === "limited" ? "This review needs a larger historical review by the registrar. No partial total is shown." :
                data.status === "needs_review" ? "Some registration records could not be verified. Ask the registrar to review them before relying on a total." :
                    data.status === "not_registered" ? "No active registrations in this term." : `${credits(data.credits_milli)} registered credits · ${data.classification?.label || "No load classification configured"}`;
            root.replaceChildren(node("h2", { class: "h5" }, `Enrollment load · ${data.term.code}`),
                node("p", { role: "status", class: "fw-semibold" }, message),
                node("p", { class: "form-text campus-muted" }, `${data.program.code} · ${data.program.title}. All active registrations in this term count, including registrations made before a program change.`),
                node("p", { class: "form-text campus-muted" }, "Working review of current registrations, not an official census or financial-aid determination. Credits reflect recorded registrations and any explicit registered-credit corrections. Catalog and award/grade edits do not replace those quantities."));
            if (data.policy?.source && data.policy.source !== "not_configured") root.append(node("p", { class: "form-text campus-muted" },
                data.policy.source === "program" ? "Using this program's load policy." : "Using the institutional load policy."));
            const list = node("ul", { class: "list-unstyled" });
            for (const item of data.items) {
                const row = node("li", { class: "border-bottom py-2" }, `${item.course_code} · ${item.course_title} · ${item.credits_milli === null ? "Credits need review" : `${credits(item.credits_milli)} credits`}`);
                if (correctCredits && item.enrollment_id && item.section_id) row.append(button(`Registered credits · ${item.course_code}`, () => { ++serial; return correctCredits(root, item, () => review(term)); }));
                list.append(row);
            }
            root.append(list, node("div", { class: "d-flex flex-wrap gap-2" }, button("Refresh review", () => review(term)), button("Change term", () => choose())));
            if (canReadDeterminations) root.append(button("Recorded determinations for this term", () => history(term)));
            if (canDetermine && data.term_timing === 'current' && ['ready', 'not_registered'].includes(data.status)) determinationForm(term);
        }
        async function history(term, after = null, stack = []) {
            const token = ++serial;
            const data = await request(`${base}determinations/`, "POST", { term_id: term.id, after });
            if (!alive(token)) return;
            root.replaceChildren(node("h2", { class: "h5" }, `Recorded enrollment load · ${term.code}`),
                node("p", { class: "form-text campus-muted" }, "These are separate retained determinations. Later registration and policy changes do not replace them. They do not reconstruct an earlier census or determine financial-aid eligibility."));
            for (const item of data.items) {
                const details = node("details", {}, node("summary", {}, "Recorded policy and basis"),
                    node("p", {}, `${item.program.code} · ${item.program.title}. Policy: ${item.policy.source === 'program' ? 'Program' : 'Institution'}. Basis: accepted registered credits.`),
                    policyBasis(item.policy),
                    node("ul", {}, ...item.policy.definition.bands.map(band => node("li", {}, `${band.label}: from ${credits(band.min_credits_milli)} credits`))),
                    node("p", { class: "form-text" }, "Individual registration sources remain in protected academic history."));
                root.append(node("article", { class: "border rounded p-3 my-2" },
                    node("h3", { class: "h6" }, `${item.as_of_date} · ${credits(item.credits_milli)} credits · ${item.classification?.label || 'Not registered'}`),
                    node("p", {}, `Recorded ${item.recorded_at} · ${item.enrollment_count} active registrations`), details,
                    checkDetermination && item.id ? button(labels.check_load || "Check retained load history",
                        () => checkDetermination(item.id, { term, after, stack: [...stack] })) : null));
            }
            if (!data.items.length) root.append(node("p", { role: "status" }, "No load determinations have been recorded for this pursuit and term."));
            if (stack.length) root.append(button("Newer determinations", () => history(term, stack.at(-1), stack.slice(0, -1))));
            if (data.next_after) root.append(button("Older determinations", () => history(term, data.next_after, [...stack, after])));
            root.append(button("Refresh recorded determinations", () => history(term)), button("Change recorded term", () => choose('', null, [], true)));
        }
        function determinationForm(term) {
            const identity = `load-determination-${++sequence}`;
            let selection = null, selectionPage = 0;
            const input = node("textarea", { id: identity, class: "form-control", required: true, maxlength: "1000" });
            const submit = node("button", { type: "submit", class: "btn btn-primary mt-2" }, "Review load determination");
            const chosen = node('div', { role: 'status', class: 'mb-2' });
            const chooser = node('div', { class: 'border rounded p-3 mb-3', hidden: true });
            const controls = node('fieldset', { class: 'border-0 p-0 m-0' },
                node('legend', { class: 'h6' }, policyLabels.policy), chosen);
            function currentPolicy() {
                ++selectionPage;
                selection = null;
                chooser.hidden = true;
                submit.disabled = false;
                chosen.replaceChildren(node('p', { class: 'mb-1' }, policyLabels.current),
                    node('p', { class: 'form-text' }, policyLabels.current_help));
            }
            async function publications(source = 'global', cursor = null, stack = []) {
                const pageToken = ++selectionPage;
                const rootToken = serial;
                chooser.hidden = false;
                submit.disabled = true;
                const close = () => {
                    ++selectionPage;
                    chooser.hidden = true;
                    submit.disabled = false;
                    input.focus();
                };
                chooser.replaceChildren(node('p', { role: 'status' }, policyLabels.loading), button(policyLabels.close, close));
                let data;
                try {
                    data = await request(`${base}policy-choices/`, 'POST', { source, cursor });
                } catch (error) {
                    if (alive(rootToken) && pageToken === selectionPage) {
                        chooser.replaceChildren(node('p', { role: 'alert' }, policyLabels.failed),
                            button(policyLabels.retry, () => publications(source, cursor, stack)), button(policyLabels.close, close));
                    }
                    throw error;
                }
                if (!alive(rootToken) || pageToken !== selectionPage) return;
                const scopeId = `${identity}-scope`;
                const scope = node('select', { id: scopeId, class: 'form-select mb-3' },
                    node('option', { value: 'global' }, policyLabels.global), node('option', { value: 'program' }, policyLabels.program));
                scope.value = source;
                scope.addEventListener('change', () => task(() => publications(scope.value)));
                chooser.replaceChildren(node('label', { for: scopeId, class: 'form-label' }, policyLabels.source), scope,
                    node('p', { class: 'form-text' }, policyLabels.selection_help));
                for (const item of data.items) {
                    const card = node('article', { class: 'border rounded p-3 my-2 text-break' },
                        node('h4', { class: 'h6' }, publication(item)));
                    if (item.definition) {
                        card.append(node('ul', {}, ...item.definition.bands.map(band => node('li', {}, threshold(band)))),
                            button(policyLabels.pick.replace('{version}', item.publication_version), () => {
                                ++selectionPage;
                                selection = { source, version_id: item.version_id };
                                chosen.replaceChildren(node('p', { class: 'mb-1' }, policyLabels.selected),
                                    node('p', { class: 'mb-1 text-break' }, `${policyLabels[source]} · ${publication(item)}`),
                                    node('ul', {}, ...item.definition.bands.map(band => node('li', {}, threshold(band)))));
                                chooser.hidden = true;
                                submit.disabled = false;
                                input.focus();
                            }));
                    } else card.append(node('p', { class: 'form-text' }, policyLabels.cleared));
                    chooser.append(card);
                }
                if (!data.items.length) chooser.append(node('p', { role: 'status' }, policyLabels.empty));
                const navigation = node('div', { class: 'd-flex flex-wrap gap-2' });
                if (stack.length) navigation.append(button(policyLabels.previous, () => publications(source, stack.at(-1), stack.slice(0, -1))));
                if (data.next_cursor) navigation.append(button(policyLabels.more, () => publications(source, data.next_cursor, [...stack, cursor])));
                navigation.append(button(policyLabels.close, close));
                chooser.append(navigation);
                scope.focus();
            }
            const pick = button(policyLabels.select, () => publications());
            if (canSelectPolicy) controls.append(node('div', { class: 'd-flex flex-wrap gap-2 mb-3' }, pick,
                button(policyLabels.current, () => { currentPolicy(); input.focus(); })));
            controls.append(chooser, node("label", { for: identity, class: "form-label" }, "Reason for recording load"), input,
                node("p", { class: "form-text" }, policyLabels.record_help), submit);
            const form = node("form", { class: "mt-3" }, controls);
            currentPolicy();
            form.addEventListener("submit", event => {
                event.preventDefault();
                if (controls.disabled || !chooser.hidden) return;
                task(async () => {
                    if (!form.reportValidity()) return;
                    const token = ++serial;
                    ++selectionPage;
                    const reviewedSelection = selection ? { ...selection } : null;
                    controls.disabled = true;
                    let review;
                    try {
                        review = await request(`${base}record-review/`, "POST", { term_id: term.id, reason: input.value, policy_selection: reviewedSelection });
                    } finally {
                        controls.disabled = false;
                    }
                    if (!alive(token)) return;
                    form.hidden = true;
                    const result = review.result;
                    const confirmation = node("div", { class: "mt-3", role: "region", "aria-label": "Confirm enrollment load determination" },
                        node("h3", { class: "h6" }, "Review this retained determination"),
                        node("p", {}, `${result.term.code} · ${result.as_of_date} · ${credits(result.credits_milli)} registered credits · ${result.classification?.label || 'Not registered'}`),
                        node("p", {}, `Policy: ${result.policy.source === 'program' ? 'Program' : 'Institution'} · ${result.enrollment_count} active registrations`),
                        policyBasis(result.policy),
                        node("details", {}, node("summary", {}, "Reviewed policy and registrations"),
                            node("ul", {}, ...result.policy.definition.bands.map(band => node("li", {}, `${band.label}: from ${credits(band.min_credits_milli)} credits`))),
                            node("ul", {}, ...result.items.map(item => node("li", {}, `${item.course_code} · ${item.course_title} · ${credits(item.credits_milli)} credits`)))),
                        node("p", { style: "white-space: pre-wrap" }, review.reason),
                        button("Confirm load determination", async () => {
                            await request(`${base}record-confirm/`, "POST", { term_id: term.id, operation_id: review.operation_id,
                                revision: review.revision, reason: review.reason, review_token: review.review_token,
                                policy_selection: reviewedSelection });
                            if (alive(token)) await history(term);
                        }),
                        button("Back to determination editing", () => { ++serial; confirmation.remove(); form.hidden = false; input.focus(); }));
                    root.append(confirmation);
                    confirmation.querySelector('button').focus();
                }, submit);
            });
            root.append(form);
        }
        root.append(node("h2", { class: "h5" }, "Enrollment load"), button("Review enrollment load", () => choose()));
        if (canReadDeterminations) root.append(button("Recorded determinations", () => choose('', null, [], true)));
        if (initialHistory) task(() => history(initialHistory.term, initialHistory.after, initialHistory.stack));
        return root;
    };
};

window.createWorkspaceEnrollmentLoad = function (ui) {
    "use strict";
    const { node, request, task, openDrawer, can } = ui;
    const mount = window.createEnrollmentLoadReview(ui);
    const button = (label, run, style = "btn-outline-primary") => node("button", { type: "button", class: `btn ${style}`,
        onclick: event => task(run, event.currentTarget) }, label);
    const field = (label, input) => node("div", { class: "mb-3" }, node("label", { for: input.id, class: "form-label" }, label), input);
    const showBands = definition => definition === null ? "No separate credit bands" : definition.bands.map((b, i, all) =>
        `${b.label}: ${b.min_credits_milli / 1000}${all[i + 1] ? ` to under ${all[i + 1].min_credits_milli / 1000}` : " or more"} credits`).join("; ");
    async function registeredCredits(container, student, item, back, preset = null) {
        const panel = node("section", { "aria-label": "Registered-credit correction" });
        container.replaceChildren(panel);
        panel.append(node("p", { role: "status" }, "Loading registered-credit evidence…"), button("Back to enrollment load", back));
        const base = `/sis/students/${student}/sections/${item.section_id}/enrollments/${item.enrollment_id}/registered-credits/`;
        const data = await request(base + "view/", "POST", {});
        if (!panel.isConnected) return;
        const quantity = value => value === null ? "Not recorded" : (value / 1000).toLocaleString(undefined, { maximumFractionDigits: 3 });
        const warning = "Correct the registered quantity using institutional evidence. Awarded attempted/completed credits, grades, GPA and original charges stay separate. Arrange any required academic or financial correction through its own review. This is not variable-credit registration or a backdated census.";
        let correctionSequence = 0;
        const current = token => panel.isConnected && token === correctionSequence;
        const draft = preset || { credits: data.current_registered_milli === null ? "" : String(data.current_registered_milli / 1000), reason: "", acknowledged: false };
        function edit() {
            if (!panel.isConnected) return;
            ++correctionSequence;
            panel.replaceChildren(node("h3", { class: "h5" }, "Correct registered credits"),
                node("p", {}, `${data.course_code} · ${data.course_title} · Registration term (current label) ${data.term_code}`),
                node("p", {}, `Original registered credits: ${quantity(data.original_registered_milli)}. Current accepted registered credits: ${quantity(data.current_registered_milli)}.`),
                node("p", {}, `Awarded attempted credits: ${quantity(data.awarded_attempted_milli)}; completed credits: ${quantity(data.awarded_completed_milli)}.`),
                node("p", { class: "form-text" }, warning));
            if (!data.can_correct) {
                panel.append(node("p", { role: "status" }, "This attempt needs separate registrar resolution. Only verified active registrations without a pending grade correction can be changed here."),
                    button("Registered-credit history", () => history()), button("Back to enrollment load", back));
                return;
            }
            const amount = node("input", { id: "registered-credit-amount", type: "number", min: "0", max: "999.999", step: "0.001", required: true, class: "form-control", value: draft.credits });
            const reason = node("textarea", { id: "registered-credit-reason", rows: "3", maxlength: "1000", required: true, class: "form-control" });
            reason.value = draft.reason;
            const acknowledged = node("input", { id: "registered-credit-evidence", type: "checkbox", required: true, class: "form-check-input" });
            acknowledged.checked = draft.acknowledged;
            amount.addEventListener("input", () => { draft.credits = amount.value; amount.setCustomValidity(""); });
            reason.addEventListener("input", () => { draft.reason = reason.value; });
            acknowledged.addEventListener("change", () => { draft.acknowledged = acknowledged.checked; });
            const submit = node("button", { type: "submit", class: "btn btn-primary" }, "Review registered-credit correction");
            const form = node("form", {}, field("Corrected registered credits", amount), field("Reason and institutional evidence", reason),
                node("div", { class: "form-check mb-3" }, acknowledged, node("label", { for: acknowledged.id, class: "form-check-label" }, "I verified the institutional registration evidence and understand that awards and charges need separate review.")),
                node("div", { class: "inline-actions" }, submit, button("Registered-credit history", () => history()), button("Reload registered-credit evidence", () => registeredCredits(container, student, item, back, draft)), button("Back to enrollment load", back)));
            form.addEventListener("submit", event => { event.preventDefault(); task(async () => {
                if (!form.reportValidity()) return;
                if (!/^(?:0|[1-9]\d{0,2})(?:\.\d{1,3})?$/.test(draft.credits)) { amount.setCustomValidity("Use zero to 999.999 credits with at most three decimals."); amount.reportValidity(); return; }
                const [whole, fraction = ""] = draft.credits.split(".");
                const token = ++correctionSequence;
                const stamp = JSON.stringify(draft);
                const result = await request(base + "review/", "POST", { expected_version: data.expected_version, expected_position: data.expected_position,
                    registered_milli: Number(whole) * 1000 + Number(fraction.padEnd(3, "0")), reason: draft.reason, acknowledged: draft.acknowledged });
                if (!current(token)) return;
                if (JSON.stringify(draft) !== stamp) { edit(); panel.prepend(node("p", { role: "status" }, "Your draft changed while reviewing. Review it again.")); return; }
                panel.replaceChildren(node("h3", { class: "h5" }, "Review registered-credit correction"),
                    node("p", {}, `${data.course_code} · ${quantity(result.previous_registered_milli)} → ${quantity(result.registered_milli)} registered credits`),
                    node("p", {}, result.reason), node("p", {}, warning), node("p", { class: "form-text" }, "This correction takes effect when confirmed. The review expires after twenty minutes. Earlier load determinations remain unchanged."),
                    button("Confirm registered-credit correction", async () => {
                        const confirmation = ++correctionSequence;
                        await request(base + "confirm/", "POST", { operation_id: result.operation_id, expected_version: result.expected_version, expected_position: result.expected_position,
                            previous_registered_milli: result.previous_registered_milli, registered_milli: result.registered_milli,
                            reason: result.reason, acknowledged: result.acknowledged, review_token: result.review_token });
                        if (!current(confirmation)) return;
                        panel.replaceChildren(node("p", { role: "status" }, "Registered credits corrected. Earlier registration, award and charge facts remain retained."),
                            button("Refresh enrollment load", back), button("Review current registered credits", () => registeredCredits(container, student, item, back)));
                    }, "btn-primary"), button("Revise correction", edit),
                    button("Reload registered-credit evidence", () => registeredCredits(container, student, item, back, draft)), button("Back to enrollment load", back));
            }, submit); });
            panel.append(form);
        }
        async function history(cursor = null, previous = []) {
            const token = ++correctionSequence;
            const page = await request(base + "history/", "POST", { cursor });
            if (!current(token)) return;
            panel.replaceChildren(node("h3", { class: "h5" }, "Registered-credit history"), node("p", { class: "form-text" }, "Current corrections retain the original quantity. Staff reasons and raw history are excluded here."));
            for (const entry of page.items) panel.append(node("article", { class: "surface border p-3 my-2" },
                node("h4", { class: "h6" }, `${quantity(entry.previous_registered_milli)} → ${quantity(entry.registered_milli)} registered credits`), node("p", {}, `Recorded ${entry.recorded_at}`)));
            if (!page.items.length) panel.append(node("p", { role: "status" }, "No registered-credit corrections on this history page."));
            if (previous.length) panel.append(button("Previous credit history page", () => history(previous.at(-1), previous.slice(0, -1))));
            if (page.next_cursor) panel.append(button("More credit history", () => history(page.next_cursor, [...previous, cursor])));
            panel.append(button("Back to correction", edit), button("Back to enrollment load", back));
        }
        edit();
    }

    async function programs(search = "", after = null, stack = []) {
        const body = openDrawer("Program load policies", "INSTITUTION SETUP");
        const page = await request("/sis/enrollment-load/policy/programs/", "POST", { search, after });
        if (!body.isConnected) return;
        const input = node("input", { id: "load-program-search", class: "form-control", type: "search", maxlength: "80", value: search });
        const form = node("form", {}, field("Find a program", input), node("button", { type: "submit", class: "btn btn-primary" }, "Search"));
        form.addEventListener("submit", event => { event.preventDefault(); task(() => programs(input.value)); });
        body.append(form);
        for (const item of page.items) body.append(node("div", { class: "my-2" }, button(`${item.code} · ${item.title}`, () => policy(item.id))));
        if (!page.items.length) body.append(node("p", { role: "status" }, "No programs match."));
        if (stack.length) body.append(button("Previous programs", () => programs(search, stack.at(-1), stack.slice(0, -1))));
        if (page.next_after) body.append(button("More programs", () => programs(search, page.next_after, [...stack, after])));
        body.append(button("Institution policy", () => policy()));
    }
    async function policy(program = null) {
        const waiting = openDrawer("Enrollment load policy", "INSTITUTION SETUP");
        const saved = await request("/sis/enrollment-load/policy/view/", "POST", { program_id: program });
        if (!waiting.isConnected) return;
        let enabled = saved.state.definition !== null;
        let rows = (saved.state.definition?.bands || saved.global.definition?.bands || [{ code: "", label: "", min_credits_milli: 0 }])
            .map(b => ({ code: b.code, label: b.label, minimum: String(b.min_credits_milli / 1000) }));
        let reason = "";
        function render() {
            const body = openDrawer("Enrollment load policy", saved.program ? `${saved.program.code} · ${saved.program.title}` : "INSTITUTION POLICY");
            const checkbox = node("input", { id: "load-enabled", type: "checkbox", class: "form-check-input", checked: enabled });
            const cards = node("div"), reasonInput = node("textarea", { id: "load-reason", class: "form-control", required: true, maxlength: "1000" }, reason);
            const form = node("form", {}, node("p", { class: "form-text" }, "Define your institution's credit bands. Start at zero; each next minimum ends the previous band. Published changes apply to working reviews and do not rewrite earlier academic decisions."),
                node("div", { class: "form-check mb-3" }, checkbox, node("label", { for: checkbox.id, class: "form-check-label" },
                    program ? "Use separate program bands" : "Classify enrollment load")), cards);
            if (program) form.append(node("p", { class: "form-text" }, `Otherwise inherit the institution policy: ${showBands(saved.global.definition)}.`));
            checkbox.addEventListener("change", () => { enabled = checkbox.checked; render(); });
            if (enabled) rows.forEach((row, i) => {
                const code = node("input", { id: `load-code-${i}`, class: "form-control", required: true, maxlength: "32", value: row.code });
                const label = node("input", { id: `load-label-${i}`, class: "form-control", required: true, maxlength: "120", value: row.label });
                const min = node("input", { id: `load-min-${i}`, class: "form-control", inputmode: "decimal", required: true, value: row.minimum });
                code.addEventListener("input", () => { row.code = code.value.toUpperCase().trim(); });
                label.addEventListener("input", () => { row.label = label.value; });
                min.addEventListener("input", () => { row.minimum = min.value; min.setCustomValidity(""); });
                cards.append(node("fieldset", { class: "surface border rounded p-3 mb-3" }, node("legend", { class: "h6" }, `Band ${i + 1}`),
                    field("Code", code), field("Name", label), field("Minimum credits", min),
                    button("Remove band", () => { rows.splice(i, 1); render(); }, "btn-outline-danger")));
            });
            if (enabled && rows.length < 12) form.append(button("Add band", () => { rows.push({ code: "", label: "", minimum: "" }); render(); }));
            reasonInput.addEventListener("input", () => { reason = reasonInput.value; });
            const submit = node("button", { type: "submit", class: "btn btn-primary" }, "Review policy");
            form.append(field("Reason for change", reasonInput), node("div", { class: "inline-actions" }, submit,
                button(program ? "Institution policy" : "Program policies", () => program ? policy() : programs()),
                can("sis:audit:read") ? button("Publication history", () => history(program, render)) : null));
            form.addEventListener("submit", event => { event.preventDefault(); task(async () => {
                if (!form.reportValidity()) return;
                const bands = (enabled ? rows : []).map((r, i) => {
                    if (!/^(?:0|[1-9]\d{0,3})(?:\.\d{1,3})?$/.test(r.minimum)) {
                        const input = form.querySelector(`#load-min-${i}`); input.setCustomValidity("Use credits with up to three decimal places."); input.reportValidity();
                        throw new Error("Review the credit minimums.");
                    }
                    const [whole, fraction = ""] = r.minimum.split(".");
                    return { code: r.code, label: r.label, min_credits_milli: Number(whole) * 1000 + Number(fraction.padEnd(3, "0")) };
                });
                const reviewed = await request("/sis/enrollment-load/policy/review/", "POST", { program_id: program, definition: enabled ? { bands } : null,
                    revision: saved.revision, reason });
                if (!body.isConnected) return;
                const confirmation = openDrawer("Review load policy", "REVIEW → PUBLISH");
                confirmation.append(node("h3", { class: "h6" }, "Current bands"), node("p", {}, showBands(reviewed.before)),
                    node("h3", { class: "h6" }, "Proposed bands"), node("p", {}, showBands(reviewed.after)),
                    node("p", {}, reviewed.after === null ? (program ? "Use the institution's policy." : "Stop assigning a load classification.") : "Use these credit bands for working reviews."),
                    node("p", {}, reviewed.reason), node("div", { class: "inline-actions" }, button("Publish policy", async () => {
                        await request("/sis/enrollment-load/policy/publish/", "POST", reviewed);
                        if (confirmation.isConnected) await policy(program);
                    }, "btn-primary"), button("Back to editing", render)));
            }, submit); });
            body.append(form);
        }
        render();
    }
    async function history(program, back, cursor = null) {
        const body = openDrawer("Load policy history", "PUBLISHED POLICIES");
        const page = await request("/sis/enrollment-load/policy/history/", "POST", { program_id: program, cursor });
        if (!body.isConnected) return;
        for (const row of page.items) body.append(node("article", { class: "surface border p-3 mb-3" },
            node("h3", { class: "h6" }, `Publication ${row.version}`), node("p", {}, row.published_at),
            node("p", {}, showBands(row.definition)), node("p", {}, row.reason)));
        if (!page.items.length) body.append(node("p", {}, "No published policy yet."));
        if (page.next_cursor) body.append(button("Earlier publications", () => history(program, back, page.next_cursor)));
        body.append(button("Back", back));
    }
    function open(student, pursuit, initialHistory = null) {
        const body = openDrawer("Enrollment load", "STUDENT PROGRAM");
        mount(body, `/sis/students/${student}/programs/${pursuit}/load/`, { canDetermine: can("sis:load:determine"), canSelectPolicy: can('sis:load:policy'), canReadDeterminations: can("sis:load:determine"),
            initialHistory,
            checkDetermination: can("sis:load:determine") && can("sis:student:read") && can("sis:history:verify") && can("sis:audit:read")
                ? (id, state) => ui.historyChecks().open('load', id, () => open(student, pursuit, state)) : null,
            correctCredits: can("sis:registration:credits:amend") && can("sis:registration:manage")
                ? (container, item, back) => registeredCredits(container, student, item, back) : null });
    }
    return { policy, open };
};
