<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use Domain\Student\Enum\HiatusCode;
use InvalidArgumentException;

final readonly class HiatusPolicy
{
    public const array DEFAULTS = ['W' => ['deactivate' => true, 'courses' => true]];
    /** @var array<string, array{deactivate: bool, courses: bool}> */
    private array $proposals;

    /** @param array<string, mixed> $proposals */
    public function __construct(array $proposals = self::DEFAULTS)
    {
        $values = [];
        foreach ($proposals as $code => $row) {
            if (
                HiatusCode::tryFrom($code) === null || !is_array($row)
                || !is_bool($row['deactivate'] ?? null) || !is_bool($row['courses'] ?? null)
            ) {
                throw new InvalidArgumentException('Hiatus proposals require recognized codes and boolean choices.');
            }
            $values[$code] = ['deactivate' => $row['deactivate'], 'courses' => $row['courses']];
        }
        $this->proposals = $values;
    }

    /** @return array{deactivate: bool, courses: bool} */
    public function for(HiatusCode $code): array
    {
        return $this->proposals[$code->value] ?? ['deactivate' => false, 'courses' => false];
    }
}
