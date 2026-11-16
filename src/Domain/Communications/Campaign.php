<?php

declare(strict_types=1);

namespace Domain\Communications;

use Domain\Shared\ValueObject\EntityId;
use DomainException;

final readonly class Campaign
{
    public const array TEMPLATE_STATES = ['template', 'template_review', 'template_shared', 'template_retired'];

    public function __construct(
        public EntityId $id,
        public EntityId $ownerUserId,
        #[\SensitiveParameter] public string $encryptedContent,
        public string $status = 'draft',
        public int $version = 0,
    ) {
        if (!in_array($status, ['draft', 'queued', 'paused', 'completed', ...self::TEMPLATE_STATES], true)) {
            throw new DomainException('Invalid campaign state.');
        }
    }

    public function transition(string $status): self
    {
        $allowed = ['draft' => ['queued'], 'queued' => ['paused', 'completed'], 'paused' => ['queued', 'completed'],
            'completed' => [], 'template' => ['template_review', 'template_retired'],
            'template_review' => ['template', 'template_shared', 'template_retired'],
            'template_shared' => ['template', 'template_retired'], 'template_retired' => ['template']];
        if (!in_array($status, $allowed[$this->status], true)) {
            throw new DomainException('Invalid campaign transition.');
        }

        return new self($this->id, $this->ownerUserId, $this->encryptedContent, $status, $this->version + 1);
    }
}
