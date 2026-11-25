/* Published institutional GPA bands and minimized standing-proposal presentation. */
window.createStandingProposals = function (ui) {
    'use strict';
    const { node, request, task, openDrawer } = ui;
    const t = key => ui.labels[key];
    const number = value => (value / 1000).toFixed(3);
    const milli = value => {
        if (!/^\d{1,3}(\.\d{1,3})?$/.test(value)) throw new Error(t('error'));
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

    function summary(data, historical = false) {
        return card(node('h3', { class: 'h6' }, t(historical ? 'history' : 'calculated')),
            fact(t('gpa'), number(data.gpa_milli)), fact(t('credits'), number(data.gpa_credits_milli)),
            fact(t('as_of'), data.as_of), fact(t('current_version'), data.policy_version),
            historical ? null : node('p', { class: 'form-text mb-0' }, t('proposal_help')));
    }

    async function policy(back) {
        const loading = openDrawer(t('policy'), t('setup'));
        const saved = await request('/sis/academic-standing/proposal-policy/', 'POST', {});
        if (!loading.isConnected) return;
        let published = saved;
        let draft = { rules: structuredClone(saved.rules), reason: '', version: saved.version,
            minimumText: number(saved.rules.minimum_gpa_credits_milli) };
        draft.rules.bands.forEach(band => { band.floorText = number(band.minimum_gpa_milli); });
        let pending = false;
        const facts = () => ({ enabled: draft.rules.enabled, minimum_gpa_credits_milli: milli(draft.minimumText),
            bands: draft.rules.bands.map(band => ({ definition_id: band.definition_id, code: band.code, title: band.title,
                minimum_gpa_milli: milli(band.floorText) })).sort((a, b) => a.minimum_gpa_milli - b.minimum_gpa_milli) });
        const lock = (body, value) => {
            pending = value;
            body.setAttribute('aria-busy', String(value));
            body.querySelectorAll('input,textarea,button').forEach(control => { control.disabled = value; });
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

        async function choose(index, search = '', after = null, stack = []) {
            if (!draft || pending) return;
            const body = openDrawer(t('choose'), t('setup'));
            const page = await request('/sis/academic-standing/definitions/', 'POST', { search, after });
            if (!body.isConnected) return;
            const input = node('input', { id: 'standing-policy-search', type: 'search', class: 'form-control', maxlength: 80, value: search });
            const form = node('form', {}, field(t('find'), input),
                node('button', { type: 'submit', class: 'btn btn-primary' }, t('search')));
            form.addEventListener('submit', event => {
                event.preventDefault(); task(() => choose(index, input.value));
            });
            body.append(action('back', 'arrow-left', render), form);
            const active = page.items.filter(item => item.active);
            if (!active.length) body.append(node('p', { role: 'status' }, t('none')));
            for (const item of active) body.append(card(node('h3', { class: 'h6 text-break' }, `${item.code} · ${item.title}`),
                action('choose', 'check2', () => {
                    Object.assign(draft.rules.bands[index], { definition_id: item.id, code: item.code, title: item.title });
                    render();
                })));
            body.append(node('div', { class: 'inline-actions' },
                stack.length ? action('previous', 'arrow-left', () => choose(index, search, stack.at(-1), stack.slice(0, -1))) : null,
                page.next_after ? action('more', 'arrow-right', () => choose(index, search, page.next_after, [...stack, after])) : null));
        }

        function render() {
            if (!draft || pending) return;
            const body = openDrawer(t('policy'), t('setup'));
            if (back) body.append(action('back', 'arrow-left', back));
            body.append(node('p', {}, t('intro')), published.publication_id
                ? fact(t('current_version'), draft.version) : node('p', { role: 'status' }, t('unconfigured')));
            const enabled = node('input', { id: 'standing-policy-enabled', type: 'checkbox', class: 'form-check-input', checked: draft.rules.enabled });
            enabled.addEventListener('change', () => { draft.rules.enabled = enabled.checked; });
            const minimum = node('input', { id: 'standing-policy-credits', type: 'number', class: 'form-control',
                min: '0', max: '999.999', step: '0.001', required: true, value: draft.minimumText,
                'aria-describedby': 'standing-policy-credits-help' });
            minimum.addEventListener('input', () => { draft.minimumText = minimum.value; });
            const form = node('form', {}, card(node('div', { class: 'form-check mb-3' }, enabled,
                node('label', { for: enabled.id, class: 'form-check-label' }, t('enabled'))),
                field(t('minimum_credits'), minimum, t('minimum_help'))));
            if (!draft.rules.bands.length) form.append(node('p', { role: 'status' }, t('empty')));
            draft.rules.bands.forEach((band, index) => {
                const floor = node('input', { id: `standing-policy-floor-${index}`, type: 'number', class: 'form-control',
                    min: '0', max: '10', step: '0.001', required: true, value: band.floorText });
                floor.addEventListener('input', () => { band.floorText = floor.value; });
                form.append(card(node('h3', { class: 'h6' }, `${t('band')} ${index + 1}`),
                    field(t('floor'), floor), node('p', { class: 'fw-semibold text-break' }, band.title ? `${band.code} · ${band.title}` : t('choose')),
                    node('p', { class: 'form-text' }, t('choose_help')),
                    node('div', { class: 'inline-actions' }, action('choose', 'list-check', () => choose(index)),
                        action('remove', 'trash', () => { draft.rules.bands.splice(index, 1); render(); }, 'btn-outline-danger'))));
            });
            const reason = node('textarea', { id: 'standing-policy-reason', class: 'form-control', rows: 3,
                required: true, maxlength: 1000 });
            reason.value = draft.reason;
            reason.addEventListener('input', () => { draft.reason = reason.value; });
            const review = node('button', { type: 'submit', class: 'btn btn-primary' },
                node('i', { class: 'bi bi-check2-square', 'aria-hidden': 'true' }), ` ${t('review')}`);
            form.append(node('div', { class: 'inline-actions mb-3' }, draft.rules.bands.length < 20
                ? action('add', 'plus-lg', () => {
                    draft.rules.bands.push({ minimum_gpa_milli: 0, floorText: '0.000', definition_id: '', code: '', title: '' }); render();
                }) : null), field(t('reason'), reason), node('div', { class: 'inline-actions' }, review,
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
                    const command = { rules: facts(), version: draft.version, reason: draft.reason };
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

        function confirm(data) {
            const body = openDrawer(t('review_title'), t('setup'));
            body.append(node('h3', { class: 'h6' }, t('before')), published.publication_id
                ? fact(t('current_version'), data.version) : node('p', {}, t('unconfigured')));
            for (const band of published.rules.bands) body.append(fact(`${band.code} · ${band.title}`, number(band.minimum_gpa_milli)));
            body.append(node('h3', { class: 'h6' }, t('after')), node('p', {}, t(data.rules.enabled ? 'on' : 'off')),
                fact(t('minimum_credits'), number(data.rules.minimum_gpa_credits_milli)));
            for (const band of data.rules.bands) body.append(card(node('h3', { class: 'h6 text-break' }, `${band.code} · ${band.title}`),
                fact(t('floor'), number(band.minimum_gpa_milli))));
            body.append(node('p', { class: 'text-break' }, data.reason), node('div', { class: 'inline-actions' },
                action('publish', 'check2-circle', async () => {
                    if (pending) return;
                    lock(body, true);
                    try {
                        await request('/sis/academic-standing/proposal-policy/publish/', 'POST', data);
                        if (!body.isConnected || !draft) return;
                        pending = false; await policy(back);
                        const current = document.getElementById('drawer-body');
                        current?.prepend(node('p', { class: 'alert alert-success', role: 'status' }, t('published')));
                    } catch (error) { failure(body, error); }
                    finally { if (body.isConnected && draft) lock(body, false); }
                }, 'btn-primary'), action('edit', 'pencil', render)));
        }
        render();
    }

    return { policy, summary, t };
};
