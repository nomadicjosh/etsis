/* Ordered report labels use the shared condition editor and remain only in the current report draft. */
window.createReportLabelMappings = function (ui) {
    "use strict";
    const { node, button, labeled, icon, labels, definition, fields, conditionBuilder, mutate, changed, back } = ui;
    const t = key => labels[key];
    const decorate = (control, glyph) => {
        if (!control.querySelector('.bi')) control.prepend(icon(glyph));
        control.querySelector('.bi').classList.add('me-2');
    };
    let selected = null, active = null;
    const hasRules = group => (group?.rules || []).some(rule => rule.condition ? hasRules(rule) : !!rule.id);
    function select(column) {
        selected = column;
        column.label_mapping ||= { branches: [{ when: null, label: "" }], otherwise: null };
        active = column.label_mapping.branches[0];
    }
    function ready() {
        for (const column of definition.columns) {
            if (!column.label_mapping) continue;
            const mapping = column.label_mapping;
            if (!mapping.branches.length || mapping.branches.some(branch =>
                !branch.label.trim() || [...branch.label].length > 120 || !hasRules(branch.when)) ||
                (mapping.otherwise !== null && (!mapping.otherwise.trim() || [...mapping.otherwise].length > 120))) {
                throw new Error(t('incomplete'));
            }
        }
    }
    function render(parent) {
        const columns = definition.columns.filter(column => column.label_mapping);
        if (!columns.includes(selected)) selected = columns[0] || null;
        if (!selected) return () => {};
        const column = selected;
        const mapping = column.label_mapping;
        if (!mapping.branches.includes(active)) active = mapping.branches[0];
        const panel = node('section', { class: 'report-filter-section', 'data-report-label-editor': 'true' },
            node('h2', { tabindex: '-1' }, t('title')), node('p', {}, t('help')));
        const choices = node('div', { class: 'd-flex gap-2 flex-wrap mb-3', role: 'group', 'aria-label': t('choose') });
        for (const column of columns) {
            const choose = button(column.label, () => mutate(() => select(column), () => panelFocus()),
                column === selected ? 'btn-sm btn-primary' : 'btn-sm btn-outline-primary');
            choose.setAttribute('aria-pressed', String(column === selected));
            choices.append(choose);
        }
        panel.append(choices);
        mapping.branches.forEach((branch, index) => {
            const row = node('section', { class: 'bg-white border rounded p-3 mb-3',
                'aria-label': `${t('rule')} ${index + 1}` });
            const text = node('input', { type: 'text', class: 'form-control', maxLength: 240,
                value: branch.label, required: true });
            text.addEventListener('input', () => { branch.label = text.value; changed(); });
            const actions = node('div', { class: 'd-flex flex-wrap gap-2 mt-2' });
            const edit = button(t('edit_rule'), () => mutate(() => { active = branch; }, () => panelFocus()),
                branch === active ? 'btn-sm btn-primary' : 'btn-sm btn-outline-primary');
            edit.setAttribute('aria-pressed', String(branch === active));
            actions.append(edit);
            for (const [key, offset, glyph] of [['up', -1, 'arrow-up'], ['down', 1, 'arrow-down']]) {
                const move = button(t(key), () => mutate(() => {
                    mapping.branches.splice(index, 1);
                    mapping.branches.splice(index + offset, 0, branch);
                }, () => panelFocus()), 'btn-sm btn-outline-secondary');
                decorate(move, glyph);
                move.disabled = index + offset < 0 || index + offset >= mapping.branches.length;
                actions.append(move);
            }
            const remove = button(t('remove_rule'), () => mutate(() => {
                mapping.branches.splice(index, 1);
            }, () => panelFocus()), 'btn-sm btn-outline-danger');
            decorate(remove, 'trash');
            remove.disabled = mapping.branches.length === 1;
            actions.append(remove);
            row.append(node('h3', { class: 'h6' }, `${t('rule')} ${index + 1}`), labeled(t('label'), text), actions);
            panel.append(row);
        });
        const add = button(t('add_rule'), () => mutate(() => {
            active = { when: null, label: '' };
            mapping.branches.push(active);
        }, () => panelFocus()));
        decorate(add, 'plus-lg');
        add.disabled = mapping.branches.length >= 8;
        const blank = node('input', { type: 'checkbox', class: 'form-check-input me-2', checked: mapping.otherwise === null });
        const fallback = node('input', { type: 'text', class: 'form-control', maxLength: 240,
            value: mapping.otherwise || '', disabled: blank.checked, required: !blank.checked });
        blank.addEventListener('change', () => {
            mapping.otherwise = blank.checked ? null : fallback.value;
            fallback.disabled = blank.checked;
            fallback.required = !blank.checked;
            changed();
        });
        fallback.addEventListener('input', () => { mapping.otherwise = fallback.value; changed(); });
        panel.append(add, node('p', { class: 'form-text' }, t('limit')),
            node('label', { class: 'form-check-label d-block my-3' }, blank, t('blank')),
            labeled(t('otherwise'), fallback), node('p', { class: 'form-text' }, t('fallback_help')));
        const editor = node('section', { class: 'report-filter-section' },
            node('h3', {}, `${t('rule')} ${mapping.branches.indexOf(active) + 1}: ${t('conditions')}`));
        panel.append(editor);
        const branch = active;
        let restoring = true;
        const read = conditionBuilder(editor, fields(), branch.when, t('conditions'), () => {
            if (!restoring) changed();
        });
        restoring = false;
        const original = button(t('remove'), () => mutate(() => {
            delete column.label_mapping;
        }, () => back(column)));
        const returnButton = button(t('back'), () => back(column), 'btn-link');
        decorate(returnButton, 'arrow-left');
        panel.append(node('div', { class: 'd-flex flex-wrap gap-2 mt-3' }, original, returnButton));
        parent.append(panel);
        return relaxed => { branch.when = read(relaxed); };
    }
    function panelFocus() {
        document.querySelector('[data-report-label-editor] h2')?.focus();
    }
    return { select, render, ready };
};
