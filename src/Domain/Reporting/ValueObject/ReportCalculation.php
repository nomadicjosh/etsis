<?php

declare(strict_types=1);

namespace Domain\Reporting\ValueObject;

use Domain\Shared\Exception\ValidationException;

use function Codefy\Framework\Helpers\trans;

/** One typed arithmetic operation; decimal literals remain strings across JSON and PDO boundaries. */
final readonly class ReportCalculation
{
    /** @param array<string,mixed> $definition */
    public function __construct(public array $definition)
    {
        $field = array_key_exists('field', $definition);
        $value = array_key_exists('value', $definition);
        if (
            $field === $value || count($definition) !== 2 ||
            !in_array($definition['operator'] ?? null, ['+', '-', '*', '/'], true) ||
            ($field && (!is_string($definition['field']) || $definition['field'] === ''))
        ) {
            throw new ValidationException([['field' => 'calculation', 'message' =>
                trans('Choose one numeric field or decimal constant and a valid operation.')]]);
        }
        if (
            $value && (!is_string($definition['value']) ||
                preg_match('/\A-?(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,6})?\z/D', $definition['value']) !== 1)
        ) {
            throw new ValidationException([['field' => 'calculation', 'message' => trans(
                'Enter a decimal constant with up to nine whole digits and six decimal places. Use a dot for decimals.',
            )]]);
        }
    }
}
