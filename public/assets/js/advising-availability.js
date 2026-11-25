/* Working availability and private owner self-booking. Drafts and retries stay in page memory. */
(() => {
    'use strict';
    let sequence = 0;
    window.createAdvisingAvailability = ({ node, request, labels: t }) => {
        const setup = (container, portal) => {
            const root = node('section'), output = node('div');
            const status = node('p', { role: 'status', 'aria-live': 'polite', tabindex: '-1', id: `availability-status-${++sequence}`, class: 'form-text text-break' });
            let busy = false;
            const work = async (run, form = null) => {
                if (busy || !root.isConnected) return;
                busy = true; root.querySelectorAll('button').forEach(b => { b.disabled = true; }); status.textContent = '';
                try { await run(); }
                catch (error) {
                    if (!root.isConnected) return;
                    if ([401, 403].includes(error.status)) {
                        const returns = [...root.querySelectorAll('[data-availability-return]')];
                        root.dispatchEvent(new Event('availability-denied')); root.replaceChildren(...returns, status); status.textContent = t.denied;
                    }
                    else {
                        status.textContent = (error.fields || []).map(f => f.message).join(' ') || error.message || t.error;
                        for (const f of error.fields || []) {
                            const input = typeof f.field === 'string' ? (form || root).querySelector(`[name="${CSS.escape(f.field)}"]`) : null;
                            if (input) { input.setAttribute('aria-invalid', 'true'); input.setAttribute('aria-describedby', status.id); }
                        }
                    }
                    status.focus();
                } finally { busy = false; root.querySelectorAll('button').forEach(b => { b.disabled = false; }); }
            };
            const button = (label, run, icon = 'arrow-right', primary = false) => node('button', { type: 'button',
                class: `btn ${portal ? primary ? 'campus-button' : 'campus-button-outline' : primary ? 'btn-primary' : 'btn-outline-primary'}`,
                onclick: () => work(run) }, node('i', { class: `bi bi-${icon} me-2`, 'aria-hidden': 'true' }), label);
            const field = (form, key, label, type = 'textarea', value = '') => {
                const id = `availability-${++sequence}-${key}`;
                const input = node(type === 'textarea' ? 'textarea' : 'input', { id, name: key, class: 'form-control', required: true,
                    ...(type === 'textarea' ? { maxlength: '1000', rows: '3' } : { type }) }); input.value = value;
                input.addEventListener('input', () => { input.removeAttribute('aria-invalid'); input.removeAttribute('aria-describedby'); });
                form.append(node('div', { class: 'mb-3' }, node('label', { for: id, class: 'form-label' }, label), input));
                return input;
            };
            root.append(status, output); container.append(root);
            return { root, output, status, work, button, field };
        };
        return {
            manage(container, endpoint, { portal = false, back = null } = {}) {
                const { root, output, status, work, button, field } = setup(container, portal);
                const controls = node('div', { class: 'd-flex flex-wrap gap-2 mb-3' }), editor = node('div');
                let finished = false, after = null, stack = [], current = null, createId = null;
                let creation = null;
                const reasons = new Map();
                root.addEventListener('availability-denied', () => { reasons.clear(); creation = null; createId = null; editor.replaceChildren(); });
                const refresh = button(t.refresh, () => load(), 'arrow-clockwise');
                const switcher = button(t.finished, async () => { finished = !finished; after = null; stack = []; switcher.lastChild.textContent = finished ? t.upcoming : t.finished; await load(); }, 'archive');
                const previous = button(t.previous, async () => { after = stack.pop(); await load(); }, 'arrow-left');
                const more = button(t.more, async () => { stack.push(after); after = current.next_after; await load(); });
                const add = button(t.add, () => { if (!creation) create(); creation.hidden = false; creation.querySelector('input').focus(); }, 'calendar2-plus', true);
                const recurring = button(t.recurring, () => {
                    controls.classList.add('d-none'); editor.hidden = true;
                    return window.mountAdvisingRecurringAvailability({ root: output, owner: root, node, request, labels: t,
                        button, field, work, status, endpoint, timezone: current.timezone, submitClass: portal ? 'campus-button' : 'btn-primary',
                        back: async () => { controls.classList.remove('d-none'); editor.hidden = false; await load(); recurring.focus(); } });
                }, 'calendar2-week');
                const returnControl = back ? button(t.back_advisors, back, 'arrow-left') : null;
                if (returnControl) returnControl.dataset.availabilityReturn = '';
                controls.append(...(returnControl ? [returnControl] : []), refresh, switcher, previous, more, add, recurring);
                root.prepend(node('h3', { class: 'h5' }, t.title), node('p', { class: 'form-text' }, t.intro), controls, editor);
                function create() {
                    createId ||= current.new_id;
                    const timezone = current.timezone;
                    creation = node('form', { class: 'border rounded bg-white p-3 mb-3' });
                    creation.append(node('p', { class: 'form-text' }, `${t.timezone}: ${timezone}`), node('p', { class: 'form-text' }, t.limits));
                    const times = node('div', { class: 'row g-3' }), left = node('div', { class: 'col-12 col-lg-6' }), right = node('div', { class: 'col-12 col-lg-6' });
                    const start = field(left, 'start', t.start, 'datetime-local'), end = field(right, 'end', t.end, 'datetime-local');
                    times.append(left, right); creation.append(times);
                    const minutes = field(creation, 'slot_minutes', t.minutes, 'number', '30'); minutes.min = '5'; minutes.max = '120'; minutes.step = '1';
                    const message = field(creation, 'message', t.instructions);
                    const save = node('button', { type: 'submit', class: `btn ${portal ? 'campus-button' : 'btn-primary'}` }, t.save);
                    creation.append(save);
                    creation.addEventListener('submit', event => { event.preventDefault(); work(async () => {
                        if (!creation.reportValidity()) return;
                        const inputs = [start, end, minutes, message]; inputs.forEach(i => { i.disabled = true; });
                        try { await request(endpoint + 'create/', 'POST', { window_id: createId, start: start.value, end: end.value, timezone, slot_minutes: Number(minutes.value), message: message.value }); }
                        finally { inputs.forEach(i => { i.disabled = false; }); }
                        if (!root.isConnected) return;
                        editor.replaceChildren(); creation = null; createId = null; after = null; stack = []; finished = false; switcher.lastChild.textContent = t.finished;
                        await load(); status.textContent = t.saved; status.focus();
                    }, creation); }); editor.append(creation);
                }
                function card(item) {
                    const card = node('article', { class: 'border rounded bg-white p-3 mb-3 text-break' },
                        node('h4', { class: 'h6' }, `${item.time.start.replace('T', ' ')} – ${item.time.end.slice(11)}`),
                        node('p', { class: 'form-text' }, `${item.time.timezone} · ${t.minutes}: ${item.slot_minutes}`),
                        node('p', {}, item.status === 'open' && item.expired ? t.expired : t.status[item.status]), node('p', { style: 'white-space:pre-wrap;overflow-wrap:anywhere' }, item.message));
                    if (item.close_reason) card.append(node('p', { style: 'white-space:pre-wrap;overflow-wrap:anywhere' }, item.close_reason));
                    if (item.status === 'open') {
                        const form = node('form');
                        const reason = field(form, 'message', t.reason, 'textarea', reasons.get(item.id) || '');
                        reason.addEventListener('input', () => reasons.set(item.id, reason.value));
                        const save = node('button', { type: 'submit', class: `btn ${portal ? 'campus-button-outline' : 'btn-outline-primary'} mt-2` }, t.confirm_close);
                        const disclosure = node('details', { open: reasons.has(item.id) }, node('summary', { class: 'fw-semibold' }, t.close), node('p', { class: 'form-text' }, t.close_help), form);
                        form.append(save); form.addEventListener('submit', event => { event.preventDefault(); work(async () => {
                            if (!form.reportValidity()) return;
                            reason.disabled = true;
                            try { await request(endpoint + 'close/', 'POST', { window_id: item.id, version: item.version, message: reason.value }); }
                            finally { reason.disabled = false; }
                            if (!root.isConnected) return;
                            reasons.delete(item.id); await load(); status.textContent = t.closed; status.focus();
                        }, form); }); card.append(disclosure);
                    }
                    return card;
                }
                async function load() {
                    const data = await request(endpoint + 'list/', 'POST', { finished, after });
                    if (!root.isConnected) return;
                    current = data; add.hidden = !data.can_create; previous.hidden = !stack.length; more.hidden = !data.next_after;
                    if (!data.can_create) { editor.replaceChildren(); creation = null; createId = null; }
                    output.replaceChildren(node('h4', { class: 'h6' }, data.host_name), ...data.items.map(card));
                    if (!data.items.length) output.append(node('p', {}, t.empty));
                }
                return work(() => load());
            },
            book(container, endpoint, { back = null } = {}) {
                const { root, output, status, work, button } = setup(container, true);
                const controls = node('form', { class: 'row g-3 mb-3' });
                const host = node('select', { id: `booking-host-${++sequence}`, class: 'form-select', required: true });
                const date = node('input', { id: `booking-date-${sequence}`, class: 'form-control', type: 'date', required: true });
                controls.append(node('div', { class: 'col-12 col-sm-6' }, node('label', { for: host.id, class: 'form-label' }, t.choose_host), host),
                    node('div', { class: 'col-12 col-sm-6' }, node('label', { for: date.id, class: 'form-label' }, t.date), date),
                    node('div', { class: 'col-12' }, node('button', { type: 'submit', class: 'btn campus-button' }, t.find_windows)));
                const returnControl = back ? button(t.back, back, 'arrow-left') : null;
                if (returnControl) returnControl.dataset.availabilityReturn = '';
                root.prepend(node('h3', { class: 'h5' }, t.book), node('p', { class: 'form-text' }, t.booking_intro),
                    ...(returnControl ? [returnControl] : []), controls);
                let selected = null, intent = null, generation = 0;
                root.addEventListener('availability-denied', () => { selected = null; intent = null; generation++; });
                const clear = () => { generation++; selected = null; intent = null; output.replaceChildren(); };
                host.addEventListener('change', clear); date.addEventListener('input', clear);
                controls.addEventListener('submit', event => { event.preventDefault(); work(async () => {
                    if (!controls.reportValidity()) return;
                    const epoch = ++generation;
                    const requested = { host_person_id: host.value, date: date.value };
                    const data = await request(endpoint + 'available-times/', 'POST', requested);
                    if (!root.isConnected || epoch !== generation || requested.host_person_id !== host.value || requested.date !== date.value) return;
                    output.replaceChildren(); selected = null; intent = null;
                    for (const window of data.windows) output.append(node('article', { class: 'border rounded bg-white p-3 mb-3 text-break' },
                        node('h4', { class: 'h6' }, `${window.time.start.replace('T', ' ')} – ${window.time.end.slice(11)} · ${window.time.timezone}`),
                        node('p', { style: 'white-space:pre-wrap;overflow-wrap:anywhere' }, window.message),
                        button(t.choose_window, () => slots(window), 'calendar2-check')));
                    if (!data.windows.length) output.append(node('p', {}, t.no_windows));
                }); });
                async function slots(window) {
                    const epoch = ++generation;
                    const data = await request(endpoint + 'available-times/', 'POST', { window_id: window.id });
                    if (!root.isConnected || epoch !== generation) return;
                    selected = data.window; intent = data.new_id; output.replaceChildren();
                    output.append(node('p', { style: 'white-space:pre-wrap;overflow-wrap:anywhere' }, selected.message));
                    const grid = node('div', { class: 'row g-2 mt-2' });
                    for (const slot of data.slots) {
                        const choose = button(`${t.choose_slot} · ${slot.start.slice(11)} – ${slot.end.slice(11)}`, () => confirm(slot), 'clock');
                        choose.classList.add('w-100', 'text-wrap'); grid.append(node('div', { class: 'col-12 col-sm-6' }, choose));
                    }
                    output.append(grid);
                    if (!data.slots.length) output.append(node('p', {}, t.no_slots));
                }
                function confirm(slot) {
                    const epoch = ++generation;
                    output.replaceChildren(node('article', { class: 'border rounded bg-white p-3 mb-3 text-break' },
                        node('h4', { class: 'h6' }, `${slot.start.replace('T', ' ')} – ${slot.end.slice(11)} · ${slot.timezone}`),
                        node('p', { style: 'white-space:pre-wrap;overflow-wrap:anywhere' }, selected.message),
                        button(t.confirm_book, async () => {
                            await request(endpoint + 'book/', 'POST', { appointment_id: intent, window_id: selected.id, window_version: selected.version, start: slot.start });
                            if (!root.isConnected || epoch !== generation) return;
                            output.replaceChildren(node('p', { role: 'status' }, t.booked)); selected = null; intent = null; status.textContent = t.booked; status.focus();
                        }, 'check2-circle', true), button(t.keep, () => slots(selected), 'arrow-left')));
                }
                return work(async () => {
                    const data = await request(endpoint + 'available-times/', 'POST', {});
                    if (!root.isConnected) return;
                    host.replaceChildren(...data.hosts.map(h => node('option', { value: h.id }, h.name))); date.value ||= data.today; date.min = data.today;
                    if (!data.hosts.length) { controls.hidden = true; output.append(node('p', {}, t.no_hosts)); }
                });
            },
        };
    };
})();
