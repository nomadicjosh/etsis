/* Explicit reply scopes complement ordinary role permissions; drafts stay in page memory. */
'use strict';
window.createWebsiteFormAccess = ({ root, request, node, button, message, labels }) => {
    const t = key => labels[`form_${key}`];
    let generation = 0, selected = null, memberCursor = null;
    const status = error => message(error.message, 'danger');
    const run = async action => { try { await action(); } catch (error) { status(error); } };
    function chooser(kind, title, choose) {
        let cursor = null, lookup = 0;
        const id = `form-access-${kind}-${crypto.randomUUID()}`;
        const search = node('input', { id, type: 'search', class: 'form-control', maxlength: 80 });
        const options = node('div', { class: 'd-grid gap-2 mt-3' });
        const more = button('form_next', () => find(cursor), 'arrow-right'); more.hidden = true;
        const form = node('form', { class: 'site-card mb-3', onsubmit: event => { event.preventDefault(); void run(() => find()); } },
            node('h2', { class: 'h5' }, title), node('label', { for: id, class: 'form-label' }, t('search')), search,
            button('form_search', () => find(), 'search'), options, more);
        async function find(after = null) {
            const current = ++lookup;
            const data = await request('/sis/website/form-access/choices/?' + new URLSearchParams({ kind, search: search.value,
                ...(after ? { after } : {}) }));
            if (!form.isConnected || current !== lookup) return;
            cursor = data.next_after; more.hidden = !cursor;
            options.replaceChildren(...data.items.map(item => {
                const label = kind === 'department' ? `${item.code} · ${item.title}` : kind === 'page' ? item.name
                    : `${item.given_name} ${item.family_name} · ${item.institutional_id}`;
                const action = node('button', { type: 'button', class: 'btn btn-outline-secondary text-start',
                    onclick: () => { if (form.isConnected) void run(() => choose(item)); } }, label);
                return action;
            }));
            if (!data.items.length) options.append(node('p', {}, t('empty')));
        }
        return form;
    }
    const detail = node('section', { class: 'site-card', tabindex: '-1' });
    async function members(cursor = null) {
        if (!selected) return;
        const current = ++generation, scope = { ...selected };
        const query = new URLSearchParams({ kind: scope.kind, scope: scope.id, form: scope.key, ...(cursor ? { after: cursor } : {}) });
        const data = await request('/sis/website/form-access/members/?' + query);
        if (current !== generation || !detail.isConnected) return;
        memberCursor = data.next_after;
        const list = node('div');
        for (const row of data.items) {
            const card = node('div', { class: 'border rounded p-3 mb-3 d-flex flex-wrap gap-3 align-items-center' },
                node('span', { class: 'text-break flex-grow-1' }, `${row.given_name || t('unknown_account')} ${row.family_name || ''}`));
            card.append(button('form_remove_access', async () => {
                await request('/sis/website/form-access/members/', { kind: scope.kind, scope: scope.id, form: scope.key,
                    user_id: row.user_id, grant: false, expected: row.id });
                if (current === generation) { message(t('access_saved'), 'success'); await members(); }
            }, 'person-dash', 'btn btn-outline-danger'));
            list.append(card);
        }
        if (!data.items.length) list.append(node('p', {}, t('no_members')));
        const accounts = chooser('account', t('choose_account'), async account => {
            if (current !== generation) return;
            await request('/sis/website/form-access/members/', { kind: scope.kind, scope: scope.id, form: scope.key,
                user_id: account.id, grant: true, expected: null });
            if (current === generation) { message(t('access_saved'), 'success'); await members(); }
        });
        detail.replaceChildren(node('h2', { class: 'h4' }, scope.title), node('p', { class: 'form-text' },
            t(scope.kind === 'department' ? 'route_department' : 'route_form')), node('h3', { class: 'h5' }, t('members')), list,
            ...(memberCursor ? [button('form_more_members', () => members(memberCursor), 'arrow-right')] : []),
            ...(cursor ? [button('form_first_members', () => members(), 'arrow-left')] : []),
            node('p', { class: 'form-text' }, t('account_help')), accounts);
        detail.focus();
    }
    async function forms(page) {
        const current = ++generation;
        const result = await request('/sis/website/form-access/forms/?' + new URLSearchParams({ page: page.id }));
        if (current !== generation) return;
        detail.replaceChildren(node('h2', { class: 'h4' }, page.name), ...result.items.map(form =>
            node('button', { class: 'btn btn-outline-secondary m-2 text-start', type: 'button', onclick: () => {
                selected = { kind: 'form', id: page.id, key: form.key, title: form.title };
                void run(() => members());
            } }, `${form.title} · ${form.key}`)));
        if (!result.items.length) detail.append(node('p', {}, t('empty')));
        detail.focus();
    }
    async function load() {
        root.replaceChildren(node('p', {}, t('access_help')), node('div', { class: 'row g-4' },
            node('div', { class: 'col-12 col-lg-5' }, chooser('department', t('department_members'), async department => {
                selected = { kind: 'department', id: department.id, key: '', title: `${department.code} · ${department.title}` };
                await members();
            }), chooser('page', t('form_members'), forms)), node('div', { class: 'col-12 col-lg-7' }, detail)));
        detail.replaceChildren(node('p', {}, t('choose_scope')));
    }
    const dispose = () => { generation++; selected = null; detail.replaceChildren(); root.replaceChildren(); };
    window.addEventListener('pagehide', dispose);
    window.addEventListener('site:access-denied', dispose);
    return { load };
};
