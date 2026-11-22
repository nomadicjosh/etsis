"use strict";
const test = require("node:test"),
    assert = require("node:assert/strict");
const fs = require("node:fs"),
    vm = require("node:vm");
const { Element, node, walk } = require("./support/elements.cjs");
Element.prototype.removeAttribute = function (key) {
    delete this.attributes[key];
};
function fixture(overrides = {}) {
    const window = {};
    vm.runInNewContext(fs.readFileSync("public/assets/js/gradebook-calculation.js", "utf8"), { window });
    vm.runInNewContext(fs.readFileSync("public/assets/js/workspace-teaching.js", "utf8"), { window });
    let body, pending;
    const sent = [];
    const record = {
        id: "request",
        status: "pending",
        institutional_id: "TEST100",
        course_code: "TEST.101",
        before: { code: "B" },
        grade: { code: "A" },
        effective_at: "2026-08-20T00:00:00Z",
        reason: "Fictional marking review <img>",
        can_approve: true,
        can_reject: true,
        can_cancel: false,
        ...overrides,
    };
    const ui = {
        node,
        can: () => false,
        openDrawer: () => (body = node("div")),
        task: (run) => (pending = Promise.resolve().then(run)),
        request: async (path, method, data) => {
            sent.push({ path, method, data });
            if (path.endsWith("/review/"))
                return { outcome: data.outcome, reason: data.reason, review_token: "private-proof" };
            if (path.endsWith("/commit/")) return {};
            if (path === "/sis/grade-change-policy/") return { mode: "direct", revision: 3 };
            return record;
        },
        recordSummary: (rows) =>
            node(
                "dl",
                {},
                rows.map(([label, value]) => node("div", {}, label, ": ", value)),
            ),
        buildForm: async (fields, initial = {}) => {
            const form = node("form");
            form.elements = {};
            form.fields = fields;
            for (const [name, label, type, choices] of fields) {
                const input = node(
                    type === "choice" ? "select" : type === "checkbox" ? "input" : "textarea",
                    { name },
                );
                input.value = initial[name] || (choices && choices[0]?.value) || "";
                input.checked = initial[name] === true;
                form.elements[name] = input;
                form.append(node("label", {}, label), input);
            }
            return form;
        },
        formData: (form, fields) =>
            Object.fromEntries(
                fields.map(([name, , type]) => [
                    name,
                    type === "checkbox" ? form.elements[name].checked : form.elements[name].value,
                ]),
            ),
    };
    const component = window.createWorkspaceTeaching(ui);
    const click = async (label) => {
        const button = walk(body).find((e) => e.tag === "button" && e.textContent === label);
        assert.ok(button, label);
        button.attributes.onclick({ currentTarget: button });
        await pending;
    };
    return { component, ui, sent, body: () => body, click, done: () => pending };
}
test("independent approval uses an explicit reviewed confirmation and preserves the decision form", async () => {
    const f = fixture();
    let returned = false;
    await f.component.gradeChangeRecord("request", async () => {
        returned = true;
    });
    assert.equal(
        walk(f.body()).some((e) => e.tag === "img"),
        false,
    );
    const form = walk(f.body()).find((e) => e.tag === "form");
    assert.deepEqual(
        JSON.parse(JSON.stringify(form.fields[0][3])).map((o) => o.value),
        ["approved", "rejected"],
    );
    form.elements.reason.value = "Fictional independent approval.";
    form.events.submit({ preventDefault() {} });
    await f.done();
    assert.equal(f.sent.filter((r) => r.path.endsWith("/commit/")).length, 0);
    await f.click("Change review");
    assert.match(f.body().textContent, /TEST100/);
    assert.equal(
        walk(f.body()).find((e) => e.tag === "form"),
        form,
    );
    assert.equal(form.elements.reason.value, "Fictional independent approval.");
    form.events.submit({ preventDefault() {} });
    await f.done();
    await f.click("Confirm decision");
    assert.equal(returned, true);
    assert.equal(f.sent.filter((r) => r.path.endsWith("/commit/")).length, 1);
    assert.deepEqual(JSON.parse(JSON.stringify(f.sent.at(-1).data)), { review_token: "private-proof" });
});
test("the requesting registrar is offered cancellation rather than self approval", async () => {
    const f = fixture({ can_approve: false, can_reject: false, can_cancel: true });
    await f.component.gradeChangeRecord("request");
    const form = walk(f.body()).find((e) => e.tag === "form");
    assert.deepEqual(
        JSON.parse(JSON.stringify(form.fields[0][3])).map((o) => o.value),
        ["cancelled"],
    );
});
test("changed academic facts remove approval while leaving rejection available", async () => {
    const f = fixture({ stale: true, can_approve: false });
    await f.component.gradeChangeRecord("request");
    assert.match(f.body().textContent, /academic record changed/);
    const form = walk(f.body()).find((e) => e.tag === "form");
    assert.deepEqual(
        JSON.parse(JSON.stringify(form.fields[0][3])).map((o) => o.value),
        ["rejected"],
    );
});
test("withdrawn-grade cases explain the zero-credit boundary and distinct approval duty", async () => {
    const f = fixture({ withdrawal_grade_correction: true, can_approve: false });
    await f.component.gradeChangeRecord("request");
    assert.match(
        f.body().textContent,
        /does not reopen the enrollment, award earned credit or change charges/,
    );
    assert.match(f.body().textContent, /separate withdrawn-grade correction authority/);
    const form = walk(f.body()).find((e) => e.tag === "form");
    assert.deepEqual(
        JSON.parse(JSON.stringify(form.fields[0][3])).map((o) => o.value),
        ["rejected"],
    );
});
test("late request reads cannot populate a closed private drawer", async () => {
    const f = fixture();
    let resolve;
    f.ui.request = () =>
        new Promise((done) => {
            resolve = done;
        });
    // The factory captures its transport, so instantiate it with the delayed request.
    const window = {};
    vm.runInNewContext(fs.readFileSync("public/assets/js/gradebook-calculation.js", "utf8"), { window });
    vm.runInNewContext(fs.readFileSync("public/assets/js/workspace-teaching.js", "utf8"), { window });
    const component = window.createWorkspaceTeaching(f.ui);
    const pending = component.gradeChangeRecord("request");
    f.body().isConnected = false;
    resolve({});
    await pending;
    assert.equal(f.body().children.length, 0);
});
test("policy save carries its revision and uses the shared private transport", async () => {
    const f = fixture();
    await f.component.gradeChangePolicy();
    const form = walk(f.body()).find((e) => e.tag === "form");
    form.elements.mode.value = "approval";
    form.events.submit({ preventDefault() {} });
    await f.done();
    const saved = f.sent.find((r) => r.method === "POST");
    assert.deepEqual(JSON.parse(JSON.stringify(saved.data)), { mode: "approval", revision: 3 });
});
test("missing withdrawn-grade editor requires evidence and explains limits without completion edits", async () => {
    const f = fixture();
    f.ui.request = async (path, method, data) => {
        f.sent.push({ path, method, data });
        if (path.endsWith("/review/"))
            return {
                withdrawal_grade_correction: true,
                missing_withdrawal_grade: true,
                grade: { code: "D", points_milli: 1000 },
                reason: data.reason,
                review_token: "private-proof",
            };
        return {
            withdrawal_grade_correction: true,
            missing_withdrawal_grade: true,
            posted: true,
            can_amend: true,
            current_grade_code: null,
            current_version: 2,
            effective_on_default: "2026-09-02",
            items: [{ id: "grade-d", code: "D", points_milli: 1000 }],
        };
    };
    const window = {};
    vm.runInNewContext(fs.readFileSync("public/assets/js/gradebook-calculation.js", "utf8"), { window });
    vm.runInNewContext(fs.readFileSync("public/assets/js/workspace-teaching.js", "utf8"), { window });
    const component = window.createWorkspaceTeaching(f.ui);
    await component.recordFinalGrade({ id: "section" }, { student_id: "student" }, () => {});
    assert.match(f.body().textContent, /earned credit stays zero and charges stay unchanged/);
    assert.match(f.body().textContent, /Unknown definition/);
    assert.match(f.body().textContent, /Verify the grade against institutional records/);
    assert.equal(
        walk(f.body()).some((e) => e.tag === "button" && /completion date/.test(e.textContent)),
        false,
    );
    const form = walk(f.body()).find((e) => e.tag === "form");
    form.elements.grade_id.value = "grade-d";
    form.elements.reason.value = "Fictional late-drop marking correction.";
    form.elements.grade_evidence.checked = true;
    assert.equal(form.elements.grade_evidence.required, true);
    assert.equal(form.elements.grade_evidence.getAttribute("aria-describedby"), "grade-evidence-help");
    form.events.submit({ preventDefault() {} });
    await f.done();
    assert.match(f.body().textContent, /Enrollment remains withdrawn. Earned credit remains zero/);
    assert.match(f.body().textContent, /earlier grading definition remains unknown/);
    assert.equal(f.sent.at(-1).data.grade_evidence, true);
    assert.equal(
        f.sent.some((r) => r.path.endsWith("/commit/")),
        false,
    );
    await f.click("Change review");
    assert.equal(
        walk(f.body()).find((e) => e.tag === "form"),
        form,
    );
    assert.equal(form.elements.reason.value, "Fictional late-drop marking correction.");
    assert.equal(form.elements.grade_evidence.checked, true);
    assert.match(f.body().textContent, /Verify the grade against institutional records/);
});
test("missing-grade approval shows the unknown definition and preserves its evidence notice", async () => {
    const f = fixture({ before: null, withdrawal_grade_correction: true, missing_withdrawal_grade: true });
    await f.component.gradeChangeRecord("request");
    assert.match(f.body().textContent, /Unknown definition/);
    assert.match(f.body().textContent, /Review the requester's evidence/);
    const form = walk(f.body()).find((e) => e.tag === "form");
    form.elements.reason.value = "Fictional independent evidence review.";
    form.events.submit({ preventDefault() {} });
    await f.done();
    assert.match(f.body().textContent, /Unknown definition → A/);
    await f.click("Change review");
    assert.match(f.body().textContent, /earlier definition will remain unknown/);
});

async function courseworkFixture(overrides = {}) {
    const f = fixture();
    const choices = {
        posted: false,
        enrollment_id: 'fictional-enrollment',
        can_amend: false,
        current_grade_code: null,
        current_version: 0,
        effective_on_default: "2026-09-02",
        items: [
            { id: "grade-b", code: "B", points_milli: 3000 },
            { id: "grade-a", code: "A", points_milli: 4000 },
        ],
        coursework: {
            available: true,
            grade_id: "grade-b",
            grade_code: "B",
            score_display: "92%",
            grade_policy: { rounding: "none", decimal_places: null, cap_at_hundred: false },
            revision: "first",
            ...overrides,
        },
    };
    f.ui.request = async (path, method, data) => {
        f.sent.push({ path, method, data });
        if (path.endsWith("/grade-choices/")) return choices;
        if (path.endsWith("/review/"))
            return {
                grade: choices.items.find((g) => g.id === data.grade_id),
                reason: data.reason,
                review_token: "protected-proof",
                ...(data.coursework_revision ? { coursework_summary: { score_display: "92%" } } : {}),
            };
        return {};
    };
    const window = {};
    vm.runInNewContext(fs.readFileSync("public/assets/js/gradebook-calculation.js", "utf8"), { window });
    vm.runInNewContext(fs.readFileSync("public/assets/js/workspace-teaching.js", "utf8"), { window });
    const component = window.createWorkspaceTeaching(f.ui);
    await component.recordFinalGrade({ id: "section" }, { student_id: "student" }, () => {});
    return { ...f, choices, component };
}
test("a coursework suggestion requires explicit selection, review and confirmation", async () => {
    const f = await courseworkFixture();
    const form = walk(f.body()).find((e) => e.tag === "form");
    assert.match(f.body().textContent, /92% → B/);
    assert.match(f.body().textContent, /exact percentage, without rounding/);
    assert.equal(
        f.sent.some((r) => r.path.endsWith("/review/") || r.path.endsWith("/commit/")),
        false,
    );
    await f.click("Use suggested grade");
    assert.equal(form.elements.grade_id.value, "grade-b");
    form.elements.reason.value = "Fictional final assessment.";
    form.events.submit({ preventDefault() {} });
    await f.done();
    assert.equal(f.sent.at(-1).data.coursework_revision, "first");
    assert.equal(f.sent.at(-1).data.version, 0);
    assert.equal(f.sent.at(-1).data.enrollment_id, 'fictional-enrollment');
    assert.equal(f.sent.at(-1).data.initial_only, true);
    assert.match(f.body().textContent, /Its inputs will be retained with the grade decision/);
    await f.click("Change review");
    assert.equal(
        walk(f.body()).find((e) => e.tag === "form"),
        form,
    );
    assert.equal(form.elements.reason.value, "Fictional final assessment.");
    form.events.submit({ preventDefault() {} });
    await f.done();
    assert.equal(f.sent.at(-1).data.coursework_revision, "first");
    await f.click("Confirm final grade");
    assert.deepEqual(JSON.parse(JSON.stringify(f.sent.at(-1).data)), {
        student_id: "student",
        review_token: "protected-proof",
    });
});
test("manually selecting another grade removes the coursework claim", async () => {
    const f = await courseworkFixture();
    const form = walk(f.body()).find((e) => e.tag === "form");
    await f.click("Use suggested grade");
    form.elements.grade_id.value = "grade-a";
    form.elements.grade_id.events.change();
    form.events.submit({ preventDefault() {} });
    await f.done();
    assert.equal(f.sent.at(-1).data.grade_id, "grade-a");
    assert.equal("coursework_revision" in f.sent.at(-1).data, false);
    assert.doesNotMatch(f.body().textContent, /Its inputs will be retained/);
});
test("refresh preserves the entered note and date while requiring fresh selection", async () => {
    const f = await courseworkFixture();
    const old = walk(f.body()).find((e) => e.tag === "form");
    old.elements.reason.value = "Keep this fictional note.";
    old.elements.effective_on.value = "2026-09-01";
    await f.click("Use suggested grade");
    f.choices.coursework.revision = "fresh";
    await f.click("Refresh suggestion");
    const form = walk(f.body()).find((e) => e.tag === "form");
    assert.equal(form.elements.reason.value, "Keep this fictional note.");
    assert.equal(form.elements.effective_on.value, "2026-09-01");
    form.events.submit({ preventDefault() {} });
    await f.done();
    assert.equal("coursework_revision" in f.sent.at(-1).data, false);
});
test("an unavailable suggestion stays manual and renders its explanation as text", async () => {
    const f = await courseworkFixture({ available: false, message: "Review a grading gap <img>." });
    assert.equal(
        walk(f.body()).some((e) => e.tag === "button" && e.textContent === "Use suggested grade"),
        false,
    );
    assert.equal(
        walk(f.body()).some((e) => e.tag === "img"),
        false,
    );
    assert.match(f.body().textContent, /Review a grading gap <img>/);
    assert.ok(walk(f.body()).some((e) => e.attributes.role === "status"));
    const form = walk(f.body()).find((e) => e.tag === "form");
    form.elements.grade_id.value = "grade-b";
    form.events.submit({ preventDefault() {} });
    await f.done();
    assert.equal("coursework_revision" in f.sent.at(-1).data, false);
});

test("coursework selection explains both the raw and policy-adjusted percentage", async () => {
    const f = await courseworkFixture({
        score_display: "92.5%", grading_display: "93%", grade_code: "A", grade_id: "grade-a",
        grade_policy: { rounding: "half_up", decimal_places: 0, cap_at_hundred: false },
    });
    assert.match(f.body().textContent, /92.5% raw · 93% for grade comparison/);
    assert.match(f.body().textContent, /nearest, ties up to 0 decimal places/);
    await f.click("Use suggested grade");
    assert.match(f.body().textContent, /Selected A from 92.5% raw · 93% for grade comparison/);
});
