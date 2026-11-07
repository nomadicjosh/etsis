<?php

declare(strict_types=1);

namespace Domain\Communications;

use Domain\Shared\ValueObject\EntityId;
use DomainException;

final readonly class Campaign
{
    public function __construct(
        public EntityId $id,
        public EntityId $ownerUserId,
        #[\SensitiveParameter] public string $encryptedContent,
        public string $status = 'draft',
        public int $version = 0,
    ) {
        if (!in_array($status, ['draft', 'queued', 'paused', 'completed'], true)) {
            throw new DomainException('Invalid campaign state.');
        }
    }

    public function transition(string $status): self
    {
        $allowed = ['draft' => ['queued'], 'queued' => ['paused', 'completed'], 'paused' => ['queued'],
            'completed' => []];
        if (!in_array($status, $allowed[$this->status], true)) {
            throw new DomainException('Invalid campaign transition.');
        }

        return new self($this->id, $this->ownerUserId, $this->encryptedContent, $status, $this->version + 1);
    }
}
