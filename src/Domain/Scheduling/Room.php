<?php

declare(strict_types=1);

namespace Domain\Scheduling;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

final readonly class Room
{
    public function __construct(public EntityId $id, public string $code, public int $capacity)
    {
        if ($code === '' || trim($code) !== $code || mb_strlen($code) > 80 || preg_match('/[\x00-\x1F\x7F]/', $code)) {
            throw new ValidationException([['field' => 'code',
                'message' => 'Enter a room code of up to 80 characters.']]);
        }
        if ($capacity < 1 || $capacity > 100000) {
            throw new ValidationException([['field' => 'capacity',
                'message' => 'Capacity must be between 1 and 100,000.']]);
        }
    }
}
