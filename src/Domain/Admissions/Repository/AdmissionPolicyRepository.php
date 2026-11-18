<?php

declare(strict_types=1);

namespace Domain\Admissions\Repository;

interface AdmissionPolicyRepository
{
    public const string ID = '01J00000000000000000000A0F';
    public function lock(): void;
    /** @return array{require_acceptance:bool,revision:int} */
    public function offers(): array;
    public function saveOffers(bool $required, int $expectedRevision): void;
    /** @return array{mode:string,revision:int} */
    public function checklist(): array;
    public function saveChecklist(bool $required, int $expectedRevision): void;
    public function answers(): array;
    public function saveAnswers(string $mode, int $expectedRevision): void;
    public function withdrawals(): array;
    public function saveWithdrawals(string $mode, int $expectedRevision): void;
}
