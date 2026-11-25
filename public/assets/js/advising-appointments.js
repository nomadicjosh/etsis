/* Private working appointments. Drafts and original retry identities remain in page memory only. */
(() => {
    'use strict';
    let sequence = 0;
    window.createAdvisingAppointments = ({ node, request, labels: t }) => ({
        mount(container, endpoint, { owner = false, portal = false, selectedAppointment = null, onBack = null, onDenied = null } = {}) {
            const identity = `appointments-${++sequence}`;
            const controls = node('div', { class: 'd-flex flex-wrap gap-2 mb-3' });
            const status = node('p', { role: 'status', 'aria-live': 'polite', tabindex: '-1', id: `${identity}-status`, class: 'form-text text-break' });
            const editor = node('div'), content = node('div');
            const availability = node('p', { class: 'form-text', hidden: true }, t.unavailable);
            const drafts = new Map();
            let finished = false, after = null, next = null, stack = [], busy = false, generation = 0, creation = null, createId = null, hosts = [], zone = '', activeDraft = null;
            const button = (label, run, icon = 'arrow-right', primary = false) => node('button', { type: 'button',
                class: `btn ${portal ? primary ? 'campus-button' : 'campus-button-outline' : primary ? 'btn-primary' : 'btn-outline-primary'}`,
                onclick: () => work(run) }, node('i', { class: `bi bi-${icon} me-2`, 'aria-hidden': 'true' }), label);
            async function work(run) {
                if (busy || !container.isConnected) return;
                busy = true;
                container.querySelectorAll('button').forEach(b => { b.disabled = true; });
                status.textContent = '';
                try { await run(); }
                catch (error) {
                    if (!container.isConnected) return;
                    if ([401, 403].includes(error.status)) {
                        content.replaceChildren(); editor.replaceChildren(); controls.replaceChildren(); drafts.clear(); creation = null; createId = null; hosts = []; activeDraft = null;
                        status.textContent = t.denied;
                        onDenied?.();
                    } else {
                        const fields = Array.isArray(error.fields) ? error.fields : [];
                        status.textContent = fields.map(f => f.message).filter(v => typeof v === 'string').join(' ') || error.message || t.failure;
                        for (const field of fields) {
                            if (typeof field.field !== 'string') continue;
                            const input = container.querySelector(`[name="${CSS.escape(field.field)}"]`);
                            if (input) { input.setAttribute('aria-invalid', 'true'); input.setAttribute('aria-describedby', status.id); }
                        }
                    }
                    status.focus();
                } finally {
                    busy = false;
                    container.querySelectorAll('button').forEach(b => { b.disabled = false; });
                }
            }
            function field(form, key, label, type = 'textarea', value = '') {
                const id = `${identity}-${key}-${++sequence}`;
                const input = node(type === 'textarea' ? 'textarea' : 'input', { id, name: key, class: 'form-control', required: true,
                    ...(type === 'textarea' ? { rows: '3', maxlength: '1000' } : { type }) });
                input.value = value;
                input.addEventListener('input', () => { input.removeAttribute('aria-invalid'); input.removeAttribute('aria-describedby'); });
                form.append(node('div', { class: 'mb-3' }, node('label', { for: id, class: 'form-label' }, label), input));
                return input;
            }
            function formFor(parent, item = null, action = 'schedule') {
                const key = item?.id || 'create';
                activeDraft = key;
                container.querySelectorAll('form').forEach(form => form.remove());
                creation = null;
                const draft = drafts.get(key) || { message: '', start: item?.time.start || '', end: item?.time.end || '', host: hosts[0]?.advisor_person_id || '' };
                draft.action = action;
                if (item) { draft.operation ??= item.new_operation; draft.version ??= item.version; }
                drafts.set(key, draft);
                const form = node('form', { class: 'border rounded bg-white p-3 mb-3' });
                form.append(node('h4', { class: 'h6' }, t[action] || t.schedule));
                if (action.startsWith('correct_')) form.append(node('p', { class: 'form-text' }, t.correction_help));
                let host = null, start = null, end = null;
                if (action === 'schedule') {
                    host = node('select', { id: `${identity}-host`, name: 'host_person_id', class: 'form-select mb-3', required: true },
                        ...hosts.map(h => node('option', { value: h.advisor_person_id }, `${h.name} · ${h.starts_on}${h.ends_on ? ` – ${h.ends_on}` : ''}`)));
                    host.value = draft.host;
                    form.append(node('label', { for: host.id, class: 'form-label' }, t.host), host);
                }
                if (['schedule', 'reschedule'].includes(action)) {
                    form.append(node('p', { class: 'form-text' }, `${t.timezone}: ${zone}`), node('p', { class: 'form-text' }, t.limits));
                    const times = node('div', { class: 'row g-3' });
                    const left = node('div', { class: 'col-12 col-lg-6' }), right = node('div', { class: 'col-12 col-lg-6' });
                    start = field(left, 'start', t.start, 'datetime-local', draft.start);
                    end = field(right, 'end', t.end, 'datetime-local', draft.end);
                    times.append(left, right); form.append(times);
                }
                const message = field(form, 'message', t.message, 'textarea', draft.message);
                const remember = () => Object.assign(draft, { message: message.value, start: start?.value || '', end: end?.value || '', host: host?.value || draft.host });
                form.addEventListener('input', remember); form.addEventListener('change', remember);
                const save = node('button', { type: 'submit', class: `btn ${portal ? 'campus-button' : 'btn-primary'}` },
                    node('i', { class: 'bi bi-check2 me-2', 'aria-hidden': 'true' }), action === 'schedule' ? t.save : t.confirm);
                const keep = button(t.keep, () => { parent.replaceChildren(); activeDraft = null; if (!item) creation = null; else drafts.delete(key); }, 'arrow-left');
                form.append(node('div', { class: 'd-flex gap-2 flex-wrap' }, save, keep));
                form.addEventListener('submit', event => {
                    event.preventDefault();
                    work(async () => {
                        if (!form.reportValidity()) return;
                        remember();
                        const data = { appointment_id: item?.id || createId, message: draft.message };
                        if (start) Object.assign(data, { start: draft.start, end: draft.end, timezone: zone });
                        if (item) Object.assign(data, { operation_id: draft.operation, version: draft.version, action });
                        else data.host_person_id = draft.host;
                        form.querySelectorAll('input,textarea,select').forEach(i => { i.disabled = true; });
                        try { await request(endpoint + (item ? 'act/' : 'schedule/'), 'POST', data); }
                        finally { form.querySelectorAll('input,textarea,select').forEach(i => { i.disabled = false; }); }
                        if (!container.isConnected) return;
                        drafts.delete(key); activeDraft = null; if (!item) { editor.replaceChildren(); creation = null; createId = null; finished = false; after = null; stack = []; switcher.lastChild.textContent = t.finished; }
                        await load(); status.textContent = item ? t.changed : t.saved; status.focus();
                    });
                });
                parent.replaceChildren(form); message.focus(); return form;
            }
            function card(item) {
                const box = node('article', { class: 'border rounded bg-white p-3 mb-3 text-break' });
                const local = time => `${time.start.replace('T', ' ')} – ${time.end.slice(11)} · ${time.timezone}`;
                box.append(node('h4', { class: 'h6' }, item.host_name), node('p', { class: 'fw-semibold' }, local(item.time)),
                    node('p', {}, t.status[item.status]), node('p', { style: 'white-space:pre-wrap;overflow-wrap:anywhere' }, item.message));
                const changes = node('details', { class: 'mb-3' }, node('summary', { class: 'fw-semibold' }, t.history));
                for (const change of item.changes) changes.append(node('div', { class: 'border-top pt-2 mt-2' },
                    node('p', { class: 'form-text' }, `${t.actions[change.action]} · ${change.at}`),
                    ...(change.time ? [node('p', {}, local(change.time))] : []),
                    node('p', { style: 'white-space:pre-wrap;overflow-wrap:anywhere' }, change.message)));
                box.append(changes);
                if (item.can_act || item.can_correct) {
                    const actions = node('div', { class: 'd-flex flex-wrap gap-2 mb-3' }), host = node('div');
                    const open = action => formFor(host, item, action);
                    if (item.can_reschedule) actions.append(button(t.reschedule, () => open('reschedule'), 'calendar2-week'));
                    if (item.can_act) actions.append(button(t.cancel, () => open('cancel'), 'x-circle'));
                    if (item.can_act && !owner && !item.cancel_only) actions.append(button(t.held, () => open('held'), 'check2-circle'), button(t.no_show, () => open('no_show'), 'person-x'));
                    if (!owner && item.can_correct) {
                        const target = item.status === 'held' ? 'correct_no_show' : 'correct_held';
                        actions.append(button(t[target], () => open(target), 'pencil-square'));
                    }
                    if (item.cancel_only) box.append(node('p', { class: 'form-text' }, t.cancel_only));
                    if (!owner && item.version >= 10) box.append(node('p', { class: 'form-text' }, t.reschedule_limit));
                    box.append(actions, host);
                    const draft = drafts.get(item.id);
                    if (draft && activeDraft === item.id) { draft.operation = item.new_operation; draft.version = item.version; formFor(host, item, draft.action); }
                }
                return box;
            }
            const refresh = button(t.refresh, () => load(), 'arrow-clockwise');
            const switcher = button(t.finished, async () => { finished = !finished; after = null; stack = []; switcher.lastChild.textContent = finished ? t.active : t.finished; await load(); }, 'archive');
            const previous = button(t.previous, async () => { after = stack.pop(); await load(); }, 'arrow-left');
            const more = button(t.more, async () => { stack.push(after); after = next; await load(); }, 'arrow-right');
            const add = button(t.schedule, () => { creation ||= formFor(editor); }, 'calendar2-plus', true);
            previous.hidden = more.hidden = add.hidden = true;
            if (onBack) controls.append(button(t.back_calendar, onBack, 'arrow-left'));
            controls.append(refresh, ...(selectedAppointment ? [] : [switcher, previous, more]), ...(owner ? [] : [add]));
            if (!selectedAppointment) controls.append(button(t.calendar, async () => {
                controls.hidden = true; editor.replaceChildren(); creation = null; content.replaceChildren();
                availability.hidden = true;
                await window.createAdvisingAppointmentCalendar({ node, request, labels: t }).mount(content, endpoint + 'calendar/', {
                    owner, portal, detailEndpoint: () => endpoint,
                    onDenied: () => { drafts.clear(); activeDraft = null; },
                    onBack: () => work(async () => { controls.hidden = false; await load(); }),
                });
            }, 'calendar3'));
            container.append(node('h3', { class: 'h5' }, t.title), node('p', { class: 'form-text' }, t.intro), controls, availability, status, editor, content);
            async function load() {
                const current = ++generation;
                const data = await request(endpoint + 'list/', 'POST', { finished, after, ...(selectedAppointment ? { appointment_id: selectedAppointment } : {}) });
                if (!container.isConnected || current !== generation) return;
                hosts = data.hosts; zone = data.timezone; createId ||= data.new_id;
                add.hidden = owner || !data.can_schedule;
                availability.hidden = owner || Boolean(selectedAppointment) || data.can_schedule;
                if (!data.can_schedule) { editor.replaceChildren(); creation = null; drafts.delete('create'); if (activeDraft === 'create') activeDraft = null; }
                next = data.next_after; more.hidden = !next; previous.hidden = !stack.length;
                content.replaceChildren(...data.items.map(card));
                if (!data.items.length) content.append(node('p', {}, t.empty));
            }
            work(load);
        },
    });
})();
