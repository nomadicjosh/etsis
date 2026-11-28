<?php

declare(strict_types=1);

namespace Domain\Gradebook\ValueObject;

use Brick\Math\BigDecimal;
use Domain\Shared\EventSourcing\CorruptHistoryException;

/** Raw decimal course total only: never names, feedback, formatted HTML or assignment scores. */
final readonly class ExternalCourseGrade
{
    public array $facts;

    public function __construct(#[\SensitiveParameter] array $facts)
    {
        $keys = ["state", "item_id", "grade", "minimum", "maximum", "revision"];
        if (
            count($facts) !== 6 ||
            array_diff(array_keys($facts), $keys) !== [] ||
            !in_array(
                $facts["state"] ?? null,
                ["available", "ungraded", "hidden", "unsupported"],
                true,
            ) ||
            !is_string($facts["revision"]) ||
            preg_match('/^[a-f0-9]{64}$/D', $facts["revision"]) !== 1
        ) {
            throw new CorruptHistoryException();
        }
        if ($facts["state"] !== "available") {
            foreach (["item_id", "grade", "minimum", "maximum"] as $key) {
                if ($facts[$key] !== null) {
                    throw new CorruptHistoryException();
                }
            }
        } else {
            if (
                !is_string($facts["item_id"]) ||
                preg_match('/^[1-9][0-9]{0,18}$/D', $facts["item_id"]) !== 1 ||
                (string) (int) $facts["item_id"] !== $facts["item_id"]
            ) {
                throw new CorruptHistoryException();
            }
            foreach (["grade", "minimum", "maximum"] as $key) {
                if (
                    !is_string($facts[$key]) ||
                    preg_match(
                        '/^-?[0-9]{1,10}(?:\.[0-9]{1,5})?$/D',
                        $facts[$key],
                    ) !== 1
                ) {
                    throw new CorruptHistoryException();
                }
            }
            if (
                BigDecimal::of($facts["maximum"])->compareTo(
                    $facts["minimum"],
                ) <= 0
            ) {
                throw new CorruptHistoryException();
            }
        }
        $this->facts = $facts;
    }
}
