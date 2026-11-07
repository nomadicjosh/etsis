<?php

declare(strict_types=1);

namespace Domain\Shared\Exception;

use InvalidArgumentException;

final class ValidationException extends InvalidArgumentException
{
    /** @param list<array{field: string, message: string}> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Please correct the highlighted fields.');
    }
}
