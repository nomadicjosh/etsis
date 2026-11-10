<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use InvalidArgumentException;

final readonly class ProgramDefinition
{
    public ?string $description;

    public function __construct(
        public string $code,
        public string $title,
        ?string $description = null,
        public ProgramClassifications $classifications = new ProgramClassifications(),
    ) {
        if (
            preg_match('/^[A-Z0-9][A-Z0-9._-]{0,79}$/D', $code) !== 1 ||
            trim($title) === "" ||
            mb_strlen($title) > 191 ||
            !mb_check_encoding($title, "UTF-8") ||
            preg_match('/[\x00-\x1F\x7F]/', $title) ||
            ($description !== null &&
                (mb_strlen($description) > 10000 ||
                    !mb_check_encoding($description, "UTF-8") ||
                    preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $description)))
        ) {
            throw new InvalidArgumentException(
                "Use a valid program code, title and plain-text description up to 10000 characters.",
            );
        }
        $this->description = $description === null || trim($description) === "" ? null : trim($description);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 4 ||
            !is_string($data["code"] ?? null) ||
            !is_string($data["title"] ?? null) ||
            !array_key_exists("description", $data) ||
            ($data["description"] !== null && !is_string($data["description"])) ||
            !is_array($data["classifications"] ?? null)
        ) {
            throw new InvalidArgumentException("Provide a complete program definition.");
        }
        return new self(
            $data["code"],
            $data["title"],
            $data["description"],
            ProgramClassifications::fromArray($data["classifications"]),
        );
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return [
            "code" => $this->code,
            "title" => $this->title,
            "description" => $this->description,
            "classifications" => $this->classifications->describe(),
        ];
    }
}
