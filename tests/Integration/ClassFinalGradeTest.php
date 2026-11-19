<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class ClassFinalGradeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    /** @return array{SisFixture,\Domain\Registration\Section,array<int,array<string,mixed>>} */
    private function fixture(): array
    {
        $f = new SisFixture();
        $f->gradeScale([
            [
                "code" => "A",
                "min_score_milli" => 90000,
                "max_score_milli" => 100000,
                "points_milli" => 4000,
                "passes" => true,
                "counts_gpa" => true,
            ],
            [
                "code" => "B",
                "min_score_milli" => 80000,
                "max_score_milli" => 89999,
                "points_milli" => 3000,
                "passes" => true,
                "counts_gpa" => true,
            ],
        ]);
        $section = $f->section(faculty: $f->access->actor);
        $rows = [];
        for ($index = 0; $index < 2; $index++) {
            $student = $f->student();
            $enrollment = $f->service->register($student->id, $section->id);
            $choices = $f->gradebook()->gradeChoices($student->id, $section->id);
            $rows[] = [
                "student" => $student->id,
                "enrollment" => $enrollment->id,
                "version" => $choices["current_version"],
                "grade" => new EntityId($choices["items"][$index]["id"]),
            ];
        }
        return [$f, $section, $rows];
    }
    /** @param array<string,mixed> $row
     * @return array<string,mixed> */
    private function review(SisFixture $f, EntityId $section, array $row): array
    {
        return $f
            ->gradebook()
            ->reviewFinalGrade(
                $row["student"],
                $section,
                $row["grade"],
                $row["version"],
                expectedEnrollment: $row["enrollment"],
                initialOnly: true,
            );
    }

    public function testInitialGradeReviewsDoNotPostAndEachConfirmationHasItsOwnAtomicOutcome(): void
    {
        [$f, $section, $rows] = $this->fixture();
        $events = $f->sql->rows("sis_history_events");
        $credits = $f->sql->rows("sis_academic_credits");
        $financial = $f->financialEvents();
        $first = $this->review($f, $section->id, $rows[0]);
        $second = $this->review($f, $section->id, $rows[1]);
        self::assertFalse($first["amendment"]);
        self::assertFalse($second["amendment"]);
        self::assertSame($rows[0]["enrollment"]->value, $first["enrollment_id"]);
        self::assertNotSame($first["operation_id"], $second["operation_id"]);
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertSame($credits, $f->sql->rows("sis_academic_credits"));
        $saved = $f->gradebook()->commitFinalGrade($rows[0]["student"], $section->id, $first["review_token"]);
        $after = $f->sql->rows("sis_history_events");
        $f->clock->time = $f->clock->time->modify("+20 minutes");
        try {
            $f->gradebook()->commitFinalGrade($rows[1]["student"], $section->id, $second["review_token"]);
            self::fail("An expired unconfirmed row posted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($after, $f->sql->rows("sis_history_events"));
            self::assertNull(
                $f->sql->one("sis_academic_credits", ["source_id" => $rows[1]["enrollment"]->value])[
                    "grade_id"
                ],
            );
        }
        self::assertSame(
            $saved,
            $f->gradebook()->commitFinalGrade($rows[0]["student"], $section->id, $first["review_token"]),
        );
        self::assertSame($after, $f->sql->rows("sis_history_events"));
        self::assertSame($financial, $f->financialEvents());
    }

    public function testAReviewCannotBeRedirectedToAnotherRegistration(): void
    {
        [$f, $section, $rows] = $this->fixture();
        $before = $f->sql->rows("sis_history_events");
        try {
            $f->gradebook()->reviewFinalGrade(
                $rows[0]["student"],
                $section->id,
                $rows[0]["grade"],
                0,
                expectedEnrollment: $rows[1]["enrollment"],
                initialOnly: true,
            );
            self::fail("An old or foreign registration was ignored.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $f->sql->rows("sis_history_events"));
        }
        self::assertSame(
            $rows[0]["enrollment"]->value,
            $this->review($f, $section->id, $rows[0])["enrollment_id"],
        );
    }

    public function testAnInitialOnlyReviewCannotBecomeACorrectionEvenForAuthorizedRegistrarStaff(): void
    {
        [$f, $section, $rows] = $this->fixture();
        $review = $this->review($f, $section->id, $rows[0]);
        $f->gradebook()->commitFinalGrade($rows[0]["student"], $section->id, $review["review_token"]);
        $choices = $f->gradebook()->gradeChoices($rows[0]["student"], $section->id);
        $rows[0]["version"] = $choices["current_version"];
        $before = $f->sql->rows("sis_history_events");
        try {
            $this->review($f, $section->id, $rows[0]);
            self::fail("The initial-grade sheet prepared a correction.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $f->sql->rows("sis_history_events"));
        }
        $correction = $f
            ->gradebook()
            ->reviewFinalGrade(
                $rows[0]["student"],
                $section->id,
                $rows[0]["grade"],
                $choices["current_version"],
                reason: "Fictional authorized individual correction.",
                expectedEnrollment: $rows[0]["enrollment"],
            );
        self::assertTrue($correction["amendment"]);
    }

    public function testConfirmationRechecksCurrentAuthorityForEveryStudent(): void
    {
        [$f, $section, $rows] = $this->fixture();
        $first = $this->review($f, $section->id, $rows[0]);
        $second = $this->review($f, $section->id, $rows[1]);
        $f->gradebook()->commitFinalGrade($rows[0]["student"], $section->id, $first["review_token"]);
        $before = $f->sql->rows("sis_history_events");
        $f->access->staff = false;
        $f->access->actor = $f->user();
        try {
            $f->gradebook()->commitFinalGrade($rows[1]["student"], $section->id, $second["review_token"]);
            self::fail("A different unassigned user posted the next grade.");
        } catch (AccessDeniedException) {
            self::assertSame($before, $f->sql->rows("sis_history_events"));
        }
    }

    public function testAnUnpostedReviewCannotAmendAGradeConfirmedByAnotherReview(): void
    {
        [$f, $section, $rows] = $this->fixture();
        $old = $this->review($f, $section->id, $rows[0]);
        $competing = $rows[0];
        $competing["grade"] = $rows[1]["grade"];
        $fresh = $this->review($f, $section->id, $competing);
        $f->gradebook()->commitFinalGrade($rows[0]["student"], $section->id, $fresh["review_token"]);
        $before = $f->sql->rows("sis_history_events");
        try {
            $f->gradebook()->commitFinalGrade($rows[0]["student"], $section->id, $old["review_token"]);
            self::fail("An initial review became a later grade correction.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $f->sql->rows("sis_history_events"));
            self::assertSame(
                $competing["grade"]->value,
                $f->sql->one("sis_academic_credits", ["source_id" => $rows[0]["enrollment"]->value])[
                    "grade_id"
                ],
            );
        }
    }
}
