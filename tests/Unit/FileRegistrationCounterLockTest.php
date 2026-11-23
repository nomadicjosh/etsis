<?php

declare(strict_types=1);

namespace Tests\Unit;

use Infrastructure\Security\FileRegistrationCounterLock;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FileRegistrationCounterLockTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/etsis-registration-lock-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
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

    public function testPublicPeerVolumeCannotCreateMoreThan256EmptyPrivateLockFiles(): void
    {
        $lock = new FileRegistrationCounterLock($this->root . '/locks');
        $calls = 0;
        for ($index = 0; $index < 512; $index++) {
            $identifier = sprintf('%02x', $index % 256) . substr(hash('sha256', (string) $index), 2);
            $lock->run($identifier, static function () use (&$calls): void {
                $calls++;
            });
        }
        self::assertSame(512, $calls);
        $paths = glob($this->root . '/locks/*');
        self::assertCount(256, $paths);
        foreach ($paths as $path) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{2}\.lock$/D', basename($path));
            self::assertSame('', file_get_contents($path));
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0700, fileperms($this->root . '/locks') & 0777);
            self::assertSame(0600, fileperms($paths[0]) & 0777);
        }
    }

    public function testExceptionReleasesLockWithoutDeletingOrReplacingItsInode(): void
    {
        $lock = new FileRegistrationCounterLock($this->root . '/locks');
        $identifier = str_repeat('a', 64);
        try {
            $lock->run($identifier, static fn () => throw new RuntimeException('Fictional counter failure.'));
            self::fail('The callback failure was swallowed.');
        } catch (RuntimeException $error) {
            self::assertSame('Fictional counter failure.', $error->getMessage());
        }
        $inode = fileinode($this->root . '/locks/aa.lock');
        $called = false;
        $lock->run($identifier, static function () use (&$called): void {
            $called = true;
        });
        self::assertTrue($called);
        self::assertSame($inode, fileinode($this->root . '/locks/aa.lock'));
    }

    public function testSameStripeFailsPromptlyWhileAnotherStripeRemainsAvailable(): void
    {
        $lock = new FileRegistrationCounterLock($this->root . '/locks');
        $lock->run(str_repeat('a', 64), function () use ($lock): void {
            $called = false;
            $lock->run(str_repeat('b', 64), static function () use (&$called): void {
                $called = true;
            });
            self::assertTrue($called);
            $start = microtime(true);
            try {
                $lock->run('aa' . str_repeat('c', 62), static fn () => self::fail('A busy stripe was admitted.'));
                self::fail('A busy stripe was admitted.');
            } catch (RuntimeException) {
                self::assertLessThan(0.5, microtime(true) - $start);
            }
        });
    }

    public function testUnsafeFilesAndDirectoryLinksAreRejectedBeforeTheCallback(): void
    {
        mkdir($this->root . '/locks', 0700);
        file_put_contents($this->root . '/untouched', 'Preserve this file.');
        symlink($this->root . '/untouched', $this->root . '/locks/aa.lock');
        mkdir($this->root . '/locks/bb.lock');
        link($this->root . '/untouched', $this->root . '/locks/cc.lock');
        symlink($this->root . '/locks', $this->root . '/linked');
        foreach ([['locks', 'a'], ['locks', 'b'], ['locks', 'c'], ['linked', 'd']] as [$directory, $prefix]) {
            try {
                new FileRegistrationCounterLock($this->root . '/' . $directory)->run(
                    str_repeat($prefix, 64),
                    static fn () => self::fail('An unsafe lock was admitted.'),
                );
                self::fail('An unsafe lock was admitted.');
            } catch (RuntimeException) {
                self::assertSame('Preserve this file.', file_get_contents($this->root . '/untouched'));
            }
        }
    }

    public function testRawPeerIdentifiersAreRejectedWithoutCreatingStorage(): void
    {
        $lock = new FileRegistrationCounterLock($this->root . '/locks');
        try {
            $lock->run('192.0.2.10', static fn () => self::fail('A raw peer was accepted.'));
            self::fail('A raw peer was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertDirectoryDoesNotExist($this->root . '/locks');
        }
    }
}
