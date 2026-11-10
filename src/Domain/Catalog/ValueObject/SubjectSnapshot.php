<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Frozen subject fields retained once per course publication. */
final readonly class SubjectSnapshot
{
    public function __construct(public EntityId $id, public string $code, public string $title)
    {
        if (
            preg_match('~^[A-Z0-9][A-Z0-9._/-]{0,39}$~D', $code) !== 1 ||
            trim($title) === "" ||
            mb_strlen($title) > 191 ||
            !mb_check_encoding($title, "UTF-8") ||
            preg_match('/[\x00-\x1F\x7F]/', $title)
        ) {
            throw new InvalidArgumentException(
                "Use a subject code up to 40 letters, digits or . _ / -, and a name up to 191 characters.",
            );
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 3 ||
            !is_string($data["id"] ?? null) ||
            !is_string($data["code"] ?? null) ||
            !is_string($data["title"] ?? null)
        ) {
            throw new InvalidArgumentException("Use an exact subject identity, code and name.");
        }
        return new self(new EntityId($data["id"]), $data["code"], $data["title"]);
    }

    /** @return array{id: string, code: string, title: string} */
    public function describe(): array
    {
        return ["id" => $this->id->value, "code" => $this->code, "title" => $this->title];
    }
}
