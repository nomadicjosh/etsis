/* Private, account-pinned outcome notices. Loading and deletion are explicit; nothing is persisted in this browser. */
'use strict';
window.createRegistrationUpdates = ({ node, request, labels = {}, notify }) => {
    const defaults = {
        registration_updates: 'Registration updates', registration_loading: 'Loading registration updates…',
        registration_help: 'Waitlist registrations and staff removals appear here. Delete an update when you no longer need it.',
        registration_empty: 'There are no matching registration updates.', registration_include: 'Show read updates',
        registration_refresh: 'Refresh registration updates', registration_pages: 'Registration update pages',
        registration_previous: 'Previous updates', registration_more: 'More updates',
        registration_registered: 'Registered from waitlist', registration_removed: 'Waitlist request removed',
        registration_mark: 'Mark as read', registration_delete: 'Delete update',
        registration_loaded: 'Registration updates loaded. Course, section and term labels are current.',
        registration_failed: 'Registration updates could not load. Use Refresh to try again.',
        registration_denied: 'Registration updates are unavailable for this account.',
        registration_changed: 'This update changed or is unavailable. Refresh the list.',
        registration_new: 'New registration updates are available.',
        registration_link: 'View your registration', registration_marked: 'Registration update marked as read.',
        registration_deleted: 'Registration update deleted.',
        announcement_unread: 'Unread', announcement_read: 'Read',
    };
    const text = key => labels[key] || defaults[key];
    const icon = name => node('i', { class: `bi bi-${name}`, 'aria-hidden': 'true' });
    let root, status, content, include, refresh, disposed = false, serial = 0, busy = false;
    let cursor = null, previous = [], baseline = false, newest = '';
    const seen = new Set();
    const active = turn => !disposed && root?.isConnected && turn === serial;
    const button = (key, glyph, run) => node('button', {
        type: 'button', class: `btn btn-sm ${glyph === 'trash' ? 'btn-outline-danger' : 'btn-outline-primary'}`,
        onclick: event => { if (!busy) void run(event.currentTarget); },
    }, icon(glyph), text(key));
    function controls(disabled) {
        busy = disabled;
        root?.querySelectorAll('button, input').forEach(control => { control.disabled = disabled; });
    }
    function failed(turn, error) {
        if (!active(turn)) return;
        status.setAttribute('role', 'alert');
        if ([401, 403].includes(error.status)) {
            content.replaceChildren(); seen.clear();
            status.textContent = text('registration_denied');
        } else status.textContent = text([404, 409].includes(error.status) ? 'registration_changed' : 'registration_failed');
        status.focus();
    }
    async function load(after = null, stack = [], announce = false) {
        const turn = ++serial; controls(true);
        status.setAttribute('role', 'status'); status.textContent = text('registration_loading');
        try {
            const query = new URLSearchParams({ include_read: include.checked ? '1' : '0' });
            if (after) { query.set('after_at', after.at); query.set('after', after.id); }
            const result = await request(`/portal/api/registration-updates/?${query}`);
            if (!active(turn)) return;
            if (!Array.isArray(result.items) || result.items.length > 20) throw new Error('Invalid registration update page.');
            if (announce && baseline && result.items.some(item => item.unread && item.id > newest && !seen.has(item.id))) {
                notify(text('registration_new'), { tone: 'info' });
            }
            result.items.forEach(item => { seen.add(item.id); if (item.id > newest) newest = item.id; });
            while (seen.size > 100) seen.delete(seen.values().next().value);
            baseline = true; cursor = after; previous = stack;
            const list = node('ul', { class: 'list-unstyled registration-updates-list' });
            for (const item of result.items) {
                if (!['registered', 'removed'].includes(item.status)) throw new Error('Invalid registration outcome.');
                const card = node('li', { class: 'registration-update-card border rounded mb-3 text-start' },
                    node('h3', { class: 'h6' }, text(`registration_${item.status}`)),
                    node('p', { class: 'fw-semibold text-break mb-1' }, `${item.course_code} · ${item.section_code} · ${item.term_code}`),
                    node('p', { class: 'text-break' }, item.course_title),
                    node('time', { class: 'form-text d-block mb-2', datetime: item.recorded_at },
                        new Date(item.recorded_at).toLocaleString()),
                    node('span', { class: 'badge text-bg-light border mb-2' }, text(item.unread ? 'announcement_unread' : 'announcement_read')));
                const actions = node('div', { class: 'd-flex flex-wrap gap-2' });
                if (item.unread) actions.append(button('registration_mark', 'check2', () => mutate(item, 'read')));
                actions.append(button('registration_delete', 'trash', () => mutate(item, 'delete')),
                    node('a', { href: '/portal/student/registration/', class: 'btn btn-outline-secondary btn-sm' },
                        icon('arrow-right'), text('registration_link')));
                card.append(actions); list.append(card);
            }
            const paging = node('nav', { class: 'd-flex flex-wrap gap-2', 'aria-label': text('registration_pages') });
            if (stack.length) paging.append(button('registration_previous', 'arrow-left', () => {
                const earlier = [...stack]; return load(earlier.pop(), earlier);
            }));
            if (result.next) paging.append(button('registration_more', 'arrow-right', () => load(result.next, [...stack, after])));
            content.replaceChildren(result.items.length ? list : node('p', {}, text('registration_empty')), paging);
            status.textContent = text('registration_loaded');
            return true;
        } catch (error) { failed(turn, error); }
        finally { if (active(turn)) controls(false); }
    }
    async function mutate(item, action) {
        const turn = ++serial; controls(true);
        try {
            await request(`/portal/api/registration-updates/${encodeURIComponent(item.id)}/${action}/`, 'POST', { revision: item.revision });
            if (!active(turn)) return;
            const loaded = await load(cursor, previous);
            if (loaded && !disposed && root?.isConnected) {
                status.textContent = text(action === 'delete' ? 'registration_deleted' : 'registration_marked');
                status.focus();
            }
        } catch (error) { failed(turn, error); }
        finally { if (active(turn)) controls(false); }
    }
    function mount(container) {
        root = container;
        status = node('p', { role: 'status', tabindex: '-1', class: 'form-text' });
        content = node('div');
        include = node('input', { id: 'registration-updates-include-read', type: 'checkbox', class: 'form-check-input' });
        include.addEventListener('change', () => { if (!busy) void load(); });
        refresh = button('registration_refresh', 'arrow-clockwise', () => load(null, [], true));
        root.replaceChildren(node('p', { class: 'form-text' }, text('registration_help')),
            node('div', { class: 'form-check my-3' }, include,
                node('label', { for: include.id, class: 'form-check-label' }, text('registration_include'))),
            refresh, status, content);
        void load();
    }
    function destroy() { disposed = true; ++serial; seen.clear(); root?.replaceChildren(); root = null; }
    return { mount, destroy };
};
