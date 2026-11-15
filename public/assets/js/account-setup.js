(() => {
    const token = location.hash.slice(1);
    history.replaceState(null, '', location.pathname);
    const form = document.getElementById('account-setup-form');
    if (!form) return;
    const valid = /^[a-f0-9]{64}$/.test(token);
    document.getElementById('account-setup-token').value = valid ? token : '';
    document.getElementById('account-setup-submit').disabled = !valid;
    document.getElementById('setup-link-error').hidden = valid;
    // Keep the fragment only in memory while correcting form errors; never persist it or include it in requests' URLs.
    form.addEventListener('submit', () => { if (valid) form.action = '/portal/password-setup/#' + token; });
})();
