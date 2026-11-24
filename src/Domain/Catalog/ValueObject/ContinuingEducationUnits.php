<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use InvalidArgumentException;

/** Declared/offered CEUs, never academic credits, contact hours or an earned award. */
final readonly class ContinuingEducationUnits
{
    public function __construct(public ?int $milli = null)
    {
        if ($milli !== null && ($milli < 0 || $milli > 999999)) {
            throw new InvalidArgumentException('Use CEU thousandths between zero and 999999.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (
            array_keys($data) !== ['ceu_milli'] ||
            ($data['ceu_milli'] !== null && !is_int($data['ceu_milli']))
        ) {
            throw new InvalidArgumentException('Provide an explicit CEU value or no CEUs.');
        }
        return new self($data['ceu_milli']);
    }

    public static function stored(?string $data): ?self
    {
        if ($data === null) {
            return null;
        }
        if (strlen($data) > 128) {
            throw new InvalidArgumentException('Use a bounded CEU definition.');
        }
        $value = json_decode($data, true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new InvalidArgumentException('Provide a complete CEU definition.');
        }
        return self::fromArray($value);
    }

    /** @return array{ceu_milli: ?int} */
    public function describe(): array
    {
        return ['ceu_milli' => $this->milli];
    }
}
