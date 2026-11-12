<?php

declare(strict_types=1);

namespace Domain\Lms\ValueObject;

enum MoodleResourceKind: string
{
    case User = "user";
    case Course = "course";
}
