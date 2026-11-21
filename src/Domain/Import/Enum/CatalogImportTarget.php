<?php

declare(strict_types=1);

namespace Domain\Import\Enum;

use Domain\Catalog\Enum\ClassificationKind;

/** Server-owned destinations; browser input never supplies table or column names. */
enum CatalogImportTarget: string
{
    case Course = "course";
    case Program = "program";
    case Subject = "subject";
    case Department = "department";
    case AcademicLevel = "academic_level";
    case CourseLevel = "course_level";
    case Award = "award";
    case InstructionalMethod = "instructional_method";
    case Campus = "campus";
    case School = "school";
    case FieldOfStudy = "field_of_study";

    public function classification(): ?ClassificationKind
    {
        return ClassificationKind::tryFrom($this->value);
    }

    public function isReference(): bool
    {
        return $this !== self::Course && $this !== self::Program;
    }

    public function idField(): string
    {
        return $this->classification() !== null ? "classification_id" : $this->value . "_id";
    }

    public function references(): array
    {
        if ($this->isReference()) {
            return [];
        }
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
