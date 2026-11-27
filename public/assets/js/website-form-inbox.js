/* Website inquiries use one private working queue, separate from admission applications. */
window.createWebsiteFormInbox = ({ root, request, node, button, inspect, message, labels, close }) => {
    let generation = 0, listGeneration = 0, state = 'all', scope = 'all', canManage = false, disposed = false;
    let currentCursor = null, previous = [], selected = null, directoryAbort = null;
    const drawer = document.getElementById('site-review');
    const body = document.getElementById('site-review-body');
    const text = key => labels[`form_${key}`];
    const active = ticket => !disposed && root.isConnected && ticket === generation;
    const control = (key, action, glyph = 'arrow-right', classes = 'btn btn-outline-primary') => node('button', {
        type: 'button', class: classes, onclick: () => { void action(); },
    }, node('i', { class: `bi bi-${glyph} me-2`, 'aria-hidden': 'true' }), text(key));
    async function load(cursor = null, stack = [], focus = null) {
        if (disposed || !root.isConnected) return;
        const ticket = ++listGeneration, chosen = { state, scope };
        root.replaceChildren(node('p', { role: 'status' }, text('loading')));
        let data;
        try {
            data = await request('/sis/website/form-inbox/?' + new URLSearchParams({ ...chosen, ...(cursor ? { after: cursor } : {}) }));
        } catch (error) {
            if (!disposed && ticket === listGeneration && ![401, 403].includes(error.status)) {
                root.replaceChildren(node('p', { role: 'alert' }, text('load_failed')),
                    control('refresh', () => load(cursor, stack), 'arrow-clockwise'));
            }
            return;
        }
        if (disposed || !root.isConnected || ticket !== listGeneration) return;
        currentCursor = cursor; previous = stack.slice(-5); canManage = data.can_manage;
        const filter = (key, values, current, changed) => {
            const select = node('select', { class: 'form-select', 'aria-label': text(key), onchange: () => changed(select.value) },
                ...values.map(([value, label]) => node('option', { value }, text(label))));
            select.value = current;
            return node('div', { class: 'col-12 col-md-6' }, node('label', { class: 'form-label' }, text(key), select));
        };
        const filters = node('div', { class: 'row g-3 mb-3' },
            filter('filter', ['all', 'open', 'closed'].map(value => [value, value]), state, value => { state = value; close(); void load(); }),
            filter('work_filter', [['all', 'work_all'], ['mine', 'work_mine'], ['unassigned', 'work_unassigned']], scope,
                value => { scope = value; close(); void load(); }));
        const cards = data.items.map(item => {
            const open = button('form_open_item', () => show(item.id), 'envelope-open'); open.dataset.inquiryId = item.id;
            return node('article', { class: 'site-card mb-3 text-break' },
                node('h2', { class: 'h5' }, item.form_title), node('p', {}, `${item.page_title} · ${item.submitted_at}`),
                node('p', {}, text(item.status)), node('p', {}, `${text('assigned_to')}: ${item.assignee_name || text('work_unassigned')}`), open);
        });
        root.replaceChildren(filters, node('div', { class: 'site-toolbar mb-3' },
            control('refresh', () => load(currentCursor, previous), 'arrow-clockwise')),
            ...cards, ...(cards.length ? [] : [node('p', { role: 'status' }, text('empty'))]),
            node('div', { class: 'site-toolbar' },
                ...(previous.length ? [control('previous', () => load(previous.at(-1), previous.slice(0, -1)), 'arrow-left')] : []),
                ...(data.next_after ? [control('more', () => load(data.next_after, [...previous, cursor]), 'arrow-right')] : []),
                ...(cursor ? [control('first', () => load(), 'arrow-bar-left')] : [])));
        if (focus) (root.querySelector(`[data-inquiry-id="${CSS.escape(focus)}"]`) || root.querySelector('select'))?.focus();
    }
    function back(id) {
        generation++; directoryAbort?.abort(); selected = null; close();
        void load(currentCursor, previous, id);
    }
    async function show(id, draft = null, notice = null) {
        if (disposed || !root.isConnected) return;
        const ticket = ++generation; selected = id; directoryAbort?.abort();
        const backControl = () => control('back_inbox', () => back(id), 'arrow-left');
        inspect(text('detail'), backControl(), node('p', { role: 'status' }, text('loading')));
        let data;
        try { data = await request(`/sis/website/form-inbox/${id}/`); }
        catch (error) {
            if (active(ticket) && ![401, 403].includes(error.status)) inspect(text('detail'), backControl(),
                node('p', { role: 'alert' }, text('load_failed')), control('reload_inquiry', () => show(id, draft), 'arrow-clockwise'));
            return;
        }
        if (!active(ticket)) return;
        const answers = node('dl');
        for (const field of data.fields) {
            if (!Object.hasOwn(data.answers, field.name)) continue;
            const value = data.answers[field.name];
            const format = item => field.options.find(option => option.value === item)?.label || String(item);
            answers.append(node('dt', {}, field.label), node('dd', { class: 'text-break', style: 'white-space:pre-wrap' },
                typeof value === 'boolean' ? text(value ? 'yes' : 'no') : Array.isArray(value) ? value.map(format).join(', ') : format(value)));
        }
        const actions = node('div', { class: 'site-toolbar' });
        if (canManage) {
            actions.append(button(data.status === 'open' ? 'form_close_item' : 'form_reopen_item', async () => {
                await request(`/sis/website/form-inbox/${id}/`, { version: Number(data.version), action: data.status === 'open' ? 'close' : 'reopen' });
                if (active(ticket)) { await load(currentCursor, previous); if (active(ticket)) await show(id, null, text('updated')); }
            }, 'check-lg'));
            actions.append(button('form_delete', () => {
                if (!active(ticket)) return;
                directoryAbort?.abort();
                inspect(text('delete'), node('p', {}, text('delete_help')), button('form_confirm_delete', async () => {
                    await request(`/sis/website/form-inbox/${id}/`, { version: Number(data.version), action: 'delete' });
                    if (active(ticket)) { message(text('deleted'), 'success'); back(id); }
                }, 'trash3'), control('back', () => show(id), 'arrow-left'));
            }, 'trash3', 'btn btn-outline-danger'));
        }
        const subscription = node('section', { class: 'site-card my-3' });
        if (data.newsletter) {
            const job = data.newsletter;
            subscription.append(node('h3', { class: 'h5' }, labels.newsletter_delivery),
                node('p', {}, labels[`newsletter_state_${job.state}`] || labels.newsletter_state_review),
                node('p', {}, labels[`newsletter_issue_${job.issue}`] || labels.newsletter_issue_uncertain));
            if (!job.delivery_enabled) subscription.append(node('p', { class: 'text-secondary' }, labels.newsletter_disabled));
            if (canManage && ['review', 'blocked'].includes(job.state)) subscription.append(button('newsletter_retry', () => {
                if (!active(ticket)) return;
                directoryAbort?.abort();
                inspect(labels.newsletter_retry, node('p', {}, labels.newsletter_retry_help), button('newsletter_retry_confirm', async () => {
                    await request(`/sis/website/form-inbox/${id}/newsletter/retry/`, { version: job.version });
                    if (active(ticket)) { message(labels.newsletter_retried, 'success'); await show(id); }
                }, 'arrow-repeat'), control('back', () => show(id), 'arrow-left'));
            }, 'arrow-repeat'));
        }
        const feedback = notice ? node('p', { role: 'status', tabindex: '-1' }, notice) : null;
        const editor = assignmentEditor(data, ticket, draft);
        inspect(data.form_title, backControl(), feedback,
            node('p', {}, `${data.page_title} · ${data.submitted_at}`), editor, answers,
            data.newsletter ? subscription : null, actions);
        if (feedback) feedback.focus();
        else if (draft) editor.querySelector('select')?.focus();
    }
    function assignmentEditor(data, ticket, initialDraft) {
        const work = data.assignment;
        const card = node('section', { class: 'site-card my-3 text-break', 'aria-label': text('assignment_title') },
            node('h3', { class: 'h5' }, text('assignment_title')),
            node('p', {}, `${text('assigned_to')}: ${work?.name || text('work_unassigned')}`),
            node('p', { class: 'form-text' }, text('assignment_help')));
        if (!work?.can_assign) return card;
        const draft = initialDraft || { mode: 'keep', search: '', selection: null };
        let saving = false, searching = false, directoryTicket = 0, pages = [];
        const owns = () => active(ticket) && card.isConnected && selected === data.id;
        const status = node('p', { role: 'status', 'aria-live': 'polite', tabindex: '-1', class: 'form-text text-break' });
        const mode = node('select', { class: 'form-select', 'aria-label': text('assignment_action') },
            ...['keep', 'claim', 'colleague', 'clear'].map(value => node('option', { value }, text(`assignment_${value}`))));
        mode.value = draft.mode;
        const search = node('input', { type: 'search', class: 'form-control', maxlength: 80, minlength: 2,
            'aria-label': text('colleague_search'), value: draft.search });
        const searchForm = node('form', { class: 'mb-3' });
        const results = node('div', { class: 'd-grid gap-2' });
        const chosen = node('p', { class: 'form-text' });
        const picker = node('div', {}, searchForm, chosen, results);
        const searchButton = node('button', { type: 'submit', class: 'btn btn-outline-primary mt-2' },
            node('i', { class: 'bi bi-search me-2', 'aria-hidden': 'true' }), text('search_colleagues'));
        searchForm.append(node('label', { class: 'form-label d-block' }, text('colleague_search'), search), searchButton);
        const save = control('assignment_save', submit, 'person-check', 'btn btn-primary');
        const refresh = control('reload_inquiry', () => show(data.id, draft), 'arrow-clockwise');
        function sync() {
            picker.hidden = draft.mode !== 'colleague';
            chosen.textContent = draft.selection ? `${text('selected_colleague')}: ${draft.selection.name}` : text('choose_colleague');
            save.disabled = saving || draft.mode === 'keep' || (draft.mode === 'colleague' && !draft.selection);
        }
        mode.addEventListener('change', () => {
            draft.mode = mode.value; directoryTicket++; directoryAbort?.abort(); results.replaceChildren(); status.textContent = ''; sync();
        });
        search.addEventListener('input', () => {
            draft.search = search.value; directoryTicket++; directoryAbort?.abort(); results.replaceChildren(); pages = [];
        });
        searchForm.addEventListener('submit', event => { event.preventDefault(); void find(); });
        async function find(after = null, stack = []) {
            if (!owns() || searching || draft.mode !== 'colleague') return;
            if (search.value.trim().length < 2 || !search.reportValidity()) { status.textContent = text('search_help'); status.focus(); return; }
            const serial = ++directoryTicket, query = search.value;
            const controller = new AbortController(); directoryAbort = controller;
            const timer = setTimeout(() => controller.abort(), 12000);
            searching = true; searchButton.disabled = true; results.replaceChildren(); status.textContent = text('searching_colleagues');
            try {
                const response = await request(`/sis/website/form-inbox/${data.id}/assignees/?` + new URLSearchParams({ search: query, ...(after ? { after } : {}) }), null, controller.signal);
                if (!owns() || serial !== directoryTicket || draft.mode !== 'colleague' || search.value !== query) return;
                pages = stack.slice(-5); status.setAttribute('role', 'status');
                status.textContent = response.items.length ? text('choose_colleague') : text('no_colleagues');
                for (const person of response.items) {
                    const name = `${person.given_name} ${person.family_name}`.trim();
                    results.append(control('select_colleague', () => {
                        draft.selection = { id: person.id, person_id: person.person_id, name }; sync(); save.focus();
                    }, 'person-plus', 'btn btn-outline-primary text-start text-break'));
                    results.lastElementChild.replaceChildren(node('i', { class: 'bi bi-person-plus me-2', 'aria-hidden': 'true' }), `${text('select_colleague')} · ${name}`);
                }
                if (!response.items.length) results.append(node('p', { role: 'status' }, text('no_colleagues')));
                results.append(node('div', { class: 'site-toolbar mt-2' },
                    ...(pages.length ? [control('previous_colleagues', () => find(pages.at(-1), pages.slice(0, -1)), 'arrow-left')] : []),
                    ...(response.next_after ? [control('more_colleagues', () => find(response.next_after, [...pages, after]), 'arrow-right')] : [])));
                if (after || stack.length) (results.querySelector('button') || status).focus();
            } catch (error) {
                if (owns() && serial === directoryTicket && ![401, 403].includes(error.status)) { status.textContent = text('search_failed'); status.focus(); }
            } finally {
                clearTimeout(timer); if (directoryAbort === controller) directoryAbort = null;
                searching = false; searchButton.disabled = false;
            }
        }
        async function submit() {
            if (!owns() || saving || save.disabled) return;
            const target = draft.mode === 'claim' ? { id: work.actor_id, person_id: work.actor_person_id }
                : draft.mode === 'clear' ? { id: null, person_id: null } : draft.selection;
            if (!target) return;
            saving = true; sync(); mode.disabled = true;
            status.setAttribute('role', 'status'); status.textContent = text('assignment_saving');
            const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 15000);
            try {
                await request(`/sis/website/form-inbox/${data.id}/assignment/`, { version: work.version,
                    inquiry_version: work.inquiry_version, assignee_id: target.id, assignee_person_id: target.person_id }, controller.signal);
                if (!owns()) return;
                await load(currentCursor, previous);
                if (owns()) await show(data.id, null, text('assignment_saved'));
            } catch (error) {
                if (owns() && ![401, 403].includes(error.status)) {
                    status.textContent = error.status === 409 ? text('assignment_stale') : text('assignment_failed');
                    status.setAttribute('role', 'alert'); status.focus();
                }
            } finally { clearTimeout(timer); saving = false; mode.disabled = false; sync(); }
        }
        card.append(node('label', { class: 'form-label' }, text('assignment_action'), mode), picker,
            node('div', { class: 'site-toolbar' }, save, refresh), status);
        sync(); return card;
    }
    drawer.addEventListener('hide.bs.offcanvas', () => { generation++; selected = null; directoryAbort?.abort(); });
    drawer.addEventListener('hidden.bs.offcanvas', () => { body.replaceChildren(); });
    const dispose = () => {
        disposed = true; generation++; listGeneration++; selected = null; directoryAbort?.abort();
        root.replaceChildren(); body.replaceChildren();
    };
    window.addEventListener('pagehide', dispose);
    window.addEventListener('site:access-denied', dispose);
    return { load };
};
