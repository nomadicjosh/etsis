<?php

declare(strict_types=1);

namespace Domain\Admissions\Repository;

interface AdmissionOfferPolicyRepository
{
    public const string ID = '01J00000000000000000000A0F';
    public function lock(): void;
    /** @return array{require_acceptance:bool,revision:int} */
    public function get(): array;
    public function save(bool $required, int $expectedRevision): void;
}
