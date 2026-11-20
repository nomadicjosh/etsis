<?php

declare(strict_types=1);

namespace Domain\Transcript\Repository;

use Domain\Transcript\ValueObject\TranscriptReleasePolicy;

interface TranscriptReleasePolicyRepository
{
    public const string POLICY_ID = '01J00000000000000000000TP0';

    public function current(): TranscriptReleasePolicy;

    public function lock(): void;

    /** Caller holds the policy lock. */
    public function save(TranscriptReleasePolicy $policy, int $expectedRevision): void;

    /** Short commit guard; ordinary views and document rendering never take this lock. */
    public function assertCurrent(TranscriptReleasePolicy $expected): void;
}
