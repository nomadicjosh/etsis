<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use Domain\Shared\Exception\ValidationException;

/** Institutional boundary and a program's explicit restrictions, without academic-level inference. */
final readonly class SpecializationPolicy
{
    public int $effectiveMaximum;

    public function __construct(
        public int $institutionMaximum = 1,
        public int $institutionDefault = 1,
        public int $minimum = 0,
        public ?int $maximum = null,
    ) {
        $errors = [];
        if ($institutionMaximum < 1 || $institutionMaximum > 100) {
            $errors[] = [
                'field' => 'institution_maximum',
                'message' => 'Choose an institutional ceiling from 1 through 100.',
            ];
        }
        if ($institutionDefault < 1 || $institutionDefault > $institutionMaximum) {
            $errors[] = [
                'field' => 'institution_default',
                'message' => 'The default maximum must fit within the institutional ceiling.',
            ];
        }
        $effective = $maximum ?? $institutionDefault;
        if ($effective < 0 || $effective > $institutionMaximum) {
            $errors[] = [
                'field' => 'maximum',
                'message' => 'The program maximum must fit within the institutional ceiling.',
            ];
        }
        if ($minimum < 0 || $minimum > $effective) {
            $errors[] = [
                'field' => 'minimum',
                'message' => 'The required number must be between zero and this program’s maximum.',
            ];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->effectiveMaximum = $effective;
    }
}
