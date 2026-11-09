<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use InvalidArgumentException;

/** SQL fragments for repository-owned aliases/expressions; never accepts browser input. */
final readonly class SectionCourseSql
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function joins(string $sectionAlias): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $sectionAlias) !== 1) {
            throw new InvalidArgumentException("Invalid section alias.");
        }
        $q = $this->sql->quote(...);
        return " LEFT JOIN " .
        $q("sis_section_course_versions") .
        " scb ON scb." .
        $q("id") .
        " = " .
        $sectionAlias .
        "." .
        $q("id") .
        " LEFT JOIN " .
        $q("sis_course_versions") .
        " pcv ON pcv." .
        $q("id") .
        " = scb." .
        $q("course_version_id");
    }

    public function value(string $field, string $unboundExpression): string
    {
        if (!in_array($field, ["code", "title", "credits_milli", "description", "transcript_title"], true)) {
            throw new InvalidArgumentException("Unknown retained course field.");
        }
        $q = $this->sql->quote(...);
        // A retained null stays unknown; never coalesce it into today's catalog text.
        return "CASE WHEN scb." .
        $q("id") .
        " IS NULL THEN " .
        $unboundExpression .
        " ELSE pcv." .
        $q($field) .
        " END";
    }
}
