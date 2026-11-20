<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use Domain\Shared\Exception\ValidationException;

/** Inclusive minimums; the next band's minimum is the exclusive upper boundary. */
final readonly class EnrollmentLoadBands
{
    public array $definition;

    public function __construct(array $definition)
    {
        $bands = $definition["bands"] ?? null;
        if (
            count($definition) !== 1 ||
            !is_array($bands) ||
            !array_is_list($bands) ||
            count($bands) < 1 ||
            count($bands) > 12
        ) {
            self::invalid("Use one to twelve credit bands.");
        }
        $previous = -1;
        $codes = [];
        $rows = [];
        foreach ($bands as $band) {
            if (
                !is_array($band) ||
                count($band) !== 3 ||
                array_diff(array_keys($band), ["code", "label", "min_credits_milli"]) !== [] ||
                !is_string($band["code"] ?? null) ||
                preg_match('~^[A-Z0-9][A-Z0-9_.\/-]{0,31}$~D', $band["code"]) !== 1 ||
                !is_string($band["label"] ?? null) ||
                trim($band["label"]) === "" ||
                $band["label"] !== trim($band["label"]) ||
                mb_strlen($band["label"]) > 120 ||
                !mb_check_encoding($band["label"], "UTF-8") ||
                preg_match('/[\x00-\x1F\x7F]/', $band["label"]) ||
                !is_int($band["min_credits_milli"] ?? null) ||
                $band["min_credits_milli"] < 0 ||
                $band["min_credits_milli"] > 1000000 ||
                $band["min_credits_milli"] <= $previous ||
                isset($codes[$band["code"]])
            ) {
                self::invalid(
                    "Give bands unique codes and names, with increasing credit minimums from zero to 1,000 credits.",
                );
            }
            if ($previous === -1 && $band["min_credits_milli"] !== 0) {
                self::invalid("Start the first credit band at zero so there are no gaps.");
            }
            $rows[] = [
                "code" => $band["code"],
                "label" => $band["label"],
                "min_credits_milli" => $band["min_credits_milli"],
            ];
            $codes[$band["code"]] = true;
            $previous = $band["min_credits_milli"];
        }
        $this->definition = ["bands" => $rows];
    }

    public function classify(int $credits): array
    {
        if ($credits < 0) {
            self::invalid("Credit totals cannot be negative.");
        }
        $selected = $this->definition["bands"][0];
        foreach ($this->definition["bands"] as $band) {
            if ($credits < $band["min_credits_milli"]) {
                break;
            }
            $selected = $band;
        }
        return $selected;
    }

    private static function invalid(string $message): never
    {
        throw new ValidationException([["field" => "definition", "message" => $message]]);
    }
}
