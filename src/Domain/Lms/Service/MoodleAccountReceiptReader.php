<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Lms\ValueObject\MoodleAccountReceipt;
use Domain\Lms\ValueObject\MoodleBinding;
use Domain\Shared\ValueObject\EntityId;

interface MoodleAccountReceiptReader
{
    /** Read an existing receipt only; never provision an account or send email. */
    public function receipt(
        MoodleBinding $binding,
        EntityId $operation,
        string $requestHash,
    ): MoodleAccountReceipt;
}
