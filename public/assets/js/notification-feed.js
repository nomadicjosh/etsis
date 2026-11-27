/* One bounded, private account feed. Source services retain ownership, revision and deletion authority. */
'use strict';
window.createNotificationFeed = ({ node, request, labels, importLabels = {}, notify,
    feedback = () => [], deleteFeedback, changed = () => {}, includePrivate = true }) => {
    const text = key => labels[key] || key;
    const icon = name => node('i', { class: `bi bi-${name}`, 'aria-hidden': 'true' });
    const definitions = ['admissions', 'academic', 'transcript', 'standing', 'registration', 'advising', 'enrollment']
        .map(kind => ({ kind, endpoint: `/portal/api/${kind}-updates/` }));
    definitions.push({ kind: 'announcement', endpoint: '/portal/api/announcements/' });
    definitions.push({ kind: 'window', endpoint: '/portal/api/registration-open/' });
    if (importLabels.notices) definitions.push({ kind: 'import', endpoint: '/sis/imports/notices/' });
    let root, status, toolbar, content, paging, reader = null, disposed = false, serial = 0, busy = false;
    let sources = [], pages = [], pageIndex = 0, selected = null, unavailable = false;
    const key = row => `${row.kind}:${row.item.id}:${row.item.revision ?? 0}`;
    const date = row => row.item.updated_at || row.item.recorded_at || '';
    const time = row => Number.isFinite(Date.parse(date(row))) ? Date.parse(date(row)) : null;
    const orderedDate = row => {
        const match = /^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?Z$/.exec(date(row));
        if (match) return `${match[1]}.${(match[2] || '').padEnd(6, '0')}Z`;
        return time(row) === null ? '' : new Date(time(row)).toISOString().replace(/Z$/, '000Z');
    };
    const compare = (a, b) => orderedDate(b).localeCompare(orderedDate(a))
        || (typeof a.item.id === 'number' && typeof b.item.id === 'number'
            ? b.item.id - a.item.id : String(b.item.id).localeCompare(String(a.item.id)))
        || a.kind.localeCompare(b.kind);
    const active = turn => !disposed && root?.isConnected && turn === serial;
    const button = (label, glyph, action, danger = false) => node('button', {
        type: 'button', class: `btn btn-sm ${danger ? 'btn-outline-danger' : 'btn-outline-secondary'}`,
        onclick: async event => {
            if (busy) return;
            event.currentTarget.disabled = true;
            try { await action(); }
            finally { if (event.currentTarget?.isConnected) event.currentTarget.disabled = false; }
        },
    }, icon(glyph), label);
    function controls(value) {
        busy = value;
        root?.querySelectorAll('button').forEach(control => { control.disabled = value; });
    }
    function fail(error) {
        if (disposed || !root?.isConnected) return;
        if ([401, 403].includes(error.status)) {
            ++serial;
            reader?.destroy(); reader = null; pages = []; sources = []; selected = null;
            content.replaceChildren(); paging.replaceChildren();
            toolbar.hidden = false;
            controls(false);
        }
        status.setAttribute('role', 'alert');
        status.textContent = text([401, 403].includes(error.status) ? 'feed_denied' : 'feed_retry');
        status.focus();
    }
    function sourceDenied(endpoint) {
        const kind = definitions.find(source => source.endpoint === endpoint)?.kind;
        if (kind && (selected?.kind === kind || pages.flat().some(row => row.kind === kind)
            || sources.some(source => source.kind === kind && source.rows.length))) fail({ status: 403 });
    }
    async function fill(source, turn) {
        if (source.done || source.rows.length || !active(turn)) return;
        const query = new URLSearchParams(source.kind === 'import' ? { scope: 'all' } : { include_read: '1' });
        const cursorKey = JSON.stringify(source.cursor);
        if (source.cursor) {
            if (typeof source.cursor === 'string') query.set('after', source.cursor);
            else { query.set('after', source.cursor.id); query.set('after_at', source.cursor.at); }
        }
        try {
            const result = await request(`${source.endpoint}?${query}`);
            if (!active(turn)) return;
            if (!Array.isArray(result.items) || result.items.length > 20) throw new Error('Invalid notice page.');
            source.rows = result.items.map(item => ({ kind: source.kind, item })).sort(compare);
            source.cursor = result.next || result.next_after || null;
            if (source.cursor && (JSON.stringify(source.cursor) === cursorKey || !source.rows.length))
                throw new Error('Invalid notice continuation.');
            source.done = !source.cursor;
        } catch (error) {
            if (!active(turn)) return;
            if (error.status === 401) throw error;
            if (error.status === 403) sourceDenied(source.endpoint);
            source.rows = []; source.done = true;
            // A source unavailable to this account contributes no category or private data.
            if (![403, 404].includes(error.status)) unavailable = true;
        }
    }
    function heading(row) {
        if (row.kind === 'feedback') return row.item.message;
        if (row.kind === 'announcement') return row.item.title;
        if (row.kind === 'import') return importLabels[row.item.state] || text('feed_import');
        return text(`${row.kind}_${row.kind === 'admissions' ? 'updated' : row.item.status}`);
    }
    function typeLabel(kind) {
        return kind === 'import' ? importLabels.notices : text(`feed_${kind}`);
    }
    function render() {
        if (disposed || selected) return;
        toolbar.hidden = false;
        const rows = pages[pageIndex] || [];
        const list = node('ol', { class: 'list-unstyled notification-feed-list mb-0', 'aria-label': text('feed_list') });
        for (const row of rows) {
            const { item, kind } = row;
            const card = node('li', { class: `registration-update-card notification-feed-card border rounded text-start mb-3${kind === 'feedback' ? ' workspace-notification-entry' : ''}` },
                node('div', { class: 'd-flex flex-wrap align-items-center gap-2 mb-2' },
                    node('span', { class: 'badge text-bg-light border' }, typeLabel(kind)),
                    node('span', { class: `badge ${item.unread ? 'text-bg-primary' : 'text-bg-light border'}` },
                        text(item.unread ? 'announcement_unread' : 'announcement_read'))),
                node('h3', { class: 'h6 text-break' }, heading(row)));
            card.dataset.notificationKey = key(row);
            card.dataset.updateId = item.id;
            const context = kind === 'admissions' || kind === 'advising' || kind === 'standing'
                ? [item.program_code, item.program_title, item.term_code]
                : kind === 'import' ? [String(item.target || '').replaceAll('_', ' '), `${item.processed} / ${item.total}`]
                : [item.course_code, item.section_code, item.term_code, item.course_title];
            if (context.some(Boolean)) card.append(node('p', { class: 'text-break mb-2' }, context.filter(Boolean).join(' · ')));
            if (kind === 'window') {
                card.append(node('p', { class: 'text-break mb-2' }, text('window_help')));
                if (Number.isFinite(Date.parse(item.closes_at))) card.append(node('p', { class: 'form-text mb-2' },
                    text('window_closes').replace('{date}', new Date(item.closes_at).toLocaleString())));
            }
            if (time(row) !== null) card.append(node('time', { class: 'form-text d-block mb-2',
                datetime: new Date(time(row)).toISOString() }, new Date(time(row)).toLocaleString()));
            const actions = node('div', { class: 'd-flex flex-wrap gap-2' });
            if (!['feedback', 'import'].includes(kind)) {
                const label = kind === 'announcement' ? text('feed_open_announcement') : text(`${kind}_link`);
                if (['registration', 'enrollment', 'admissions', 'window'].includes(kind)) {
                    actions.append(node('a', { class: 'btn btn-sm btn-outline-secondary',
                        href: kind === 'admissions' ? `/portal/admissions-updates/?update=${encodeURIComponent(item.id)}`
                            : kind === 'window' ? `/portal/student/registration/?term=${encodeURIComponent(item.id)}`
                                : '/portal/student/registration/', onclick: event => {
                            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button > 0) return;
                            event.preventDefault(); if (!busy) void open(row);
                        } }, icon('arrow-right'), label));
                } else actions.append(button(label, 'arrow-right', () => open(row)));
            }
            actions.append(button(text('delete_notification'), 'trash', () => remove(row), true));
            card.append(actions); list.append(card);
        }
        content.replaceChildren(rows.length ? list : node('p', { class: 'text-secondary' }, text('feed_empty')));
        paging.replaceChildren();
        if (pageIndex) paging.append(button(text('feed_previous'), 'arrow-left', () => { pageIndex--; render(); status.focus(); }));
        if (pageIndex + 1 < pages.length || sources.some(source => source.rows.length || !source.done))
            paging.append(button(text('feed_more'), 'arrow-right', next));
        status.setAttribute('role', unavailable ? 'alert' : 'status');
        status.textContent = text(unavailable ? 'feed_partial' : 'feed_loaded');
    }
    async function next() {
        if (pageIndex + 1 < pages.length) { pageIndex++; render(); status.focus(); return; }
        const turn = ++serial; controls(true); status.textContent = text('feed_loading');
        try {
            const page = [], seen = new Set(pages.flat().map(key));
            // At most twenty heads per source per turn; never drain an unbounded source.
            const calls = new Map();
            for (let step = 0; step < 40 && page.length < 20; step++) {
                for (const source of sources) {
                    if (!source.rows.length && !source.done) {
                        const count = calls.get(source.kind) || 0;
                        if (count >= 2) { unavailable = true; source.done = true; continue; }
                        calls.set(source.kind, count + 1); await fill(source, turn);
                    }
                }
                if (!active(turn)) return;
                const source = sources.filter(source => source.rows.length)
                    .sort((a, b) => compare(a.rows[0], b.rows[0]))[0];
                if (!source) break;
                const row = source.rows.shift(), identity = key(row);
                if (!seen.has(identity)) { page.push(row); seen.add(identity); }
            }
            pages.push(page); if (pages.length > 5) pages.shift();
            pageIndex = pages.length - 1; render();
        } catch (error) { if (active(turn)) fail(error); }
        finally { if (active(turn)) controls(false); }
    }
    async function refresh() {
        reader?.destroy(); reader = null; selected = null; ++serial;
        content.replaceChildren(); paging.replaceChildren();
        sources = includePrivate ? definitions.map(source => ({ ...source, rows: [], cursor: null, done: false })) : [];
        sources.push({ kind: 'feedback', rows: feedback().map(item => ({ kind: 'feedback', item: {
            ...item, recorded_at: item.at.toISOString(), unread: false,
        } })).sort(compare), done: true });
        pages = []; pageIndex = 0; unavailable = false;
        await next();
    }
    async function remove(row) {
        const turn = ++serial; controls(true);
        try {
            if (row.kind === 'feedback') deleteFeedback(row.item.id);
            else if (row.kind === 'import') await request('/sis/imports/notices/remove/', 'POST', { id: row.item.id });
            else await request(`${definitions.find(source => source.kind === row.kind).endpoint}${encodeURIComponent(row.item.id)}/delete/`,
                'POST', { revision: row.item.revision });
            if (!active(turn)) return;
            pages = pages.map(page => page.filter(item => key(item) !== key(row)));
            sources.forEach(source => { source.rows = source.rows.filter(item => key(item) !== key(row)); });
            render(); changed(); status.textContent = text('feed_deleted'); status.focus();
        } catch (error) { if (active(turn)) fail(error); }
        finally { if (active(turn)) controls(false); }
    }
    async function open(row) {
        if (['registration', 'enrollment', 'admissions', 'window'].includes(row.kind)) {
            const turn = ++serial; controls(true);
            try {
                if (row.item.unread) await request(`${definitions.find(source => source.kind === row.kind).endpoint}${encodeURIComponent(row.item.id)}/read/`,
                    'POST', { revision: row.item.revision });
                if (!active(turn)) return;
                changed();
                window.location.assign(row.kind === 'admissions'
                    ? `/portal/admissions-updates/?update=${encodeURIComponent(row.item.id)}`
                    : row.kind === 'window' ? `/portal/student/registration/?term=${encodeURIComponent(row.item.id)}`
                        : '/portal/student/registration/');
            } catch (error) { if (active(turn)) fail(error); }
            finally { if (active(turn)) controls(false); }
            return;
        }
        ++serial; selected = row; toolbar.hidden = true; paging.replaceChildren(); content.replaceChildren();
        const backToFeed = async (deleted = false) => {
            if (deleted) {
                pages = pages.map(page => page.filter(item => key(item) !== key(row)));
                sources.forEach(source => { source.rows = source.rows.filter(item => key(item) !== key(row)); });
            }
            reader?.destroy(); reader = null; selected = null; render();
            const card = [...content.querySelectorAll('[data-notification-key]')]
                .find(card => card.dataset.notificationKey === key(row));
            (card?.querySelector('button') || status).focus();
        };
        const detailLabels = { ...labels, announcement_back: text('feed_back') };
        detailLabels[`${row.kind}_back`] = text('feed_back');
        detailLabels[`${row.kind}_delete`] = text('delete_notification');
        detailLabels.notifications_retry = text('feed_retry');
        const privateRequest = async (...args) => {
            try { return await request(...args); }
            catch (error) {
                if ([401, 403].includes(error.status)) fail(error);
                else if ([404, 409].includes(error.status)) { await backToFeed(); fail(error); }
                throw error;
            }
        };
        const options = { node, request: privateRequest, labels: detailLabels, notify, notificationsOnly: true,
            initialItem: row.item, backToFeed, changed: () => { row.item.unread = false; changed(); } };
        reader = row.kind === 'announcement' ? window.createCampusAnnouncements(options)
            : window.createStudentUpdates({ ...options, kind: row.kind });
        const detail = node('div');
        content.replaceChildren(node('div', { class: 'd-flex flex-wrap gap-2 mb-3' },
            button(text('feed_back'), 'arrow-left', () => backToFeed())), detail);
        reader.mount(detail);
    }
    return {
        mount(container) {
            root = container; status = node('p', { role: 'status', tabindex: '-1', class: 'form-text' });
            toolbar = node('div', { class: 'd-flex flex-wrap gap-2 mb-3' },
                button(text('feed_refresh'), 'arrow-clockwise', refresh));
            content = node('div'); paging = node('nav', { class: 'd-flex flex-wrap gap-2', 'aria-label': text('feed_pages') });
            root.replaceChildren(toolbar, status, content, paging); void refresh();
        },
        refresh,
        sourceDenied,
        updateFeedback() { if (root?.isConnected && !selected && !busy) void refresh(); },
        destroy() { disposed = true; ++serial; reader?.destroy(); sources = []; pages = []; root?.replaceChildren(); root = null; },
    };
};
