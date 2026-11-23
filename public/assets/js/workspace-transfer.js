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
    recordSummary,
    badge,
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
  async function pages(container, label, fetchPage, render, saved = null, remember = () => {}) {
    let after = saved?.after || "",
      nextAfter = null,
      serial = 0;
    let previousCursors = [...(saved?.previousCursors || [])];
    const information = node("p", {
        role: "status",
        "aria-live": "polite",
        class: "form-text",
      }),
      content = node("div");
    const pageButton = (text, open) => node("button", {
      type: "button", class: "btn btn-outline-primary", onclick: () => task(open),
    }, text);
    const previous = pageButton(`Previous ${label} page`, () =>
      load(true, previousCursors.at(-1) || "", previousCursors.slice(0, -1)));
    const next = pageButton(`Next ${label} page`, () =>
      load(true, nextAfter, [...previousCursors, after]));
    previous.disabled = true;
    next.disabled = true;
    container.append(
      information,
      node("nav", { class: "inline-actions mb-3", "aria-label": `${label === "catalog" ? "Related record" : "Award"} pages` }, previous, next),
      content,
    );
    async function load(focus = false, cursor = after, trail = previousCursors) {
      const token = ++serial;
      previous.disabled = true;
      next.disabled = true;
      content.setAttribute("aria-busy", "true");
      try {
        const page = await fetchPage(cursor);
        if (!container.isConnected || token !== serial) return;
        after = cursor;
        previousCursors = [...trail];
        nextAfter = page.next_after;
        remember({ after, previousCursors: [...previousCursors] });
        information.textContent = `${page.items.length} ${page.items.length === 1 ? "record" : "records"} on this page.${nextAfter ? " More are available." : ""}`;
        content.replaceChildren(render(page.items));
        if (focus) {
          information.tabIndex = -1;
          information.focus();
        }
      } finally {
        if (container.isConnected && token === serial) {
          previous.disabled = !previousCursors.length;
          next.disabled = !nextAfter;
          content.removeAttribute("aria-busy");
        }
      }
    }
    await load();
  }
  function summary(value) {
    return recordSummary([
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
  function card(title, subtitle, status, fields, actions, label) {
    return node("article", { class: "transfer-record-card", "aria-label": label },
      node("div", { class: "transfer-record-heading" },
        node("div", { class: "transfer-record-identity" },
          node("h4", {}, title), node("p", { class: "form-text" }, subtitle)),
        status ? badge(status) : null),
      fields.length ? recordSummary(fields) : null,
      actions.length ? node("div", { class: "transfer-record-footer" }, ...actions) : null);
  }
  async function record(kind, id, context = {}) {
    let pageState = context.page || null;
    const back = () => record(kind, id, { parent: context.parent, page: pageState });
    const options = { record: { key: `transfer:${kind}:${id}`, label: "Transfer record", run: back } };
    const loading = openDrawer("Transfer record", "TRANSFER CATALOG", options);
    loading.append(node("p", { role: "status" }, "Loading transfer record…"));
    const value = await request(`/sis/ui/transfer/${kind}/${id}/`);
    if (!loading.isConnected) return;
    const label = kind === "equivalencies" ? `${value.external_code} → ${value.course_code}`
      : `${value.code} · ${kind === "institutions" ? value.name : value.title}`;
    options.record.label = label;
    const body = openDrawer(label, kind === "institutions" ? "SENDING INSTITUTION"
      : kind === "external" ? "EXTERNAL COURSE" : "TRANSFER EQUIVALENCY", options);
    if (kind === "equivalencies") body.append(summary(value));
    else
      body.append(
        recordSummary(
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
    if (context.parent || kind !== "institutions")
      body.prepend(
        button(
          context.parent?.label || (kind === "external" ? "Back to institution" : "Back to external course"),
          context.parent?.open || (() =>
            record(
              kind === "external" ? "institutions" : "external",
              kind === "external"
                ? value.institution_id
                : value.external_course_id,
            )),
          "btn-link mb-3",
        ),
      );
    const actions = node("div", {
      class: "inline-actions",
      role: "group",
      "aria-label": "Transfer catalog tasks",
    });
    body.append(actions);
    if (kind !== "equivalencies") {
      actions.append(
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
              { layout: "transfer_catalog" },
            );
            form.elements.code.maxLength = 80;
          },
        ),
      );
      actions.prepend(
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
                { layout: "transfer_catalog" },
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
                { layout: "transfer_equivalency" },
              );
              constrain(form);
            }
          },
          "btn-primary",
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
            ? node("div", { class: "transfer-record-list" },
                rows.map((row) =>
                  kind === "institutions"
                    ? card(row.code, row.title, null, [], [
                        button("Open external course", () => record("external", row.id, {
                          parent: { label: "Back to institution", open: back },
                        })),
                      ], `External course ${row.code}`)
                    : card(row.course_code, row.course_title, null, [
                        ["Credits", Number(row.credits_milli) / 1000],
                        ["Effective from", row.starts_on],
                      ], [button("Review equivalency", () => record("equivalencies", row.id, {
                        parent: { label: "Back to external course", open: back },
                      }))], `Equivalency ${row.course_code}`),
                ))
            : node("p", {}, "No related records have been added."),
        pageState,
        (state) => { pageState = state; },
      );
    } else {
      actions.append(
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
            { layout: "transfer_equivalency" },
          );
          constrain(form);
        }),
      );
      if (can("sis:student:read")) {
        actions.prepend(
          button("Award transfer credit", () => award(id), "btn-primary"),
        );
      }
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
            ? node("div", { class: "transfer-record-list" },
                rows.map((row) => card(
                  `${row.given_name} ${row.family_name}`, "Posted transfer credit",
                  row.status === "V" ? "Voided" : "Awarded", [
                    ["Institutional ID", row.institutional_id],
                    ["Completion date", row.completed_on],
                    ["Posting term", row.term_code],
                    ["Credits / grade", `${Number(row.credits_milli) / 1000} · ${row.status === "V" ? "Voided" : row.grade_code || "—"}`],
                  ],
                  can("sis:student:read")
                    ? [
                        button("Review award", () =>
                          ui.awards().record(row.student_id, row.id),
                        ),
                        button("Open student record", () =>
                          openRecord("students", { id: row.student_id }),
                        ),
                      ]
                    : [],
                  `Transfer award for ${row.given_name} ${row.family_name} · ${row.institutional_id}`,
                )))
            : node(
                "p",
                {},
                "No credit awards have been recorded for this equivalency.",
              ),
        pageState,
        (state) => { pageState = state; },
      );
    }
    if (body.isConnected && can("sis:audit:read"))
      actions.append(
        button(
          "View audit history",
          () => showAudit(kind, id),
          "btn-outline-secondary",
        ),
      );
  }
  async function award(id) {
    const body = openDrawer("Prepare transfer award", "REGISTRAR REVIEW");
    body.append(
      node(
        "p",
        { role: "status" },
        "Loading the reviewed equivalency and course publications…",
      ),
    );
    const reviewed = await request(`/sis/ui/transfer/equivalencies/${id}/`);
    if (!body.isConnected) return;
    const publications = await request(
      `/sis/transfer-equivalencies/${id}/course-versions/`,
      "POST",
      {},
    );
    if (!body.isConnected) return;
    if (!publications.items.length)
      throw new Error(
        "Catalog staff must publish the institutional course before transfer credit can be awarded.",
      );
    const label = (item) =>
      `Version ${item.version} · ${item.code} · ${item.transcript_title} · ${item.credits_milli / 1000} catalog credits`;
    const form = await flow(
      "Confirm transfer award",
      [
        ["student_id", "Student", "lookup", "transferstudents"],
        ["term_id", "Posting term", "lookup", "terms"],
        ["completed_on", "External course completion date", "date"],
        ["grade_rank", "External grade rank (0–10000)", "number"],
        [
          "course_version_id",
          "Accepted institutional course publication",
          "choice",
          publications.items.map((item) => ({
            value: item.version_id,
            label: label(item),
          })),
        ],
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
      { layout: "transfer_award" },
    );
    if (!form.isConnected) return;
    form.prepend(summary(reviewed));
    form.prepend(
      node(
        "p",
        {},
        "Select the receiving course definition accepted for this award. Its published code and transcript title are retained. Awarded credits come from the reviewed equivalency, independently of catalog default credits. No publication is selected automatically.",
      ),
    );
    const publicationStatus = node(
      "p",
      { role: "status", class: "form-text" },
      `${publications.items.length} publications loaded.`,
    );
    form.elements.course_version_id.parentElement.append(publicationStatus);
    let cursor = publications.next_cursor;
    if (cursor) {
      const more = button("Load more course publications", async () => {
        const next = await request(
          `/sis/transfer-equivalencies/${id}/course-versions/`,
          "POST",
          {
            cursor,
          },
        );
        if (!form.isConnected) return;
        next.items.forEach((item) =>
          form.elements.course_version_id.append(
            node("option", { value: item.version_id }, label(item)),
          ),
        );
        cursor = next.next_cursor;
        publicationStatus.textContent = `${form.elements.course_version_id.options.length - 1} publications loaded.`;
        if (!cursor) {
          more.remove();
          form.elements.course_version_id.focus();
        }
      });
      form.elements.course_version_id.parentElement.append(more);
    }
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
        if (
          !form.isConnected ||
          serial !== token ||
          form.elements.student_id.value !== student
        )
          return;
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
          recordSummary([
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
