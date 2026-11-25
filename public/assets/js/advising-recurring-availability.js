/* Finite reviewed offers. Personal drafts and review proofs stay in page memory. */
(() => {
    'use strict';
    let sequence = 0;
    window.mountAdvisingRecurringAvailability = ({ root, owner, node, request, labels: t, button, field, work, status, endpoint, timezone, submitClass, back }) => {
        let epoch = 0, draft = null, reviewed = null;
        const reasons = new Map();
        const valid = ticket => root.isConnected && ticket === epoch;
        const clear = () => { epoch++; draft = null; reviewed = null; reasons.clear(); };
        owner.addEventListener('availability-denied', clear, { once: true });
        const leave = () => { clear(); owner.removeEventListener('availability-denied', clear); return back(); };
        const timeList = windows => node('ol', { class: 'list-group list-group-numbered mb-3' }, ...windows.map(w => node('li', {
            class: 'list-group-item text-break bg-white'
        }, node('span', { class: 'fw-semibold' }, w.time.start.slice(0, 10)),
        node('div', {}, `${w.time.start.slice(11)} – ${w.time.end.slice(11)}`),
        node('small', { class: 'form-text text-break d-block' }, w.time.timezone))));
        const actions = (...items) => node('div', { class: 'd-flex flex-wrap gap-2 mb-3' }, ...items);
        const heading = () => node('h4', { class: 'h5' }, t.recurring);
        const focusHeading = () => { const title = root.querySelector('h5, h4'); title.tabIndex = -1; title.focus(); };
        const summary = data => node('p', { class: 'form-text text-break' },
            `${data.pattern.from} – ${data.pattern.through} · ${t.every_weeks}: ${data.pattern.interval_weeks} · ` +
            data.pattern.weekdays.map(day => t.weekdays[day - 1]).join(', '));
        async function list(after = null, stack = []) {
            const ticket = ++epoch;
            const data = await request(endpoint + 'series-list/', 'POST', { after });
            if (!valid(ticket)) return;
            reviewed = null;
            root.replaceChildren(heading(), node('p', { class: 'form-text' }, t.recurring_intro), actions(
                ...(data.can_create ? [button(t.add_recurring, () => edit(), 'calendar2-plus', true)] : []),
                button(t.refresh_recurring, () => list(after, stack), 'arrow-clockwise'),
                button(t.back_availability, leave, 'arrow-left')));
            for (const item of data.items) root.append(node('article', { class: 'border rounded bg-white p-3 mb-3 text-break' },
                node('h5', { class: 'h6' }, `${item.from} – ${item.through}`),
                node('p', {}, `${t.window_count}: ${item.count} · ${t.series_status[item.status]}`),
                button(t.view_recurring, () => detail(item.id, after, stack), 'calendar2-week')));
            if (!data.items.length) root.append(node('p', {}, t.no_recurring));
            root.append(actions(...(stack.length ? [button(t.previous, () => list(stack.at(-1), stack.slice(0, -1)), 'arrow-left')] : []),
                ...(data.next_after ? [button(t.more, () => list(data.next_after, [...stack, after]))] : [])));
            focusHeading();
        }
        function edit() {
            ++epoch; reviewed = null;
            const value = draft || { from: '', through: '', start_time: '10:00', end_time: '12:00', weekdays: [], interval_weeks: 1,
                excluded_dates: [], slot_minutes: 30, message: '', timezone };
            const form = node('form', { class: 'border rounded bg-white p-3 mb-3' });
            form.append(node('p', { class: 'form-text' }, `${t.timezone}: ${value.timezone}`), node('p', { class: 'form-text' }, t.pattern_help));
            const fields = {};
            for (const [a, b] of [['from', 'through'], ['start_time', 'end_time']]) {
                const row = node('div', { class: 'row g-3' });
                for (const key of [a, b]) {
                    const column = node('div', { class: 'col-12 col-lg-6' });
                    fields[key] = field(column, key, t[key], key.endsWith('_time') ? 'time' : 'date', value[key]); row.append(column);
                }
                form.append(row);
            }
            const days = node('fieldset', { class: 'mb-3' }, node('legend', { class: 'form-label fs-6' }, t.choose_days));
            const weekdays = t.weekdays.map((label, i) => {
                const id = `availability-weekday-${++sequence}`;
                const input = node('input', { id, name: 'weekdays', type: 'checkbox', class: 'form-check-input', value: String(i + 1), checked: value.weekdays.includes(i + 1) });
                days.append(node('div', { class: 'form-check' }, input, node('label', { class: 'form-check-label', for: id }, label)));
                return input;
            });
            form.append(days);
            fields.interval_weeks = field(form, 'interval_weeks', t.every_weeks, 'number', value.interval_weeks);
            fields.interval_weeks.min = '1'; fields.interval_weeks.max = '12';
            fields.excluded_dates = field(form, 'excluded_dates', t.excluded_dates, 'textarea', value.excluded_dates.join('\n'));
            fields.excluded_dates.required = false; fields.excluded_dates.maxLength = 1100;
            form.append(node('p', { class: 'form-text' }, t.excluded_help));
            fields.slot_minutes = field(form, 'slot_minutes', t.minutes, 'number', value.slot_minutes);
            fields.slot_minutes.min = '5'; fields.slot_minutes.max = '120';
            fields.message = field(form, 'message', t.instructions, 'textarea', value.message);
            const capture = () => Object.fromEntries([...Object.entries(fields).map(([key, input]) => [key,
                ['interval_weeks', 'slot_minutes'].includes(key) ? Number(input.value) : key === 'excluded_dates'
                    ? input.value.split(/\s+/).filter(Boolean) : input.value]),
                ['weekdays', weekdays.filter(input => input.checked).map(input => Number(input.value))], ['timezone', value.timezone]]);
            form.addEventListener('input', () => { draft = capture(); });
            form.append(node('button', { type: 'submit', class: `btn ${submitClass}` }, t.review_pattern));
            form.addEventListener('submit', event => { event.preventDefault(); work(async () => {
                if (!form.reportValidity()) return;
                draft = capture(); const submitted = JSON.stringify(draft), ticket = ++epoch;
                const data = await request(endpoint + 'review-series/', 'POST', draft);
                if (!valid(ticket)) return;
                if (JSON.stringify(capture()) !== submitted) { status.textContent = t.draft_changed; status.focus(); return; }
                reviewed = data; confirmCreate();
            }, form); });
            root.replaceChildren(heading(), form, button(t.back_recurring, () => list(), 'arrow-left'));
            fields.from.focus();
        }
        function confirmCreate() {
            ++epoch;
            root.replaceChildren(heading(), node('h5', { class: 'h6' }, t.review_pattern), summary(reviewed),
                node('p', { style: 'white-space:pre-wrap;overflow-wrap:anywhere' }, reviewed.message),
                node('p', { class: 'form-text' }, `${t.minutes}: ${reviewed.slot_minutes}`), timeList(reviewed.windows),
                node('p', { class: 'form-text' }, t.confirm_help), actions(
                    button(t.confirm_offer, async () => {
                        const ticket = ++epoch, data = reviewed;
                        const result = await request(endpoint + 'create-series/', 'POST', { ...data.pattern, slot_minutes: data.slot_minutes,
                            message: data.message, series_id: data.series_id, window_ids: data.window_ids, revision: data.revision, review_token: data.review_token });
                        if (!valid(ticket)) return;
                        draft = null; await detail(result.id); status.textContent = t.recurring_saved; status.focus();
                    }, 'check2-circle', true), button(t.keep_editing, edit, 'arrow-left')));
            focusHeading();
        }
        async function detail(id, after = null, stack = []) {
            const ticket = ++epoch;
            const data = await request(endpoint + 'series-view/', 'POST', { series_id: id });
            if (!valid(ticket)) return;
            const series = data.series;
            root.replaceChildren(heading(), summary(series), node('p', { style: 'white-space:pre-wrap;overflow-wrap:anywhere' }, series.message),
                node('p', { class: 'form-text' }, t.close_help), actions(
                    button(t.refresh_recurring, () => detail(id, after, stack), 'arrow-clockwise'),
                    button(t.back_recurring, () => list(after, stack), 'arrow-left')));
            for (const window of data.windows) root.append(node('article', { class: 'border rounded bg-white p-3 mb-2 text-break' },
                timeList([window]), node('p', {}, window.status === 'open' && window.expired ? t.expired : t.status[window.status]),
                ...(window.close_reason ? [node('p', { style: 'white-space:pre-wrap;overflow-wrap:anywhere' }, window.close_reason)] : [])));
            if (data.remaining_ids.length) {
                const form = node('form', { class: 'border rounded bg-white p-3 mt-3' });
                const reason = field(form, 'message', t.reason, 'textarea', reasons.get(id) || '');
                reason.addEventListener('input', () => reasons.set(id, reason.value));
                form.append(node('button', { type: 'submit', class: `btn ${submitClass}` }, t.review_remaining));
                form.addEventListener('submit', event => { event.preventDefault(); work(async () => {
                    if (!form.reportValidity()) return;
                    const ticket = ++epoch, submitted = reason.value;
                    const review = await request(endpoint + 'review-series-close/', 'POST', { series_id: id, message: submitted });
                    if (!valid(ticket)) return;
                    if (reason.value !== submitted) { status.textContent = t.draft_changed; status.focus(); return; }
                    root.replaceChildren(heading(), node('h5', { class: 'h6' }, t.review_remaining), timeList(review.windows),
                        node('p', { style: 'white-space:pre-wrap;overflow-wrap:anywhere' }, review.reason),
                        node('p', { class: 'form-text' }, t.close_help), actions(button(t.confirm_remaining, async () => {
                            const closing = ++epoch;
                            await request(endpoint + 'close-series/', 'POST', { series_id: id, version: review.version, window_ids: review.window_ids,
                                message: review.reason, revision: review.revision, review_token: review.review_token });
                            if (!valid(closing)) return;
                            reasons.delete(id); await detail(id, after, stack); status.textContent = t.closed; status.focus();
                        }, 'check2-circle', true), button(t.keep_editing, () => detail(id, after, stack), 'arrow-left')));
                    focusHeading();
                }, form); }); root.append(form);
            } else root.append(node('p', {}, t.no_remaining));
            focusHeading();
        }
        return list();
    };
})();
