<?php

declare(strict_types=1);

namespace Domain\Transfer\Repository;

use Domain\Catalog\ValueObject\PublishedCourseDefinition;
use Domain\Shared\ValueObject\EntityId;

interface TransferCreditRepository
{
    /** @return array<string,mixed>|null */
    public function existing(
        EntityId $student,
        EntityId $equivalency,
        string $completed,
    ): ?array;
    /** @return array<string,mixed> */
    public function award(EntityId $award): array;
    /** @return array<string,mixed>|null */
    public function mapping(EntityId $award): ?array;
    /** @return array<string,mixed> */
    public function credit(EntityId $credit): array;
    public function courseVersion(EntityId $credit): ?EntityId;
    /** @return array<string,mixed> */
    public function term(EntityId $term): array;
    /** @param array<string,mixed> $source
     * @param array<string,mixed> $credit Unknown definitions are reserved for historical projection reconstruction.
     */
    public function create(
        array $source,
        array $credit,
        ?PublishedCourseDefinition $definition = null,
    ): void;
    /** @param array<string,mixed> $credit */
    public function project(
        array $credit,
        int $expectedVersion,
    ): void;
    /** @param array<string,mixed> $source
     * @param array<string,mixed> $credit */
    public function track(array $source, array $credit): void;
}
