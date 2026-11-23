"use strict";
const assert = require("node:assert/strict");
const { readFileSync } = require("node:fs");
const { webcrypto } = require("node:crypto");
const { runInNewContext } = require("node:vm");
const { test } = require("node:test");
const source = readFileSync("public/assets/js/workspace-form-library.js", "utf8");
const key = "etsis.form-library.collapses";
function fixture(raw = null, fail = null) {
    const values = new Map(raw === null ? [] : [[key, raw]]),
        writes = [];
    const storage = {
        getItem(name) {
            if (fail === "read") throw new Error("Fictional blocked storage");
            return values.get(name) || null;
        },
        setItem(name, value) {
            if (fail === "write") throw new Error("Fictional quota");
            values.set(name, value);
            writes.push({ name, value });
        },
    };
    const window = { crypto: webcrypto, localStorage: storage };
    runInNewContext(source, { window, TextEncoder });
    return { preferences: window.createApplicationFormPreferences(), values, writes };
}
test("reading defaults and unsaved display state never persists a form draft", async () => {
    const f = fixture();
    assert.equal(f.preferences.library(true), true);
    const transient = f.preferences.transient();
    transient.write({ fields: [true], settings: [], containers: [] });
    assert.equal(f.writes.length, 0);
    assert.equal(transient.snapshot().fields[0], true);
});
test("only booleans and opaque form hashes are serialized, even for an accidental private payload", async () => {
    const f = fixture(),
        id = "01K00000000000000000000003";
    const record = await f.preferences.record(id);
    record.write({
        fields: [true],
        settings: [false],
        containers: [true],
        answers: { name: "Fictional private applicant" },
        name: "Private form",
        revision: 5,
    });
    let encoded = f.values.get(key),
        state = JSON.parse(encoded);
    assert.equal(Object.keys(state.forms).length, 1);
    assert.match(Object.keys(state.forms)[0], /^[a-f0-9]{64}$/);
    assert.deepEqual(Object.values(state.forms)[0], {
        fields: [true],
        settings: [false],
        containers: [true],
    });
    for (const forbidden of [id, "Fictional private", "Private form", "answers", "revision"])
        assert.equal(encoded.includes(forbidden), false);
    record.write({
        fields: [{ label: "Private field" }],
        settings: ["private"],
        containers: [false],
    });
    encoded = f.values.get(key);
    state = JSON.parse(encoded);
    assert.deepEqual(Object.values(state.forms)[0], {
        fields: [],
        settings: [],
        containers: [false],
    });
    assert.equal(encoded.includes("Private field"), false);
});
test("preference retention and disclosure arrays stay bounded", async () => {
    const f = fixture();
    for (let index = 0; index < 30; index++) {
        const record = await f.preferences.record(`fictional-form-${index}`);
        record.write({
            fields: Array(100).fill(true),
            settings: Array(100).fill(false),
            containers: Array(100).fill(true),
        });
    }
    const encoded = f.values.get(key),
        state = JSON.parse(encoded);
    assert.equal(Object.keys(state.forms).length, 25);
    assert.ok(encoded.length < 65536);
    for (const record of Object.values(state.forms))
        for (const list of Object.values(record)) assert.equal(list.length, 40);
    assert.equal((await f.preferences.record("fictional-form-0")).stored, false);
    assert.equal((await f.preferences.record("fictional-form-29")).stored, true);
});
test("malformed, oversized or unrecognized preferences reset safely on an explicit display change", () => {
    for (const raw of [
        "{broken",
        "x".repeat(65537),
        JSON.stringify({ library: "false", forms: { secret: { answers: "private" } } }),
    ]) {
        const f = fixture(raw);
        assert.equal(f.preferences.library(true), true);
        assert.equal(f.writes.length, 0);
        f.preferences.showLibrary(false);
        assert.deepEqual(JSON.parse(f.values.get(key)), { library: false, forms: {} });
    }
});
test("blocked storage and quota failure retain usable in-memory display preferences", async () => {
    for (const fail of ["read", "write"]) {
        const f = fixture(null, fail);
        assert.equal(f.preferences.library(true), true);
        f.preferences.showLibrary(false);
        assert.equal(f.preferences.library(true), false);
        const record = await f.preferences.record("fictional-saved-form");
        record.write({ fields: [true] });
        assert.equal(record.snapshot().fields[0], true);
        assert.equal(f.writes.length, 0);
    }
});
