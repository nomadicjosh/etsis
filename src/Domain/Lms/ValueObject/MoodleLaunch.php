<?php

declare(strict_types=1);

namespace Domain\Lms\ValueObject;

use Domain\Shared\ValueObject\EntityId;

/** Private integration reference; never an academic event or reporting source. */
final readonly class MoodleLaunch implements \JsonSerializable
{
    public function __construct(
        public EntityId $id,
        public EntityId $session,
        public EntityId $user,
        public EntityId $person,
        public EntityId $binding,
        public EntityId $link,
        public MoodleExternalId $external,
        public int $ticketExpiresAt,
        public int $sessionExpiresAt,
        public bool $consumed = false,
    ) {
    }
    /** @return array<string,string> */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ["launch" => "[private Moodle launch reference]"];
    }
}
