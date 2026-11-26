/* Website form drafts are private editor memory; saving updates the selected page block. */
(() => {
    'use strict';
    const config = JSON.parse(document.getElementById('website-form-designer-config').textContent);
    const labels = config.labels;
    const types = ['text', 'textarea', 'email', 'tel', 'url', 'number', 'date', 'checkbox', 'select', 'radio', 'multi_select'];
    const clone = value => JSON.parse(JSON.stringify(value));
    const node = (tag, attrs = {}, ...children) => {
        const element = document.createElement(tag);
        for (const [key, value] of Object.entries(attrs)) {
            if (key.startsWith('on')) element.addEventListener(key.slice(2), value);
            else if (key === 'checked') element.checked = !!value;
            else if (value !== false && value != null) element.setAttribute(key, value === true ? '' : value);
        }
        element.append(...children.filter(value => value != null)); return element;
    };
    let dialog, draft, original, activeTrait, generation = 0, sortables = [], opener;
    const dirty = () => draft && JSON.stringify(draft) !== original;
    const changed = () => { generation++; };
    function cleanup() { sortables.forEach(sortable => sortable.destroy()); sortables = []; }
    function close() { if (dirty() && !confirm(labels.discard)) return; generation++; cleanup(); dialog.close(); draft = null; activeTrait = null; opener?.focus(); }
    function button(text, action, glyph = null) {
        return node('button', { type: 'button', class: 'btn btn-outline-secondary', onclick: action },
            glyph ? node('i', { class: `bi bi-${glyph}`, 'aria-hidden': 'true' }) : null, ` ${text}`);
    }
    function setting(parent, key, value, update, options = {}) {
        const id = `wf-setting-${crypto.randomUUID()}`;
        const control = node(options.lines ? 'textarea' : 'input', { class: 'form-control', id,
            ...(options.lines ? { rows: options.lines } : { type: options.type || 'text' }),
            ...(options.maxlength ? { maxlength: options.maxlength } : {}),
            oninput: event => { update(event.target.value); changed(); } });
        control.value = value ?? '';
        parent.append(node('div', { class: 'wf-setting' }, node('label', { for: id }, labels[key]), control));
        return control;
    }
    function conditionSettings(parent, field, index) {
        const select = node('select', { class: 'form-control', id: `wf-condition-${index}`, onchange: event => {
            if (!event.target.value) field.show_when = null;
            else {
                const prior = draft.fields.find(item => item.name === event.target.value);
                field.show_when = { field: prior.name, value: prior.type === 'checkbox' ? true : prior.options?.[0]?.value || '' };
            }
            changed(); render();
        } }, node('option', { value: '' }, labels.always));
        draft.fields.slice(0, index).filter(item => item.type !== 'multi_select').forEach(prior => select.append(node('option', { value: prior.name }, prior.label || prior.name)));
        select.value = field.show_when?.field || '';
        parent.append(node('div', { class: 'wf-setting' }, node('label', { for: select.id }, labels.condition), select));
        if (!field.show_when) return;
        const prior = draft.fields.find(item => item.name === field.show_when.field);
        if (prior?.type === 'checkbox') {
            const choice = node('select', { class: 'form-control', 'aria-label': labels.equals, onchange: event => { field.show_when.value = event.target.value === 'true'; changed(); } },
                node('option', { value: 'true' }, labels.yes), node('option', { value: 'false' }, labels.no));
            choice.value = String(field.show_when.value); parent.append(choice);
        } else setting(parent, 'equals', field.show_when.value, value => { field.show_when.value = value; }, { maxlength: 120 });
    }
    function valid() {
        if (!/^[a-z][a-z0-9_]{0,39}$/.test(draft.key) || !draft.title?.trim() || !draft.submit_label?.trim() || !draft.success_message?.trim()
            || !draft.fields.length || draft.fields.length > 40) return false;
        const names = new Set();
        for (const field of draft.fields) {
            if (!/^[a-z][a-z0-9_]{0,39}$/.test(field.name) || names.has(field.name) || !field.label?.trim()) return false;
            if (field.show_when && (!names.has(field.show_when.field) || field.show_when.value === '')) return false;
            if (['select', 'radio', 'multi_select'].includes(field.type) && (!field.options?.length || field.options.length > 30
                || new Set(field.options.map(option => option.value)).size !== field.options.length
                || field.options.some(option => !option.value.trim() || !option.label.trim()))) return false;
            names.add(field.name);
        }
        return true;
    }
    async function preview(save = false) {
        const message = dialog.querySelector('[data-designer-status]');
        if (!valid()) { message.textContent = labels.invalid; message.focus(); return; }
        const current = ++generation, snapshot = JSON.stringify(draft);
        message.textContent = labels.loading;
        try {
            const response = await fetch('/sis/website/form-preview/', { method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
                headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded', [config.csrf_header]: config.csrf },
                body: new URLSearchParams({ _workspace_json: JSON.stringify({ definition: draft }) }) });
            const result = await response.json();
            if (!dialog.open || current !== generation || snapshot !== JSON.stringify(draft)) return;
            if (!response.ok) { message.textContent = result.errors?.map(item => item.message).join(' ') || labels.failed; message.focus(); return; }
            if (save) {
                activeTrait.setTargetValue(JSON.stringify(draft)); original = JSON.stringify(draft);
                message.textContent = labels.saved; close();
            } else {
                const region = dialog.querySelector('[data-designer-preview]');
                region.innerHTML = result.html;
                region.hidden = false; dialog.querySelector('[data-designer-editor]').hidden = true;
                message.textContent = ''; region.querySelector('h2')?.setAttribute('tabindex', '-1'); region.querySelector('h2')?.focus();
            }
        } catch (_) { if (dialog.open && current === generation) message.textContent = labels.failed; }
    }
    function newsletterSetting(parent) {
        const section = node('section', { class: 'wf-setting', style: 'grid-column:1 / -1' });
        const picker = node('select', { id: 'wf-newsletter', class: 'form-control' }, node('option', { value: '' }, labels.no_newsletter));
        const mappings = node('div', { class: 'wf-grid mt-3' }), status = node('p', { role: 'status', class: 'form-text' });
        let profiles = new Map(), cursor = null, lookup = 0;
        if (draft.newsletter) picker.append(node('option', { value: draft.newsletter.connection_id }, labels.saved_newsletter));
        picker.value = draft.newsletter?.connection_id || '';
        function mapFields() {
            mappings.replaceChildren(); if (!draft.newsletter) return;
            for (const [key, label, type] of [['email_field', 'newsletter_email', 'email'], ['name_field', 'newsletter_name', 'text'], ['consent_field', 'newsletter_consent', 'checkbox']]) {
                const control = node('select', { id: `wf-newsletter-${key}`, class: 'form-control', onchange: () => {
                    draft.newsletter[key] = control.value || (key === 'name_field' ? null : ''); changed();
                } }, node('option', { value: '' }, labels[key === 'name_field' ? 'newsletter_no_name' : 'newsletter_choose']));
                draft.fields.filter(field => field.type === type && !field.show_when
                    && (type === 'email' ? field.required : type === 'checkbox' ? !field.required : true))
                    .forEach(field => control.append(node('option', { value: field.name }, field.label)));
                control.value = draft.newsletter[key] || '';
                mappings.append(node('div', { class: 'wf-setting' }, node('label', { for: control.id }, labels[label]), control));
            }
        }
        picker.addEventListener('change', () => {
            const profile = profiles.get(picker.value);
            if (!picker.value) draft.newsletter = null;
            else if (profile) draft.newsletter = { connection_id: profile.id, target_hash: profile.target_hash,
                email_field: draft.fields.find(field => field.type === 'email' && field.required && !field.show_when)?.name || '',
                name_field: null, consent_field: draft.fields.find(field => field.type === 'checkbox' && !field.required && !field.show_when)?.name || '' };
            changed(); mapFields();
        });
        const more = button(labels.newsletter_more, () => find(cursor)); more.hidden = true;
        async function find(after = null) {
            const captured = draft, current = ++lookup;
            try {
                const response = await fetch('/sis/website/newsletters/?' + new URLSearchParams({ choices: '1', ...(after ? { after } : {}) }),
                    { credentials: 'same-origin', cache: 'no-store', redirect: 'error', headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error();
                const data = await response.json();
                if (!section.isConnected || draft !== captured || lookup !== current) return;
                profiles = new Map(data.items.map(item => [item.id, item]));
                const chosen = draft.newsletter?.connection_id || '';
                picker.replaceChildren(node('option', { value: '' }, labels.no_newsletter));
                data.items.forEach(item => picker.append(node('option', { value: item.id }, item.name)));
                if (chosen && !profiles.has(chosen)) picker.append(node('option', { value: chosen }, labels.saved_newsletter));
                picker.value = chosen; cursor = data.next_after; more.hidden = !cursor;
                status.textContent = data.delivery_enabled ? '' : labels.newsletter_paused;
            } catch (_) { if (section.isConnected) status.textContent = labels.failed; }
        }
        section.append(node('h3', { class: 'h5' }, labels.newsletter), node('p', { class: 'form-text' }, labels.newsletter_help),
            node('label', { for: picker.id }, labels.newsletter_connection), picker, status, mappings,
            node('div', { class: 'wf-toolbar' }, button(labels.newsletter_refresh, () => find()), more,
                button(labels.newsletter_add_consent, () => {
                    if (draft.fields.length >= 40) { status.textContent = labels.limit; return; }
                    let key = 'newsletter_opt_in';
                    if (draft.fields.some(field => field.name === key)) key += '_' + crypto.randomUUID().slice(0, 8);
                    draft.fields.push({ name: key, type: 'checkbox', label: labels.newsletter_consent_label,
                        help: labels.newsletter_consent_help, required: false });
                    if (draft.newsletter) draft.newsletter.consent_field = key;
                    changed(); render(); listFocus(draft.fields.length - 1);
                }, 'plus-lg'), node('a', { href: '/sis/website/newsletters/view/', target: '_blank', rel: 'noopener noreferrer', class: 'btn btn-outline-secondary' }, labels.newsletter_configure)));
        parent.append(section); mapFields(); queueMicrotask(() => { if (section.isConnected) void find(); });
    }
    function departmentSetting(parent) {
        const picker = node('select', { class: 'form-control', id: 'wf-department', onchange: () => {
            draft.department_id = picker.value || null; changed();
        } }, node('option', { value: '' }, labels.no_department));
        if (draft.department_id) picker.append(node('option', { value: draft.department_id }, labels.saved_department));
        picker.value = draft.department_id || '';
        const search = node('input', { class: 'form-control', type: 'search', id: 'wf-department-search', maxlength: 80 });
        const more = button(labels.next_departments, () => find(cursor)); more.hidden = true;
        let cursor = null, lookup = 0;
        const find = async (after = null) => {
            const current = ++lookup, captured = draft;
            try {
                const response = await fetch('/sis/website/form-access/choices/?' + new URLSearchParams({
                    kind: 'department', search: search.value, ...(after ? { after } : {})
                }), { credentials: 'same-origin', cache: 'no-store', redirect: 'error', headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error();
                const result = await response.json();
                if (!picker.isConnected || draft !== captured || lookup !== current) return;
                const chosen = draft.department_id || '';
                picker.replaceChildren(node('option', { value: '' }, labels.no_department));
                result.items.forEach(row => picker.append(node('option', { value: row.id }, `${row.code} · ${row.title}`)));
                if (chosen && !result.items.some(row => row.id === chosen)) picker.append(node('option', { value: chosen }, labels.saved_department));
                picker.value = chosen; cursor = result.next_after; more.hidden = !cursor;
            } catch (_) { if (picker.isConnected) dialog.querySelector('[data-designer-status]').textContent = labels.failed; }
        };
        parent.append(node('div', { class: 'wf-setting' }, node('label', { for: picker.id }, labels.department), picker,
            node('p', { class: 'form-text' }, labels.department_help), node('label', { for: search.id }, labels.search_departments), search,
            node('div', { class: 'wf-toolbar mt-2' }, button(labels.search, () => find()), more)));
        queueMicrotask(() => { if (picker.isConnected) void find(); });
    }
    function render() {
        cleanup();
        const editor = dialog.querySelector('[data-designer-editor]'); editor.replaceChildren();
        const general = node('section', { class: 'wf-card wf-grid' });
        for (const key of ['key', 'title', 'intro', 'submit_label', 'success_message'])
            setting(general, key, draft[key], value => { draft[key] = value; }, { maxlength: key === 'intro' ? 1000 : key === 'success_message' ? 500 : key === 'key' ? 40 : 120, lines: ['intro', 'success_message'].includes(key) ? 3 : 0 });
        departmentSetting(general);
        newsletterSetting(general);
        editor.append(general, node('h3', { class: 'h5' }, labels.fields));
        const list = node('div', { 'data-designer-fields': '' });
        draft.fields.forEach((field, index) => {
            const actions = node('div', { class: 'wf-toolbar' });
            const grip = button(labels.grip, () => {}, 'grip-vertical'); grip.classList.add('wf-grip'); actions.append(grip);
            for (const [delta, label, glyph] of [[-1, 'up', 'arrow-up'], [1, 'down', 'arrow-down']]) {
                const move = button(labels[label], () => {
                    [draft.fields[index], draft.fields[index + delta]] = [draft.fields[index + delta], draft.fields[index]];
                    changed(); render(); listFocus(index + delta);
                }, glyph); move.disabled = index + delta < 0 || index + delta >= draft.fields.length; actions.append(move);
            }
            actions.append(button(labels.duplicate, () => {
                if (draft.fields.length >= 40) { dialog.querySelector('[data-designer-status]').textContent = labels.limit; return; }
                const copy = clone(field); copy.name = `field_${crypto.randomUUID().replaceAll('-', '').slice(0, 24)}`;
                draft.fields.splice(index + 1, 0, copy); changed(); render(); listFocus(index + 1);
            }, 'copy'), button(labels.remove, () => { draft.fields.splice(index, 1); changed(); render(); }, 'trash3'));
            const heading = node('h4', { class: 'h6', id: `wf-card-${index}` }, field.label || labels.type);
            const card = node('section', { class: 'wf-card', 'data-field-position': index, 'aria-labelledby': heading.id }, heading, actions);
            const grid = node('div', { class: 'wf-grid mt-3' });
            const typePicker = node('select', { class: 'form-control', id: `wf-type-${index}`, onchange: event => {
                field.type = event.target.value;
                field.min = field.max = field.min_length = field.max_length = null;
                field.options = ['select', 'radio', 'multi_select'].includes(field.type)
                    ? field.options?.length ? field.options : [{ value: 'one', label: '1' }, { value: 'two', label: '2' }] : [];
                changed(); render(); listFocus(index);
            } }, ...types.map(type => node('option', { value: type }, labels[type])));
            typePicker.value = field.type;
            grid.append(node('div', { class: 'wf-setting' }, node('label', { for: typePicker.id }, labels.type), typePicker));
            setting(grid, 'name', field.name, value => {
                const old = field.name; field.name = value;
                draft.fields.forEach(item => { if (item.show_when?.field === old) item.show_when.field = value; });
                if (draft.newsletter) for (const key of ['email_field', 'name_field', 'consent_field']) {
                    if (draft.newsletter[key] === old) draft.newsletter[key] = value;
                }
            }, { maxlength: 40 });
            setting(grid, 'label', field.label, value => { field.label = value; heading.textContent = value || labels.type; }, { maxlength: 120 });
            setting(grid, 'help', field.help, value => { field.help = value; }, { maxlength: 300, lines: 2 });
            const required = node('input', { type: 'checkbox', checked: field.required, onchange: event => { field.required = event.target.checked; changed(); } });
            grid.append(node('label', { class: 'form-check-label wf-setting' }, required, ` ${labels.required}`));
            if (['select', 'radio', 'multi_select'].includes(field.type)) setting(grid, 'options', (field.options || []).map(option => `${option.value} | ${option.label}`).join('\n'), value => {
                field.options = value.split('\n').filter(line => line.trim()).map(line => {
                    const [value, ...label] = line.split('|'); return { value: value.trim(), label: (label.length ? label.join('|') : value).trim() };
                });
            }, { lines: 5 });
            if (['text', 'textarea', 'email', 'tel', 'url'].includes(field.type)) {
                for (const key of ['min_length', 'max_length']) setting(grid, key, field[key], value => { field[key] = value === '' ? null : Number(value); }, { type: 'number' });
            }
            if (['number', 'date'].includes(field.type)) {
                for (const key of ['min', 'max']) setting(grid, key, field[key], value => { field[key] = value === '' ? null : field.type === 'number' ? Number(value) : value; }, { type: field.type });
            }
            conditionSettings(grid, field, index); card.append(grid); list.append(card);
        });
        const select = node('select', { class: 'form-control', 'aria-label': labels.type }, ...types.map(type => node('option', { value: type }, labels[type])));
        editor.append(list, node('div', { class: 'wf-toolbar wf-card' }, select, button(labels.add, () => {
            if (draft.fields.length >= 40) { dialog.querySelector('[data-designer-status]').textContent = labels.limit; return; }
            draft.fields.push({ name: `field_${crypto.randomUUID().replaceAll('-', '').slice(0, 24)}`, type: select.value,
                label: labels[select.value], required: false, help: '', options: ['select', 'radio', 'multi_select'].includes(select.value)
                    ? [{ value: 'one', label: '1' }, { value: 'two', label: '2' }] : [] }); changed(); render(); listFocus(draft.fields.length - 1);
        }, 'plus-lg')));
        if (window.Sortable) sortables.push(new Sortable(list, { handle: '.wf-grip', draggable: '[data-field-position]', forceFallback: true, fallbackOnBody: true, store: null,
            animation: matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 120,
            onEnd: event => { const [field] = draft.fields.splice(event.oldDraggableIndex, 1); draft.fields.splice(event.newDraggableIndex, 0, field); changed(); render(); listFocus(event.newDraggableIndex); } }));
    }
    function listFocus(index) { dialog.querySelector(`[data-field-position="${index}"] input`)?.focus(); }
    function open(trait) {
        if (dialog?.open) return;
        try { draft = JSON.parse(trait.getTargetValue()); } catch (_) { draft = clone(config.starter); }
        if (!draft || !Array.isArray(draft.fields)) draft = clone(config.starter);
        original = JSON.stringify(draft); activeTrait = trait; opener = document.activeElement;
        if (!dialog) {
            dialog = node('dialog', { class: 'website-form-designer', 'aria-labelledby': 'website-form-designer-title' });
            dialog.append(node('header', {}, node('h2', { id: 'website-form-designer-title' }, labels.edit), node('div', { class: 'wf-toolbar' },
                button(labels.preview, () => preview()), button(labels.save, () => preview(true), 'check-lg'), button(labels.close, close, 'x-lg'))),
            node('main', {}, node('p', { 'data-designer-status': '', role: 'status', 'aria-live': 'polite', tabindex: '-1' }),
                node('div', { 'data-designer-editor': '' }), node('div', { 'data-designer-preview': '', hidden: true }),
                button(labels.back, () => { dialog.querySelector('[data-designer-preview]').hidden = true; dialog.querySelector('[data-designer-editor]').hidden = false; })));
            dialog.addEventListener('cancel', event => { event.preventDefault(); close(); }); document.body.append(dialog);
        }
        dialog.querySelector('[data-designer-preview]').hidden = true; dialog.querySelector('[data-designer-editor]').hidden = false;
        dialog.querySelector('[data-designer-status]').textContent = ''; render(); dialog.showModal(); dialog.querySelector('input')?.focus();
    }
    window.addEventListener('beforeunload', event => { if (dialog?.open && dirty()) event.preventDefault(); });
    window.addEventListener('vihzhuo:grapesjs:before-init', event => {
        event.detail.plugins = [...(event.detail.plugins || []), editor => {
            editor.TraitManager.addType('website-form', { createInput({ trait }) { return button(labels.edit, () => open(trait), 'ui-checks'); } });
        }];
    });
})();
