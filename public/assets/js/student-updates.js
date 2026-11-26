/* Private, account-pinned outcome notices. Loading and deletion are explicit; nothing is persisted in this browser. */
'use strict';
window.createStudentUpdates = ({ node, request, labels = {}, notify, kind = 'registration', notificationsOnly = false, changed = () => {} }) => {
    if (!['registration', 'advising', 'academic', 'transcript'].includes(kind)) throw new Error('Unsupported student update source.');
    const advising = kind === 'advising', academic = kind === 'academic', transcript = kind === 'transcript';
    const endpoint = `/portal/api/${kind}-updates/`;
    const advisingDefaults = {
        advising_updates: 'Advising updates', advising_loading: 'Loading advising updates…',
        advising_help: 'New and resolved advising follow-up appears here. Open a check-in to review it or delete the notice when it is no longer needed.',
        advising_empty: 'There are no matching advising updates.', advising_include: 'Show read updates',
        advising_refresh: 'Refresh advising updates', advising_pages: 'Advising update pages',
        advising_previous: 'Previous updates', advising_more: 'More updates',
        advising_open: 'New advising follow-up', advising_closed: 'Advising follow-up closed',
        advising_cancelled: 'Advising follow-up cancelled', advising_mark: 'Mark as read', advising_delete: 'Delete update',
        advising_loaded: 'Advising updates loaded. Program labels are current.',
        advising_failed: 'Advising updates could not load. Use Refresh to try again.',
        advising_denied: 'Advising updates are unavailable for this account.',
        advising_changed: 'This update changed or is unavailable. Refresh the list.',
        advising_new: 'New advising updates are available.', advising_link: 'View check-in',
        advising_marked: 'Advising update marked as read.', advising_deleted: 'Advising update deleted.',
        advising_back: 'Back to advising updates',
    };
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
    const text = key => {
        const name = kind === 'registration' ? key : key.replace(/^registration_/, `${kind}_`);
        return labels[name] || advisingDefaults[name] || defaults[name];
    };
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
        } else status.textContent = notificationsOnly ? labels.notifications_retry
            : text([404, 409].includes(error.status) ? 'registration_changed' : 'registration_failed');
        status.focus();
    }
    async function load(after = null, stack = [], announce = false) {
        const turn = ++serial; controls(true);
        status.setAttribute('role', 'status'); status.textContent = text('registration_loading');
        try {
            const query = new URLSearchParams({ include_read: include.checked ? '1' : '0' });
            if (after) { query.set('after_at', after.at); query.set('after', after.id); }
            const result = await request(`${endpoint}?${query}`);
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
                if (!(advising ? ['open', 'closed', 'cancelled'] : academic ? ['posted', 'corrected'] : transcript ? ['ready', 'fulfilled'] : ['registered', 'removed']).includes(item.status)) throw new Error('Invalid registration outcome.');
                const card = node('li', { class: 'registration-update-card border rounded mb-3 text-start' },
                    node('h3', { class: 'h6' }, text(`registration_${item.status}`)),
                    node('p', { class: 'fw-semibold text-break mb-1' }, transcript ? text(`transcript_${item.delivery}`) : advising ? item.program_code : `${item.course_code} · ${item.section_code} · ${item.term_code}`),
                    node('p', { class: 'text-break' }, transcript ? `${text('transcript_requested_on')} · ${String(item.requested_at).slice(0, 10)}` : advising ? item.program_title : item.course_title),
                    node('time', { class: 'form-text d-block mb-2', datetime: item.recorded_at },
                        new Date(item.recorded_at).toLocaleString()),
                    node('span', { class: 'badge text-bg-light border mb-2' }, text(item.unread ? 'announcement_unread' : 'announcement_read')));
                card.dataset.updateId = item.id;
                const actions = node('div', { class: 'd-flex flex-wrap gap-2' });
                if (item.unread && !notificationsOnly) actions.append(button('registration_mark', 'check2', () => mutate(item, 'read')));
                actions.append(button('registration_delete', 'trash', () => mutate(item, 'delete')),
                    transcript ? button('registration_link', 'arrow-right', () => openTranscriptRequest(item))
                        : academic ? button('registration_link', 'arrow-right', () => openAcademicRecord(item))
                        : advising ? button('registration_link', 'arrow-right', () => openCheckIn(item))
                        : node('a', { href: '/portal/student/registration/', class: 'btn btn-outline-secondary btn-sm' },
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
            await request(`${endpoint}${encodeURIComponent(item.id)}/${action}/`, 'POST', { revision: item.revision });
            if (!active(turn)) return;
            const loaded = await load(cursor, previous); changed();
            if (loaded && !disposed && root?.isConnected) {
                status.textContent = text(action === 'delete' ? 'registration_deleted' : 'registration_marked');
                status.focus();
            }
        } catch (error) { failed(turn, error); }
        finally { if (active(turn)) controls(false); }
    }
    async function openAcademicRecord(item) {
        const turn = ++serial; controls(true);
        try {
            if (typeof item.id !== 'string' || !/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/.test(item.id)) throw new Error('Invalid academic notification.');
            const result = await request(`${endpoint}${encodeURIComponent(item.id)}/`);
            if (!active(turn)) return;
            await request(`${endpoint}${encodeURIComponent(item.id)}/read/`, 'POST', { revision: item.revision });
            if (!active(turn)) return;
            const back = button('registration_back', 'arrow-left', async () => { await load(cursor, previous); (content.querySelector('button') || status).focus(); });
            const title = node('h3', { class: 'h6 text-break', tabindex: '-1' }, `${result.course_code} · ${result.course_title}`);
            const facts = node('dl', { class: 'record-summary record-summary-paired' });
            for (const [key, value] of [['academic_grade', result.grade || text('academic_ungraded')],
                ['academic_term', result.term_code], ['academic_credits', String(result.completed_milli / 1000)]]) {
                facts.append(node('div', {}, node('dt', {}, text(key)), node('dd', {}, value)));
            }
            content.replaceChildren(node('div', { class: 'd-flex flex-wrap gap-2 my-3' }, back,
                button('registration_delete', 'trash', () => mutate(item, 'delete'))), title,
                node('p', { class: 'form-text' }, text('academic_current_help')), facts);
            status.textContent = ''; title.focus(); changed();
        } catch (error) { failed(turn, error); }
        finally { if (active(turn)) controls(false); }
    }
    async function openTranscriptRequest(item) {
        const turn = ++serial; controls(true);
        try {
            if (typeof item.id !== 'string' || !/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/.test(item.id)) throw new Error('Invalid transcript notification.');
            const result = await request(`${endpoint}${encodeURIComponent(item.id)}/`);
            if (!active(turn)) return;
            if (!['ready', 'fulfilled'].includes(result.status) || !['pickup', 'electronic'].includes(result.delivery)) throw new Error('Invalid transcript outcome.');
            await request(`${endpoint}${encodeURIComponent(item.id)}/read/`, 'POST', { revision: item.revision });
            if (!active(turn)) return;
            const back = button('registration_back', 'arrow-left', async () => {
                const loaded = await load(cursor, previous);
                if (loaded) (content.querySelector('button') || status).focus();
            });
            const title = node('h3', { class: 'h6 text-break', tabindex: '-1' }, text('transcript_current'));
            const facts = node('dl', { class: 'record-summary record-summary-paired' });
            for (const [key, value] of [['transcript_requested_on', String(result.requested_at).slice(0, 10)],
                ['transcript_delivery', text(`transcript_${result.delivery}`)],
                ['transcript_status', text(`transcript_${result.status}`)]]) {
                facts.append(node('div', {}, node('dt', {}, text(key)), node('dd', {}, value)));
            }
            content.replaceChildren(node('div', { class: 'd-flex flex-wrap gap-2 my-3' }, back,
                button('registration_delete', 'trash', () => mutate(item, 'delete'))), title, facts,
                node('p', { class: 'form-text' }, text('transcript_privacy')),
                node('a', { href: '/portal/student/transcripts/', class: 'btn btn-outline-secondary btn-sm' },
                    icon('arrow-right'), text('transcript_requests')));
            status.textContent = ''; title.focus(); changed();
        } catch (error) { failed(turn, error); }
        finally { if (active(turn)) controls(false); }
    }
    async function openCheckIn(item) {
        const turn = ++serial; controls(true);
        try {
            if (![item.student_id, item.pursuit_id, item.checkin_id].every(value =>
                typeof value === 'string' && /^[0-7][0-9A-HJKMNP-TV-Z]{25}$/.test(value))) throw new Error('Invalid check-in reference.');
            await request(`${endpoint}${encodeURIComponent(item.id)}/read/`, 'POST', { revision: item.revision });
            if (!active(turn)) return;
            const back = button('registration_back', 'arrow-left', async () => {
                const loaded = await load(cursor, previous);
                if (loaded) {
                    const original = [...content.querySelectorAll('[data-update-id]')].find(card => card.dataset.updateId === item.id);
                    (original?.querySelector('button') || status).focus();
                }
            });
            const selected = node('section');
            content.replaceChildren(node('div', { class: 'd-flex flex-wrap gap-2 my-3' }, back), selected);
            status.textContent = `${item.program_code} · ${item.program_title}`;
            const title = node('h3', { class: 'h6 text-break', tabindex: '-1' }, text('registration_link'));
            selected.append(title); title.focus();
            window.createAdvisingCheckIns({ node, request }).mount(selected,
                `/portal/api/students/${item.student_id}/programs/${item.pursuit_id}/checkins/`,
                { owner: true, selectedCheckIn: item.checkin_id, onDenied: error => failed(turn, error) });
        } catch (error) { failed(turn, error); }
        finally { if (active(turn)) controls(false); }
    }
    function mount(container) {
        root = container;
        status = node('p', { role: 'status', tabindex: '-1', class: 'form-text' });
        content = node('div');
        include = node('input', { id: `${kind}-updates-include-read`, type: 'checkbox', class: 'form-check-input' });
        include.checked = notificationsOnly;
        include.addEventListener('change', () => { if (!busy) void load(); });
        refresh = button('registration_refresh', 'arrow-clockwise', () => load(null, [], true));
        root.replaceChildren(node('p', { class: 'form-text' }, text('registration_help')),
            node('div', { class: 'form-check my-3' }, include,
                node('label', { for: include.id, class: 'form-check-label' }, text('registration_include'))),
            refresh, status, content);
        if (notificationsOnly) {
            refresh.hidden = true; include.parentElement.hidden = true;
            root.querySelector(':scope > p.form-text')?.remove();
        }
        void load();
    }
    function destroy() { disposed = true; ++serial; seen.clear(); root?.replaceChildren(); root = null; }
    return { mount, destroy };
};
