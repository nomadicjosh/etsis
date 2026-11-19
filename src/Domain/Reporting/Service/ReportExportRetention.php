<?php

declare(strict_types=1);

namespace Domain\Reporting\Service;

use Domain\Reporting\Repository\ReportExportRepository;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

/** Export files expire independently; a report's current authority still gates every download. */
final readonly class ReportExportRetention
{
    public function __construct(
        private ReportExportRepository $jobs,
        private UnitOfWork $transactions,
        private Clock $clock,
    ) {
    }

    /** @return array{examined:int,removed:int,apply:bool} */
    public function run(bool $apply = false, int $limit = 50): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Use a report export cleanup limit of 1–100.');
        }
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
        $ids = $this->jobs->expired($now, $limit);
        $removed = 0;
        if ($apply) {
            foreach ($ids as $value) {
                try {
                    $removed += $this->transactions->run(function () use ($value, $now): int {
                        $id = new EntityId($value);
                        $this->jobs->lock($id);
                        $job = $this->jobs->get($id);
                        if ($job === null || $job['expires_at'] > $now) {
                            return 0;
                        }
                        $this->jobs->remove($id);
                        return 1;
                    });
                } catch (RecordNotFoundException) {
                    // Another bounded cleanup already removed this job.
                }
            }
        }
        return ['examined' => count($ids), 'removed' => $removed, 'apply' => $apply];
    }
}
