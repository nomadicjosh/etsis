<?php

declare(strict_types=1);

namespace Domain\Registration\Waitlist;

use Domain\Shared\ValueObject\EntityId;

/** Signed, selected-request input. Possession alone never grants registration authority. */
final readonly class WaitlistBypass implements \JsonSerializable
{
    public const string DUTY = 'sis:registration:waitlist:bypass';

    public function __construct(
        public EntityId $section,
        public EntityId $student,
        public EntityId $person,
        public EntityId $entry,
        public EntityId $operation,
        public int $version,
        public string $assessmentRevision,
        #[\SensitiveParameter] public string $reason,
        #[\SensitiveParameter] public string $token,
    ) {
    }

    public function revision(): string
    {
        return WaitlistPolicy::hash([
            $this->section->value, $this->student->value, $this->person->value,
            $this->entry->value, $this->operation->value, $this->version,
            $this->assessmentRevision, $this->reason,
        ]);
    }

    public function jsonSerialize(): array
    {
        return ['waitlist_bypass' => '[private reviewed registration]'];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
