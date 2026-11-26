/* Connection secrets are write-only controls and never enter browser persistence or job payloads. */
window.createWebsiteNewsletters = ({ root, request, node, button, inspect, message, labels }) => {
    const t = key => labels[`newsletter_${key}`];
    let generation = 0, disposed = false, dirty = false;
    async function load(after = null) {
        if (disposed) return;
        const current = ++generation;
        const data = await request('/sis/website/newsletters/' + (after ? `?after=${encodeURIComponent(after)}` : ''));
        if (disposed || current !== generation) return;
        root.replaceChildren(node('p', { class: data.delivery_enabled ? 'alert alert-info' : 'alert alert-warning' },
            t(data.delivery_enabled ? 'enabled' : 'disabled')), button('newsletter_add', () => edit(), 'plus-lg'),
            ...data.items.map(item => node('article', { class: 'site-card my-3' },
                node('h2', { class: 'h5' }, item.name), node('p', {}, `${t(item.provider)} · ${t(item.enabled ? 'active' : 'paused')}`),
                button('newsletter_edit', () => open(item.id), 'pencil-square'))),
            ...(data.items.length ? [] : [node('p', { class: 'mt-3' }, t('empty'))]),
            ...(data.next_after ? [button('newsletter_more', () => load(data.next_after), 'arrow-right')] : []),
            ...(after ? [button('newsletter_first', () => load(), 'arrow-left')] : []));
    }
    async function open(id) {
        const current = ++generation;
        const data = await request(`/sis/website/newsletters/${id}/`);
        if (!disposed && current === generation) edit(data);
    }
    function edit(existing = null) {
        if (disposed) return;
        const current = ++generation, controls = {};
        const form = node('form', { class: 'site-card', autocomplete: 'off' });
        const field = (key, type = 'text', value = '', maximum = 120) => {
            const id = `newsletter-${key}-${crypto.randomUUID()}`;
            const control = node('input', { type, id, class: type === 'checkbox' ? 'form-check-input me-2' : 'form-control',
                autocomplete: type === 'password' ? 'new-password' : 'off', maxlength: maximum,
                oninput: () => { dirty = true; } });
            if (type === 'checkbox') control.checked = !!value; else control.value = value;
            const wrap = node('div', { class: 'mb-3' });
            if (type === 'checkbox') wrap.append(node('label', { for: id, class: 'form-check-label' }, control, t(key)));
            else wrap.append(node('label', { for: id, class: 'form-label' }, t(key)), control);
            form.append(wrap); controls[key] = control; return control;
        };
        field('name', 'text', existing?.name || '').required = true;
        let provider;
        if (!existing) {
            provider = node('select', { id: 'newsletter-provider', class: 'form-select', onchange: () => {
                controls.username.parentElement.hidden = provider.value !== 'mautic';
                controls.idempotent_receiver.parentElement.hidden = provider.value !== 'webhook';
                controls.secret.minLength = provider.value === 'webhook' ? 32 : 1;
                controls.url.placeholder = { sendy: 'https://news.example.edu/sendy', mailchimp: 'https://us19.api.mailchimp.com',
                    mautic: 'https://leads.example.edu', webhook: 'https://integration.example.edu/subscribe' }[provider.value];
                dirty = true;
            } }, ...['sendy', 'mailchimp', 'mautic', 'webhook'].map(value => node('option', { value }, t(value))));
            form.append(node('div', { class: 'mb-3' }, node('label', { for: provider.id, class: 'form-label' }, t('provider')), provider));
            field('url', 'url', '', 2048).required = true;
            controls.url.placeholder = 'https://news.example.edu/sendy';
            field('list', 'text', '', 120).required = true;
            form.append(node('p', { class: 'form-text' }, t('destination_help')));
        } else form.append(node('p', {}, `${t(existing.provider)} · ${existing.host} · ${existing.list}`),
            node('p', { class: 'form-text' }, t('immutable')));
        field('secret', 'password', '', 4096).required = !existing;
        form.append(node('p', { class: 'form-text' }, t(existing ? 'secret_keep' : 'secret_help')));
        field('username', 'text', '', 191).parentElement.hidden = (existing?.provider || 'sendy') !== 'mautic';
        field('idempotent_receiver', 'checkbox', existing?.provider === 'webhook').parentElement.hidden = (existing?.provider || 'sendy') !== 'webhook';
        field('connection_enabled', 'checkbox', existing ? !!Number(existing.enabled) : false);
        const save = node('button', { type: 'submit', class: 'btn btn-primary' }, t('save'));
        const feedback = node('p', { role: 'status', tabindex: '-1', class: 'my-3' });
        form.append(feedback, save);
        const back = button('newsletter_back', () => {
            if (dirty && !confirm(t('discard'))) return;
            controls.secret.value = ''; controls.username.value = ''; dirty = false; return load();
        }, 'arrow-left');
        root.replaceChildren(back, form);
        form.addEventListener('submit', async event => {
            event.preventDefault(); if (!form.reportValidity() || save.disabled) return;
            save.disabled = true; feedback.textContent = t('saving');
            const body = { id: existing?.id || null, version: existing ? Number(existing.version) : null,
                name: controls.name.value, secret: controls.secret.value, username: controls.username.value,
                idempotent_receiver: controls.idempotent_receiver.checked, enabled: controls.connection_enabled.checked };
            if (!existing) Object.assign(body, { provider: provider.value, url: controls.url.value, list: controls.list.value });
            try {
                await request('/sis/website/newsletters/', body);
                controls.secret.value = ''; controls.username.value = '';
                if (disposed || current !== generation) return;
                dirty = false; message(t('saved'), 'success'); await load();
            } catch (error) {
                if (!disposed && current === generation) { feedback.textContent = error.message; feedback.focus(); save.disabled = false; }
            }
        });
        controls.name.focus(); dirty = false;
    }
    const dispose = () => { disposed = true; generation++; dirty = false;
        root.querySelectorAll('input[type="password"]').forEach(input => { input.value = ''; }); root.replaceChildren(); };
    window.addEventListener('site:access-denied', dispose); window.addEventListener('pagehide', dispose);
    window.addEventListener('beforeunload', event => { if (dirty) event.preventDefault(); });
    return { load };
};
