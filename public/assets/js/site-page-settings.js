/* Only an unsaved-change flag is retained in memory. Native submission keeps CSRF and all fields. */
(() => {
    'use strict';
    const form = document.getElementById('site-page-form');
    if (!form) return;
    const snapshot = () => JSON.stringify([...new FormData(form)]);
    const original = snapshot();
    let submitting = false;
    window.addEventListener('beforeunload', event => {
        if (!submitting && snapshot() !== original) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    form.addEventListener('submit', () => {
        submitting = true;
        form.querySelectorAll('button[type="submit"]').forEach(button => { button.disabled = true; });
    });
    window.addEventListener('pageshow', () => {
        submitting = false;
        form.querySelectorAll('button[type="submit"]').forEach(button => { button.disabled = false; });
    });
})();
