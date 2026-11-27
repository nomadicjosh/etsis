<?php

declare(strict_types=1);

namespace Domain\User\Service;

use DateTimeZone;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;
use Domain\User\Repository\EmailVerificationRepository;

/** Expired challenge material is disposable; accepted current email-control proof remains protected. */
final readonly class EmailVerificationRetention
{
    public function __construct(
        private EmailVerificationRepository $records,
        private UnitOfWork $transactions,
        private Clock $clock,
    ) {
    }

    public function run(int $limit = 50): int
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException();
        }
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $removed = 0;
        foreach ($this->records->expired($now, $limit) as $id) {
            $this->transactions->run(function () use ($id, $now, &$removed): void {
                $this->records->lock($id);
                $row = $this->records->get($id);
                if (
                    $row !== null && $row['expires_at'] <= $now
                    && ($row['token_hash'] !== null || $row['sealed_challenge'] !== null
                        || $row['sealed_notice'] !== null)
                ) {
                    $this->records->redact($id, $row['version']);
                    $removed++;
                }
            });
        }
        return $removed;
    }
}
