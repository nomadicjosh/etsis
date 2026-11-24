/* Owner-only application history. Accepted pages stay in memory; each unseen page is authorized by the server. */
(() => {
    'use strict';
    let sequence = 0;
    const configuration = document.querySelector('#campus-application-history-config');
    const defaults = configuration ? JSON.parse(configuration.textContent) : {};
    window.applicationHistoryLabels = defaults;
    window.createApplicantApplicationHistory = ({ node, request, task, labels = defaults,
        progress = window.campusApplicationProgress }) => ({
        mount(container, { beforeOpen = () => true } = {}) {
            const text = key => labels[key];
            const format = (key, values) => text(key).replace(/\{([a-z_]+)\}/g, (_, name) => String(values[name] ?? ''));
            const identity = `application-history-${++sequence}`;
            const panel = node('section', { id: identity, class: 'campus-application-history mt-4', hidden: true,
                'aria-labelledby': `${identity}-title` });
            container.append(panel);
            let generation = 0, active = null, opener = null, nextSteps = null, busy = false;
            let pages = [], index = 0, snapshot = null;
            const alive = ticket => ticket === generation && panel.isConnected && !panel.hidden;
            const icons = { open: 'clock-history', close: 'x-lg', previous: 'arrow-left', next: 'arrow-right',
                refresh: 'arrow-clockwise', retry: 'arrow-repeat', next_steps: 'list-check' };
            const button = (key, action, style = 'campus-button-outline') => {
                const b = node('button', { type: 'button', class: `btn ${style}` },
                    node('i', { class: `bi bi-${icons[key]}`, 'aria-hidden': 'true' }), node('span', {}, text(key)));
                b.addEventListener('click', () => task(action, b)); return b;
            };
            function close({ focus = true } = {}) {
                generation++; busy = false; active = null; pages = []; snapshot = null;
                panel.hidden = true; panel.replaceChildren();
                opener?.setAttribute('aria-expanded', 'false'); opener?.removeAttribute('aria-busy');
                if (focus && opener?.isConnected) opener.focus();
                opener = null; nextSteps = null;
            }
            const date = value => value ? format('utc', { date: new Date(value).toLocaleString(undefined,
                { timeZone: 'UTC', year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) }) : text('unknown');
            const program = value => `${value.code} · ${value.title}`;
            function definition(source, key) {
                const card = node('section', { class: 'border rounded bg-white application-history-card h-100' }, node('h3', { class: 'h6' }, text(key)));
                if (!source) { card.append(node('p', { class: 'mb-0' }, text('original_unknown'))); return card; }
                card.append(node('p', { class: 'fw-semibold text-break' }, program(source.program)),
                    node('p', { class: 'mb-0 text-break' }, format('intake', { term: source.entry_term.code })));
                if (source.effective_at) card.append(node('p', { class: 'form-text mb-0' }, format('submitted_at', { date: date(source.effective_at) })));
                return card;
            }
            let heading, status, content, navigation;
            async function open(application, trigger, onNextSteps = null) {
                if (opener === trigger && !panel.hidden) { close(); return; }
                if (!beforeOpen()) return;
                close({ focus: false }); active = application; opener = trigger; nextSteps = onNextSteps;
                trigger.setAttribute('aria-expanded', 'true'); trigger.setAttribute('aria-busy', 'true');
                heading = node('h2', { id: `${identity}-title`, tabindex: '-1', class: 'h4' }, text('title'));
                status = node('p', { role: 'status', 'aria-live': 'polite', 'aria-atomic': 'true', class: 'form-text' });
                content = node('div'); navigation = node('nav', { class: 'd-flex flex-wrap gap-2 mt-3', 'aria-label': text('pages') });
                panel.replaceChildren(node('div', { class: 'd-flex flex-wrap gap-2 align-items-start justify-content-between' },
                    heading, button('close', () => close())), status, content, navigation);
                panel.hidden = false; heading.focus(); await load(null, true);
            }
            function render() {
                const page = pages[index], state = progress(snapshot.current.status);
                content.replaceChildren(node('p', { class: 'fw-semibold' }, format('current_status', { status: state.label })),
                    node('p', { class: 'form-text' }, state.next), node('p', { class: 'form-text' }, format('checked', { date: date(snapshot.checked_at) })));
                if (state.href) content.append(node('a', { href: state.href, class: 'btn campus-button-outline mb-3' }, text('student_services')));
                const cards = node('div', { class: 'application-history-summaries mb-3' });
                cards.append(node('div', { class: 'application-history-summary' }, definition(snapshot.original, 'original')),
                    node('div', { class: 'application-history-summary' }, definition(snapshot.current, 'current')));
                content.append(cards, node('p', { class: 'form-text' }, text('snapshot_help')));
                if (snapshot.earlier_history_unknown) content.append(node('p', { class: 'alert alert-info' }, text('earlier_unknown')));
                const list = node('ol', { class: 'list-unstyled', 'aria-label': text('entries') });
                for (const item of page.items) {
                    const kind = item.kind === 'decision' || item.kind === 'correction'
                        ? `${item.kind}_${item.status}` : item.kind;
                    const card = node('li', { class: 'border rounded bg-white application-history-card mb-3' },
                        node('h3', { class: 'h6' }, text(`event_${kind}`) || text('event_unknown')));
                    if (item.source) card.append(node('p', { class: 'text-break' },
                        `${program(item.source.program)} · ${item.source.entry_term.code}`));
                    if (item.program) card.append(node('p', { class: 'text-break' },
                        format('program_change', { before: program(item.program.before), after: program(item.program.after) })));
                    if (item.entry) for (const key of ['before', 'after']) {
                        const term = item.entry[key];
                        card.append(node('p', { class: 'text-break' }, format(key, { term: `${term.code} · ${term.name}` })),
                            node('p', { class: 'form-text' }, format('term_dates', { start: term.starts_on, end: term.ends_on })));
                        if (term.academic_year) card.append(node('p', { class: 'form-text' }, format('academic_year', { year: term.academic_year })));
                    }
                    if (item.kind === 'observation') card.append(node('p', { class: 'form-text' },
                        format('observed', { status: progress(item.status).label, date: date(item.recorded_at) })));
                    else card.append(node('p', { class: 'form-text mb-0' }, format('effective', { date: date(item.effective_at) })),
                        node('p', { class: 'form-text mb-0' }, format('recorded', { date: date(item.recorded_at) })));
                    list.append(card);
                }
                content.append(list);
                if (!page.items.length) content.append(node('p', {}, page.next_cursor ? text('empty_page') : text('empty')));
                controls(); heading.focus();
            }
            function controls() {
                navigation.replaceChildren();
                if (index > 0) navigation.append(button('previous', () => { if (!busy) { index--; render(); status.textContent = text('ready'); } }));
                if (index + 1 < pages.length) navigation.append(button('next', () => { if (!busy) { index++; render(); status.textContent = text('ready'); } }));
                else if (pages[index]?.next_cursor && pages.length < 200) navigation.append(button('next', () => { if (!busy) return load(pages[index].next_cursor); }));
                else if (pages[index]?.next_cursor) navigation.append(node('p', { class: 'form-text' }, text('limit')));
                navigation.append(button('refresh', () => { if (!busy) return load(null, true); }));
                if (nextSteps) navigation.append(button('next_steps', async () => {
                    if (busy) return; const action = nextSteps; close({ focus: false }); await action();
                }, 'campus-button-outline'));
                for (const control of navigation.querySelectorAll('button')) control.disabled = busy;
            }
            async function load(cursor, refresh = false) {
                if (busy) return;
                const ticket = generation; busy = true; status.textContent = text('loading'); controls();
                try {
                    const query = cursor ? `?${new URLSearchParams({ cursor })}` : '';
                    const data = await request(`/portal/api/applications/${active}/history/${query}`);
                    if (!alive(ticket)) return;
                    if (refresh) { pages = [data]; index = 0; snapshot = data; }
                    else { pages.push(data); index = pages.length - 1; }
                    render(); status.textContent = text('ready');
                } catch (error) {
                    if (!alive(ticket)) return;
                    if ([401, 403, 404].includes(error.status)) {
                        pages = []; snapshot = null; content.replaceChildren(); navigation.replaceChildren();
                        status.textContent = text('denied');
                    } else {
                        status.textContent = text('failed');
                        if (!snapshot) navigation.replaceChildren(button('retry', () => load(null, true)));
                    }
                    throw error;
                } finally {
                    if (alive(ticket)) {
                        busy = false; opener?.removeAttribute('aria-busy');
                        if (snapshot) controls();
                    }
                }
            }
            return { close, trigger(application, label, onNextSteps = null) {
                const b = button('open', () => open(application, b, onNextSteps));
                b.setAttribute('aria-label', format('open_for', { application: label }));
                b.setAttribute('aria-expanded', 'false'); b.setAttribute('aria-controls', identity); return b;
            } };
        }
    });
})();
