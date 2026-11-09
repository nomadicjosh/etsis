<?php

declare(strict_types=1);

namespace Domain\Transcript\Repository;

use Domain\Shared\ValueObject\EntityId;

interface TranscriptRepository
{
    public function lockStudent(EntityId $id): void;
    public function lockPerson(EntityId $id): void;
    public function lockRequest(EntityId $id): void;
    /** @return array<string, mixed> */
    public function request(EntityId $id): array;
    /** @return list<array<string, mixed>> */
    public function credits(EntityId $student): array;
    /** @return array<string, mixed>|null */
    public function document(EntityId $id): ?array;
    /** @return array<string, mixed>|null */
    public function latest(EntityId $request): ?array;
    /** @param array<string, scalar> $data */
    public function addDocument(EntityId $id, #[\SensitiveParameter] array $data): void;
    public function status(EntityId $request, string $status, int $version): void;
    /** @return array<string, mixed>|null */
    public function delivery(EntityId $operation): ?array;
    /** @return array<string, mixed>|null */
    public function documentDelivery(EntityId $document): ?array;
    /** @param array<string, scalar> $data */
    public function addDelivery(EntityId $operation, #[\SensitiveParameter] array $data): void;
}
