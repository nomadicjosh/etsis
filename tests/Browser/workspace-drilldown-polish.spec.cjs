const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const { workspaceTool } = require("./support/workspace-actions.cjs");
const id = (n) => `01K000000000000000000000${String(n).padStart(2, "0")}`;
async function fixture(page, { student = true } = {}) {
    const requests = [],
        errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    await page.route("**/sis/**", async (route) => {
        const request = route.request(),
            path = new URL(request.url()).pathname;
        requests.push({ path, method: request.method() });
        let data = { items: [], next_after: null };
        if (path === "/sis/ui/metrics/") data = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === "/sis/ui/data/students/")
            data.items = [
                {
                    id: id(1),
                    given_name: "Alex",
                    family_name: "Example",
                    institutional_id: "S-FICTIONAL",
                    active: true,
                },
            ];
        else if (path.endsWith("/record/"))
            data = {
                id: id(1),
                person_id: id(2),
                given_name: "Alex",
                family_name: "Example",
                institutional_id: "S-FICTIONAL",
                active: true,
                primary_program: { code: "ARTS.UG", title: "Undergraduate Arts" },
                entry_term: { code: "26/FA" },
            };
        else if (path.endsWith("/programs/"))
            data.items = [
                {
                    id: id(3),
                    program_code: "ARTS.UG",
                    program_title: "Undergraduate Arts",
                    term_code: "26/FA",
                    catalog_year: "2026–2027",
                    catalog_revision: 2,
                    is_primary: true,
                    status: "active",
                },
                {
                    id: id(4),
                    program_code: "SCI.UG",
                    program_title: "Undergraduate Sciences",
                    term_code: "25/FA",
                    catalog_year: "2025–2026",
                    catalog_revision: 1,
                    is_primary: false,
                    status: "closed",
                },
            ];
        else if (path === `/sis/ui/people/${id(2)}/`)
            data = {
                person: {
                    id: { value: id(2) },
                    givenName: "Alex",
                    familyName: "Example",
                    institutionalId: { value: "S-FICTIONAL" },
                    ownerUserId: null,
                },
            };
        else if (path.endsWith("/hiatuses/"))
            data = {
                student: {
                    given_name: "Alex",
                    family_name: "Example",
                    institutional_id: "S-FICTIONAL",
                },
                today: "2026-11-22",
                timezone: "America/Los_Angeles",
                items: [],
                next_after: null,
            };
        else if (path.endsWith("/registration-history/")) data = { items: [], next_after: null };
        await route.fulfill({ json: data, headers: { "Cache-Control": "private, no-store" } });
    });
    await page.goto("/admin/");
    if (student) {
        await page.locator('.workspace-nav-link[data-workspace="students"]').click();
        await page.getByLabel("Search this workspace", { exact: true }).fill("Alex");
        await page.getByLabel("Search this workspace", { exact: true }).press("Enter");
        await page.getByRole("button", { name: "Alex Example", exact: true }).click();
        await expect(
            page.getByRole("heading", { name: "Student programs", exact: true }),
        ).toBeVisible();
    }
    return { requests, errors };
}
async function accessible(page) {
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(
        scan.violations.map((v) => ({ id: v.id, targets: v.nodes.map((n) => n.target) })),
    ).toEqual([]);
}
async function containedAndHittable(page, menu) {
    await expect(menu).toBeVisible();
    const misaligned = await menu.locator('.dropdown-item').evaluateAll(items => items.flatMap(item => {
        const style = getComputedStyle(item);
        const first = [...item.childNodes].find(child => child.nodeType === Node.ELEMENT_NODE || child.textContent.trim());
        if (!first) return [];
        const range = document.createRange();
        range.selectNode(first);
        const start = first.nodeType === Node.ELEMENT_NODE ? first.getBoundingClientRect().left : range.getBoundingClientRect().left;
        const inset = item.getBoundingClientRect().left + parseFloat(style.paddingLeft) + parseFloat(style.borderLeftWidth);
        return Math.abs(start - inset) <= 1 && ['start', 'left'].includes(style.textAlign)
            ? [] : [{ label: item.textContent.trim(), offset: start - inset, justify: style.justifyContent, text: style.textAlign }];
    }));
    expect(misaligned).toEqual([]);
    await expect
        .poll(() =>
            menu.evaluate((el) => {
                const r = el.getBoundingClientRect();
                return (
                    r.left >= 0 &&
                    r.top >= 0 &&
                    r.right <= innerWidth + 1 &&
                    r.bottom <= innerHeight + 1
                );
            }),
        )
        .toBe(true);
    expect(
        await menu.evaluate((el) => {
            const r = el.getBoundingClientRect();
            return [r.left + 10, r.left + r.width / 2, r.right - 10].every((x) => {
                const hit = document.elementFromPoint(x, r.top + Math.min(30, r.height / 2));
                return hit === el || el.contains(hit);
            });
        }),
    ).toBe(true);
}
test("student program cards keep identity, primary status and actions readable", async ({
    page,
}) => {
    const f = await fixture(page);
    const programs = page.getByRole("group", { name: "Program assignments", exact: true });
    const active = programs.getByRole("article", { name: "ARTS.UG · 26/FA", exact: true });
    await expect(active).toContainText("Undergraduate Arts");
    await expect(active).toContainText("Primary");
    await expect(active).toContainText("2026–2027 · revision 2");
    await expect(
        active.getByRole("button", { name: "Degree requirements", exact: true }),
    ).toBeVisible();
    await expect(active.locator(".bi-mortarboard[aria-hidden=true]")).toHaveCount(1);
    await accessible(page);
    await page.screenshot({ path: "/tmp/etsis-student-drawer-after.png" });
    await page.setViewportSize({ width: 320, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
        true,
    );
    await accessible(page);
    await page.screenshot({ path: "/tmp/etsis-student-drawer-phone.png" });
    expect(f.errors).toEqual([]);
});
test("a child without a return action reopens its connected record with a fresh read", async ({
    page,
}) => {
    const f = await fixture(page);
    await page.getByRole("button", { name: "Hiatus history", exact: true }).click();
    await expect(page.locator("#drawer-title")).toHaveText("Student hiatus history");
    const nav = page.getByRole("navigation", { name: "Task navigation", exact: true });
    await expect(nav.getByRole("button", { name: "Back to record", exact: true })).toBeVisible();
    await expect(nav).toContainText("Student hiatus history");
    await page.screenshot({ path: "/tmp/etsis-nested-drawer-after.png" });
    await nav.getByRole("button", { name: "Back to record", exact: true }).click();
    await expect(page.locator("#drawer-title")).toHaveText("Alex Example");
    await expect(
        page.getByRole("group", { name: "Program assignments", exact: true }),
    ).toBeVisible();
    expect(f.requests.filter((r) => r.path.endsWith("/record/"))).toHaveLength(2);
    await expect(page.getByRole("button", { name: "Back to record", exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});
test("specific task returns take precedence and shared libraries have no unrelated record link", async ({
    page,
}) => {
    const f = await fixture(page);
    await page.getByRole("button", { name: "Registration history", exact: true }).click();
    const nav = page.getByRole("navigation", { name: "Task navigation", exact: true });
    await expect(nav.getByRole("button", { name: "Back to student", exact: true })).toBeVisible();
    await expect(page.getByRole("button", { name: "Back to record", exact: true })).toHaveCount(0);
    await nav.getByRole("button", { name: "Back to student", exact: true }).click();
    await page.getByRole("button", { name: "Close record", exact: true }).click();
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    await workspaceTool(page, "Subjects");
    await expect(page.locator("#drawer-title")).toHaveText("Course subjects");
    await expect(page.getByRole("button", { name: "Back to record", exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});
test("dropdowns stay on screen and above the sidebar across viewport sizes and zoom", async ({
    page,
}) => {
    const f = await fixture(page, { student: false });
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    const toggle = page
        .locator("#directory-tools")
        .getByRole("button", { name: "Curriculum", exact: true });
    for (const width of [1440, 1024, 991, 768, 375, 320]) {
        await page.setViewportSize({ width, height: 640 });
        await toggle.focus();
        await page.keyboard.press("ArrowDown");
        const menu = page.locator("#" + (await toggle.getAttribute("aria-controls")));
        await containedAndHittable(page, menu);
        await expect(menu.locator('.dropdown-item:not(:has(> .bi))')).toHaveCount(0);
        await expect(
            menu.getByRole("button", { name: "Course group library", exact: true }),
        ).toBeFocused();
        await page.keyboard.press("Escape");
        await expect(toggle).toBeFocused();
    }
    await page.setViewportSize({ width: 1024, height: 640 });
    await page.addStyleTag({ content: "html {font-size:200% !important;}" });
    await toggle.click();
    await containedAndHittable(
        page,
        page.locator("#" + (await toggle.getAttribute("aria-controls"))),
    );
    await page.screenshot({ path: "/tmp/etsis-dropdown-zoom.png" });
    await page.keyboard.press("Escape");
    expect(f.errors).toEqual([]);
});
test("card dropdowns remain reachable in a scrolling drawer and close cleanly with the task", async ({
    page,
}) => {
    const f = await fixture(page);
    await page.setViewportSize({ width: 375, height: 640 });
    const toggle = page.getByRole("button", {
        name: "More actions for SCI.UG · 25/FA",
        exact: true,
    });
    await toggle.scrollIntoViewIfNeeded();
    await toggle.click();
    const menu = page.locator("#" + (await toggle.getAttribute("aria-controls")));
    await containedAndHittable(page, menu);
    await expect(menu.locator('.dropdown-item:not(:has(> .bi))')).toHaveCount(0);
    await page.screenshot({ path: '/tmp/etsis-record-menu-left-aligned.png' });
    await page.locator("#drawer-body").evaluate((el) => {
        el.scrollTop -= 80;
    });
    await containedAndHittable(page, menu);
    await page.getByRole("button", { name: "Close record", exact: true }).click();
    await expect(page.locator(".workspace-floating-menu:popover-open")).toHaveCount(0);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});
test("Bootstrap dropdowns keep their keyboard behavior inside native dialog screens", async ({
    page,
}) => {
    const f = await fixture(page, { student: false });
    await page.evaluate(() => {
        const dialog = document.createElement("dialog");
        dialog.id = "fictional-menu-dialog";
        const wrap = document.createElement("div");
        wrap.className = "dropdown workspace-action-menu";
        const toggle = document.createElement("button");
        toggle.type = "button";
        toggle.className = "btn btn-primary dropdown-toggle";
        toggle.dataset.bsToggle = "dropdown";
        toggle.textContent = "Fictional dialog actions";
        const menu = document.createElement("ul");
        menu.className = "dropdown-menu";
        menu.id = "fictional-dialog-menu";
        const item = document.createElement("button");
        item.type = "button";
        item.className = "dropdown-item";
        item.textContent = "Fictional screen action";
        item.addEventListener("click", () => {
            dialog.dataset.chosen = "yes";
        });
        const row = document.createElement("li");
        row.append(item);
        menu.append(row);
        wrap.append(toggle, menu);
        dialog.append(wrap);
        document.body.append(dialog);
        dialog.showModal();
    });
    const toggle = page.getByRole("button", { name: "Fictional dialog actions", exact: true });
    await toggle.focus();
    await page.keyboard.press("ArrowDown");
    await containedAndHittable(page, page.locator("#fictional-dialog-menu"));
    await expect(
        page.getByRole("button", { name: "Fictional screen action", exact: true }),
    ).toBeFocused();
    await page.keyboard.press("Enter");
    await expect(page.locator("#fictional-menu-dialog")).toHaveAttribute("data-chosen", "yes");
    await toggle.click();
    await page.evaluate(() => document.querySelector("#fictional-menu-dialog").close());
    await expect(page.locator(".workspace-floating-menu:popover-open")).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test("linked records return to the originating record without accumulating a navigation loop", async ({
    page,
}) => {
    const f = await fixture(page);
    for (let count = 0; count < 3; count++) {
        await page.getByRole("button", { name: "Open connected person", exact: true }).click();
        await expect(page.locator("#drawer-title")).toHaveText("Person workspace");
        await page
            .getByRole("navigation", { name: "Task navigation", exact: true })
            .getByRole("button", { name: "Back to record", exact: true })
            .click();
        await expect(page.locator("#drawer-title")).toHaveText("Alex Example");
        await expect(page.getByRole("button", { name: "Back to record", exact: true })).toHaveCount(
            0,
        );
    }
    expect(f.requests.filter((r) => r.path.endsWith("/record/"))).toHaveLength(4);
    expect(f.requests.filter((r) => r.path === `/sis/ui/people/${id(2)}/`)).toHaveLength(3);
    expect(f.errors).toEqual([]);
});
