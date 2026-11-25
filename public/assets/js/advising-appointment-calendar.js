/* Bounded private appointment periods, independent of class terms. No personal browser storage. */
(() => {
    'use strict';
    let sequence = 0;
    window.createAdvisingAppointmentCalendar = ({ node, request, labels: t }) => ({
        mount(container, endpoint, { owner = false, portal = false, detailEndpoint = null, initial = null, initialError = null, onBack = null, onDenied = null } = {}) {
            const identity = `appointment-calendar-${++sequence}`;
            const root = node('section', { class: 'mb-4' });
            const controls = node('form', { class: 'row g-3 mb-3' });
            const output = node('div');
            const status = node('p', { id: `${identity}-status`, role: 'status', 'aria-live': 'polite', tabindex: '-1', class: 'form-text text-break' });
            let busy = false, generation = 0, current = initial, restore = null;
            const button = (label, run, icon = 'arrow-right') => node('button', { type: 'button',
                class: `btn ${portal ? 'campus-button-outline' : 'btn-outline-primary'}`,
                onclick: () => work(run) }, node('i', { class: `bi bi-${icon} me-2`, 'aria-hidden': 'true' }), label);
            async function work(run) {
                if (busy || !root.isConnected) return;
                busy = true;
                root.querySelectorAll('button,input,select').forEach(b => { b.disabled = true; });
                status.textContent = '';
                try { await run(); }
                catch (error) {
                    if (!root.isConnected) return;
                    output.replaceChildren();
                    if ([401, 403].includes(error.status)) { current = null; onDenied?.(); }
                    status.textContent = [401, 403].includes(error.status) ? t.denied : error.message || t.calendar_failure;
                    status.focus();
                } finally {
                    busy = false;
                    root.querySelectorAll('button,input,select').forEach(b => { b.disabled = false; });
                }
            }
            function control(label, key, element) {
                element.id = `${identity}-${key}`;
                controls.append(node('div', { class: 'col-12 col-sm-6 col-xl-3' }, node('label', { for: element.id, class: 'form-label' }, label), element));
            }
            const date = node('input', { name: 'date', type: 'date', class: 'form-control', required: true, min: '1000-01-06', max: '9998-12-27' });
            const view = node('select', { name: 'view', class: 'form-select' }, node('option', { value: 'week' }, t.week), node('option', { value: 'month' }, t.month));
            control(t.date, 'date', date); control(t.view, 'view', view);
            const cancelled = node('input', { id: `${identity}-cancelled`, name: 'include_cancelled', type: 'checkbox', class: 'form-check-input' });
            const refresh = node('button', { type: 'submit', class: `btn ${portal ? 'campus-button' : 'btn-primary'}` },
                node('i', { class: 'bi bi-arrow-clockwise me-2', 'aria-hidden': 'true' }), t.refresh_calendar);
            const previous = button(t.previous_period, () => load(current.previous), 'arrow-left');
            const next = button(t.next_period, () => load(current.next));
            controls.append(node('div', { class: 'col-12 d-flex flex-wrap gap-2 align-items-center' }, refresh,
                node('div', { class: 'form-check' }, cancelled, node('label', { for: cancelled.id, class: 'form-check-label' }, t.show_cancelled))));
            const navigation = node('div', { class: 'd-flex gap-2 flex-wrap mb-3' }, ...(onBack ? [button(t.back_list, onBack, 'arrow-left')] : []), previous, next);
            previous.hidden = next.hidden = true;
            root.append(node('h3', { class: 'h5' }, t.calendar), node('p', { class: 'form-text' }, t.calendar_help), controls, navigation, status, output);
            container.append(root);
            const invalidate = () => {
                generation++; output.replaceChildren(); current = null; previous.hidden = next.hidden = true;
            };
            date.addEventListener('input', invalidate); view.addEventListener('change', invalidate); cancelled.addEventListener('change', invalidate);
            controls.addEventListener('submit', event => { event.preventDefault(); work(() => load()); });
            async function load(target = null) {
                const serial = ++generation;
                if (target) date.value = target;
                if (date.value && !controls.reportValidity()) return;
                const query = { ...(date.value ? { date: date.value } : {}), view: view.value, include_cancelled: cancelled.checked };
                const data = await request(endpoint, 'POST', query);
                if (!root.isConnected || serial !== generation) return;
                current = data; draw();
            }
            function draw() {
                date.value = current.from; view.value = current.view; cancelled.checked = current.include_cancelled;
                previous.hidden = next.hidden = false;
                const items = new Map(current.items.map(item => [item.id, item]));
                const days = [];
                for (const day of current.days) {
                    if (!day.items.length) continue;
                    const section = node('section', { class: 'border rounded bg-white p-3 mb-3 text-break' }, node('h4', { class: 'h6' }, day.date));
                    for (const reference of day.items) {
                        const item = items.get(reference.id);
                        const clock = value => new Date(value).toLocaleTimeString(undefined, { timeZone: current.timezone, hour: '2-digit', minute: '2-digit', timeZoneName: 'shortOffset' });
                        const open = button(`${t.view_appointment} · ${item.host_name}`, () => inspect(item), 'box-arrow-in-right');
                        open.dataset.appointmentId = item.id;
                        section.append(node('article', { class: 'border-top pt-2 mt-2' },
                            node('p', { class: 'fw-semibold' }, `${clock(item.starts_at)} – ${clock(item.ends_at)} · ${current.timezone}`),
                            node('p', {}, `${item.program_code} · ${item.program_title}`), node('p', {}, `${item.host_name} · ${t.status[item.status]}`), open));
                    }
                    days.push(section);
                }
                output.replaceChildren(node('p', { class: 'form-text' }, `${current.from} – ${current.through} · ${current.timezone}`), ...days);
                if (!days.length) output.append(node('p', { role: 'status' }, t.calendar_empty));
                if (restore) {
                    const control = output.querySelector(`[data-appointment-id="${CSS.escape(restore)}"]`) || refresh;
                    control.focus(); restore = null;
                }
            }
            function inspect(item) {
                generation++;
                controls.hidden = navigation.hidden = true;
                output.replaceChildren();
                const details = node('section'); output.append(details);
                const base = detailEndpoint ? detailEndpoint(item)
                    : `/portal/api/students/${item.student_id}/programs/${item.pursuit_id}/appointments/`;
                window.createAdvisingAppointments({ node, request, labels: t }).mount(details, base, {
                    owner, portal, selectedAppointment: item.id,
                    onDenied: () => { current = null; onDenied?.(); },
                    onBack: () => work(async () => {
                        controls.hidden = navigation.hidden = false; restore = item.id; await load();
                    }),
                });
            }
            if (initial) draw();
            else if (initialError) status.textContent = [401, 403].includes(initialError.status) ? t.denied : initialError.message || t.calendar_failure;
            else return work(() => load());
        },
    });
})();
