const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (n) => `01K000000000000000000000${String(n).padStart(2, "0")}`;
async function accessible(page) {
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(
        scan.violations.map((v) => ({
            id: v.id,
            targets: v.nodes.map((n) => n.target),
        })),
    ).toEqual([]);
}
async function fixture(page, settings = {}) {
    const calls = [],
        errors = [],
        versions = [];
    const subject = id(1),
        course = id(2),
        publication = id(3);
    page.on("pageerror", (error) => errors.push(error.message));
    await page.route("**/sis/**", async (route) => {
        const req = route.request(),
            path = new URL(req.url()).pathname;
        const body = req.postData()
            ? JSON.parse(
                  new URLSearchParams(req.postData()).get("_workspace_json"),
              )
            : {};
        calls.push({ path, body, csrf: req.headers()["x-csrf-token"] });
        let data;
        if (path === "/sis/ui/metrics/")
            data = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith("/sis/ui/data/"))
            data = {
                items: path.includes("courses")
                    ? [
                          {
                              id: course,
                              code: "ENG.500",
                              title: "Independent study <b>fictional</b>",
                          },
                      ]
                    : [
                          {
                              id: id(10),
                              code: "26/FA",
                              title: "Fictional program",
                          },
                      ],
                next_after: null,
            };
        else if (path === "/sis/catalog/subjects/")
            data = {
                items: [
                    {
                        id: subject,
                        code: "ENG",
                        title: "English <script>fictional</script>",
                    },
                ],
                next_after: null,
            };
        else if (path === "/sis/course-rules/page/")
            data = {
                items: versions.length ? [versions.at(-1)] : [],
                next_after: null,
            };
        else if (path === "/sis/course-rules/save/") {
            data = {
                id: id(20 + versions.length),
                rule_id: versions[0]?.rule_id || id(20),
                code: body.code,
                title: body.title,
                revision: versions.length + 1,
                definition: {
                    tree: structuredClone(body.tree),
                    references: {
                        subject_id: {
                            [subject]: {
                                id: subject,
                                code: "ENG",
                                title: "English <script>fictional</script>",
                            },
                        },
                        course_id: {
                            [course]: {
                                id: course,
                                code: "ENG.500",
                                title: "Independent study <b>fictional</b>",
                            },
                        },
                    },
                },
            };
            versions.push(data);
        } else if (/\/sis\/course-rules\/[^/]+\/revisions\/$/.test(path))
            data = { items: [...versions].reverse(), next_before: null };
        else if (
            /^\/sis\/course-rules\/[^/]+\/$/.test(path) &&
            !path.endsWith("preview/")
        )
            data = versions.find((v) => path.includes(v.id));
        else if (path === `/sis/courses/${course}/publication/page/`)
            data = {
                items: [
                    {
                        version: 1,
                        version_id: publication,
                        code: "ENG.500",
                        title: "Retained independent study",
                    },
                ],
                next_cursor: null,
            };
        else if (path === "/sis/course-rules/preview/")
            data = {
                items: [
                    {
                        course_id: course,
                        version_id: publication,
                        code: "ENG.500",
                        title: "Retained independent study",
                        outcome: settings.unknown
                            ? "unknown"
                            : "does_not_match",
                    },
                ],
            };
        else throw new Error(`Unexpected course-rule fixture route ${path}`);
        await route.fulfill({
            json: data,
            headers: { "Cache-Control": "private, no-store" },
        });
    });
    await page.goto("/admin/");
    await page.locator('[data-workspace="rules"]').click();
    await page
        .getByRole("button", { name: "Course eligibility", exact: true })
        .click();
    return { calls, errors, versions, subject, course };
}
test("course rules use catalog pickers and retain exclusions in independently saved revisions", async ({
    page,
}) => {
    const f = await fixture(page);
    await page
        .getByLabel("Course rule code", { exact: true })
        .fill("DA.ENG.EXCEPT");
    await page
        .getByLabel("Course rule title", { exact: true })
        .fill("English except independent study <b>fictional</b>");
    await page
        .getByLabel("Course condition field", { exact: true })
        .selectOption("subject_id");
    await page
        .getByRole("button", { name: "Choose subject", exact: true })
        .click();
    await page
        .getByRole("button", {
            name: "ENG — English <script>fictional</script>",
            exact: true,
        })
        .click();
    await page
        .locator("#course-rule-builder")
        .getByRole("button", { name: "Add course condition", exact: true })
        .click();
    await page
        .getByLabel("Course condition field", { exact: true })
        .nth(1)
        .selectOption("course_id");
    await page
        .getByLabel("Course condition comparison", { exact: true })
        .nth(1)
        .selectOption("not_equal");
    await page
        .getByRole("button", { name: "Choose course", exact: true })
        .click();
    await page
        .getByRole("button", {
            name: "ENG.500 — Independent study <b>fictional</b>",
            exact: true,
        })
        .click();
    await page
        .getByRole("button", { name: "Save course-rule revision", exact: true })
        .click();
    await expect(page.locator("#course-rule-studio")).toContainText(
        "Revision 1 saved",
    );
    expect(
        f.versions[0].definition.tree.rules.map((r) => ({
            id: r.id,
            value: r.value,
            operator: r.operator,
        })),
    ).toEqual([
        { id: "subject_id", value: f.subject, operator: "equal" },
        { id: "course_id", value: f.course, operator: "not_equal" },
    ]);
    await page
        .getByLabel("Course rule title", { exact: true })
        .fill("Later title");
    await page
        .getByRole("button", { name: "Save course-rule revision", exact: true })
        .click();
    await expect(page.locator("#course-rule-studio")).toContainText(
        "Revision 2 saved",
    );
    expect(
        f.calls.filter((c) => c.path.endsWith("/save/"))[1].body
            .expected_revision,
    ).toBe(1);
    await page
        .getByRole("button", { name: "Browse saved revisions", exact: true })
        .click();
    await page
        .getByRole("button", {
            name: /DA.ENG.EXCEPT — English except independent study.*Revision 1/,
        })
        .click();
    await expect(
        page.getByLabel("Course rule title", { exact: true }),
    ).toHaveValue("English except independent study <b>fictional</b>");
    await expect(
        page.locator("#course-rule-studio script, #course-rule-studio b"),
    ).toHaveCount(0);
    expect(
        f.calls
            .filter((c) => c.path.endsWith("/save/"))
            .every((c) => c.csrf === "fictional-test-token"),
    ).toBe(true);
    expect(
        await page.evaluate(() => localStorage.length + sessionStorage.length),
    ).toBe(0);
    expect(f.errors).toEqual([]);
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
});
test("numeric-level preview uses an explicit publication and keeps missing history unknown", async ({
    page,
}) => {
    const f = await fixture(page, { unknown: true });
    await page
        .getByLabel("Course condition field", { exact: true })
        .selectOption("course_level_number");
    await page
        .getByLabel("Course condition comparison", { exact: true })
        .selectOption("greater");
    await page.getByLabel("Numeric course level", { exact: true }).fill("200");
    await page
        .getByRole("button", { name: "Choose preview course", exact: true })
        .click();
    await page
        .getByRole("button", {
            name: "ENG.500 — Independent study <b>fictional</b>",
            exact: true,
        })
        .click();
    await page
        .getByRole("button", {
            name: "Use publication 1 — ENG.500 — Retained independent study",
            exact: true,
        })
        .click();
    await page
        .getByRole("button", {
            name: "Preview selected publications",
            exact: true,
        })
        .click();
    await expect(page.locator("#course-rule-studio")).toContainText(
        "Needs review — retained classification is missing or not numeric",
    );
    expect(f.calls.filter((c) => c.path.endsWith("/preview/"))).toHaveLength(1);
    expect(
        f.calls.find((c) => c.path.endsWith("/preview/")).body.publications,
    ).toHaveLength(1);
    await page.getByLabel("Numeric course level", { exact: true }).fill("300");
    await expect(
        page.getByRole("heading", {
            name: "Selected publication preview",
            exact: true,
        }),
    ).toHaveCount(0);
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(f.errors).toEqual([]);
});
