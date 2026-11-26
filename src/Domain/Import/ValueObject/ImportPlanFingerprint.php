<?php

declare(strict_types=1);

namespace Domain\Import\ValueObject;

final class ImportPlanFingerprint
{
    /** Presentation and chunk-local record numbers do not grant authority to change data. */
    public static function of(#[\SensitiveParameter] array $row): string
    {
        unset($row["row"], $row["message"]);
        return hash("sha256", json_encode($row, JSON_THROW_ON_ERROR));
    }
}
