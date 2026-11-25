/* Reviewed standing policies with program, cohort-level and institutional precedence. */
window.createStandingProposals = function (ui) {
    'use strict';
    const { node, request, task, openDrawer } = ui;
    const t = key => ui.labels[key];
    const number = value => (value / 1000).toFixed(3);
    const milli = value => {
        if (!/^\d{1,3}(\.\d{1,3})?$/.test(value)) throw new Error(t('values_error'));
        const [whole, fraction = ''] = value.split('.');
        return Number(whole) * 1000 + Number(fraction.padEnd(3, '0'));
    };
    const action = (key, icon, run, style = 'btn-outline-primary') => node('button', {
        type: 'button', class: `btn ${style}`, onclick: event => task(run, event.currentTarget),
    }, node('i', { class: `bi bi-${icon}`, 'aria-hidden': 'true' }), ` ${t(key)}`);
    const field = (label, input, help = null) => node('div', { class: 'mb-3' },
        node('label', { for: input.id, class: 'form-label' }, label), input,
        help ? node('p', { id: `${input.id}-help`, class: 'form-text mb-0' }, help) : null);
    const fact = (label, value) => node('div', { class: 'mb-2 text-break' },
        node('span', { class: 'fw-semibold' }, `${label}: `), String(value));
    const card = (...children) => node('section', { class: 'record-section bg-white border rounded p-3 mb-3' }, ...children);
    const scopeName = scope => scope ? `${t(scope.kind)}${scope.title ? ` · ${scope.code} · ${scope.title}` : ''}` : t('scope_unknown');

    function summary(data, historical = false) {
        return card(node('h3', { class: 'h6' }, t(historical ? 'history' : 'calculated')),
            fact(t('applied_scope'), scopeName(data.scope)),
            fact(t('gpa_basis'), t(data.assessment?.basis || 'cumulative')),
            data.assessment?.term_code ? fact(t('choose_term'), data.assessment.term_code) : null,
            fact(t('gpa'), number(data.gpa_milli)), fact(t('credits'), number(data.gpa_credits_milli)),
            fact(t('as_of'), data.as_of), fact(t('current_version'), data.policy_version),
            historical ? null : node('p', { class: 'form-text mb-0' }, t('proposal_help')));
    }

    async function policy(back) {
        const loading = openDrawer(t('policy'), t('setup'));
        const saved = await request('/sis/academic-standing/proposal-policy/', 'POST', {});
        if (!loading.isConnected) return;
        const draftSet = rules => ({ enabled: rules.enabled, gpa_basis: rules.gpa_basis || 'cumulative', minimumText: number(rules.minimum_gpa_credits_milli),
            bands: rules.bands.map(band => ({ ...band, floorText: number(band.minimum_gpa_milli) })) });
        let published = saved;
        let draft = { rules: draftSet(saved.rules), overrides: (saved.rules.overrides || []).map(scope =>
            ({ kind: scope.kind, target: { ...scope.target }, rules: draftSet(scope.rules) })), reason: '', version: saved.version };
        let pending = false;
        const setFacts = set => ({ enabled: set.enabled, gpa_basis: set.gpa_basis, minimum_gpa_credits_milli: milli(set.minimumText),
            bands: set.bands.map(band => ({ definition_id: band.definition_id, code: band.code, title: band.title,
                minimum_gpa_milli: milli(band.floorText) })).sort((a, b) => a.minimum_gpa_milli - b.minimum_gpa_milli) });
        const facts = () => ({ ...setFacts(draft.rules), overrides: draft.overrides.map(scope =>
            ({ kind: scope.kind, target: { ...scope.target }, rules: setFacts(scope.rules) })) });
        const lock = (body, value) => {
            pending = value;
            body.setAttribute('aria-busy', String(value));
            body.querySelectorAll('input,textarea,button,select').forEach(control => { control.disabled = value; });
        };
        function failure(body, error) {
            if (!body.isConnected) return;
            if (error.status === 403 || error.status === 401) {
                draft = null;
                body.replaceChildren(...[node('p', { class: 'alert alert-warning', role: 'alert' }, t('denied')),
                    back ? action('back', 'arrow-left', back) : null].filter(Boolean));
                return;
            }
            body.prepend(node('p', { class: 'alert alert-warning', role: 'alert' }, error.message || t('error')));
        }

        async function picker(kind, returnTo, use, search = '', after = null, stack = []) {
            if (!draft || pending) return;
            const title = kind === 'definition' ? t('choose') : t(kind === 'program' ? 'choose_program' : 'choose_level');
            const body = openDrawer(title, t('setup'));
            let page;
            try {
                page = await request(kind === 'definition' ? '/sis/academic-standing/definitions/'
                    : '/sis/academic-standing/proposal-policy/targets/', 'POST', { search, after, ...(kind === 'definition' ? {} : { kind }) });
            } catch (error) { failure(body, error); return; }
            if (!body.isConnected || !draft) return;
            const input = node('input', { id: 'standing-policy-search', type: 'search', class: 'form-control', maxlength: 80, value: search });
            const form = node('form', {}, field(kind === 'definition' ? t('find') : t('find_target'), input),
                node('button', { type: 'submit', class: 'btn btn-primary' }, t(kind === 'definition' ? 'search' : 'search_targets')));
            form.addEventListener('submit', event => {
                event.preventDefault(); task(() => picker(kind, returnTo, use, input.value));
            });
            body.append(action('back', 'arrow-left', returnTo), form);
            const active = page.items.filter(item => item.active);
            if (!active.length) body.append(node('p', { role: 'status' }, t(kind === 'definition' ? 'none' : 'target_none')));
            for (const item of active) body.append(card(node('h3', { class: 'h6 text-break' }, `${item.code} · ${item.title}`),
                action(kind === 'definition' ? 'choose' : 'use_target', 'check2', () => use(item))));
            body.append(node('div', { class: 'inline-actions' },
                stack.length ? action(kind === 'definition' ? 'previous' : 'previous_targets', 'arrow-left', () => picker(kind, returnTo, use, search, stack.at(-1), stack.slice(0, -1))) : null,
                page.next_after ? action(kind === 'definition' ? 'more' : 'more_targets', 'arrow-right', () => picker(kind, returnTo, use, search, page.next_after, [...stack, after])) : null));
        }

        function controls(set, prefix, returnTo, isDefault) {
            const enabled = node('input', { id: `${prefix}-enabled`, type: 'checkbox', class: 'form-check-input', checked: set.enabled });
            enabled.addEventListener('change', () => { set.enabled = enabled.checked; });
            const minimum = node('input', { id: `${prefix}-credits`, type: 'number', class: 'form-control',
                min: '0', max: '999.999', step: '0.001', required: true, value: set.minimumText,
                'aria-describedby': `${prefix}-credits-help` });
            minimum.addEventListener('input', () => { set.minimumText = minimum.value; });
            const basis = node('select', { id: `${prefix}-basis`, class: 'form-select',
                'aria-describedby': `${prefix}-basis-help` },
                node('option', { value: 'cumulative' }, t('basis_cumulative')), node('option', { value: 'term' }, t('basis_term')));
            basis.value = set.gpa_basis;
            basis.addEventListener('change', () => { set.gpa_basis = basis.value; });
            const container = node('div', {}, card(node('div', { class: 'form-check mb-3' }, enabled,
                node('label', { for: enabled.id, class: 'form-check-label' }, t(isDefault ? 'enabled' : 'override_enabled'))),
                field(t('gpa_basis'), basis, t('basis_help')), field(t('minimum_credits'), minimum, t('minimum_help'))));
            if (!set.bands.length) container.append(node('p', { role: 'status' }, t('empty')));
            set.bands.forEach((band, index) => {
                const floor = node('input', { id: `${prefix}-floor-${index}`, type: 'number', class: 'form-control',
                    min: '0', max: '10', step: '0.001', required: true, value: band.floorText });
                floor.addEventListener('input', () => { band.floorText = floor.value; });
                container.append(card(node('h3', { class: 'h6' }, `${t('band')} ${index + 1}`),
                    field(t('floor'), floor), node('p', { class: 'fw-semibold text-break' }, band.title ? `${band.code} · ${band.title}` : t('choose')),
                    node('p', { class: 'form-text' }, t('choose_help')),
                    node('div', { class: 'inline-actions' }, action('choose', 'list-check', () => picker('definition', returnTo, item => {
                        Object.assign(band, { definition_id: item.id, code: item.code, title: item.title }); returnTo();
                    })), action('remove', 'trash', () => { set.bands.splice(index, 1); returnTo(); }, 'btn-outline-danger'))));
            });
            if (set.bands.length < 20) container.append(node('div', { class: 'inline-actions mb-3' }, action('add', 'plus-lg', () => {
                set.bands.push({ floorText: '0.000', definition_id: '', code: '', title: '' }); returnTo();
            })));
            return container;
        }

        function editOverride(index) {
            if (!draft || pending) return;
            const scope = draft.overrides[index];
            const body = openDrawer(t('edit_override'), t('setup'));
            const again = () => editOverride(index);
            body.append(action('back_policy', 'arrow-left', render), card(node('h3', { class: 'h6 text-break' },
                scopeName({ kind: scope.kind, ...scope.target })), node('p', { class: 'form-text' }, t('override_help')),
                action('choose_target', 'arrow-repeat', () => picker(scope.kind, again, item => {
                    if (draft.overrides.some((other, position) => position !== index && other.kind === scope.kind && other.target.id === item.id)) {
                        throw new Error(t('duplicate_target'));
                    }
                    scope.target = { id: item.id, code: item.code, title: item.title }; again();
                }))));
            const form = node('form', {}, controls(scope.rules, `standing-override-${index}`, again, false));
            const done = node('button', { type: 'submit', class: 'btn btn-primary' },
                node('i', { class: 'bi bi-check2', 'aria-hidden': 'true' }), ` ${t('back_policy')}`);
            form.append(done);
            form.addEventListener('submit', event => { event.preventDefault(); if (form.reportValidity()) render(); });
            body.append(form);
        }

        function addOverride(kind) {
            return picker(kind, render, item => {
                const existing = draft.overrides.findIndex(scope => scope.kind === kind && scope.target.id === item.id);
                if (existing >= 0) {
                    draft.overrides[existing].target = { id: item.id, code: item.code, title: item.title };
                    editOverride(existing); return;
                }
                const set = structuredClone(draft.rules);
                set.enabled = true;
                draft.overrides.push({ kind, target: { id: item.id, code: item.code, title: item.title }, rules: set });
                editOverride(draft.overrides.length - 1);
            });
        }

        function render() {
            if (!draft || pending) return;
            const body = openDrawer(t('policy'), t('setup'));
            if (back) body.append(action('back', 'arrow-left', back));
            body.append(node('p', {}, t('intro')), node('p', { class: 'alert alert-info' }, t('precedence')), published.publication_id
                ? fact(t('current_version'), draft.version) : node('p', { role: 'status' }, t('unconfigured')));
            const form = node('form', {}, node('h3', { class: 'h6' }, t('institution')),
                controls(draft.rules, 'standing-policy', render, true), node('h3', { class: 'h6' }, t('overrides')),
                node('p', { class: 'form-text' }, t('override_help')));
            for (const [index, scope] of draft.overrides.entries()) form.append(card(node('h3', { class: 'h6 text-break' },
                scopeName({ kind: scope.kind, ...scope.target })), node('p', {}, t(scope.rules.enabled ? 'on' : 'off')),
                node('div', { class: 'inline-actions' }, action('edit_override', 'pencil', () => editOverride(index)),
                    action('remove_override', 'trash', () => { draft.overrides.splice(index, 1); render(); }, 'btn-outline-danger'))));
            if (draft.overrides.length < 20) form.append(node('div', { class: 'inline-actions mb-3' },
                action('add_program', 'plus-lg', () => addOverride('program')), action('add_level', 'plus-lg', () => addOverride('academic_level'))));
            const reason = node('textarea', { id: 'standing-policy-reason', class: 'form-control', rows: 3, required: true, maxlength: 1000 });
            reason.value = draft.reason;
            reason.addEventListener('input', () => { draft.reason = reason.value; });
            const review = node('button', { type: 'submit', class: 'btn btn-primary' },
                node('i', { class: 'bi bi-check2-square', 'aria-hidden': 'true' }), ` ${t('review')}`);
            form.append(field(t('reason'), reason), node('div', { class: 'inline-actions' }, review,
                action('refresh', 'arrow-clockwise', async () => {
                    if (pending) return;
                    lock(body, true);
                    try {
                        const current = await request('/sis/academic-standing/proposal-policy/', 'POST', {});
                        if (!body.isConnected || !draft) return;
                        published = current; draft.version = current.version; pending = false; render();
                    } catch (error) { failure(body, error); }
                    finally { if (body.isConnected && draft) lock(body, false); }
                })), node('p', { class: 'form-text' }, t('refresh_help')));
            form.addEventListener('submit', event => {
                event.preventDefault(); task(async () => {
                    if (pending || !form.reportValidity()) return;
                    let command;
                    try { command = { rules: facts(), version: draft.version, reason: draft.reason }; }
                    catch (error) { failure(body, error); return; }
                    lock(body, true);
                    try {
                        const data = await request('/sis/academic-standing/proposal-policy/review/', 'POST', command);
                        if (!body.isConnected || !draft) return;
                        lock(body, false); confirm(data);
                    } catch (error) { failure(body, error); }
                    finally { if (body.isConnected && draft) lock(body, false); }
                }, review);
            });
            body.append(form);
        }

        function policyDescription(rules) {
            const contents = node('div', {}, node('p', {}, t(rules.enabled ? 'on' : 'off')),
                fact(t('gpa_basis'), t(rules.gpa_basis || 'cumulative')),
                fact(t('minimum_credits'), number(rules.minimum_gpa_credits_milli)));
            for (const band of rules.bands) contents.append(fact(`${band.code} · ${band.title}`, `${t('floor')} ${number(band.minimum_gpa_milli)}`));
            return contents;
        }
        function overrideDescriptions(rules) {
            return (rules.overrides || []).map(scope => node('details', { class: 'record-section bg-white border rounded p-3 mb-3' },
                node('summary', { class: 'fw-semibold text-break' }, scopeName({ kind: scope.kind, ...scope.target })),
                policyDescription(scope.rules)));
        }
        function confirm(data) {
            const body = openDrawer(t('review_title'), t('setup'));
            body.append(node('h3', { class: 'h6' }, t('before')), published.publication_id
                ? node('div', {}, fact(t('current_version'), data.version), policyDescription(published.rules), ...overrideDescriptions(published.rules))
                : node('p', {}, t('unconfigured')), node('h3', { class: 'h6' }, t('after')), node('p', { class: 'alert alert-info' }, t('precedence')),
                policyDescription(data.rules), ...overrideDescriptions(data.rules), node('p', { class: 'text-break' }, data.reason),
                node('div', { class: 'inline-actions' }, action('publish', 'check2-circle', async () => {
                    if (pending) return;
                    lock(body, true);
                    try {
                        await request('/sis/academic-standing/proposal-policy/publish/', 'POST', data);
                        if (!body.isConnected || !draft) return;
                        pending = false; await policy(back);
                        document.getElementById('drawer-body')?.prepend(node('p', { class: 'alert alert-success', role: 'status' }, t('published')));
                    } catch (error) { failure(body, error); }
                    finally { if (body.isConnected && draft) lock(body, false); }
                }, 'btn-primary'), action('edit', 'pencil', render)));
        }
        render();
    }
    async function termPicker(base, back, choose, deny = null, search = '', after = null, trail = []) {
        const body = openDrawer(t('choose_term'), t('term_context'));
        let page;
        try { page = await request(base + 'proposal-terms/', 'POST', { search, after }); }
        catch (error) {
            if ([401, 403].includes(error.status)) {
                deny?.();
                if (body.isConnected) body.replaceChildren(node('p', { class: 'alert alert-warning', role: 'alert' }, error.message));
                return;
            }
            if (body.isConnected) body.append(node('p', { class: 'alert alert-warning', role: 'alert' }, error.message),
                action('back_standing', 'arrow-left', back));
            return;
        }
        if (!body.isConnected) return;
        const input = node('input', { id: 'standing-proposal-term-search', type: 'search', class: 'form-control',
            maxlength: 80, value: search });
        const form = node('form', {}, field(t('find_term'), input),
            node('button', { type: 'submit', class: 'btn btn-primary' }, t('search_terms')));
        form.addEventListener('submit', event => { event.preventDefault(); task(() => termPicker(base, back, choose, deny, input.value)); });
        body.append(action('back_standing', 'arrow-left', back), node('p', { class: 'form-text' }, t('term_help')), form);
        if (!page.items.length) body.append(node('p', { role: 'status' }, t('no_terms')));
        for (const item of page.items) body.append(card(node('h3', { class: 'h6 text-break' }, item.code),
            node('p', { class: 'form-text' }, `${item.starts_on} – ${item.ends_on}`),
            action('use_term', 'check2', () => choose(item))));
        body.append(node('div', { class: 'inline-actions' },
            trail.length ? action('previous_terms', 'arrow-left', () => termPicker(base, back, choose, deny, search,
                trail.at(-1), trail.slice(0, -1))) : null,
            page.next_after ? action('more_terms', 'arrow-right', () => termPicker(base, back, choose, deny, search,
                page.next_after, [...trail, after])) : null));
    }
    return { policy, summary, termPicker, t };
};
