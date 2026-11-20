<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\AcademicRecord\Service\AcademicCourseService;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class AwardedCourseCorrectionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/CatalogWorkflowFixture.php";
    }

    private function fixture(bool $withdrawn = false): array
    {
        $catalog = new \CatalogWorkflowFixture();
        $f = $catalog->sis;
        $f->gradeScale([
            [
                "code" => "A",
                "min_score_milli" => 0,
                "max_score_milli" => 100000,
                "points_milli" => 4000,
                "counts_gpa" => true,
                "passes" => true,
            ],
            [
                "code" => "W",
                "min_score_milli" => null,
                "max_score_milli" => null,
                "points_milli" => 0,
                "counts_gpa" => false,
                "passes" => false,
            ],
        ]);
        $student = $f->student($f->user())->id;
        $section = $f->section();
        $original = $f->publishCourse($section->courseId);
        $review = $catalog->bindings()->review($section->id, $original, "Fictional binding.");
        $catalog->bindings()->commit($section->id, $review["review_token"]);
        $publisher = $catalog->sectionPublications();
        $review = $publisher->review($section->id, "2026-08-01", "2026-08-31", "Fictional offering.");
        $offering = new EntityId($publisher->publish($section->id, $review["review_token"])["operation_id"]);
        $review = $publisher->reviewSelection($section->id, $offering, "Fictional initial selection.");
        $publisher->select($section->id, $review["review_token"]);
        $enrollment = $f->service->register($student, $section->id)->id;
        if ($withdrawn) {
            $f->clock->time = new \DateTimeImmutable("2026-09-02T12:00:00-07:00");
            $f->publishDropPolicy(
                $f->term,
                $f->dropPolicies->show($f->term)["version"],
                new \Domain\Registration\ValueObject\DropWindows([["through" => null, "grade_code" => "W"]]),
            );
            $f->drops->drop($student, $section->id);
        } else {
            $grade = new EntityId(
                $f->academicCredits()->choices($student, $section->id, $enrollment)["items"][0]["id"],
            );
            $review = $f->academicCredits()->review($student, $section->id, $enrollment, $grade, 0);
            $f->academicCredits()->commit($student, $section->id, $review["review_token"]);
        }
        $credit = new EntityId(
            $f->sql->one("sis_academic_credits", ["source_id" => $enrollment->value])["id"],
        );
        $targetSection = $f->section();
        $target = $targetSection->courseId;
        $version = $f->publishCourse($target);
        return [
            $f,
            $student,
            $section,
            $enrollment,
            $credit,
            $target,
            $version,
            $original,
            $catalog,
            $targetSection,
        ];
    }

    private function review(array $fixture): array
    {
        [$f, $student, $section, $enrollment, $credit, $target, $version] = $fixture;
        return $f
            ->academicCourses()
            ->review(
                $student,
                $section->id,
                $enrollment,
                $version,
                $f->academicCredits()->auditFacts($credit, $student)["credit"]["version"],
                "2026-08-10",
                "Fictional verified awarded-course correction.",
                $target,
            );
    }

    private function snapshot(SisFixture $f): array
    {
        $tables = [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_academic_credits",
            "sis_credit_course_versions",
            "sis_course_versions",
            "sis_audit",
        ];
        $result = [];
        foreach ($tables as $table) {
            $result[$table] = $f->sql->rows($table);
        }
        return $result;
    }

    public function testCorrectionPreservesRegistrationOfferingGradingPursuitAndMoney(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, $enrollment, $credit, $target, $version, $original] = $fixture;
        $before = $f->academicCredits()->auditFacts($credit, $student);
        $preserved = [
            $f->financialEvents(),
            $f->sql->rows("sis_enrollments"),
            $f->sql->rows("sis_student_programs"),
        ];
        $all = $this->snapshot($f);
        $review = $this->review($fixture);
        self::assertSame($all, $this->snapshot($f));
        self::assertTrue($review["course_correction"]);
        self::assertFalse($review["earlier_reference_unknown"]);
        self::assertTrue($f->prerequisitesMet($student, $target, [$section->courseId->value]));
        self::assertFalse($f->prerequisitesMet($student, $target, [$target->value]));
        $receipt = $f->academicCourses()->commit($student, $section->id, $review["review_token"]);
        self::assertFalse($f->prerequisitesMet($student, $target, [$section->courseId->value]));
        self::assertTrue($f->prerequisitesMet($student, $target, [$target->value]));
        $after = $f->academicCredits()->auditFacts($credit, $student);
        self::assertSame($target->value, $after["credit"]["course_id"]);
        self::assertSame($version->value, $after["credit"]["course_version_id"]);
        self::assertSame($section->courseId->value, $after["registration_course_id"]);
        self::assertSame($original->value, $after["registration_course_version_id"]);
        foreach (["grade", "section_version_id", "completed_on", "academic_on"] as $key) {
            self::assertSame($before[$key], $after[$key]);
        }
        $protectedFields = [
                "grade_id",
                "attempted_milli",
                "completed_milli",
                "grade_points_milli",
                "term_id",
                "source_id",
                "status",
        ];
        foreach ($protectedFields as $key) {
            self::assertSame($before["credit"][$key], $after["credit"][$key]);
        }
        self::assertSame($preserved, [
            $f->financialEvents(),
            $f->sql->rows("sis_enrollments"),
            $f->sql->rows("sis_student_programs"),
        ]);
        $inputs = $f->inputVerifier()->verify($student, new HistoryReadBudget());
        self::assertSame($target->value, $inputs->items[0]["course_id"]);
        self::assertSame(
            $receipt,
            $f->academicCourses()->commit($student, $section->id, $review["review_token"]),
        );
        self::assertFalse(
            $f->academicCredits()->choices($student, $section->id, $enrollment)["can_accept_course"],
        );
    }

    public function testSameCoursePublicationCorrectionAndLaterGradeAndPostingReviewsKeepTheOriginalOffering(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, $enrollment, $credit, $target, $version, $original] = $fixture;
        $f->sql->update(
            "sis_courses",
            ["title" => "Revised fictional title"],
            ["id" => $section->courseId->value],
        );
        $fixture[5] = $section->courseId;
        $fixture[6] = $f->publishCourse($section->courseId);
        $review = $this->review($fixture);
        $f->academicCourses()->commit($student, $section->id, $review["review_token"]);
        $facts = $f->academicCredits()->auditFacts($credit, $student);
        self::assertSame("Revised fictional title", $facts["credit"]["course_title"]);
        self::assertSame($original->value, $facts["registration_course_version_id"]);
        $grade = $f
            ->academicCredits()
            ->review(
                $student,
                $section->id,
                $enrollment,
                new EntityId($facts["credit"]["grade_id"]),
                2,
                reason: "Fictional later grade evidence.",
            );
        $f->academicCredits()->commit($student, $section->id, $grade["review_token"]);
        $term = EntityId::generate();
        $f->sql->insert(
            "sis_terms",
            array_replace($f->sql->one("sis_terms", ["id" => $f->term->value]), [
                "id" => $term->value,
                "code" => "26/POST",
            ]),
        );
        $review = $f
            ->academicDates()
            ->reviewDetails(
                $student,
                $section->id,
                $enrollment,
                null,
                "2026-08-09",
                $term,
                3,
                "Fictional placement and completion evidence.",
                "2026-08-10",
            );
        $f->academicDates()->commitDetails($student, $section->id, $review["review_token"]);
        self::assertSame(
            $term->value,
            $f->academicCredits()->auditFacts($credit, $student)["credit"]["term_id"],
        );
    }

    public function testSeparateCorrectionDutyAndClosedRecordDutyAreRequired(): void
    {
        $cases = [false, true];
        foreach ($cases as $withdrawn) {
            $fixture = $this->fixture($withdrawn);
            [$f, $student, $section] = $fixture;
            $review = $this->review($fixture);
            $before = $this->snapshot($f);
            $f->access->staff = false;
            $f->access->grants = ["sis:student:read", "sis:gradebook:amend", "sis:gradebook:closed"];
            try {
                $f->academicCourses()->commit($student, $section->id, $review["review_token"]);
                self::fail("Grade authority corrected an awarded course.");
            } catch (AccessDeniedException) {
                self::assertSame($before, $this->snapshot($f));
            }
            $f->access->grants[] = AcademicCourseService::CORRECTION_PERMISSION;
            if ($withdrawn) {
                $f->access->grants = array_values(array_diff($f->access->grants, ["sis:gradebook:closed"]));
                try {
                    $f->academicCourses()->commit($student, $section->id, $review["review_token"]);
                    self::fail("Closed-record authority was bypassed.");
                } catch (AccessDeniedException) {
                    self::assertSame($before, $this->snapshot($f));
                }
                $f->access->grants[] = "sis:gradebook:closed";
            }
            $f->academicCourses()->commit($student, $section->id, $review["review_token"]);
        }
    }

    public function testWithdrawnCorrectionPreservesZeroEarnedCreditAndItsCharge(): void
    {
        $fixture = $this->fixture(true);
        [$f, $student, $section, $enrollment, $credit, $target] = $fixture;
        $money = $f->financialEvents();
        $review = $this->review($fixture);
        $f->academicCourses()->commit($student, $section->id, $review["review_token"]);
        $facts = $f->academicCredits()->auditFacts($credit, $student);
        self::assertSame("W", $facts["credit"]["status"]);
        self::assertSame(0, $facts["credit"]["completed_milli"]);
        self::assertNull($facts["completed_on"]);
        self::assertSame($money, $f->financialEvents());
    }

    public function testFailedAuditAuthorityLossAndExpiryRollBackCourseBindingAndAllEvents(): void
    {
        foreach (["audit", "authority", "expiry"] as $failure) {
            $fixture = $this->fixture();
            [$f, $student, $section] = $fixture;
            $review = $this->review($fixture);
            $before = $this->snapshot($f);
            $audit = new class ($f, $failure) implements \Domain\Audit\Repository\AuditTrail {
                public function __construct(private SisFixture $f, private string $failure)
                {
                }
                public function record(EntityId $actor, string $action, EntityId $subject): void
                {
                    if ($this->failure === "audit") {
                        throw new \RuntimeException("Fictional audit failure.");
                    }
                    if ($this->failure === "expiry") {
                        $this->f->clock->time = $this->f->clock->time->modify("+21 minutes");
                    } else {
                        $this->f->access->staff = false;
                        $this->f->access->grants = ["sis:student:read", "sis:gradebook:amend"];
                    }
                }
                public function forSubject(EntityId $subject): array
                {
                    return [];
                }
            };
            try {
                $f->academicCourses($audit)->commit($student, $section->id, $review["review_token"]);
                self::fail("An interrupted correction committed.");
            } catch (\RuntimeException | AccessDeniedException | ConcurrentModificationException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
    }

    public function testCourseSearchRequiresAQueryAndUsesBoundedIdentityResults(): void
    {
        [$f, $student, $section, $enrollment] = $this->fixture();
        $before = $this->snapshot($f);
        self::assertSame([], $f->academicCourses()->search($student, $section->id, $enrollment, "")["items"]);
        $result = $f->academicCourses()->search($student, $section->id, $enrollment, "CS");
        self::assertNotEmpty($result["items"]);
        self::assertLessThanOrEqual(20, count($result["items"]));
        self::assertSame($before, $this->snapshot($f));
    }
    public function testStaleExpiredForeignAndWrongPublicationReviewsCannotChangeAnAward(): void
    {
        foreach (["stale", "expired", "student", "actor", "publication"] as $failure) {
            $fixture = $this->fixture();
            [$f, $student, $section, $enrollment, $credit, $target, $version] = $fixture;
            $review = $this->review($fixture);
            if ($failure === "stale") {
                $date = $f
                    ->academicDates()
                    ->review(
                        $student,
                        $section->id,
                        $enrollment,
                        "2026-08-09",
                        1,
                        "Fictional source evidence.",
                    );
                $f->academicDates()->commit($student, $section->id, $date["review_token"]);
            }
            if ($failure === "expired") {
                $f->clock->time = $f->clock->time->modify("+21 minutes");
            }
            if ($failure === "actor") {
                $f->access->actor = $f->user();
            }
            $before = $this->snapshot($f);
            try {
                if ($failure === "publication") {
                    $f->academicCourses()->review(
                        $student,
                        $section->id,
                        $enrollment,
                        $version,
                        1,
                        "2026-08-10",
                        "Fictional incorrect course ownership.",
                        $section->courseId,
                    );
                } else {
                    $f->academicCourses()->commit(
                        $failure === "student" ? EntityId::generate() : $student,
                        $section->id,
                        $review["review_token"],
                    );
                }
                self::fail("An invalid review changed the academic course.");
            } catch (
                AccessDeniedException | ConcurrentModificationException | ValidationException |
                \Domain\Shared\Exception\RecordNotFoundException
            ) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
    }

    public function testOriginalRetryDoesNotUndoLaterCourseDecisionsOrOrdinaryCatalogEdits(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, $enrollment, $credit, $target, $version, $original] = $fixture;
        $first = $this->review($fixture);
        $receipt = $f->academicCourses()->commit($student, $section->id, $first["review_token"]);
        $f->sql->update(
            "sis_courses",
            ["title" => "Current unsaved-to-history title"],
            ["id" => $target->value],
        );
        self::assertNotSame(
            "Current unsaved-to-history title",
            $f->academicCredits()->auditFacts($credit, $student)["credit"]["course_title"],
        );
        $fixture[5] = $section->courseId;
        $fixture[6] = $original;
        $back = $this->review($fixture);
        $f->academicCourses()->commit($student, $section->id, $back["review_token"]);
        $f->clock->time = $f->clock->time->modify("+1 day");
        $before = $this->snapshot($f);
        self::assertSame(
            $receipt,
            $f->academicCourses()->commit($student, $section->id, $first["review_token"]),
        );
        self::assertSame($before, $this->snapshot($f));
        self::assertSame(
            $section->courseId->value,
            $f->academicCredits()->auditFacts($credit, $student)["credit"]["course_id"],
        );
    }

    public function testPendingGradeRequestBlocksCourseSearchChoicesAndCorrection(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, $enrollment, $credit, $target, $version] = $fixture;
        $facts = $f->academicCredits()->auditFacts($credit, $student)["credit"];
        $f->academicCredits()->saveGradeChangePolicy("approval", 0);
        $grade = $f
            ->academicCredits()
            ->review(
                $student,
                $section->id,
                $enrollment,
                new EntityId($facts["grade_id"]),
                1,
                reason: "Fictional pending grading request.",
            );
        $f->academicCredits()->commit($student, $section->id, $grade["review_token"]);
        self::assertFalse(
            $f->academicCredits()->choices($student, $section->id, $enrollment)["can_correct_course"],
        );
        $before = $this->snapshot($f);
        $actions = [
            fn() => $this->review($fixture),
            fn() => $f->academicCourses()->search($student, $section->id, $enrollment, "CS"),
            fn() => $f->academicCourses()->choices($student, $section->id, $enrollment, course: $target),
        ];
        foreach ($actions as $action) {
            try {
                $action();
                self::fail("A pending grade request permitted academic course changes.");
            } catch (ValidationException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
    }

    public function testRepeatOrderingUsesOriginalOfferingAfterCourseAndPostingTermCorrections(): void
    {
        $fixture = $this->fixture();
        [
            $f,
            $student,
            $section,
            $enrollment,
            $credit,
            $target,
            $version,
            $original,
            $catalog,
            $targetSection,
        ] = $fixture;
        $review = $this->review($fixture);
        $f->academicCourses()->commit($student, $section->id, $review["review_token"]);
        $other = $f->service->register($student, $targetSection->id)->id;
        $grade = $f->academicCredits()->auditFacts($credit, $student)["credit"]["grade_id"];
        $review = $f
            ->academicCredits()
            ->review($student, $targetSection->id, $other, new EntityId($grade), 0);
        $f->academicCredits()->commit($student, $targetSection->id, $review["review_token"]);
        $term = EntityId::generate();
        $f->sql->insert(
            "sis_terms",
            array_replace($f->sql->one("sis_terms", ["id" => $f->term->value]), [
                "id" => $term->value,
                "code" => "27/POST",
            ]),
        );
        $review = $f
            ->academicDates()
            ->reviewDetails(
                $student,
                $section->id,
                $enrollment,
                null,
                null,
                $term,
                2,
                "Fictional placement correction.",
                "2026-08-10",
            );
        $f->academicDates()->commitDetails($student, $section->id, $review["review_token"]);
        $inputs = $f->inputVerifier()->verify($student, new HistoryReadBudget());
        $scale = $f->academicCredits()->auditFacts($credit, $student)["grade"]["scale_version_id"];
        $policy = new \Domain\DegreeAudit\ValueObject\AuditCalculationPolicy(
            gpa: \Domain\DegreeAudit\ValueObject\RepeatGpaPolicy::LatestEligible,
            gradeScaleVersion: new EntityId($scale),
            attemptOrder: \Domain\DegreeAudit\ValueObject\RepeatAttemptOrder::AcademicPeriod,
        );
        $reader = new \Domain\DegreeAudit\Service\RetainedAttemptReader(
            new \Domain\Registration\Service\RetainedSectionReader(
                \EventStoreFixture::history($f->connection->queryBuilder()),
            ),
        );
        $attempts = $reader->forPolicy($inputs, $policy, new HistoryReadBudget());
        $selected = array_values(array_filter($attempts, fn($a) => $a->id->value === $credit->value))[0];
        self::assertSame("2026-08-01", $selected->academicOn);
        self::assertSame($target->value, $selected->courseId->value);
        $items = $inputs->items;
        foreach ($items as &$item) {
            if ($item["id"] === $credit->value) {
                $item["registration_course_id"] = EntityId::generate()->value;
            }
        }
        unset($item);
        $this->expectException(CorruptHistoryException::class);
        $reader->forPolicy(
            new \Domain\DegreeAudit\ValueObject\AcademicInputSet(
                $inputs->status,
                $items,
                $inputs->references,
            ),
            $policy,
            new HistoryReadBudget(),
        );
    }

    public function testPreparedTranscriptBecomesStaleWhileItsProtectedOriginalRemainsUnchanged(): void
    {
        require_once dirname(__DIR__) . "/Support/TranscriptFixture.php";
        $t = new \TranscriptFixture();
        $request = $t->request();
        $t->postGrade($request);
        $f = $t->sis;
        $student = new EntityId($t->repository->request($request)["student_id"]);
        $enrollment = $f->sql->rows("sis_enrollments", ["student_id" => $student->value])[0];
        $renderer = $this->createStub(\Domain\Transcript\Service\TranscriptDocumentRenderer::class);
        $renderer->method("html")->willReturn("<p>Fictional protected transcript</p>");
        $renderer->method("pdf")->willReturn("%PDF-1.4 Fictional original");
        $renderer->method("html")->willReturn("Fictional transcript");
        $service = $t->service($renderer);
        $prepared = $service->review($request);
        $document = new EntityId($prepared["operation_id"]);
        $service->prepare($request, $document, $prepared["revision"], true, $prepared["review_token"]);
        $bytes = $service->pdf($request, $document);
        $target = $f->section()->courseId;
        $f->sql->update("sis_courses", ["title" => "Fictional corrected award"], ["id" => $target->value]);
        $version = $f->publishCourse($target);
        $credit = $f->sql->one("sis_academic_credits", ["source_id" => $enrollment["id"]]);
        $section = new EntityId($enrollment["section_id"]);
        $review = $f
            ->academicCourses()
            ->review(
                $student,
                $section,
                new EntityId($enrollment["id"]),
                $version,
                $credit["version"],
                "2026-08-10",
                "Fictional transcript correction evidence.",
                $target,
            );
        $f->academicCourses()->commit($student, $section, $review["review_token"]);
        self::assertTrue($service->view($request, $document)["academic_changed"]);
        self::assertSame($bytes, $service->pdf($request, $document));
        self::assertSame(
            "Fictional corrected award",
            $service->review($request)["snapshot"]["credits"][0]["course_title"],
        );
    }

    public function testAnAwardedCourseCannotFallBackToUnknownReferenceAuthorityAfterCorrection(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, $enrollment, $credit, $target, $version] = $fixture;
        $review = $this->review($fixture);
        $f->academicCourses()->commit($student, $section->id, $review["review_token"]);
        $another = $f->publishCourse($target);
        $f->access->staff = false;
        $f->access->grants = ["sis:gradebook:amend", "sis:student:read"];
        $before = $this->snapshot($f);
        try {
            $f->academicCourses()->review(
                $student,
                $section->id,
                $enrollment,
                $another,
                2,
                "2026-08-10",
                "Fictional attempted authority bypass.",
            );
            self::fail("Unknown-reference authority replaced an accepted correction.");
        } catch (ValidationException) {
            self::assertSame($before, $this->snapshot($f));
        }
    }

    public function testChangedCourseProjectionIsRejectedInsteadOfBecomingANewBaseline(): void
    {
        $fixture = $this->fixture();
        [$f, $student, $section, $enrollment, $credit, $target] = $fixture;
        $review = $this->review($fixture);
        $f->academicCourses()->commit($student, $section->id, $review["review_token"]);
        $f->sql->update(
            "sis_academic_credits",
            ["course_id" => $section->courseId->value],
            ["id" => $credit->value],
        );
        $this->expectException(CorruptHistoryException::class);
        $f->academicCredits()->auditFacts($credit, $student);
    }

    public function testPermissionMigrationIsRetrySafeAndChangesNoExistingRoleOrUserGrants(): void
    {
        [$f] = $this->fixture();
        $tables = [
            "sis_access_role_permissions",
            "sis_access_user_permissions",
            "sis_access_user_roles",
            "sis_history_events",
        ];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $f->sql->rows($table);
        }
        $migration = new \AddSisAwardedCourseCorrectionPermission("retry");
        $migration->setObjectMap(
            new \ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $f->connection,
                    "migration",
                ),
            ]),
        );
        $migration->up();
        $migration->up();
        $permissions = $f->sql->rows("sis_access_permissions", [
            "name" => AcademicCourseService::CORRECTION_PERMISSION,
        ]);
        self::assertCount(1, $permissions);
        self::assertSame(1, (int) $permissions[0]["is_system"]);
        foreach ($tables as $table) {
            self::assertSame($before[$table], $f->sql->rows($table));
        }
        $f->sql->update("sis_access_permissions", ["is_system" => 0], ["id" => $permissions[0]["id"]]);
        $before = $f->sql->rows("sis_access_permissions");
        try {
            $migration->up();
            self::fail("An existing custom permission was overwritten.");
        } catch (\LogicException) {
            self::assertSame($before, $f->sql->rows("sis_access_permissions"));
        }
    }
}
