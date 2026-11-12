<?php

declare(strict_types=1);

namespace Domain\Lms\ValueObject;

use Domain\Shared\ValueObject\EntityId;

/** Approval metadata. Profile attributes belong only in encrypted approval storage. */
final readonly class MoodleAccountRequest
{
    public function __construct(
        public EntityId $id,
        public EntityId $binding,
        public EntityId $person,
        public EntityId $requestedBy,
        public string $requestedAt,
        public string $reviewHash,
    ) {
    }

    /** @return array<string,string> */
    public function describe(): array
    {
        return [
            "id" => $this->id->value,
            "binding_id" => $this->binding->value,
            "requested_at" => $this->requestedAt,
        ];
    }
}
