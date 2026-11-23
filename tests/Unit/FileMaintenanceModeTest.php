<?php

declare(strict_types=1);

namespace Tests\Unit;

use DateTimeImmutable;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Domain\Shared\Service\Clock;
use Infrastructure\Operations\FileMaintenanceMode;
use PHPUnit\Framework\TestCase;

final class FileMaintenanceModeTest extends TestCase
{
    private string $root;
    private FileMaintenanceMode $mode;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/etsis-maintenance-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $clock = new class implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-11-22T15:30:00-08:00');
            }
        };
        $this->mode = new FileMaintenanceMode($this->root . '/maintenance', $clock);
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($this->root);
    }

    public function testStatusIsReadOnlyAndControlsPreserveTheStartWhileUpdatingRetry(): void
    {
        self::assertSame('up', $this->mode->status()->status);
        self::assertDirectoryDoesNotExist($this->root . '/maintenance');
        $first = $this->mode->down(120);
        self::assertSame('down', $first->status);
        self::assertSame('2026-11-22T23:30:00Z', $first->startedAt->format('Y-m-d\TH:i:s\Z'));
        self::assertSame(120, $this->mode->status()->retryAfter);
        self::assertEquals($first->startedAt, $this->mode->down(600)->startedAt);
        self::assertSame(600, $this->mode->status()->retryAfter);
        self::assertSame([], glob($this->root . '/maintenance/pending-*'));
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0700, fileperms($this->root . '/maintenance') & 0777);
            self::assertSame(0600, fileperms($this->root . '/maintenance/down.json') & 0777);
            self::assertSame(0600, fileperms($this->root . '/maintenance/control.lock') & 0777);
        }
        self::assertSame('up', $this->mode->up()->status);
        self::assertSame('up', $this->mode->up()->status);
        self::assertFileDoesNotExist($this->root . '/maintenance/down.json');
    }

    public function testInvalidRetryDoesNotCreateStorage(): void
    {
        foreach ([0, 29, 3601, PHP_INT_MAX] as $retry) {
            try {
                $this->mode->down($retry);
                self::fail('An invalid retry interval was accepted.');
            } catch (InvalidArgumentException) {
                self::assertDirectoryDoesNotExist($this->root . '/maintenance');
            }
        }
    }

    public function testMalformedBoundedStateFailsClosedAndExplicitUpCanRemoveIt(): void
    {
        $this->mode->down();
        $valid = ['retry_after' => 300, 'started_at' => '2026-11-22T23:30:00Z'];
        $invalid = [
            '', '{', 'null', '[]', str_repeat('x', 513),
            json_encode($valid + ['unexpected' => 'fictional']),
            json_encode(['retry_after' => '300', 'started_at' => $valid['started_at']]),
            json_encode(['retry_after' => 0, 'started_at' => $valid['started_at']]),
            json_encode(['retry_after' => 300, 'started_at' => "2026-11-22T23:30:00Z\0"]),
            json_encode(['retry_after' => 300, 'started_at' => '2026-02-31T23:30:00Z']),
        ];
        foreach ($invalid as $text) {
            file_put_contents($this->root . '/maintenance/down.json', $text);
            $before = hash_file('sha256', $this->root . '/maintenance/down.json');
            self::assertSame('unavailable', $this->mode->status()->status);
            self::assertTrue($this->mode->status()->paused());
            self::assertSame($before, hash_file('sha256', $this->root . '/maintenance/down.json'));
        }
        $this->mode->up();
        self::assertFalse($this->mode->status()->paused());
    }

    public function testSymlinkedMarkerIsNeverFollowedOrReplaced(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('This platform does not support symlink fixtures.');
        }
        $this->mode->down();
        unlink($this->root . '/maintenance/down.json');
        file_put_contents($this->root . '/outside', 'fictional protected content');
        symlink($this->root . '/outside', $this->root . '/maintenance/down.json');
        self::assertSame('unavailable', $this->mode->status()->status);
        foreach (['up', 'down'] as $operation) {
            try {
                $this->mode->{$operation}();
                self::fail('An unsafe marker was changed.');
            } catch (RuntimeException) {
                self::assertSame('fictional protected content', file_get_contents($this->root . '/outside'));
                self::assertTrue(is_link($this->root . '/maintenance/down.json'));
            }
        }
    }

    public function testUnsafeDirectoryAndControlFileFailClosed(): void
    {
        $this->mode->down();
        $this->mode->up();
        unlink($this->root . '/maintenance/control.lock');
        mkdir($this->root . '/maintenance/control.lock');
        self::assertTrue($this->mode->status()->paused());
        try {
            $this->mode->down();
            self::fail('A directory was accepted as a lock file.');
        } catch (RuntimeException) {
            self::assertDirectoryExists($this->root . '/maintenance/control.lock');
        }
    }

    public function testBusyControlsReturnImmediatelyWithoutChangingTheAcceptedMode(): void
    {
        $this->mode->down();
        $before = file_get_contents($this->root . '/maintenance/down.json');
        $lock = fopen($this->root . '/maintenance/control.lock', 'cb');
        flock($lock, LOCK_EX);
        try {
            $start = microtime(true);
            try {
                $this->mode->up();
                self::fail('A competing control acquired the held lock.');
            } catch (RuntimeException) {
                self::assertLessThan(1, microtime(true) - $start);
                self::assertSame($before, file_get_contents($this->root . '/maintenance/down.json'));
                self::assertTrue($this->mode->status()->paused());
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
