const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
test.use({ timezoneId: "America/Los_Angeles" });

test("term policy preserves edits through review and history with private CSRF publication", async ({
  page,
}) => {
  const term = "01K00000000000000000000091",
    sent = [],
    errors = [];
  page.on("pageerror", (error) => errors.push(error.message));
  const definition = {
    term_id: term,
    term_code: "26/FA",
    opens_at: "2026-08-01 00:00:00",
    closes_at: "2026-08-31 23:59:59",
    course_limit: 5,
    timezone: "UTC",
  };
  await page.route("**/sis/**", async (route) => {
    const path = new URL(route.request().url()).pathname;
    const raw = route.request().postData();
    const input = raw
      ? JSON.parse(new URLSearchParams(raw).get("_workspace_json"))
      : null;
    if (input)
      sent.push({
        path,
        input,
        csrf: route.request().headers()["x-csrf-token"],
      });
    let data = { items: [], next_after: null };
    if (path.includes("/ui/metrics/"))
      data = { cards: [], intake: [], pipeline: [], queue: [] };
    if (path.includes("/ui/data/terms/"))
      data = {
        items: [
          {
            id: term,
            code: "26/FA",
            starts_on: "2026-08-24",
            ends_on: "2026-12-18",
          },
        ],
        next_after: null,
      };
    if (path.endsWith("/registration-window/"))
      data = {
        term: { id: term, code: "26/FA" },
        definition,
        version: 0,
        published: false,
      };
    if (path.endsWith("/registration-window/review/"))
      data = {
        definition: { ...definition, course_limit: input.course_limit },
        version: 1,
        review_token: "fictional-sealed-review",
      };
    if (path.endsWith("/registration-window/page/"))
      data = { items: [], next_cursor: null };
    if (path.endsWith("/registration-window/publish/"))
      data = { publication_id: term, version: 1 };
    await route.fulfill({
      json: data,
      headers: { "Cache-Control": "private, no-store" },
    });
  });
  await page.goto("/admin/");
  await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
  await page.getByRole("link", { name: "Terms", exact: true }).click();
  await page.getByRole("button", { name: "26/FA", exact: true }).click();
  await page
    .getByRole("button", { name: "Registration policy", exact: true })
    .click();
  await page.getByLabel("Maximum registered courses").fill("6");
  await page
    .getByLabel("Reason for publication")
    .fill("Fictional approved calendar.");
  await page
    .getByRole("button", { name: "Policy history", exact: true })
    .click();
  await expect(
    page.getByText("No published policy history.", { exact: true }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Back to policy", exact: true })
    .click();
  await expect(page.getByLabel("Maximum registered courses")).toHaveValue("6");
  await page
    .getByRole("button", { name: "Review policy", exact: true })
    .click();
  await expect(
    page.getByRole("heading", {
      name: "Review registration policy",
      exact: true,
    }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Revise", exact: true }).click();
  await expect(page.getByLabel("Reason for publication")).toHaveValue(
    "Fictional approved calendar.",
  );
  await page.setViewportSize({ width: 375, height: 812 });
  const axe = await new AxeBuilder({ page })
    .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
    .analyze();
  expect(
    axe.violations.map((item) => ({
      id: item.id,
      targets: item.nodes.map((n) => n.target),
    })),
  ).toEqual([]);
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  await page
    .getByRole("button", { name: "Review policy", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Publish policy", exact: true })
    .click();
  await expect
    .poll(() => sent.filter((item) => item.path.endsWith("/publish/")).length)
    .toBe(1);
  expect(
    sent.find((item) => item.path.endsWith("/review/")).input.course_limit,
  ).toBe(6);
  expect(sent.find(item => item.path.endsWith("/review/")).input.closes_at)
    .toBe("2026-08-31T23:59:59+00:00");
  expect(
    sent
      .filter((item) => item.path.includes("/registration-window/"))
      .every((item) => item.csrf === "fictional-test-token"),
  ).toBe(true);
  expect(errors).toEqual([]);
});


test('institution configures faculty and student overlap policies separately', async ({ page }) => {
  const { workspaceTool } = require('./support/workspace-actions.cjs');
  const calls = [], errors = [];
  let saved = false;
  page.on('pageerror', error => errors.push(error.message));
  await page.route('**/sis/**', async route => {
    const path = new URL(route.request().url()).pathname;
    const raw = route.request().postData();
    const input = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
    let result = { items: [], next_after: null };
    if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
    if (path === '/sis/registration-policy/') {
      if (input) { calls.push({ input, csrf: route.request().headers()['x-csrf-token'] }); saved = true; }
      result = { mode: 'block', revision: saved ? 1 : 0, faculty_mode: saved ? 'warn' : 'block',
        corequisites: { prior_completion: true, drop_mode: 'block', revision: 0 } };
    }
    await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
  });
  await page.goto('/admin/registration-policy-fixture/');
  await page.locator('.workspace-nav-link[data-workspace="registration"]').click();
  await workspaceTool(page, 'Registration timetable policy');
  await page.getByLabel('When a faculty member’s published classes overlap').selectOption({ label: 'warn — Warn and allow publication' });
  await page.getByRole('button', { name: 'Save policy', exact: true }).click();
  await expect.poll(() => calls.length).toBe(1);
  expect(calls[0].input).toMatchObject({ mode: 'block', faculty_mode: 'warn', revision: 0 });
  expect(calls[0].csrf).toBe('fictional-test-token');
  await expect(page.getByLabel('When a faculty member’s published classes overlap')).toHaveValue('warn — Warn and allow publication');
  expect((await new AxeBuilder({ page }).include('#record-drawer').analyze()).violations).toEqual([]);
  expect(errors).toEqual([]);
});
