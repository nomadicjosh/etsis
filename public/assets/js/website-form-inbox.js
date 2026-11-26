/* Website inquiries have an independent private inbox, separate from admission applications. */
window.createWebsiteFormInbox = ({ root, request, node, button, inspect, message, labels }) => {
    let generation = 0, after = null, state = 'all', canManage = false, disposed = false;
    const text = key => labels[`form_${key}`];
    async function load(cursor = null) {
        if (disposed) return;
        const current = ++generation;
        root.replaceChildren(node('p', { role: 'status' }, text('loading')));
        const data = await request('/sis/website/form-inbox/?' + new URLSearchParams({ state, ...(cursor ? { after: cursor } : {}) }));
        if (current !== generation) return;
        after = data.next_after; canManage = data.can_manage;
        const filter = node('select', { class: 'form-select', 'aria-label': text('filter'), onchange: () => { state = filter.value; void load().catch(error => message(error.message, 'danger')); } },
            ...['all', 'open', 'closed'].map(value => node('option', { value }, text(value)))); filter.value = state;
        const cards = data.items.map(item => node('article', { class: 'site-card mb-3' },
            node('h2', { class: 'h5' }, item.form_title), node('p', {}, `${item.page_title} · ${item.submitted_at}`),
            node('p', {}, text(item.status)), button('form_open_item', () => open(item.id), 'envelope-open')));
        root.replaceChildren(node('div', { class: 'site-toolbar mb-3' }, filter, button('form_refresh', () => load(), 'arrow-clockwise')),
            ...cards, ...(cards.length ? [] : [node('p', {}, text('empty'))]),
            ...(after ? [button('form_more', () => load(after), 'arrow-right')] : []),
            ...(cursor ? [button('form_first', () => load(), 'arrow-left')] : []));
    }
    async function open(id) {
        if (disposed) return;
        const current = ++generation;
        const data = await request(`/sis/website/form-inbox/${id}/`);
        if (current !== generation) return;
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
                if (current === generation) { await load(); inspect(text('detail'), node('p', {}, text('updated'))); }
            }, 'check-lg'));
            actions.append(button('form_delete', () => {
                inspect(text('delete'), node('p', {}, text('delete_help')), button('form_confirm_delete', async () => {
                    await request(`/sis/website/form-inbox/${id}/`, { version: Number(data.version), action: 'delete' });
                    await load(); inspect(text('detail'), node('p', {}, text('deleted')));
                }, 'trash3'), button('form_back', () => open(id), 'arrow-left'));
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
                inspect(labels.newsletter_retry, node('p', {}, labels.newsletter_retry_help), button('newsletter_retry_confirm', async () => {
                    await request(`/sis/website/form-inbox/${id}/newsletter/retry/`, { version: job.version });
                    if (current === generation) { message(labels.newsletter_retried, 'success'); await open(id); }
                }, 'arrow-repeat'), button('newsletter_back_inquiry', () => open(id), 'arrow-left'));
            }, 'arrow-repeat'));
        }
        inspect(data.form_title, node('p', {}, `${data.page_title} · ${data.submitted_at}`), answers,
            data.newsletter ? subscription : null, actions);
    }
    document.getElementById('site-review').addEventListener('hide.bs.offcanvas', () => { generation++; });
    document.getElementById('site-review').addEventListener('hidden.bs.offcanvas', () => { document.getElementById('site-review-body').replaceChildren(); });
    const dispose = () => { disposed = true; generation++; root.replaceChildren(); document.getElementById('site-review-body').replaceChildren(); };
    window.addEventListener('pagehide', dispose);
    window.addEventListener('site:access-denied', dispose);
    return { load };
};
