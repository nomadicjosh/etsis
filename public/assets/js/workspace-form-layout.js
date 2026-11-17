/* One working-definition model for field validation, references and retained form layouts. */
'use strict';
window.createApplicationLayoutEditor = ({ node, icon, openDrawer, closeDrawer, task, draft, changed, render, announce }) => {
    let sortables = [];
    const layout = () => draft().layout || [];
    const find = key => {
        for (const container of layout()) for (const [index, column] of container.columns.entries())
            if (column.includes(key)) return { container, index, column };
        return null;
    };
    function reorder() {
        if (!layout().length) return;
        const fields = new Map(draft().fields.map(field => [field.key, field]));
        draft().fields = layout().flatMap(container => container.columns.flat()).map(key => fields.get(key));
    }
    function remove(key) {
        const place = find(key);
        if (place) place.column.splice(place.column.indexOf(key), 1);
    }
    function focus(key) {
        const card = document.querySelector(`[data-field-key="${CSS.escape(key)}"]`);
        card?.querySelector('.form-field-drag')?.focus();
    }
    const cleanup = () => { sortables.forEach(sortable => sortable.destroy()); sortables = []; };
    function sorted(root, event) {
        if (event.from === event.to && event.oldDraggableIndex === event.newDraggableIndex) return;
        const key = event.item.dataset.fieldKey;
        if (layout().length) {
            const containers = new Map(layout().map(container => [container.key, container]));
            draft().layout = [...root.querySelectorAll('.designer-container')].map(element => {
                const container = containers.get(element.dataset.containerKey);
                container.columns = [...element.querySelectorAll('.designer-column')].map(column =>
                    [...column.children].filter(child => child.matches('.designer-field')).map(card => card.dataset.fieldKey));
                return container;
            });
            reorder();
        } else {
            const [field] = draft().fields.splice(event.oldDraggableIndex, 1);
            draft().fields.splice(event.newDraggableIndex, 0, field);
        }
        changed();
        announce(key ? 'Question moved. Review its conditions before saving.' : 'Container order updated.');
        requestAnimationFrame(() => { render(); if (key) focus(key); });
    }
    function sortable(element, root, containers = false) {
        if (!window.Sortable) return;
        sortables.push(new window.Sortable(element, {
            ...(containers ? {} : { group: 'application-questions' }),
            handle: containers ? '.form-container-drag' : '.form-field-drag',
            draggable: containers ? '.designer-container' : '.designer-field',
            ghostClass: 'form-field-ghost', forceFallback: true, fallbackOnBody: true,
            animation: matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 140,
            delayOnTouchOnly: true, delay: 150, store: null,
            onEnd: event => sorted(root, event),
        }));
    }
    function palette(container, column) {
        const id = `add-${container.key}-${column}`;
        return node('div', { class: 'dropdown form-column-add' },
            node('button', { type: 'button', class: 'btn btn-sm btn-outline-primary dropdown-toggle', id,
                'data-bs-toggle': 'dropdown', 'data-workspace-menu': '', 'aria-expanded': 'false',
                'aria-label': `Add a field to ${container.label}, column ${column + 1}` }, icon('plus-lg'), ' Add a field'),
            node('ul', { class: 'dropdown-menu', 'aria-labelledby': id }, Object.entries(window.applicationQuestionTypes).map(([type, label]) =>
                node('li', {}, node('button', { type: 'button', class: 'dropdown-item', 'data-add-field': type,
                    'data-container': container.key, 'data-column': column }, label)))));
    }
    function renderContainers(root, cards) {
        cleanup();
        if (!layout().length) {
            root.append(...cards.values()); sortable(root, root); return;
        }
        layout().forEach((container, index) => {
            const title = node('h3', { id: `container-${container.key}` }, container.label || 'Untitled container');
            const actions = node('div', { class: 'form-container-actions' });
            for (const [delta, label, glyph] of [[-1, 'Move container up', 'arrow-up'], [1, 'Move container down', 'arrow-down']])
                actions.append(node('button', { class: 'icon-button', type: 'button', 'aria-label': `${label}: ${container.label}`,
                    disabled: index + delta < 0 || index + delta >= layout().length, onclick: () => {
                        [draft().layout[index], draft().layout[index + delta]] = [draft().layout[index + delta], draft().layout[index]];
                        reorder(); changed(); render();
                        document.getElementById(`container-${container.key}`)?.focus();
                    } }, icon(glyph)));
            actions.append(node('button', { class: 'icon-button', type: 'button', 'aria-label': `Remove container: ${container.label}`,
                title: 'Move or remove all questions before removing this container', disabled: container.columns.some(column => column.length),
                onclick: () => { draft().layout.splice(index, 1); changed(); render(); } }, icon('trash3')));
            title.tabIndex = -1;
            const heading = node('div', { class: 'form-container-heading' },
                node('button', { class: 'icon-button form-container-drag', type: 'button', 'aria-label': `Reorder container ${index + 1}`, title: 'Drag to reorder; arrow buttons also work' }, icon('grip-vertical')),
                title, actions);
            const titleInput = node('input', { class: 'form-control', id: `container-title-${container.key}`, maxlength: 120, required: true, type: 'text',
                oninput: event => {
                    container.label = event.target.value; title.textContent = container.label || 'Untitled container';
                    ['Move container up', 'Move container down', 'Remove container'].forEach((label, position) =>
                        actions.children[position].setAttribute('aria-label', `${label}: ${container.label}`));
                    columns.querySelectorAll('.designer-column').forEach((column, position) => {
                        column.setAttribute('aria-label', `${container.label}, column ${position + 1}`);
                        column.querySelector('.form-column-add button').setAttribute('aria-label', `Add a field to ${container.label}, column ${position + 1}`);
                    });
                    changed(event);
                } });
            titleInput.value = container.label;
            const help = node('input', { class: 'form-control', id: `container-help-${container.key}`, maxlength: 300, type: 'text',
                oninput: event => { container.help = event.target.value; changed(event); } }); help.value = container.help || '';
            const columnsSelect = node('select', { class: 'form-select', id: `container-columns-${container.key}`, onchange: event => {
                const count = Number(event.target.value);
                while (container.columns.length < count) container.columns.push([]);
                if (container.columns.length > count) container.columns[count - 1].push(...container.columns.splice(count).flat());
                reorder(); changed(); render(); document.getElementById(columnsSelect.id)?.focus();
            } }, [1, 2, 3].map(count => node('option', { value: count }, `${count} ${count === 1 ? 'column' : 'columns'}`)));
            columnsSelect.value = container.columns.length;
            const settings = node('details', { class: 'form-container-settings', open: true }, node('summary', {}, 'Container settings'),
                node('div', { class: 'row g-3 mt-1' },
                    node('div', { class: 'col-md-8' }, node('label', { class: 'form-label', for: titleInput.id }, 'Container title'), titleInput),
                    node('div', { class: 'col-md-4' }, node('label', { class: 'form-label', for: columnsSelect.id }, 'Columns'), columnsSelect),
                    node('div', { class: 'col-12' }, node('label', { class: 'form-label', for: help.id }, 'Container introduction'), help)));
            const columns = node('div', { class: 'designer-columns', 'data-columns': container.columns.length });
            container.columns.forEach((keys, column) => {
                const target = node('div', { class: 'designer-column', role: 'group', 'aria-label': `${container.label}, column ${column + 1}` },
                    node('p', { class: 'form-column-label' }, `Column ${column + 1}`),
                    keys.map(key => cards.get(key)).filter(Boolean), palette(container, column));
                columns.append(target); sortable(target, root);
            });
            root.append(node('section', { class: 'designer-container', 'data-container-key': container.key, 'aria-labelledby': title.id },
                heading, settings, columns));
        });
        sortable(root, root, true);
    }
    return {
        conditionLimits: tree => {
            let nodes = 0;
            const valueValid = value => typeof value === 'boolean' || (typeof value === 'number' && Number.isSafeInteger(value))
                || (typeof value === 'string' && value.trim() !== '' && Array.from(value).length <= 191 && !/[\x00-\x1f\x7f]/.test(value));
            const valid = (group, depth = 0) => {
                if (depth > 4 || ++nodes > 50 || !['AND', 'OR'].includes(group?.condition)
                    || !Array.isArray(group.rules) || !group.rules.length || group.rules.length > 20) return false;
                return group.rules.every(rule => {
                    if (rule?.condition) return valid(rule, depth + 1);
                    if (++nodes > 50 || !rule) return false;
                    if (['in', 'not_in'].includes(rule.operator))
                        return Array.isArray(rule.value) && rule.value.length > 0 && rule.value.length <= 20 && rule.value.every(valueValid);
                    return valueValid(rule.value);
                });
            };
            return valid(tree) ? '' : 'Use 1–20 conditions per group, at most 50 conditions and four nested groups. Lists allow 1–20 values; text values allow up to 191 characters.';
        },
        inputProblem: (part, value) => {
            if (!['label', 'help', 'options'].includes(part)) return '';
            if (part === 'options') {
                const options = value.split('\n').filter(option => option.trim());
                if (!options.length || options.length > 30) return 'Enter between 1 and 30 choices, one per line.';
                if (options.some(option => Array.from(option).length > 120 || /[\x00-\x1f\x7f]/.test(option)))
                    return 'Each choice must use up to 120 characters without control characters.';
                return '';
            }
            if (part === 'label' && !value.trim()) return 'Give this question a label.';
            if (Array.from(value).length > (part === 'label' ? 120 : 300))
                return `Use up to ${part === 'label' ? 120 : 300} characters.`;
            return /[\x00-\x1f\x7f]/.test(value) ? 'Remove control characters from this text.' : '';
        },
        cleanup,
        render: renderContainers,
        addContainer: () => {
            if (layout().length >= 12) throw new Error('An application form supports up to twelve containers.');
            const container = { key: `section_${crypto.randomUUID().replaceAll('-', '').slice(0, 24)}`,
                label: `Section ${layout().length + 1}`, help: '', columns: [layout().length ? [] : draft().fields.map(field => field.key)] };
            draft().layout = [...layout(), container]; changed(); render();
            document.getElementById(`container-title-${container.key}`)?.focus();
        },
        add: (field, containerKey, column) => {
            draft().fields.push(field);
            if (layout().length) {
                const container = layout().find(item => item.key === containerKey) || layout().at(-1);
                container.columns[Number(column) || 0].push(field.key); reorder();
            }
        },
        remove,
        rename: (oldKey, key) => {
            const fields = draft().fields, field = fields.find(item => item.key === oldKey);
            if (!field || typeof key !== 'string' || !/^[a-z][a-z0-9_]{0,39}$/.test(key)
                || fields.some(item => item !== field && item.key === key)) return false;
            if (oldKey === key) return true;
            const renameReferences = group => {
                for (const rule of group.rules) {
                    if (rule.rules) renameReferences(rule);
                    else if (rule.id === oldKey) rule.id = key;
                }
            };
            const place = find(oldKey);
            if (place) place.column[place.column.indexOf(oldKey)] = key;
            for (const item of fields) if (item.show_when) renameReferences(item.show_when);
            field.key = key;
            return true;
        },
        insertAfter: (key, copyKey) => { const place = find(key); if (place) { place.column.splice(place.column.indexOf(key) + 1, 0, copyKey); reorder(); } },
        canMove: (key, delta) => {
            const keys = find(key)?.column || draft().fields.map(field => field.key);
            return keys.indexOf(key) + delta >= 0 && keys.indexOf(key) + delta < keys.length;
        },
        move: (key, delta) => {
            const keys = find(key)?.column || draft().fields.map(field => field.key), index = keys.indexOf(key);
            [keys[index], keys[index + delta]] = [keys[index + delta], keys[index]];
            if (layout().length) reorder(); else {
                [draft().fields[index], draft().fields[index + delta]] = [draft().fields[index + delta], draft().fields[index]];
            }
            return draft().fields.findIndex(field => field.key === key);
        },
        showMove: field => {
            const body = openDrawer('Move question', field.label || 'APPLICATION QUESTION');
            const select = node('select', { class: 'form-select', id: 'form-field-destination' },
                layout().flatMap(container => container.columns.map((column, index) =>
                    node('option', { value: `${container.key}:${index}` }, `${container.label} · Column ${index + 1}`))));
            const current = find(field.key); if (current) select.value = `${current.container.key}:${current.index}`;
            body.append(node('label', { class: 'form-label', for: select.id }, 'Destination'), select,
                node('p', { class: 'form-text' }, 'The question goes at the end of this column. Conditions must still refer to earlier questions.'),
                node('button', { class: 'btn btn-primary', type: 'button', onclick: event => task(async () => {
                    const [key, index] = select.value.split(':'); remove(field.key);
                    layout().find(container => container.key === key).columns[Number(index)].push(field.key);
                    reorder(); changed(); render(); closeDrawer(() => focus(field.key));
                }, event.currentTarget) }, 'Move question'));
        },
    };
};
