<?php

declare(strict_types=1);

namespace Domain\Catalog\Enum;

enum ClassificationKind: string
{
    case Department = "department";
    case AcademicLevel = "academic_level";
    case CourseLevel = "course_level";
}
