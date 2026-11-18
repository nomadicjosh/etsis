"use strict";
const test = require("node:test"),
    assert = require("node:assert/strict");
const fs = require("node:fs"),
    vm = require("node:vm");
const { node, walk } = require("./support/elements.cjs");
async function fixture({
    tracked = true,
    returnFlow = false,
    interrupted = false,
} = {}) {
    const window = {},
        body = node("div"),
        sent = [];
    vm.runInNewContext(
        fs.readFileSync("public/assets/js/workspace-hiatus.js", "utf8"),
        { window },
        { timeout: 500 },
    );
    const person = {
        active: !returnFlow,
        given_name: "Fictional",
        family_name: "Student",
        institutional_id: "EXAMPLE",
    };
    let pending,
        commits = 0;
    const ui = window.createWorkspaceHiatus({
        node,
        table: (headers, rows) => node("table", {}, rows),
        badge: (text) => node("span", {}, text),
        openDrawer: () => {
            body.replaceChildren();
            return body;
        },
        task: (run) => {
            pending = Promise.resolve().then(run);
            return pending;
        },
        refreshDirectory: async () => {},
        financeReviews: () => ({ handoff: async () => {} }),
        buildForm: async (fields, values = {}) => {
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
        formData: (form, fields) =>
            Object.fromEntries(
                fields.map(([name]) => [name, form.elements[name].value]),
            ),
        request: async (path, method, data) => {
            sent.push({ path, method, data });
            if (path.endsWith("/hiatuses/"))
                return {
                    student: person,
                    items: [],
                    next_after: null,
                    today: "2026-08-10",
                    timezone: "UTC",
                };
            if (path.endsWith("/history/"))
                return {
                    next_after: null,
                    items: [
                        {
                            action: "created",
                            created_at: "2026-08-10",
                            actor_name: "Fictional Registrar",
                            note: "<img> Private reason",
                            history_tracked: tracked,
                        },
                    ],
                };
            if (path.endsWith("/review/"))
                return returnFlow
                    ? {
                          student: person,
                          operation_id: "return-op",
                          revision: "revision",
                          review_token: "opaque-return-proof",
                          primary_program: {
                              code: "BA",
                              title: "Fictional Arts",
                          },
                          entry_term: { code: "26/FA" },
                          hiatus_label: "Leave",
                          hiatus_starts_on: "2026-08-01",
                          hiatus_ends_on: null,
                          return_on: data.return_on,
                          staff_registration_blocked: false,
                          self_registration_blocked: false,
                      }
                    : {
                          student: person,
                          operation_id: "start-op",
                          revision: "revision",
                          review_token: "opaque-start-proof",
                          effective_now: true,
                          label: "Leave",
                          starts_on: data.starts_on,
                          defaults: {
                              section_ids: ["section"],
                              deactivate: true,
                          },
                          courses: [
                              {
                                  section_id: "section",
                                  section_code: "TEST.101",
                                  course_title: "Fictional Course",
                                  term_code: "26/FA",
                                  eligible: true,
                                  proposed_status: "D",
                              },
                          ],
                      };
            if (path.endsWith("/commit/")) {
                if (interrupted && ++commits === 1)
                    throw Error("Fictional connection interruption");
                return returnFlow
                    ? {
                          operation_id: "return-op",
                          result: {
                              return_on: data.return_on,
                              student_active: data.reactivate,
                          },
                      }
                    : {
                          id: "start-op",
                          impact: {
                              course_changes: [],
                              student_active: !data.deactivate,
                          },
                      };
            }
            return {
                id: "case",
                label: "Leave",
                code: "LOA",
                starts_on: "2026-08-01",
                state: "Current",
                history_tracked: tracked,
                comment: "<img> Private reason",
                voided: false,
            };
        },
    });
    const find = (label) =>
        walk(body).find((e) => e.tag === "button" && e.textContent === label);
    const click = async (label) => {
        const button = find(label);
        assert.ok(button, label);
        await button.attributes.onclick({ currentTarget: button });
    };
    const submit = async () => {
        walk(body)
            .find((e) => e.tag === "form")
            .events.submit({ preventDefault() {} });
        await pending;
    };
    await (returnFlow ? ui.record("student", "case") : ui.page("student"));
    return {
        body,
        sent,
        click,
        submit,
        field: (name) => walk(body).find((e) => e.name === name),
    };
}
test("start review preserves staff choices when revising and transports its proof on confirmation", async () => {
    const f = await fixture();
    await f.click("Record a hiatus");
    f.field("starts_on").value = "2026-08-10";
    f.field("note").value = "<img> Fictional note";
    await f.submit();
    for (const id of ["hiatus-deactivate", "hiatus-choice-section"]) {
        const e = walk(f.body).find((e) => e.id === id);
        e.checked = false;
        e.events.change();
    }
    await f.click("Change details");
    assert.equal(f.field("note").value, "<img> Fictional note");
    await f.submit();
    assert.equal(
        walk(f.body).find((e) => e.id === "hiatus-deactivate").checked,
        false,
    );
    assert.equal(
        walk(f.body).find((e) => e.id === "hiatus-choice-section").checked,
        false,
    );
    assert.equal(
        f.sent.some((r) => r.path.endsWith("/commit/")),
        false,
    );
    await f.click("Confirm hiatus plan");
    const command = f.sent.find((r) => r.path.endsWith("/commit/")).data;
    assert.equal(command.review_token, "opaque-start-proof");
    assert.equal(command.deactivate, false);
    assert.equal(command.section_ids.length, 0);
});
test("an interrupted return retries the same proof and keeps reactivation when details are revised", async () => {
    const f = await fixture({ returnFlow: true, interrupted: true });
    await f.click("Review return / readmission");
    f.field("return_on").value = "2026-08-11";
    f.field("note").value = "Fictional return";
    await f.submit();
    walk(f.body).find((e) => e.id === "return-reactivate").checked = true;
    await f.click("Change return details");
    assert.equal(f.field("note").value, "Fictional return");
    await f.submit();
    assert.equal(
        walk(f.body).find((e) => e.id === "return-reactivate").checked,
        true,
    );
    await assert.rejects(f.click("Confirm return decision"), /interruption/);
    await f.click("Confirm return decision");
    const commands = f.sent.filter((r) => r.path.endsWith("/commit/"));
    assert.deepEqual(commands[0].data, commands[1].data);
    assert.equal(commands[0].data.review_token, "opaque-return-proof");
});
test("curated history distinguishes verified decisions from earlier evidence and renders private notes as text", async () => {
    for (const tracked of [true, false]) {
        const f = await fixture({ returnFlow: true, tracked });
        assert.match(
            f.body.textContent,
            tracked
                ? /verified decision history/
                : /original decision history may be incomplete/,
        );
        assert.match(
            f.body.textContent,
            tracked ? /Verified decision/ : /Earlier case evidence/,
        );
        assert.equal(
            walk(f.body).some((e) => e.tag === "img"),
            false,
        );
        assert.equal(
            f.sent.some((r) => r.path.endsWith("/commit/")),
            false,
        );
    }
});
