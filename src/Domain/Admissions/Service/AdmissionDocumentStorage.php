<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use Domain\Shared\ValueObject\EntityId;

interface AdmissionDocumentStorage
{
    public function put(EntityId $application, EntityId $id, #[\SensitiveParameter] string $bytes): void;
    public function read(EntityId $application, EntityId $id): string;
    public function remove(EntityId $application, EntityId $id): void;
}
