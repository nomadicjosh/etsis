<?php

declare(strict_types=1);

namespace Domain\Portal;

use Domain\Shared\ValueObject\EntityId;
use DomainException;
use InvalidArgumentException;

final readonly class PortalPost
{
    public const int WRITE_LIMIT = 60000;
    public ?string $visibleFrom;
    public ?string $visibleUntil;
    public function __construct(
        public EntityId $id,
        public EntityId $ownerUserId,
        public string $kind,
        public string $code,
        public string $title,
        #[\SensitiveParameter] public string $content,
        public string $status = "draft",
        public int $version = 0,
        ?string $visibleFrom = null,
        ?string $visibleUntil = null,
        public ?string $reviewNote = null,
        public string $contentFormat = "plain",
    ) {
        if (
            !in_array($kind, ["page", "news", "link"], true) ||
            preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $code) !== 1 ||
            trim($title) === "" ||
            mb_strlen($title) > 191 ||
            strlen($content) > 100000 ||
            str_contains($content, "\0") ||
            !in_array($status, ["draft", "pending", "published", "archived"], true) ||
            $version < 0 ||
            $version > 2147483646
        ) {
            throw new InvalidArgumentException("A valid portal post is required.");
        }
        if (
            !in_array($contentFormat, ["plain", "html"], true) ||
            ($kind !== "news" && $contentFormat !== "plain")
        ) {
            throw new InvalidArgumentException("Use rich text only for news content.");
        }
        $window = new \Domain\Portal\ValueObject\PublicationWindow($visibleFrom, $visibleUntil);
        $this->visibleFrom = $window->data()["visible_from"];
        $this->visibleUntil = $window->data()["visible_until"];
        if (
            $reviewNote !== null &&
            (!mb_check_encoding($reviewNote, "UTF-8") ||
                mb_strlen($reviewNote) > 1000 ||
                preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reviewNote))
        ) {
            throw new InvalidArgumentException("Use a review note up to 1,000 characters.");
        }
        if (
            $kind === "link" &&
            (filter_var($content, FILTER_VALIDATE_URL) === false ||
                parse_url($content, PHP_URL_SCHEME) !== "https" ||
                parse_url($content, PHP_URL_USER) !== null)
        ) {
            throw new InvalidArgumentException("Portal links must use HTTPS without embedded credentials.");
        }
    }

    /** New writes fit the supported TEXT storage; earlier content remains readable without reinterpretation. */
    public static function requireWritableContent(#[\SensitiveParameter] string $content): void
    {
        if (strlen($content) > self::WRITE_LIMIT) {
            throw new \Domain\Shared\Exception\ValidationException([
                [
                    "field" => "content",
                    "message" => "This update is too long. Shorten its text or formatting before saving.",
                ],
            ]);
        }
    }

    public function transition(
        string $action,
        \Domain\Portal\ValueObject\PublicationWindow $window,
        #[\SensitiveParameter] ?string $note,
    ): self {
        $status = match ($action) {
            "submit" => $this->status === "draft" ? "pending" : null,
            "approve" => in_array($this->status, ["draft", "pending"], true) ? "published" : null,
            "return" => $this->status === "pending" ? "draft" : null,
            "archive" => $this->status === "published" ? "archived" : null,
            default => null,
        };
        if ($status === null) {
            throw new DomainException("This content changed. Reopen it before reviewing.");
        }
        $data = $action === "approve" ? $window->data() : ["visible_from" => null, "visible_until" => null];
        return new self(
            $this->id,
            $this->ownerUserId,
            $this->kind,
            $this->code,
            $this->title,
            $this->content,
            $status,
            $this->version + 1,
            $data["visible_from"],
            $data["visible_until"],
            $action === "return" ? $note : null,
            $this->contentFormat,
        );
    }

    public function visibility(\DateTimeImmutable $now): string
    {
        if ($this->status !== "published") {
            return $this->status;
        }
        $window = new \Domain\Portal\ValueObject\PublicationWindow($this->visibleFrom, $this->visibleUntil);
        return $window->from !== null && $window->from > $now
        ? "scheduled"
        : ($window->until !== null && $window->until <= $now
                ? "expired"
                : "published");
    }
}
