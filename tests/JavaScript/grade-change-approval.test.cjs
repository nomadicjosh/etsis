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
