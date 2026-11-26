/* Owner-only published class times. All state stays in this page; no booking or roster controls. */
window.createAcademicTimetable = function (ui) {
    'use strict';
    const { node, request, task, table, labels } = ui;
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
        let overlapsOnly = false, inspected = null, comparisonPage = 0;
        let exporting = false, exportButton = null, exportStatus = null;
        let exportAbort = null;
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
            exportAbort?.abort();
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
            exportAbort?.abort();
            const query = { term_id: term.value, date: date.value, view };
            output.replaceChildren(node('p', { role: 'status' }, 'Getting your published class times…'));
            const result = await request(`${base}view/`, 'POST', query);
            if (!root.isConnected || serial !== generation || term.value !== query.term_id || date.value !== query.date || view !== query.view) return;
            current = result; day = null; dailyPage = 0; inspected = null; comparisonPage = 0;
            draw('period');
        }
        const clock = instant => new Date(instant).toLocaleString(undefined, {
            timeZone: current.timezone, timeZoneName: 'shortOffset',
        });
        async function downloadCalendar() {
            if (exporting || !current || !root.isConnected) return;
            exporting = true;
            const serial = generation;
            const query = { term_id: current.term.id, date: current.from, view: current.view };
            const active = () => root.isConnected && generation === serial;
            const controller = new AbortController(); exportAbort = controller;
            const timeout = setTimeout(() => controller.abort(), 15000);
            const leaving = () => controller.abort();
            window.addEventListener('pagehide', leaving, { once: true });
            exportStatus.textContent = labels.preparing;
            exportStatus.setAttribute('role', 'status');
            try {
                const result = await request(`${base}export/`, 'POST', query, controller.signal);
                if (!active()) return;
                if (typeof result.calendar !== 'string' || !result.calendar.startsWith('BEGIN:VCALENDAR\r\n')
                    || !result.calendar.endsWith('END:VCALENDAR\r\n')) throw new Error(labels.failed);
                const file = new Blob([result.calendar], { type: 'text/calendar;charset=utf-8' });
                if (file.size > 2097152) throw new Error(labels.failed);
                const url = URL.createObjectURL(file);
                const cleanup = () => {
                    URL.revokeObjectURL(url); clearTimeout(timer);
                    window.removeEventListener('pagehide', cleanup);
                };
                const timer = setTimeout(cleanup, 1000);
                window.addEventListener('pagehide', cleanup, { once: true });
                const link = node('a', { href: url, download: 'my-timetable.ics', hidden: true });
                root.append(link); link.click(); link.remove();
                exportStatus.textContent = labels.started;
            } catch (error) {
                if (!active()) return;
                if ([401, 403].includes(error.status)) {
                    generation++; current = null;
                    root.replaceChildren(node('p', { role: 'alert' }, labels.denied));
                } else {
                    exportStatus.setAttribute('role', 'alert'); exportStatus.textContent = labels.failed;
                }
            } finally {
                clearTimeout(timeout); window.removeEventListener('pagehide', leaving);
                if (exportAbort === controller) exportAbort = null;
                exporting = false;
                if (exportButton?.isConnected) exportButton.disabled = false;
            }
        }

        function calendarDownload() {
            exportButton = button(labels.download, downloadCalendar);
            exportButton.prepend(node('i', { class: 'bi bi-calendar2-plus', 'aria-hidden': 'true' }));
            exportButton.disabled = exporting;
            exportStatus = node('p', { role: 'status', class: 'form-text mt-2 mb-0' }, exporting ? labels.preparing : '');
            return node('section', { class: 'border rounded bg-white p-3 my-4', 'aria-label': labels.title },
                node('h3', { class: 'h5' }, labels.title), node('p', {}, labels.scope),
                node('p', { class: 'form-text' }, labels.privacy), exportButton, exportStatus);
        }
        const classLabel = item => node('div', {}, node('strong', { class: 'd-block' }, item.section_code),
            node('span', {}, item.title || item.course_code || 'Course details unavailable'));
        const location = item => node('div', {}, node('span', { class: 'd-block' }, item.room_code
            ? `${item.room_code} · ${item.room_name}` : 'Online · No room'),
            node('span', { class: 'small' }, [item.building_code, item.building_name].filter(Boolean).join(' · ')));
        function overlapSummary() {
            const counts = current.overlaps;
            const section = node('section', { class: 'academic-overlap-summary mb-4', 'aria-label': 'Schedule overlap review' });
            section.append(node('h3', { class: 'h5' }, counts.pairs
                ? `${counts.pairs.toLocaleString()} possible ${counts.pairs === 1 ? 'overlap' : 'overlaps'} to review`
                : 'Published meeting overlap review'));
            section.append(node('p', {}, counts.pairs
                ? `${counts.meetings.toLocaleString()} meetings in ${counts.sections} classes share time in this term’s published ${current.view}. Review these times with your institution.`
                : current.items.length
                    ? `No overlaps between different sections in this term’s published meetings for this ${current.view}.`
                    : 'There are no published meetings in this period to compare.'));
            if (current.classes.some(item => item.schedule_status === 'unpublished'))
                section.append(node('p', { class: 'form-text' }, 'Some class meeting times have not been published yet.'));
            const filter = node('input', { id: 'academic-overlaps-only', type: 'checkbox', class: 'form-check-input', checked: overlapsOnly });
            filter.addEventListener('change', () => {
                overlapsOnly = filter.checked; dailyPage = 0; inspected = null; comparisonPage = 0; draw('day');
            });
            section.append(node('div', { class: 'form-check' }, filter,
                node('label', { for: filter.id, class: 'form-check-label' }, 'Show overlapping meetings only')));
            if (counts.pairs) section.append(button('Review possible overlaps', () => {
                overlapsOnly = true; day = current.days.find(item => item.overlaps.pairs)?.date || day;
                dailyPage = 0; inspected = null; comparisonPage = 0; draw('day');
            }));
            section.append(node('p', { class: 'form-text mt-2 mb-0' },
                'Advisory review of the selected term’s published times. Other terms and unpublished class times are outside this review.'));
            return section;
        }
        function inspect(selected, byId) {
            const target = byId.get(inspected);
            const expected = selected.items.find(item => item.id === inspected)?.overlap_count;
            if (!target || !Number.isInteger(expected) || expected < 1)
                throw new Error('Reload your timetable before reviewing these overlaps.');
            const start = Math.max(Date.parse(target.starts_at), Date.parse(selected.starts_at));
            const end = Math.min(Date.parse(target.ends_at), Date.parse(selected.ends_at));
            const peers = current.items.filter(item => item.section_id !== target.section_id).map(item => ({
                ...item, sharedStart: Math.max(start, Date.parse(item.starts_at)), sharedEnd: Math.min(end, Date.parse(item.ends_at)),
            })).filter(item => item.sharedStart < item.sharedEnd);
            if (peers.length !== expected) throw new Error('Reload your timetable before reviewing these overlaps.');
            const heading = node('h4', { class: 'h5', tabindex: '-1' }, `Overlaps for ${target.section_code}`);
            const panel = node('section', { class: 'academic-overlap-details mt-4', 'aria-label': 'Selected meeting overlap details' },
                heading, classLabel(target), node('p', {}, `${clock(target.starts_at)} through ${clock(target.ends_at)}`),
                node('p', { class: 'form-text' }, `Shared time on ${label(selected.date)}. Compare these classes before contacting your institution.`));
            const duration = (from, through) => {
                const seconds = (through - from) / 1000, minutes = Math.floor(seconds / 60), remaining = seconds % 60;
                if (!minutes) return `${seconds} seconds`;
                return remaining ? `${minutes} min ${remaining} sec` : `${minutes} ${minutes === 1 ? 'minute' : 'minutes'}`;
            };
            panel.append(table(['Other class', 'Role', 'Shared time', 'Location'],
                peers.slice(comparisonPage * 20, (comparisonPage + 1) * 20).map(item => [classLabel(item), role(item),
                    node('div', {}, node('span', { class: 'd-block' },
                        `${clock(item.sharedStart)} through ${clock(item.sharedEnd)}`),
                    node('strong', {}, duration(item.sharedStart, item.sharedEnd))), location(item)]), 'Overlapping class meetings'));
            const nav = node('nav', { class: 'd-flex flex-wrap align-items-center gap-2 mt-3', 'aria-label': 'Overlap comparison pages' });
            if (comparisonPage) nav.append(button('Previous comparisons', () => { comparisonPage--; draw('inspection'); }));
            nav.append(node('span', { role: 'status' },
                `Comparisons ${comparisonPage * 20 + 1}–${Math.min((comparisonPage + 1) * 20, peers.length)} of ${peers.length}`));
            if ((comparisonPage + 1) * 20 < peers.length)
                nav.append(button('Next comparisons', () => { comparisonPage++; draw('inspection'); }));
            nav.append(button('Back to meetings', () => {
                const id = inspected; inspected = null; comparisonPage = 0; draw();
                output.querySelector(`[data-overlap-meeting="${CSS.escape(id)}"]`)?.focus();
            }));
            panel.append(nav);
            return { panel, heading };
        }
        function draw(focus = null) {
            if (!current) return;
            const selected = current.days.find(item => item.date === day)
                || current.days.find(item => item.date === context.today) || current.days[0];
            day = selected.date;
            const title = node('h2', { class: 'h4', tabindex: '-1' }, `${current.term.code} · ${label(current.from)} through ${label(current.through)}`);
            const navigation = node('nav', { class: 'd-flex flex-wrap gap-2 mb-3', 'aria-label': 'Timetable dates' },
                button('Previous', () => { date.value = current.previous; return load(); }),
                button('This week', () => { view = 'week'; modes(); date.value = context.today; return load(); }),
                button('Next', () => { date.value = current.next; return load(); }));
            const days = node('div', { class: 'academic-timetable-days', 'data-view': view, role: 'group', 'aria-label': 'Choose a day' });
            if (view === 'month') {
                ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].forEach(text => days.append(
                    node('span', { class: 'academic-timetable-weekday', 'aria-hidden': 'true' }, text)));
                const leading = (new Date(`${current.from}T12:00:00Z`).getUTCDay() + 6) % 7;
                for (let i = 0; i < leading; i++) days.append(node('span', { class: 'academic-timetable-spacer', 'aria-hidden': 'true' }));
            }
            current.days.forEach(item => {
                const control = button('', () => { day = item.date; dailyPage = 0; inspected = null; comparisonPage = 0; draw('day'); });
                control.className = 'academic-timetable-day';
                control.setAttribute('aria-pressed', String(item.date === day));
                control.setAttribute('aria-label', `${label(item.date)}: ${item.counts.published} meetings; ${item.overlaps.meetings} with overlaps`);
                control.append(node('span', { class: 'd-block' }, label(item.date)),
                    node('strong', {}, `${item.counts.published} meetings`));
                if (item.overlaps.meetings) control.append(node('span', { class: 'academic-overlap-note d-block' },
                    `${item.overlaps.meetings} with possible overlaps`));
                days.append(control);
            });
            const byId = new Map(current.items.map(item => [item.id, item]));
            const entries = selected.items.filter(item => !overlapsOnly || item.overlap_count > 0)
                .map(item => ({ ...byId.get(item.id), ...item }));
            const listing = entries.length ? table(['Class', 'Role', 'Starts', 'Ends', 'Location', 'Overlap review'],
                entries.slice(dailyPage * 20, (dailyPage + 1) * 20).map(item => [
                    classLabel(item),
                    role(item), item.continues_before ? 'Continues from an earlier day' : clock(item.starts_at),
                    item.continues_after ? 'Continues into the next day' : clock(item.ends_at),
                    location(item), item.overlap_count ? node('button', { type: 'button', class: 'btn campus-button-outline',
                        'data-overlap-meeting': item.id, onclick: event => task(() => {
                            inspected = item.id; comparisonPage = 0; draw('inspection');
                        }, event.currentTarget) }, `Review ${item.overlap_count} ${item.overlap_count === 1 ? 'overlap' : 'overlaps'}`)
                        : node('span', { class: 'small' }, 'No overlap on this day'),
                ]), 'Published class meetings') : node('p', { class: 'campus-empty' }, overlapsOnly
                    ? 'No overlapping class meetings for this day.' : 'No published meetings for this day.');
            const dailyHeading = node('h3', { class: 'h5', tabindex: '-1' }, label(day));
            const details = node('section', { class: 'mt-4', 'aria-label': 'Daily class meetings' },
                dailyHeading, overlapsOnly ? node('p', { class: 'form-text' }, 'Showing meetings with overlaps only.') : null, listing);
            if (entries.length) {
                const paging = node('nav', { class: 'd-flex flex-wrap align-items-center gap-2 mt-3', 'aria-label': 'Daily meeting pages' });
                if (dailyPage) paging.append(button('Previous meetings', () => { dailyPage--; inspected = null; draw('day'); }));
                paging.append(node('span', { role: 'status' }, `Meetings ${dailyPage * 20 + 1}–${Math.min((dailyPage + 1) * 20, entries.length)} of ${entries.length}`));
                if ((dailyPage + 1) * 20 < entries.length) paging.append(button('Next meetings', () => { dailyPage++; inspected = null; draw('day'); }));
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
                `${current.totals.published} published meetings in this ${current.view}. ${current.timezone}.`),
                overlapSummary(), calendarDownload(), navigation, days, details, classes);
            const comparison = inspected ? inspect(selected, byId) : null;
            if (comparison) details.append(comparison.panel);
            if (focus === 'period') title.focus();
            if (focus === 'day') dailyHeading.focus();
            if (focus === 'inspection') comparison?.heading.focus();
        }
        draw();
    }
    return { mount };
};
