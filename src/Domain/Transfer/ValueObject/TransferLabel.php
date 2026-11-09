<?php

declare(strict_types=1);

namespace Domain\Transfer\ValueObject;

use Domain\Shared\Exception\ValidationException;

final readonly class TransferLabel
{
    public string $code;
    public string $title;

    public function __construct(string $code, string $title, string $labelField = 'title')
    {
        if (!in_array($labelField, ['name', 'title'], true)) {
            throw new \InvalidArgumentException('Unknown transfer label field.');
        }
        $this->code = trim($code);
        $this->title = trim($title);
        foreach (['code' => [$this->code, 80], $labelField => [$this->title, 191]] as $field => [$value, $limit]) {
            if (
                !mb_check_encoding($value, 'UTF-8') || $value === '' || mb_strlen($value) > $limit
                || preg_match('/[\x00-\x1F\x7F]/', $value)
            ) {
                throw new ValidationException([
                    ['field' => $field, 'message' => 'Enter a valid label within the field limit.'],
                ]);
            }
        }
    }
}
