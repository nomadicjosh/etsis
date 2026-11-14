<?php

declare(strict_types=1);

namespace Domain\Reporting\Service;

use Domain\Reporting\Repository\ReportLifecycleRepository;
use Domain\Reporting\Repository\ReportRepository;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

/** Deployment housekeeping; expiry gates private reads even before this bounded removal runs. */
final readonly class ReportRetention
{
    public function __construct(
        private ReportLifecycleRepository $storage,
        private ReportRepository $reports,
        private UnitOfWork $transactions,
        private Clock $clock,
    ) {
    }
    /** @return array{examined:int,removed:int,apply:bool} */
    public function run(bool $apply = false, int $limit = 50): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Choose a cleanup limit of 1–100 reports.');
        }
        $cutoff = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
        $ids = $this->storage->expired($cutoff, $limit);
        $removed = 0;
        if ($apply) {
            foreach ($ids as $value) {
                $removed += $this->transactions->run(function () use ($value, $cutoff): int {
                    $id = new EntityId($value);
                    $this->reports->lock($id);
                    $state = $this->storage->get($id);
                    if (
                        $state === null || $state['removed_at'] !== null || $state['expires_at'] === null ||
                        $state['expires_at'] > $cutoff
                    ) {
                        return 0;
                    }
                    $this->storage->remove($id, $cutoff);
                    return 1;
                });
            }
        }
        return ['examined' => count($ids), 'removed' => $removed, 'apply' => $apply];
    }
}
