/* Owner-only editorial announcements. Content and reading state never enter browser storage. */
window.createCampusAnnouncements = function (ui) {
    'use strict';
    const { node, request, labels = {}, notify } = ui;
    const text = (key, fallback) => labels[key] || fallback;
    const icon = name => node('i', { class: `bi bi-${name}`, 'aria-hidden': 'true' });
    let root, content, status, include, refresh, serial = 0, disposed = false;
    let cursor = null, previous = [], baseline = false, newest = '';
    const seen = new Map();
    const active = turn => !disposed && root?.isConnected && turn === serial;
    const button = (key, fallback, glyph, run) => node('button', {
        type: 'button', class: 'btn btn-outline-primary btn-sm', onclick: event => run(event.currentTarget),
    }, icon(glyph), text(key, fallback));
    function failed(turn, error) {
        if (!active(turn)) return;
        if ([401, 403].includes(error.status)) { destroy(); return; }
        status.setAttribute('role', 'alert');
        status.textContent = error.status === 409 || error.status === 404
            ? text('announcement_changed', 'This announcement changed or is unavailable. Refresh the list.')
            : text('announcement_failed', 'Announcements could not load. Use Refresh announcements to try again.');
        status.focus();
    }
    async function load(after = null, stack = [], announce = false) {
        const turn = ++serial;
        refresh.disabled = true; include.disabled = true;
        status.setAttribute('role', 'status');
        status.textContent = text('announcement_loading', 'Loading campus announcements…');
        try {
            const result = await request(`/portal/api/announcements/?${new URLSearchParams({
                include_read: include.checked ? '1' : '0', after: after || '',
            })}`);
            if (!active(turn)) return;
            if (!Array.isArray(result.items) || result.items.length > 20) throw new Error('Invalid announcement page.');
            const newer = result.items.some(item => item.unread && (item.id > newest
                || (seen.has(item.id) && seen.get(item.id) !== item.revision)));
            if (announce && baseline && newer) notify(text('announcement_new', 'New campus announcements are available.'), { tone: 'info' });
            result.items.forEach(item => { seen.set(item.id, item.revision); if (item.id > newest) newest = item.id; });
            while (seen.size > 100) seen.delete(seen.keys().next().value);
            baseline = true; cursor = after; previous = stack;
            const list = node('ul', { class: 'list-unstyled campus-announcement-list' },
                ...result.items.map(item => node('li', {}, node('span', { class: 'badge text-bg-light border' },
                    item.unread ? text('announcement_unread', 'Unread') : text('announcement_read', 'Read')),
                    node('button', { type: 'button', class: 'btn btn-link text-start text-break',
                        onclick: () => detail(item.id) }, item.title))));
            const paging = node('nav', { class: 'd-flex flex-wrap gap-2',
                'aria-label': text('announcement_pages', 'Announcement pages') });
            if (stack.length) paging.append(button('announcement_previous', 'Previous announcements', 'arrow-left', () => {
                const earlier = [...stack]; return load(earlier.pop(), earlier);
            }));
            if (result.next_after) paging.append(button('announcement_more', 'More announcements', 'arrow-right',
                () => load(result.next_after, [...stack, after])));
            content.replaceChildren(result.items.length ? list : node('p', {},
                text('announcement_empty', 'There are no matching campus announcements.')), paging);
            status.textContent = text('announcement_loaded', 'Campus announcements loaded. Reading state is saved to your account.');
        } catch (error) { failed(turn, error); }
        finally { if (active(turn)) { refresh.disabled = false; include.disabled = false; } }
    }
    async function detail(id) {
        const turn = ++serial;
        refresh.disabled = true; include.disabled = true;
        status.setAttribute('role', 'status');
        status.textContent = text('announcement_loading', 'Loading campus announcements…');
        try {
            const result = await request(`/portal/api/announcements/${encodeURIComponent(id)}/`);
            if (!active(turn)) return;
            if (result.id !== id || typeof result.content !== 'string') throw new Error('Invalid announcement.');
            const heading = node('h3', { class: 'h5 text-break', tabindex: '-1' }, result.title);
            const message = node('div', { class: 'campus-announcement-content' },
                window.CampusRichText.article(result, node));
            const readStatus = node('p', { role: 'status', class: 'form-text' }, result.unread
                ? text('announcement_unread', 'Unread') : text('announcement_read', 'Read'));
            const mark = button('announcement_mark', 'Mark as read', 'check2', async control => {
                control.disabled = true;
                try {
                    await request(`/portal/api/announcements/${encodeURIComponent(id)}/read/`, 'POST', { revision: result.revision });
                    if (!active(turn)) return;
                    seen.set(id, result.revision);
                    readStatus.textContent = text('announcement_marked', 'Marked as read. This is not a policy acknowledgement.');
                    control.hidden = true;
                    back.focus();
                } catch (error) { failed(turn, error); }
                finally { if (active(turn)) control.disabled = false; }
            });
            mark.hidden = !result.unread;
            const back = button('announcement_back', 'Back to list', 'arrow-left', async () => {
                await load(cursor, previous);
                if (!disposed && root.isConnected) status.focus();
            });
            content.replaceChildren(heading, message, readStatus,
                node('div', { class: 'd-flex flex-wrap gap-2' }, mark, back));
            status.textContent = '';
            heading.focus();
        } catch (error) { failed(turn, error); }
        finally { if (active(turn)) { refresh.disabled = false; include.disabled = false; } }
    }
    function mount(container) {
        root = container;
        status = node('p', { role: 'status', tabindex: '-1', class: 'form-text' });
        include = node('input', { id: 'campus-announcements-include-read', type: 'checkbox', class: 'form-check-input' });
        include.addEventListener('change', () => { void load(); });
        refresh = button('announcement_refresh', 'Refresh announcements', 'arrow-clockwise', () => load(null, [], true));
        content = node('div');
        root.replaceChildren(node('p', { class: 'form-text' }, text('announcement_help',
            'Published campus news is available to signed-in applicants, students and staff. Open an announcement before marking its current version as read.')),
        node('div', { class: 'form-check my-3' }, include,
            node('label', { for: include.id, class: 'form-check-label' },
                text('announcement_include', 'Show read items'))), refresh, status, content);
        void load();
    }
    function destroy() {
        disposed = true; ++serial; seen.clear();
        root?.replaceChildren(); root = null;
    }
    return { mount, destroy };
};
