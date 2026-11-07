const { defineConfig } = require('@playwright/test');

const interpreter = process.env.ETSIS_TEST_PHP;
if (!interpreter) throw new Error('Set ETSIS_TEST_PHP to your configured PHP 8.4+ interpreter.');
const quotedInterpreter = `'${interpreter.replaceAll("'", "'\\''")}'`;

module.exports = defineConfig({
    testDir: './tests/Browser',
    workers: 1,
    fullyParallel: false,
    retries: 0,
    timeout: 60000,
    reporter: 'line',
    use: {
        baseURL: 'http://127.0.0.1:8127',
        headless: true,
        actionTimeout: 10000,
        viewport: { width: 1440, height: 1000 },
        reducedMotion: 'reduce',
        trace: 'off',
        video: 'off',
        launchOptions: {
            args: ['--disable-dev-shm-usage', '--renderer-process-limit=1', '--js-flags=--max-old-space-size=96'],
        },
    },
    webServer: {
        command: `${quotedInterpreter} -d memory_limit=128M -S 127.0.0.1:8127 -t public tests/Browser/router.php`,
        url: 'http://127.0.0.1:8127',
        reuseExistingServer: false,
        timeout: 15000,
    },
});
