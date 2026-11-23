/* Website drafts and review proofs remain in page memory. */
(() => {
    'use strict';
    const root = document.getElementById('site-manager');
    if (!root) return;
    const labels = JSON.parse(document.getElementById('site-labels').textContent);
    const settings = JSON.parse(document.getElementById('site-config').textContent);
    const managerLink = query => { const url = new URL(settings.manager_url, location.origin);
        for (const [key, value] of Object.entries(query)) url.searchParams.set(key, value); return url.href; };
    const t = key => labels[key] || key;
    const node = (tag, attrs = {}, ...children) => {
        const el = document.createElement(tag);
        for (const [key, value] of Object.entries(attrs)) {
            if (key.startsWith('on')) el.addEventListener(key.slice(2), value);
            else if (value !== null && value !== false) el.setAttribute(key, value === true ? '' : value);
        }
        el.append(...children.filter(value => value !== null && value !== undefined));
        return el;
    };
    const icon = name => node('i', { class: `bi bi-${name}`, 'aria-hidden': 'true' });
    const status = document.getElementById('site-status');
    const reviewElement = document.getElementById('site-review');
    const reviewBody = document.getElementById('site-review-body');
    const drawer = bootstrap.Offcanvas.getOrCreateInstance(reviewElement);
    let drawerTrigger = null;
    reviewElement.addEventListener('hidden.bs.offcanvas', () => {
        const target = drawerTrigger?.isConnected ? drawerTrigger : root.querySelector('button');
        target?.focus();
    });
    let generation = 0, dirty = false, pending = false;
    const clone = value => JSON.parse(JSON.stringify(value));
    function message(text, kind = 'info') {
        status.replaceChildren(node('div', { class: `alert alert-${kind}`, role: kind === 'danger' ? 'alert' : 'status' }, text));
    }
    function clearReview() { reviewBody.replaceChildren(); drawer.hide(); }
    async function request(path, data = null) {
        const headers = { Accept: 'application/json' };
        if (data !== null) {
            headers['Content-Type'] = 'application/x-www-form-urlencoded';
            headers[settings.csrf_header] = document.querySelector('#site-csrf input')?.value || '';
        }
        const response = await fetch(path, { method: data === null ? 'GET' : 'POST', headers,
            body: data === null ? null : new URLSearchParams({ _workspace_json: JSON.stringify(data) }),
            credentials: 'same-origin', cache: 'no-store', redirect: 'error' });
        const result = await response.json();
        if (!response.ok) {
            if ([401, 403].includes(response.status)) {
                generation++; dirty = false; clearReview(); root.replaceChildren();
                window.initializeSiteDropdowns?.(root);
                message(t('access'), 'danger');
            }
            const details = Array.isArray(result.errors) ? result.errors.map(item => item.message).filter(Boolean).join(' ') : '';
            const error = new Error(details || result.error || t('failed')); error.status = response.status; throw error;
        }
        return result;
    }
    async function task(run, trigger = null) {
        if (pending) return;
        pending = true; if (trigger) trigger.disabled = true;
        if (locationPicker?.isConnected) locationPicker.disabled = true;
        try { await run(); }
        catch (error) { if (![401, 403].includes(error.status)) message(error.message || t('failed'), 'danger'); }
        finally { pending = false; if (trigger?.isConnected) trigger.disabled = false;
            if (locationPicker?.isConnected) locationPicker.disabled = false; }
    }
    function button(key, action, glyph = null, classes = 'btn btn-outline-primary') {
        return node('button', { type: 'button', class: classes, onclick: event => task(action, event.currentTarget) },
            glyph ? icon(glyph) : null, t(key));
    }
    function field(parent, key, control) {
        const id = `site-${key}-${crypto.randomUUID()}`; control.id = id;
        parent.append(node('div', { class: 'mb-3' }, node('label', { class: 'form-label', for: id }, t(key)), control));
        return control;
    }
    function inspect(title, ...content) {
        for (const toggle of document.querySelectorAll('[data-bs-toggle="dropdown"][aria-expanded="true"]')) {
            bootstrap.Dropdown.getInstance(toggle)?.hide();
        }
        if (!reviewElement.classList.contains('show')) drawerTrigger = document.activeElement;
        document.getElementById('site-review-title').textContent = title;
        reviewBody.replaceChildren(...content); drawer.show();
    }
    window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
    window.addEventListener('pagehide', () => { generation++; dirty = false; root.replaceChildren(); reviewBody.replaceChildren(); });

    async function pageDirectory(after = null, previous = []) {
        const ticket = ++generation;
        root.replaceChildren(node('p', { role: 'status' }, t('loading')));
        const data = await request('/sis/website/pages/' + (after === null ? '' : `?after=${encodeURIComponent(after)}`));
        if (ticket !== generation) return;
        const card = node('section', { class: 'site-card', 'aria-label': t('pages') });
        const create = node('a', { class: 'btn btn-primary', href: managerLink({ route: 'page_settings', action: 'create' }) },
            icon('plus-lg'), t('create_page'));
        card.append(create);
        if (!data.items.length) card.append(node('p', { class: 'site-empty' }, t('no_pages')));
        for (const page of data.items) {
            const row = node('div', { class: 'site-page-row' }, node('h2', { class: 'h5 mb-0' }, page.name));
            const id = encodeURIComponent(page.id);
            row.append(node('div', { class: 'site-page-actions' },
                node('a', { class: 'btn btn-light', href: `${settings.builder_url}?page=${id}` }, icon('palette'), t('design')),
                node('a', { class: 'btn btn-light', href: managerLink({ route: 'page_settings', action: 'edit', page: page.id }) }, icon('sliders'), t('page_settings')),
                button('remove_page', () => {
                    const form = node('form', { method: 'post', action: managerLink({ route: 'page_settings', action: 'destroy', page: page.id }) });
                    for (const input of document.querySelectorAll('#site-csrf input')) form.append(input.cloneNode(true));
                    form.append(node('button', { class: 'btn btn-danger', type: 'submit' }, t('remove_page')));
                    inspect(page.name, node('p', {}, t('remove_page_help')), form, button('back_pages', clearReview, 'arrow-left'));
                }, 'trash'),
                button('publication', () => openPage(String(page.id), () => pageDirectory(after, previous)), 'clipboard-check')));
            card.append(row);
        }
        const paging = node('div', { class: 'site-toolbar' });
        if (previous.length) paging.append(button('previous', () => pageDirectory(previous.at(-1), previous.slice(0, -1)), 'arrow-left'));
        if (data.next_after !== null) paging.append(button('next', () => pageDirectory(data.next_after, [...previous, after]), 'arrow-right'));
        root.replaceChildren(card, paging);
    }
    async function openPage(id, back, draft = null) {
        const ticket = ++generation;
        const data = await request(`/sis/website/pages/${encodeURIComponent(id)}/`);
        if (ticket !== generation) return;
        const form = node('form', {}), fields = node('fieldset', {});
        const actions = data.status === 'review' ? (data.can_publish ? ['approve', 'return'] : [])
            : data.status === 'published' ? (data.can_publish ? ['archive'] : []) : ['submit'];
        const action = field(fields, 'action', node('select', { class: 'form-select' },
            ...actions.map(value => node('option', { value }, t(value)))));
        if (draft && actions.includes(draft.action)) action.value = draft.action;
        const from = field(fields, 'from', node('input', { type: 'datetime-local', step: '1', class: 'form-control' }));
        const until = field(fields, 'until', node('input', { type: 'datetime-local', step: '1', class: 'form-control' }));
        from.value = draft?.from || ''; until.value = draft?.until || '';
        const help = node('p', { class: 'form-text' }, t('window_help')); fields.append(help);
        const note = field(fields, 'note', node('textarea', { class: 'form-control', rows: '4', maxlength: '1000' }));
        note.value = draft?.note || '';
        const update = () => {
            for (const input of [from, until]) { input.disabled = action.value !== 'approve'; input.parentElement.hidden = input.disabled; }
            help.hidden = action.value !== 'approve'; note.disabled = action.value !== 'return';
            note.required = !note.disabled; note.parentElement.hidden = note.disabled;
        };
        action.addEventListener('change', update); update();
        const submit = node('button', { type: 'submit', class: 'btn btn-primary' }, icon('clipboard-check'), t('request_review'));
        form.append(fields, submit);
        const info = node('dl', { class: 'mb-4' }, node('dt', {}, t('status')),
            node('dd', {}, t({ review: 'in_review', published: 'published_state' }[data.status] || data.status)));
        for (const variant of data.translations) info.append(node('dt', {}, `${variant.locale} · ${variant.title}`), node('dd', {}, variant.route));
        const feedback = data.review_note ? node('p', { class: 'alert alert-info' }, data.review_note) : null;
        const inspectDesign = node('a', { class: 'btn btn-outline-primary mb-3', target: '_blank', rel: 'noopener noreferrer',
            href: `${settings.builder_url}?page=${encodeURIComponent(id)}` }, icon('box-arrow-up-right'), t('inspect_design'),
            node('span', { class: 'visually-hidden' }, ` (${t('new_tab_hint')})`));
        inspect(data.name, node('p', {}, t('page_help')), inspectDesign, info, feedback,
            actions.length ? form : node('p', { role: 'status' }, t('in_review')),
            button('reload_page', () => openPage(id, back, { action: action.value, from: from.value, until: until.value, note: note.value }), 'arrow-clockwise'),
            button('back_pages', () => { clearReview(); return back(); }, 'arrow-left'));
        form.addEventListener('submit', event => {
            event.preventDefault(); void task(async () => {
                if (!form.reportValidity()) return;
                const iso = input => input.value ? `${input.value.length === 16 ? input.value + ':00' : input.value}Z` : null;
                const intent = { action: action.value, revision: data.revision,
                    window: { visible_from: action.value === 'approve' ? iso(from) : null,
                        visible_until: action.value === 'approve' ? iso(until) : null },
                    note: action.value === 'return' ? note.value : null };
                const working = { action: action.value, from: from.value, until: until.value, note: note.value };
                fields.disabled = true;
                let reviewed;
                try { reviewed = await request(`/sis/website/pages/${id}/review/`, intent); }
                finally { fields.disabled = false; }
                if (ticket !== generation || !form.isConnected) return;
                const confirmation = button('confirm_action', async () => {
                    await request(`/sis/website/pages/${id}/confirm/`, reviewed);
                    if (ticket !== generation) return;
                    message(t('published'), 'success'); await openPage(id, back);
                }, 'check2', 'btn btn-primary');
                inspect(data.name, node('p', {}, t('publish_help')), info,
                    node('p', {}, t(intent.action)),
                    intent.window.visible_from ? node('p', {}, `${t('from')}: ${intent.window.visible_from}`) : null,
                    intent.window.visible_until ? node('p', {}, `${t('until')}: ${intent.window.visible_until}`) : null,
                    intent.note ? node('p', {}, intent.note) : null, confirmation,
                    button('cancel_review', () => openPage(id, back, working), 'arrow-left'));
            }, submit);
        });
    }

    let slot = 'primary', saved = null, items = [], selected = null, undo = [], redo = [], sortables = [];
    let treeRoot, editor, preview, saveButton, publishButton, locationPicker, unsaved, historyControls;
    const visit = (list, run) => { for (const item of list) { run(item); visit(item.children, run); } };
    const find = id => { let result; visit(items, item => { if (item.id === id) result = item; }); return result; };
    function container(id, list = items, parent = null) {
        const index = list.findIndex(item => item.id === id);
        if (index >= 0) return { list, index, parent };
        for (const item of list) { const result = container(id, item.children, item); if (result) return result; }
        return null;
    }
    function modified(before) {
        undo.push(before); if (undo.length > 25) undo.shift(); redo = []; dirty = true;
        clearReview(); unsaved.textContent = t('dirty'); publishButton.disabled = true; updateHistory();
    }
    function updateHistory() {
        historyControls.replaceChildren(button('undo', () => {
            if (!undo.length) return; redo.push(clone(items)); items = undo.pop(); dirty = true; renderTree(); renderEditor();
        }, 'arrow-counterclockwise'), button('redo', () => {
            if (!redo.length) return; undo.push(clone(items)); items = redo.pop(); dirty = true; renderTree(); renderEditor();
        }, 'arrow-clockwise'));
        historyControls.children[0].disabled = !undo.length; historyControls.children[1].disabled = !redo.length;
        if (dirty) { publishButton.disabled = true; unsaved.textContent = t('dirty'); }
    }
    function change(run, focus = selected) {
        const before = clone(items); run();
        if (depth(items) > 3 || total(items) > 100 || items.some(value => value.type === 'divider')) {
            items = before; message(t('limit'), 'danger'); renderTree(); renderEditor(); return;
        }
        modified(before); renderTree(); renderEditor();
        if (focus) treeRoot.querySelector(`[data-item-id="${focus}"] > .site-item-row .site-item-label`)?.focus();
    }
    function item(type = 'link', page = null) {
        return { id: crypto.randomUUID(), type, label: type === 'page' ? page.name
            : t(type === 'divider' ? 'divider' : type === 'group' ? 'new_group' : 'new_link'),
            description: '', page_id: type === 'page' ? String(page.id) : null, url: type === 'link' ? '/' : null,
            enabled: true, new_tab: false, icon: '', children: [] };
    }
    function add(type, page = null) {
        let count = 0; visit(items, () => count++); if (count >= 100) { message(t('limit'), 'danger'); return; }
        change(() => { const added = item(type, page), parent = selected ? find(selected) : null;
            (parent && parent.type !== 'divider' ? parent.children : items).push(added); selected = added.id; });
    }
    function move(id, action) {
        const source = container(id); if (!source) return;
        change(() => {
            const { list, index, parent } = source;
            if (action === 'up' && index > 0) [list[index - 1], list[index]] = [list[index], list[index - 1]];
            if (action === 'down' && index < list.length - 1) [list[index + 1], list[index]] = [list[index], list[index + 1]];
            if (action === 'indent' && index > 0 && list[index - 1].type !== 'divider') list[index - 1].children.push(...list.splice(index, 1));
            if (action === 'outdent' && parent) {
                const outer = container(parent.id); outer.list.splice(outer.index + 1, 0, ...list.splice(index, 1));
            }
            if (action === 'duplicate') {
                const copy = clone(list[index]); visit([copy], value => { value.id = crypto.randomUUID(); });
                list.splice(index + 1, 0, copy); selected = copy.id;
            }
            if (action === 'remove') { list.splice(index, 1); selected = null; }

        }, id);
    }
    const depth = list => list.length ? 1 + Math.max(...list.map(value => depth(value.children))) : 0;
    const total = list => list.reduce((sum, value) => sum + 1 + total(value.children), 0);
    function renderTree() {
        for (const sortable of sortables) sortable.destroy(); sortables = [];
        const render = list => {
            const ol = node('ol', { class: 'site-tree', 'aria-label': t('structure') });
            for (const entry of list) {
                const edit = button('edit', () => { selected = entry.id; renderTree(); renderEditor(); }, null,
                    'btn btn-link site-item-label text-decoration-none');
                edit.replaceChildren(entry.label || t(entry.type)); edit.setAttribute('aria-label', `${t('edit')}: ${entry.label || t(entry.type)}`);
                const toggle = node('button', { type: 'button', class: 'btn btn-light dropdown-toggle',
                    'data-bs-toggle': 'dropdown', 'aria-expanded': 'false', 'aria-label': `${t('actions')}: ${entry.label}` }, icon('three-dots'));
                const actions = node('ul', { class: 'dropdown-menu' });
                for (const action of ['up', 'down', 'indent', 'outdent', 'duplicate', 'remove']) {
                    actions.append(node('li', {}, button(action, () => move(entry.id, action), null, 'dropdown-item')));
                }
                const grip = node('button', { type: 'button', class: 'btn btn-light site-drag',
                    'aria-label': `${t('drag')}: ${entry.label}`, title: t('structure_help') }, icon('grip-vertical'));
                const row = node('div', { class: 'site-item-row', 'aria-current': selected === entry.id ? 'true' : null },
                    grip, edit, node('span', { class: 'site-item-kind' }, t(entry.type)), node('div', { class: 'dropdown' }, toggle, actions));
                const li = node('li', { class: 'site-item', 'data-item-id': entry.id }, row);
                if (entry.type !== 'divider') li.append(render(entry.children));
                ol.append(li);
            }
            return ol;
        };
        treeRoot.replaceChildren(render(items));
        const before = clone(items);
        for (const list of treeRoot.querySelectorAll('.site-tree')) {
            sortables.push(new Sortable(list, { group: 'website-navigation', handle: '.site-drag',
                animation: matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 140,
                forceFallback: true, fallbackTolerance: 3, fallbackOnBody: true,
                swapThreshold: .65, ghostClass: 'site-ghost',
                onEnd: () => {
                    const map = new Map(); visit(items, value => map.set(value.id, value));
                    const read = list => [...list.children].filter(el => el.matches('.site-item')).map(el => {
                        const value = clone(map.get(el.dataset.itemId));
                        const child = el.querySelector(':scope > .site-tree'); value.children = child ? read(child) : [];
                        return value;
                    });
                    items = read(treeRoot.firstElementChild);
                    if (depth(items) > 3 || items.some(value => value.type === 'divider')) {
                        items = clone(before); message(t('limit'), 'danger');
                    } else modified(clone(before));
                    renderTree(); renderEditor();
                },
            }));
        }
        renderPreview(); updateHistory();
        window.initializeSiteDropdowns?.(treeRoot);
    }
    function renderEditor() {
        editor.replaceChildren(node('h2', { class: 'h5' }, t('settings')));
        const entry = selected ? find(selected) : null;
        if (!entry) { editor.append(node('p', { class: 'text-secondary' }, t('choose_item'))); return; }
        const update = (key, value) => {
            if (entry[key] === value) return;
            const before = clone(items); entry[key] = value; modified(before);
            const caption = treeRoot.querySelector(`[data-item-id="${entry.id}"] > .site-item-row .site-item-label`);
            if (caption) { caption.textContent = entry.label; caption.setAttribute('aria-label', `${t('edit')}: ${entry.label}`); }
            renderPreview();
        };
        if (entry.type !== 'divider') {
            for (const [key, maximum] of [['label', 100], ['description', 200]]) {
                const control = field(editor, key, node('input', { class: 'form-control', maxlength: String(maximum), value: entry[key] }));
                control.addEventListener('input', () => update(key, control.value));
            }
        }
        if (entry.type === 'link') {
            const url = field(editor, 'url', node('input', { class: 'form-control', maxlength: '2048', value: entry.url }));
            url.addEventListener('input', () => update('url', url.value));
        }
        if (entry.type === 'page') {
            const destination = node('p', { class: 'form-text', role: 'status' }, t('loading_destination'));
            const pageId = entry.page_id;
            editor.append(destination, button('choose_page', () => choosePage(entry.id), 'file-earmark'));
            void request(`/sis/website/pages/${encodeURIComponent(pageId)}/`).then(data => {
                if (destination.isConnected && find(entry.id)?.page_id === pageId) destination.textContent = data.name;
            }).catch(error => {
                if (!destination.isConnected) return;
                destination.textContent = t('unavailable_destination');
                if (![401, 403, 404].includes(error.status)) message(error.message || t('failed'), 'danger');
            });
        }
        if (entry.type !== 'divider') {
            const glyph = field(editor, 'icon', node('select', { class: 'form-select' }, node('option', { value: '' }, t('no_icon')),
            ...['house', 'book', 'calendar3', 'mortarboard', 'person', 'people', 'envelope', 'telephone', 'geo-alt', 'info-circle', 'link-45deg', 'file-earmark-text'].map(name => node('option', { value: name }, name))));
            glyph.value = entry.icon; glyph.addEventListener('change', () => update('icon', glyph.value));
        }
        for (const key of (['page', 'link'].includes(entry.type) ? ['enabled', 'new_tab'] : ['enabled'])) {
            const id = `site-${key}`; const input = node('input', { id, type: 'checkbox', class: 'form-check-input', checked: entry[key] });
            input.addEventListener('change', () => update(key, input.checked));
            editor.append(node('div', { class: 'form-check' }, input, node('label', { for: id, class: 'form-check-label' }, t(key))));
        }
    }
    function renderPreview() {
        const render = list => node('ul', { class: 'navbar-nav flex-row flex-wrap gap-2' }, ...list.filter(value => value.enabled).map(value => {
            if (value.type === 'divider') return node('li', { class: 'dropdown-divider', role: 'separator' });
            const caption = node('span', {}, value.icon ? icon(value.icon) : null, value.label);
            const li = node('li', { class: value.children.length ? 'nav-item dropdown' : 'nav-item' });
            if (value.children.length) {
                li.append(node('button', { class: 'btn btn-light dropdown-toggle', type: 'button', 'data-bs-toggle': 'dropdown',
                    'data-bs-auto-close': 'outside', 'aria-expanded': 'false' }, caption));
                const children = render(value.children); children.className = 'dropdown-menu p-2'; li.append(children);
            } else li.append(node('button', { class: 'btn btn-light', type: 'button' }, caption));
            return li;
        }));
        preview.replaceChildren(node('h2', { class: 'h5' }, t('preview')), node('p', { class: 'form-text' }, t('preview_help')), render(items));
        window.initializeSiteDropdowns?.(preview);
    }
    async function choosePage(replaceId = null) {
        const ticket = generation, choices = node('div'), search = node('input', { class: 'form-control mb-3', type: 'search', 'aria-label': t('search_pages') });
        const pages = [], more = button('more_pages', () => load(next), 'plus-lg'); let next = null;
        const show = () => {
            const text = search.value.toLocaleLowerCase();
            choices.replaceChildren(...pages.filter(page => page.name.toLocaleLowerCase().includes(text)).map(page =>
                node('div', { class: 'site-page-row' }, node('span', {}, page.name), button('add_page', () => {
                    clearReview();
                    if (replaceId && find(replaceId)) change(() => { find(replaceId).page_id = String(page.id); });
                    else add('page', page);
                }, 'plus-lg'))));
        };
        const load = async after => {
            const data = await request('/sis/website/pages/' + (after ? `?after=${encodeURIComponent(after)}` : ''));
            if (ticket !== generation) return;
            pages.push(...data.items); next = data.next_after; show(); more.hidden = !next || pages.length >= 100;
        };
        search.addEventListener('input', show);
        inspect(t('choose_page'), search, choices, more, button('back', clearReview, 'arrow-left')); await load(null);
    }
    async function loadMenu(nextSlot = slot) {
        const ticket = ++generation;
        const data = await request(`/sis/website/menus/${nextSlot}/`);
        if (ticket !== generation) return;
        slot = nextSlot; saved = data; items = clone(data.items); dirty = false; selected = null; undo = []; redo = [];
        buildMenu();
    }
    function buildMenu() {
        for (const sortable of sortables) sortable.destroy(); sortables = [];
        locationPicker = node('select', { class: 'form-select' }, ...['primary', 'secondary', 'footer'].map(value => node('option', { value }, t(value))));
        locationPicker.value = slot;
        locationPicker.addEventListener('change', () => { const desired = locationPicker.value;
            if (!dirty) { void task(() => loadMenu(desired)); return; }
            locationPicker.value = slot;
            status.replaceChildren(node('div', { class: 'alert alert-warning' }, node('p', {}, t('unsaved')),
                button('stay', () => status.replaceChildren()), button('discard', () => { dirty = false; return loadMenu(desired); })));
        });
        const locationBox = node('div', { class: 'site-card' }); field(locationBox, 'menu', locationPicker);
        unsaved = node('span', { class: 'text-secondary', role: 'status' });
        saveButton = button('save', async () => {
            const snapshot = clone(items), originalGeneration = generation;
            const result = await request(`/sis/website/menus/${slot}/`, { items: snapshot, revision: saved.revision });
            if (originalGeneration !== generation) return;
            saved = result; dirty = JSON.stringify(snapshot) !== JSON.stringify(items);
            message(t('saved'), 'success'); unsaved.textContent = dirty ? t('dirty') : '';
            publishButton.disabled = dirty || !saved.can_publish;
        }, 'save', 'btn btn-primary');
        publishButton = button('review', async () => {
            if (dirty) { message(t('not_saved'), 'warning'); return; }
            const originalGeneration = generation;
            const reviewed = await request(`/sis/website/menus/${slot}/review/`, { revision: saved.revision });
            if (originalGeneration !== generation || dirty) return;
            const describe = list => node('ol', {}, ...list.map(entry => node('li', {}, `${entry.label || t(entry.type)} · ${entry.url || t(entry.type)}${entry.new_tab ? ' · ' + t('new_tab_hint') : ''}${entry.enabled ? '' : ' · ' + t('draft')}`,
                entry.children.length ? describe(entry.children) : null)));
            inspect(t('review'), node('p', {}, t('publish_help')), describe(reviewed.items),
                button('confirm', async () => {
                    root.inert = true;
                    try { await request(`/sis/website/menus/${slot}/publish/`, {
                        operation_id: reviewed.operation_id, revision: reviewed.revision, review_token: reviewed.review_token,
                    }); }
                    finally { root.inert = false; }
                    if (originalGeneration !== generation) return;
                    clearReview(); message(t('published'), 'success'); await loadMenu(slot);
                }, 'check2', 'btn btn-primary'), button('back', clearReview, 'arrow-left'));
        }, 'clipboard-check');
        publishButton.disabled = !saved.can_publish;
        historyControls = node('div', { class: 'site-toolbar' });
        const addActions = node('div', { class: 'site-toolbar' }, button('page', choosePage, 'file-earmark'),
            button('link', () => add('link'), 'link-45deg'), button('group', () => add('group'), 'menu-down'),
            button('divider', () => add('divider'), 'dash-lg'), button('add_root', () => { selected = null; renderTree(); renderEditor(); }, 'list'));
        treeRoot = node('div'); editor = node('section', { class: 'site-card site-settings' }); preview = node('section', { class: 'site-card site-preview mt-4' });
        const copySlot = node('select', { class: 'form-select', 'aria-label': t('copy') }, ...['primary', 'secondary', 'footer'].filter(value => value !== slot).map(value => node('option', { value }, t(value))));
        const copy = button('copy_apply', async () => {
            const source = await request(`/sis/website/menus/${copySlot.value}/`);
            change(() => { items = clone(source.items); selected = null; });
        }, 'copy');
        const extras = node('details', { class: 'mt-4' }, node('summary', {}, t('copy')), node('p', { class: 'form-text' }, t('copy_help')), copySlot, copy);
        root.replaceChildren(locationBox, node('div', { class: 'site-toolbar' }, saveButton, publishButton, unsaved,
            button('reload', () => {
                if (!dirty) return loadMenu(slot);
                status.replaceChildren(node('div', { class: 'alert alert-warning' }, node('p', {}, t('unsaved')),
                    button('stay', () => status.replaceChildren()), button('discard', () => { dirty = false; return loadMenu(slot); })));
            }, 'arrow-clockwise')),
        node('div', { class: 'site-builder' }, node('section', { class: 'site-card' }, node('h2', { class: 'h5' }, t('structure')),
            node('p', { class: 'form-text' }, t('structure_help')), addActions, historyControls, treeRoot, extras), editor), preview);
        renderTree(); renderEditor();
    }
    if (root.dataset.mode !== 'settings') void task(() => root.dataset.mode === 'menus' ? loadMenu() : pageDirectory());
})();
