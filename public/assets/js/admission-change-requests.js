/* Private working follow-ups. Actual program/intake corrections use their own reviewed writers. */
(() => {
    'use strict';
    const config = document.getElementById('admission-change-labels');
    const labels = config ? JSON.parse(config.textContent) : {};
    window.admissionChangeLabels = labels;
    let sequence = 0;
    window.createAdmissionChangeRequests = ({ node, request, task }) => ({
        mount(container, applicationId, { staff = false, toggleTarget = null, label = '', state = {},
            beforeOpen = () => true, correctProgram = null, correctEntry = null, initiallyOpen = false } = {}) {
            state.replies ||= {}; state.message ||= ''; state.area ||= 'program';
            let generation = 0, expanded = false, busy = false, denied = false, retaining = false, disposed = false;
            let packet = null, finished = false, after = '', stack = [], needsRefresh = false;
            const base = `${staff ? '/sis' : '/portal/api'}/applications/${applicationId}/change-requests/`;
            const identity = `admission-changes-${++sequence}`;
            const button = (key, run, style = 'btn-outline-primary', icon = '') => node('button', {
                type: 'button', class: `btn ${style} mw-100 text-wrap`, onclick: () => task(run),
            }, icon ? node('i', { class: `bi ${icon} me-2`, 'aria-hidden': 'true' }) : null, labels[key]);
            const toggle = button('title', async () => {
                if (!beforeOpen() || !beforeLeave()) return;
                if (expanded) {
                    generation++; expanded = false; packet = null; list.replaceChildren(); composer.replaceChildren();
                    content.hidden = true; toggle.setAttribute('aria-expanded', 'false'); return;
                }
                expanded = true; content.hidden = false; toggle.setAttribute('aria-expanded', 'true'); await load();
            }, 'btn-outline-primary', 'bi-chat-left-text');
            toggle.setAttribute('aria-controls', identity); toggle.setAttribute('aria-expanded', 'false');
            if (label) toggle.setAttribute('aria-label', `${labels.title}: ${label}`);
            const status = node('p', { role: 'status', class: 'form-text', 'aria-atomic': 'true', tabindex: '-1' });
            const controls = node('fieldset', { class: 'border-0 p-0 m-0' });
            const retry = button('retry', () => sendCaptured());
            const discard = button('discard', async () => {
                if (busy || !window.confirm(labels.discard_help)) return;
                state.pending = null; needsRefresh = true; pendingArea.hidden = true; await load();
            }, 'btn-outline-secondary');
            const pendingArea = node('div', { class: 'inline-actions mb-3', hidden: true }, retry, discard);
            const list = node('div'), composer = node('div');
            const refresh = button('refresh', () => load());
            const active = button('active', () => switchView(false));
            const completed = button('finished', () => switchView(true));
            const previous = button('previous', () => load({ after: stack.at(-1), stack: stack.slice(0, -1) }));
            const next = button('next', () => load({ after: packet?.next_after, stack: [...stack, after] }));
            const content = node('section', { id: identity, class: 'record-form-section admission-change-panel mt-3', hidden: true,
                'aria-label': labels.title }, node('h3', { class: 'h5' }, labels.title),
            node('p', {}, staff ? labels.staff_help : labels.help),
            node('div', { class: 'inline-actions mb-3' }, active, completed, refresh), status, pendingArea, controls);
            controls.append(list, node('nav', { class: 'inline-actions mb-3', 'aria-label': labels.pages }, previous, next), composer);
            (toggleTarget || container).append(toggle); container.append(content);
            const alive = ticket => !disposed && !denied && container.isConnected && ticket === generation;
            function reset() { state.message = ''; state.replies = {}; state.pending = null; }
            function privateFailure() {
                denied = true; generation++; reset(); packet = null; controls.replaceChildren(); pendingArea.replaceChildren();
                content.querySelectorAll('button').forEach(item => { item.disabled = true; });
                toggle.disabled = true; status.textContent = labels.denied;
            }
            function sync() {
                controls.disabled = busy || !!state.pending || needsRefresh;
                retry.disabled = busy; discard.disabled = busy; pendingArea.hidden = !state.pending;
                refresh.disabled = busy; active.disabled = busy; completed.disabled = busy;
                toggle.disabled = busy;
                active.setAttribute('aria-pressed', String(!finished)); completed.setAttribute('aria-pressed', String(finished));
                previous.disabled = busy || !stack.length; next.disabled = busy || !packet?.next_after;
            }
            function dirty() { return !!state.message.trim() || Object.values(state.replies).some(value => value.trim()) || !!state.pending; }
            function beforeLeave() {
                if (busy) { status.textContent = labels.busy; return false; }
                if (dirty() && !window.confirm(labels.leave)) return false;
                if (dirty()) reset();
                return true;
            }
            async function switchView(value) {
                if (busy) return;
                await load({ finished: value, after: '', stack: [] });
            }
            async function load(candidate = { finished, after, stack }) {
                if (busy || denied || disposed) return;
                const ticket = ++generation; busy = true; sync(); status.textContent = labels.loading;
                const target = { finished, ...candidate };
                try {
                    const query = new URLSearchParams({ finished: target.finished ? '1' : '0' });
                    if (target.after) query.set('after', target.after);
                    const data = await request(`${base}?${query}`);
                    if (!alive(ticket)) return;
                    packet = data; finished = target.finished; after = target.after || ''; stack = [...(target.stack || [])];
                    needsRefresh = false; render(); status.textContent = state.pending ? labels.failed : ''; return true;
                } catch (error) {
                    if (!alive(ticket)) return;
                    if ([401, 403].includes(error.status)) privateFailure();
                    else status.textContent = labels.load_failed;
                } finally { busy = false; if (alive(ticket)) sync(); }
            }
            async function sendCaptured() {
                if (busy || !state.pending || denied || disposed) return;
                const captured = state.pending, ticket = generation; busy = true; sync(); status.textContent = labels.saving;
                let accepted = false;
                try {
                    await request(base + captured.path, 'POST', captured.body); accepted = true;
                    if (!alive(ticket)) return;
                    state.pending = null; needsRefresh = true;
                    if (captured.path === 'request/') state.message = '';
                    else delete state.replies[captured.body.request_id];
                    status.textContent = labels.saved;
                } catch (error) {
                    if (!alive(ticket)) return;
                    if ([401, 403].includes(error.status)) privateFailure();
                    else status.textContent = labels.failed;
                } finally { busy = false; if (alive(ticket)) sync(); }
                if (accepted && alive(ticket)) {
                    const refreshed = await load();
                    if (!disposed && !denied && container.isConnected) {
                        status.textContent = refreshed ? labels.saved : labels.saved + ' ' + labels.load_failed;
                        status.focus({ preventScroll: true });
                    }
                }
            }
            function capture(path, body) {
                if (busy || state.pending || denied) return;
                state.pending = { path, body: { ...body } }; return sendCaptured();
            }
            function textField(form, key, value, update) {
                const id = `admission-change-field-${++sequence}`;
                const input = node('textarea', { id, class: 'form-control', rows: '3', maxlength: '1000', required: true, value });
                const help = node('p', { id: `${id}-help`, class: 'form-text' }, labels[staff ? 'reply_help' : 'privacy']);
                input.setAttribute('aria-describedby', help.id);
                input.addEventListener('input', () => update(input.value));
                form.append(node('label', { for: id, class: 'form-label' }, labels[key]), input, help); return input;
            }
            function render() {
                list.replaceChildren(); composer.replaceChildren();
                if (!packet.items.length) list.append(node('p', {}, labels.empty));
                packet.items.forEach(item => {
                    const card = node('article', { class: 'admission-change-card bg-white border rounded mb-3' },
                        node('h4', { class: 'h6' }, labels[item.area]), node('p', { class: 'fw-semibold' }, labels[item.status]),
                        node('p', { class: 'text-break', style: 'white-space: pre-wrap' }, item.message),
                        node('p', { class: 'form-text text-break' }, `${labels.created}: ${item.created_at}`));
                    if (item.application_changed) card.append(node('p', { class: 'alert alert-info' }, labels.changed));
                    if (staff && item.owner_changed) card.append(node('p', { class: 'alert alert-info' }, labels.owner_changed));
                    if (item.resolution) card.append(node('p', { class: 'fw-semibold' }, labels.reply),
                        node('p', { class: 'text-break', style: 'white-space: pre-wrap' }, item.resolution),
                        node('p', { class: 'form-text text-break' }, `${labels.resolved}: ${item.resolved_at}`));
                    if (item.status === 'requested') {
                        if (staff) {
                            const form = node('form', { class: 'record-form' });
                            const input = textField(form, 'reply', state.replies[item.id] || '', value => { state.replies[item.id] = value; });
                            form.append(node('button', { type: 'submit', class: 'btn btn-primary mw-100 text-wrap' }, labels.close));
                            form.addEventListener('submit', event => { event.preventDefault();
                                if (form.reportValidity()) task(() => capture('close/', { request_id: item.id, version: item.version,
                                    application_revision: packet.application_revision, message: input.value })); });
                            if (packet.application_status === 'submitted') {
                                const correct = item.area === 'program' ? correctProgram : correctEntry;
                                if (correct) {
                                    card.append(button(item.area === 'program' ? 'correct_program' : 'correct_entry', async () => {
                                        if (busy || state.pending || !beforeOpen()) return;
                                        retaining = true;
                                        try { await correct(); } finally { if (container.isConnected) retaining = false; }
                                    }), node('p', { class: 'form-text mt-2' }, labels.retained));
                                }
                            }
                            card.append(form);
                        } else card.append(button('cancel', () => capture('cancel/', { request_id: item.id, version: item.version,
                            application_revision: packet.application_revision, message: labels.cancel_reason }), 'btn-outline-secondary'));
                    }
                    list.append(card);
                });
                if (!staff && !finished) {
                    if (packet.application_status !== 'submitted') { composer.append(node('p', {}, labels.closed_application)); return; }
                    const form = node('form', { class: 'record-form admission-change-card bg-white border rounded' }, node('h4', { class: 'h6' }, labels.new));
                    const id = `admission-change-area-${++sequence}`;
                    const area = node('select', { id, class: 'form-select mb-3' },
                        ['program', 'entry_term'].map(value => node('option', { value }, labels[value])));
                    area.value = state.area; area.addEventListener('change', () => { state.area = area.value; });
                    form.append(node('label', { for: id, class: 'form-label' }, labels.area), area);
                    const input = textField(form, 'message', state.message, value => { state.message = value; });
                    form.append(node('button', { type: 'submit', class: 'btn btn-primary' }, labels.send));
                    form.addEventListener('submit', event => { event.preventDefault();
                        if (form.reportValidity()) task(() => capture('request/', { request_id: packet.new_id, area: area.value,
                            application_revision: packet.application_revision, message: input.value })); });
                    composer.append(form);
                }
            }
            const drawer = container.closest('.offcanvas');
            const guard = event => { if (!retaining && !beforeLeave()) event.preventDefault(); };
            drawer?.addEventListener('hide.bs.offcanvas', guard);
            function dispose() {
                if (disposed) return;
                disposed = true; generation++; packet = null; observer.disconnect();
                drawer?.removeEventListener('hide.bs.offcanvas', guard); window.removeEventListener('pagehide', pageHide);
                window.removeEventListener('beforeunload', beforeUnload);
                if (!retaining) reset();
            }
            const pageHide = () => { reset(); dispose(); };
            const beforeUnload = event => { if (busy || dirty()) { event.preventDefault(); event.returnValue = ''; } };
            window.addEventListener('pagehide', pageHide);
            window.addEventListener('beforeunload', beforeUnload);
            const observer = new MutationObserver(() => { if (!container.isConnected) dispose(); });
            observer.observe(document.body, { childList: true, subtree: true });
            if (initiallyOpen) { expanded = true; content.hidden = false; toggle.setAttribute('aria-expanded', 'true'); task(() => load()); }
            return { beforeLeave, refresh: load, open: async () => {
                if (!expanded) { expanded = true; content.hidden = false; toggle.setAttribute('aria-expanded', 'true'); await load(); }
                toggle.focus();
            } };
        },
    });
})();
