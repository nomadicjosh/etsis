<?php

declare(strict_types=1);

namespace Domain\Scheduling;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

final readonly class Building
{
    public function __construct(public EntityId $id, public string $code, public string $name)
    {
        foreach (['code' => [$code, 80], 'name' => [$name, 191]] as $field => [$value, $limit]) {
            if (
                $value === '' || trim($value) !== $value || !mb_check_encoding($value, 'UTF-8')
                || mb_strlen($value) > $limit || preg_match('/[\x00-\x1f\x7f]/', $value)
            ) {
                throw new ValidationException([['field' => $field,
                    'message' => 'Enter a building ' . $field . ' of up to ' . $limit . ' characters.']]);
            }
        }
    }
}
