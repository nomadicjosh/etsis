<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/ReusableGroupFixture.php";
require_once dirname(__DIR__) . "/Support/ProgramPublicationFixture.php";
require_once dirname(__DIR__) . "/Support/StudentRequirementsFixture.php";

use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class RequirementProgramLinkTest extends TestCase
{
    public function testNewRequirementsCannotBeAssignedAcrossProgramCatalogRevisions(): void
    {
        $student = new StudentRequirementsFixture();
        $r = $student->f;
        $original = $student->review();
        $student->service->assign($student->student, $student->assignment, $original["review_token"]);
        $this->program($r, new ProgramPublicationFixture($r->sis));
        $review = $r->degrees->review($r->degree, "Fictional linked requirements");
        $version = new EntityId($r->degrees->publish($r->degree, $review["review_token"])["operation_id"]);
        $before = $r->sis->sql->rows("sis_history_events");
        $choices = $student->service->choices($student->student, $student->assignment);
        $linkedChoice = array_values(
            array_filter(
                $choices["items"],
                static fn(array $item): bool => $item["version_id"] === $version->value,
            ),
        )[0];
        self::assertFalse($linkedChoice["assignable"]);
        self::assertStringContainsString(
            "different program catalog revision",
            $linkedChoice["assignment_note"],
        );
        try {
            $student->service->review(
                $student->student,
                $student->assignment,
                $r->degree,
                $version,
                new \Domain\DegreeAudit\ValueObject\PursuitStart("2026-01-31"),
                "Fictional wrong revision",
            );
            self::fail("A student was assigned requirements for another program catalog revision.");
        } catch (InvalidArgumentException) {
            self::assertSame($before, $r->sis->sql->rows("sis_history_events"));
            self::assertSame(
                $student->version->value,
                $student->service->view($student->student, $student->assignment)["requirements"][
                    "version_id"
                ],
            );
        }
    }

    private function program(ReusableGroupFixture $r, ProgramPublicationFixture $p): array
    {
        $review = $p->service->review($r->sis->program, $r->year, "Fictional linked program");
        return $p->service->publish($r->sis->program, $review["review_token"]);
    }

    public function testReviewedProgramLinkAndLabelsSurviveLaterDraftsPublicationsAndRetries(): void
    {
        $r = new ReusableGroupFixture();
        $p = new ProgramPublicationFixture($r->sis);
        $first = $this->program($r, $p);
        $r->saveDegree();
        $draft = $p->service->draft($r->sis->program);
        $p->service->save(
            $r->sis->program,
            "FUTURE",
            "Unpublished future title",
            null,
            null,
            null,
            null,
            $draft["revision"],
        );
        $review = $r->degrees->review($r->degree, "Fictional degree approval");
        self::assertSame($first["operation_id"], $review["program_publication"]["version_id"]);
        self::assertSame(1, $review['program_publication']['catalog_revision']);
        self::assertSame('2027-2028', $review['program_publication']['academic_year_code']);
        self::assertSame("Computer Science", $review["context"]["program_title"]);
        $receipt = $r->degrees->publish($r->degree, $review["review_token"]);
        $page = $r->degrees->versions($r->degree);
        self::assertSame($first["operation_id"], $page["items"][0]["program_version_id"]);
        $reader = new \Domain\DegreeAudit\Service\PublishedRequirementsReader($r->history);
        self::assertSame(
            $first["operation_id"],
            $reader->version($r->degree, new EntityId($receipt["operation_id"]))["program_version_id"],
        );
        $this->program($r, $p);
        $historical = $reader->version($r->degree, new EntityId($receipt["operation_id"]));
        self::assertSame("Computer Science", $historical["program_publication"]["definition"]["title"]);
        self::assertSame(1, $historical["program_publication"]["catalog_revision"]);
        self::assertSame(
            "Computer Science",
            $r->degrees->version($r->degree, new EntityId($receipt["operation_id"]))["program_publication"][
                "definition"
            ]["title"],
        );
        $before = $r->sis->sql->rows("sis_history_events");
        self::assertSame($receipt, $r->degrees->publish($r->degree, $review["review_token"]));
        self::assertSame($before, $r->sis->sql->rows("sis_history_events"));
    }

    public function testNewProgramPublicationInvalidatesAnUnconfirmedDegreeReviewAtomically(): void
    {
        $r = new ReusableGroupFixture();
        $p = new ProgramPublicationFixture($r->sis);
        $this->program($r, $p);
        $r->saveDegree();
        $review = $r->degrees->review($r->degree, "Fictional pending degree review");
        $this->program($r, $p);
        $before = $r->sis->sql->rows("sis_history_events");
        try {
            $r->degrees->publish($r->degree, $review["review_token"]);
            self::fail("An outdated program revision was accepted.");
        } catch (\Domain\Shared\Exception\ConcurrentModificationException) {
            self::assertSame($before, $r->sis->sql->rows("sis_history_events"));
            self::assertSame([], $r->degrees->versions($r->degree)["items"]);
        }
    }

    public function testUnlinkedEarlierPublicationRemainsUnlinkedAfterAProgramIsPublished(): void
    {
        $r = new ReusableGroupFixture();
        $r->saveDegree();
        $review = $r->degrees->review($r->degree, "Fictional earlier requirements");
        self::assertNull($review["program_publication"]);
        $receipt = $r->degrees->publish($r->degree, $review["review_token"]);
        $this->program($r, new ProgramPublicationFixture($r->sis));
        self::assertNull($r->degrees->versions($r->degree)["items"][0]["program_version_id"]);
        self::assertSame($receipt, $r->degrees->publish($r->degree, $review["review_token"]));
    }

    public function testHistoricalProgramInspectionRejectsAnotherAcademicYear(): void
    {
        $r = new ReusableGroupFixture();
        $publication = $this->program($r, new ProgramPublicationFixture($r->sis));
        $reader = new \Domain\DegreeAudit\Service\PublishedRequirementsReader($r->history);
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $reader->programDefinition(
            $r->sis->program,
            EntityId::generate(),
            new EntityId($publication["operation_id"]),
        );
    }
}
