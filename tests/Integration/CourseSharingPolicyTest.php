<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/StudentRequirementsFixture.php";

use Domain\DegreeAudit\Service\PublishedRequirementsReader;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV2;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\DegreeAudit\ValueObject\CourseGroupReuse;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\ConcurrentModificationException;
use PHPUnit\Framework\TestCase;

final class CourseSharingPolicyTest extends TestCase
{
    public function testNewCompositionsDefaultToOneGroupWithAnExplicitTypedPolicy(): void
    {
        $definition = AuditRequirementsV3::blank("Fictional new degree");
        self::assertSame(CourseGroupReuse::OneGroup, $definition->groupReuse);
        self::assertSame("one_group", $definition->definition["policies"]["group_reuse"]);
        self::assertArrayNotHasKey("group_reuse", $definition->settings->definition["policies"]);
    }

    public function testBothPolicyChoicesAreRetainedAndEarlierPublicationRetriesStayUnchanged(): void
    {
        $f = new ReusableGroupFixture();
        $f->saveDegree();
        $review = $f->degrees->review($f->degree, "Fictional first allocation policy");
        $first = $f->degrees->publish($f->degree, $review["review_token"]);
        $definition = $f->definition;
        $definition["policies"]["group_reuse"] = "multiple_groups";
        $f->degrees->save($f->degree, 1, new AuditRequirementsV3($definition));
        $second = $f->publishDegree();
        $reader = new PublishedRequirementsReader($f->history);
        self::assertSame(
            "one_group",
            $reader->version($f->degree, new EntityId($first["operation_id"]))["definition"]["policies"][
                "group_reuse"
            ],
        );
        $later = $reader->version($f->degree, new EntityId($second["operation_id"]));
        self::assertSame(
            CourseGroupReuse::MultipleGroups,
            new AuditRequirementsV3($later["definition"])->groupReuse,
        );
        self::assertSame(4, $later["schema_version"]);
        $before = $f->digest("sis_history_events");
        self::assertSame($first, $f->degrees->publish($f->degree, $review["review_token"]));
        self::assertSame($before, $f->digest("sis_history_events"));
        self::assertStringNotContainsString(
            "multiple_groups",
            json_encode($f->sis->sql->rows("sis_history_events"), JSON_THROW_ON_ERROR),
        );
    }

    public function testChangingOnlyTheSharingPolicyInvalidatesAnUnconfirmedReview(): void
    {
        $f = new ReusableGroupFixture();
        $f->saveDegree();
        $review = $f->degrees->review($f->degree, "Fictional pending one-group policy");
        $definition = $f->definition;
        $definition["policies"]["group_reuse"] = "multiple_groups";
        $f->degrees->save($f->degree, 1, new AuditRequirementsV3($definition));
        $before = $f->digest("sis_history_events");
        try {
            $f->degrees->publish($f->degree, $review["review_token"]);
            self::fail("A changed allocation policy was published from an older review.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $f->digest("sis_history_events"));
            self::assertSame([], $f->degrees->versions($f->degree)["items"]);
        }
    }

    public function testStudentRetainsTheSelectedPolicyAfterCatalogStaffPublishAnotherChoice(): void
    {
        $f = new StudentRequirementsFixture();
        $f->assign();
        $definition = $f->f->definition;
        $definition["policies"]["group_reuse"] = "multiple_groups";
        $f->f->degrees->save($f->f->degree, 1, new AuditRequirementsV3($definition));
        $later = $f->f->publishDegree();
        self::assertSame(
            "one_group",
            $f->service->view($f->student, $f->assignment)["requirements"]["definition"]["policies"][
                "group_reuse"
            ],
        );
        $f->version = new EntityId($later["operation_id"]);
        $f->assign();
        self::assertSame(
            "multiple_groups",
            $f->service->view($f->student, $f->assignment)["requirements"]["definition"]["policies"][
                "group_reuse"
            ],
        );
    }

    public function testCloningKeepsTheReviewedChoiceAndCreatesNoPublication(): void
    {
        $f = new ReusableGroupFixture();
        $f->definition["policies"]["group_reuse"] = "multiple_groups";
        $f->saveDegree();
        $published = $f->publishDegree();
        $year = new EntityId(new AcademicCalendarFixture($f->sis)->year(2028)["id"]);
        $before = $f->digest("sis_history_events");
        $clone = $f->degrees->cloneTo(
            $f->degree,
            new EntityId($published["operation_id"]),
            $f->sis->program,
            $year,
        );
        self::assertSame(
            CourseGroupReuse::MultipleGroups,
            new AuditRequirementsV3($clone["definition"])->groupReuse,
        );
        self::assertNull($clone["published_version_id"]);
        self::assertSame($before, $f->digest("sis_history_events"));
    }

    public function testMissingUnknownAndExecutableCompositionPolicyValuesAreRejected(): void
    {
        $f = new ReusableGroupFixture();
        $values = [null, false, 1, [], "sql", "unrestricted"];
        foreach ($values as $value) {
            $definition = $f->definition;
            $definition["policies"]["group_reuse"] = $value;
            try {
                new AuditRequirementsV3($definition);
                self::fail("Invalid sharing policy accepted.");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $definition = $f->definition;
        unset($definition["policies"]["group_reuse"]);
        $this->expectException(InvalidArgumentException::class);
        new AuditRequirementsV3($definition);
    }

    public function testEarlierInlinePoliciesRemainUnknownUntilAnExplicitNewDraftConversion(): void
    {
        $f = new ReusableGroupFixture();
        $inline = $f->oldDefinition();
        self::assertArrayNotHasKey("group_reuse", new AuditRequirementsV2($inline)->definition["policies"]);
        $f->sis->sql->update(
            "sis_degree_audit_templates",
            ["draft_json" => json_encode($inline, JSON_THROW_ON_ERROR)],
            ["id" => $f->degree->value],
        );
        $before = $f->digest("sis_history_events");
        $converted = $f->degrees->reuseLegacy($f->degree, 0);
        self::assertSame("one_group", $converted["definition"]["policies"]["group_reuse"]);
        self::assertSame($before, $f->digest("sis_history_events"));
        self::assertArrayNotHasKey("group_reuse", $inline["policies"]);
    }
}
