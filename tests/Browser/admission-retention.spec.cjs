const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (n) => String(n).padStart(26, "0");
async function fixture(page, options = {}) {
    const errors = [],
        writes = [],
        category = id(10),
        application = id(1),
        document = id(9);
    let policy = {
        id: options.existing ? id(40) : null,
        revision: options.existing ? 1 : 0,
        definition: {
            default_id: options.existing ? category : null,
            categories: options.existing
                ? [
                      {
                          id: category,
                          code: "WORKING",
                          title: "Fictional working files",
                          days: 730,
                          active: true,
                      },
                  ]
                : [],
        },
        created_at: "2026-11-22T10:00:00Z",
    };
    let basis = {
        source: "deployment",
        policy_id: null,
        policy_revision: 0,
        days: 365,
        category: null,
    };
    let version = 3,
        count = 0,
        confirmations = 0,
        proof,
        started,
        release;
    const pending = new Promise((resolve) => {
        started = resolve;
    });
    const held = new Promise((resolve) => {
        release = resolve;
    });
    const history = [
        {
            id: id(9),
            source: "upload",
            actor_name: "Fictional applicant",
            recorded_at: "2026-11-22T10:00:00Z",
            before: null,
            after: basis,
            expires_after: "2027-11-22T10:00:00Z",
            reason: null,
        },
    ];
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
        else if (path === `/sis/applications/${application}/review/`)
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
                    program_title: "Fictional arts",
                    term_code: "26/FA",
                },
            };
        else if (path === `/sis/applications/${application}/documents/`)
            result = {
                enabled: false,
                can_retain: true,
                upload_id: id(22),
                max_bytes: 5242880,
                items: [
                    {
                        id: document,
                        name: "fictional.pdf",
                        status: "ready",
                        version,
                        bytes: 50,
                        applicant_visible: false,
                        held: false,
                        can_download: true,
                        can_purge: false,
                        created_at: "2026-11-22T10:00:00Z",
                        expires_at: "2028-11-22T10:00:00Z",
                        retention_basis: basis,
                    },
                ],
            };
        else if (
            path === "/sis/document-retention/" &&
            request.method() === "GET"
        ) {
            if (options.delayed) {
                started();
                await held;
            }
            result = {
                ...policy,
                new_ids: [id(10), id(11), id(12)].filter(
                    (value) =>
                        !policy.definition.categories.some(
                            (item) => item.id === value,
                        ),
                ),
                deployment_days: 365,
                uploads_enabled: false,
            };
        } else if (path === "/sis/document-retention/history/")
            result = {
                items: [
                    {
                        ...policy,
                        actor_name: "Fictional records staff",
                        reason: "Fictional retained policy reason.",
                        recorded_at: "2026-11-22T10:00:00Z",
                    },
                ],
                next_cursor: null,
            };
        else if (
            path ===
            `/sis/applications/${application}/documents/${document}/category/`
        )
            result = { items: history, next_cursor: null };
        else if (
            request.method() === "POST" &&
            (path.includes("document-retention/") ||
                path.includes("/category/"))
        ) {
            expect(request.headers()["x-csrf-token"]).toBe(
                "fictional-test-token",
            );
            const body = JSON.parse(
                new URLSearchParams(request.postData()).get("_workspace_json"),
            );
            writes.push({ path, body });
            if (path.endsWith("/review/")) {
                proof = {
                    ...body,
                    operation_id: id(100 + ++count),
                    review_token: `fictional-review-${count}`,
                };
                if (path.includes("/category/")) {
                    const choice = policy.definition.categories.find(
                        (item) => item.id === body.category_id,
                    );
                    proof = {
                        ...proof,
                        before: basis,
                        after: {
                            source: "category",
                            days: choice.days,
                            policy_id: policy.id,
                            policy_revision: policy.revision,
                            category: {
                                id: choice.id,
                                code: choice.code,
                                title: choice.title,
                            },
                        },
                        revision: "b".repeat(64),
                        expires_before: "2028-11-22T10:00:00Z",
                        expires_after: "2028-11-22T10:00:00Z",
                    };
                }
                result = proof;
            } else {
                confirmations++;
                if (options.denied) {
                    await route.fulfill({
                        status: 403,
                        json: { error: "Fictional access denied." },
                    });
                    return;
                }
                if (options.interrupted && confirmations === 1) {
                    await route.fulfill({
                        status: 503,
                        json: { error: "Fictional interrupted response." },
                    });
                    return;
                }
                if (path.includes("/category/")) {
                    basis = body.after;
                    version++;
                    history.unshift({
                        id: body.operation_id,
                        source: "staff",
                        actor_name: "Fictional records staff",
                        recorded_at: "2026-11-22T11:00:00Z",
                        before: body.before,
                        after: body.after,
                        reason: body.reason,
                        expires_after: body.expires_after,
                    });
                    result = { id: document, version };
                } else {
                    policy = {
                        ...policy,
                        id: body.operation_id,
                        revision: body.revision + 1,
                        definition: body.definition,
                    };
                    result = { id: policy.id, revision: policy.revision };
                }
            }
        }
        await route.fulfill({
            json: result,
            headers: { "Cache-Control": "private, no-store" },
        });
    });
    await page.goto("/admin/");
    await page
        .locator('.workspace-nav-link[data-workspace="applications"]')
        .click();
    return {
        errors,
        writes,
        pending,
        release,
        category,
        async policy() {
            await page
                .getByRole("button", {
                    name: "Configure document retention",
                    exact: true,
                })
                .click();
            await expect(
                page.getByLabel("Default for new uploads", { exact: true }),
            ).toBeVisible();
        },
        async file() {
            await page
                .getByRole("button", {
                    name: "Fictional Applicant",
                    exact: true,
                })
                .click();
            await page
                .getByRole("button", { name: "Documents", exact: true })
                .click();
            await page
                .getByRole("button", { name: "File controls", exact: true })
                .click();
        },
    };
}
async function addPolicy(page, category) {
    await page
        .getByRole("button", { name: "Add retention category", exact: true })
        .click();
    await page.getByLabel("Category code", { exact: true }).fill("WORKING");
    await page
        .getByLabel("Category name", { exact: true })
        .fill("Fictional working files");
    await page
        .getByLabel("Retention days from upload", { exact: true })
        .fill("730");
    await page
        .getByLabel("Default for new uploads", { exact: true })
        .selectOption(category);
    await page
        .getByLabel("Reason for policy change", { exact: true })
        .fill("Fictional explicit policy change.");
}

test("staff configure reviewed categories and defaults without discarding drafts or changing saved files", async ({
    page,
}) => {
    const f = await fixture(page);
    await f.policy();
    await addPolicy(page, f.category);
    await page
        .getByRole("button", { name: "Back to Admissions", exact: true })
        .click();
    await expect(
        page.getByText(
            "Finish or discard retention changes before starting another task.",
            { exact: true },
        ),
    ).toBeVisible();
    await page
        .getByRole("button", { name: "Review retention change", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Revise retention change", exact: true })
        .click();
    await page
        .getByLabel("Default for new uploads", { exact: true })
        .selectOption("");
    await page
        .getByLabel("Category name", { exact: true })
        .fill("Fictional revised title");
    await expect(
        page.getByLabel("Default for new uploads", { exact: true }),
    ).toHaveValue("");
    await page
        .getByLabel("Default for new uploads", { exact: true })
        .selectOption(f.category);
    await page
        .getByLabel("Retention days from upload", { exact: true })
        .fill("1095");
    await page
        .getByRole("button", { name: "Review retention change", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Confirm retention change", exact: true })
        .click();
    await expect(page.getByLabel("Category name", { exact: true })).toHaveValue(
        "Fictional revised title",
    );
    await expect(
        page.getByLabel("Retention days from upload", { exact: true }),
    ).toHaveValue("1095");
    expect(f.writes.at(-1).body.definition.default_id).toBe(f.category);
    await page
        .getByRole("button", { name: "Earlier policies", exact: true })
        .click();
    await expect(
        page.getByText("Fictional retained policy reason.", { exact: true }),
    ).toBeVisible();
    await page.screenshot({ path: "/tmp/etsis-retention-policy-desktop.png" });
    expect(f.errors).toEqual([]);
});

test("classification preserves other file drafts and retries the exact accepted review after an interrupted response", async ({
    page,
}) => {
    const f = await fixture(page, { existing: true, interrupted: true });
    await f.file();
    await page
        .getByRole("button", { name: "Classify document", exact: true })
        .click();
    await page
        .getByLabel("Retention category", { exact: true })
        .selectOption(f.category);
    await page
        .getByLabel("Reason for classification", { exact: true })
        .fill("Fictional classification review.");
    await page
        .getByRole("button", { name: "Review retention change", exact: true })
        .click();
    await page.getByLabel("Show file to applicant", { exact: true }).check();
    await page
        .getByRole("button", { name: "Confirm retention change", exact: true })
        .click();
    expect(
        f.writes.filter((write) => write.path.endsWith("/confirm/")),
    ).toHaveLength(0);
    await page.getByLabel("Show file to applicant", { exact: true }).uncheck();
    await page
        .getByRole("button", { name: "Confirm retention change", exact: true })
        .click();
    await expect(
        page.getByRole("button", {
            name: "Confirm retention change",
            exact: true,
        }),
    ).toBeEnabled();
    await page
        .getByRole("button", { name: "Confirm retention change", exact: true })
        .click();
    await expect(
        page.getByRole("button", { name: "File controls", exact: true }),
    ).toHaveAttribute("aria-expanded", "false");
    const confirms = f.writes.filter((write) =>
        write.path.endsWith("/confirm/"),
    );
    expect(confirms).toHaveLength(2);
    expect(confirms[0].body).toEqual(confirms[1].body);
    await page
        .getByRole("button", { name: "File controls", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Classification history", exact: true })
        .click();
    await expect(
        page.getByText("Fictional classification review.", { exact: true }),
    ).toBeVisible();
    expect(f.errors).toEqual([]);
});

test("lost policy authority clears retained reasons and publication controls", async ({
    page,
}) => {
    const f = await fixture(page, { existing: true, denied: true });
    await f.policy();
    await page
        .getByRole("button", { name: "Earlier policies", exact: true })
        .click();
    await expect(
        page.getByText("Fictional retained policy reason.", { exact: true }),
    ).toBeVisible();
    await page
        .getByLabel("Reason for policy change", { exact: true })
        .fill("Fictional policy change.");
    await page
        .getByRole("button", { name: "Review retention change", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Confirm retention change", exact: true })
        .click();
    await expect(
        page.getByText("Retention access is no longer available.", {
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        page.getByText("Fictional retained policy reason.", { exact: true }),
    ).toHaveCount(0);
    await expect(
        page.getByRole("button", {
            name: "Confirm retention change",
            exact: true,
        }),
    ).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test("retention policy review supports keyboard focus enlarged text and phone reflow", async ({
    page,
}) => {
    const f = await fixture(page);
    await f.policy();
    await addPolicy(page, f.category);
    await page.setViewportSize({ width: 320, height: 900 });
    await page.addStyleTag({ content: "html { font-size: 200%; }" });
    await page
        .getByRole("button", { name: "Review retention change", exact: true })
        .focus();
    await page.keyboard.press("Enter");
    await expect(
        page.getByRole("button", {
            name: "Confirm retention change",
            exact: true,
        }),
    ).toBeFocused();
    await expect(
        page.getByRole("button", {
            name: "Confirm retention change",
            exact: true,
        }),
    ).toBeInViewport({ ratio: 1 });
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(scan.violations.map((item) => item.id)).toEqual([]);
    expect(
        await page
            .locator("#drawer-body")
            .evaluate((el) => el.scrollWidth <= el.clientWidth),
    ).toBe(true);
    await page.screenshot({ path: "/tmp/etsis-retention-policy-phone.png" });
    expect(f.errors).toEqual([]);
});

test("a closed policy drawer ignores its delayed setup response", async ({
    page,
}) => {
    const f = await fixture(page, { delayed: true });
    await page
        .getByRole("button", {
            name: "Configure document retention",
            exact: true,
        })
        .click();
    await f.pending;
    await page
        .getByRole("button", { name: "Close record", exact: true })
        .click();
    f.release();
    await expect(page.locator("#record-drawer")).not.toHaveClass(/show/);
    await expect(
        page.getByLabel("Default for new uploads", { exact: true }),
    ).not.toBeVisible();
    expect(f.errors).toEqual([]);
});

test("file classification review remains visible and accessible on a narrow enlarged screen", async ({
    page,
}) => {
    const f = await fixture(page, { existing: true });
    await f.file();
    await page
        .getByRole("button", { name: "Classify document", exact: true })
        .click();
    await page
        .getByLabel("Retention category", { exact: true })
        .selectOption(f.category);
    await page
        .getByLabel("Reason for classification", { exact: true })
        .fill("Fictional phone classification.");
    await page.setViewportSize({ width: 320, height: 900 });
    await page.addStyleTag({ content: "html { font-size: 200%; }" });
    await page
        .getByRole("button", { name: "Review retention change", exact: true })
        .focus();
    await page.keyboard.press("Enter");
    const confirm = page.getByRole("button", {
        name: "Confirm retention change",
        exact: true,
    });
    await expect(confirm).toBeFocused();
    await expect(confirm).toBeInViewport({ ratio: 1 });
    expect((await confirm.boundingBox()).width).toBeGreaterThan(150);
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(scan.violations.map((item) => item.id)).toEqual([]);
    expect(
        await page
            .locator("#drawer-body")
            .evaluate((el) => el.scrollWidth <= el.clientWidth),
    ).toBe(true);
    await page.screenshot({ path: "/tmp/etsis-retention-file-phone.png" });
    expect(f.errors).toEqual([]);
});
