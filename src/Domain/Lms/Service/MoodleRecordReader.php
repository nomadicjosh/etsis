<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Lms\ValueObject\MoodleExternalId;
use Domain\Lms\ValueObject\MoodleRecordCheck;
use Domain\Lms\ValueObject\MoodleResourceKind;

/** Deployment-operator preflight only. No bulk search, profile output, mapping writes or access grants. */
interface MoodleRecordReader
{
    public function enabled(): bool;
    public function inspect(MoodleResourceKind $kind, MoodleExternalId $id): MoodleRecordCheck;
}
