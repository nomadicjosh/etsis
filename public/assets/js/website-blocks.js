/* Public block behavior uses the existing Bootstrap bundle and no inline script execution. */
(() => {
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
    function start() {
        startCarousels();
        startEmbeds();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
})();
