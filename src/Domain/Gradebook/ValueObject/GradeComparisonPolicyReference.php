<?php

declare(strict_types=1);

namespace Domain\Gradebook\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** An exact institutional publication, a built-in default, or an explicit working section override. */
final readonly class GradeComparisonPolicyReference
{
    public function __construct(public array $facts)
    {
        if (
            count($facts) !== 3 ||
            array_diff(array_keys($facts), ["scope", "publication_id", "version"]) !== [] ||
            !in_array($facts["scope"], ["institution", "section"], true)
        ) {
            throw new InvalidArgumentException("Invalid grade comparison policy reference.");
        }
        if ($facts["scope"] === "section") {
            if ($facts["publication_id"] !== null || $facts["version"] !== null) {
                throw new InvalidArgumentException(
                    "A section override is retained with its calculation inputs.",
                );
            }
        } elseif (
            !is_int($facts["version"]) ||
            $facts["version"] < 0 ||
            $facts["version"] > 2000 ||
            ($facts["version"] === 0
                ? $facts["publication_id"] !== null
                : !is_string($facts["publication_id"]))
        ) {
            throw new InvalidArgumentException(
                "Invalid institutional comparison policy publication.",
            );
        } elseif ($facts["publication_id"] !== null) {
            new EntityId($facts["publication_id"]);
        }
    }
    public function describe(): array
    {
        return ["scope" => $this->facts["scope"], "version" => $this->facts["version"]];
    }
}
