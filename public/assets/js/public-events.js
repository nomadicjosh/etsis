/* Progressive enhancement of the server-rendered public calendar. No account reads or browser storage. */
document.addEventListener('DOMContentLoaded', () => {
    'use strict';
    const root = document.querySelector('[data-public-events]');
    if (!root) return;
    let generation = 0, pending = null;
    const status = document.createElement('p');
    status.className = 'campus-muted mt-3';
    status.setAttribute('role', 'status');
    root.after(status);
    const invalidate = () => { generation++; pending?.abort(); root.removeAttribute('aria-busy'); status.textContent = ''; };
    root.addEventListener('input', invalidate);
    root.addEventListener('change', invalidate);
    async function load(url, daily) {
        invalidate();
        const serial = generation;
        pending = new AbortController();
        root.setAttribute('aria-busy', 'true');
        status.textContent = 'Finding public events…';
        try {
            const response = await fetch(`/portal/api/events/?${url.searchParams}`, {
                credentials: 'same-origin', cache: 'no-store', signal: pending.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json();
            if (serial !== generation || !root.isConnected) return;
            if (!response.ok || typeof data.html !== 'string') throw new Error();
            // This fragment is escaped by the same server template used for the non-JavaScript page.
            const fragment = new DOMParser().parseFromString(data.html, 'text/html').querySelector('#campus-event-calendar');
            if (!fragment) throw new Error();
            root.replaceChildren(document.importNode(fragment, true));
            status.textContent = 'Public events updated.';
            root.querySelector(daily ? '[data-event-day-heading]' : '[data-event-heading]')?.focus();
        } catch (error) {
            if (serial === generation && error.name !== 'AbortError')
                status.textContent = 'Unable to load these events. The previous results are still shown. Try again or reload the page.';
        } finally {
            if (serial === generation) root.removeAttribute('aria-busy');
        }
    }
    root.addEventListener('submit', event => {
        if (!event.target.matches('[data-event-filter]')) return;
        event.preventDefault();
        const url = new URL('/portal/events/', location.origin);
        url.search = new URLSearchParams(new FormData(event.target)).toString();
        load(url, false);
    });
    root.addEventListener('click', event => {
        const link = event.target.closest('a[data-event-link]');
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        const url = new URL(link.href);
        load(url, url.searchParams.has('day'));
    });
    window.addEventListener('pagehide', invalidate);
});
