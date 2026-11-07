import { mkdir, copyFile, cp } from 'node:fs/promises';
const root = new URL('../', import.meta.url);
const assets = new URL('public/assets/vendor/', root);
await mkdir(assets, { recursive: true });
const files = {
    'bootstrap/dist/css/bootstrap.min.css': 'bootstrap.min.css',
    'bootstrap/dist/js/bootstrap.bundle.min.js': 'bootstrap.bundle.min.js',
    'bootstrap/LICENSE': 'bootstrap.LICENSE',
    'jquery/dist/jquery.min.js': 'jquery.min.js',
    'jquery/LICENSE.txt': 'jquery.LICENSE',
    'chart.js/dist/chart.umd.js': 'chart.umd.js',
    'chart.js/LICENSE.md': 'chart.LICENSE',
    'jQuery-QueryBuilder/dist/js/query-builder.standalone.js': 'query-builder.min.js',
    'jQuery-QueryBuilder/dist/css/query-builder.default.css': 'query-builder.min.css',
    'jQuery-QueryBuilder/LICENSE': 'query-builder.LICENSE',
    'bootstrap-icons/LICENSE': 'bootstrap-icons.LICENSE',
};
for (const [source, destination] of Object.entries(files)) {
    await copyFile(new URL(`node_modules/${source}`, root), new URL(destination, assets));
}
await cp(new URL('node_modules/bootstrap-icons/font/', root), new URL('icons/', assets), { recursive: true });
console.log('Built self-hosted workspace assets.');
