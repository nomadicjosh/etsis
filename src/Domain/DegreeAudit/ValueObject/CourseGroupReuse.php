<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

/** Reusing completed work across requirement groups; distinct from repeat-award treatment. */
enum CourseGroupReuse: string
{
    case OneGroup = "one_group";
    case MultipleGroups = "multiple_groups";
}
