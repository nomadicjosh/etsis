<?php

declare(strict_types=1);

namespace Domain\Lms\Repository;

use Domain\Shared\ValueObject\EntityId;

interface MoodleSectionRepository
{
    /** Verified published context; caller authorizes.
     * @return array{code:string,title:string,hash:string} */
    public function section(EntityId $section): array;
    /** @return list<array<string,mixed>> */
    public function directory(string $search): array;
    /** @return array<string,mixed>|null */
    public function link(EntityId $binding, EntityId $section): ?array;
    public function add(
        EntityId $id,
        EntityId $binding,
        EntityId $section,
        string $course,
        EntityId $actor,
        string $at,
        #[\SensitiveParameter] string $review,
    ): void;
    /** @return array<string,mixed>|null */
    public function claim(EntityId $binding, int $now): ?array;
    public function candidate(EntityId $section, EntityId $link, ?EntityId $after): ?EntityId;
    /** Reverify academic facts under local locks; never writes academic projections.
     * @return array{account_eligible:bool,student:bool,instructor:bool,enrollment_id:?string,hash:string,issue:string} */
    public function desired(EntityId $section, EntityId $link, EntityId $person): array;
    /** Retain a person/enrollment reference before any external effect, including an uncertain one. */
    public function remember(EntityId $link, EntityId $person, ?string $enrollment): void;
    /** Number of retained members still requiring setup; no personal details. */
    public function issues(EntityId $link): int;
    public function memberResult(EntityId $link, EntityId $person, string $hash, string $issue): void;
    public function finish(
        EntityId $link,
        int $version,
        ?EntityId $after,
        int $cycleAt,
        int $leaseUntil,
        string $issue,
    ): void;
}
