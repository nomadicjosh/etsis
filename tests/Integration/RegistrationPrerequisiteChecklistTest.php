<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Registration\ValueObject\EnrollmentHistoryId;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class RegistrationPrerequisiteChecklistTest extends TestCase
{
    private function fixture(): array
    {
        require_once dirname(__DIR__) . "/Support/TransferAwardFixture.php";
        $s = new \SisFixture();
        $t = new \TransferAwardFixture($s, $s->dropCipher);
        $next = $s->section(20, 0);
        $s->registration->addPrerequisite($next->courseId, $t->course);
        return [$s, $t, $next, new RegistrationBatchSelection($next->id, $t->student)];
    }

    public function testSharedLabelsAndCompactPerStudentChecksDoNotExposeSupportingRecords(): void
    {
        [$s, $t, $next] = $this->fixture();
        $other = $s->student()->id;
        $selection = new RegistrationBatchSelection($next->id, $t->student, $other);
        $before = $s->sql->rows("sis_history_events");
        $review = $s->registrationBatches()->review($selection);
        self::assertCount(1, $review["section"]["prerequisite_courses"]);
        self::assertSame(
            $s->registration->course($t->course)->code,
            $review["section"]["prerequisite_courses"][0]["code"],
        );
        $rows = array_column($review["students"], null, "id");
        self::assertSame(["met"], $rows[$t->student->value]["prerequisites"]["course_statuses"]);
        self::assertSame(["missing"], $rows[$other->value]["prerequisites"]["course_statuses"]);
        foreach (["credit_id", "source_id", "support", "academic_version", "pursuit_version"] as $field) {
            self::assertStringNotContainsString(
                '"' . $field . '"',
                json_encode($review, JSON_THROW_ON_ERROR),
            );
        }
        self::assertSame($before, $s->sql->rows("sis_history_events"));
    }

    public function testReceivingCourseCorrectionChangesNewChecksButPreservesAcceptedEvidenceAndRetries(): void
    {
        [$s, $t, $next, $selection] = $this->fixture();
        $accepted = $s->service->register($t->student, $next->id);
        $events = iterator_to_array(
            \EventStoreFixture::framework($s->connection->queryBuilder())->getAggregateHistoryFor(
                new EnrollmentHistoryId($accepted->id->value),
            ),
        );
        $evidence = $events[0]->metaParam("prerequisite_evidence");
        $pendingSection = $s->section(20, 0);
        $s->registration->addPrerequisite($pendingSection->courseId, $t->course);
        $pendingSelection = new RegistrationBatchSelection($pendingSection->id, $t->student);
        $batch = $s->registrationBatches();
        $pending = $batch->review($pendingSelection);
        $replacementCourse = $s->section(20, 0)->courseId;
        $publication = $s->publishCourse($replacementCourse);
        $replacement = $t
            ->courses()
            ->review(
                $t->student,
                $t->award,
                $publication,
                0,
                $s->clock->now()->format("Y-m-d"),
                "Fictional receiving-course correction.",
                $replacementCourse,
            );
        $t->courses()->commit($t->student, $t->award, $replacement["review_token"]);
        $before = $s->sql->rows("sis_history_events");
        $fresh = $batch->review($pendingSelection);
        self::assertSame(["missing"], $fresh["students"][0]["prerequisites"]["course_statuses"]);
        try {
            $batch->commit(
                new EntityId($pending["operation_id"]),
                $pendingSelection,
                $pending["revision"],
                $pending["review_token"],
            );
            self::fail("A pending registration used replaced prerequisite evidence.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $s->sql->rows("sis_history_events"));
        }
        self::assertSame($accepted->id->value, $s->service->register($t->student, $next->id)->id->value);
        $events = iterator_to_array(
            \EventStoreFixture::framework($s->connection->queryBuilder())->getAggregateHistoryFor(
                new EnrollmentHistoryId($accepted->id->value),
            ),
        );
        self::assertSame($evidence, $events[0]->metaParam("prerequisite_evidence"));
        $newSection = $s->section(20, 0);
        $s->registration->addPrerequisite($newSection->courseId, $replacementCourse);
        $new = $batch->review(new RegistrationBatchSelection($newSection->id, $t->student));
        self::assertSame(["met"], $new["students"][0]["prerequisites"]["course_statuses"]);
        $old = $batch->review($selection);
        self::assertSame([], $old["students"][0]["prerequisites"]["course_statuses"]);
        self::assertSame("not_rechecked", $old["students"][0]["prerequisites"]["status"]);
    }

    public function testCurrentLabelChangesRequireFreshReviewWithoutCreatingHistoricalFacts(): void
    {
        [$s, $t, , $selection] = $this->fixture();
        $batch = $s->registrationBatches();
        $review = $batch->review($selection);
        $s->sql->update(
            "sis_courses",
            ["title" => "Fictional revised catalog label"],
            ["id" => $t->course->value],
        );
        $before = $s->sql->rows("sis_history_events");
        try {
            $batch->commit(
                new EntityId($review["operation_id"]),
                $selection,
                $review["revision"],
                $review["review_token"],
            );
            self::fail("Displayed labels changed without a fresh review.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $s->sql->rows("sis_history_events"));
        }
        $fresh = $batch->review($selection);
        self::assertSame(
            "Fictional revised catalog label",
            $fresh["section"]["prerequisite_courses"][0]["title"],
        );
    }
}
