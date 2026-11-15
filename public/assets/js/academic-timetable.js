/* Owner-only published class times. All state stays in this page; no booking or roster controls. */
window.createAcademicTimetable = function (ui) {
    'use strict';
    const { node, request, task, table } = ui;
    const base = '/portal/api/timetable/';
    const label = date => new Date(`${date}T12:00:00Z`).toLocaleDateString(undefined, {
        timeZone: 'UTC', weekday: 'long', month: 'short', day: 'numeric', year: 'numeric',
    });
    const role = item => item.learning && item.teaching ? 'Learning & teaching' : item.teaching ? 'Teaching' : 'Learning';
    const button = (text, run, style = 'campus-button-outline') => node('button', {
        type: 'button', class: `btn ${style}`, onclick: event => task(run, event.currentTarget),
    }, text);

    function mount(container, context, initial) {
        const root = node('div', { class: 'academic-timetable' });
        container.append(root);
        if (!context.recommended_term && !context.terms.length) {
            root.append(node('div', { class: 'campus-empty' }, node('h2', { class: 'h4' }, 'Your next chapter has room to grow.'),
                node('p', {}, 'Your timetable will appear here when you have an active course registration or teaching assignment.')));
            return;
        }
        let generation = 0, searchGeneration = 0, current = initial, day = null, dailyPage = 0;
        let terms = context.terms, nextAfter = context.next_after, selectedTerm = context.recommended_term || terms[0];
        let available = new Map();
        const form = node('form', { class: 'academic-timetable-controls' });
        const term = node('select', { id: 'academic-term', name: 'term_id', class: 'form-select', required: true });
        const date = node('input', { id: 'academic-date', name: 'date', class: 'form-control', type: 'date', required: true,
            min: '1000-01-06', max: '9998-12-27', value: initial?.from || context.today });
        const mode = node('div', { class: 'btn-group', role: 'group', 'aria-label': 'Timetable view' });
        let view = initial?.view || 'week';
        const week = button('Week', () => changeView('week'));
        const month = button('Month', () => changeView('month'));
        mode.append(week, month);
        const apply = node('button', { type: 'submit', class: 'btn campus-button' }, 'View timetable');
        const output = node('div', { class: 'mt-4', 'aria-live': 'polite' });
        const help = node('p', { class: 'form-text' },
            `Times use ${context.timezone}. Room names and meeting times come from your institution’s published class schedule.`);
        const search = node('input', { id: 'academic-term-search', name: 'search', type: 'search', maxlength: 80,
            class: 'form-control', placeholder: 'Term code', autocomplete: 'off' });
        const more = button('More terms', () => findTerms(nextAfter));
        const searchStatus = node('p', { class: 'form-text', role: 'status' });
        const searchButton = button('Find terms', () => findTerms());
        const searchPanel = node('details', { class: 'mt-3' }, node('summary', {}, 'Find another term'),
            node('label', { for: search.id, class: 'form-label mt-3' }, 'Find a term'), search,
            node('div', { class: 'd-flex flex-wrap gap-2 mt-2' }, searchButton, more), searchStatus);
        form.append(node('div', {}, node('label', { for: term.id, class: 'form-label' }, 'Academic term'), term),
            node('div', {}, node('label', { for: date.id, class: 'form-label' }, 'Calendar date'), date),
            node('div', {}, mode), apply);
        root.append(help, form, searchPanel, output);
        const options = () => {
            available = new Map([[selectedTerm.id, selectedTerm], ...terms.map(item => [item.id, item])]);
            term.replaceChildren(...Array.from(available.values(), item => node('option', { value: item.id }, item.code)));
            term.value = selectedTerm.id;
            more.hidden = !nextAfter;
        };
        options();
        const modes = () => {
            week.setAttribute('aria-pressed', String(view === 'week'));
            month.setAttribute('aria-pressed', String(view === 'month'));
        };
        modes();
        const invalidate = () => {
            generation++;
            output.replaceChildren(node('p', { class: 'campus-muted', role: 'status' }, 'Choose View timetable to show these dates.'));
        };
        date.addEventListener('input', invalidate);
        term.addEventListener('change', () => task(async () => {
            selectedTerm = available.get(term.value) || selectedTerm;
            if (context.today < selectedTerm.starts_on || context.today > selectedTerm.ends_on)
                date.value = selectedTerm.starts_on;
            else date.value = context.today;
            await load();
        }));
        search.addEventListener('input', () => { searchGeneration++; more.hidden = true; });
        search.addEventListener('keydown', event => {
            if (event.key === 'Enter') { event.preventDefault(); task(() => findTerms(), searchButton); }
        });
        form.addEventListener('submit', event => { event.preventDefault(); task(() => load(), apply); });

        async function findTerms(after = null) {
            const serial = ++searchGeneration, text = search.value;
            const result = await request(`${base}terms/`, 'POST', { search: text, after });
            if (!root.isConnected || serial !== searchGeneration || text !== search.value) return;
            terms = result.items;
            nextAfter = result.next_after;
            options();
            searchStatus.textContent = terms.length ? `${terms.length} terms available. Choose a term above.`
                : 'No other terms match this search. Your selected term is still available.';
        }
        async function changeView(value) { view = value; modes(); await load(); }
        async function load() {
            if (!form.reportValidity()) return;
            const serial = ++generation;
            const query = { term_id: term.value, date: date.value, view };
            output.replaceChildren(node('p', { role: 'status' }, 'Getting your published class times…'));
            const result = await request(`${base}view/`, 'POST', query);
            if (!root.isConnected || serial !== generation || term.value !== query.term_id || date.value !== query.date || view !== query.view) return;
            current = result; day = null; dailyPage = 0;
            draw(true);
        }
        function draw(focus = false) {
            if (!current) return;
            const selected = current.days.find(item => item.date === day)
                || current.days.find(item => item.date === context.today) || current.days[0];
            day = selected.date;
            const title = node('h2', { class: 'h4', tabindex: '-1' }, `${current.term.code} · ${label(current.from)} through ${label(current.through)}`);
            const navigation = node('nav', { class: 'd-flex flex-wrap gap-2 mb-3', 'aria-label': 'Timetable dates' },
                button('Previous', () => { date.value = current.previous; return load(); }),
                button('This week', () => { view = 'week'; modes(); date.value = context.today; return load(); }),
                button('Next', () => { date.value = current.next; return load(); }));
            const days = node('div', { class: 'academic-timetable-days', 'data-view': view, 'aria-label': 'Choose a day' });
            if (view === 'month') {
                ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].forEach(text => days.append(
                    node('span', { class: 'academic-timetable-weekday', 'aria-hidden': 'true' }, text)));
                const leading = (new Date(`${current.from}T12:00:00Z`).getUTCDay() + 6) % 7;
                for (let i = 0; i < leading; i++) days.append(node('span', { class: 'academic-timetable-spacer', 'aria-hidden': 'true' }));
            }
            current.days.forEach(item => {
                const control = button('', () => { day = item.date; dailyPage = 0; draw(true); });
                control.className = 'academic-timetable-day';
                control.setAttribute('aria-pressed', String(item.date === day));
                control.setAttribute('aria-label', `${label(item.date)}: ${item.counts.published} meetings`);
                control.append(node('span', { class: 'd-block' }, label(item.date)),
                    node('strong', {}, `${item.counts.published} meetings`));
                days.append(control);
            });
            const byId = new Map(current.items.map(item => [item.id, item]));
            const entries = selected.items.map(item => ({ ...byId.get(item.id), ...item }));
            const clock = instant => new Date(instant).toLocaleString(undefined, { timeZone: current.timezone, timeZoneName: 'shortOffset' });
            const listing = entries.length ? table(['Class', 'Role', 'Starts', 'Ends', 'Location'],
                entries.slice(dailyPage * 20, (dailyPage + 1) * 20).map(item => [
                    node('div', {}, node('strong', { class: 'd-block' }, item.section_code),
                        node('span', {}, item.title || item.course_code || 'Course details unavailable')),
                    role(item), item.continues_before ? 'Continues from an earlier day' : clock(item.starts_at),
                    item.continues_after ? 'Continues into the next day' : clock(item.ends_at),
                    node('div', {}, node('span', { class: 'd-block' }, item.room_code
                        ? `${item.room_code} · ${item.room_name}` : 'Online · No room'),
                        node('span', { class: 'small' }, [item.building_code, item.building_name].filter(Boolean).join(' · '))),
                ]), 'Published class meetings') : node('p', { class: 'campus-empty' }, 'No published meetings for this day.');
            const details = node('section', { class: 'mt-4', 'aria-label': 'Daily class meetings' },
                node('h3', { class: 'h5' }, label(day)), listing);
            if (entries.length) {
                const paging = node('nav', { class: 'd-flex flex-wrap align-items-center gap-2 mt-3', 'aria-label': 'Daily meeting pages' });
                if (dailyPage) paging.append(button('Previous meetings', () => { dailyPage--; draw(true); }));
                paging.append(node('span', { role: 'status' }, `Meetings ${dailyPage * 20 + 1}–${Math.min((dailyPage + 1) * 20, entries.length)} of ${entries.length}`));
                if ((dailyPage + 1) * 20 < entries.length) paging.append(button('Next meetings', () => { dailyPage++; draw(true); }));
                details.append(paging);
            }
            const classes = node('section', { class: 'mt-4', 'aria-label': 'My classes in this term' }, node('h3', { class: 'h5' }, 'Your classes this term'));
            const list = node('ul', { class: 'list-unstyled' });
            current.classes.forEach(item => list.append(node('li', { class: 'academic-timetable-class' },
                node('strong', {}, item.code), node('span', {}, ` · ${item.title || item.course_code || 'Course details unavailable'}`),
                node('p', { class: 'small mb-0' }, `${role(item)} · ${item.schedule_status === 'unpublished'
                    ? 'Meeting times have not been published yet' : item.schedule_status === 'unscheduled'
                        ? 'Published without scheduled meetings' : 'Meeting times published'}`))));
            classes.append(list);
            output.replaceChildren(title, node('p', { class: 'campus-muted' },
                `${current.totals.published} published meetings in this ${current.view}. ${current.timezone}.`), navigation, days, details, classes);
            if (focus) title.focus();
        }
        draw();
    }
    return { mount };
};
