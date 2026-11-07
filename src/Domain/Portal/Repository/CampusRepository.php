<?php

declare(strict_types=1);

namespace Domain\Portal\Repository;

use Domain\Shared\ValueObject\EntityId;

interface CampusRepository
{
    /** @return list<array<string, mixed>> */
    public function terms(): array;
    /** @return list<array<string, mixed>> */
    public function programs(string $search = '', ?EntityId $after = null): array;
    /** @return array{items: list<array<string, mixed>>, next_after: ?string} */
    public function sections(?EntityId $term, string $search, ?EntityId $after): array;
    /** @return list<array<string, mixed>> */
    public function news(bool $campus, ?EntityId $after = null): array;
    /** @return array<string, mixed> */
    public function article(string $code, bool $campus): array;
    public function audience(EntityId $post): string;
    public function setAudience(EntityId $post, string $audience): void;
    public function personForUser(EntityId $user): EntityId;
    public function lockPerson(EntityId $person): void;
    public function requireOwnedAccount(EntityId $user, EntityId $account): void;
    /** @return array<string, mixed> */
    public function overview(EntityId $user): array;
    /** @return array<string, mixed> */
    public function ownedStudent(EntityId $user, EntityId $student): array;
    /** @return list<array<string, mixed>> */
    public function enrollments(EntityId $student): array;
    /** @return array<string, mixed>|null */
    public function contact(EntityId $person): ?array;
    public function saveContact(EntityId $person, string $encrypted, int $version): void;
    /** @param array<string, scalar> $data */
    public function addTranscriptRequest(EntityId $id, array $data): void;
    /** @return list<array<string, mixed>> */
    public function transcriptRequests(?EntityId $owner, ?EntityId $after = null): array;
    /** @return array<string, mixed> */
    public function transcriptRequest(EntityId $id): array;
    public function changeTranscriptStatus(EntityId $id, string $status, int $version): void;
}
