<?php

declare(strict_types=1);

namespace Domain\Catalog;

use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class CatalogYear
{
    public function __construct(
        public EntityId $id,
        public string $code,
        public string $title,
        public DateRange $cohort,
        public bool $published = false,
    ) {
        if (
            preg_match('/^[12][0-9]{3}$/D', $code) !== 1 ||
            trim($title) === "" ||
            mb_strlen($title) > 80 ||
            !mb_check_encoding($title, "UTF-8") ||
            preg_match('/[\x00-\x1F\x7F]/', $title) ||
            $cohort->end === null
        ) {
            throw new InvalidArgumentException(
                "A four-digit catalog year, label and finite matriculation cohort dates are required.",
            );
        }
    }
    public function revision(): string
    {
        return hash(
            "sha256",
            json_encode(
                [
                    $this->code,
                    $this->title,
                    $this->cohort->start->format("Y-m-d"),
                    $this->cohort->end?->format("Y-m-d"),
                    $this->published,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
