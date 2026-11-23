const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (n) => String(n).padStart(26, "0");
async function fixture(page, options = {}) {
  const application = id(1),
    writes = [],
    errors = [];
  const scope = (active) => ({
    id: active ? id(7) : null,
    active,
    sequence: active ? 1 : 0,
    created_at: "2026-11-22T10:00:00.000000Z",
    reason: "Fictional private preservation reason.",
  });
  let holds = {
    revision: "a".repeat(64),
    scopes: { case: scope(false), person: scope(false) },
    history: [],
    next_cursor: null,
  };
  let confirmCount = 0,
    release,
    started;
  const pending = new Promise((resolve) => {
    started = resolve;
  });
  const held = new Promise((resolve) => {
    release = resolve;
  });
  page.on("pageerror", (error) => errors.push(error.message));
  await page.route("**/sis/**", async (route) => {
    const request = route.request(),
      path = new URL(request.url()).pathname;
    let result = { items: [], next_after: null };
    if (path === "/sis/ui/metrics/")
      result = { cards: [], intake: [], pipeline: [], queue: [] };
    else if (path.includes("/data/applications/"))
      result = {
        items: [
          {
            id: application,
            given_name: "Fictional",
            family_name: "Applicant",
            status: "submitted",
          },
        ],
        next_after: null,
      };
    else if (path.endsWith("/review/") && !path.includes("document-holds"))
      result = {
        application: {
          id: { value: application },
          status: "submitted",
          version: 0,
        },
        person: {
          id: { value: id(2) },
          givenName: "Fictional",
          familyName: "Applicant",
          institutionalId: { value: "EXAMPLE" },
        },
        labels: {
          program_code: "ARTS-UG",
          program_title: "Fictional Arts",
          term_code: "26/FA",
        },
      };
    else if (path === `/sis/applications/${application}/documents/`)
      result = {
        enabled: false,
        can_retain: true,
        upload_id: id(8),
        max_bytes: 5242880,
        retention_holds: holds,
        items: [
          {
            id: id(9),
            name: "fictional.pdf",
            status: "ready",
            version: 3,
            bytes: 50,
            created_at: "2025-01-01T00:00:00Z",
            expires_at: "2025-02-01T00:00:00Z",
            can_download: true,
            can_purge: !holds.scopes.person.active,
            scope_held: holds.scopes.person.active,
          },
        ],
      };
    else if (path.includes("/document-holds/")) {
      if (request.method() === "POST") {
        expect(request.headers()["x-csrf-token"]).toBe("fictional-test-token");
        const body = JSON.parse(
          new URLSearchParams(request.postData()).get("_workspace_json"),
        );
        writes.push({ path, body });
        if (path.endsWith("/review/")) {
          if (options.delayedReview) {
            started();
            await held;
          }
          result = {
            ...body,
            operation_id: id(20),
            review_token: "fictional-review-token",
          };
        } else {
          confirmCount++;
          if (options.denied) {
            await route.fulfill({
              status: 403,
              json: { error: "Access denied." },
            });
            return;
          }
          if (options.interrupted && confirmCount === 1) {
            await route.fulfill({
              status: 503,
              json: { error: "Fictional interruption." },
            });
            return;
          }
          holds = {
            ...holds,
            revision: "b".repeat(64),
            scopes: {
              ...holds.scopes,
              [body.scope]: scope(body.action === "place"),
            },
            history: [{ ...scope(true), scope: body.scope }],
            next_cursor: id(25),
          };
          result = { id: id(7), active: true };
        }
      } else
        result = new URL(request.url()).searchParams.has("before")
          ? {
              ...holds,
              history: [
                {
                  ...scope(false),
                  scope: "case",
                  release_reason: "Fictional release.",
                  released_at: "2026-11-23T10:00:00Z",
                },
              ],
              next_cursor: null,
            }
          : holds;
    }
    await route.fulfill({
      json: result,
      headers: { "Cache-Control": "private, no-store" },
    });
  });
  await page.goto("/admin/?workspace=applications");
  await page
    .locator('.workspace-nav-link[data-workspace="applications"]')
    .click();
  await page
    .getByRole("button", { name: "Fictional Applicant", exact: true })
    .click();
  await page.getByRole("button", { name: "Documents", exact: true }).click();
  await expect(
    page.getByRole("region", { name: "Document preservation", exact: true }),
  ).toBeVisible();
  return { writes, errors, pending, release };
}
async function review(page) {
  await page
    .getByRole("button", { name: "Place person hold", exact: true })
    .click();
  await page
    .getByLabel("Reason for hold", { exact: true })
    .fill("Fictional preservation.");
  await page
    .getByRole("button", { name: "Review hold change", exact: true })
    .click();
}

test("staff review scoped holds, preserve file controls, and page retained hold decisions", async ({
  page,
}) => {
  const f = await fixture(page);
  await review(page);
  await expect(
    page.getByRole("button", { name: "Confirm hold", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByLabel("Reason for hold", { exact: true }),
  ).toHaveJSProperty("readOnly", true);
  await page
    .getByRole("button", { name: "Revise hold change", exact: true })
    .click();
  await page
    .getByLabel("Reason for hold", { exact: true })
    .fill("Fictional revised reason.");
  await page
    .getByRole("button", { name: "Review hold change", exact: true })
    .click();
  await page.getByRole("button", { name: "Confirm hold", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Release person hold", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("A case or person hold protects this file from removal.", {
      exact: true,
    }),
  ).toBeVisible();
  expect(f.writes.at(-1).body.reason).toBe("Fictional revised reason.");
  await page.getByRole("button", { name: "Hold history", exact: true }).click();
  await page
    .getByRole("button", { name: "Earlier holds", exact: true })
    .click();
  await expect(
    page.getByText("Fictional release.", { exact: true }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Newer holds", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Earlier holds", exact: true }),
  ).toBeVisible();
  await page.screenshot({ path: "/tmp/etsis-document-holds-desktop.png" });
  expect(f.errors).toEqual([]);
});

test("an interrupted hold confirmation retries the exact proof and protects the unfinished review", async ({
  page,
}) => {
  const f = await fixture(page, { interrupted: true });
  await review(page);
  await page
    .getByRole("button", { name: "Refresh documents", exact: true })
    .click();
  await expect(
    page.getByText("Finish or cancel the document hold change first.", {
      exact: true,
    }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Confirm hold", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Confirm hold", exact: true }),
  ).toBeEnabled();
  await page.getByRole("button", { name: "Confirm hold", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Release person hold", exact: true }),
  ).toBeVisible();
  const confirmations = f.writes.filter((item) =>
    item.path.endsWith("/confirm/"),
  );
  expect(confirmations).toHaveLength(2);
  expect(confirmations[0].body).toEqual(confirmations[1].body);
  expect(f.errors).toEqual([]);
});

test("lost retention authority clears hold reasons and mutation controls", async ({
  page,
}) => {
  const f = await fixture(page, { denied: true });
  await review(page);
  await page.getByRole("button", { name: "Confirm hold", exact: true }).click();
  await expect(
    page.getByText(
      "Application documents are no longer available with your current access.",
      { exact: true },
    ),
  ).toBeVisible();
  await expect(
    page.getByRole("region", { name: "Document preservation", exact: true }),
  ).toHaveCount(0);
  expect(f.errors).toEqual([]);
});

test("a late hold review cannot inject controls after the application drawer closes", async ({
  page,
}) => {
  const f = await fixture(page, { delayedReview: true });
  await review(page);
  await f.pending;
  await page.getByRole("button", { name: "Close record", exact: true }).click();
  f.release();
  await expect(page.locator("#record-drawer")).not.toHaveClass(/show/);
  await expect(
    page.getByRole("button", { name: "Confirm hold", exact: true }),
  ).not.toBeVisible();
  expect(f.errors).toEqual([]);
});

test("preservation controls support keyboard use, phone reflow, enlarged text and accessible labels", async ({
  page,
}) => {
  const f = await fixture(page);
  await page.setViewportSize({ width: 320, height: 800 });
  await page.addStyleTag({ content: "html { font-size: 200%; }" });
  await page
    .getByRole("button", { name: "Place person hold", exact: true })
    .focus();
  await page.keyboard.press("Enter");
  await expect(
    page.getByLabel("Reason for hold", { exact: true }),
  ).toBeFocused();
  await page
    .getByLabel("Reason for hold", { exact: true })
    .fill("Fictional keyboard review.");
  await page
    .getByRole("button", { name: "Review hold change", exact: true })
    .focus();
  await page.keyboard.press("Enter");
  await expect(
    page.getByRole("button", { name: "Confirm hold", exact: true }),
  ).toBeFocused();
  const scan = await new AxeBuilder({ page })
    .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
    .analyze();
  expect(scan.violations.map((item) => item.id)).toEqual([]);
  expect(
    await page
      .locator("#drawer-body")
      .evaluate((el) => el.scrollWidth <= el.clientWidth),
  ).toBe(true);
  expect(
    (
      await page
        .getByRole("button", { name: "Confirm hold", exact: true })
        .boundingBox()
    ).width,
  ).toBeGreaterThan(150);
  await page.screenshot({ path: "/tmp/etsis-document-holds-phone.png" });
  expect(f.errors).toEqual([]);
});
