/* Private account email control. No token persistence, URL requests, automatic consumption or automatic resend. */
(() => {
    'use strict';
    const node = (tag, attributes = {}, ...children) => {
        const element = document.createElement(tag);
        for (const [key, value] of Object.entries(attributes)) {
            if (value === undefined || value === null || value === false) continue;
            if (key.startsWith('on')) element.addEventListener(key.slice(2), value);
            else element.setAttribute(key, value === true ? '' : String(value));
        }
        for (const child of children.flat()) if (child !== null && child !== undefined) element.append(child instanceof Node ? child : document.createTextNode(String(child)));
        return element;
    };
    window.createEmailVerification = ({ request, labels: t }) => ({
        mount(container, { token = '', signedIn = true, loginUrl = '/login/', inline = false } = {}) {
            let generation = 0, current = null, busy = false, disposed = false, pending = null;
            const root = node('section', { class: 'border rounded bg-white p-3 my-3 text-break', 'aria-label': t.title });
            const summary = node('div');
            const status = node('p', { role: 'status', 'aria-live': 'polite', tabindex: '-1', class: 'form-text' });
            const controls = node('div', { class: 'd-flex gap-2 flex-wrap' });
            const link = (title, href, blank = false) => node('a', { class: 'btn campus-button-outline', href,
                ...(blank ? { target: '_blank', rel: 'noopener' } : {}) },
                node('i', { class: 'bi bi-box-arrow-up-right me-2', 'aria-hidden': 'true' }), title);
            const button = (title, run, glyph) => node('button', { type: 'button', class: 'btn campus-button-outline',
                onclick: () => void work(run) }, node('i', { class: `bi bi-${glyph} me-2`, 'aria-hidden': 'true' }), title);
            const refresh = button(t.refresh, () => fetchStatus(), 'arrow-clockwise');
            const send = button(t.request, () => mutate('request', { version: current.version }), 'envelope');
            const confirm = button(t.confirm, () => mutate('confirm', { token }), 'check-circle');
            const guidance = node('p', { class: 'form-text' }, t.draft_help);
            root.append(node('h2', { class: 'h5' }, t.title), node('p', {}, t.help), summary, controls, status, guidance);
            container.replaceChildren(root);
            if (!signedIn) {
                token = '';
                summary.replaceChildren(node('p', {}, t.signin));
                controls.append(link(t.signin_action, loginUrl));
                return;
            }
            controls.append(refresh, send, confirm);
            if (inline) root.append(link(t.open, '/portal/verify-email/', true));
            else root.append(link(t.return, '/portal/student/applications/'));
            function valid(data) {
                return data && ['verified', 'required', 'delivery_enabled', 'can_request'].every(key => typeof data[key] === 'boolean')
                    && Number.isInteger(data.version) && data.version >= 0 && data.version <= 2147483647
                    && typeof data.destination_hint === 'string' && data.destination_hint.length <= 191
                    && ['verified', 'not_requested', 'queued', 'sending', 'sent', 'uncertain', 'expired'].includes(data.state)
                    && (data.state === 'verified') === data.verified;
            }
            function draw() {
                summary.replaceChildren(node('p', {}, `${t.destination}: ${current.destination_hint}`),
                    node('p', {}, current.required ? t.required : t.optional), node('p', {}, t[current.state]),
                    ...(!current.delivery_enabled && !current.verified ? [node('p', {}, t.disabled)] : []),
                    ...(!current.verified ? [node('p', { class: 'form-text' }, t.link_help)] : []),
                    ...(token && !current.verified ? [node('p', {}, t.confirm_help)] : []),
                    ...(!current.can_request && current.delivery_enabled && !current.verified ? [node('p', { class: 'form-text' }, t.cooldown)] : []));
                send.hidden = current.verified;
                confirm.hidden = !token || current.verified;
                send.disabled = busy || !current.can_request;
                confirm.disabled = busy;
            }
            async function call(path, method = 'GET', data = undefined) {
                const controller = new AbortController(); pending = controller;
                const timer = setTimeout(() => controller.abort(), 15000);
                try { return await request(`/portal/api/email-verification/${path}`, method, data, controller.signal); }
                finally { clearTimeout(timer); if (pending === controller) pending = null; }
            }
            async function fetchStatus() {
                const serial = ++generation;
                current = null; summary.replaceChildren(node('p', { role: 'status' }, t.loading));
                send.disabled = true; confirm.hidden = true;
                const data = await call('');
                if (disposed || !root.isConnected || serial !== generation) return;
                if (!valid(data)) throw new Error();
                current = data; if (current.verified) token = ''; draw();
            }
            async function mutate(action, data) {
                const serial = ++generation;
                status.textContent = action === 'request' ? t.requesting : t.confirming;
                const result = await call(`${action}/`, 'POST', data);
                if (disposed || !root.isConnected || serial !== generation) return;
                if (!valid(result)) throw new Error();
                current = result; if (current.verified || action === 'request') token = '';
                status.textContent = t[current.state]; status.focus(); draw();
            }
            async function work(run) {
                if (busy || disposed || !root.isConnected) return;
                busy = true; controls.querySelectorAll('button').forEach(item => { item.disabled = true; });
                status.setAttribute('role', 'status'); status.textContent = '';
                try { await run(); }
                catch (error) {
                    if (disposed || !root.isConnected) return;
                    if ([401, 403].includes(error.status)) {
                        token = ''; current = null; summary.replaceChildren(); controls.replaceChildren(link(t.signin_action, loginUrl));
                        status.textContent = t.signin;
                    } else {
                        current = null; summary.replaceChildren();
                        status.setAttribute('role', 'alert'); status.textContent = error.status === 422 ? t.invalid : t.failed;
                    }
                    status.focus();
                } finally {
                    busy = false; refresh.disabled = false;
                    if (current) draw(); else { send.disabled = true; confirm.hidden = true; }
                }
            }
            const dispose = () => { disposed = true; generation++; token = ''; current = null; pending?.abort(); root.replaceChildren(); };
            window.addEventListener('pagehide', dispose, { once: true });
            window.addEventListener('campus:access-denied', dispose, { once: true });
            send.disabled = true; confirm.hidden = true;
            void work(fetchStatus);
        },
    });
    const page = document.getElementById('email-verification');
    if (!page) return;
    let initialToken = /^#[a-f0-9]{64}$/.test(location.hash) ? location.hash.slice(1) : '';
    history.replaceState(null, '', location.pathname);
    const config = JSON.parse(document.getElementById('email-verification-config').textContent);
    const request = async (path, method = 'GET', data = undefined, signal = undefined) => {
        const response = await fetch(path, { method, signal, credentials: 'same-origin', cache: 'no-store', redirect: 'error',
            headers: { Accept: 'application/json', ...(method === 'POST' ? {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                [config.csrf_header]: document.querySelector('#email-verification-csrf input')?.value || '',
            } : {}) }, body: method === 'POST' ? new URLSearchParams({ _workspace_json: JSON.stringify(data) }) : undefined });
        if (!response.ok) { const error = new Error(); error.status = response.status; throw error; }
        return response.json();
    };
    window.createEmailVerification({ request, labels: config.labels }).mount(page, { token: initialToken,
        signedIn: page.dataset.signedIn === '1', loginUrl: config.login_url });
    initialToken = '';
})();
