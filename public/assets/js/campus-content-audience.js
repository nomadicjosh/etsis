/* Bounded recipient-definition labels only; no member lists or private browser storage. */
window.createCampusContentAudience = function (ui) {
    'use strict';
    const { node, request, task, labels } = ui;
    let sequence = 0;
    const summary = post => {
        if (['roles', 'programs'].includes(post.audience)) {
            const selected = post[post.audience === 'roles' ? 'audience_roles' : 'audience_programs'];
            return selected?.length ? labels[post.audience] + ': ' + selected.map(target => target.name).join(', ')
                : labels[post.audience === 'roles' ? 'unknown' : 'program_unknown'];
        }
        return labels[post.audience === 'public' ? 'public' : 'campus'];
    };
    function mount(form, original = [], originalPrograms = []) {
        const prefix = 'campus-audience-' + ++sequence;
        const audience = form.elements.namedItem('audience'), kind = form.elements.namedItem('kind');
        const options = ['roles', 'programs'].map(mode => node('option', { value: mode }, labels[mode]));
        audience.append(...options);
        if (['roles', 'programs'].includes(form.dataset.audienceMode)) audience.value = form.dataset.audienceMode;
        const choices = {
            roles: new Map(original.map(target => [target.id, { id: target.id, name: target.name }])),
            programs: new Map(originalPrograms.map(target => [target.id, { id: target.id, name: target.name }])),
        };
        let mode = audience.value === 'programs' ? 'programs' : 'roles';
        const programLabels = Object.fromEntries(['help', 'search', 'find', 'available', 'selected', 'remove', 'next',
            'previous', 'loading', 'failed', 'empty', 'none', 'limit'].map(key => [key, labels['program_' + key]]));
        programLabels.roles = labels.programs;
        let wording = mode === 'programs' ? programLabels : labels;
        let selected = choices[mode];
        const panel = node('fieldset', { class: 'record-section campus-role-audience', hidden: true });
        const legend = node('legend', { class: 'h6' }), help = node('p', { class: 'form-text' });
        panel.append(legend, help);
        const selectedHeading = node('h3', { class: 'h6', tabindex: '-1' });
        const selections = node('ul', { class: 'list-group mb-3' });
        const search = node('input', { id: prefix + '-search', type: 'search', maxlength: 80, class: 'form-control' });
        const searchLabel = node('label', { class: 'form-label', for: search.id });
        const status = node('p', { class: 'form-text', role: 'status', tabindex: '-1' });
        const available = node('div', { class: 'list-group mb-3', role: 'group' });
        const control = (label, callback) => node('button', { type: 'button', class: 'btn btn-outline-primary',
            onclick: async event => {
                await task(callback, event.currentTarget);
                if (form.isConnected) syncButtons();
            } }, label);
        let items = [], next = null, cursor = null, history = [], query = '', generation = 0, loaded = false, busy = false;
        const active = () => form.isConnected && ['roles', 'programs'].includes(audience.value) && kind.value === 'news';
        const find = control('', () => { history = []; query = search.value; return load(null); });
        const previous = control('', () => load(history.pop() ?? null));
        const following = control('', () => {
            if (!next) return;
            history.push(cursor);
            if (history.length > 5) history.shift();
            return load(next);
        });
        function syncButtons() {
            find.disabled = busy;
            previous.disabled = busy || !history.length;
            following.disabled = busy || !next;
        }
        function changed() {
            if (selected.size && status.textContent === wording.limit) status.textContent = '';
            form.dispatchEvent(new Event('change', { bubbles: true }));
        }
        function redraw(focus = null) {
            selections.replaceChildren();
            if (!selected.size) selections.append(node('li', { class: 'list-group-item' }, wording.none));
            for (const target of selected.values()) {
                const caption = wording.remove.replace(mode === 'programs' ? '{program}' : '{role}', target.name);
                const remove = control(caption, () => {
                    selected.delete(target.id);
                    redraw();
                    changed();
                    selectedHeading.focus({ preventScroll: true });
                });
                remove.classList.add('btn-sm', 'campus-role-remove');
                remove.replaceChildren(node('i', { class: 'bi bi-x-lg', 'aria-hidden': 'true' }));
                remove.setAttribute('aria-label', caption);
                remove.title = caption;
                selections.append(node('li', { class: 'list-group-item d-flex flex-wrap align-items-center gap-2' },
                    node('span', { class: 'text-break flex-grow-1' }, target.name), remove));
            }
            available.replaceChildren();
            const attribute = mode === 'programs' ? 'data-audience-program' : 'data-audience-role';
            for (const target of items) {
                const choose = control(target.name, () => {
                    if (selected.has(target.id)) selected.delete(target.id);
                    else if (selected.size < 10) selected.set(target.id, { id: target.id, name: target.name });
                    redraw(target.id);
                    changed();
                });
                choose.className = 'list-group-item list-group-item-action text-start text-break';
                choose.setAttribute(attribute, target.id);
                choose.setAttribute('aria-pressed', String(selected.has(target.id)));
                choose.classList.toggle('active', selected.has(target.id));
                choose.disabled = !selected.has(target.id) && selected.size >= 10;
                available.append(choose);
            }
            syncButtons();
            if (focus) available.querySelector('[' + attribute + '="' + CSS.escape(focus) + '"]')?.focus();
        }
        async function load(after) {
            if (busy || !active()) return;
            const attempt = ++generation, source = mode;
            status.textContent = wording.loading;
            busy = true;
            syncButtons();
            try {
                const parameters = new URLSearchParams({ search: query });
                if (after) parameters.set('after', after);
                const result = await request('/sis/portal/audience-' + source + '/?' + parameters);
                if (!active() || attempt !== generation || source !== mode) return;
                items = result.items.slice(0, 20);
                next = result.next_after && result.next_after !== after ? result.next_after : null;
                cursor = after;
                loaded = true;
                status.textContent = items.length ? '' : wording.empty;
                redraw();
            } catch {
                if (!active() || attempt !== generation || source !== mode) return;
                status.textContent = wording.failed;
                next = null;
                redraw();
            } finally {
                busy = false;
                if (active() && attempt !== generation && !loaded) void load(null);
                else if (form.isConnected) syncButtons();
            }
        }
        function update() {
            options.forEach(option => { option.disabled = kind.value !== 'news'; });
            if (kind.value !== 'news' && ['roles', 'programs'].includes(audience.value)) audience.value = '';
            const changedMode = active() && mode !== audience.value;
            if (changedMode) {
                mode = audience.value;
                selected = choices[mode];
                wording = mode === 'programs' ? programLabels : labels;
                ++generation;
                loaded = false; items = []; next = null; cursor = null; history = []; query = ''; search.value = '';
                status.textContent = '';
            }
            legend.textContent = wording.roles;
            help.textContent = wording.help;
            selectedHeading.textContent = wording.selected;
            selections.setAttribute('aria-label', wording.selected);
            searchLabel.textContent = wording.search;
            available.setAttribute('aria-label', wording.available);
            find.textContent = wording.find; previous.textContent = wording.previous; following.textContent = wording.next;
            panel.hidden = !['roles', 'programs'].includes(audience.value);
            if (!panel.hidden && !loaded && !busy) void load(null);
            if (panel.hidden) {
                ++generation;
                loaded = false;
                items = []; next = null; cursor = null; history = [];
            }
            redraw();
        }
        search.addEventListener('keydown', event => {
            if (event.key !== 'Enter') return;
            event.preventDefault();
            if (!busy) find.click();
        });
        panel.append(selectedHeading, selections, searchLabel,
            node('div', { class: 'd-flex flex-wrap align-items-end gap-2 mb-3' }, search, find),
            status, available, node('div', { class: 'd-flex flex-wrap gap-2' }, previous, following));
        audience.parentElement.append(panel);
        kind.addEventListener('change', update);
        audience.addEventListener('change', update);
        update();
        function read(source, required) {
            const ids = audience.value === source ? [...choices[source].keys()].sort() : [];
            if (required && audience.value === source && (!ids.length || ids.length > 10)) {
                status.textContent = wording.limit;
                status.focus();
                throw new Error(wording.limit);
            }
            return ids;
        }
        return { read: (required = false) => read('roles', required),
            readPrograms: (required = false) => read('programs', required) };
    }
    return { mount, summary };
};
