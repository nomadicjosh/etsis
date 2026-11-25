/* Working follow-up stays in memory. Server scopes every read and action independently. */
(() => {
    'use strict';
    let sequence = 0;
    const statuses = { open: 'Awaiting student response', responded: 'Ready for advisor review', closed: 'Closed', cancelled: 'Cancelled' };
    window.createAdvisingCheckIns = ({ node, request }) => ({
        mount(container, endpoint, { owner = false, portal = false, selectedCheckIn = null, onDenied = null } = {}) {
            const identity = `checkins-${++sequence}`;
            const controls = node('div', { class: 'd-flex gap-2 flex-wrap mb-3' });
            const status = node('p', { role: 'status', 'aria-live': 'polite', class: 'form-text text-break', tabindex: '-1' });
            status.id = `${identity}-status`;
            const content = node('div'), editor = node('div');
            let finished = false, after = null, next = null, stack = [], generation = 0, busy = false, createForm = null, createId = null, createButton = null;
            const drafts = new Map();
            const buttons = new Set();
            const button = (label, run) => {
                const control = node('button', { type: 'button', class: portal ? 'btn campus-button-outline' : 'btn btn-outline-primary', onclick: () => work(run) }, label);
                buttons.add(control);
                return control;
            };
            async function work(run) {
                if (busy || !container.isConnected) return;
                busy = true;
                for (const control of buttons) control.disabled = true;
                status.textContent = '';
                try { await run(); }
                catch (error) {
                    if (!container.isConnected) return;
                    if ([401, 403].includes(error.status)) {
                        content.replaceChildren(); editor.replaceChildren(); drafts.clear();
                        createButton?.remove(); createButton = null; createForm = null; createId = null;
                        onDenied?.(error);
                    }
                    const fields = Array.isArray(error.fields) ? error.fields : [];
                    const messages = fields.map(field => field.message).filter(message => typeof message === 'string');
                    status.textContent = messages.length ? messages.join(' ') : error.message || 'Unable to save. Your draft remains here. Refresh and review the current check-in before trying again.';
                    for (const field of fields) {
                        if (typeof field.field !== 'string') continue;
                        const input = container.querySelector(`[name="${CSS.escape(field.field)}"]`);
                        if (input) { input.setAttribute('aria-invalid', 'true'); input.setAttribute('aria-describedby', status.id); }
                    }
                    status.focus();
                }
                finally {
                    busy = false;
                    for (const control of buttons) if (control.isConnected) control.disabled = false;
                }
            }
            const refresh = button('Refresh check-ins', () => load());
            const switcher = button('Finished check-ins', async () => {
                finished = !finished; selectedCheckIn = null; after = null; stack = [];
                switcher.textContent = finished ? 'Active check-ins' : 'Finished check-ins';
                await load();
            });
            const older = button('Older check-ins', async () => { stack.push(after); after = next; await load(); });
            const previous = button('Previous check-ins', async () => { after = stack.pop(); await load(); });
            older.hidden = previous.hidden = true;
            controls.append(refresh, switcher, previous, older);
            container.append(node('h3', {}, 'Advising check-ins'), node('p', { class: 'form-text' },
                'Completed check-ins and follow-up for this program. Next steps and outcomes are visible to the student. This does not change academic records or schedule an appointment.'), controls, status, editor, content);
            function field(form, label, key, { value = '', type = null, required = true } = {}) {
                const input = node(type ? 'input' : 'textarea', { id: `${identity}-${key}`, name: key, class: 'form-control', required,
                    ...(type ? { type } : { rows: '3', maxlength: '1000' }) });
                input.value = value;
                input.addEventListener('input', () => { input.removeAttribute('aria-invalid'); input.removeAttribute('aria-describedby'); });
                form.append(node('label', { for: input.id, class: 'form-label mt-2' }, label), input);
                return input;
            }
            function card(item) {
                const box = node('article', { class: 'border rounded p-3 mb-3 text-break' });
                box.append(node('h4', { class: 'h6' }, `Check-in · ${item.held_on}`), node('p', {}, statuses[item.status]),
                    node('p', { class: 'form-text' }, `Recorded ${item.created_at}${item.due_on ? ` · Follow-up due ${item.due_on}` : ''}`));
                const text = (label, value) => { if (value) box.append(node('h5', { class: 'h6' }, label), node('p', { style: 'white-space:pre-wrap;overflow-wrap:anywhere' }, value)); };
                text('Next step', item.next_step);
                if (!owner) text('Private advising note', item.private_note);
                text('Student response', item.response);
                if (item.responded_at) box.append(node('p', { class: 'form-text' }, `Responded ${item.responded_at}`));
                text('Outcome', item.resolution);
                if (item.resolved_at) box.append(node('p', { class: 'form-text' }, `Resolved ${item.resolved_at}`));
                if (finished || !['open', 'responded'].includes(item.status) || (owner && item.status !== 'open')) return box;
                const form = node('form');
                const draft = drafts.get(item.id) || { message: '', action: 'close' };
                let action = null;
                if (!owner) {
                    action = node('select', { id: `${identity}-${item.id}-action`, class: 'form-select' },
                        ...(item.owner_changed ? [] : [node('option', { value: 'close' }, 'Close follow-up')]),
                        node('option', { value: 'cancel' }, 'Cancel with a reason'));
                    action.value = item.owner_changed ? 'cancel' : draft.action;
                    form.append(node('label', { for: action.id, class: 'form-label' }, 'Outcome type'), action);
                    if (item.owner_changed) form.append(node('p', { class: 'form-text' }, 'The student account link changed. Cancel this earlier check-in before recording fresh follow-up.'));
                }
                const input = field(form, owner ? 'Your response' : 'Outcome visible to the student', `${item.id}-message`, { value: draft.message });
                input.name = 'message';
                const remember = () => drafts.set(item.id, { message: input.value, action: action?.value || 'respond' });
                input.addEventListener('input', remember);
                action?.addEventListener('change', remember);
                const save = node('button', { type: 'submit', class: portal ? 'btn campus-button mt-3' : 'btn btn-primary mt-3' }, owner ? 'Send response' : 'Save follow-up outcome');
                buttons.add(save);
                form.append(save);
                form.addEventListener('submit', event => {
                    event.preventDefault();
                    work(async () => {
                        if (!form.reportValidity()) return;
                        input.disabled = true;
                        if (action) action.disabled = true;
                        try { await request(endpoint + 'act/', 'POST', { checkin_id: item.id, action: owner ? 'respond' : action.value, version: item.version, message: input.value }); }
                        finally { input.disabled = false; if (action) action.disabled = false; }
                        drafts.delete(item.id);
                        if (!container.isConnected) return;
                        await load();
                        status.textContent = owner ? 'Your response was saved.' : 'Follow-up outcome saved.';
                    });
                });
                box.append(form);
                return box;
            }
            function create(data) {
                if (owner || createForm || !data.can_record) return;
                createId = data.new_id;
                createForm = node('form', { class: 'border rounded p-3 mb-4', hidden: true });
                const add = button('Record a check-in', () => { createForm.hidden = false; held.focus(); });
                createButton = add;
                controls.append(add);
                const held = field(createForm, 'Held on', 'held_on', { type: 'date', value: data.today });
                held.max = data.today;
                const due = field(createForm, 'Follow-up due (optional)', 'due_on', { type: 'date', required: false });
                due.min = held.value;
                held.addEventListener('input', () => { due.min = held.value; });
                const step = field(createForm, 'Next step visible to the student', 'next_step');
                const note = field(createForm, 'Private advising note (optional)', 'private_note', { required: false });
                createForm.append(node('p', { class: 'form-text' }, 'Private notes are available to authorized advising staff only. Keep notes relevant to advising; follow institutional privacy and retention rules. Original wording is retained. Cancel with a reason to correct a check-in. Up to 20 active check-ins per program.'));
                const save = node('button', { type: 'submit', class: portal ? 'btn campus-button mt-3' : 'btn btn-primary mt-3' }, 'Save check-in');
                buttons.add(save);
                createForm.append(save);
                createForm.addEventListener('submit', event => {
                    event.preventDefault();
                    work(async () => {
                        if (!createForm.reportValidity() || !createId) return;
                        const inputs = [held, due, step, note];
                        inputs.forEach(input => { input.disabled = true; });
                        try { await request(endpoint + 'record/', 'POST', { checkin_id: createId, held_on: held.value, due_on: due.value || null, next_step: step.value, private_note: note.value }); }
                        finally { inputs.forEach(input => { input.disabled = false; }); }
                        if (!container.isConnected) return;
                        createForm.reset(); createForm.remove(); createForm = null; add.remove(); buttons.delete(add);
                        createId = null; finished = false; after = null; stack = [];
                        switcher.textContent = 'Finished check-ins';
                        await load();
                        status.textContent = 'Check-in saved. The student can review the next step and respond.';
                    });
                });
                editor.append(createForm);
            }
            async function load() {
                const current = ++generation;
                const data = await request(endpoint + 'list/', 'POST', { finished, after, ...(selectedCheckIn ? {checkin_id: selectedCheckIn} : {}) });
                if (!container.isConnected || current !== generation) return;
                for (const control of buttons) if (!control.isConnected) buttons.delete(control);
                create(data);
                if (createForm) {
                    createButton.hidden = !data.can_record;
                    if (!data.can_record) createForm.hidden = true;
                    createForm.querySelectorAll('input,textarea,button').forEach(input => { input.disabled = !data.can_record; });
                }
                next = data.next_after;
                older.hidden = !next; previous.hidden = !stack.length;
                content.replaceChildren(...data.items.map(card));
                if (!data.items.length) content.append(node('p', { role: 'status' }, finished ? 'No finished check-ins on this page.' : 'No active check-ins on this page.'));
            }
            work(load);
        },
    });
})();
