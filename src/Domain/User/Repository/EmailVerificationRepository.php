<?php

declare(strict_types=1);

namespace Domain\User\Repository;

use Domain\Shared\ValueObject\EntityId;

interface EmailVerificationRepository
{
    /** Current login routing only; never credentials or an authoritative historical replacement. */
    public function account(EntityId $user): ?array;
    public function get(EntityId $user): ?array;
    public function lock(EntityId $user): void;
    public function request(EntityId $user, int $version, array $row): void;
    public function confirm(EntityId $user, int $version, string $proof): void;
    public function pending(string $now, int $limit): array;
    public function claim(EntityId $user, int $version, string $hash): bool;
    public function delivered(EntityId $user, int $version, string $hash, string $state): void;
    public function expired(string $now, int $limit): array;
    public function redact(EntityId $user, int $version): void;
}
