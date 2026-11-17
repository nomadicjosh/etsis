/** Open secondary actions through their visible disclosure rather than clicking hidden controls. */
async function workspaceTool(page, name) {
    return chooseAction(page.locator('#directory-tools'), name);
}
async function recordAction(page, name, { last = false } = {}) {
    const control = await revealRecordAction(page, name, { last });
    await control.click();
}
async function revealRecordAction(page, name, { last = false } = {}) {
    return revealAction(page.locator('#drawer-body'), name, last);
}
async function chooseAction(root, name, last = false) {
    const control = await revealAction(root, name, last);
    await control.click();
}
async function revealAction(root, name, last = false) {
    const matches = root.getByRole('button', { name, exact: typeof name === 'string', includeHidden: true });
    const control = last ? matches.last() : matches.first();
    await control.waitFor({ state: 'attached' });
    const menu = control.locator('xpath=ancestor::div[contains(concat(" ", normalize-space(@class), " "), " workspace-action-menu ")][1]');
    if (await menu.count() && !await control.isVisible()) {
        await menu.locator(':scope > button[data-bs-toggle="dropdown"]').click();
    }
    return control;
}
module.exports = { workspaceTool, recordAction, revealRecordAction };
