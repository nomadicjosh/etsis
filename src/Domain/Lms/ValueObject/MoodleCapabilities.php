<?php

declare(strict_types=1);

namespace Domain\Lms\ValueObject;

/** Discovery only: availability does not establish usable permissions, identity matching or sync readiness. */
final readonly class MoodleCapabilities
{
    public const array FUNCTIONS = [
        "core_webservice_get_site_info",
        "core_user_get_users_by_field",
        "core_course_get_courses_by_field",
        "core_course_create_courses",
        "core_course_update_courses",
        "enrol_manual_enrol_users",
        "enrol_manual_unenrol_users",
        "gradereport_user_get_grade_items",
    ];
    /** @var array<string,bool> */
    private array $available;

    /** @param list<string> $functions Available function names only; never pass account/profile fields. */
    public function __construct(#[\SensitiveParameter] array $functions)
    {
        $available = [];
        foreach (self::FUNCTIONS as $name) {
            $available[$name] = in_array($name, $functions, true);
        }
        $this->available = $available;
    }
    /** @return array<string,bool> */
    public function describe(): array
    {
        return $this->available;
    }
}
