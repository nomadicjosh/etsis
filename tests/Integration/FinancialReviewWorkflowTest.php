<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\FinancialReview;
use Domain\Finance\Repository\AccountRepository;
use Domain\Finance\Repository\FinancialReviewRepository;
use Domain\Finance\Service\FinancialReviewService;
use Domain\Finance\ValueObject\Money;
use Domain\Finance\ValueObject\PostingReference;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Enum\HiatusCode;
use Domain\Student\StudentHiatus;
use DomainException;
use HiatusFixture;
use Infrastructure\Persistence\Repository\SqlFinancialReviewRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SisFixture;

final class FinancialReviewWorkflowTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/HiatusFixture.php";
    }

    private function service(
        HiatusFixture $h,
        ?AuditTrail $audit = null,
        ?AccountRepository $accounts = null,
        ?FinancialReviewRepository $reviews = null,
    ): FinancialReviewService {
        $f = $h->sis;
        return new FinancialReviewService(
            $reviews ?? new SqlFinancialReviewRepository($f->sql),
            $h->repository,
            $f->admissions,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $h->cipher,
            $f->clock,
            $accounts ?? $f->accounts,
        );
    }

    private function source(HiatusFixture $h): StudentHiatus
    {
        $hiatus = new StudentHiatus(
            EntityId::generate(),
            $h->sis->student()->id,
            HiatusCode::Illness,
            new DateRange(new DateTimeImmutable("2026-08-01")),
        );
        $h->history->create($hiatus, "Fictional sensitive medical case comment");
        return $hiatus;
    }

    public function testRegistrarRequestsOneHandOffAndSeesProgressWithoutFinanceNotesOrPrivateCaseDetails(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $source = $this->source($h);
        $service = $this->service($h);
        $events = $f->sql->rows("sis_history_events");
        $f->access->staff = false;
        $f->access->grants = ["sis:registration:manage"];
        $result = $service->request($source->studentId, $source->id);
        self::assertSame($result, $service->request($source->studentId, $source->id));
        self::assertSame($result, $service->progress($source->studentId, $source->id));
        self::assertCount(1, $f->sql->rows("sis_financial_reviews"));
        self::assertCount(1, $f->sql->rows("sis_audit", ["action" => "finance.review_requested"]));
        $f->access->grants = ["sis:finance:post"];
        $view = $service->view(new EntityId($result["id"]));
        self::assertArrayNotHasKey("code", $view["source"]);
        self::assertStringNotContainsString("medical", json_encode($view));
        $op = EntityId::generate();
        $service->change(new EntityId($result["id"]), $op, 0, "claim", "Fictional private finance note");
        $f->access->grants = ["sis:registration:manage"];
        $progress = $service->progress($source->studentId, $source->id);
        self::assertSame("in_review", $progress["status"]);
        self::assertArrayNotHasKey("note", $progress);
        self::assertArrayNotHasKey("assigned_to", $progress);
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        $this->expectException(AccessDeniedException::class);
        $service->history(new EntityId($result["id"]));
    }

    public function testFinanceClaimCompleteAndReopenAreVersionedEncryptedAndRepeatableWithoutPostingMoney(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $source = $this->source($h);
        $service = $this->service($h);
        $id = new EntityId($service->request($source->studentId, $source->id)["id"]);
        $events = $f->sql->rows("sis_history_events");
        $op = EntityId::generate();
        $claimed = $service->change($id, $op, 0, "claim", "Fictional balance review");
        self::assertSame($claimed, $service->change($id, $op, 0, "claim", "Fictional balance review"));
        $completed = $service->change(
            $id,
            EntityId::generate(),
            1,
            "complete",
            "Fictional no adjustment necessary",
        );
        self::assertSame("completed", $completed["status"]);
        $service->change($id, EntityId::generate(), 2, "reopen", "Fictional further review");
        $history = $service->history($id)["items"];
        self::assertCount(3, $history);
        self::assertSame("Fictional further review", $history[0]["note"]);
        self::assertSame("open", $service->view($id)["status"]);
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertStringNotContainsString(
            "Fictional balance review",
            json_encode($f->sql->rows("sis_financial_review_actions")),
        );
        self::assertStringNotContainsString("Fictional no adjustment", json_encode($f->sql->rows("sis_audit")));
    }

    public function testOtherReviewerMustClaimBeforeCompletingAndStaleReviewCannotOverwriteAssignment(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $source = $this->source($h);
        $service = $this->service($h);
        $id = new EntityId($service->request($source->studentId, $source->id)["id"]);
        $service->change($id, EntityId::generate(), 0, "claim", "Fictional first claim");
        $f->access->actor = $f->user();
        try {
            $service->change($id, EntityId::generate(), 1, "complete", "Fictional wrong assignee");
            self::fail("A reviewer completed another reviewer task without claiming it.");
        } catch (DomainException) {
            self::assertSame(1, $service->view($id)["version"]);
        }
        try {
            $service->change($id, EntityId::generate(), 0, "claim", "Fictional stale claim");
            self::fail("A stale assignment was accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame(1, $service->view($id)["version"]);
        }
        $service->change($id, EntityId::generate(), 1, "claim", "Fictional explicit takeover");
        self::assertTrue($service->view($id)["assigned_to_me"]);
    }

    public function testStudentOwnerAndAcademicReaderCannotAccessStaffHandOffsOrFinanceQueue(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $source = $this->source($h);
        $service = $this->service($h);
        $id = new EntityId($service->request($source->studentId, $source->id)["id"]);
        $f->access->staff = false;
        $f->access->grants = ["sis:student:read", "sis:finance:read"];
        foreach (["request", "progress", "page", "view", "history", "change"] as $action) {
            try {
                match ($action) {
                    "request" => $service->request($source->studentId, $source->id),
                    "progress" => $service->progress($source->studentId, $source->id),
                    "page" => $service->page(null),
                    "view" => $service->view($id),
                    "history" => $service->history($id),
                    "change" => $service->change($id, EntityId::generate(), 0, "claim", "Fictional denied claim"),
                };
                self::fail("A private office review was exposed.");
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
    }

    public function testHandOffCannotBeMovedToAnotherStudentAndVoidedCaseIsVisibleForFinanceFollowUp(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $source = $this->source($h);
        $service = $this->service($h);
        $other = $f->student();
        try {
            $service->request($other->id, $source->id);
            self::fail("The source case moved between students.");
        } catch (RecordNotFoundException) {
            self::assertSame([], $f->sql->rows("sis_financial_reviews"));
        }
        $result = $service->request($source->studentId, $source->id);
        $h->history->void(
            $source->studentId,
            $source->id,
            EntityId::generate(),
            $source->revision(),
            "Fictional duplicate",
        );
        self::assertTrue($service->view(new EntityId($result["id"]))["source"]["voided"]);
        self::assertSame($result, $service->request($source->studentId, $source->id));
    }

    public function testQueueAndPrivateDecisionHistoryAreBoundedAndCursorPaged(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $source = $this->source($h);
        $service = $this->service($h);
        $repository = new SqlFinancialReviewRepository($f->sql);
        $id = new EntityId($service->request($source->studentId, $source->id)["id"]);
        for ($i = 0; $i < 51; ++$i) {
            $extra = new StudentHiatus(
                EntityId::generate(),
                $source->studentId,
                HiatusCode::Leave,
                new DateRange(new DateTimeImmutable("2026-08-01")),
            );
            $h->repository->add($extra);
            $repository->add(
                new FinancialReview(
                    EntityId::generate(),
                    $source->studentId,
                    $extra->id,
                    $f->access->actor,
                    $f->clock->now()->format(DATE_ATOM),
                ),
            );
        }
        $page = $service->page("open");
        self::assertCount(50, $page["items"]);
        $next = $service->page("open", new EntityId($page["next_after"]));
        self::assertCount(2, $next["items"]);
        self::assertNull($next["next_after"]);
        for ($i = 0; $i < 53; ++$i) {
            $service->change($id, EntityId::generate(), $i, "claim", "Fictional progress " . $i);
        }
        $history = $service->history($id);
        self::assertCount(50, $history["items"]);
        $nextHistory = $service->history($id, new EntityId($history["next_after"]));
        self::assertCount(3, $nextHistory["items"]);
        self::assertNull($nextHistory["next_after"]);
        self::assertSame(
            [],
            array_intersect(array_column($history["items"], "id"), array_column($nextHistory["items"], "id")),
        );
    }

    public function testLateAuditFailureRollsBackBothFinanceDecisionAndEncryptedHistory(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $source = $this->source($h);
        $id = new EntityId($this->service($h)->request($source->studentId, $source->id)["id"]);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willThrowException(new RuntimeException("Fictional finance audit failure"));
        try {
            $this->service($h, $audit)->change($id, EntityId::generate(), 0, "claim", "Fictional failed decision");
            self::fail("A partially audited decision committed.");
        } catch (RuntimeException $error) {
            self::assertSame("Fictional finance audit failure", $error->getMessage());
        }
        self::assertSame("open", $this->service($h)->view($id)["status"]);
        self::assertSame([], $f->sql->rows("sis_financial_review_actions"));
    }

    private function facts(SisFixture $f): array
    {
        return array_map($f->sql->rows(...), [
            "sis_financial_reviews",
            "sis_financial_review_actions",
            "sis_audit",
            "sis_student_accounts",
            "sis_account_balances",
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
        ]);
    }

    private function claimed(HiatusFixture $h): array
    {
        $source = $this->source($h);
        $service = $this->service($h);
        $id = new EntityId($service->request($source->studentId, $source->id)["id"]);
        $service->change($id, EntityId::generate(), 0, "claim", "Fictional finance assignment.");
        return [$source, $id];
    }

    public function testCompletedReviewRetainsVerifiedPostingFactsAndOriginalRetryAfterLaterActivity(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        [$source, $id] = $this->claimed($h);
        $account = $f->finance()->open($source->studentId, $f->term, "USD");
        $charge = EntityId::generate();
        $f->finance()->post(
            $account->aggregateId(),
            $charge,
            EntryType::Charge,
            new Money(1250, "USD"),
            1,
            "Fictional original charge.",
        );
        $reference = new PostingReference($account->aggregateId(), $charge, 2);
        $events = $f->sql->rows("sis_history_events");
        $service = $this->service($h);
        $operation = EntityId::generate();
        $completed = $service->change($id, $operation, 1, "complete", "Fictional verified outcome.", [$reference]);
        self::assertSame("completed", $completed["status"]);
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        $snapshot = $service->history($id)["items"][0]["postings"][0];
        self::assertSame(
            $reference->values() + [
                "term_id" => $f->term->value,
                "type" => "charge",
                "minor" => 1250,
                "currency" => "USD",
                "reversed" => false,
            ],
            $snapshot,
        );
        $receipt = $f->sql->one("sis_financial_review_actions", ["id" => $operation->value]);
        self::assertStringNotContainsString((string) $account->aggregateId(), json_encode($receipt));
        $f->finance()->reverse($account->aggregateId(), $charge, 2, "Fictional later reversal.");
        $service->change($id, EntityId::generate(), 2, "reopen", "Fictional later follow-up.");
        $before = $this->facts($f);
        self::assertSame(
            $completed,
            $service->change($id, $operation, 1, "complete", "Fictional verified outcome.", [$reference]),
        );
        self::assertSame($before, $this->facts($f));
        self::assertSame($receipt, $f->sql->one("sis_financial_review_actions", ["id" => $operation->value]));
        self::assertSame($snapshot, $service->history($id)["items"][1]["postings"][0]);
        self::assertSame("open", $service->view($id)["status"]);
        $f->access->staff = false;
        $f->access->grants = ["sis:finance:post"];
        self::assertFalse($service->view($id)["can_view_accounts"]);
        self::assertSame([], $service->history($id)["items"][1]["postings"]);
        $this->expectException(AccessDeniedException::class);
        $service->change($id, $operation, 1, "complete", "Fictional verified outcome.", [$reference]);
    }

    public function testForeignMissingDuplicateStaleAndExcessPostingReferencesCannotCompleteATask(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        [$source, $id] = $this->claimed($h);
        $account = $f->finance()->open($source->studentId, $f->term, "USD");
        $foreign = $f->finance()->open($f->student()->id, $f->term, "USD");
        $posting = EntityId::generate();
        foreach ([$account, $foreign] as $a) {
            $f->finance()->post(
                $a->aggregateId(),
                $posting,
                EntryType::Payment,
                new Money(100, "USD"),
                1,
                "Fictional payment.",
            );
        }
        $valid = new PostingReference($account->aggregateId(), $posting, 2);
        $before = $this->facts($f);
        $invalidSelections = [
            [new PostingReference($foreign->aggregateId(), $posting, 2)],
            [new PostingReference($account->aggregateId(), EntityId::generate(), 2)],
            [$valid, $valid],
            array_fill(0, 11, $valid),
            [new PostingReference($account->aggregateId(), $posting, 1)],
        ];
        foreach ($invalidSelections as $references) {
            try {
                $this->service($h)->change(
                    $id,
                    EntityId::generate(),
                    1,
                    "complete",
                    "Fictional invalid links.",
                    $references,
                );
                self::fail("An invalid linked-entry selection completed the finance task.");
            } catch (ValidationException | ConcurrentModificationException) {
                self::assertSame($before, $this->facts($f));
            }
        }
        try {
            $this->service($h)->change($id, EntityId::generate(), 1, "claim", "Fictional misplaced link.", [
                $valid,
            ]);
            self::fail("A progress-only action accepted completion evidence.");
        } catch (ValidationException) {
            self::assertSame($before, $this->facts($f));
        }
        self::assertSame(
            "completed",
            $this->service($h)->change(
                $id,
                EntityId::generate(),
                1,
                "complete",
                "Fictional fresh reviewed evidence.",
                [$valid],
            )["status"],
        );
    }

    public function testLateFailuresRollBackPostingEvidenceReceiptCaseAndRequiredAudit(): void
    {
        foreach (["audit", "post_permission", "read_permission", "actor", "history_limit"] as $failure) {
            $h = new HiatusFixture();
            $f = $h->sis;
            [$source, $id] = $this->claimed($h);
            $a = $f->finance()->open($source->studentId, $f->term, "USD");
            $operation = EntityId::generate();
            $f->finance()->post(
                $a->aggregateId(),
                $operation,
                EntryType::Payment,
                new Money(100, "USD"),
                1,
                "Fictional source payment.",
            );
            $other = $f->user();
            $audit = $this->createStub(AuditTrail::class);
            $audit
                ->method("record")
                ->willReturnCallback(static function ($actor, $action, $subject) use ($f, $failure, $other): void {
                    $f->audit->record($actor, $action, $subject);
                    if ($failure === "audit") {
                        throw new RuntimeException("Fictional audit failure.");
                    }
                    $f->access->staff = false;
                    $f->access->grants = match ($failure) {
                        "post_permission" => ["sis:finance:read"],
                        "read_permission" => ["sis:finance:post"],
                        default => ["sis:finance:read", "sis:finance:post"],
                    };
                if ($failure === "actor") {
                    $f->access->actor = $other;
                }
                });
            $repository = null;
            if ($failure === "history_limit") {
                $repository = $this->createStub(AccountRepository::class);
                $repository->method("get")->willThrowException(new HistoryReadLimitException());
            }
            $before = $this->facts($f);
            try {
                $this->service($h, $audit, $repository)->change(
                    $id,
                    EntityId::generate(),
                    1,
                    "complete",
                    "Fictional interrupted evidence.",
                    [new PostingReference($a->aggregateId(), $operation, 2)],
                );
                self::fail("An interrupted financial review committed.");
            } catch (RuntimeException | AccessDeniedException | HistoryReadLimitException) {
                self::assertSame($before, $this->facts($f));
            }
        }
    }

    public function testAccountAndHistoryReadsRecheckAuthorityAfterTheirAuditsWithoutReturningPrivateResults(): void
    {
        foreach (["accounts", "history", "view", "request"] as $read) {
            $h = new HiatusFixture();
            $f = $h->sis;
            [$source, $id] = $this->claimed($h);
            $f->finance()->open($source->studentId, $f->term, "USD");
            $otherSource = $read === "request" ? $this->source($h) : $source;
            $audit = $this->createStub(AuditTrail::class);
            $audit
                ->method("record")
                ->willReturnCallback(static function ($actor, $action, $subject) use ($f, $read): void {
                    $f->audit->record($actor, $action, $subject);
                    $f->access->staff = false;
                    $f->access->grants = $read === "history" ? ["sis:finance:post"] : [];
                });
            $before = $this->facts($f);
            try {
                $service = $this->service($h, $audit);
                match ($read) {
                    "accounts" => $service->accounts($id),
                    "history" => $service->history($id),
                    "view" => $service->view($id),
                    "request" => $service->request($otherSource->studentId, $otherSource->id),
                };
                self::fail("A finance response survived removed authority.");
            } catch (AccessDeniedException) {
                self::assertSame($before, $this->facts($f));
            }
        }
    }

    public function testAccountLookupPagesStudentScopedLabelsWithoutReplayingStatements(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        [$source, $id] = $this->claimed($h);
        for ($i = 0; $i < 26; $i++) {
            $term = EntityId::generate();
            $f->sql->insert("sis_terms", [
                "id" => $term->value,
                "code" => "FICTION." . $i,
                "starts_on" => "2026-01-01",
                "ends_on" => "2026-12-31",
                "add_drop_ends_on" => "2026-01-15",
            ]);
            $f->finance()->open($source->studentId, $term, "USD");
        }
        $f->finance()->open($f->student()->id, $f->term, "EUR");
        $repository = $this->createMock(AccountRepository::class);
        $repository->expects(self::never())->method("get");
        $service = $this->service($h, accounts: $repository);
        $first = $service->accounts($id);
        $next = $service->accounts($id, new EntityId($first["next_after"]));
        self::assertCount(25, $first["items"]);
        self::assertCount(1, $next["items"]);
        self::assertNull($next["next_after"]);
        self::assertSame(["id", "currency", "term_code"], array_keys($first["items"][0]));
        self::assertSame(
            [],
            array_intersect(array_column($first["items"], "id"), array_column($next["items"], "id")),
        );
        $f->access->staff = false;
        $f->access->grants = ["sis:finance:post"];
        $this->expectException(AccessDeniedException::class);
        $service->accounts($id);
    }
}
