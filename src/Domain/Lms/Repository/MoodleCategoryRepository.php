<?php

declare(strict_types=1);

namespace Domain\Lms\Repository;

use Domain\Shared\ValueObject\EntityId;

interface MoodleCategoryRepository
{
    /** @return array<string,mixed>|null */
    public function settings(EntityId $binding): ?array;
    public function configure(
        EntityId $binding,
        string $source,
        int $expected,
        EntityId $actor,
        string $at,
    ): void;
    /** @return array{id:string,code:string,title:string,active:bool} */
    public function reference(string $source, EntityId $id): array;
    /** @return list<array<string,mixed>> */
    public function page(EntityId $binding, string $source, string $search): array;
    /** @return array{id:string,code:string,title:string,active:bool}|null */
    public function candidate(EntityId $binding, string $source, int $now): ?array;
    /** @return array<string,mixed>|null */
    public function mapping(EntityId $binding, string $source, EntityId $reference): ?array;
    /** @return array<string,mixed> */
    public function claim(EntityId $binding, string $source, EntityId $reference, int $now): array;
    /** @param array{id:string,code:string,title:string,active:bool} $reference */
    public function finish(
        EntityId $id,
        int $version,
        ?string $external,
        bool $owned,
        array $reference,
        string $issue,
        int $lease,
        int $checkedAt,
        ?string $decision = null,
    ): void;
}
