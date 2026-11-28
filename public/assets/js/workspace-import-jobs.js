/* Private server import activity and create-only people intake. No browser persistence. */
'use strict';
window.createWorkspaceImportJobs = ({ node, request, task, openDrawer, can, notify, table, labels }) => {
    const text = key => labels[key] || key;
    const icon = name => node('i', { class: `bi bi-${name}`, 'aria-hidden': 'true' });
    const button = (key, glyph, run, primary = false) => {
        const el = node('button', { type: 'button', class: `btn btn-${primary ? 'primary' : 'outline-secondary'}` }, icon(glyph), text(key));
        el.addEventListener('click', () => task(run, el));
        return el;
    };
    const wrap = content => node('div', { class: 'table-responsive', tabindex: '0', role: 'region', 'aria-label': text('review') }, content);
    const sentence = (key, data) => text(key).replace(/\{(\w+)\}/g, (_, field) => String(data[field] ?? ''));
    async function activity(after = null, previous = []) {
        const body = openDrawer(text('activity'));
        body.append(node('p', { role: 'status' }, text('loading')));
        const result = await request(`/sis/imports/jobs/${after ? '?after=' + encodeURIComponent(after) : ''}`);
        if (!body.isConnected) return;
        const rows = result.items.map(item => [item.target.replaceAll('_', ' '), text(item.state), `${item.processed} / ${item.total}`,
            button('details', 'arrow-right', () => detail(item.id, () => activity(after, previous)))]);
        const paging = node('nav', { class: 'd-flex flex-wrap gap-2', 'aria-label': text('pages') });
        if (previous.length) paging.append(button('previous', 'arrow-left', () => {
            const stack = [...previous]; return activity(stack.pop(), stack);
        }));
        if (result.next_after) paging.append(button('next', 'arrow-right', () => activity(result.next_after, [...previous, after])));
        body.replaceChildren(node('p', { class: 'form-text' }, text('server')),
            button('refresh', 'arrow-clockwise', () => activity(after, previous)),
            result.items.length ? wrap(table([text('destination'), text('state'), text('processed'), ''], rows)) : node('p', { class: 'mt-3' }, text('empty')), paging);
    }
    async function detail(id, back = activity) {
        const body = openDrawer(text('progress'));
        body.append(node('p', { role: 'status' }, text('loading')));
        const item = await request(`/sis/imports/jobs/${encodeURIComponent(id)}/`);
        if (!body.isConnected) return;
        const summary = node('dl', { class: 'record-summary record-summary-paired' });
        for (const key of ['state', 'processed', 'total', 'imported', 'linked', 'skipped', 'omitted']) {
            summary.append(node('div', {}, node('dt', {}, text(key === 'imported' ? 'created' : key === 'total' ? 'rows' : key)),
                node('dd', {}, key === 'state' ? text(item.state) : String(item[key]))));
        }
        body.replaceChildren(button('back_activity', 'arrow-left', back), summary,
            node('p', { class: item.state === 'needs_attention' ? 'alert alert-warning' : 'alert alert-info', role: 'status' },
                item.state === 'needs_attention' ? sentence('stopped', item) : text(item.state === 'completed'
                    ? 'completed' : item.state === 'cancelled' ? 'cancelled_help' : 'queued_help')));
        if (item.error_code) body.append(node('p', {}, text(item.error_code)));
        body.append(button('refresh', 'arrow-clockwise', () => detail(id, back)));
        if (['queued', 'running'].includes(item.state)) body.append(
            node('p', { class: 'form-text mt-3' }, text('cancel_help')),
            button('cancel', 'stop-circle', async () => {
                await request(`/sis/imports/jobs/${encodeURIComponent(id)}/cancel/`, 'POST', {});
                if (body.isConnected) await detail(id, back);
            }));
    }
    function people() {
        if (!can('sis:person:manage')) return;
        const body = openDrawer(text('people'), text('setup'));
        let csv = '', headers = [], reviewed = null, rows = [], page = 0, generation = 0;
        const file = node('input', { type: 'file', id: 'people-import-file', class: 'form-control', accept: '.csv,text/csv', required: true });
        const delimiter = node('select', { id: 'people-import-delimiter', class: 'form-select' },
            [',', ';', '\t'].map((value, i) => node('option', { value }, text(['comma', 'semicolon', 'tab'][i]))));
        const reason = node('textarea', { id: 'people-import-reason', class: 'form-control', maxlength: '500', rows: '3', required: true });
        const field = (key, control) => node('div', { class: 'mb-3' }, node('label', { for: control.id, class: 'form-label' }, text(key)), control);
        const setup = node('fieldset', { class: 'record-form-section' }, node('legend', {}, text('setup')),
            field('file', file), field('delimiter', delimiter), field('reason', reason),
            node('a', { href: '/assets/examples/people.csv', download: '', class: 'btn btn-outline-secondary' }, icon('download'), text('sample')));
        const mapping = node('fieldset', { class: 'record-form-section', hidden: true }, node('legend', {}, text('map')));
        const plan = node('div', { hidden: true });
        const status = node('p', { role: 'status', tabindex: '-1', class: 'form-text' });
        const queue = button('queue', 'cloud-upload', async () => {
            if (!reviewed?.review_token) return;
            const token = reviewed.review_token;
            const turn = generation;
            const result = await request('/sis/imports/people/commit/', 'POST', { review_token: token });
            if (!body.isConnected || turn !== generation) return;
            csv = ''; reviewed = null; rows = []; file.value = ''; reason.value = '';
            notify(text('queued_help'), { tone: 'info' });
            await detail(result.id);
        }, true);
        queue.disabled = true;
        const invalidate = () => { generation++; reviewed = null; queue.disabled = true; plan.hidden = true; status.textContent = ''; };
        const read = button('inspect', 'table', async () => {
            if (!file.reportValidity() || !reason.reportValidity()) return;
            const selected = file.files[0];
            if (!selected || selected.size > 2097152) throw new Error(text('large'));
            invalidate();
            const turn = generation;
            const value = await selected.text();
            const result = await request('/sis/imports/people/preview/', 'POST', { csv: value, delimiter: delimiter.value, mapping: {}, reason: reason.value });
            if (!body.isConnected || turn !== generation) return;
            csv = value; headers = result.headers;
            mapping.replaceChildren(node('legend', {}, text('map')));
            for (const key of ['given_name', 'family_name', 'email']) {
                const select = node('select', { class: 'form-select', id: `people-import-${key}`, name: key, required: true },
                    node('option', { value: '' }, text('choose')), headers.map((label, i) => node('option', { value: String(i) }, label)));
                select.addEventListener('change', invalidate);
                mapping.append(field(key, select));
            }
            mapping.hidden = false;
            mapping.append(button('review', 'list-check', review, true));
            mapping.querySelector('select').focus();
        }, true);
        async function review() {
            const fields = {};
            for (const select of mapping.querySelectorAll('select')) {
                if (!select.reportValidity()) return;
                fields[select.name] = Number(select.value);
            }
            if (!reason.reportValidity()) return;
            invalidate();
            const turn = generation;
            const result = await request('/sis/imports/people/preview/', 'POST', { csv, mapping: fields, delimiter: delimiter.value, reason: reason.value });
            if (!body.isConnected || turn !== generation) return;
            reviewed = result; rows = result.rows; page = 0;
            queue.disabled = !result.ready || !result.review_token;
            status.textContent = result.ready ? text('server') : text('correct');
            render(); status.focus();
        }
        function render() {
            plan.hidden = false;
            const start = page * 25;
            const view = rows.slice(start, start + 25).map(row => [String(row.row), `${row.given_name} ${row.family_name}`, row.email,
                row.issues.length ? node('span', { class: 'text-danger' }, row.issues.join(' ')) : text('ready')]);
            const previous = button('previous', 'arrow-left', () => { page--; render(); });
            const next = button('next', 'arrow-right', () => { page++; render(); });
            previous.disabled = page === 0; next.disabled = start + 25 >= rows.length;
            plan.replaceChildren(node('p', { role: 'status' }, sentence('count', { from: start + 1, to: Math.min(start + 25, rows.length), total: rows.length })),
                wrap(table([text('record'), text('name'), text('email'), text('issues')], view)),
                node('nav', { class: 'd-flex flex-wrap gap-2 my-3', 'aria-label': text('pages') }, previous, next));
        }
        for (const control of [file, delimiter, reason]) control.addEventListener('input', () => {
            invalidate(); if (control !== reason) { csv = ''; mapping.hidden = true; }
        });
        body.append(node('p', {}, text('help')), setup, read, mapping, status, plan, queue);
    }
    return { activity, detail, people };
};

window.createImportUpdates = ({ node, request, labels, changed = () => {}, notificationsOnly = false }) => {
    const text = key => labels[key] || key;
    let host = null, disposed = false, serial = 0;
    const icon = name => node('i', { class: `bi bi-${name}`, 'aria-hidden': 'true' });
    const button = (key, glyph, action) => {
        const el = node('button', { type: 'button', class: 'btn btn-outline-secondary btn-sm' }, icon(glyph), text(key));
        el.addEventListener('click', action); return el;
    };
    let include = null, status = null, content = null;
    async function load(after = null, stack = []) {
        const turn = ++serial;
        status.textContent = text('loading');
        try {
            const query = new URLSearchParams({ scope: include.checked ? 'all' : 'unread' });
            if (after) query.set('after', after);
            const result = await request(`/sis/imports/notices/?${query}`);
            if (disposed || turn !== serial || !host?.isConnected) return;
            const list = node('ul', { class: 'list-unstyled' });
            for (const item of result.items) {
                const actions = node('div', { class: 'd-flex flex-wrap gap-2' });
                const mutate = async action => {
                    actions.querySelectorAll('button').forEach(el => { el.disabled = true; });
                    try { await request(`/sis/imports/notices/${action}/`, 'POST', { id: item.id }); await load(after, stack); changed(); }
                    catch { if (!disposed) status.textContent = text('failed'); }
                    finally { actions.querySelectorAll('button').forEach(el => { el.disabled = false; }); }
                };
                if (item.unread && !notificationsOnly) actions.append(button('mark', 'check2', () => mutate('read')));
                actions.append(button('delete', 'trash', () => mutate('remove')));
                list.append(node('li', { class: 'registration-update-card border rounded mb-3 text-start' },
                    node('h3', { class: 'h6' }, text(item.state)),
                    node('p', { class: 'text-break' }, `${item.target.replaceAll('_', ' ')} · ${item.processed} / ${item.total}`),
                    node('time', { class: 'form-text d-block mb-2', datetime: item.recorded_at }, new Date(item.recorded_at).toLocaleString()), actions));
            }
            const paging = node('nav', { class: 'd-flex flex-wrap gap-2', 'aria-label': text('notices_pages') });
            if (stack.length) paging.append(button('previous', 'arrow-left', () => { const prev = [...stack]; load(prev.pop(), prev); }));
            if (result.next_after) paging.append(button('next', 'arrow-right', () => load(result.next_after, [...stack, after])));
            content.replaceChildren(result.items.length ? list : node('p', {}, text('notice_empty')), paging);
            status.textContent = text('loaded');
        } catch { if (!disposed && turn === serial) status.textContent = text('failed'); }
    }
    return {
        mount(target) {
            host = target;
            include = node('input', { type: 'checkbox', class: 'form-check-input', id: 'import-updates-include' });
            include.checked = notificationsOnly;
            include.addEventListener('change', () => load());
            status = node('p', { role: 'status', class: 'form-text' }); content = node('div');
            host.replaceChildren(node('p', {}, text('notice_help')), node('div', { class: 'form-check mb-3' }, include,
                node('label', { class: 'form-check-label', for: include.id }, text('include'))), button('refresh', 'arrow-clockwise', () => load()), status, content);
            if (notificationsOnly) {
                include.parentElement.hidden = true;
                host.querySelector(':scope > button').hidden = true;
                host.querySelector(':scope > p').remove();
            }
            load();
        },
        destroy() { disposed = true; serial++; host?.replaceChildren(); host = null; },
    };
};
