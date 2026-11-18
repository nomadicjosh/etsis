"use strict";
const test = require("node:test"),
    assert = require("node:assert/strict");
const fs = require("node:fs"),
    vm = require("node:vm");
const { node, walk } = require("./support/elements.cjs");
async function fixture() {
    const window = {};
    vm.runInNewContext(fs.readFileSync("public/assets/js/workspace-transfer-awards.js", "utf8"), { window });
    let body,
        pending,
        failure,
        deferred,
        release,
        reviews = 0,
        reviewedDates,
        reviewedCourse;
    const sent = [];
    const value = {
        given_name: "Fictional",
        family_name: "Student",
        institutional_id: "TEST100",
        institution_name: "Fictional Sending College",
        external_code: "EXT.101",
        term_code: "26/FA",
        term_id: "01K00000000000000000000060",
        completed_on: "2026-05-01",
        academic_on: null,
        effective_on_default: "2026-08-10",
        grade_code: "TR",
        credit: {
            course_id: "course-original",
            course_code: "TEST.101",
            course_title: "Fictional course",
            completed_milli: 3000,
            status: "A",
            version: 0,
        },
    };
    const openDrawer = () => {
        if (body) for (const element of walk(body)) element.isConnected = false;
        return (body = node("div"));
    };
    const ui = {
        node,
        openDrawer,
        table: (headers, rows) => node("table", {}, rows.flat().join(" ")),
        recordSummary: (rows) =>
            node(
                "dl",
                {},
                rows.map(([label, text]) => node("div", {}, label, ": ", text)),
            ),
        actionMenu: (label, actions) => node("div", {}, ...actions),
        task: (run) => (pending = Promise.resolve().then(run)),
        buildForm: async (fields, initial) => {
            const form = node("form");
            form.elements = {};
            for (const [name, label, type, source] of fields) {
                const input = node(type === "choice" ? "select" : "input", { name });
                Object.defineProperty(input, "parentElement", { get: () => input.parent });
                if (type === "choice") {
                    for (const option of source)
                        input.append(node("option", { value: option.value }, option.label));
                    Object.defineProperty(input, "options", {
                        get: () => input.children.filter((e) => e.tag === "option"),
                    });
                    Object.defineProperty(input, "selectedOptions", {
                        get: () => input.options.filter((e) => e.attributes.value === input.value),
                    });
                    for (const option of input.children) option.value = option.attributes.value;
                }
                input.value = initial[name] ?? "";
                form.elements[name] = input;
                form.append(node("label", {}, label), input);
            }
            return form;
        },
        formData: (form, fields) =>
            Object.fromEntries(
                fields.map(([name, , type]) => [
                    name,
                    type === "credits"
                        ? Math.round(Number(form.elements[name].value) * 1000)
                        : form.elements[name].value,
                ]),
            ),
        request: async (path, method, data) => {
            sent.push({ path, method, data });
            if (path.endsWith("/accepted-course/search/"))
                return {
                    items: [{ id: "course-target", code: "ENG.220", title: "Fictional literature" }],
                    next_after: null,
                };
            if (path.endsWith("/accepted-course/choices/"))
                return {
                    items: [
                        {
                            version_id:
                                data.course_id === "course-target"
                                    ? "target-publication"
                                    : "original-publication",
                            code: data.course_id === "course-target" ? "ENG.220" : "TEST.101",
                            transcript_title: "Fictional published course",
                            credits_milli: 4000,
                            version: 1,
                        },
                    ],
                    next_cursor: null,
                };
            if (path.endsWith("/accepted-course/review/")) {
                const review = {
                    ...data,
                    before: value.credit,
                    after: {
                        course_code: data.course_id === "course-target" ? "ENG.220" : "TEST.101",
                        course_title: "Fictional published course",
                    },
                    replacement: data.course_id !== value.credit.course_id,
                    previous_course_version_id: null,
                    review_token: "private-course-proof-" + ++reviews,
                };
                reviewedCourse = review;
                if (deferred)
                    return new Promise((resolve) => {
                        release = () => resolve(review);
                    });
                return review;
            }
            if (path.endsWith("/accepted-course/commit/")) {
                if (failure) {
                    const error = failure;
                    failure = null;
                    throw error;
                }
                Object.assign(value.credit, reviewedCourse.after, { course_id: reviewedCourse.course_id });
                return { operation_id: "course-operation" };
            }
            if (path.endsWith("/source-period/review/")) {
                const review = {
                    ...data,
                    previous_academic_on: value.academic_on,
                    previous_completed_on: value.completed_on,
                    previous_term_code: value.term_code,
                    term_code: data.term_id === value.term_id ? value.term_code : "27/SP",
                    effective_at: data.effective_on,
                    review_token: "private-date-proof-" + ++reviews,
                };
                reviewedDates = review;
                if (deferred)
                    return new Promise((resolve) => {
                        release = () => resolve(review);
                    });
                return review;
            }
            if (path.endsWith("/source-period/commit/")) {
                if (failure) {
                    const error = failure;
                    failure = null;
                    throw error;
                }
                Object.assign(value, {
                    term_id: reviewedDates.term_id,
                    term_code: reviewedDates.term_code,
                    completed_on: reviewedDates.completed_on,
                    academic_on: reviewedDates.academic_on,
                });
                return { operation_id: "date-operation" };
            }
            if (path.endsWith("/preview/")) {
                const review = {
                    request: data,
                    record: value,
                    before: value.credit,
                    after: {
                        ...value.credit,
                        completed_milli: data.credits_milli,
                        grade_code: data.awarded_grade,
                    },
                    revision: "revision",
                    operation_id: "operation-" + ++reviews,
                    review_token: "private-proof-" + reviews,
                };
                if (deferred)
                    return new Promise((resolve) => {
                        release = () => resolve(review);
                    });
                return review;
            }
            if (path.endsWith("/confirm/") && failure) {
                const error = failure;
                failure = null;
                throw error;
            }
            if (path.endsWith("/confirm/") || path.includes("/changes/"))
                return { operation_id: "operation-" + reviews, action: "amend", version: 1 };
            return value;
        },
    };
    const component = window.createWorkspaceTransferAwards(ui);
    await component.record("student", "award");
    const click = async (label) => {
        const button = walk(body).find((e) => e.tag === "button" && e.textContent === label);
        assert.ok(button, label);
        button.attributes.onclick({ currentTarget: button });
        await pending;
    };
    const submit = () => {
        const form = walk(body).find((e) => e.tag === "form" && e.elements);
        form.events.submit({ preventDefault() {} });
        return pending;
    };
    const edit = async () => {
        await click("Correct credits or grade");
        const form = walk(body).find((e) => e.tag === "form");
        form.elements.credits_milli.value = 4;
        form.elements.awarded_grade.value = "B";
        form.elements.reason.value = "Fictional evidence <img>.";
        return form;
    };
    return {
        body: () => body,
        settled: () => pending,
        sent,
        value,
        click,
        submit,
        edit,
        fail: (status) => (failure = Object.assign(new Error("Fictional request failure"), { status })),
        defer: () => (deferred = true),
        release: () => release(),
        close: openDrawer,
    };
}
test("correction confirmation uses the server review operation and retains values when editing again", async () => {
    const f = await fixture();
    await f.edit();
    await f.submit();
    assert.match(f.body().textContent, /expires after 20 minutes/);
    assert.equal(
        walk(f.body()).some((e) => e.tag === "img"),
        false,
    );
    assert.equal(
        f.sent.some((r) => r.path === "/sis/ui/operation-id/"),
        false,
    );
    await f.click("Change correction");
    const form = walk(f.body()).find((e) => e.tag === "form");
    assert.equal(form.elements.credits_milli.value, 4);
    assert.equal(form.elements.awarded_grade.value, "B");
    assert.equal(form.elements.effective_on.value, "2026-08-10");
    assert.equal(form.elements.reason.value, "Fictional evidence <img>.");
    await f.submit();
    await f.click("Confirm academic correction");
    const data = f.sent.find((r) => r.path.endsWith("/confirm/")).data;
    assert.equal(data.operation_id, "operation-2");
    assert.equal(data.review_token, "private-proof-2");
    assert.equal(data.credits_milli, 4000);
});
test("expiry permits a fresh review with preserved values and a new operation", async () => {
    const f = await fixture();
    await f.edit();
    await f.submit();
    f.fail(409);
    await assert.rejects(f.click("Confirm academic correction"), /Fictional/);
    const change = walk(f.body()).find((e) => e.tag === "button" && e.textContent === "Change correction");
    assert.equal(change.disabled, false);
    await f.click("Change correction");
    assert.equal(
        walk(f.body()).find((e) => e.tag === "form").elements.reason.value,
        "Fictional evidence <img>.",
    );
    await f.submit();
    await f.click("Confirm academic correction");
    assert.deepEqual(
        f.sent.filter((r) => r.path.endsWith("/confirm/")).map((r) => r.data.operation_id),
        ["operation-1", "operation-2"],
    );
});
test("an interrupted confirmation retries the same proof and operation", async () => {
    const f = await fixture();
    await f.edit();
    await f.submit();
    f.fail(0);
    await assert.rejects(f.click("Confirm academic correction"), /Fictional/);
    await f.click("Confirm academic correction");
    const requests = f.sent.filter((r) => r.path.endsWith("/confirm/"));
    assert.deepEqual(requests[0].data, requests[1].data);
});
test("an interrupted request can check its saved receipt without resubmitting", async () => {
    const f = await fixture();
    await f.edit();
    await f.submit();
    f.fail(0);
    await assert.rejects(f.click("Confirm academic correction"), /Fictional/);
    await f.click("Check saved correction");
    assert.equal(f.sent.filter((r) => r.path.endsWith("/confirm/")).length, 1);
    assert.ok(f.sent.some((r) => r.path.endsWith("/changes/operation-1/")));
});
test("late review responses cannot reopen a closed private drawer", async () => {
    const f = await fixture();
    await f.edit();
    f.defer();
    const pending = f.submit();
    await Promise.resolve();
    const other = f.close();
    f.release();
    await pending;
    assert.equal(f.body(), other);
    assert.equal(other.children.length, 0);
});

test("academic dates share one review, retain edited values and apply completion bounds", async () => {
    const f = await fixture();
    await f.click("Academic details");
    let form = walk(f.body()).find((e) => e.tag === "form");
    assert.equal(form.elements.completed_on.value, "2026-05-01");
    assert.equal(form.elements.academic_on.max, "2026-05-01");
    form.elements.completed_on.value = "2026-05-12";
    form.elements.completed_on.events.input();
    assert.equal(form.elements.academic_on.max, "2026-05-12");
    assert.equal(form.elements.effective_on.min, "2026-05-12");
    form.elements.academic_on.value = "2026-01-12";
    form.elements.reason.value = "Fictional <img> evidence.";
    await f.submit();
    assert.match(f.body().textContent, /Completion: 2026-05-01 → 2026-05-12/);
    assert.equal(
        walk(f.body()).some((e) => e.tag === "img"),
        false,
    );
    await f.click("Revise details");
    form = walk(f.body()).find((e) => e.tag === "form");
    assert.equal(form.elements.completed_on.value, "2026-05-12");
    assert.equal(form.elements.reason.value, "Fictional <img> evidence.");
    f.value.credit.version = 2;
    await f.click("Refresh details");
    assert.equal(walk(f.body()).find((e) => e.tag === "form").elements.completed_on.value, "2026-05-12");
    await f.submit();
    assert.equal(f.sent.filter((r) => r.path.endsWith("/source-period/review/")).at(-1).data.version, 2);
    f.fail(0);
    await assert.rejects(f.click("Confirm details"), /Fictional/);
    await f.click("Confirm details");
    const commands = f.sent.filter((r) => r.path.endsWith("/source-period/commit/"));
    assert.equal(commands.length, 2);
    assert.deepEqual(commands[0].data, commands[1].data);
    assert.deepEqual(Object.keys(commands[0].data), ["review_token"]);
});

test("a closed academic dates editor is not replaced by its late review", async () => {
    const f = await fixture();
    await f.click("Academic details");
    const form = walk(f.body()).find((e) => e.tag === "form");
    form.elements.reason.value = "Fictional pending review.";
    f.defer();
    const pending = f.submit();
    await Promise.resolve();
    const replacement = f.close();
    f.release();
    await pending;
    assert.equal(f.body(), replacement);
    assert.equal(f.body().textContent, "");
});

test("posting term and source dates share one proof and survive revising and refreshing", async () => {
    const f = await fixture();
    await f.click("Academic details");
    let form = walk(f.body()).find((e) => e.tag === "form");
    assert.equal(form.elements.term_id.value, f.value.term_id);
    form.elements.term_id.value = "01K00000000000000000000061";
    form.elements.completed_on.value = "2026-05-12";
    form.elements.reason.value = "Fictional posting correction.";
    await f.submit();
    assert.match(f.body().textContent, /Posting term: 26\/FA → 27\/SP/);
    await f.click("Revise details");
    form = walk(f.body()).find((e) => e.tag === "form");
    assert.equal(form.elements.term_id.value, "01K00000000000000000000061");
    await f.click("Refresh details");
    assert.equal(
        walk(f.body()).find((e) => e.tag === "form").elements.term_id.value,
        "01K00000000000000000000061",
    );
    await f.submit();
    await f.click("Confirm details");
    assert.equal(f.value.term_code, "27/SP");
    assert.equal(f.value.completed_on, "2026-05-12");
    const commands = f.sent.filter((r) => r.path.endsWith("/source-period/commit/"));
    assert.equal(commands.length, 1);
    assert.deepEqual(Object.keys(commands[0].data), ["review_token"]);
});

test("receiving-course search reuses the editor and preserves reasons while requiring a new publication", async () => {
    const f = await fixture();
    await f.click("Review accepted course");
    let form = walk(f.body()).find((e) => e.tag === "form" && e.elements);
    form.elements.reason.value = "Fictional replacement evidence.";
    assert.equal(
        f.sent.some((r) => r.path.endsWith("/search/")),
        false,
    );
    const search = walk(f.body()).find((e) => e.tag === "input" && e.attributes.type === "search");
    search.value = "ENG";
    const searchForm = walk(f.body()).find((e) => e.tag === "form" && e.attributes.role === "search");
    await searchForm.events.submit({ preventDefault() {} });
    await f.settled();
    await f.click("ENG.220 · Fictional literature");
    form = walk(f.body()).find((e) => e.tag === "form" && e.elements);
    assert.equal(form.elements.reason.value, "Fictional replacement evidence.");
    assert.equal(form.elements.course_version_id.value, "");
    form.elements.course_version_id.value = "target-publication";
    await f.submit();
    assert.match(f.body().textContent, /replaces the receiving course/);
    await f.click("Revise accepted course");
    form = walk(f.body()).find((e) => e.tag === "form" && e.elements);
    assert.equal(form.elements.course_version_id.value, "target-publication");
    await f.click("Refresh award");
    await f.submit();
    f.fail(0);
    await assert.rejects(f.click("Confirm course acceptance"), /Fictional/);
    await f.click("Confirm course acceptance");
    assert.equal(f.value.credit.course_id, "course-target");
    assert.equal(f.value.credit.completed_milli, 3000);
    const commits = f.sent.filter((r) => r.path.endsWith("/accepted-course/commit/"));
    assert.deepEqual(commits[0].data, commits[1].data);
    assert.deepEqual(Object.keys(commits[0].data), ["review_token"]);
});

test("a late accepted-course review cannot reopen a closed private drawer", async () => {
    const f = await fixture();
    await f.click("Review accepted course");
    const form = walk(f.body()).find((e) => e.tag === "form" && e.elements);
    form.elements.course_version_id.value = "original-publication";
    form.elements.reason.value = "Fictional pending review.";
    f.defer();
    const pending = f.submit();
    await Promise.resolve();
    const closed = f.close();
    f.release();
    await pending;
    assert.equal(f.body(), closed);
    assert.equal(closed.textContent, "");
});
