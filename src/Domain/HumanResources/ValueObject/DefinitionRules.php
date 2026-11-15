<?php

declare(strict_types=1);

namespace Domain\HumanResources\ValueObject;

use Domain\Finance\ValueObject\Money;
use Domain\Shared\Exception\ValidationException;
use Money\Currencies\ISOCurrencies;
use Money\Currency;

/** Shared validation for editable HR reference definitions, not employee compensation. */
final class DefinitionRules
{
    public static function code(string $value): string
    {
        $value = strtoupper(trim($value));
        if (preg_match('~^[A-Z0-9][A-Z0-9._/-]{0,39}$~D', $value) !== 1) {
            self::invalid('code', 'Use a code of up to 40 letters, numbers, dots, slashes, underscores or hyphens.');
        }
        return $value;
    }
    public static function title(string $value): string
    {
        $value = trim($value);
        if (
            $value === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > 191
            || preg_match('/[\x00-\x1f\x7f]/', $value)
        ) {
            self::invalid('title', 'Enter a title of up to 191 characters.');
        }
        return $value;
    }
    public static function units(string $currency, string $basis): void
    {
        self::precision($currency);
        if (!in_array($basis, ['hourly', 'annual'], true)) {
            self::invalid('basis', 'Choose an hourly or annual rate.');
        }
    }
    public static function precision(string $currency): int
    {
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1 || !new ISOCurrencies()->contains(new Currency($currency))) {
            self::invalid('currency', 'Choose a supported ISO currency.');
        }
        return new ISOCurrencies()->subunitFor(new Currency($currency));
    }
    public static function amount(?int $value, string $field): void
    {
        if ($value !== null && ($value < 0 || $value > Money::MAX_MINOR)) {
            self::invalid($field, 'Enter a nonnegative amount within the supported range.');
        }
    }
    public static function invalid(string $field, string $message): never
    {
        throw new ValidationException([['field' => $field, 'message' => $message]]);
    }
}
