<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class PolicyReferences
{
    /** @param array<mixed> $references */
    public function __construct(public array $references)
    {
        if (!array_is_list($references) || count($references) > 20) {
            self::invalid();
        }
        $seen = [];
        foreach ($references as $r) {
            if (
                !is_array($r) ||
                count($r) !== 6 ||
                array_diff(array_keys($r), [
                    "assignment_id",
                    "program_id",
                    "label",
                    "scale_id",
                    "scale_version_id",
                    "known",
                ]) !== [] ||
                !is_bool($r["known"]) ||
                ($r["scale_id"] === null) !== ($r["scale_version_id"] === null) ||
                $r["known"] !== ($r["scale_version_id"] !== null) ||
                !is_string($r["label"]) ||
                trim($r["label"]) === "" ||
                mb_strlen($r["label"]) > 191 ||
                !mb_check_encoding($r["label"], "UTF-8") ||
                preg_match('/[\x00-\x1F\x7F]/', $r["label"])
            ) {
                self::invalid();
            }
            foreach (["assignment_id", "program_id", "scale_id", "scale_version_id"] as $key) {
                if ($r[$key] !== null) {
                    if (!is_string($r[$key])) {
                        self::invalid();
                    }
                    new EntityId($r[$key]);
                }
            }
            $key = $r["assignment_id"] ?? "global";
            if (isset($seen[$key])) {
                self::invalid();
            }
            $seen[$key] = true;
        }
    }
    private static function invalid(): never
    {
        throw new InvalidArgumentException("Use bounded, explicit grading policy references.");
    }
}
