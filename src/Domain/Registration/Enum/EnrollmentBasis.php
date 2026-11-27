<?php

declare(strict_types=1);

namespace Domain\Registration\Enum;

enum EnrollmentBasis: string
{
    case Standard = 'standard';
    case Audit = 'audit';

    public static function parse(string $value): self
    {
        return self::tryFrom($value) ?? throw new \Domain\Shared\Exception\ValidationException([
            ['field' => 'basis', 'message' => \Codefy\Framework\Helpers\trans('Choose a valid enrollment basis.')],
        ]);
    }
}
