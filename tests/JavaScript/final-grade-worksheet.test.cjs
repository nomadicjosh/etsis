"use strict";
const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs"),
    vm = require("node:vm");
const { Element, node, walk } = require("./support/elements.cjs");
Element.prototype.removeAttribute = function (key) {
    delete this.attributes[key];
};
const item = (n) => ({
    id: `enrollment-${String(n).padStart(3, "0")}`,
    student_id: `student-${n}`,
    given_name: `Fictional ${n}`,
    family_name: "Student",
    institutional_id: `F-${n}`,
    status: "A",
    grade_version: 0,
});
async function fixture(options = {}) {
    const window = {};
    vm.runInNewContext(fs.readFileSync("public/assets/js/teaching-worksheet.js", "utf8"), { window });
    const items = options.items || [item(3), item(2), item(1)],
        calls = [],
        posted = new Map(),
        proofs = new Map();
    let body,
        pending,
        backs = 0,
        proofNumber = 0,
        rejectReview = options.rejectReview,
        failCommit = options.failCommit,
        delayedPath = null,
        release;
    const controller = window.createTeachingWorksheet({
        node,
        table: (headers, rows, caption) => node("table", {}, node("caption", {}, caption), rows.flat()),
        openDrawer: () => (body = node("div")),
        task: (run) => (pending = Promise.resolve().then(run)),
        request: async (path, method, data) => {
            calls.push({ path, method, data: JSON.parse(JSON.stringify(data)) });
            if (path.endsWith("/roster/section/"))
                return {
                    can_grade: true,
                    items: items.filter((row) => !data.after || row.id < data.after),
                    next_after: null,
                };
            if (delayedPath && path.endsWith(delayedPath))
                await new Promise((resolve) => {
                    release = resolve;
                });
            if (path.endsWith("/grade-choices/")) {
                const student = path.split("/")[5],
                    row = items.find((row) => row.student_id === student);
                return {
                    enrollment_id: options.changedEnrollment === student ? "different-enrollment" : row.id,
                    needs_assignment: options.ambiguous === student && !data.assignment_id,
                    pursuits: [{ id: "program-one", code: "BA", title: "Fictional program" }],
                    items:
                        options.noScale === student
                            ? []
                            : [
                                  { id: "grade-a", code: "A <script>", points_milli: 4000 },
                                  { id: "grade-b", code: "B", points_milli: 3000 },
                              ],
                    posted: options.alreadyPosted === student,
                    current_version: 0,
                    effective_on_default: "2026-11-18",
                    coursework: {
                        available: true,
                        grade_id: "grade-a",
                        score_display: "96%",
                        revision: `coursework-${student}`,
                    },
                };
            }
            if (path.endsWith("/review/")) {
                if (rejectReview === data.student_id)
                    throw Object.assign(new Error("Fictional rejection"), { status: 422 });
                const proof = {
                    student_id: data.student_id,
                    section_id: "section",
                    enrollment_id: data.enrollment_id,
                    operation_id: `operation-${++proofNumber}`,
                    review_token: `token-${proofNumber}`,
                    amendment: false,
                    grade: {
                        id: data.grade_id,
                        code: data.grade_id === "grade-a" ? "A <script>" : "B",
                        points_milli: 4000,
                    },
                    effective_at: "2026-11-18",
                };
                proofs.set(proof.review_token, proof);
                return proof;
            }
            if (path.endsWith("/commit/")) {
                const proof = proofs.get(data.review_token);
                assert.ok(proof, "Confirmation must use an actual reviewed token.");
                assert.equal(proof.student_id, data.student_id);
                if (failCommit?.student === data.student_id) {
                    const error = failCommit;
                    failCommit = null;
                    if (error.status >= 500)
                        posted.set(data.student_id, { operation_id: proof.operation_id });
                    throw Object.assign(new Error("Fictional stopped response"), { status: error.status });
                }
                if (!posted.has(data.student_id))
                    posted.set(data.student_id, { operation_id: proof.operation_id });
                return posted.get(data.student_id);
            }
            throw new Error(`Unexpected fixture request: ${path}`);
        },
    });
    await controller.finalGrades(
        { id: "section", code: "FIC.101.A", course_title: "Fictional course" },
        () => {
            backs++;
        },
        "Fictional",
    );
    const get = (id) => walk(body).find((element) => element.id === id);
    const button = (label) =>
        walk(body).find((element) => element.tag === "button" && element.textContent === label);
    const click = async (label) => {
        const control = button(label);
        assert.ok(control, label);
        assert.equal(!!control.disabled, false, `${label} is enabled`);
        control.attributes.onclick();
        await pending;
    };
    const change = (id, value) => {
        const control = get(id);
        assert.ok(control, id);
        control.value = value;
        control.events.change();
    };
    const grade = (n, value = "grade-a") => change(`final-grade-student-${n}-grade`, value);
    return {
        controller,
        calls,
        posted,
        get,
        button,
        click,
        change,
        grade,
        body: () => body,
        backs: () => backs,
        allowReview: () => {
            rejectReview = null;
        },
        delay: (path) => {
            delayedPath = path;
        },
        release: () => release(),
        close: () => {
            for (const element of walk(body)) element.isConnected = false;
        },
    };
}

test("final-grade choices load only on demand with at most twenty students per page", async () => {
    const items = Array.from({ length: 30 }, (_, index) => item(30 - index));
    const f = await fixture({ items });
    assert.equal(f.calls.length, 1);
    assert.equal(f.calls[0].data.grade_status, "needs_grade");
    assert.equal(f.calls[0].data.search, "Fictional");
    await f.click("Load grade choices");
    assert.equal(f.calls.filter((call) => call.path.endsWith("/grade-choices/")).length, 20);
    assert.equal(f.posted.size, 0);
    assert.equal(f.get("final-grade-student-30-grade").value, "");
    await f.click("Next page");
    assert.equal(f.calls.at(-1).data.after, items[19].id);
    assert.equal(f.calls.filter((call) => call.path.endsWith("/grade-choices/")).length, 20);
});

test("review is separate from posting and binds each enrollment, grade and selected coursework evidence", async () => {
    const f = await fixture();
    await f.click("Load grade choices");
    f.grade(3);
    f.grade(2, "grade-b");
    assert.equal(f.controller.canLeave(), false);
    await f.click("Back to roster");
    assert.equal(f.backs(), 0);
    await f.click("Review selected");
    assert.equal(f.posted.size, 0);
    const reviews = f.calls.filter((call) => call.path.endsWith("/review/"));
    assert.deepEqual(
        reviews.map((call) => call.data),
        [
            {
                student_id: "student-3",
                enrollment_id: "enrollment-003",
                initial_only: true,
                grade_id: "grade-a",
                version: 0,
                effective_on: "2026-11-18",
            },
            {
                student_id: "student-2",
                enrollment_id: "enrollment-002",
                initial_only: true,
                grade_id: "grade-b",
                version: 0,
                effective_on: "2026-11-18",
            },
        ],
    );
    await f.click("Revise grades");
    assert.equal(f.get("final-grade-student-3-grade").value, "grade-a");
    await f.click("Review selected");
    await f.click("Post reviewed grades");
    assert.equal(f.posted.size, 2);
    assert.equal(f.controller.canLeave(), true);
    assert.equal(f.get("final-grade-student-3-status").textContent, "Posted");
    assert.equal(f.button("Post reviewed grades").disabled, true);
});

test("an uncertain confirmation is retried with the same proof and no duplicate prior confirmations", async () => {
    const f = await fixture({ failCommit: { student: "student-2", status: 500 } });
    await f.click("Load grade choices");
    f.grade(3);
    f.grade(2);
    f.grade(1);
    await f.click("Review selected");
    await f.click("Post reviewed grades");
    let commits = f.calls.filter((call) => call.path.endsWith("/commit/"));
    assert.equal(commits.length, 2);
    assert.equal(f.posted.size, 2); // The interrupted response happened after the fictional write.
    assert.match(f.get("final-grade-student-2-status").textContent, /outcome is unknown/);
    assert.equal(f.button("Discard selections").disabled, true);
    assert.equal(f.controller.canLeave(), false);
    await f.click("Retry confirmation");
    commits = f.calls.filter((call) => call.path.endsWith("/commit/"));
    assert.deepEqual(commits[1].data, commits[2].data);
    assert.equal(commits.filter((call) => call.data.student_id === "student-3").length, 1);
    assert.equal(f.posted.size, 3);
    assert.equal(f.controller.canLeave(), true);
});

test("a definite stale rejection keeps prior successes and requires revised review before later posts", async () => {
    const f = await fixture({ failCommit: { student: "student-2", status: 409 } });
    await f.click("Load grade choices");
    f.grade(3);
    f.grade(2);
    f.grade(1);
    await f.click("Review selected");
    await f.click("Post reviewed grades");
    assert.equal(f.posted.size, 1);
    assert.equal(f.button("Post reviewed grades").disabled, true);
    await f.click("Revise grades");
    assert.equal(f.get("final-grade-student-3-grade").disabled, true);
    f.grade(2, "");
    await f.click("Review selected");
    await f.click("Post reviewed grades");
    assert.equal(f.posted.size, 2);
    assert.equal(f.posted.has("student-2"), false);
});

test("a rejected multi-student review leaves every grade unposted and preserves selections", async () => {
    const f = await fixture({ rejectReview: "student-2" });
    await f.click("Load grade choices");
    f.grade(3);
    f.grade(2);
    await f.click("Review selected");
    assert.equal(f.posted.size, 0);
    assert.match(f.body().textContent, /No grades were posted/);
    assert.equal(f.get("final-grade-student-3-grade").value, "grade-a");
    f.allowReview();
    await f.click("Review selected");
    await f.click("Post reviewed grades");
    assert.equal(f.posted.size, 2);
});

test("program choices and coursework are explicit; manual selection clears the suggestion proof", async () => {
    const f = await fixture({ ambiguous: "student-3" });
    await f.click("Load grade choices");
    assert.equal(f.get("final-grade-student-3-pursuit").hidden, false);
    f.change("final-grade-student-3-pursuit", "program-one");
    await f.click("Use program");
    await f.click("Use coursework suggestion");
    await f.click("Review selected");
    assert.equal(
        f.calls.find((call) => call.path.endsWith("/review/")).data.coursework_revision,
        "coursework-student-3",
    );
    assert.equal(f.calls.find((call) => call.path.endsWith("/review/")).data.assignment_id, "program-one");
    await f.click("Revise grades");
    f.grade(3, "grade-b");
    await f.click("Review selected");
    assert.equal(
        f.calls.filter((call) => call.path.endsWith("/review/")).at(-1).data.coursework_revision,
        undefined,
    );
});

test("posted or replaced enrollments cannot be selected for an initial grade", async () => {
    const f = await fixture({
        alreadyPosted: "student-3",
        changedEnrollment: "student-2",
        noScale: "student-1",
    });
    await f.click("Load grade choices");
    assert.equal(f.get("final-grade-student-3-grade").disabled, true);
    assert.equal(f.get("final-grade-student-2-grade").disabled, true);
    assert.match(f.get("final-grade-student-3-status").textContent, /Already posted/);
    assert.match(f.get("final-grade-student-2-status").textContent, /Registration changed/);
    assert.match(f.get("final-grade-student-1-status").textContent, /published grading scale/);
    assert.equal(f.button("Review selected").disabled, true);
    assert.equal(f.posted.size, 0);
});

test("closing during confirmation stops later requests and never opens another screen", async () => {
    const f = await fixture();
    await f.click("Load grade choices");
    f.grade(3);
    f.grade(2);
    await f.click("Review selected");
    f.delay("/commit/");
    const saving = f.click("Post reviewed grades");
    await new Promise(setImmediate);
    f.close();
    f.release();
    await saving;
    assert.equal(f.calls.filter((call) => call.path.endsWith("/commit/")).length, 1);
    assert.equal(f.controller.canLeave(), true);
});

test("access and validation failures stop later student confirmations without retrying accepted rows", async () => {
    for (const status of [401, 403, 422]) {
        const f = await fixture({ failCommit: { student: "student-2", status } });
        await f.click("Load grade choices");
        f.grade(3);
        f.grade(2);
        f.grade(1);
        await f.click("Review selected");
        await f.click("Post reviewed grades");
        assert.equal(f.posted.size, 1);
        assert.equal(f.calls.filter((call) => call.path.endsWith("/commit/")).length, 2);
        assert.equal(f.button("Post reviewed grades").disabled, true);
        assert.equal(f.button("Discard selections").disabled, false);
        await f.click("Discard selections");
        assert.equal(f.controller.canLeave(), true);
        assert.equal(f.posted.has("student-3"), true);
        assert.equal(f.posted.has("student-1"), false);
    }
});
