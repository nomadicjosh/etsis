<?php

declare(strict_types=1);

namespace Domain\Import\Enum;

/** Server-owned destinations; browser input never supplies table or column names. */
enum CatalogImportTarget: string
{
    case Course = "course";
    case Program = "program";

    public function idField(): string
    {
        return $this->value . "_id";
    }

    public function references(): array
    {
        return $this === self::Course
        ? [
                "subject" => "Subject",
                "department" => "Department",
                "academic_level" => "Academic level",
                "course_level" => "Course level",
            ]
        : [
                "department" => "Department",
                "academic_level" => "Academic level",
                "award" => "Award",
                "school" => "School/college",
                "field_of_study" => "Field of study",
                "campus" => "Campus/site",
            ];
    }
}
