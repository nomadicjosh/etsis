<?php

declare(strict_types=1);

namespace Domain\Catalog\Enum;

enum ClassificationKind: string
{
    case Department = "department";
    case AcademicLevel = "academic_level";
    case CourseLevel = "course_level";
    case Award = "award";

    /** @return list<self> */
    public static function courseKinds(): array
    {
        return [self::Department, self::AcademicLevel, self::CourseLevel];
    }

    /** @return list<self> */
    public static function programKinds(): array
    {
        return [self::Department, self::AcademicLevel, self::Award];
    }
}
