"use strict";
const test = require("node:test"),
    assert = require("node:assert/strict");
const fs = require("node:fs"),
    vm = require("node:vm");
const { node, walk } = require("./support/elements.cjs");
const presentation = fs.readFileSync(
    "public/assets/js/registration-schedule.js",
    "utf8",
);
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
        p.panel({ status: "not_fully_checked", unpublished_count: 2 })
            .textContent,
        /2 section schedules are unpublished/,
    );
    assert.match(p.text({ status: "not_recorded" }), /not recorded/);
    assert.match(p.text({ status: "clear" }), /No overlaps in published/);
    assert.equal(
        walk(p.panel(conflict(true))).some((e) => e.tag === "img"),
        false,
    );
});
async function staff(blocked) {
    const window = {},
        sent = [];
    let body, pending;
    vm.runInNewContext(presentation, { window });
    vm.runInNewContext(
        fs.readFileSync("public/assets/js/workspace-registration.js", "utf8"),
        { window },
    );
    const component = window.createWorkspaceRegistration({
        node,
        openDrawer: () => {
            body = node("div");
            return body;
        },
        task: (run) => {
            pending = Promise.resolve().then(run);
            return pending;
        },
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
            return path.endsWith("/review/") ? conflict(blocked) : {};
        },
    });
    let returned = false;
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
        find: (text) =>
            walk(body).find(
                (e) => e.tag === "button" && e.textContent === text,
            ),
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
    assert.equal(
        warn.sent.find((r) => r.path === "/sis/registrations/").data.by_staff,
        true,
    );
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
        pageLeaving: false, portalDialogGeneration: 0,
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
        one: (selector, value) =>
            value
                ? walk(value).find((e) => e.attributes.type === "submit")
                : {},
        bootstrap: { Modal: { getInstance: () => ({ hide() {} }) } },
        notice() {},
        renderService: async () => {},
        request: async (path, method, data) => {
            sent.push({ path, method, data });
            if (gate) await gate;
            return result;
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
    assert.equal(
        walk(f.form).find((e) => e.attributes.type === "submit").disabled,
        true,
    );
    await f.ready();
    assert.equal(
        walk(f.form).find((e) => e.attributes.type === "submit").disabled,
        true,
    );
    assert.equal(f.sent.length, 1);
    assert.equal(f.sent[0].data.student_id, "student");
    assert.match(f.form.textContent, /registration blocked/);
    const warn = await portal({ result: conflict(false) });
    await warn.ready();
    assert.equal(
        walk(warn.form).find((e) => e.attributes.type === "submit").disabled,
        false,
    );
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
    assert.equal(
        walk(f.form).find((e) => e.attributes.type === "submit").disabled,
        false,
    );
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
    assert.equal(
        walk(f.form).find((e) => e.attributes.type === "submit").disabled,
        true,
    );
    assert.equal(f.form.textContent.includes("TEST.101 <img>"), false);
    assert.equal(
        f.sent.some((r) => r.path === "/portal/api/register/"),
        false,
    );
});
