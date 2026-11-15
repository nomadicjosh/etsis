<?php

declare(strict_types=1);

namespace Domain\Admissions\Repository;

use Domain\Admissions\ValueObject\SubmissionFact;

/** Internal deterministic read-model writer. The submission command owns authorization and its transaction. */
interface SubmissionProjection
{
    public function project(SubmissionFact $fact): void;
}
