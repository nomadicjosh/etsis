<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Admissions\AdmissionCasework;
use Domain\Admissions\ValueObject\AdmissionDecisionId;
use Domain\Admissions\ValueObject\AdmissionChecklistProof;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAdmissionCaseworkRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class AdmissionReadinessTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }
    private function fixture(): array
    {
        $f = new SisFixture();
        $p = $f->legacyPerson($f->access->actor, "Fictional", "Applicant");
        $id = EntityId::generate();
        $f->submissionService()->apply($id, $p->id, $f->program, $f->term);
        return [$f, $id];
    }
    private function item(
        string $status = "pending",
        bool $visible = true,
        bool $needed = true,
    ): array {
        return [
            "key" => "transcript",
            "label" => $visible
                ? "Fictional transcript"
                : "Private fictional staff review",
            "instructions" => "Fictional instructions.",
            "needed" => $needed,
            "visible" => $visible,
            "status" => $status,
            "due_on" => "",
        ];
    }
    private function save(
        SisFixture $f,
        EntityId $id,
        array $items = [],
        string $state = "open",
        string $notes = "",
    ): void {
        $repo = new SqlAdmissionCaseworkRepository($f->sql, $f->dropCipher);
        $before = $repo->find($id)["case"] ?? null;
        $version = $before?->version ?? 0;
        $case = AdmissionCasework::fromInput(
            $id,
            [
                "items" => $items,
                "work_status" => $state,
                "private_notes" => $notes,
            ],
            null,
            $version + 1,
        );
        $f->transactions->run(function () use (
            $f,
            $id,
            $repo,
            $case,
            $version,
        ): void {
            $f->admissions->lockPerson(
                $f->admissions->application($id)->personId,
            );
            $f->admissions->lockApplication($id);
            $repo->save(
                $case,
                $version,
                $f->access->actor,
                $f->clock->now()->format(DATE_ATOM),
                str_repeat("a", 64),
            );
        });
    }
    private function decide(
        SisFixture $f,
        EntityId $id,
        bool $accept = true,
    ): array {
        $d = $f->decisionService();
        return $d->commit(
            $id,
            $d->review($id, $accept, "Fictional admissions review.")[
                "review_token"
            ],
        );
    }
    private function events(SisFixture $f, EntityId $id): array
    {
        return iterator_to_array(
            \EventStoreFixture::framework(
                $f->connection->queryBuilder(),
            )->getAggregateHistoryFor(new AdmissionDecisionId($id->value)),
        );
    }
    public function testAdvisoryDefaultRecordsItsActualChecklistAndLaterEditsDoNotChangeTheDecisionOrReceipt(): void
    {
        [$f, $id] = $this->fixture();
        $this->save($f, $id, [$this->item()]);
        self::assertSame(
            ["required" => false, "revision" => 0],
            $f->admissionPolicy()->checklist(),
        );
        $d = $f->decisionService();
        $review = $d->review($id, true, "Fictional advisory review.");
        self::assertFalse($review["checklist"]["ready"]);
        $result = $d->commit($id, $review["review_token"]);
        $events = $this->events($f, $id);
        $proof = $events[1]->metaParam("admission_checklist");
        self::assertFalse($proof["required"]);
        self::assertSame("pending", $proof["items"][0]["status"]);
        $before = $f->sql->rows("sis_history_events");
        $this->save(
            $f,
            $id,
            [$this->item("complete")],
            "done",
            "Changed fictional private notes.",
        );
        $f->admissionPolicyService()->save("checklist", true, 0);
        $f->clock->time = $f->clock->now()->modify("+1 day");
        self::assertSame($result, $d->commit($id, $review["review_token"]));
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        $summary = array_slice($d->history($id)["items"], -1)[0]["checklist"];
        self::assertSame(
            [
                "required" => false,
                "ready" => false,
                "needed" => 1,
                "complete" => 0,
                "not_needed" => 0,
            ],
            $summary,
        );
        self::assertStringNotContainsString(
            "Fictional transcript",
            json_encode($summary),
        );
    }
    public function testRequiredPolicyBlocksPendingAndReceivedItemsButAllowsRejection(): void
    {
        foreach (["pending", "received"] as $state) {
            [$f, $id] = $this->fixture();
            $this->save($f, $id, [$this->item($state)]);
            $f->admissionPolicyService()->save("checklist", true, 0);
            $before = $f->sql->rows("sis_history_events");
            try {
                $f->decisionService()->review(
                    $id,
                    true,
                    "Fictional acceptance.",
                );
                self::fail("Needed items were ignored.");
            } catch (ValidationException) {
                self::assertSame($before, $f->sql->rows("sis_history_events"));
            }
            self::assertSame(
                "rejected",
                $this->decide($f, $id, false)["status"],
            );
            self::assertFalse(
                AdmissionChecklistProof::ready(
                    $this->events($f, $id)[1]->metaParam("admission_checklist"),
                ),
            );
        }
    }
    public function testCompleteAndNotNeededItemsFulfilReadinessWhileOptionalItemsStayIndependent(): void
    {
        foreach (["complete", "not_needed"] as $state) {
            [$f, $id] = $this->fixture();
            $optional = $this->item("pending", needed: false);
            $optional["key"] = "optional";
            $this->save($f, $id, [$this->item($state), $optional]);
            $f->admissionPolicyService()->save("checklist", true, 0);
            self::assertSame("accepted", $this->decide($f, $id)["status"]);
            $proof = $this->events($f, $id)[1]->metaParam(
                "admission_checklist",
            );
            self::assertTrue(AdmissionChecklistProof::ready($proof));
            self::assertCount(1, $proof["items"]);
        }
    }
    public function testAnEmptyOrMissingChecklistRequiresExplicitSavedDoneReviewWhenEnforced(): void
    {
        [$f, $id] = $this->fixture();
        $f->admissionPolicyService()->save("checklist", true, 0);
        foreach (["missing", "open", "done"] as $state) {
            if ($state !== "missing") {
                $this->save($f, $id, [], $state);
            }
            if ($state === "done") {
                self::assertSame("accepted", $this->decide($f, $id)["status"]);
                continue;
            }
            try {
                $this->decide($f, $id);
                self::fail("Missing case review silently counted as complete.");
            } catch (ValidationException) {
                self::assertSame(
                    "submitted",
                    $f->admissions->application($id)->status->value,
                );
            }
        }
        self::assertSame(
            [],
            $this->events($f, $id)[1]->metaParam("admission_checklist")[
                "items"
            ],
        );
    }
    public function testPolicyCyclesAndAnySavedCaseRevisionInvalidateEarlierUnconfirmedReviews(): void
    {
        foreach (["case", "policy"] as $change) {
            [$f, $id] = $this->fixture();
            $this->save($f, $id, [$this->item("complete")]);
            $d = $f->decisionService();
            $review = $d->review($id, true, "Fictional earlier review.");
            if ($change === "case") {
                $this->save(
                    $f,
                    $id,
                    [$this->item("complete")],
                    notes: "Fictional new staff note.",
                );
            } else {
                $p = $f->admissionPolicyService();
                $p->save("checklist", true, 0);
                $p->save("checklist", false, 1);
            }
            $before = $f->sql->rows("sis_history_events");
            try {
                $d->commit($id, $review["review_token"]);
                self::fail("A stale checklist review was accepted.");
            } catch (ConcurrentModificationException) {
                self::assertSame($before, $f->sql->rows("sis_history_events"));
            }
        }
    }
    public function testCorrectionsToAcceptedUseCurrentReadinessWithoutRewritingEarlierOutcomes(): void
    {
        [$f, $id] = $this->fixture();
        $this->decide($f, $id, false);
        $f->admissionPolicyService()->save("checklist", true, 0);
        $this->save($f, $id, [$this->item()]);
        $c = $f->correctionService();
        try {
            $c->review($id, true, "Fictional corrected acceptance.");
            self::fail("A correction bypassed current requirements.");
        } catch (ValidationException) {
            self::assertSame(
                "rejected",
                $f->admissions->application($id)->status->value,
            );
        }
        $this->save($f, $id, [$this->item("complete")]);
        $before = $f->sql->rows("sis_history_events");
        $c->commit(
            $id,
            $c->review($id, true, "Fictional corrected acceptance.")[
                "review_token"
            ],
        );
        self::assertSame(
            $before,
            array_slice($f->sql->rows("sis_history_events"), 0, count($before)),
        );
        self::assertTrue(
            $this->events($f, $id)[2]->metaParam("admission_checklist")[
                "required"
            ],
        );
    }
    public function testLaterChecklistRequirementsDoNotRevokeAnEarlierAcceptanceOrMatriculation(): void
    {
        [$f, $id] = $this->fixture();
        $this->decide($f, $id);
        $f->admissionPolicyService()->save("checklist", true, 0);
        self::assertSame(
            $f->admissions->application($id)->personId->value,
            $f->matriculate($id)->personId->value,
        );
    }
    public function testAuditAndAuthorityFailuresRollBackTheDecisionAndFrozenProofTogether(): void
    {
        foreach (["audit", "authority"] as $failure) {
            [$f, $id] = $this->fixture();
            $this->save($f, $id, [$this->item("complete")]);
            $audit = $this->createMock(
                \Domain\Audit\Repository\AuditTrail::class,
            );
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
                    $f->access->staff = false;
                });
            $d = $f->decisionService($audit);
            $review = $d->review($id, true, "Fictional reviewed decision.");
            $before = $f->sql->rows("sis_history_events");
            try {
                $d->commit($id, $review["review_token"]);
                self::fail(
                    "An unaudited or unauthorized acceptance committed.",
                );
            } catch (\RuntimeException | AccessDeniedException) {
                self::assertSame($before, $f->sql->rows("sis_history_events"));
                self::assertSame(
                    "submitted",
                    $f->admissions->application($id)->status->value,
                );
            }
        }
    }
    public function testApplicantGuidanceNeverContainsHiddenChecklistDetailsOrCounts(): void
    {
        $case = AdmissionCasework::fromInput(
            EntityId::generate(),
            [
                "items" => [$this->item(visible: false)],
                "private_notes" => "Private fictional investigation.",
            ],
            null,
            1,
        );
        $proof = AdmissionChecklistProof::capture($case, [
            "required" => true,
            "revision" => 4,
        ]);
        self::assertSame(
            [
                "required" => true,
                "message" =>
                    "Admissions is reviewing your application requirements.",
            ],
            AdmissionChecklistProof::applicant($proof),
        );
        self::assertSame(
            ["Private fictional staff review"],
            AdmissionChecklistProof::staff($proof)["pending"],
        );
    }

    public function testCompletedEarlierReviewFormatKeepsItsReceiptWhileUnconfirmedOlderReviewsNeedRefresh(): void
    {
        foreach ([false, true] as $completed) {
            [$f, $id] = $this->fixture();
            $d = $f->decisionService();
            $review = $d->review($id, true, "Fictional accepted review.");
            $result = $completed
            ? $d->commit($id, $review["review_token"])
            : null;
            $older = json_decode(
                $f->dropCipher->open($review["review_token"]),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            unset($older["checklist"]);
            $token = $f->dropCipher->seal(
                json_encode($older, JSON_THROW_ON_ERROR),
            );
            $events = $f->sql->rows("sis_history_events");
            if ($completed) {
                self::assertSame($result, $d->commit($id, $token));
            } else {
                try {
                    $d->commit($id, $token);
                    self::fail(
                        "An unconfirmed earlier review bypassed checklist capture.",
                    );
                } catch (ConcurrentModificationException) {
                    self::assertSame(
                        "submitted",
                        $f->admissions->application($id)->status->value,
                    );
                }
            }
            self::assertSame($events, $f->sql->rows("sis_history_events"));
        }
    }
    public function testRerunningAdditiveSetupKeepsTheCurrentPolicyAndAllRetainedFacts(): void
    {
        [$f, $id] = $this->fixture();
        $this->save($f, $id, [$this->item("complete")]);
        $f->admissionPolicyService()->save("checklist", true, 0);
        $this->decide($f, $id);
        $policy = $f->sql->rows("sis_admission_policy");
        $events = $f->sql->rows("sis_history_events");
        $cases = $f->sql->rows("sis_admission_cases");
        $m = new \AddSisAdmissionChecklistPolicy("20261117000010");
        $m->setObjectMap(
            new \ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $f->connection,
                    "test_migrations",
                ),
            ]),
        );
        $m->up();
        self::assertSame($policy, $f->sql->rows("sis_admission_policy"));
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertSame($cases, $f->sql->rows("sis_admission_cases"));
    }

    public function testChecklistPolicyIsIndependentlyVersionedAndRequiresPolicyAuthority(): void
    {
        [$f] = $this->fixture();
        $service = $f->admissionPolicyService();
        $other = $service->current();
        $f->access->staff = false;
        $f->access->grants = ["sis:admissions:manage"];
        try {
            $service->save("checklist", true, 0);
            self::fail("Admissions management changed institutional policy.");
        } catch (AccessDeniedException) {
            self::assertFalse($f->admissionPolicy()->checklist()["required"]);
        }
        $f->access->grants[] = "sis:admissions:policy";
        $service->save("checklist", true, 0);
        $after = $service->current();
        foreach (["offers", "withdrawals", "answers"] as $area) {
            self::assertSame($other[$area], $after[$area]);
        }
        $this->expectException(ConcurrentModificationException::class);
        $service->save("checklist", false, 0);
    }

    public function testOnlyAChangedPublicReadinessMessageCreatesAnOwnerUpdateForPrivateCasework(): void
    {
        require_once dirname(__DIR__) . "/Support/CampusFixture.php";
        require_once dirname(__DIR__) . "/Support/AdmissionCaseworkFixture.php";
        $fixture = new \Tests\Support\AdmissionCaseworkFixture();
        $f = $fixture->campus->sis;
        $f->admissionPolicyService()->save("checklist", true, 0);
        $fixture->service->save(
            $fixture->application,
            ["items" => [$this->item(visible: false)]],
            0,
            "keep",
        );
        self::assertSame([], $f->sql->rows("sis_admission_updates"));
        $fixture->service->save(
            $fixture->application,
            ["items" => [$this->item("complete", false)]],
            1,
            "keep",
        );
        self::assertCount(1, $f->sql->rows("sis_admission_updates"));
        $public = $fixture->service->applicant($fixture->application);
        self::assertSame(
            "Your checklist is ready for admissions review. It is not an admission decision.",
            $public["readiness"]["message"],
        );
        self::assertStringNotContainsString(
            "Private fictional staff review",
            json_encode($public),
        );
    }
}
