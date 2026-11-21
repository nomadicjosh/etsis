/* Shared working requests; actual profile edits keep their existing authorizations and history. */
(() => {
    'use strict';
    let sequence = 0;
    const statuses = { requested: 'Update requested', responded: 'Ready for staff review', closed: 'Closed', cancelled: 'Cancelled' };
    const areas = { contact: 'Contact information', addresses: 'Dated addresses', emergency_contacts: 'Emergency contacts' };
    window.createPersonProfileRequests = ({ node, request, task, notify }) => ({
        mount(container, endpoint, { staff = false, openEditor = () => {} } = {}) {
            const identity = `profile-requests-${++sequence}`;
            const active = node('div');
            const controls = node('div', { class: 'd-flex gap-2 flex-wrap mb-3' });
            const status = node('p', { role: 'status', 'aria-live': 'polite', class: 'form-text' });
            const formArea = node('div');
            let finished = false, next = null, generation = 0, currentNewId = null, createForm = null;
            const drafts = new Map();
            const button = (label, handler) => node('button', { type: 'button', class: 'btn btn-outline-primary',
                onclick: event => task(handler, event.currentTarget) }, label);
            const switcher = button('Finished requests', async () => {
                finished = !finished;
                switcher.textContent = finished ? 'Active requests' : 'Finished requests';
                await load();
            });
            const refresh = button('Refresh requests', () => load());
            const more = button('Older requests', () => load(true));
            more.hidden = true;
            controls.append(refresh, switcher);
            container.append(node('h3', {}, 'Profile update requests'),
                node('p', { class: 'form-text' }, 'Use the existing editors to update details, then respond here. These requests do not change admissions, registration or account access.'),
                controls, formArea, active, more, status);
            function messageField(form, label, suffix) {
                const id = `${identity}-${suffix}`;
                const input = node('textarea', { id, name: 'message', class: 'form-control', rows: 3,
                    maxlength: 1000, required: true });
                form.append(node('label', { for: id, class: 'form-label' }, label), input);
                return input;
            }
            function card(item) {
                const box = node('article', { class: 'border rounded p-3 mb-3', 'aria-label': `${areas[item.area] || 'Profile'} request` });
                box.append(node('h4', {}, areas[item.area] || 'Profile update'),
                    node('p', {}, `${statuses[item.status] || 'Unavailable'} · Requested ${item.created_at || ''}`),
                    node('p', { class: 'text-break', style: 'white-space: pre-wrap' }, item.instructions));
                if (item.response) box.append(node('p', { class: 'text-break' }, `Owner response: ${item.response}`));
                if (item.resolution) box.append(node('p', { class: 'text-break' }, `Staff resolution: ${item.resolution}`));
                const pending = ['requested', 'responded'].includes(item.status);
                if (pending && areas[item.area]) box.append(button(`Open ${areas[item.area].toLowerCase()}`, () => openEditor(item.area)));
                if (item.owner_changed) box.append(node('p', {}, 'The account link changed. Cancel this request before creating another.'));
                if (!pending || (item.owner_changed && !staff)) {
                    if (drafts.has(item.id)) box.append(node('p', { class: 'text-break' }, `Unsent draft: ${drafts.get(item.id).message}`));
                    return box;
                }
                if (!staff && item.status !== 'requested') {
                    if (drafts.has(item.id)) box.append(node('p', { class: 'text-break' }, `Unsent draft: ${drafts.get(item.id).message}`));
                    return box;
                }
                const form = node('form', { class: 'mt-3' });
                let action = null;
                if (staff) {
                    action = node('select', { class: 'form-select', id: `${identity}-${item.id}-action` },
                        ...(!item.owner_changed ? [node('option', { value: 'close' }, 'Close after reviewing details')] : []),
                        node('option', { value: 'cancel' }, 'Cancel this request'));
                    form.append(node('label', { for: action.id, class: 'form-label' }, 'Request outcome'), action);
                }
                const input = messageField(form, staff ? 'Resolution note visible to the owner' : 'Update for staff', item.id);
                const savedDraft = drafts.get(item.id);
                input.value = savedDraft?.message || '';
                if (action && savedDraft?.action && [...action.options].some(option => option.value === savedDraft.action)) action.value = savedDraft.action;
                const remember = () => drafts.set(item.id, { message: input.value, action: action?.value });
                input.addEventListener('input', remember);
                action?.addEventListener('change', remember);
                const save = node('button', { type: 'submit', class: 'btn btn-primary mt-2' },
                    staff ? 'Save request outcome' : 'Send update for review');
                form.append(node('p', { class: 'form-text' }, staff
                    ? 'Review the current details before closing. Closure records follow-up, not approval of a credential or academic decision.'
                    : 'Save any changed details in the editor first. You can also explain why the current information is already correct.'), save);
                form.addEventListener('submit', event => {
                    event.preventDefault();
                    task(async () => {
                        if (!form.reportValidity()) return;
                        const data = { version: item.version, message: input.value, ...(staff ? { action: action.value } : {}) };
                        const path = `${endpoint}${encodeURIComponent(item.id)}/${staff ? 'resolve' : 'respond'}/`;
                        input.disabled = true;
                        if (action) action.disabled = true;
                        try {
                            await request(path, 'POST', data);
                        } finally {
                            input.disabled = false;
                            if (action) action.disabled = false;
                        }
                        drafts.delete(item.id);
                        if (!container.isConnected) return;
                        notify(staff ? 'Request outcome saved.' : 'Your update is ready for staff review.');
                        await load();
                    }, save);
                });
                box.append(form);
                return box;
            }
            function createEditor() {
                if (!staff || createForm) return;
                const add = button('Request a profile update', () => {
                    createForm.hidden = false;
                    createForm.querySelector('select').focus();
                });
                controls.append(add);
                createForm = node('form', { class: 'mb-4', hidden: true });
                const area = node('select', { id: `${identity}-area`, name: 'area', class: 'form-select' },
                    ...Object.entries(areas).map(([key, label]) => node('option', { value: key }, label)));
                createForm.append(node('label', { for: area.id, class: 'form-label' }, 'Information to update'), area);
                const message = messageField(createForm, 'Instructions visible to the owner', 'instructions');
                const save = node('button', { type: 'submit', class: 'btn btn-primary mt-2' }, 'Send profile update request');
                createForm.append(node('p', { class: 'form-text' }, 'Explain the next step. Keep confidential internal commentary out of this owner-visible message.'), save);
                createForm.addEventListener('submit', event => {
                    event.preventDefault();
                    task(async () => {
                        if (!createForm.reportValidity() || !currentNewId) return;
                        const data = { request_id: currentNewId, area: area.value, message: message.value };
                        area.disabled = message.disabled = true;
                        try { await request(endpoint, 'POST', data); }
                        finally { area.disabled = message.disabled = false; }
                        if (!container.isConnected) return;
                        message.value = '';
                        createForm.hidden = true;
                        currentNewId = null;
                        notify('Profile update requested.');
                        finished = false;
                        switcher.textContent = 'Finished requests';
                        await load();
                    }, save);
                });
                formArea.append(createForm);
            }
            async function load(append = false) {
                const token = ++generation;
                const query = new URLSearchParams({ finished: finished ? '1' : '0' });
                if (append && next) query.set('after', next);
                const result = await request(`${endpoint}?${query}`);
                if (!container.isConnected || token !== generation) return;
                if (!currentNewId) currentNewId = result.new_id;
                if (!append) active.replaceChildren();
                const rows = result.items || [];
                active.append(...rows.map(card));
                next = result.next_after;
                more.hidden = !next;
                if (!append && !rows.length) active.append(node('p', {}, finished ? 'No finished requests.' : 'No active profile update requests.'));
                status.textContent = append ? 'Older requests loaded.' : 'Requests refreshed.';
                createEditor();
            }
            task(load, refresh);
        },
    });
})();
