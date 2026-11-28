<?php

declare(strict_types=1);

namespace Infrastructure\Backup;

use Closure;
use Domain\Shared\Backup\BackupHistory;
use Domain\Shared\Backup\BackupPlan;
use Domain\Shared\EventSourcing\HistoryAnchor;
use Domain\Shared\EventSourcing\HistoryAnchorService;
use Domain\Shared\EventSourcing\HistoryAnchorStorage;

/** Restore/list/init never resolve a live application database or require its original keys. */
final readonly class LazyBackupHistory implements BackupHistory
{
    /** @param Closure():HistoryAnchorService $service */
    public function __construct(
        private Closure $service,
        private HistoryAnchorStorage $storage,
    ) {
    }

    public function capture(string $scope, int $rows): HistoryAnchor
    {
        return ($this->service)()->capture($scope, $rows);
    }

    public function retain(HistoryAnchor $anchor, BackupPlan $plan): void
    {
        $this->storage->write(
            $plan->checkpointDirectory . "/" . $anchor->data["id"] . ".anchor",
            $plan->checkpointKey,
            $anchor,
        );
    }

    public function compare(
        HistoryAnchor $anchor,
        string $scope,
        int $rows,
    ): bool {
        return ($this->service)()->compare($anchor, $scope, $rows);
    }
}
