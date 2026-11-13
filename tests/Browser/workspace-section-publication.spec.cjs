const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (n) => String(n).padStart(26, "0");

async function fixture(page, { bound = true, paged = false, unavailable = false, amendment = false, aiEnabled = true, aiFailure = false, moodle = false } = {}) {
  const section = id(1),
    course = id(2),
    term = id(3),
    publication = id(4);
  const row = {
    id: section,
    code: "MATH.101.A",
    course_id: course,
    term_id: term,
    course_code: "MATH.101",
    course_title: "Fictional Algebra",
    term_code: "26/FA",
    capacity: 20,
    active: 1,
    faculty_user_id: null,
    faculty_person_id: null,
    web_registration: 1,
    fee_minor: 2500,
    currency: "USD",
    delivery_mode: "online",
  };
  const definition = {
    course_id: course,
    course_version_id: publication,
    term_id: term,
    term_code: "26/FA",
    term_starts_on: "2026-08-01",
    term_ends_on: "2026-12-20",
    code: row.code,
    capacity: 20,
    faculty_user_id: null,
    faculty_person_id: null,
    delivery_mode: "online",
    fee_minor: 2500,
    currency: "USD",
    instruction_starts_on: "2026-08-24",
    instruction_ends_on: "2026-12-18",
  };
  const calls = [],
    errors = [];
  let latest = null;
  let selected = null;
  if (amendment) {
    selected = { version: 1, version_id: publication, definition: { ...definition } };
    latest = { version: 2, version_id: id(6), definition: { ...definition, capacity: 30, fee_minor: 5000, instruction_starts_on: '2026-09-01' },
      effective_at: '2026-11-10T12:00:00Z', recorded_at: '2026-11-10T12:00:01Z' };
  }
  page.on("pageerror", (error) => errors.push(error.message));
  await page.route("**/sis/**", async (route) => {
    const path = new URL(route.request().url()).pathname;
    const raw = route.request().postData();
    const data = raw
      ? JSON.parse(new URLSearchParams(raw).get("_workspace_json"))
      : null;
    if (data)
      calls.push({
        path,
        data,
        csrf: route.request().headers()["x-csrf-token"],
      });
    let result = { items: [], next_after: null };
    if (path === "/sis/ui/metrics/")
      result = { cards: [], intake: [], pipeline: [], queue: [] };
    else if (path.startsWith("/sis/ui/data/"))
      result = {
        items: path.includes("/sections/") ? [row] : [],
        next_after: null,
      };
    else if (path.endsWith('/assistance/')) {
      if (data && aiFailure) {
        await route.fulfill({ status: 503, json: { error: 'Optional assistance is unavailable.' } });
        return;
      }
      result = data ? { advice: { summary: '<script>window.sectionAiExecuted = true</script> Review saved delivery.', suggestions: ['Verify publication dates separately.'], description: null } } :
        { enabled: aiEnabled, label: 'Fictional provider', revision: 'a'.repeat(64), draft: { section_code: row.code, capacity: 20, delivery_mode: 'online', teaching_assignment_present: false, retained_course: bound ? { code: 'MATH.101', title: 'Fictional Algebra' } : null, term: { code: '26/FA', starts_on: '2026-08-01', ends_on: '2026-12-20' } } };
    }
    else if (path.endsWith("/publication/setup/"))
      result = {
        section: row,
        term: {
          id: term,
          code: "26/FA",
          starts_on: "2026-08-01",
          ends_on: "2026-12-20",
        },
        course_definition: bound
          ? {
              version_id: publication,
              definition: { code: "MATH.101", title: "Algebra" },
            }
          : null,
        latest,
        selected_offering: selected,
        has_student_records: false,
        publication_status: unavailable ? "unavailable" : latest ? "ready" : "unpublished",
        faculty_label: "Not assigned",
        moodle: { available: moodle, linked: false, request_state: null },
      };
    else if (path.endsWith("/publication/page/"))
      result = {
        items: latest
          ? [latest]
          : paged
            ? [
                {
                  version: data.cursor ? 21 : 1,
                  version_id: publication,
                  definition,
                  effective_at: "2026-11-09T12:00:00Z",
                  recorded_at: "2026-11-09T12:00:01Z",
                },
              ]
            : [],
        next_cursor: paged && !data.cursor ? "fictional-section-cursor" : null,
      };
    else if (path.endsWith("/publication/amendment-review/")) result = {
      previous: selected, selected: latest, enrolled: 3, seat_sequence: 7,
      faculty_label: 'Not assigned', reason: data.reason, review_token: 'fictional-amendment-proof',
    };
    else if (path.endsWith("/publication/amend/")) {
      selected = latest;
      result = { operation_id: id(11), first_version: 2, last_version: 2, event_ids: [id(12)] };
    }
    else if (path.endsWith("/publication/selection-page/")) result = {
      items: [{ action: 'selected', version: 1, selected: { version: 1, version_id: publication, definition },
        effective_at: '2026-11-10T12:00:00Z', recorded_at: '2026-11-10T12:00:01Z' }], next_cursor: null,
    };
    else if (path.endsWith("/publication/selection-review/")) result = {
      selected: { version: 1, version_id: publication, definition },
      faculty_label: 'Not assigned', reason: data.reason, review_token: 'fictional-offering-selection-proof',
    };
    else if (path.endsWith("/publication/select/")) {
      selected = { version: 1, version_id: publication, definition };
      result = { operation_id: id(9), first_version: 1, last_version: 1, event_ids: [id(10)] };
    }
    else if (path.endsWith("/publication/review/"))
      result = {
        expected_version: 0,
        definition: {
          ...definition,
          instruction_starts_on: data.instruction_starts_on,
          instruction_ends_on: data.instruction_ends_on,
        },
        reason: data.reason,
        create_in_moodle: data.create_in_moodle === true,
        faculty_label: "Not assigned",
        review_token: "fictional-section-publication-proof",
      };
    else if (path.endsWith("/publication/commit/")) {
      const reviewed = calls
        .filter((call) => call.path.endsWith("/publication/review/"))
        .at(-1).data;
      latest = {
        version: 1,
        version_id: publication,
        definition: { ...definition, ...reviewed },
        effective_at: "2026-11-09T12:00:00Z",
        recorded_at: "2026-11-09T12:00:01Z",
      };
      result = {
        operation_id: publication,
        first_version: 1,
        last_version: 1,
        event_ids: [id(5)],
        moodle_creation_queued: reviewed.create_in_moodle === true,
      };
    }
    await route.fulfill({
      json: result,
      headers: { "Cache-Control": "private, no-store" },
    });
  });
  await page.goto("/admin/");
  await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
  await page.getByRole("link", { name: "Sections", exact: true }).click();
  await page.getByRole("button", { name: "MATH.101.A", exact: true }).click();
  await page
    .getByRole("button", { name: "Published section details", exact: true })
    .click();
  return { section, publication, calls, errors };
}
async function accessible(page) {
  const scan = await new AxeBuilder({ page })
    .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
    .analyze();
  expect(
    scan.violations.map((item) => ({
      id: item.id,
      targets: item.nodes.map((node) => node.target),
    })),
  ).toEqual([]);
}

test("section publication preserves dates and reason through revision, then confirms only the reviewed proof", async ({
  page,
}) => {
  const f = await fixture(page);
  await expect(page.getByLabel("Instruction starts on")).toHaveValue(
    "2026-08-01",
  );
  await expect(page.locator("#drawer-body")).toContainText(
    "Term dates are suggested starting values",
  );
  await page.getByLabel("Instruction starts on").fill("2026-07-20");
  await page.getByLabel("Instruction ends on").fill("2026-12-18");
  await page
    .getByLabel("Reason for section publication")
    .fill("Fictional <b>short-session</b> approval");
  await page
    .getByRole("button", { name: "Review section publication", exact: true })
    .click();
  await expect(page.locator("#drawer-body")).toContainText(
    "These instructional dates extend beyond the selected term",
  );
  await expect(page.locator("#drawer-body")).toContainText("Online");
  expect(f.calls.some((call) => call.path.endsWith("/commit/"))).toBe(false);
  expect(await page.locator("#drawer-body b").count()).toBe(0);
  await page
    .getByRole("button", { name: "Revise section publication", exact: true })
    .click();
  await expect(page.getByLabel("Instruction starts on")).toHaveValue(
    "2026-07-20",
  );
  await expect(page.getByLabel("Instruction ends on")).toHaveValue(
    "2026-12-18",
  );
  await expect(page.getByLabel("Reason for section publication")).toHaveValue(
    "Fictional <b>short-session</b> approval",
  );
  await page
    .getByRole("button", { name: "Review section publication", exact: true })
    .click();
  await page.setViewportSize({ width: 375, height: 812 });
  await accessible(page);
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  await page
    .getByRole("button", {
      name: "Publish reviewed section details",
      exact: true,
    })
    .focus();
  await page.keyboard.press("Enter");
  await expect(page.locator("#drawer-body")).toContainText(
    "Version 1 is retained",
  );
  expect(f.calls.find((call) => call.path.endsWith("/commit/"))).toMatchObject({
    data: { review_token: "fictional-section-publication-proof" },
    csrf: "fictional-test-token",
  });
  await page
    .getByRole("button", { name: "View section publications", exact: true })
    .click();
  await expect(page.getByLabel("Instruction starts on")).toHaveValue(
    "2026-07-20",
  );
  await page
    .getByText("Version 1 · MATH.101.A · 2026-07-20", { exact: true })
    .click();
  await expect(page.locator("#drawer-body")).toContainText(
    "2026-07-20 through 2026-12-18",
  );
  expect(
    await page.evaluate(() => [localStorage.length, sessionStorage.length]),
  ).toEqual([0, 0]);
  expect(f.errors).toEqual([]);
  await accessible(page);
});

test("paged section history keeps an unsaved publication draft", async ({
  page,
}) => {
  const f = await fixture(page, { paged: true });
  await page.getByLabel("Instruction starts on").fill("2026-08-24");
  await page
    .getByLabel("Reason for section publication")
    .fill("Fictional draft to retain");
  await page
    .getByRole("button", { name: "Next section versions page", exact: true })
    .click();
  await expect(page.locator("#drawer-body")).toContainText("Version 21");
  await expect(page.getByLabel("Instruction starts on")).toHaveValue(
    "2026-08-24",
  );
  await expect(page.getByLabel("Reason for section publication")).toHaveValue(
    "Fictional draft to retain",
  );
  await page
    .getByRole("button", {
      name: "Previous section versions page",
      exact: true,
    })
    .click();
  await expect(page.locator("#drawer-body")).toContainText("Version 1");
  await expect(page.getByLabel("Reason for section publication")).toHaveValue(
    "Fictional draft to retain",
  );
  expect(f.calls.some((call) => call.path.endsWith("/commit/"))).toBe(false);
  expect(f.errors).toEqual([]);
});

test("unbound sections guide staff to the required course selection", async ({
  page,
}) => {
  await fixture(page, { bound: false });
  await expect(page.locator("#drawer-body")).toContainText(
    "Choose and retain the course definition first",
  );
  await expect(
    page.getByRole("button", { name: "Choose course definition", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", {
      name: "Review section publication",
      exact: true,
    }),
  ).toHaveCount(0);
  await accessible(page);
});

test("missing current projection is explained without presenting older dates as current", async ({ page }) => {
    const f = await fixture(page, { unavailable: true, paged: true });
    await expect(page.locator("#drawer-body")).toContainText("The latest published details are temporarily unavailable");
    await expect(page.getByLabel("Instruction starts on")).toHaveValue("2026-08-01");
    expect(f.calls.some(call => call.path.endsWith("/review/") || call.path.endsWith("/commit/"))).toBe(false);
    expect(f.errors).toEqual([]);
    await accessible(page);
});

test('offering selection is reviewed explicitly and remains selected after confirmation', async ({ page }) => {
    const f = await fixture(page, { paged: true });
    await page.getByText('Version 1 · MATH.101.A · 2026-08-24', { exact: true }).click();
    await page.getByRole('button', { name: 'Use this version for new registrations', exact: true }).click();
    await page.getByLabel('Reason for offering selection').fill('Fictional registrar approval');
    await page.getByRole('button', { name: 'Review offering selection', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('New registrations will retain this offering version');
    expect(f.calls.some(call => call.path.endsWith('/select/'))).toBe(false);
    await page.getByRole('button', { name: 'Revise offering selection', exact: true }).click();
    await expect(page.getByLabel('Reason for offering selection')).toHaveValue('Fictional registrar approval');
    await page.getByRole('button', { name: 'Review offering selection', exact: true }).click();
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Confirm offering selection', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('#drawer-body')).toContainText('New registrations retain section version 1');
    await expect(page.getByRole('button', { name: 'Use this version for new registrations', exact: true })).toHaveCount(0);
    expect(f.calls.find(call => call.path.endsWith('/select/'))).toMatchObject({
      data: { review_token: 'fictional-offering-selection-proof' }, csrf: 'fictional-test-token',
    });
    expect(await page.evaluate(() => [localStorage.length, sessionStorage.length])).toEqual([0, 0]);
    expect(f.errors).toEqual([]);
    await accessible(page);
});

test('future offering amendments show prior and proposed terms without rebinding existing students', async ({ page }) => {
    const f = await fixture(page, { amendment: true });
    await page.getByText('Version 2 · MATH.101.A · 2026-09-01', { exact: true }).click();
    await page.getByRole('button', { name: 'Use this version for future registrations', exact: true }).click();
    await page.getByLabel('Reason for future offering amendment').fill('Fictional future-cohort approval');
    await page.getByRole('button', { name: 'Review future offering amendment', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Previously selected offering');
    await expect(page.locator('#drawer-body')).toContainText('3 currently enrolled students keep their recorded offering references and charges');
    await expect(page.locator('#drawer-body')).toContainText('2026-08-24');
    await expect(page.locator('#drawer-body')).toContainText('2026-09-01');
    expect(f.calls.some(call => call.path.endsWith('/amend/'))).toBe(false);
    await page.getByRole('button', { name: 'Revise future offering amendment', exact: true }).click();
    await expect(page.getByLabel('Reason for future offering amendment')).toHaveValue('Fictional future-cohort approval');
    await page.getByRole('button', { name: 'Review future offering amendment', exact: true }).click();
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Confirm future offering amendment', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('#drawer-body')).toContainText('New registrations retain section version 2');
    expect(f.calls.find(call => call.path.endsWith('/amend/'))).toMatchObject({
      data: { review_token: 'fictional-amendment-proof' }, csrf: 'fictional-test-token',
    });
    await page.getByRole('button', { name: 'Offering selection history', exact: true }).click();
    await page.getByText('Initial selection · publication version 1', { exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('2026-08-24');
    await accessible(page);
    expect(await page.evaluate(() => [localStorage.length, sessionStorage.length])).toEqual([0, 0]);
    expect(f.errors).toEqual([]);
});


test('section readiness is explicitly consented, text-only and preserves unsaved publication inputs', async ({ page }) => {
  const f = await fixture(page);
  await page.getByLabel('Instruction starts on').fill('2026-09-02');
  await page.getByLabel('Reason for section publication').fill('Fictional private reason.');
  await page.getByRole('button', { name: 'Optional section AI help', exact: true }).click();
  await expect(page.getByText(/This checks saved setup only/)).toBeVisible();
  await page.getByText('Inspect saved section facts to be sent', { exact: true }).click();
  await expect(page.locator('#drawer-body pre')).not.toContainText('Fictional private reason.');
  const send = page.getByRole('button', { name: 'Suggest saved section checks', exact: true });
  await send.click();
  expect(f.calls.filter(c => c.path.endsWith('/assistance/'))).toHaveLength(0);
  const consent = page.getByRole('checkbox', { name: /I agree to send the displayed saved section facts/ });
  await consent.check();
  await send.click();
  await expect(page.getByText('Suggestions to review', { exact: true })).toBeVisible();
  await expect(consent).not.toBeChecked();
  await expect(page.getByLabel('Instruction starts on')).toHaveValue('2026-09-02');
  await expect(page.getByLabel('Reason for section publication')).toHaveValue('Fictional private reason.');
  expect(f.calls.filter(c => c.path.endsWith('/assistance/'))).toEqual([{ path: `/sis/sections/${f.section}/assistance/`, csrf: 'fictional-test-token', data: { consent: true, revision: 'a'.repeat(64) } }]);
  expect(f.calls.some(c => c.path.endsWith('/commit/'))).toBe(false);
  expect(await page.evaluate(() => window.sectionAiExecuted)).toBeUndefined();
  await page.setViewportSize({ width: 375, height: 812 });
  await accessible(page);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
  expect(f.errors).toEqual([]);
});

test('section assistance works before course binding and remains optional during outages', async ({ page }) => {
  const f = await fixture(page, { bound: false, aiFailure: true });
  await page.getByRole('button', { name: 'Optional section AI help', exact: true }).click();
  await page.getByRole('checkbox', { name: /I agree to send the displayed saved section facts/ }).check();
  await page.getByRole('button', { name: 'Suggest saved section checks', exact: true }).click();
  await expect(page.getByText('No suggestion was applied. Another request requires fresh consent.', { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Choose course definition', exact: true })).toBeVisible();
  expect(f.calls.some(c => c.path.endsWith('/commit/'))).toBe(false);
  expect(f.errors).toEqual([]);
});

test('Moodle course creation is an explicit retained publication option and confirmation sends only its proof', async ({ page }) => {
  const f = await fixture(page, { moodle: true });
  const choice = page.getByRole('checkbox', { name: 'Create this section’s course in Moodle', exact: true });
  await expect(choice).not.toBeChecked();
  await choice.focus(); await page.keyboard.press('Space');
  await page.getByLabel('Reason for section publication').fill('Publish offering with its learning space.');
  await page.getByRole('button', { name: 'Review section publication', exact: true }).click();
  await expect(page.locator('#drawer-body')).toContainText('queues creation and linking of the Moodle course');
  expect(f.calls.some(call => call.path.endsWith('/commit/'))).toBe(false);
  await page.getByRole('button', { name: 'Revise section publication', exact: true }).click();
  await expect(choice).toBeChecked();
  await page.getByRole('button', { name: 'Review section publication', exact: true }).click();
  await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
  await page.getByRole('button', { name: 'Publish reviewed section details', exact: true }).click();
  await expect(page.locator('#drawer-body')).toContainText('Moodle course creation is queued');
  expect(f.calls.find(call => call.path.endsWith('/publication/review/')).data.create_in_moodle).toBe(true);
  expect(f.calls.find(call => call.path.endsWith('/publication/commit/')).data).toEqual({ review_token: 'fictional-section-publication-proof' });
  expect(f.calls.find(call => call.path.endsWith('/publication/commit/')).csrf).toBe('fictional-test-token');
  expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
  expect(f.errors).toEqual([]);
});
