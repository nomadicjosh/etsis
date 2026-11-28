<?php

declare(strict_types=1);

namespace Domain\Shared\Backup;

use RuntimeException;

final readonly class BackupBudget
{
    private int $deadline;

    public function __construct(
        public int $seconds,
        public int $bytes,
        public int $files,
    ) {
        if (
            $seconds < 1 ||
            $seconds > 1800 ||
            $bytes < 1 ||
            $bytes > 10737418240 ||
            $files < 1 ||
            $files > 10000
        ) {
            throw new \InvalidArgumentException("Invalid backup limits.");
        }
        $this->deadline = hrtime(true) + $seconds * 1_000_000_000;
    }

    public function remaining(): float
    {
        $remaining = ($this->deadline - hrtime(true)) / 1_000_000_000;
        if ($remaining <= 0) {
            throw new RuntimeException(
                "The backup operation exceeded its allowance.",
            );
        }
        return $remaining;
    }
}
