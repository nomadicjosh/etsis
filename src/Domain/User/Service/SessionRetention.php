<?php

declare(strict_types=1);

namespace Domain\User\Service;

use DateInterval;
use DateInvalidOperationException;
use DateMalformedIntervalStringException;
use DateTimeZone;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;
use Domain\User\Repository\SessionRetentionRepository;
use Domain\User\ValueObject\SessionRetentionSummary;
use InvalidArgumentException;

/** Explicit operator housekeeping. Normal sign-in and launch requests never invoke cleanup. */
final readonly class SessionRetention
{
    public function __construct(
        private SessionRetentionRepository $repository,
        private UnitOfWork $transactions,
        private Clock $clock,
    ) {
    }

    /**
     * @throws DateInvalidOperationException
     * @throws DateMalformedIntervalStringException
     */
    public function run(bool $apply = false, int $limit = 100, int $graceHours = 24): SessionRetentionSummary
    {
        if ($limit < 1 || $limit > 500 || $graceHours < 1 || $graceHours > 8760) {
            throw new InvalidArgumentException("Use a limit of 1–500 and a grace period of 1–8760 hours.");
        }
        // Freeze a UTC cutoff for the entire batch, including across daylight-saving transitions.
        $cutoff = $this->clock
            ->now()
            ->setTimezone(new DateTimeZone("UTC"))
            ->sub(new DateInterval("PT" . $graceHours . "H"));
        return $apply
        ? $this->transactions->run(fn() => $this->repository->prune($cutoff, $limit))
        : $this->repository->preview($cutoff, $limit);
    }
}
