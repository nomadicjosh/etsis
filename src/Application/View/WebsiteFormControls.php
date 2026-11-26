<?php

declare(strict_types=1);

namespace Application\View;

/** Shared presentation rules for native form blocks; submission authorization belongs to their handler. */
final class WebsiteFormControls
{
    public static function id(string $prefix = 'field'): string
    {
        $prefix = preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,39}$/D', $prefix) === 1 ? $prefix : 'field';

        return 'website-' . $prefix . '-' . bin2hex(random_bytes(8));
    }

    public static function name(string $name, string $fallback): string
    {
        return preg_match('/^[a-zA-Z_][a-zA-Z0-9_.\[\]-]{0,79}$/D', $name) === 1 && $name !== '_token'
        ? $name : $fallback;
    }

    public static function inputType(string $type): string
    {
        return in_array($type, ['text', 'email', 'password', 'hidden', 'number', 'tel', 'url', 'date'], true)
        ? $type : 'text';
    }

    public static function length(string $value, int $maximum): ?int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        return $parsed !== false && $parsed >= 1 && $parsed <= $maximum ? $parsed : null;
    }
}
