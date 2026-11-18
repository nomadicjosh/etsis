"use strict";
const test = require("node:test"),
    assert = require("node:assert/strict");
const fs = require("node:fs"),
    vm = require("node:vm");
const { node: element, walk } = require("./support/elements.cjs");
async function fixture({
    saved = false,
    interrupted = false,
    reviewGate = null,
    deliveryGate = null,
    prepareGate = null,
} = {}) {
    const window = {},
        sent = [];
    vm.runInNewContext(
        fs.readFileSync("public/assets/js/workspace-transcripts.js", "utf8"),
        { window },
        { timeout: 500 },
    );
    const node = (...args) => {
        const e = element(...args);
        Object.defineProperty(e, "childElementCount", {
            get: () => e.children.length,
        });
        return e;
    };
    let body,
        pending,
        prep = 0,
        delivery = 0,
        fulfilled = false;
    const snapshot = {
        student: { name: "Fictional Student", institutional_id: "EXAMPLE" },
        program: { code: "BA", title: "Fictional Arts" },
        issuer: { name: "Fictional University" },
        credits: [],
        totals: { attempted_milli: 0, completed_milli: 0, gpa_milli: null },
    };
    const metadata = {
        id: "document",
        created_at: "2026-08-10",
        prepared_version: 1,
    };
    const close = () => {
        if (body) for (const e of walk(body)) e.isConnected = false;
    };
    const ui = window.createWorkspaceTranscripts({
        node,
        openDrawer: () => {
            close();
            body = node("div");
            return body;
        },
        can: () => true,
        badge: (text) => node("span", {}, text),
        table: (headers, rows) => node("table", {}, rows),
        recordSummary: (rows) => node("dl", {}, rows),
        refreshDirectory: async () => {},
        task: (run) => {
            pending = Promise.resolve().then(run);
            return pending;
        },
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
        formData: (form) => ({
            evidence: form.elements.evidence.value,
            delivered_at: new Date(
                form.elements.delivered_at.value,
            ).toISOString(),
        }),
        request: async (path, method, data) => {
            sent.push({ path, method, data });
            if (path.endsWith("/fulfillment/"))
                return {
                    status: fulfilled
                        ? "fulfilled"
                        : saved
                          ? "ready"
                          : "pending",
                    version: saved ? 1 : 0,
                    document: saved ? metadata : null,
                };
            if (path.endsWith("/review/")) {
                if (reviewGate) await reviewGate;
                return {
                    snapshot,
                    operation_id: "document",
                    revision: "revision",
                    review_token: "opaque-preparation-proof",
                    delivery: "pickup",
                };
            }
            if (path.endsWith("/prepare/")) {
                if (prepareGate) await prepareGate;
                if (interrupted && ++prep === 1)
                    throw Error("Fictional interrupted preparation");
                saved = true;
                return metadata;
            }
            if (path.endsWith("/delivery-review/")) {
                if (deliveryGate) await deliveryGate;
                return {
                    operation_id: "delivery",
                    review_token: "opaque-delivery-proof",
                    delivery: data,
                };
            }
            if (path.endsWith("/deliver/")) {
                if (interrupted && ++delivery === 1)
                    throw Error("Fictional interrupted delivery");
                fulfilled = true;
                return { status: "fulfilled" };
            }
            if (path.includes("/documents/"))
                return {
                    ...metadata,
                    snapshot,
                    is_latest: true,
                    academic_changed: false,
                    delivery: null,
                };
            return { status: "pending", delivery: "pickup" };
        },
    });
    await ui.record("request");
    const find = (text) =>
        walk(body).find((e) => e.tag === "button" && e.textContent === text);
    const click = async (text) => {
        const e = find(text);
        assert.ok(e, text);
        await e.attributes.onclick({ currentTarget: e });
    };
    const submit = async () => {
        walk(body)
            .find((e) => e.tag === "form")
            .events.submit({ preventDefault() {} });
        await pending;
    };
    return {
        get body() {
            return body;
        },
        sent,
        find,
        click,
        submit,
        close,
        field: (name) => walk(body).find((e) => e.name === name),
    };
}
test("preparation requires explicit release review and keeps its exact proof for an interrupted retry", async () => {
    const f = await fixture({ interrupted: true });
    await f.click("Review preparation");
    assert.equal(f.find("Prepare transcript").disabled, true);
    assert.match(f.body.textContent, /20 minutes/);
    const check = walk(f.body).find(
        (e) => e.id === "transcript-release-reviewed",
    );
    check.checked = true;
    check.events.change();
    assert.equal(f.find("Prepare transcript").disabled, false);
    assert.equal(
        f.sent.some((r) => r.path.endsWith("/prepare/")),
        false,
    );
    await assert.rejects(f.click("Prepare transcript"), /interrupted/);
    await f.click("Prepare transcript");
    const commands = f.sent.filter((r) => r.path.endsWith("/prepare/"));
    assert.deepEqual(commands[0].data, commands[1].data);
    assert.equal(commands[0].data.review_token, "opaque-preparation-proof");
});
test("delivery has a distinct review, preserves local fields on revision and repeats only the accepted inputs", async () => {
    const f = await fixture({ saved: true, interrupted: true });
    await f.click("Review transcript");
    await f.click("Record delivery");
    f.field("delivered_at").value = "2026-08-10T12:30";
    f.field("evidence").value = "<img> Fictional private evidence";
    await f.submit();
    assert.match(f.body.textContent, /<img> Fictional private evidence/);
    assert.equal(
        walk(f.body).some((e) => e.tag === "img"),
        false,
    );
    assert.equal(
        f.sent.some((r) => r.path.endsWith("/deliver/")),
        false,
    );
    await f.click("Change details");
    assert.equal(f.field("delivered_at").value, "2026-08-10T12:30");
    assert.equal(f.field("evidence").value, "<img> Fictional private evidence");
    await f.submit();
    await assert.rejects(f.click("Confirm delivery"), /interrupted/);
    await f.click("Confirm delivery");
    const commands = f.sent.filter((r) => r.path.endsWith("/deliver/"));
    assert.deepEqual(commands[0].data, commands[1].data);
    assert.equal(commands[0].data.review_token, "opaque-delivery-proof");
    assert.equal(
        f.sent.some((r) => r.path === "/sis/ui/operation-id/"),
        false,
    );
});
test("refreshing preparation requires another explicit release acknowledgement", async () => {
    const f = await fixture();
    await f.click("Review preparation");
    const check = walk(f.body).find(
        (e) => e.id === "transcript-release-reviewed",
    );
    check.checked = true;
    check.events.change();
    await f.click("Refresh review");
    assert.equal(f.find("Prepare transcript").disabled, true);
    assert.equal(
        walk(f.body).find((e) => e.id === "transcript-release-reviewed")
            .checked,
        false,
    );
    assert.equal(
        f.sent.some((r) => r.path.endsWith("/prepare/")),
        false,
    );
});
test("dismissed preparation and delivery reviews ignore delayed responses", async () => {
    let resolve;
    const gate = new Promise((r) => {
        resolve = r;
    });
    const f = await fixture({ reviewGate: gate });
    const pending = f.click("Review preparation");
    await Promise.resolve();
    f.close();
    resolve();
    await pending;
    assert.equal(f.find("Prepare transcript"), undefined);
    let finish;
    const deliveryGate = new Promise((r) => {
        finish = r;
    });
    const d = await fixture({ saved: true, deliveryGate });
    await d.click("Review transcript");
    await d.click("Record delivery");
    d.field("delivered_at").value = "2026-08-10T12:30";
    d.field("evidence").value = "Fictional evidence";
    const loading = d.submit();
    await Promise.resolve();
    d.close();
    finish();
    await loading;
    assert.equal(d.find("Confirm delivery"), undefined);
    assert.equal(
        d.sent.some((r) => r.path.endsWith("/deliver/")),
        false,
    );
});
test("a preparation accepted after its screen closes does not reopen private academic content", async () => {
    let finish;
    const gate = new Promise((r) => {
        finish = r;
    });
    const f = await fixture({ prepareGate: gate });
    await f.click("Review preparation");
    const check = walk(f.body).find(
        (e) => e.id === "transcript-release-reviewed",
    );
    check.checked = true;
    check.events.change();
    const saving = f.click("Prepare transcript");
    await Promise.resolve();
    f.close();
    finish();
    await saving;
    assert.equal(
        f.sent.some((r) => r.path.includes("/documents/")),
        false,
    );
});
