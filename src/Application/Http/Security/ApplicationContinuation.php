<?php

declare(strict_types=1);

namespace Application\Http\Security;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Public catalog hints only; never accepts a return URL or changes application authorization. */
final class ApplicationContinuation
{
    public static function selection(array $values): array
    {
        $result = [];
        foreach (['program', 'term'] as $field) {
            if (!is_string($values[$field] ?? null)) {
                continue;
            }
            try {
                $result[$field] = new EntityId($values[$field])->value;
            } catch (InvalidArgumentException) {
                continue;
            }
        }
        return $result;
    }

    public static function query(array $values): string
    {
        return ($values['continue'] ?? null) === 'apply'
        ? '?' . http_build_query(['continue' => 'apply'] + self::selection($values)) : '';
    }

    public static function target(array $values): string
    {
        $selection = self::selection($values);
        return '/portal/apply/' . ($selection === [] ? '' : '?' . http_build_query($selection));
    }
}
