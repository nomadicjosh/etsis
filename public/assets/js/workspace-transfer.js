/* Registrar transfer workbench. Catalog grants and transcript-request grants remain separate. */
window.createWorkspaceTransfer = function (ui) {
  "use strict";
  const {
    node,
    request,
    task,
    can,
    openDrawer,
    openRecord,
    table,
    flow,
    showAudit,
  } = ui;
  const button = (label, callback, style = "btn-outline-primary") =>
    node(
      "button",
      {
        type: "button",
        class: `btn ${style}`,
        onclick: (event) => task(callback, event.currentTarget),
      },
      label,
    );
  const grid = (headers, rows, caption) =>
    node(
      "div",
      {
        class: "table-responsive",
        tabindex: "0",
        role: "region",
        "aria-label": caption,
      },
      table(headers, rows, caption),
    );
  const details = (entries) =>
    node(
      "dl",
      { class: "detail-list" },
      entries.map(([label, value]) =>
        node("div", {}, node("dt", {}, label), node("dd", {}, value)),
      ),
    );
  const rules = [
    ["starts_on", "Effective from", "date"],
    ["ends_on", "Effective through (optional)", "date", "", false],
    ["minimum_grade_rank", "Minimum external grade rank (0–10000)", "number"],
    ["credits_milli", "Credits awarded", "credits"],
    ["awarded_grade", "Awarded grade code"],
  ];
  function constrain(form) {
    form.elements.credits_milli.min = 0.001;
    form.elements.credits_milli.max = 999.999;
    form.elements.awarded_grade.maxLength = 12;
    const dates = () => {
      form.elements.ends_on.min = form.elements.starts_on.value;
    };
    form.elements.starts_on.addEventListener("input", dates);
    dates();
  }
  async function pages(container, label, fetchPage, render) {
    let after = "",
      nextAfter = null,
      serial = 0;
    const previousCursors = [];
    const information = node("p", {
        role: "status",
        "aria-live": "polite",
        class: "form-text",
      }),
      content = node("div");
    const previous = button(`Previous ${label} page`, async () => {
      after = previousCursors.pop() || "";
      await load();
    });
    const next = button(`Next ${label} page`, async () => {
      previousCursors.push(after);
      after = nextAfter;
      await load();
    });
    container.append(
      information,
      content,
      node("div", { class: "inline-actions" }, previous, next),
    );
    async function load() {
      const token = ++serial,
        page = await fetchPage(after);
      if (!container.isConnected || token !== serial) return;
      nextAfter = page.next_after;
      previous.disabled = !previousCursors.length;
      next.disabled = !nextAfter;
      information.textContent = `${page.items.length} records on this page.${nextAfter ? " More are available." : ""}`;
      content.replaceChildren(render(page.items));
    }
    await load();
  }
  function summary(value) {
    return details([
      [
        "Sending institution",
        `${value.institution_code} · ${value.institution_name}`,
      ],
      ["External course", `${value.external_code} · ${value.external_title}`],
      ["Institutional course", `${value.course_code} · ${value.course_title}`],
      [
        "Effective completion dates",
        `${value.starts_on} through ${value.ends_on || "open-ended"}`,
      ],
      [
        "Credits / awarded grade",
        `${Number(value.credits_milli) / 1000} · ${value.awarded_grade}`,
      ],
      ["Grade-rank minimum", value.minimum_grade_rank],
      [
        "GPA treatment",
        Number(value.counts_gpa)
          ? `${Number(value.points_milli) / 1000} grade points per credit`
          : "Excluded from GPA",
      ],
    ]);
  }
  async function record(kind, id) {
    const value = await request(`/sis/ui/transfer/${kind}/${id}/`),
      back = () => record(kind, id);
    const body = openDrawer(
      kind === "equivalencies"
        ? `${value.external_code} → ${value.course_code}`
        : value.code,
      "TRANSFER CATALOG",
    );
    if (kind === "equivalencies") body.append(summary(value));
    else
      body.append(
        details(
          kind === "institutions"
            ? [
                ["Institution", value.name],
                ["Code", value.code],
              ]
            : [
                ["Course", value.title],
                ["Code", value.code],
                [
                  "Sending institution",
                  `${value.institution_code} · ${value.institution_name}`,
                ],
              ],
        ),
      );
    if (can("sis:audit:read"))
      body.append(button("View audit history", () => showAudit(kind, id)));
    if (kind !== "institutions")
      body.append(
        button("Back to sending record", () =>
          record(
            kind === "external" ? "institutions" : "external",
            kind === "external"
              ? value.institution_id
              : value.external_course_id,
          ),
        ),
      );
    if (kind !== "equivalencies") {
      body.append(
        button(
          kind === "institutions" ? "Edit institution" : "Edit external course",
          async () => {
            const form = await flow(
              "Save transfer catalog record",
              [
                ["code", "Code"],
                [
                  kind === "institutions" ? "name" : "title",
                  kind === "institutions" ? "Institution name" : "Course title",
                ],
              ],
              `/sis/ui/transfer/${kind}/${id}/edit/`,
              (data) => ({ ...data, revision: value.revision }),
              back,
              value,
              "Names and codes stay connected through their existing identities. Existing awarded course snapshots are preserved. Sending-institution links cannot be reassigned.",
            );
            form.elements.code.maxLength = 80;
          },
        ),
      );
      body.append(
        button(
          kind === "institutions"
            ? "Add course at this institution"
            : "Define equivalency for this course",
          async () => {
            if (kind === "institutions") {
              const form = await flow(
                "Add external course",
                [
                  ["code", "External course code"],
                  ["title", "External course title"],
                ],
                "/sis/external-courses/",
                (data) => ({ ...data, institution_id: id }),
                back,
                {},
                `Sending institution: ${value.name}. Use the institution's course code as recorded on its transcript.`,
              );
              form.elements.code.maxLength = 80;
            } else {
              const form = await flow(
                "Define transfer equivalency",
                [
                  ["course_id", "Institutional course", "lookup", "courses"],
                  ...rules,
                ],
                "/sis/transfer-equivalencies/",
                (data) => ({
                  ...data,
                  institution_id: value.institution_id,
                  external_course_id: id,
                }),
                back,
                {},
                `Sending course: ${value.code} · ${value.title}. Enter the institution's numeric grade-rank mapping and a passing awarded grade code.`,
              );
              constrain(form);
            }
          },
        ),
      );
      const panel = node(
        "section",
        {
          class: "record-section",
          "aria-label": "Related transfer records",
        },
        node(
          "h3",
          {},
          kind === "institutions" ? "External courses" : "Equivalencies",
        ),
      );
      body.append(panel);
      await pages(
        panel,
        "catalog",
        (after) =>
          request(`/sis/ui/transfer/${kind}/${id}/children/`, "POST", {
            after,
          }),
        (rows) =>
          rows.length
            ? grid(
                kind === "institutions"
                  ? ["Code", "Title", "Action"]
                  : ["Institutional course", "Credits", "Effective", "Action"],
                rows.map((row) =>
                  kind === "institutions"
                    ? [
                        row.code,
                        row.title,
                        button("Open external course", () =>
                          record("external", row.id),
                        ),
                      ]
                    : [
                        `${row.course_code} · ${row.course_title}`,
                        Number(row.credits_milli) / 1000,
                        row.starts_on,
                        button("Review equivalency", () =>
                          record("equivalencies", row.id),
                        ),
                      ],
                ),
                "Related transfer records",
              )
            : node("p", {}, "No related records have been added."),
      );
    } else {
      body.append(
        button("Edit equivalency rules", async () => {
          const form = await flow(
            "Save equivalency rules",
            rules,
            `/sis/ui/transfer/equivalencies/${id}/edit/`,
            (data) => ({ ...data, revision: value.revision }),
            back,
            {
              ...value,
              credits_milli: Number(value.credits_milli) / 1000,
            },
            "Rule edits affect future award requests, including requests for earlier completion dates. Existing credits retain their awarded values. Source and destination course identities stay fixed.",
          );
          constrain(form);
        }),
        button("Award transfer credit", () => award(id), "btn-primary"),
      );
      const panel = node(
        "section",
        { class: "record-section", "aria-label": "Transfer awards" },
        node("h3", {}, "Awarded credit"),
      );
      body.append(panel);
      await pages(
        panel,
        "award",
        (after) =>
          request(`/sis/ui/transfer/equivalencies/${id}/awards/`, "POST", {
            after,
          }),
        (rows) =>
          rows.length
            ? grid(
                [
                  "Student",
                  "Institutional ID",
                  "Completion date",
                  "Posting term",
                  "Awarded credits / grade",
                  "Status",
                  "Action",
                ],
                rows.map((row) => [
                  `${row.given_name} ${row.family_name}`,
                  row.institutional_id,
                  row.completed_on,
                  row.term_code,
                  `${Number(row.credits_milli) / 1000} · ${row.status === "V" ? "Voided" : row.grade_code || "—"}`,
                  row.status === "V" ? "Voided" : "Awarded",
                  can("sis:student:read")
                    ? node("div", { class: "inline-actions" },
                        button("Review award", () => ui.awards().record(row.student_id, row.id)),
                        button("Open student record", () => openRecord("students", { id: row.student_id })))
                    : "—",
                ]),
                "Transfer awards",
              )
            : node(
                "p",
                {},
                "No credit awards have been recorded for this equivalency.",
              ),
      );
    }
  }
  async function award(id) {
    const reviewed = await request(`/sis/ui/transfer/equivalencies/${id}/`);
    const form = await flow(
      "Confirm transfer award",
      [
        ["student_id", "Student", "lookup", "transferstudents"],
        ["term_id", "Posting term", "lookup", "terms"],
        ["completed_on", "External course completion date", "date"],
        ["grade_rank", "External grade rank (0–10000)", "number"],
      ],
      "/sis/transfer-awards/",
      (data) => ({
        ...data,
        institution_id: reviewed.institution_id,
        equivalency_id: id,
        revision: reviewed.revision,
      }),
      () => record("equivalencies", id),
      {},
      "Review the recipient, completion date and numeric grade rank using your institution’s mapping. Credits and the grade come from the reviewed equivalency. Changes to that context require a fresh review.",
    );
    form.prepend(summary(reviewed));
    form.elements.completed_on.min = reviewed.starts_on;
    if (reviewed.ends_on) form.elements.completed_on.max = reviewed.ends_on;
    form.elements.grade_rank.min = reviewed.minimum_grade_rank;
    if (can("sis:student:read")) {
      const academics = node("section", {
        class: "record-section",
        "aria-label": "Student academic review",
        "aria-live": "polite",
      });
      let serial = 0;
      const clearAcademicReview = () => {
        ++serial;
        academics.replaceChildren();
      };
      const recipient = form.elements.student_id.closest(".mb-4");
      recipient.addEventListener("input", clearAcademicReview);
      form.elements.student_id.addEventListener("change", clearAcademicReview);
      const review = button("Review student academics", async () => {
        const student = form.elements.student_id.value;
        if (!student) {
          form.elements.student_id.reportValidity();
          return;
        }
        const token = ++serial;
        const profile = await request(`/sis/students/${student}/record/`);
        const transcript = await request(
          `/sis/students/${student}/transcript/`,
        );
        if (
          !form.isConnected ||
          serial !== token ||
          form.elements.student_id.value !== student
        )
          return;
        academics.replaceChildren(
          node("h3", {}, "Student academic record"),
          details([
            [
              "Student",
              `${profile.given_name} ${profile.family_name} · ${profile.institutional_id}`,
            ],
            ["Student activity", profile.active ? "Active" : "Inactive"],
            [
              "Primary program",
              `${profile.primary_program.code} · ${profile.primary_program.title}`,
            ],
            ["Institutional entry term", profile.entry_term.code],
          ]),
          grid(
            ["Course", "Title", "Credits completed", "Grade"],
            transcript.credits.map((credit) => [
              credit.course_code,
              credit.course_title,
              Number(credit.completed_milli) / 1000,
              credit.grade_code || "—",
            ]),
            "Existing academic credits",
          ),
        );
      });
      recipient.append(review);
      form.append(academics);
    }
  }
  return { record };
};
