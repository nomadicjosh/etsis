<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Frozen v1 contract for the fields the current catalog actually records. */
final readonly class CourseDefinition
{
    /** @param list<string> $prerequisites */
    public function __construct(
        public string $code,
        public string $title,
        public int $creditsMilli,
        public array $prerequisites,
    ) {
        if (
            trim($code) === "" ||
            strlen($code) > 80 ||
            trim($title) === "" ||
            mb_strlen($title) > 191 ||
            $creditsMilli < 0 ||
            $creditsMilli > 999999
        ) {
            throw new InvalidArgumentException("Valid course code, title and credit thousandths are required.");
        }
        if (
            !mb_check_encoding($code . $title, "UTF-8") ||
            preg_match('/[\x00-\x1F\x7F]/', $code . $title) ||
            count($prerequisites) > 100
        ) {
            throw new InvalidArgumentException("A bounded, valid course definition is required.");
        }
        $sorted = $prerequisites;
        sort($sorted, SORT_STRING);
        if ($sorted !== $prerequisites || count(array_unique($prerequisites)) !== count($prerequisites)) {
            throw new InvalidArgumentException("Course prerequisite identities must be distinct and ordered.");
        }
        foreach ($prerequisites as $id) {
            new EntityId($id);
        }
    }

    /** @return array{code: string, title: string, credits_milli: int, prerequisites: list<string>} */
    public function describe(): array
    {
        return [
            "code" => $this->code,
            "title" => $this->title,
            "credits_milli" => $this->creditsMilli,
            "prerequisites" => $this->prerequisites,
        ];
    }
}
