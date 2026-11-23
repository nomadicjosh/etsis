<?php

declare(strict_types=1);

namespace Infrastructure\Operations;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Shared\Operations\MaintenanceMode;
use Domain\Shared\Operations\MaintenanceState;
use Domain\Shared\Service\Clock;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/** One private, shared deployment directory; reads neither create files nor contact the database. */
final readonly class FileMaintenanceMode implements MaintenanceMode
{
    public function __construct(private string $directory, private Clock $clock)
    {
        if ($directory === '' || str_contains($directory, "\0") || str_contains($directory, '://')) {
            throw new InvalidArgumentException('Maintenance requires a local deployment directory.');
        }
    }

    public function status(): MaintenanceState
    {
        clearstatcache();
        if (is_link($this->directory)) {
            return MaintenanceState::unavailable();
        }
        if (!file_exists($this->directory)) {
            $parent = dirname($this->directory);
            for ($depth = 0; $depth < 32; $depth++) {
                if (file_exists($parent)) {
                    return is_dir($parent) && is_readable($parent) && is_executable($parent)
                    ? MaintenanceState::up() : MaintenanceState::unavailable();
                }
                $next = dirname($parent);
                if ($next === $parent) {
                    break;
                }
                $parent = $next;
            }
            return MaintenanceState::unavailable();
        }
        if (!is_dir($this->directory) || !is_readable($this->directory) || !is_executable($this->directory)) {
            return MaintenanceState::unavailable();
        }
        $path = $this->directory . '/down.json';
        $control = $this->directory . '/control.lock';
        if (is_link($path) || is_link($control) || (file_exists($control) && !is_file($control))) {
            return MaintenanceState::unavailable();
        }
        $stat = @lstat($path);
        if ($stat === false) {
            return MaintenanceState::up();
        }
        if (($stat['mode'] & 0170000) !== 0100000 || $stat['size'] < 1 || $stat['size'] > 512) {
            return MaintenanceState::unavailable();
        }
        $file = @fopen($path, 'rb');
        if ($file === false) {
            return MaintenanceState::unavailable();
        }
        try {
            $text = fread($file, 513);
        } finally {
            fclose($file);
        }
        if ($text === false || strlen($text) > 512) {
            return MaintenanceState::unavailable();
        }
        try {
            $data = json_decode($text, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return MaintenanceState::unavailable();
        }
        if (
            !is_array($data) || count($data) !== 2 || !is_int($data['retry_after'] ?? null)
            || $data['retry_after'] < 30 || $data['retry_after'] > 3600 || !is_string($data['started_at'] ?? null)
            || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $data['started_at']) !== 1
        ) {
            return MaintenanceState::unavailable();
        }
        $started = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $data['started_at'], new DateTimeZone('UTC'));
        if ($started === false || $started->format('Y-m-d\TH:i:s\Z') !== $data['started_at']) {
            return MaintenanceState::unavailable();
        }
        return MaintenanceState::down($data['retry_after'], $started);
    }

    public function down(int $retryAfter = 300): MaintenanceState
    {
        // Validate before creating or locking any operational file.
        MaintenanceState::down($retryAfter, $this->clock->now());
        return $this->change(function () use ($retryAfter): MaintenanceState {
            $this->safeMarker();
            $current = $this->status();
            $state = MaintenanceState::down(
                $retryAfter,
                $current->startedAt ?? $this->clock->now()->setTimezone(new DateTimeZone('UTC')),
            );
            $text = json_encode([
                'retry_after' => $state->retryAfter,
                'started_at' => $state->startedAt->format('Y-m-d\TH:i:s\Z'),
            ], JSON_THROW_ON_ERROR);
            $temporary = $this->directory . '/pending-' . bin2hex(random_bytes(12));
            $file = @fopen($temporary, 'xb');
            if ($file === false) {
                throw new RuntimeException('Maintenance state could not be saved.');
            }
            try {
                if (!@chmod($temporary, 0600) || fwrite($file, $text) !== strlen($text) || !fflush($file)) {
                    throw new RuntimeException('Maintenance state could not be saved.');
                }
                if (function_exists('fsync') && !fsync($file)) {
                    throw new RuntimeException('Maintenance state could not be saved.');
                }
                fclose($file);
                $file = null;
                $this->safeMarker();
                if (!@rename($temporary, $this->directory . '/down.json')) {
                    throw new RuntimeException('Maintenance state could not be saved.');
                }
            } finally {
                if (is_resource($file)) {
                    fclose($file);
                }
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
            return $state;
        });
    }

    public function up(): MaintenanceState
    {
        return $this->change(function (): MaintenanceState {
            $this->safeMarker();
            $path = $this->directory . '/down.json';
            if (file_exists($path) && !@unlink($path)) {
                throw new RuntimeException('Maintenance state could not be removed.');
            }
            return MaintenanceState::up();
        });
    }

    private function safeMarker(): void
    {
        $path = $this->directory . '/down.json';
        clearstatcache(true, $path);
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('Maintenance storage needs operator review.');
        }
    }

    private function change(callable $change): MaintenanceState
    {
        clearstatcache();
        if (is_link($this->directory)) {
            throw new RuntimeException('Maintenance storage needs operator review.');
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Maintenance storage is unavailable.');
        }
        $path = $this->directory . '/control.lock';
        if (is_link($path) || (file_exists($path) && !is_file($path)) || !@chmod($this->directory, 0700)) {
            throw new RuntimeException('Maintenance storage needs operator review.');
        }
        $lock = @fopen($path, 'cb');
        if ($lock === false) {
            throw new RuntimeException('Maintenance controls are unavailable.');
        }
        try {
            if (!@chmod($path, 0600) || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another maintenance control may be running. Retry shortly.');
            }
            try {
                return $change();
            } finally {
                flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }
}
