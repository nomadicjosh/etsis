<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Lms\ValueObject\MoodleBinding;
use Domain\Lms\ValueObject\MoodleExternalId;
use Domain\Lms\ValueObject\MoodleRecordCheck;
use Domain\Lms\ValueObject\MoodleResourceKind;

/** One settings snapshot and installation checks surrounding the exact-ID lookup. */
interface MoodleBoundRecordReader
{
    public function enabled(): bool;
    public function inspectBound(
        MoodleBinding $binding,
        MoodleResourceKind $kind,
        MoodleExternalId $id,
    ): MoodleRecordCheck;
}
