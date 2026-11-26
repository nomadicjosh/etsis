/* Current office routing. No access grants, fulfillment decisions or browser persistence. */
'use strict';
window.createTranscriptWorklist = ({ node, request, labels, changed = () => {} }) => {
    const text = key => labels[key];
    const icon = name => node('i', { class: `bi bi-${name}`, 'aria-hidden': 'true' });
    let scope = 'all', status = 'active';
    function filters(host, reload) {
        const field = (key, values, value, change) => {
            const select = node('select', { id: `transcript-work-${key}`, class: 'form-select' },
                values.map(code => node('option', { value: code }, text(code))));
            select.value = value;
            select.addEventListener('change', () => { change(select.value); reload(); });
            host.append(node('div', {}, node('label', { for: select.id, class: 'form-label' }, text(key)), select));
        };
        field('scope', ['all', 'mine', 'unassigned'], scope, value => { scope = value; });
        field('status', ['active', 'pending', 'ready', 'finished', 'all'], status, value => { status = value; });
    }
    function page(search, after) {
        return request(`/sis/transcript-worklist/?${new URLSearchParams({ scope, status, search, after: after || '' })}`);
    }
    async function mount(host, id, onDenied = () => {}) {
        const base = `/sis/transcript-requests/${id}/assignment/`;
        const statusLine = node('p', { class: 'form-text', role: 'status', tabindex: '-1' });
        let current = null, draft = null, turn = 0;
        const active = token => host.isConnected && token === turn;
        const button = (key, glyph, run, style = 'btn-outline-secondary') => node('button', {
            type: 'button', class: `btn ${style} d-inline-flex align-items-center gap-2`, onclick: () => void run(),
        }, icon(glyph), text(key));
        function failure(error, token) {
            if (!active(token)) return;
            if ([401, 403].includes(error.status)) { turn++; host.replaceChildren(); onDenied(text('denied')); return; }
            statusLine.setAttribute('role', 'alert');
            statusLine.textContent = text(error.status === 409 ? 'changed' : 'failed'); statusLine.focus();
        }
        async function load() {
            if (!host.isConnected) return;
            const token = ++turn; statusLine.textContent = text('loading');
            const controls = [...host.querySelectorAll('button,input')]; controls.forEach(control => { control.disabled = true; });
            try {
                const data = await request(base);
                if (!active(token)) return;
                if (typeof data.can_assign !== 'boolean') throw new Error('Invalid assignment context.');
                current = data;
                draft ??= { id: data.assignee_id, person: data.assignee_person_id, name: data.assignee_name };
                render();
            } catch (error) { failure(error, token); if (active(token) && !host.querySelector('button')) host.append(button('reload', 'arrow-clockwise', load)); }
            finally { controls.forEach(control => { if (control.isConnected) control.disabled = false; }); }
        }
        function render() {
            const token = ++turn;
            const heading = node('h3', { class: 'h5' }, text('title'));
            const selected = node('p', { class: 'fw-semibold text-break', role: 'status' }, draft.name);
            const refresh = button('reload', 'arrow-clockwise', load);
            const summary = node('p', {}, `${text('current')}: ${current.assignee_name}`);
            host.replaceChildren(heading, node('p', { class: 'form-text' }, text('help')), summary, statusLine);
            statusLine.textContent = '';
            if (!current.can_assign) { host.append(node('p', { class: 'form-text' }, text('closed')), refresh); return; }
            const choose = (id, person, name) => { draft = { id, person, name }; selected.textContent = name; };
            const search = node('input', { id: 'transcript-assignee-search', class: 'form-control', type: 'search', maxlength: '80' });
            const results = node('div'); let cursor = null, previous = [], acceptedSearch = '', searchTurn = 0;
            const find = async (after = null, stack = []) => {
                if (!active(token)) return;
                const lookup = ++searchTurn, query = search.value.trim();
                if (after && query !== acceptedSearch) { after = null; stack = []; }
                results.replaceChildren(node('p', { role: 'status' }, text('searching')));
                try {
                    const data = await request(`/sis/transcript-worklist/assignees/?${new URLSearchParams({ search: query, after: after || '' })}`);
                    if (!active(token) || lookup !== searchTurn || query !== search.value.trim()) return;
                    cursor = after; previous = stack; acceptedSearch = query;
                    results.replaceChildren(node('p', { role: 'status' }, text(data.search_required ? 'search_required' : data.items.length ? 'choose' : 'empty')),
                        ...data.items.map(row => buttonCandidate(row)));
                    if (stack.length) results.append(button('previous', 'arrow-left', () => find(stack.at(-1), stack.slice(0, -1))));
                    if (data.next_after) results.append(button('more', 'arrow-right', () => find(data.next_after, [...stack, after])));
                } catch (error) { failure(error, token); }
            };
            const buttonCandidate = row => {
                const name = `${row.given_name} ${row.family_name}`;
                return node('button', { type: 'button', class: 'btn btn-outline-primary d-block text-start text-break mb-2',
                    onclick: () => { choose(row.id, row.person_id, name); selected.focus(); } }, name, ` · ${row.institutional_id}`);
            };
            search.addEventListener('input', () => { searchTurn++; results.replaceChildren(); });
            const searchForm = node('form', {}, node('label', { for: search.id, class: 'form-label' }, text('search')),
                node('div', { class: 'd-flex flex-wrap align-items-start gap-2 mb-3' }, search,
                    node('button', { type: 'submit', class: 'btn btn-outline-primary d-inline-flex align-items-center gap-2' }, icon('search'), text('find'))));
            searchForm.addEventListener('submit', event => { event.preventDefault(); void find(); });
            const save = button('save', 'check2', async () => {
                if (!active(token)) return;
                searchTurn++;
                const controls = [...host.querySelectorAll('button,input')]; controls.forEach(control => { control.disabled = true; });
                statusLine.setAttribute('role', 'status'); statusLine.textContent = text('saving');
                try {
                    const data = await request(base, 'POST', { version: current.version, request_version: current.request_version,
                        assignee_id: draft.id, assignee_person_id: draft.person });
                    if (!active(token)) return;
                    current = data; draft = { id: data.assignee_id, person: data.assignee_person_id, name: data.assignee_name };
                    render(); statusLine.textContent = text('saved'); statusLine.focus(); void changed();
                } catch (error) { failure(error, token); }
                finally { if (host.isConnected) controls.forEach(control => { control.disabled = false; }); }
            }, 'btn-primary');
            selected.tabIndex = -1;
            host.append(node('fieldset', { class: 'border-0 p-0 m-0' }, node('legend', { class: 'h6' }, text('selected')), selected,
                node('div', { class: 'd-flex flex-wrap gap-2 mb-3' },
                    button('claim', 'person-check', () => choose(current.actor_id, current.actor_person_id, text('you'))),
                    button('clear', 'person-dash', () => choose(null, null, text('unassigned')))), searchForm, results),
                node('div', { class: 'd-flex flex-wrap gap-2' }, save, refresh));
        }
        host.append(statusLine); await load();
    }
    return { filters, page, mount };
};
