<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Catalog\ValueObject\ContinuingEducationUnits;
use InvalidArgumentException;

/** Working section choice; published definitions retain the resolved CEUs instead. */
final readonly class OfferingCeuChoice
{
    public function __construct(public string $mode = 'inherit', public ?int $milli = null)
    {
        if (
            !in_array($mode, ['inherit', 'none', 'fixed'], true) ||
            ($mode === 'fixed') !== ($milli !== null)
        ) {
            throw new InvalidArgumentException('Choose inherited, no or fixed offered CEUs.');
        }
        new ContinuingEducationUnits($milli);
    }

    public static function stored(?string $data): self
    {
        if ($data === null) {
            return new self();
        }
        if (strlen($data) > 128) {
            throw new InvalidArgumentException('Use a bounded offering CEU choice.');
        }
        $value = json_decode($data, true, 4, JSON_THROW_ON_ERROR);
        if (
            !is_array($value) || count($value) !== 2 ||
            !is_string($value['mode'] ?? null) || !array_key_exists('ceu_milli', $value) ||
            ($value['ceu_milli'] !== null && !is_int($value['ceu_milli']))
        ) {
            throw new InvalidArgumentException('Provide the complete offering CEU choice.');
        }
        return new self($value['mode'], $value['ceu_milli']);
    }

    public function resolve(?ContinuingEducationUnits $course): ?ContinuingEducationUnits
    {
        return $this->mode === 'inherit' ? $course : new ContinuingEducationUnits($this->milli);
    }

    /** @return array{mode: string, ceu_milli: ?int} */
    public function describe(): array
    {
        return ['mode' => $this->mode, 'ceu_milli' => $this->milli];
    }
}
