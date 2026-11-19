"use strict";
const test = require("node:test"),
    assert = require("node:assert/strict");
const fs = require("node:fs"),
    vm = require("node:vm");
const { node, walk } = require("./support/elements.cjs");
const presentation = fs.readFileSync("public/assets/js/registration-schedule.js", "utf8");
const conflict = (blocked) => ({
    status: "conflict",
    mode: blocked ? "block" : "warn",
    blocked,
    conflict_count: 1,
    unpublished_count: 0,
    conflicts: [
        {
            section_code: "TEST.101 <img>",
            starts_at: "2026-09-01T16:30:00Z",
            ends_at: "2026-09-01T17:00:00Z",
        },
    ],
});
const registrationReview = (schedule) => ({
    operation_id: "operation",
    revision: "revision",
    review_token: "private-proof",
    section: {
        id: "section",
        active: true,
        seats_remaining: 20,
        code: "A",
        course_code: "TEST.101",
        course_title: "Fictional course",
        credits_milli: 3000,
        term_starts_on: "2026-08-01",
        term_ends_on: "2026-12-31",
    },
    students: [
        {
            id: "student",
            given_name: "Fictional",
            family_name: "Student",
            institutional_id: "TEST-42",
            active: true,
            enrollment_id: null,
            issues: [],
            prerequisites: { status: "not_required" },
            schedule,
            charges: {
                currency: "USD",
                total_minor: 1000,
                items: [
                    { name: "Section fee", basis: "section", minor: 1000, currency: "USD", status: "new" },
                ],
                problems: [],
            },
        },
    ],
});
function presenter() {
    const window = {};
    vm.runInNewContext(presentation, { window });
    return window.createRegistrationSchedule({ node });
}
test("shared text distinguishes blocking, warnings, unpublished schedules and earlier unknown history", () => {
    const p = presenter();
    assert.match(p.panel(conflict(true)).textContent, /registration blocked/);
    assert.match(p.panel(conflict(false)).textContent, /warning/);
    assert.match(
        p.panel({ status: "not_fully_checked", unpublished_count: 2 }).textContent,
        /2 section schedules are unpublished/,
    );
    assert.match(p.text({ status: "not_recorded" }), /not recorded/);
    assert.match(p.text({ status: "clear" }), /No overlaps in published/);
    assert.equal(
        walk(p.panel(conflict(true))).some((e) => e.tag === "img"),
        false,
    );
});
async function staff(blocked, group = false) {
    const window = {},
        sent = [];
    let body, pending;
    vm.runInNewContext(presentation, { window });
    vm.runInNewContext(fs.readFileSync("public/assets/js/workspace-registration.js", "utf8"), { window });
    const component = window.createWorkspaceRegistration({
        node,
        table: (headers, rows) => node("table", {}, rows.flat()),
        recordSummary: (rows) => node("dl", {}, rows.flat()),
        openDrawer: () => {
            body = node("div");
            return body;
        },
        task: (run) => {
            pending = Promise.resolve().then(run);
            return pending;
        },
        money: (minor, currency) => `${minor / 100} ${currency}`,
        refreshDirectory: async () => {},
        buildForm: async (fields, values) => {
            const form = node("form");
            form.elements = {};
            for (const [name] of fields) {
                const field = node("input", {
                    name,
                    value: values[name] ?? "",
                });
                form.elements[name] = field;
                form.append(field);
            }
            return form;
        },
        request: async (path, method, data) => {
            sent.push({ path, method, data });
            return path.endsWith("/review/") ? registrationReview(conflict(blocked)) : {};
        },
    });
    let returned = false;
    if (group)
        await component.start("section", [
            { id: "student", name: "Fictional Student", institutional_id: "TEST-42" },
        ]);
    else
        await component.single("student", async () => {
            returned = true;
        });
    const form = walk(body).find((e) => e.tag === "form");
    form.elements.section_id.value = "section";
    form.events.submit({ preventDefault() {} });
    await pending;
    return {
        body,
        sent,
        returned: () => returned,
        find: (text) => walk(body).find((e) => e.tag === "button" && e.textContent === text),
    };
}
test("staff single registration previews overlaps and disables only a blocked confirmation", async () => {
    const blocked = await staff(true);
    assert.equal(blocked.find("Confirm registration").disabled, true);
    assert.equal(
        blocked.sent.some((r) => r.path === "/sis/registrations/"),
        false,
    );
    const warn = await staff(false);
    const confirm = warn.find("Confirm registration");
    assert.equal(confirm.disabled, false);
    await confirm.attributes.onclick({ currentTarget: confirm });
    assert.equal(warn.returned(), true);
    assert.equal(warn.sent.find((r) => r.path === "/sis/registrations/").data.by_staff, true);
});
async function portal({ result = conflict(true), gate = null } = {}) {
    const source = fs.readFileSync("public/assets/js/portal.js", "utf8");
    const code = source.slice(
        source.indexOf("    function confirmRegistration(course) {"),
        source.indexOf("    function transcripts(container) {"),
    );
    const window = {},
        sent = [];
    let form, pending;
    vm.runInNewContext(presentation, { window });
    const context = {
        window,
        el: node,
        me: { students: [{ id: "student", primary_program_id: "program" }] },
        pageLeaving: false,
        portalDialogGeneration: 0,
        programName: () => "Fictional Program",
        amount: () => "10.00 USD",
        control: (f, name, label) => {
            const e = node("select", { name });
            f.append(e);
            return e;
        },
        selectOptions: () => {},
        dialog: (title, value) => {
            form = value;
        },
        task: (run) => {
            pending = Promise.resolve().then(run);
            return pending;
        },
        one: (selector, value) => (value ? walk(value).find((e) => e.attributes.type === "submit") : {}),
        bootstrap: { Modal: { getInstance: () => ({ hide() {} }) } },
        notice() {},
        renderService: async () => {},
        request: async (path, method, data) => {
            sent.push({ path, method, data });
            if (gate) await gate;
            return path.endsWith("/review/") ? registrationReview(result) : {};
        },
    };
    vm.createContext(context);
    vm.runInContext(code, context);
    context.confirmRegistration({
        id: "section",
        course_code: "TEST.101",
        title: "Fictional",
        section_code: "A",
        fee_minor: 1000,
        currency: "USD",
    });
    return {
        get form() {
            return form;
        },
        sent,
        context,
        ready: () => pending,
        submit: async () => {
            form.events.submit({ preventDefault() {} });
            await pending;
        },
    };
}
test("student registration waits for the owner-specific review before enabling confirmation", async () => {
    const f = await portal();
    assert.equal(walk(f.form).find((e) => e.attributes.type === "submit").disabled, true);
    await f.ready();
    assert.equal(walk(f.form).find((e) => e.attributes.type === "submit").disabled, true);
    assert.equal(f.sent.length, 1);
    assert.equal(f.sent[0].data.student_id, "student");
    assert.match(f.form.textContent, /registration blocked/);
    const warn = await portal({ result: conflict(false) });
    await warn.ready();
    assert.equal(walk(warn.form).find((e) => e.attributes.type === "submit").disabled, false);
    await warn.submit();
    assert.equal(warn.sent.at(-1).path, "/portal/api/register/");
});
test("an unpublished student schedule is explained without claiming a clear timetable", async () => {
    const f = await portal({
        result: {
            status: "not_fully_checked",
            unpublished_count: 1,
            blocked: false,
        },
    });
    await f.ready();
    assert.match(f.form.textContent, /unpublished/);
    assert.equal(f.form.textContent.includes("No overlaps"), false);
    assert.equal(walk(f.form).find((e) => e.attributes.type === "submit").disabled, false);
});
test("a dismissed student dialog ignores a late private review response", async () => {
    let finish;
    const gate = new Promise((resolve) => {
        finish = resolve;
    });
    const f = await portal({ gate });
    await Promise.resolve();
    f.context.portalDialogGeneration++;
    finish();
    await f.ready();
    assert.equal(walk(f.form).find((e) => e.attributes.type === "submit").disabled, true);
    assert.equal(f.form.textContent.includes("TEST.101 <img>"), false);
    assert.equal(
        f.sent.some((r) => r.path === "/portal/api/register/"),
        false,
    );
});

test("the shared registration review explains exact new prices without rendering tokens or markup", () => {
    const p = presenter();
    const review = registrationReview({ status: "clear", blocked: false });
    review.students[0].charges = {
        currency: "USD",
        total_minor: 1375,
        problems: [],
        items: [
            {
                name: "Tuition <script>",
                basis: "registered_credit",
                minor: 375,
                currency: "USD",
                status: "new",
                rate_minor: 125,
                credits_milli: 3000,
                term_starts_on: "2026-08-01",
                term_ends_on: "2026-12-31",
            },
            { name: "Term fee", basis: "term", minor: 500, currency: "USD", status: "not_due" },
            { name: "Section fee", basis: "section", minor: 1000, currency: "USD", status: "new" },
        ],
    };
    const panel = p.registration(review, (minor, currency) => `${(minor / 100).toFixed(2)} ${currency}`);
    assert.match(panel.textContent, /13.75 USD/);
    assert.match(panel.textContent, /3 credits at 1.25 USD per credit/);
    assert.match(panel.textContent, /Not added with this registration/);
    assert.match(panel.textContent, /not an account balance/);
    assert.equal(panel.textContent.includes(review.review_token), false);
    assert.equal(
        walk(panel).some((e) => e.tag === "script"),
        false,
    );
});
test("pricing, holds, missing requirements and insufficient seats disable the shared confirmation", () => {
    const p = presenter();
    for (const problem of ["price", "hold", "prerequisite", "seat", "section"]) {
        const review = registrationReview({ status: "clear", blocked: false });
        if (problem === "price") review.students[0].charges.problems.push("Resolve pricing.");
        if (problem === "prerequisite") review.students[0].prerequisites.status = "missing";
        if (problem === "hold")
            review.students[0].issues.push("An active restriction prevents registration.");
        if (problem === "seat") review.section.seats_remaining = 0;
        if (problem === "section") review.section.active = false;
        assert.equal(p.canConfirm(review), false, problem);
    }
    const existing = registrationReview({ status: "not_rechecked", blocked: false });
    existing.students[0].enrollment_id = "existing";
    existing.students[0].enrollment_status = "N";
    existing.students[0].charges = { currency: "USD", total_minor: 0, items: [], problems: [] };
    existing.section.seats_remaining = 0;
    existing.section.active = false;
    assert.equal(p.canConfirm(existing), true);
    assert.match(p.registration(existing, String).textContent, /creates no new charges/);
});
test("staff and student confirmations send the exact reviewed operation, revision and private proof", async () => {
    const f = await staff(false);
    await f
        .find("Confirm registration")
        .attributes.onclick({ currentTarget: f.find("Confirm registration") });
    const payload = f.sent.find((entry) => entry.path === "/sis/registrations/").data;
    assert.equal(payload.operation_id, "operation");
    assert.equal(payload.revision, "revision");
    assert.equal(payload.review_token, "private-proof");
    const own = await portal({ result: { status: "clear", blocked: false } });
    await own.ready();
    await own.submit();
    assert.equal(own.sent.at(-1).data.review_token, "private-proof");
    assert.equal(own.sent.at(-1).data.by_staff, undefined);
    assert.ok(walk(own.form).some((e) => e.tag === "button" && e.textContent === "Refresh review"));
});

test("group registration lazily presents each price and carries the reviewed proof into confirmation", async () => {
    const f = await staff(false, true);
    const details = walk(f.body).find((e) => e.tag === "details");
    assert.ok(details);
    assert.equal(details.textContent.includes("New registration charges"), false);
    details.open = true;
    details.events.toggle();
    assert.match(details.textContent, /New registration charges/);
    assert.match(details.textContent, /Section fee/);
    details.open = false;
    details.events.toggle();
    assert.equal(details.textContent.includes("New registration charges"), false);
    const confirm = f.find("Confirm group registration");
    assert.equal(confirm.disabled, false);
    await confirm.attributes.onclick({ currentTarget: confirm });
    const saved = f.sent.find((entry) => entry.path.endsWith("/commit/"));
    assert.equal(saved.data.review_token, "private-proof");
    assert.equal(saved.data.operation_id, "operation");
    assert.equal(saved.data.revision, "revision");
});

test("prerequisite details render lazily from captured labels and omit private support", () => {
    const window = {};
    vm.runInNewContext(presentation, { window });
    const ui = window.createRegistrationSchedule({ node });
    const details = ui.prerequisites({ course_statuses: ["met", "missing"] }, [
        { code: "MATH.101", title: "Fictional <img> algebra" },
        { code: "ENG.101", title: "Fictional writing" },
    ]);
    assert.equal(details.textContent, "Required courses");
    details.open = true;
    details.events.toggle();
    assert.match(details.textContent, /MATH.101.*Verified completed coursework/);
    assert.match(details.textContent, /ENG.101.*Passing coursework not verified for this pursuit/);
    assert.equal(
        walk(details).some((e) => e.tag === "img"),
        false,
    );
    const length = details.textContent.length;
    details.events.toggle();
    assert.equal(details.textContent.length, length);
    assert.equal(ui.prerequisites({ course_statuses: [] }, []), null);
});

test("registration next steps use captured checks and render only when opened", () => {
    const ui = presenter();
    const details = ui.readiness([
        {
            code: "restrictions",
            label: "Registration restrictions",
            status: "blocked",
            message: "An active restriction prevents registration.",
            next_step: "Fictional help <img>.",
        },
    ]);
    assert.equal(details.textContent, "Registration checks");
    details.open = true;
    details.events.toggle();
    assert.match(details.textContent, /Needs attention/);
    assert.match(details.textContent, /Fictional help <img>/);
    assert.equal(
        walk(details).some((e) => e.tag === "img"),
        false,
    );
    assert.equal(ui.readiness([]), null);
});
