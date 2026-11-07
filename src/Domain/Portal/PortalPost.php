<?php

declare(strict_types=1);

namespace Domain\Portal;

use Domain\Shared\ValueObject\EntityId;
use DomainException;
use InvalidArgumentException;

final readonly class PortalPost
{
    public function __construct(
        public EntityId $id,
        public EntityId $ownerUserId,
        public string $kind,
        public string $code,
        public string $title,
        public string $content,
        public string $status = 'draft',
        public int $version = 0
    ) {
        if (
            !in_array($kind, ['page', 'news', 'link'], true) || preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $code) !== 1
            || trim($title) === '' || mb_strlen($title) > 191 || strlen($content) > 100000
                || str_contains($content, "\0")
            || !in_array($status, ['draft', 'published', 'archived'], true)
        ) {
            throw new InvalidArgumentException('A valid portal post is required.');
        }
        if (
            $kind === 'link' && (filter_var($content, FILTER_VALIDATE_URL) === false
            || parse_url($content, PHP_URL_SCHEME) !== 'https' || parse_url($content, PHP_URL_USER) !== null)
        ) {
            throw new InvalidArgumentException('Portal links must use HTTPS without embedded credentials.');
        }
    }

    public function publish(bool $publish): self
    {
        if ($this->status === 'archived' && $publish) {
            throw new DomainException('Archived content requires a new publication.');
        }

        return new self(
            $this->id,
            $this->ownerUserId,
            $this->kind,
            $this->code,
            $this->title,
            $this->content,
            $publish ? 'published' : 'archived',
            $this->version + 1
        );
    }
}
