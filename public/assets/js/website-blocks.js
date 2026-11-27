/* Public block behavior uses the existing Bootstrap bundle and no inline script execution. */
(() => {
    function startNotices() {
        const Alert = window.bootstrap?.Alert;
        if (typeof Alert?.getOrCreateInstance !== 'function') return;
        const visible = target => target?.isConnected && !target.closest('[hidden],[inert],[aria-hidden="true"]')
            && target.getClientRects().length > 0 && getComputedStyle(target).visibility === 'visible';
        const fallback = () => [...document.querySelectorAll('main h1,main h2,[role="main"] h1')].find(visible)
            || [...document.querySelectorAll('main,[role="main"]')].find(visible);
        document.querySelectorAll('[data-website-notice]').forEach(notice => {
            const button = notice.querySelector(':scope > [data-website-notice-dismiss]');
            if (!button || notice.dataset.noticeReady) return;
            notice.dataset.noticeReady = '1';
            let restore = false, next = null;
            notice.addEventListener('close.bs.alert', event => {
                if (event.target !== notice) return;
                restore = notice.contains(document.activeElement);
                if (!restore) return;
                const candidates = [...document.querySelectorAll('a[href],button:not([disabled]),'
                    + 'input:not([disabled]):not([type="hidden"]),select:not([disabled]),'
                    + 'textarea:not([disabled]),summary,[contenteditable="true"],[tabindex]:not([tabindex="-1"])')]
                    .filter(target => !notice.contains(target) && target.tabIndex >= 0
                        && !target.matches(':disabled,[aria-disabled="true"]') && visible(target));
                next = candidates.find(target => notice.compareDocumentPosition(target) & Node.DOCUMENT_POSITION_FOLLOWING)
                    || candidates.at(-1) || fallback();
            });
            notice.addEventListener('closed.bs.alert', () => {
                if (!restore || (document.activeElement !== document.body && visible(document.activeElement))) return;
                const target = visible(next) ? next : fallback();
                if (!visible(target)) return;
                if (!target.matches('a[href],button,input,select,textarea,[tabindex]')) target.tabIndex = -1;
                target.focus();
            });
            button.addEventListener('click', () => {
                if (notice.isConnected) Alert.getOrCreateInstance(notice).close();
            });
            button.hidden = false;
        });
    }
    function startEmbeds() {
        document.querySelectorAll('[data-website-embed]').forEach(button => {
            const block = button.closest('.website-embed');
            const target = block?.querySelector('.website-embed-frame');
            const status = block?.querySelector('.website-embed-status');
            const label = button.querySelector('[data-embed-label]');
            if (!target || !status || !label) return;
            button.addEventListener('click', () => {
                const expanded = button.getAttribute('aria-expanded') === 'true';
                target.replaceChildren();
                target.hidden = expanded;
                if (!expanded) {
                    const frame = document.createElement('iframe');
                    frame.title = button.dataset.embedTitle;
                    frame.referrerPolicy = 'strict-origin-when-cross-origin';
                    frame.allowFullscreen = true;
                    frame.allow = 'fullscreen; picture-in-picture';
                    frame.src = button.dataset.embedSource;
                    target.append(frame);
                }
                button.setAttribute('aria-expanded', String(!expanded));
                label.textContent = expanded ? button.dataset.loadLabel : button.dataset.hideLabel;
                status.textContent = expanded ? button.dataset.hiddenStatus : button.dataset.loadedStatus;
            });
            button.hidden = false;
        });
    }
    function startCarousels() {
        document.querySelectorAll('.website-carousel').forEach(carousel => {
            const inner = carousel.querySelector('.carousel-inner');
            const status = carousel.querySelector('[data-carousel-status]');
            if (!inner || !status) return;
            const slides = Array.from(inner.children);
            if (slides.length > 1) {
                window.bootstrap?.Carousel.getOrCreateInstance(carousel, { interval: false, ride: false, touch: false });
            }
            carousel.addEventListener('slid.bs.carousel', event => {
                if (event.target !== carousel || !slides.includes(event.relatedTarget)) return;
                status.textContent = status.dataset.slideStatusTemplate
                    .replace('{current}', String(slides.indexOf(event.relatedTarget) + 1))
                    .replace('{total}', String(slides.length));
            });
        });
    }
    let started = false;
    function start() {
        if (started) return;
        started = true;
        startNotices();
        startCarousels();
        startEmbeds();
    }
    if (document.readyState === 'complete') start();
    else {
        document.addEventListener('DOMContentLoaded', start, { once: true });
        window.addEventListener('load', start, { once: true });
    }
})();
