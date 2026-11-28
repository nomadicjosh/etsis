<?php

declare(strict_types=1);

namespace Domain\Shared\Backup;

use Domain\Shared\EventSourcing\HistoryAnchor;

interface BackupHistory
{
    public function capture(string $scope, int $rows): HistoryAnchor;

    public function retain(HistoryAnchor $anchor, BackupPlan $plan): void;

    public function compare(
        HistoryAnchor $anchor,
        string $scope,
        int $rows,
    ): bool;
}
