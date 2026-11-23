<?php

declare(strict_types=1);

namespace Tests\Integration;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;

final class RegistrationCounterConcurrencyTest extends TestCase
{
    public function testPublicRegistrationGuardUsesProvidersCsrfPrivateResponsesAndBusyRecovery(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-registration-throttle']);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testSeparateProcessesShareTheAllowanceWithoutLostCounterUpdates(): void
    {
        $root = sys_get_temp_dir() . '/etsis-registration-workers-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        // Translation helpers bootstrap only this empty fictional application when a request is blocked.
        mkdir($root . '/bootstrap', 0700);
        mkdir($root . '/config', 0700);
        file_put_contents(
            $root . '/bootstrap/app.php',
            '<?php return new \\Codefy\\Framework\\Application(["basePath" => dirname(__DIR__)]);',
        );
        file_put_contents($root . '/config/app.php', '<?php return ["locale_domain" => "etsis"];');
        $workers = [];
        try {
            foreach (['one', 'two'] as $worker) {
                $process = new Process([
                    PHP_BINARY, '-d', 'memory_limit=64M',
                    __DIR__ . '/../Support/RegistrationCounterWorker.php', $root, $worker,
                ], $root, ['APP_BASE_PATH' => $root, 'XDEBUG_MODE' => 'off']);
                $process->setTimeout(6);
                $process->start();
                $workers[] = $process;
            }
            $deadline = microtime(true) + 4;
            while (!is_file($root . '/ready-one') || !is_file($root . '/ready-two')) {
                if (microtime(true) >= $deadline) {
                    self::fail('The bounded registration workers did not become ready.');
                }
                usleep(10000);
            }
            file_put_contents($root . '/start', 'start');
            $statuses = [];
            foreach ($workers as $process) {
                $process->wait();
                self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $statuses = array_merge($statuses, $results);
            }
            self::assertCount(40, $statuses);
            self::assertSame(5, count(array_filter($statuses, static fn (int $status): bool => $status === 200)));
            self::assertContains(429, $statuses);
            self::assertSame([], array_diff($statuses, [200, 429, 503]));
            self::assertCount(1, glob($root . '/locks/*'));
            self::assertCount(1, glob($root . '/cache/*'));
            foreach (glob($root . '/cache/*') as $path) {
                $content = file_get_contents($path);
                self::assertStringNotContainsString('192.0.2.10', $content);
                self::assertStringNotContainsString('fictional-key', $content);
                $entry = unserialize($content, [
                    'allowed_classes' => [\Codefy\Framework\Http\Throttle\Interval::class],
                ]);
                $counted = count(array_filter($statuses, static fn (int $status): bool => $status !== 503));
                self::assertSame($counted, $entry['value']->count);
            }
        } finally {
            foreach ($workers as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($root);
        }
    }
}
