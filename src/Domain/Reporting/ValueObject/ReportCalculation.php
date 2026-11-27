<?php

declare(strict_types=1);

namespace Domain\Reporting\ValueObject;

use Domain\Shared\Exception\ValidationException;

use function Codefy\Framework\Helpers\trans;

/** Typed arithmetic operands; decimal literals remain strings across JSON and PDO boundaries. */
final readonly class ReportCalculation
{
    public const int MAX_DEPTH = 4;
    public const int MAX_OPERATIONS = 15;

    /** @param array<string,mixed> $definition */
    public function __construct(public array $definition)
    {
        $operations = 1;
        if (
            count($definition) !== (array_key_exists('left', $definition) ? 3 : 2) ||
            !in_array($definition['operator'] ?? null, ['+', '-', '*', '/'], true)
        ) {
            self::invalid();
        }
        $operand = $definition;
        unset($operand['operator'], $operand['left']);
        self::validateOperand($operand, 0, $operations);
        if (array_key_exists('left', $definition)) {
            if (!is_array($definition['left'])) {
                self::invalid();
            }
            self::validateOperand($definition['left'], 0, $operations);
        }
    }

    /** @param array<string,mixed> $operand */
    private static function validateOperand(array $operand, int $depth, int &$operations): void
    {
        if (count($operand) !== 1) {
            self::invalid();
        }
        if (array_key_exists('field', $operand)) {
            if (!is_string($operand['field']) || $operand['field'] === '' || strlen($operand['field']) > 120) {
                self::invalid();
            }
            return;
        }
        if (array_key_exists('value', $operand)) {
            if (
                !is_string($operand['value']) ||
                preg_match('/\A-?(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,6})?\z/D', $operand['value']) !== 1
            ) {
                throw new ValidationException([['field' => 'calculation', 'message' => trans(
                    'Enter a decimal constant with up to nine whole digits and six decimal places. '
                    . 'Use a dot for decimals.',
                )]]);
            }
            return;
        }
        $group = $operand['group'] ?? null;
        if (
            $depth >= self::MAX_DEPTH || ++$operations > self::MAX_OPERATIONS ||
            !is_array($group) || count($group) !== 3 ||
            !in_array($group['operator'] ?? null, ['+', '-', '*', '/'], true) ||
            !is_array($group['left'] ?? null) || !is_array($group['right'] ?? null)
        ) {
            self::invalid();
        }
        self::validateOperand($group['left'], $depth + 1, $operations);
        self::validateOperand($group['right'], $depth + 1, $operations);
    }

    /** Every referenced field, including fields visible only inside a group.
     * @return list<string>
     */
    public function fields(): array
    {
        $fields = [];
        $collect = function (array $operand) use (&$collect, &$fields): void {
            if (isset($operand['field'])) {
                $fields[] = $operand['field'];
            } elseif (isset($operand['group'])) {
                $collect($operand['group']['left']);
                $collect($operand['group']['right']);
            }
        };
        $collect($this->definition);
        if (isset($this->definition['left'])) {
            $collect($this->definition['left']);
        }
        return array_values(array_unique($fields));
    }

    public function nested(): bool
    {
        return isset($this->definition['group']) || isset($this->definition['left']);
    }

    private static function invalid(): never
    {
        throw new ValidationException([['field' => 'calculation', 'message' => trans(
            'Choose numeric fields, decimal constants and valid operations, '
            . 'with up to four group levels and fifteen operations.',
        )]]);
    }
}
