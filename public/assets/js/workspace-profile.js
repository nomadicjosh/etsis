/* Native owner forms retain server validation; private history never restores account data. */
(() => {
    "use strict";
    const password = document.getElementById("profile-password");
    const confirmation = document.getElementById("profile-confirm-password");
    const error = document.getElementById("profile-password-error");
    const clearError = () => {
        confirmation.setCustomValidity("");
        confirmation.removeAttribute("aria-invalid");
        error.hidden = true;
    };
    password.addEventListener("input", clearError);
    confirmation.addEventListener("input", clearError);
    confirmation.form.addEventListener("submit", (event) => {
        if (password.value === confirmation.value) return;
        confirmation.setCustomValidity("The passwords must match.");
        confirmation.setAttribute("aria-invalid", "true");
        error.hidden = false;
        event.preventDefault();
        confirmation.reportValidity();
    });
    window.addEventListener("pagehide", () => {
        password.value = "";
        confirmation.value = "";
        document.getElementById("account-main")?.replaceChildren();
    });
    window.addEventListener("pageshow", (event) => {
        if (event.persisted) location.reload();
    });
})();
