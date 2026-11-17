<?php

declare(strict_types=1);

namespace Domain\Admissions\Repository;

use Domain\Admissions\AdmissionCasework;
use Domain\Admissions\AdmissionChecklistPreset;
use Domain\Shared\ValueObject\EntityId;

interface AdmissionCaseworkRepository
{
    /** @return array{case:AdmissionCasework,request_hash:string}|null */
    public function find(EntityId $application): ?array;
    public function save(
        AdmissionCasework $case,
        int $expected,
        EntityId $actor,
        string $at,
        string $requestHash
    ): void;
    /** @return array{preset:AdmissionChecklistPreset,request_hash:string}|null */
    public function preset(EntityId $id): ?array;
    public function savePreset(
        AdmissionChecklistPreset $preset,
        int $expected,
        EntityId $actor,
        string $at,
        string $hash
    ): void;
    public function lockPreset(EntityId $id): void;
    public function lockActor(EntityId $actor): void;
    public function requireProgram(EntityId $program): void;
    /** @return list<array<string,mixed>> */
    public function presets(EntityId $program, string $search, ?EntityId $after, bool $includeDisabled): array;
    /** @return list<array<string,mixed>> */
    public function queue(
        ?EntityId $assignedTo,
        bool $unassigned,
        bool $dueOnly,
        string $today,
        ?EntityId $after
    ): array;
}
