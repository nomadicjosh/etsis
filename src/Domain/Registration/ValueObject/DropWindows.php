<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use DateTimeImmutable;
use Domain\Shared\Exception\ValidationException;

use function Codefy\Framework\Helpers\trans;

/** Consecutive late-drop periods. The grace-period rule is independent of grade codes. */
final readonly class DropWindows
{
    /** @var list<array{through: ?string, grade_code: string, credit_basis_points?: int}> */
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
                array_diff(array_keys($window), ["through", "grade_code", 'credit_basis_points']) !== [] ||
                !array_key_exists("through", $window) ||
                !is_string($window["grade_code"] ?? null) ||
                preg_match('/^[A-Z0-9+.-]{1,12}$/D', $window["grade_code"]) !== 1
            ) {
                self::invalid();
            }
            $end = $window["through"];
            $code = $window["grade_code"];
            if (
                array_key_exists('credit_basis_points', $window) &&
                (!is_int($window['credit_basis_points']) || $window['credit_basis_points'] < 0 ||
                    $window['credit_basis_points'] > 10000)
            ) {
                self::invalid();
            }
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
            if (array_key_exists('credit_basis_points', $window)) {
                $validated[array_key_last($validated)]['credit_basis_points'] = $window['credit_basis_points'];
            }
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

    public function creditOn(string $date): ?int
    {
        foreach ($this->windows as $window) {
            if ($window['through'] === null || $date <= $window['through']) {
                return $window['credit_basis_points'] ?? null;
            }
        }
        throw new \LogicException('A final open drop-policy window is required.');
    }

    public function hasCredits(): bool
    {
        return array_any($this->windows, static fn (array $row): bool => ($row['credit_basis_points'] ?? 0) > 0);
    }
    private static function invalid(): never
    {
        throw new ValidationException([
            [
                "field" => "windows",
                "message" =>
                    trans('Use 1–12 ordered periods with valid grade codes, a final open period '
                        . 'and optional account credits from 0 to 100 percent.'),
            ],
        ]);
    }
}
