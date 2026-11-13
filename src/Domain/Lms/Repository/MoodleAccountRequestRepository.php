<?php

declare(strict_types=1);

namespace Domain\Lms\Repository;

use Domain\Lms\ValueObject\MoodleAccountRequest;
use Domain\Shared\ValueObject\EntityId;

interface MoodleAccountRequestRepository
{
    /** @phpstan-impure */
    public function forPerson(EntityId $binding, EntityId $person): ?MoodleAccountRequest;
    public function add(
        MoodleAccountRequest $request,
        #[\SensitiveParameter] string $encryptedApproval,
    ): void;
    /** Atomically add a reference-only outbox item in the caller's transaction. */
    public function enqueue(EntityId $request, string $createdAt): void;
    /** @return array{version:int,state:string,issue_code:string,issue:string,resolution_hash:?string} */
    public function processing(EntityId $request): array;
    /** Caller owns the transaction; retain the original approval and operation reference. */
    public function resolveEmail(
        EntityId $request,
        int $expectedVersion,
        string $reviewHash,
        #[\SensitiveParameter] string $encryptedResolution,
    ): void;
    /** @return array{link_id:string,review_hash:string}|null */
    public function recovery(EntityId $request): ?array;
    /** Atomically finish stopped work and retain immutable recovery evidence in the caller's transaction. */
    public function recover(
        EntityId $request,
        int $expectedVersion,
        string $expectedIssue,
        EntityId $link,
        string $reviewHash,
        bool $needsEmailReview,
        #[\SensitiveParameter] string $encryptedReview,
    ): void;
}
