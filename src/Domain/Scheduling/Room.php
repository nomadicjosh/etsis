<?php

declare(strict_types=1);

namespace Domain\Scheduling;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

final readonly class Room
{
    public function __construct(
        public EntityId $id,
        public string $code,
        public int $capacity,
        public ?string $name,
        public ?EntityId $buildingId,
    ) {
        if (
            $code === '' || trim($code) !== $code || !mb_check_encoding($code, 'UTF-8')
            || mb_strlen($code) > 80 || preg_match('/[\x00-\x1F\x7F]/', $code)
        ) {
            throw new ValidationException([['field' => 'code',
                'message' => 'Enter a room code of up to 80 characters.']]);
        }
        if ($capacity < 1 || $capacity > 100000) {
            throw new ValidationException([['field' => 'capacity',
                'message' => 'Capacity must be between 1 and 100,000.']]);
        }
        if (
            $name !== null && ($name === '' || trim($name) !== $name || !mb_check_encoding($name, 'UTF-8')
            || mb_strlen($name) > 191 || preg_match('/[\x00-\x1f\x7f]/', $name))
        ) {
            throw new ValidationException([['field' => 'name',
                'message' => 'Enter a room name of up to 191 characters.']]);
        }
    }
}
