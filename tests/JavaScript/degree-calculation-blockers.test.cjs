"use strict";
const assert = require("node:assert/strict");
const { readFileSync } = require("node:fs");
const vm = require("node:vm");
const test = require("node:test");
const { node, walk } = require("./support/elements.cjs");
const window = {};
vm.runInNewContext(
  readFileSync("public/assets/js/degree-progress-guide.js", "utf8"),
  { window },
  { timeout: 500 },
);
const review = () => ({
  status: "review",
  policy: { attempt_order: "academic_period" },
  issues: [],
  records: [],
});
const plain = (value) => JSON.parse(JSON.stringify(value));

test("blockers retain separate scopes and only specifically affected records without private payloads", () => {
  const data = review();
  data.issues = [
    {
      component: "overall",
      code: "chronology_unknown",
      reason: "private reason",
    },
  ];
  data.records = [
    {
      overall_gpa: "chronology_unknown",
      cumulative_gpa: "included",
      id: "private-id",
    },
    { overall_gpa: "undetermined", credit: "included" },
    { overall_gpa: "in_progress" },
  ];
  const before = JSON.stringify(data);
  const result = plain(window.degreeProgressCalculationBlockers(data));
  assert.equal(result.length, 1);
  assert.deepEqual(result[0].positions, [0]);
  assert.equal(result[0].scope, "overall");
  assert.match(result[0].guidance, /source academic-period/);
  assert.doesNotMatch(JSON.stringify(result), /private|undetermined/);
  assert.equal(JSON.stringify(data), before);
});

test("completion ordering, ties, incompatible scales and unknown grades have distinct explanations", () => {
  const data = review();
  data.policy.attempt_order = "completion_date";
  data.records = [
    "chronology_unknown",
    "ambiguous_repeat",
    "grading_policy_differs",
    "grade_unknown",
  ].map((overall_gpa) => ({ overall_gpa }));
  const rows = window.degreeProgressCalculationBlockers(data);
  assert.equal(rows.length, 4);
  assert.match(
    rows.find((r) => r.code === "chronology_unknown").guidance,
    /actual completion dates/,
  );
  assert.match(
    rows.find((r) => r.code === "ambiguous_repeat").guidance,
    /no favorable winner/,
  );
  assert.match(
    rows.find((r) => r.code === "grading_policy_differs").guidance,
    /No grade-scale conversion/,
  );
  assert.match(
    rows.find((r) => r.code === "grade_unknown").guidance,
    /different from awaiting/,
  );
});

test("global policy and numeric issues do not invent affected records or academic failure", () => {
  const data = review();
  data.issues = [
    { component: "credits", code: "policy_unknown" },
    { component: "overall", code: "arithmetic_limit" },
    { component: "overall", code: "private arbitrary text" },
  ];
  const rows = window.degreeProgressCalculationBlockers(data);
  assert.equal(rows.length, 2);
  assert.ok(rows.every((r) => r.positions.length === 0));
  assert.match(rows[1].guidance, /does not mean.*failed/);
});

test("unavailable and independently satisfied reviews expose no speculative blockers", () => {
  for (const status of ["limited", "unavailable", "needs_selection"]) {
    const data = review();
    data.status = status;
    data.records = [{ credit: "grade_unknown" }];
    assert.equal(window.degreeProgressCalculationBlockers(data).length, 0);
  }
  assert.equal(window.degreeProgressCalculationBlockers(review()).length, 0);
});

test("affected coursework renders lazily in twenty-record pages and focuses the new status", () => {
  const data = review();
  data.records = Array.from({ length: 21 }, (_, i) => ({
    course_code: `COURSE.${i}`,
    course_title: "<b>Fictional</b>",
    overall_gpa: "chronology_unknown",
    grade_state: "awaiting_grade",
  }));
  const section = window.createDegreeProgressBlockers(
    {
      node,
      button: (label, action) => {
        const button = node("button", {}, label);
        button.addEventListener("click", action);
        return button;
      },
      milli: String,
    },
    data,
  );
  assert.doesNotMatch(section.textContent, /COURSE\.0/);
  const details = walk(section).find((e) => e.tag === "details");
  details.open = true;
  details.events.toggle();
  assert.match(section.textContent, /Showing affected records 1–20 of 21/);
  assert.doesNotMatch(section.textContent, /COURSE\.20/);
  walk(section)
    .find(
      (e) => e.tag === "button" && e.textContent === "More affected records",
    )
    .events.click();
  assert.match(section.textContent, /Showing affected records 21–21 of 21/);
  assert.match(section.textContent, /COURSE\.20/);
  assert.ok(
    walk(section).some((e) => e.attributes.role === "status" && e.focused),
  );
  assert.equal(walk(section).filter((e) => e.tag === "b").length, 0);
});

test("group coursework eligibility defaults permit transfer and explicit windows use completion evidence", () => {
  assert.match(window.degreeGroupEligibilityText(), /Institutional or transfer work/);
  assert.match(window.degreeGroupEligibilityText({credit_origin:"transfer",completed_from:"2020-01-01",completed_through:null}), /Transfer work only. Actual completion on or after 2020-01-01/);
  const data = review();
  data.allocation = {outcome:"needs_review",eligibility_issues:["completion_date_unknown"]};
  assert.match(window.createDegreeProgressNextSteps(node,data).textContent, /Review actual completion dates/);
  assert.match(window.createDegreeProgressNextSteps(node,data).textContent, /Posting terms and catalog dates cannot replace/);
});


test("course-age guidance uses retained calendar dates and explains missing pursuit evidence", () => {
  const policy = {credit_origin:"any",completed_from:null,completed_through:null,max_age_months_at_pursuit_start:60};
  assert.match(window.degreeGroupEligibilityText(policy,{pursuit_started_on:"2026-08-10",earliest_completion_on:"2021-08-10"}), /2021-08-10, inclusive/);
  assert.match(window.degreeGroupEligibilityText(policy,{pursuit_started_on:null,earliest_completion_on:null}), /pursuit start is unavailable/);
  const data = review();
  data.allocation = {outcome:"needs_review",eligibility_issues:["pursuit_start_unknown"]};
  assert.match(window.createDegreeProgressNextSteps(node,data).textContent, /Review the recorded pursuit start/);
  assert.match(window.createDegreeProgressNextSteps(node,data).textContent, /Today's date, term starts/);
});


test("sending-institution guidance retains labels and asks for real missing source evidence", () => {
  const policy = {credit_origin:"any",completed_from:null,completed_through:null,
    transfer_institutions:[{code:"EXT",name:"Fictional college"}]};
  assert.match(window.degreeGroupEligibilityText(policy), /Transfer work must come from: EXT — Fictional college/);
  const data = review();
  data.allocation = {outcome:"needs_review",eligibility_issues:["transfer_institution_unknown"]};
  const text = window.createDegreeProgressNextSteps(node,data).textContent;
  assert.match(text, /Review retained transfer-source evidence/);
  assert.match(text, /Today's catalog or equivalency labels cannot replace missing retained evidence/);
});


test("review-date expiration distinguishes the retained review date from pursuit start", () => {
  const policy={credit_origin:"any",completed_from:null,completed_through:null,
    max_age_months_at_evaluation:12,max_age_months_at_pursuit_start:60};
  const text=window.degreeGroupEligibilityText(policy,
    {pursuit_started_on:"2026-01-31",earliest_completion_on:"2021-01-31"},
    {evaluated_on:"2026-08-10",earliest_completion_on:"2025-08-10"});
  assert.match(text,/Pursuit began 2026-01-31/);
  assert.match(text,/Reviewed on 2026-08-10; actual completion must be on or after 2025-08-10, inclusive/);
  const data=review();data.allocation={outcome:"needs_review",eligibility_issues:["evaluation_date_unknown"]};
  assert.match(window.createDegreeProgressNextSteps(node,data).textContent,/Refresh the dated progress review/);
  assert.match(window.createDegreeProgressNextSteps(node,data).textContent,/keeps its original date/);
});
