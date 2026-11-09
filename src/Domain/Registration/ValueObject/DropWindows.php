<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use DateTimeImmutable;
use Domain\Shared\Exception\ValidationException;

/** Consecutive late-drop periods. The grace-period rule is independent of grade codes. */
final readonly class DropWindows
{
    /** @var list<array{through: ?string, grade_code: string}> */
    public array $windows;

    /** @param array<array-key, mixed> $windows Untrusted form input, normalized after validation. */
    public function __construct(array $windows)
    {
        if (!array_is_list($windows) || count($windows) < 1 || count($windows) > 12) {
            self::invalid();
        }
        $previous = null;
        $validated = [];
        foreach ($windows as $i => $window) {
            if (
                !is_array($window) ||
                array_diff(array_keys($window), ["through", "grade_code"]) !== [] ||
                !array_key_exists("through", $window) ||
                !is_string($window["grade_code"] ?? null) ||
                preg_match('/^[A-Z0-9+.-]{1,12}$/D', $window["grade_code"]) !== 1
            ) {
                self::invalid();
            }
            $end = $window["through"];
            $code = $window["grade_code"];
            if ($end === null) {
                if ($i !== count($windows) - 1) {
                    self::invalid();
                }
            } else {
                $date = is_string($end) ? DateTimeImmutable::createFromFormat("!Y-m-d", $end) : false;
                if ($date === false || $date->format("Y-m-d") !== $end || ($previous !== null && $end <= $previous)) {
                    self::invalid();
                }
                $previous = $end;
            }
            $validated[] = ["through" => $end, "grade_code" => $code];
        }
        if ($windows[count($windows) - 1]["through"] !== null) {
            self::invalid();
        }
        $this->windows = $validated;
    }
    public function gradeOn(string $date): string
    {
        foreach ($this->windows as $window) {
            if ($window["through"] === null || $date <= $window["through"]) {
                return $window["grade_code"];
            }
        }
        throw new \LogicException("A final open drop-policy window is required.");
    }
    private static function invalid(): never
    {
        throw new ValidationException([
            [
                "field" => "windows",
                "message" =>
                    "Use 1–12 ordered grade periods, with the final period open-ended and a valid grade code in each.",
            ],
        ]);
    }
}
