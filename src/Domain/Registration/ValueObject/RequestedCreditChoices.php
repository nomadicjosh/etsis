<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans_html;

/** Bounded unaccepted choices; keys refer only to the students or sections in this working selection. */
final class RequestedCreditChoices
{
    public static function validate(array $choices, array $ids): array
    {
        $allowed = array_fill_keys(array_map(static fn (EntityId $id): string => $id->value, $ids), true);
        foreach ($choices as $id => $milli) {
            if (!isset($allowed[$id]) || !is_int($milli) || $milli < 0 || $milli > 999999) {
                throw new ValidationException([['field' => 'credit_choices', 'message' => trans_html(
                    'Choose exact academic credits only for records in this selection.'
                )]]);
            }
        }
        ksort($choices, SORT_STRING);
        return $choices;
    }
}
