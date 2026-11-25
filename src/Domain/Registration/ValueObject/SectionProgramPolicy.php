<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** An empty list allows every program; a restricted list matches one verified receiving pursuit. */
final readonly class SectionProgramPolicy
{
    public function __construct(public array $programIds = [], public bool $requireCampus = false)
    {
        $sorted = $programIds;
        sort($sorted, SORT_STRING);
        if (
            !array_is_list($programIds) ||
            count($programIds) > 20 ||
            $sorted !== $programIds ||
            count(array_unique($programIds, SORT_STRING)) !== count($programIds)
        ) {
            throw new InvalidArgumentException("Use up to twenty distinct ordered program identities.");
        }
        foreach ($programIds as $id) {
            if (!is_string($id) || new EntityId($id)->value !== $id) {
                throw new InvalidArgumentException("Use canonical program identities.");
            }
        }
    }

    public function allows(EntityId $program): bool
    {
        return $this->programIds === [] || in_array($program->value, $this->programIds, true);
    }

    /** Private readiness contains no pursuit identity, program names or another student's information. */
    public function check(EntityId $program): array
    {
        $allowed = $this->allows($program);
        return [
            "code" => "program_eligibility",
            "label" => \Codefy\Framework\Helpers\trans_html("Eligible program"),
            "status" => $allowed ? "met" : "blocked",
            "message" => $allowed
                ? \Codefy\Framework\Helpers\trans_html("Check passed.")
                : \Codefy\Framework\Helpers\trans_html(
                    "The selected program pursuit is not eligible for this section.",
                ),
            "next_step" => $allowed
                ? null
                : \Codefy\Framework\Helpers\trans_html(
                    "Ask the registrar about sections available to this program pursuit.",
                ),
        ];
    }
}
