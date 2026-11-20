<?php

declare(strict_types=1);

namespace Domain\Catalog\Enum;

enum ClassificationKind: string
{
    case Department = "department";
    case AcademicLevel = "academic_level";
    case CourseLevel = "course_level";
    case Award = "award";
    case InstructionalMethod = "instructional_method";
    case Campus = "campus";
    case School = "school";
    case FieldOfStudy = "field_of_study";

    /** @return list<self> */
    public static function courseKinds(): array
    {
        return [self::Department, self::AcademicLevel, self::CourseLevel];
    }

    /** @return list<self> */
    public static function programKinds(): array
    {
        return [
            self::Department,
            self::AcademicLevel,
            self::Award,
            self::School,
            self::FieldOfStudy,
            self::Campus,
        ];
    }
}
