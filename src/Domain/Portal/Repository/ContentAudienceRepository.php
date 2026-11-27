<?php

declare(strict_types=1);

namespace Domain\Portal\Repository;

use Domain\Shared\ValueObject\EntityId;

interface ContentAudienceRepository
{
    /** @return list<array{id:string,name:string}> Twenty-one role labels, without members or grants. */
    public function roles(string $search, ?EntityId $after = null): array;

    /** @param list<string> $ids
     * @return list<array{id:string,name:string}>
     */
    public function definitions(array $ids): array;

    /** @return list<array{id:string,name:string}> */
    public function selected(EntityId $post): array;

    /** @param list<array{id:string,name:string}> $roles */
    public function replace(EntityId $post, array $roles): void;
}
