import { mkdir, copyFile, cp, rm, readFile, writeFile } from "node:fs/promises";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { build } from "esbuild";
const root = new URL("../", import.meta.url);
const assets = new URL("public/assets/vendor/", root);
await mkdir(assets, { recursive: true });
const files = {
    "bootstrap/dist/css/bootstrap.min.css": "bootstrap.min.css",
    "bootstrap/dist/js/bootstrap.bundle.min.js": "bootstrap.bundle.min.js",
    "bootstrap/LICENSE": "bootstrap.LICENSE",
    "jquery/dist/jquery.min.js": "jquery.min.js",
    "jquery/LICENSE.txt": "jquery.LICENSE",
    "chart.js/dist/chart.umd.js": "chart.umd.js",
    "chart.js/LICENSE.md": "chart.LICENSE",
    "jQuery-QueryBuilder/dist/js/query-builder.standalone.js":
        "query-builder.min.js",
    "jQuery-QueryBuilder/dist/css/query-builder.default.css":
        "query-builder.min.css",
    "jQuery-QueryBuilder/LICENSE": "query-builder.LICENSE",
    "bootstrap-icons/LICENSE": "bootstrap-icons.LICENSE",
    "sortablejs/Sortable.min.js": "sortable.min.js",
    "sortablejs/LICENSE": "sortable.LICENSE",
    "dompurify/dist/purify.min.js": "purify.min.js",
    "dompurify/LICENSE": "dompurify.LICENSE",
};
for (const [source, destination] of Object.entries(files)) {
    await copyFile(
        new URL(`node_modules/${source}`, root),
        new URL(destination, assets),
    );
}
await cp(
    new URL("node_modules/bootstrap-icons/font/", root),
    new URL("icons/", assets),
    { recursive: true },
);
await build({
    entryPoints: [fileURLToPath(new URL('tools/photo-cropper-entry.mjs', root))],
    outfile: fileURLToPath(new URL('photo-cropper.js', assets)),
    bundle: true, minify: true, format: 'iife', platform: 'browser', target: 'es2022', legalComments: 'inline',
});
await copyFile(new URL('node_modules/cropperjs/LICENSE', root), new URL('photo-cropper.LICENSE', assets));

const bundled = await build({
    entryPoints: [
        fileURLToPath(new URL("tools/campus-editor-entry.mjs", root)),
    ],
    outfile: fileURLToPath(new URL("tiptap.js", assets)),
    bundle: true,
    minify: true,
    format: "iife",
    platform: "browser",
    target: "es2022",
    legalComments: "external",
    metafile: true,
});
// Include full notices for every package that contributes code to the browser bundle.
const packages = new Map();
for (const input of Object.keys(bundled.metafile.inputs)) {
    if (!input.includes("node_modules/")) continue;
    let directory = dirname(resolve(input));
    while (directory !== dirname(directory)) {
        try {
            const metadata = JSON.parse(
                await readFile(join(directory, "package.json"), "utf8"),
            );
            packages.set(directory, metadata);
            break;
        } catch (error) {
            if (error.code !== "ENOENT") throw error;
            directory = dirname(directory);
        }
    }
}
const notices = [];
for (const [directory, metadata] of [...packages].sort((a, b) =>
    a[1].name.localeCompare(b[1].name),
)) {
    let license;
    for (const filename of [
        "LICENSE",
        "LICENSE.md",
        "LICENSE-MIT",
        "LICENSE.txt",
    ]) {
        try {
            license = await readFile(join(directory, filename), "utf8");
            break;
        } catch (error) {
            if (error.code !== "ENOENT") throw error;
        }
    }
    if (!license)
        throw new Error(`Missing license notice for ${metadata.name}.`);
    notices.push(
        `${metadata.name} ${metadata.version} (${metadata.license})\n\n${license}`,
    );
}
await writeFile(
    new URL("tiptap.LICENSES.txt", assets),
    notices.join("\n\n---\n\n"),
);
for (const obsolete of [
    "quill.js",
    "quill.core.css",
    "quill.LICENSE",
    "quill.js.LICENSE.txt",
]) {
    await rm(new URL(obsolete, assets), { force: true });
}
const soundAssets = new URL("public/assets/sounds/", root);
await mkdir(soundAssets, { recursive: true });
for (const filename of [
    "message-notification-4.wav",
    "warm-success-notification.wav",
    "bird_notification.mp3",
    "piano-notification-5b.mp3",
]) {
    await copyFile(new URL(`resources/sounds/${filename}`, root), new URL(filename, soundAssets));
}
console.log("Built self-hosted workspace assets and notification sounds.");
