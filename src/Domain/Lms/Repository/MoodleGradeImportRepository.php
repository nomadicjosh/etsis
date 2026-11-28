<?php

declare(strict_types=1);

namespace Domain\Lms\Repository;

use Domain\Shared\ValueObject\EntityId;

interface MoodleGradeImportRepository
{
    public function job(EntityId $section): ?array;
    public function saveJob(
        EntityId $section,
        int $revision,
        array $values,
    ): void;
    public function pending(int $now): ?array;
    public function boundary(EntityId $section): ?string;
    public function nextEnrollment(
        EntityId $section,
        ?string $after,
        string $through,
    ): ?array;
    public function candidate(EntityId $id): ?array;
    public function source(string $key): ?array;
    public function add(array $values): void;
    public function change(EntityId $id, int $revision, array $values): void;
    public function supersede(EntityId $enrollment, string $except): void;
    public function page(EntityId $section, ?EntityId $after = null): array;
    public function notify(array $notice): void;
    public function notices(
        EntityId $owner,
        bool $unread,
        ?EntityId $after = null,
    ): array;
    public function notice(EntityId $owner, EntityId $notice): ?array;
    public function clearNotice(
        EntityId $owner,
        EntityId $notice,
        int $revision,
        ?string $readAt,
    ): void;
}
