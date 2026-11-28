<?php

declare(strict_types=1);

namespace Domain\Payments\Service;

use Domain\Payments\Repository\GatewayRepository;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;

/** Dispose only expired, unaccepted proposals. Accepted receipts and financial checkout bindings remain. */
final readonly class GatewayReviewRetention
{
    public function __construct(
        private GatewayRepository $records,
        private UnitOfWork $transactions,
        private Clock $clock,
    ) {
    }

    public function run(int $limit = 50): int
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Invalid payment review cleanup bound.');
        }
        return $this->transactions->run(fn(): int =>
            $this->records->clearExpired($this->clock->now()->getTimestamp(), $limit));
    }
}
