<?php

declare(strict_types=1);

namespace Domain\Lms\ValueObject;

use Domain\Shared\ValueObject\EntityId;

/** An explicit reference, not authentication authority or an enrollment decision. */
final readonly class MoodlePersonLink
{
    public function __construct(
        public EntityId $id,
        public EntityId $binding,
        public EntityId $person,
        public MoodleExternalId $external,
        public EntityId $approvedBy,
        public string $approvedAt,
    ) {
    }

    /** @return array<string,string> */
    public function describe(): array
    {
        return [
            "id" => $this->id->value,
            "binding_id" => $this->binding->value,
            "moodle_user_id" => $this->external->value,
            "approved_at" => $this->approvedAt,
        ];
    }
}
