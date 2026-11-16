<?php

declare(strict_types=1);

namespace Domain\Finance\Service;

use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

/** Shared command checks for account, billing-rule and journal decisions. */
final readonly class FinancialCommandPolicy
{
    public function __construct(private StudentAccess $access, private Clock $clock)
    {
    }

    public function assertActor(EntityId $actor, string $permission): void
    {
        if ($this->access->requirePermission($permission)->value !== $actor->value) {
            throw new AccessDeniedException('The financial actor changed.');
        }
    }

    public function assertPosition(int $current, int $expected): void
    {
        if ($expected < 1 || $current !== $expected) {
            throw new ConcurrentModificationException('This financial record changed. Reload it before confirming.');
        }
    }

    public function reason(#[\SensitiveParameter] string $reason): string
    {
        $reason = trim($reason);
        if (
            $reason === '' || !mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason) > 2000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
        ) {
            throw new ValidationException([
                ['field' => 'reason', 'message' => 'Enter a reason of up to 2,000 characters.'],
            ]);
        }
        return $reason;
    }

    public function context(
        EntityId $actor,
        EntityId $operation,
        #[\SensitiveParameter] string $reason,
        ?EntityId $cause = null,
    ): EventContext {
        return new EventContext(
            $actor,
            $this->clock->now(),
            $operation,
            $cause ?? $operation,
            reason: $this->reason($reason),
        );
    }
}
