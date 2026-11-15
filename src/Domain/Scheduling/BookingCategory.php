<?php

declare(strict_types=1);

namespace Domain\Scheduling;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

final readonly class BookingCategory
{
    public const array TONES = ['blue', 'teal', 'amber', 'purple', 'gray'];

    public function __construct(
        public EntityId $id,
        public string $code,
        public string $name,
        public string $tone = 'blue',
        public bool $active = true,
        public int $version = 0
    ) {
        foreach (['code' => [$code, 80], 'name' => [$name, 191]] as $field => [$value, $limit]) {
            if (
                $value === '' || trim($value) !== $value || !mb_check_encoding($value, 'UTF-8')
                || mb_strlen($value) > $limit || preg_match('/[\x00-\x1f\x7f]/', $value)
            ) {
                throw new ValidationException([['field' => $field,
                    'message' => 'Enter a category ' . $field . ' of up to ' . $limit . ' characters.']]);
            }
        }
        if (!in_array($tone, self::TONES, true)) {
            throw new ValidationException([['field' => 'tone', 'message' => 'Choose one of the category colors.']]);
        }
    }

    /** @return array{id:string,code:string,name:string,tone:string,active:bool,version:int} */
    public function details(): array
    {
        return ['id' => $this->id->value, 'code' => $this->code, 'name' => $this->name,
            'tone' => $this->tone, 'active' => $this->active, 'version' => $this->version];
    }
}
