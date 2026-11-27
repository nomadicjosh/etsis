/* Role names only: bounded current-role selection, with no member lists or browser storage. */
window.createCampusContentAudience = function (ui) {
    'use strict';
    const { node, request, task, labels } = ui;
    let sequence = 0;
    const summary = post => post.audience === 'roles'
        ? (post.audience_roles?.length ? labels.roles + ': ' + post.audience_roles.map(role => role.name).join(', ') : labels.unknown)
        : labels[post.audience === 'public' ? 'public' : 'campus'];
    function mount(form, original = []) {
        const prefix = 'campus-audience-' + ++sequence;
        const audience = form.elements.namedItem('audience'), kind = form.elements.namedItem('kind');
        const option = node('option', { value: 'roles' }, labels.roles);
        audience.append(option);
        if (form.dataset.audienceMode === 'roles') audience.value = 'roles';
        const selected = new Map(original.map(role => [role.id, { id: role.id, name: role.name }]));
        const panel = node('fieldset', { class: 'record-section campus-role-audience', hidden: true });
        panel.append(node('legend', { class: 'h6' }, labels.roles), node('p', { class: 'form-text' }, labels.help));
        const selectedHeading = node('h3', { class: 'h6', tabindex: '-1' }, labels.selected);
        const selections = node('ul', { class: 'list-group mb-3', 'aria-label': labels.selected });
        const search = node('input', { id: prefix + '-search', type: 'search', maxlength: 80, class: 'form-control' });
        const searchLabel = node('label', { class: 'form-label', for: search.id }, labels.search);
        const status = node('p', { class: 'form-text', role: 'status', tabindex: '-1' });
        const available = node('div', { class: 'list-group mb-3', 'aria-label': labels.available, role: 'group' });
        const control = (label, callback) => node('button', { type: 'button', class: 'btn btn-outline-primary',
            onclick: async event => {
                await task(callback, event.currentTarget);
                if (form.isConnected) syncButtons();
            } }, label);
        let items = [], next = null, cursor = null, history = [], query = '', generation = 0, loaded = false, busy = false;
        const active = () => form.isConnected && audience.value === 'roles' && kind.value === 'news';
        const find = control(labels.find, () => { history = []; query = search.value; return load(null); });
        const previous = control(labels.previous, () => load(history.pop() ?? null));
        const following = control(labels.next, () => {
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
            if (selected.size && status.textContent === labels.limit) status.textContent = '';
            form.dispatchEvent(new Event('change', { bubbles: true }));
        }
        function redraw(focus = null) {
            selections.replaceChildren();
            if (!selected.size) selections.append(node('li', { class: 'list-group-item' }, labels.none));
            for (const role of selected.values()) {
                const remove = control(labels.remove.replace('{role}', role.name), () => {
                    selected.delete(role.id);
                    redraw();
                    changed();
                    selectedHeading.focus({ preventScroll: true });
                });
                remove.classList.add('btn-sm', 'campus-role-remove');
                const caption = labels.remove.replace('{role}', role.name);
                remove.replaceChildren(node('i', { class: 'bi bi-x-lg', 'aria-hidden': 'true' }));
                remove.setAttribute('aria-label', caption);
                remove.title = caption;
                selections.append(node('li', { class: 'list-group-item d-flex flex-wrap align-items-center gap-2' },
                    node('span', { class: 'text-break flex-grow-1' }, role.name), remove));
            }
            available.replaceChildren();
            for (const role of items) {
                const choose = control(role.name, () => {
                    if (selected.has(role.id)) selected.delete(role.id);
                    else if (selected.size < 10) selected.set(role.id, { id: role.id, name: role.name });
                    redraw(role.id);
                    changed();
                });
                choose.className = 'list-group-item list-group-item-action text-start text-break';
                choose.dataset.audienceRole = role.id;
                choose.setAttribute('aria-pressed', String(selected.has(role.id)));
                choose.classList.toggle('active', selected.has(role.id));
                choose.disabled = !selected.has(role.id) && selected.size >= 10;
                available.append(choose);
            }
            syncButtons();
            if (focus) available.querySelector('[data-audience-role="' + CSS.escape(focus) + '"]')?.focus();
        }
        async function load(after) {
            if (busy || !active()) return;
            const attempt = ++generation;
            status.textContent = labels.loading;
            busy = true;
            syncButtons();
            try {
                const parameters = new URLSearchParams({ search: query });
                if (after) parameters.set('after', after);
                const result = await request('/sis/portal/audience-roles/?' + parameters);
                if (!active() || attempt !== generation) return;
                items = result.items.slice(0, 20);
                next = result.next_after && result.next_after !== after ? result.next_after : null;
                cursor = after;
                loaded = true;
                status.textContent = items.length ? '' : labels.empty;
                redraw();
            } catch {
                if (!active() || attempt !== generation) return;
                status.textContent = labels.failed;
                next = null;
                redraw();
            } finally {
                busy = false;
                if (active() && attempt !== generation && !loaded) void load(null);
                else if (form.isConnected) syncButtons();
            }
        }
        function update() {
            option.disabled = kind.value !== 'news';
            if (kind.value !== 'news' && audience.value === 'roles') audience.value = '';
            panel.hidden = audience.value !== 'roles';
            if (!panel.hidden && !loaded && !busy) void load(null);
            if (panel.hidden) {
                ++generation;
                loaded = false;
                items = []; next = null; cursor = null; history = [];
                redraw();
            }
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
        redraw();
        update();
        return {
            read(required = false) {
                const ids = audience.value === 'roles' ? [...selected.keys()].sort() : [];
                if (required && audience.value === 'roles' && (!ids.length || ids.length > 10)) {
                    status.textContent = labels.limit;
                    status.focus();
                    throw new Error(labels.limit);
                }
                return ids;
            },
        };
    }
    return { mount, summary };
};
