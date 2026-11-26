/* Public block behavior uses the existing Bootstrap bundle and no inline script execution. */
(() => {
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
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', startCarousels, { once: true });
    else startCarousels();
})();
