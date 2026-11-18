<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Admissions\Service\ApplicationFormService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlApplicationFormRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class ApplicationRevisionsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }
    private function fixture(): array
    {
        $f = new SisFixture();
        $person = $f->legacyPerson($f->access->actor, "Fictional", "Applicant");
        $forms = new ApplicationFormService(
            new SqlApplicationFormRepository($f->sql),
            $f->admissionsService(),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->submissionService(),
        );
        $form = $forms->save(
            null,
            $f->program,
            "Fictional retained form",
            [
                [
                    "key" => "statement",
                    "label" => "Statement",
                    "type" => "textarea",
                    "required" => true,
                ],
            ],
            null,
        );
        $forms->publish($form->id, 0);
        $submission = EntityId::generate();
        $id = $forms->submit($submission, $form->id, $person->id, $f->term, [
            "statement" => "Original fictional statement.",
        ]);
        return [$f, $id, $submission, $form->id];
    }
    private function request(SisFixture $f, EntityId $id): array
    {
        $s = $f->applicationRevisionService();
        return $s->commit(
            $id,
            $s->review(
                $id,
                "request",
                ["instructions" => "Please clarify your fictional statement."],
                false,
            )["review_token"],
            false,
        );
    }
    private function submit(
        SisFixture $f,
        EntityId $id,
        string $text = "Revised fictional statement.",
    ): array {
        $s = $f->applicationRevisionService();
        return $s->review(
            $id,
            "submit",
            [
                "answers" => ["statement" => $text],
                "reason" => "Fictional clarification.",
            ],
            true,
        );
    }
    public function testRequestedResubmissionKeepsOriginalAnswersAndOneApplicationAndAdvancesTheSameStream(): void
    {
        [$f, $id, $submission] = $this->fixture();
        $s = $f->applicationRevisionService();
        self::assertFalse($s->current($id, true)["can_revise"]);
        $original = $f->sql->rows("sis_form_submissions");
        $people = $f->sql->rows("sis_persons");
        $events = $f->sql->rows("sis_history_events");
        $this->request($f, $id);
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertTrue($s->current($id, true)["can_revise"]);
        $review = $this->submit($f, $id);
        $done = $s->commit($id, $review["review_token"], true);
        self::assertSame("submitted", $done["status"]);
        self::assertSame(
            "Revised fictional statement.",
            $s->current($id, true)["answers"]["statement"],
        );
        self::assertSame(
            "Original fictional statement.",
            $s->current($id, true, original: true)["answers"]["statement"],
        );
        self::assertSame($original, $f->sql->rows("sis_form_submissions"));
        self::assertSame($people, $f->sql->rows("sis_persons"));
        self::assertCount(1, $f->sql->rows("sis_applications"));
        self::assertSame([], $f->sql->rows("sis_students"));
        self::assertSame(
            $events,
            array_slice($f->sql->rows("sis_history_events"), 0, count($events)),
        );
        self::assertCount(
            count($events) + 1,
            $f->sql->rows("sis_history_events"),
        );
        self::assertSame(
            "resubmission",
            array_slice($f->decisionService()->history($id)["items"], -1)[0][
                "kind"
            ],
        );
        self::assertFalse($s->current($id, true)["can_revise"]);
        self::assertSame($done, $s->commit($id, $review["review_token"], true));
        self::assertStringNotContainsString(
            "Revised fictional statement.",
            json_encode($f->sql->rows("sis_application_revisions")),
        );
    }
    public function testInstitutionMayPermitChangesBeforeDecisionAndAnAcceptanceClosesEditing(): void
    {
        [$f, $id] = $this->fixture();
        $s = $f->applicationRevisionService();
        $f->admissionPolicyService()->save("answers", "before_decision", 0);
        $s->commit($id, $this->submit($f, $id)["review_token"], true);
        self::assertTrue($s->current($id, true)["can_revise"]);
        $d = $f->decisionService();
        $d->commit(
            $id,
            $d->review($id, true, "Fictional acceptance.")["review_token"],
        );
        self::assertFalse($s->current($id, true)["can_revise"]);
        $this->expectException(ValidationException::class);
        $this->submit($f, $id, "Later fictional statement.");
    }

    public function testPendingRequestsPauseDecisionsAndCancelledCyclesInvalidateEarlierDecisionReviews(): void
    {
        [$f, $id] = $this->fixture();
        $s = $f->applicationRevisionService();
        $d = $f->decisionService();
        $old = $d->review($id, true, "Fictional earlier review.");
        $this->request($f, $id);
        try {
            $d->commit($id, $old["review_token"]);
            self::fail("A pending correction request was ignored.");
        } catch (ValidationException) {
            self::assertSame(
                "submitted",
                $f->admissions->application($id)->status->value,
            );
        }
        $cancel = $s->review(
            $id,
            "cancel",
            ["reason" => "Fictional request no longer needed."],
            false,
        );
        $s->commit($id, $cancel["review_token"], false);
        $this->expectException(ConcurrentModificationException::class);
        $d->commit($id, $old["review_token"]);
    }
    public function testOwnerBoundariesIndependentStaffPermissionAndReviewChannelsAreEnforced(): void
    {
        [$f, $id] = $this->fixture();
        $s = $f->applicationRevisionService();
        $owner = $f->access->actor;
        $other = $f->user();
        $f->access->staff = false;
        $f->access->grants = ["sis:admissions:manage"];
        try {
            $this->request($f, $id);
            self::fail("Management alone granted amendment authority.");
        } catch (AccessDeniedException) {
            self::assertNull($f->applicationRevisions()->pending($id));
        }
        $f->access->staff = true;
        $this->request($f, $id);
        $review = $this->submit($f, $id);
        $f->access->actor = $other;
        foreach (["view", "review", "commit"] as $action) {
            try {
                match ($action) {
                    "view" => $s->current($id, true),
                    "review" => $this->submit($f, $id),
                    "commit" => $s->commit($id, $review["review_token"], true),
                };
                self::fail(
                    "Staff or superuser access bypassed an owner route.",
                );
            } catch (AccessDeniedException) {
                self::assertSame(
                    "submitted",
                    $f->admissions->application($id)->status->value,
                );
            }
        }
        $f->access->actor = $owner;
        $this->expectException(ValidationException::class);
        $s->commit($id, $review["review_token"], false);
    }
    public function testRequestAndResubmissionReceiptsRemainOriginalAfterLaterRequestsAndMatriculation(): void
    {
        [$f, $id, $submission, $form] = $this->fixture();
        $s = $f->applicationRevisionService();
        $r = $s->review(
            $id,
            "request",
            ["instructions" => "Fictional first correction."],
            false,
        );
        $request = $s->commit($id, $r["review_token"], false);
        $first = $this->submit($f, $id);
        $done = $s->commit($id, $first["review_token"], true);
        $this->request($f, $id);
        $later = $this->submit($f, $id, "Another fictional statement.");
        $s->commit($id, $later["review_token"], true);
        $d = $f->decisionService();
        $d->commit(
            $id,
            $d->review($id, true, "Fictional acceptance.")["review_token"],
        );
        $f->matriculate($id);
        $f->clock->time = $f->clock->now()->modify("+1 day");
        $events = $f->sql->rows("sis_history_events");
        self::assertSame($request, $s->commit($id, $r["review_token"], false));
        self::assertSame($done, $s->commit($id, $first["review_token"], true));
        self::assertSame(
            "Another fictional statement.",
            $s->current($id, true)["answers"]["statement"],
        );
        self::assertSame(
            "Revised fictional statement.",
            $s->current($id, true, new EntityId($done["revision_id"]))[
                "answers"
            ]["statement"],
        );
        $f->submissionService()->submit(
            $submission,
            $form,
            $f->admissions->application($id)->personId,
            $f->term,
            ["statement" => "Original fictional statement."],
        );
        self::assertSame($events, $f->sql->rows("sis_history_events"));
    }
    public function testExpiredReviewsPolicyChangesAndCompetingEditsCannotOverwriteAnswers(): void
    {
        foreach (["expiry", "policy", "competing"] as $change) {
            [$f, $id] = $this->fixture();
            $s = $f->applicationRevisionService();
            $this->request($f, $id);
            $old = $this->submit($f, $id);
            match ($change) {
                "expiry" => ($f->clock->time = $f->clock
                    ->now()
                    ->modify("+21 minutes")),
                "policy" => $f
                    ->admissionPolicyService()
                    ->save("answers", "before_decision", 0),
                "competing" => $s->commit(
                    $id,
                    $this->submit($f, $id, "Competing fictional answer.")[
                        "review_token"
                    ],
                    true,
                ),
            };
            $events = $f->sql->rows("sis_history_events");
            try {
                $s->commit($id, $old["review_token"], true);
                self::fail("A stale answer review committed.");
            } catch (ValidationException | ConcurrentModificationException) {
                self::assertSame($events, $f->sql->rows("sis_history_events"));
                self::assertSame(
                    $change === "competing"
                        ? "Competing fictional answer."
                        : "Original fictional statement.",
                    $s->current($id, true)["answers"]["statement"],
                );
            }
        }
    }
    public function testAuditAndFinalAuthorityFailuresRollBackAnswersEventRequestAndInboxTogether(): void
    {
        foreach (["audit", "authority"] as $failure) {
            [$f, $id] = $this->fixture();
            $this->request($f, $id);
            $audit = $this->createMock(
                \Domain\Audit\Repository\AuditTrail::class,
            );
            $owner = $f->access->actor;
            $audit
                ->expects(self::once())
                ->method("record")
                ->willReturnCallback(static function () use (
                    $f,
                    $failure,
                ): void {
                    if ($failure === "audit") {
                        throw new \RuntimeException(
                            "Fictional required audit failure.",
                        );
                    }
                    $f->access->actor = $f->user();
                });
            $s = $f->applicationRevisionService($audit);
            $review = $s->review(
                $id,
                "submit",
                [
                    "answers" => ["statement" => "Failed fictional answer."],
                    "reason" => "Fictional correction.",
                ],
                true,
            );
            $events = $f->sql->rows("sis_history_events");
            $rows = $f->sql->rows("sis_application_revisions");
            $inbox = $f->sql->rows("sis_admission_updates");
            try {
                $s->commit($id, $review["review_token"], true);
                self::fail("An unaudited or unauthorized change committed.");
            } catch (\RuntimeException | AccessDeniedException) {
                $f->access->actor = $owner;
                self::assertSame($events, $f->sql->rows("sis_history_events"));
                self::assertSame(
                    $rows,
                    $f->sql->rows("sis_application_revisions"),
                );
                self::assertSame(
                    $inbox,
                    $f->sql->rows("sis_admission_updates"),
                );
                self::assertSame(0, $f->admissions->application($id)->version);
            }
        }
    }
    public function testTamperedRetainedAnswerArtifactsPreventBothReadsAndLaterAdmissionsDecisions(): void
    {
        [$f, $id] = $this->fixture();
        $this->request($f, $id);
        $s = $f->applicationRevisionService();
        $done = $s->commit($id, $this->submit($f, $id)["review_token"], true);
        $r = $f->sql->one("sis_application_revisions", [
            "id" => $done["revision_id"],
        ]);
        $p = json_decode(
            $f->dropCipher->open($r["sealed_data"]),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $p["artifact"]["answers"]["statement"] = "Forged fictional statement.";
        $f->sql->update(
            "sis_application_revisions",
            [
                "sealed_data" => $f->dropCipher->seal(
                    json_encode($p, JSON_THROW_ON_ERROR),
                ),
            ],
            ["id" => $done["revision_id"]],
        );
        foreach (["read", "decide"] as $action) {
            try {
                if ($action === "read") {
                    $s->current($id, true);
                } else {
                    $f->decisionService()->review(
                        $id,
                        true,
                        "Fictional acceptance.",
                    );
                }
                self::fail(
                    "An artifact changed without its retained journal proof.",
                );
            } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
                self::assertSame(
                    "submitted",
                    $f->admissions->application($id)->status->value,
                );
            }
        }
    }
    public function testRequestCancellationReceiptsDoNotCloseAnotherLaterRequest(): void
    {
        [$f, $id] = $this->fixture();
        $s = $f->applicationRevisionService();
        $this->request($f, $id);
        $cancel = $s->review(
            $id,
            "cancel",
            ["reason" => "Fictional closure."],
            false,
        );
        $done = $s->commit($id, $cancel["review_token"], false);
        $next = $this->request($f, $id);
        self::assertSame(
            $done,
            $s->commit($id, $cancel["review_token"], false),
        );
        self::assertSame(
            $next["revision_id"],
            $f->applicationRevisions()->pending($id)["id"],
        );
        self::assertCount(1, $f->sql->rows("sis_history_events"));
    }
    public function testDecisionReviewShowsTheCurrentAnswersAndLaterResubmissionInvalidatesIt(): void
    {
        [$f, $id] = $this->fixture();
        $f->admissionPolicyService()->save("answers", "before_decision", 0);
        $s = $f->applicationRevisionService();
        $s->commit($id, $this->submit($f, $id)["review_token"], true);
        $d = $f->decisionService();
        $review = $d->review($id, true, "Fictional reviewed acceptance.");
        self::assertSame(
            "Revised fictional statement.",
            $review["answers_review"]["answers"]["statement"],
        );
        $s->commit(
            $id,
            $this->submit($f, $id, "Later fictional statement.")[
                "review_token"
            ],
            true,
        );
        $this->expectException(ConcurrentModificationException::class);
        $d->commit($id, $review["review_token"]);
    }

    public function testEarlierUntrackedAnswersStayReadableWithoutInventingSubmissionOrRevisionHistory(): void
    {
        [$f, , , $form] = $this->fixture();
        $person = $f->legacyPerson($f->user(), "Older", "Fictional");
        $old = $f->legacyApplication($person->id, $f->program, $f->term);
        $answers = ["statement" => "Older fictional answer."];
        $repo = new SqlApplicationFormRepository($f->sql);
        $repo->storeSubmission(
            EntityId::generate(),
            $form,
            $old->id,
            $f->access->actor,
            $f->dropCipher->seal(json_encode($answers, JSON_THROW_ON_ERROR)),
            \Domain\Admissions\ValueObject\SubmissionIntegrity::fingerprint(
                $form,
                $person->id,
                $f->term,
                $answers,
            ),
        );
        $events = $f->sql->rows("sis_history_events");
        $s = $f->applicationRevisionService();
        $view = $s->current($old->id, false);
        self::assertSame($answers, $view["answers"]);
        self::assertFalse($view["can_revise"]);
        self::assertNull($view["submitted_term_code"]);
        self::assertSame([], $view["versions"]);
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        $this->expectException(ValidationException::class);
        $this->request($f, $old->id);
    }
    public function testCorrectionsUseTheOriginalFormAfterDisablementAndRejectUnchangedAnswers(): void
    {
        [$f, $id] = $this->fixture();
        $s = $f->applicationRevisionService();
        $this->request($f, $id);
        $repo = new SqlApplicationFormRepository($f->sql);
        $active = $repo->active($f->program);
        $repo->disable($f->program, $active->id);
        try {
            $this->submit($f, $id, "Original fictional statement.");
            self::fail("Unchanged answers fulfilled a correction request.");
        } catch (ValidationException) {
            self::assertNotNull($f->applicationRevisions()->pending($id));
        }
        $s->commit($id, $this->submit($f, $id)["review_token"], true);
        self::assertSame(
            $active->id->value,
            $s->current($id, true)["form"]->id->value,
        );
    }

    public function testRerunningTheAdditiveMigrationPreservesAnswersSettingsEventsAndPermissionGrants(): void
    {
        [$f, $id] = $this->fixture();
        $f->admissionPolicyService()->save("answers", "before_decision", 0);
        $f->applicationRevisionService()->commit(
            $id,
            $this->submit($f, $id)["review_token"],
            true,
        );
        $tables = [
            "sis_admission_policy",
            "sis_form_submissions",
            "sis_application_revisions",
            "sis_history_events",
        ];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $f->sql->rows($table);
        }
        $m = new \CreateSisApplicationRevisions("20261117000009");
        $m->setObjectMap(
            new \ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $f->connection,
                    "test_migrations",
                ),
            ]),
        );
        $m->up();
        foreach ($tables as $table) {
            self::assertSame($before[$table], $f->sql->rows($table));
        }
    }
}
