/* Shared working requests; actual profile edits keep their existing authorizations and history. */
(() => {
    'use strict';
    let sequence = 0;
    const statuses = { requested: 'Update requested', responded: 'Ready for staff review', closed: 'Closed', cancelled: 'Cancelled' };
    const areas = { contact: 'Contact information', addresses: 'Dated addresses', emergency_contacts: 'Emergency contacts', education: 'Educational background' };
    window.createPersonProfileRequests = ({ node, request, task, notify }) => ({
        mount(container, endpoint, { staff = false, openEditor = () => {}, area = null, showHeading = true } = {}) {
            const availableAreas = area ? { [area]: areas[area] } : areas;
            const identity = `profile-requests-${++sequence}`;
            const active = node('div');
            const controls = node('div', { class: 'd-flex gap-2 flex-wrap mb-3' });
            const status = node('p', { role: 'status', 'aria-live': 'polite', class: 'form-text' });
            const formArea = node('div');
            let finished = false, next = null, generation = 0, currentNewId = null, createForm = null, creating = false, createButton = null, accessGeneration = 0;
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
            container.append(...(showHeading ? [node('h3', {}, 'Profile update requests')] : []),
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
                        const scope = accessGeneration;
                        const data = { version: item.version, message: input.value, ...(staff ? { action: action.value } : {}) };
                        const path = `${endpoint}${encodeURIComponent(item.id)}/${staff ? 'resolve' : 'respond'}/`;
                        input.disabled = true;
                        if (action) action.disabled = true;
                        try {
                            await request(path, 'POST', data);
                        } catch (error) {
                            clearDenied(error); throw error;
                        } finally {
                            input.disabled = false;
                            if (action) action.disabled = false;
                        }
                        if (!container.isConnected || scope !== accessGeneration) return;
                        drafts.delete(item.id);
                        notify(staff ? 'Request outcome saved.' : 'Your update is ready for staff review.');
                        await load();
                    }, save);
                });
                box.append(form);
                return box;
            }
            function clearDenied(error) {
                if (![401, 403].includes(error.status) || !container.isConnected) return;
                generation++; accessGeneration++; creating = false;
                drafts.clear(); currentNewId = null; next = null;
                createForm = null; createButton?.remove(); createButton = null;
                formArea.replaceChildren(); active.replaceChildren(); more.hidden = true;
                status.textContent = 'Private request details cleared. Refresh after access is restored.';
            }
            function createEditor() {
                if (!staff || createForm) return;
                const add = button('Request a profile update', () => {
                    createForm.hidden = false;
                    createForm.querySelector('select').focus();
                });
                controls.append(add); createButton = add;
                createForm = node('form', { class: 'mb-4', hidden: true });
                const area = node('select', { id: `${identity}-area`, name: 'area', class: 'form-select' },
                    ...Object.entries(availableAreas).map(([key, label]) => node('option', { value: key }, label)));
                createForm.append(node('label', { for: area.id, class: 'form-label' }, 'Information to update'), area);
                const message = messageField(createForm, 'Instructions visible to the owner', 'instructions');
                const save = node('button', { type: 'submit', class: 'btn btn-primary mt-2' }, 'Send profile update request');
                createForm.append(node('p', { class: 'form-text' }, 'Explain the next step. Keep confidential internal commentary out of this owner-visible message.'), save);
                createForm.addEventListener('submit', event => {
                    event.preventDefault();
                    task(async () => {
                        if (creating || !createForm.reportValidity() || !currentNewId) return;
                        creating = true;
                        const scope = accessGeneration;
                        const data = { request_id: currentNewId, area: area.value, message: message.value };
                        area.disabled = message.disabled = true;
                        try { await request(endpoint, 'POST', data); }
                        catch (error) { clearDenied(error); throw error; }
                        finally { if (scope === accessGeneration) creating = false; area.disabled = message.disabled = false; }
                        if (!container.isConnected || scope !== accessGeneration) return;
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
                if (area) query.set('area', area);
                if (append && next) query.set('after', next);
                let result;
                try { result = await request(`${endpoint}?${query}`); }
                catch (error) {
                    if (token === generation) clearDenied(error);
                    throw error;
                }
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
            const beforeLeave = () => {
                const input = [...container.querySelectorAll('textarea')].find(field => field.value.trim());
                if (creating || input || [...drafts.values()].some(draft => draft.message?.trim())) {
                    status.textContent = creating ? 'Wait for the current request before leaving.'
                        : 'Your request draft is still here. Save or clear it before leaving.';
                    (input || refresh).focus();
                    return false;
                }
                return true;
            };
            task(load, refresh);
            return {
                beforeLeave,
                async compose(area = 'contact') {
                    if (!staff || !availableAreas[area] || !container.isConnected) return false;
                    if (!createForm) await load();
                    if (!createForm || !container.isConnected) return false;
                    const field = createForm.querySelector('select');
                    const message = createForm.querySelector('textarea');
                    if (creating) {
                        status.textContent = 'Wait for the current request before starting another.';
                        return false;
                    }
                    if (field.value !== area && message.value.trim()) {
                        createForm.hidden = false;
                        status.textContent = 'Your current request instructions are still here. Save or clear them before choosing another area.';
                        message.focus();
                        return false;
                    }
                    field.value = area;
                    createForm.hidden = false;
                    message.focus();
                    return true;
                },
            };
        },
    });
})();
