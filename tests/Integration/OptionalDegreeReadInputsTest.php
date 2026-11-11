<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once dirname(__DIR__) . "/Support/ReusableGroupFixture.php";

use Infrastructure\Persistence\Repository\SqlAcademicWorkRepository;
use Infrastructure\Persistence\Repository\SqlCourseGroupRepository;
use Infrastructure\Persistence\Repository\SqlReplacementCourseRepository;
use Infrastructure\Persistence\Repository\SqlRequirementsRepository;
use PHPUnit\Framework\TestCase;
use ReusableGroupFixture;

final class OptionalDegreeReadInputsTest extends TestCase
{
    public function testOmittedReadCursorsStartAtTheFirstPageWithoutDefaultingRequiredScopeFilters(): void
    {
        $f = new ReusableGroupFixture();
        $groups = new SqlCourseGroupRepository($f->sis->sql);
        $published = $groups->page(search: "", publishedOnly: true);
        self::assertSame($f->group->value, $published[0]["id"]);
        $requirements = new SqlRequirementsRepository($f->sis->sql)->page($f->sis->program);
        self::assertSame($f->degree->value, $requirements[0]["id"]);
        $choices = new SqlReplacementCourseRepository($f->sis->sql)->page("");
        self::assertNotEmpty($choices);
        $student = $f->sis->student()->id;
        $section = $f->sis->section(20, 0);
        $enrollment = $f->sis->service->register($student, $section->id);
        $work = new SqlAcademicWorkRepository($f->sis->sql)->page($student);
        self::assertCount(1, $work);
        self::assertSame($enrollment->id->value, $work[0]["source_id"]);
    }
}
