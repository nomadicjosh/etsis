<?php

declare(strict_types=1);

namespace Domain\Rules\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Immutable authoring revisions; authoritative academic use must retain the definition in its publication. */
interface CourseRuleRepository
{
    /** @param array<string,mixed> $record */
    public function append(array $record): void;
    /** @return array<string,mixed> */
    public function revision(EntityId $version): array;
    /** @return array<string,mixed> */
    public function latest(EntityId $rule): array;
    /** @return list<array<string,mixed>> Root metadata only; 21 rows for a 20-item page. */
    public function roots(string $search, ?EntityId $after = null): array;
    /** @return list<array<string,mixed>> */
    public function versions(EntityId $rule, ?int $before = null): array;
    /** Server-resolved display labels; callers authorize catalog scope first.
     * @return array{id:string,code:string,title:string}
     */
    public function reference(string $field, EntityId $id): array;
}
