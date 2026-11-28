<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use EventStoreFixture;
use PHPUnit\Framework\TestCase;
use Tests\Support\MoodleGradeImportFixture;

final class MoodleGradeImportTest extends TestCase
{
    public function testMaximumLengthUnicodeReasonCanBeReviewedAndConfirmed(): void
    {
        $f = new MoodleGradeImportFixture();
        $row = $f->collect();
        $section = $f->offering->section->id;
        $id = new EntityId($row["id"]);
        $grade = new EntityId(
            $f->service()->view($section, $id)["choices"]["items"][0]["id"],
        );
        $review = $f
            ->service()
            ->review(
                $section,
                $id,
                1,
                "accept",
                $grade,
                str_repeat("学", 1000),
            );
        self::assertSame(
            "accepted",
            $f->service()->confirm($section, $id, $review["review_token"])[
                "state"
            ],
        );
    }

    public function testQueueStatusAloneCannotClaimAnAcademicGradeWasRecorded(): void
    {
        $f = new MoodleGradeImportFixture();
        $row = $f->collect();
        $id = new EntityId($row["id"]);
        $f->records->change($id, 1, ["state" => "accepted"]);
        $this->expectException(
            \Domain\Shared\EventSourcing\CorruptHistoryException::class,
        );
        $f->service()->view($f->offering->section->id, $id);
    }

    public function testRetainedDispositionCannotBeRelabeledAsAnotherOutcome(): void
    {
        $f = new MoodleGradeImportFixture();
        $row = $f->collect();
        $section = $f->offering->section->id;
        $id = new EntityId($row["id"]);
        $review = $f
            ->service()
            ->review(
                $section,
                $id,
                1,
                "dismiss",
                null,
                "Fictional retained disposition.",
            );
        $f->service()->confirm($section, $id, $review["review_token"]);
        $current = $f->records->candidate($id);
        $f->records->change($id, (int) $current["revision"], [
            "state" => "requested",
        ]);
        $this->expectException(
            \Domain\Shared\EventSourcing\CorruptHistoryException::class,
        );
        $f->service()->view($section, $id);
    }

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/MoodleGradeImportFixture.php";
    }

    public function testBackgroundCollectionPublishesNothingAndNotifiesOnlyTheRequester(): void
    {
        $f = new MoodleGradeImportFixture();
        $digest = EventStoreFixture::historyDigest($f->sis->sql);
        $row = $f->collect();
        self::assertSame("pending", $row["state"]);
        self::assertSame(
            $digest,
            EventStoreFixture::historyDigest($f->sis->sql),
        );
        self::assertSame(1, $f->remote->calls);
        self::assertStringNotContainsString("91.25", $row["encrypted_source"]);
        $notices = $f->service()->notices(true);
        self::assertCount(1, $notices["items"]);
        self::assertSame("complete", $notices["items"][0]["state"]);
        $f->service()->notice(
            new EntityId($notices["items"][0]["id"]),
            1,
            true,
        );
        self::assertSame([], $f->sis->sql->rows("sis_moodle_grade_notices"));
    }

    public function testReviewedGradeRetainsSourceAndOriginalRetryAfterDisableAndExpiry(): void
    {
        $f = new MoodleGradeImportFixture();
        $row = $f->collect();
        $section = $f->offering->section->id;
        $id = new EntityId($row["id"]);
        $view = $f->service()->view($section, $id);
        $grade = new EntityId($view["choices"]["items"][0]["id"]);
        $review = $f
            ->service()
            ->review(
                $section,
                $id,
                1,
                "accept",
                $grade,
                "Fictional registrar grade decision.",
            );
        $receipt = $f
            ->service()
            ->confirm($section, $id, $review["review_token"]);
        self::assertSame("accepted", $receipt["state"]);
        $facts = $f->sis
            ->academicCredits()
            ->history($f->student, $section, $f->enrollment);
        $imported = array_values(
            array_filter(
                $facts["items"],
                static fn(array $item): bool => isset(
                    $item["external_grade_summary"],
                ),
            ),
        );
        self::assertSame(
            "91.25000",
            $imported[0]["external_grade_summary"]["grade"]["grade"],
        );
        $f->policy->on = false;
        $f->sis->clock->time = $f->sis->clock->time->modify("+2 days");
        self::assertSame(
            $receipt,
            $f->service()->confirm($section, $id, $review["review_token"]),
        );
        self::assertSame(1, $f->remote->calls);
    }

    public function testNewObservationInvalidatesAnUnacceptedReview(): void
    {
        $f = new MoodleGradeImportFixture();
        $row = $f->collect();
        $section = $f->offering->section->id;
        $id = new EntityId($row["id"]);
        $view = $f->service()->view($section, $id);
        $review = $f
            ->service()
            ->review(
                $section,
                $id,
                1,
                "accept",
                new EntityId($view["choices"]["items"][0]["id"]),
                "Fictional stale review.",
            );
        $f->remote->grade["grade"] = "84.00000";
        $f->collect();
        $this->expectException(ConcurrentModificationException::class);
        $f->service()->confirm($section, $id, $review["review_token"]);
    }

    public function testUnchangedCollectionCannotDuplicateAnAcceptedGrade(): void
    {
        $f = new MoodleGradeImportFixture();
        $row = $f->collect();
        $section = $f->offering->section->id;
        $id = new EntityId($row["id"]);
        $grade = new EntityId(
            $f->service()->view($section, $id)["choices"]["items"][0]["id"],
        );
        $review = $f
            ->service()
            ->review(
                $section,
                $id,
                1,
                "accept",
                $grade,
                "Fictional accepted result.",
            );
        $f->service()->confirm($section, $id, $review["review_token"]);
        $before = EventStoreFixture::historyDigest($f->sis->sql);
        $f->collect();
        self::assertCount(1, $f->records->page($section));
        self::assertSame(
            $before,
            EventStoreFixture::historyDigest($f->sis->sql),
        );
        self::assertSame("accepted", $f->records->candidate($id)["state"]);
    }

    public function testLaterImportedCorrectionUsesTheExistingIndependentApprovalPolicy(): void
    {
        $f = new MoodleGradeImportFixture();
        $row = $f->collect();
        $section = $f->offering->section->id;
        $id = new EntityId($row["id"]);
        $view = $f->service()->view($section, $id);
        $a = array_values(
            array_filter(
                $view["choices"]["items"],
                static fn(array $g): bool => $g["code"] === "A",
            ),
        )[0];
        $review = $f
            ->service()
            ->review(
                $section,
                $id,
                1,
                "accept",
                new EntityId($a["id"]),
                "Fictional initial grade.",
            );
        $f->service()->confirm($section, $id, $review["review_token"]);
        $f->sis->academicCredits()->saveGradeChangePolicy("approval", 0);
        $f->remote->grade["grade"] = "84.00000";
        $f->collect();
        $rows = array_values(
            array_filter(
                $f->records->page($section),
                static fn(array $r): bool => $r["state"] === "pending",
            ),
        );
        $next = new EntityId($rows[0]["id"]);
        $view = $f->service()->view($section, $next);
        $b = array_values(
            array_filter(
                $view["choices"]["items"],
                static fn(array $g): bool => $g["code"] === "B",
            ),
        )[0];
        $review = $f
            ->service()
            ->review(
                $section,
                $next,
                1,
                "accept",
                new EntityId($b["id"]),
                "Fictional correction for approval.",
            );
        self::assertTrue($review["grade_review"]["requires_approval"]);
        self::assertSame(
            "requested",
            $f->service()->confirm($section, $next, $review["review_token"])[
                "state"
            ],
        );
        $current = $f->sis
            ->academicCredits()
            ->choices($f->student, $section, $f->enrollment);
        self::assertSame("A", $current["current_grade_code"]);
        self::assertNotNull($current["pending_grade_change"]);
    }

    public function testHiddenTotalsSupersedePendingResultsWithoutExposingTheirValue(): void
    {
        $f = new MoodleGradeImportFixture();
        $old = $f->collect();
        $f->remote->grade = [
            "state" => "hidden",
            "item_id" => null,
            "grade" => null,
            "minimum" => null,
            "maximum" => null,
            "revision" => "",
        ];
        $f->collect();
        self::assertSame(
            "superseded",
            $f->records->candidate(new EntityId($old["id"]))["state"],
        );
        $rows = $f->service()->page($f->offering->section->id)["items"];
        $hidden = array_values(
            array_filter(
                $rows,
                static fn(array $r): bool => $r["state"] === "unavailable",
            ),
        )[0];
        self::assertNull($hidden["grade"]["grade"]);
        self::assertArrayNotHasKey("revision", $hidden["grade"]);
    }

    public function testMissingPersonLinkCreatesAnActionableLocalIssueWithoutAnyMoodleLookup(): void
    {
        $f = new MoodleGradeImportFixture();
        $f->sis->sql->execute("DELETE FROM sis_moodle_person_links");
        $row = $f->collect();
        self::assertSame("unavailable", $row["state"]);
        self::assertSame(0, $f->remote->calls);
        $display = $f
            ->service()
            ->view($f->offering->section->id, new EntityId($row["id"]));
        self::assertNull($display["grade"]);
        self::assertSame("source_unavailable", $display["issue"]);
    }

    public function testAuditFailureRollsBackTheGradeCandidateAndNotificationTogether(): void
    {
        $f = new MoodleGradeImportFixture();
        $row = $f->collect();
        $section = $f->offering->section->id;
        $id = new EntityId($row["id"]);
        $grade = new EntityId(
            $f->service()->view($section, $id)["choices"]["items"][0]["id"],
        );
        $review = $f
            ->service()
            ->review(
                $section,
                $id,
                1,
                "accept",
                $grade,
                "Fictional atomic grade decision.",
            );
        $before = EventStoreFixture::historyDigest($f->sis->sql);
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit
            ->method("record")
            ->willReturnCallback(static function (
                EntityId $actor,
                string $action,
            ): void {
                if ($action === "academic.grade_assigned") {
                    throw new \RuntimeException("Fictional audit rejection.");
                }
            });
        try {
            $f->service($audit)->confirm(
                $section,
                $id,
                $review["review_token"],
            );
            self::fail("An unaudited grade cannot commit.");
        } catch (\RuntimeException $failure) {
            self::assertSame(
                "Fictional audit rejection.",
                $failure->getMessage(),
            );
        }
        self::assertSame(
            $before,
            EventStoreFixture::historyDigest($f->sis->sql),
        );
        self::assertSame("pending", $f->records->candidate($id)["state"]);
        self::assertSame(
            "accepted",
            $f->service()->confirm($section, $id, $review["review_token"])[
                "state"
            ],
        );
    }

    public function testCancellationDuringANetworkReadPreventsItsResultFromBeingStored(): void
    {
        $f = new MoodleGradeImportFixture();
        $section = $f->offering->section->id;
        $p = $f->service()->page($section);
        $f->service()->request(
            $section,
            new EntityId($p["request_id"]),
            $p["revision"],
            "Fictional cancellable intake.",
        );
        $f->remote->duringCall = static function () use ($f, $section): void {
            $f->service()->cancel(
                $section,
                (int) $f->records->job($section)["revision"],
            );
        };
        $before = EventStoreFixture::historyDigest($f->sis->sql);
        $f->worker()->runOne();
        self::assertSame("cancelled", $f->records->job($section)["state"]);
        self::assertSame([], $f->records->page($section));
        self::assertSame(
            $before,
            EventStoreFixture::historyDigest($f->sis->sql),
        );
        self::assertCount(1, $f->service()->notices(true)["items"]);
    }

    public function testAWorkerReadFailureRetainsPrivateFailureStatusAndOneCompletionNotice(): void
    {
        $f = new MoodleGradeImportFixture();
        $f->remote->fail = true;
        $section = $f->offering->section->id;
        $p = $f->service()->page($section);
        $f->service()->request(
            $section,
            new EntityId($p["request_id"]),
            $p["revision"],
            "Fictional failed read.",
        );
        self::assertSame("review_required", $f->worker()->runOne());
        self::assertSame("failed", $f->records->job($section)["state"]);
        self::assertSame("idle", $f->worker()->runOne());
        self::assertCount(1, $f->service()->notices(true)["items"]);
        self::assertStringNotContainsString(
            "upstream",
            json_encode($f->records->job($section)),
        );
    }

    public function testDisabledModeMakesNoMoodleCallAndTheThreeStaffDutiesAreConjunctive(): void
    {
        $f = new MoodleGradeImportFixture();
        $f->policy->on = false;
        self::assertSame("disabled", $f->worker()->runOne());
        self::assertSame(0, $f->remote->calls);
        $f->sis->access->staff = false;
        $f->sis->access->grants = ["sis:moodle:manage", "sis:student:read"];
        $this->expectException(
            \Domain\Shared\Exception\AccessDeniedException::class,
        );
        $f->service()->page($f->offering->section->id);
    }

    public function testNotificationDeletionIsOwnerScopedAndRemovesOnlyTheNotice(): void
    {
        $f = new MoodleGradeImportFixture();
        $row = $f->collect();
        $owner = $f->sis->access->actor;
        $notice = $f->service()->notices(true)["items"][0];
        $f->sis->access->actor = EntityId::generate();
        self::assertSame([], $f->service()->notices(false)["items"]);
        try {
            $f->service()->notice(new EntityId($notice["id"]), 1, true);
            self::fail("Another account cannot delete a completion notice.");
        } catch (\Domain\Shared\Exception\RecordNotFoundException) {
            self::assertCount(
                1,
                $f->sis->sql->rows("sis_moodle_grade_notices"),
            );
        }
        $f->sis->access->actor = $owner;
        $f->service()->notice(new EntityId($notice["id"]), 1, true);
        self::assertNotNull($f->records->candidate(new EntityId($row["id"])));
        self::assertSame(
            "complete",
            $f->records->job($f->offering->section->id)["state"],
        );
    }

    public function testExpiredWorkerLeaseResumesFromDurableReferences(): void
    {
        $f = new MoodleGradeImportFixture();
        $section = $f->offering->section->id;
        $p = $f->service()->page($section);
        $f->service()->request(
            $section,
            new EntityId($p["request_id"]),
            $p["revision"],
            "Fictional interrupted worker.",
        );
        $job = $f->records->job($section);
        $f->records->saveJob($section, (int) $job["revision"], [
            "state" => "running",
            "lease_until" => $f->sis->clock->now()->getTimestamp() + 30,
        ]);
        self::assertSame("idle", $f->worker()->runOne());
        self::assertSame(0, $f->remote->calls);
        $f->sis->clock->time = $f->sis->clock->time->modify("+31 seconds");
        self::assertSame("grade_observed", $f->worker()->runOne());
        self::assertSame("intake_complete", $f->worker()->runOne());
        self::assertCount(1, $f->records->page($section));
        self::assertCount(1, $f->service()->notices(true)["items"]);
    }

    public function testPersonMappingChangesDuringAReadCannotCreateAReceivingGradeCandidate(): void
    {
        $f = new MoodleGradeImportFixture();
        $section = $f->offering->section->id;
        $p = $f->service()->page($section);
        $f->service()->request(
            $section,
            new EntityId($p["request_id"]),
            $p["revision"],
            "Fictional source fence.",
        );
        $f->remote->duringCall = static function () use ($f): void {
            $f->sis->sql->execute(
                "UPDATE sis_moodle_person_links SET moodle_user_id = ?",
                ["74"],
            );
        };
        self::assertSame("review_required", $f->worker()->runOne());
        self::assertSame([], $f->records->page($section));
        self::assertSame("source_changed", $f->records->job($section)["issue"]);
    }

    public function testReasonedDismissalIsRetainedAndItsOriginalRetryDoesNotPublishGrades(): void
    {
        $f = new MoodleGradeImportFixture();
        $row = $f->collect();
        $section = $f->offering->section->id;
        $id = new EntityId($row["id"]);
        $before = EventStoreFixture::historyDigest($f->sis->sql);
        $review = $f
            ->service()
            ->review(
                $section,
                $id,
                1,
                "dismiss",
                null,
                "Fictional rejected source evaluation.",
            );
        $result = $f
            ->service()
            ->confirm($section, $id, $review["review_token"]);
        $saved = json_decode(
            $f->sis->dropCipher->open(
                $f->records->candidate($id)["encrypted_result"],
            ),
            true,
        );
        self::assertSame(
            "Fictional rejected source evaluation.",
            $saved["reason"],
        );
        self::assertSame(
            $result,
            $f->service()->confirm($section, $id, $review["review_token"]),
        );
        self::assertSame(
            $before,
            EventStoreFixture::historyDigest($f->sis->sql),
        );
    }
}
