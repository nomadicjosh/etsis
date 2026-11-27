<?php

declare(strict_types=1);

namespace Tests\ServiceFees;

use Domain\Admissions\Service\ApplicationFormService;
use Domain\Audit\Repository\AuditTrail;
use Domain\Portal\Service\HostedCheckout;
use Domain\ServiceFee\Service\ServiceFeeCheckout;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlApplicationFormRepository;
use Infrastructure\Persistence\Repository\SqlServiceFeeRepository;
use PHPUnit\Framework\TestCase;

final class ServiceFeesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/CampusFixture.php";
    }
    private function fixture(bool $waivers = true): array
    {
        $c = new \CampusFixture();
        $f = $c->sis;
        $student = $f->student();
        $fees = $f->fees();
        $fees->savePolicy("transcript", 0, 2500, "USD", $waivers);
        $fee = $fees->prepare(
            $student->personId,
            "transcript",
            ["student_id" => $student->id->value],
            "staff",
        );
        return [$c, $student, new EntityId($fee["fee_id"])];
    }
    private function confirm(\SisFixture $f, array $r): array
    {
        return $f
            ->fees()
            ->confirm(
                new EntityId($r["fee_id"]),
                new EntityId($r["operation_id"]),
                $r["action"],
                $r["details"],
                $r["reason"],
                $r["revision"],
                $r["review_token"],
            );
    }
    private function pay(
        \SisFixture $f,
        EntityId $fee,
        int $amount = 2500,
    ): array {
        $r = $f->fees()->review(
            $fee,
            "payment",
            [
                "minor" => $amount,
                "method" => "cash",
                "reference" => "Fictional receipt",
            ],
            "Fictional cash received.",
        );
        $this->confirm($f, $r);
        return $r;
    }
    private function checkout(\CampusFixture $c): ServiceFeeCheckout
    {
        $f = $c->sis;
        return new ServiceFeeCheckout(
            $f->fees(),
            new SqlServiceFeeRepository($f->sql),
            $c->payments,
            $c->gateway,
            new HostedCheckout(
                $c->payments,
                $c->gateway,
                $c->cipher,
                $f->transactions,
                $f->clock,
            ),
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->clock,
        );
    }
    public function testDefaultRequiresNoFeeAndChangesDoNotBackfillHistoricalRequests(): void
    {
        $c = new \CampusFixture();
        $f = $c->sis;
        $s = $f->student();
        $id = EntityId::generate();
        $c->service->requestTranscript($id, $s->id, "pickup", "");
        self::assertSame([], $f->sql->rows("sis_service_fees"));
        $f->fees()->savePolicy("transcript", 0, 2500, "USD", true);
        $c->service->requestTranscript($id, $s->id, "pickup", "");
        self::assertCount(1, $f->sql->rows("sis_transcript_requests"));
        self::assertSame([], $f->sql->rows("sis_service_fees"));
        $this->expectException(ValidationException::class);
        $c->service->requestTranscript(
            EntityId::generate(),
            $s->id,
            "pickup",
            "",
        );
    }
    public function testPartialPaymentCannotClearAndReceiptWaiverAndRequestAreAtomicHistoricalFacts(): void
    {
        [$c, $s, $fee] = $this->fixture();
        $f = $c->sis;
        $this->pay($f, $fee, 1000);
        try {
            $c->service->requestTranscript($fee, $s->id, "pickup", "");
            self::fail("Unpaid fee bypassed.");
        } catch (ValidationException) {
            self::assertSame([], $f->sql->rows("sis_transcript_requests"));
        }
        $r = $f
            ->fees()
            ->review($fee, "waive", [], "Fictional approved hardship waiver.");
        $this->confirm($f, $r);
        $this->confirm($f, $r);
        $c->service->requestTranscript($fee, $s->id, "pickup", "");
        $show = $f->fees()->show($fee, "staff");
        self::assertTrue($show["waived"]);
        self::assertSame(1000, $show["net_minor"]);
        self::assertSame($fee->value, $show["applied_to"]);
        self::assertCount(4, $f->sql->rows("sis_history_events"));
        self::assertStringContainsString(
            "hardship",
            json_encode($f->fees()->timeline($fee, "staff")),
        );
        self::assertStringNotContainsString(
            "hardship",
            json_encode($f->fees()->timeline($fee, "owner")),
        );
        self::assertSame(
            $fee->value,
            $f->sql->one("sis_transcript_requests", ["id" => $fee->value])[
                "fee_id"
            ],
        );
        $this->expectException(ValidationException::class);
        $c->service->requestTranscript(
            EntityId::generate(),
            $s->id,
            "pickup",
            "",
        );
    }
    public function testOriginalQuotesSurvivePolicyChangesAndWaiverCanBeDisabled(): void
    {
        [$c, $s, $fee] = $this->fixture(false);
        $f = $c->sis;
        $f->fees()->savePolicy("transcript", 1, 0, "EUR", true);
        self::assertSame(
            $fee->value,
            $f
                ->fees()
                ->prepare(
                    $s->personId,
                    "transcript",
                    ["student_id" => $s->id->value],
                    "owner",
                )["fee_id"],
        );
        self::assertSame(
            "USD",
            $f->fees()->show($fee, "owner")["policy"]["currency"],
        );
        $this->expectException(ValidationException::class);
        $f->fees()->review($fee, "waive", [], "Waiver forbidden by the quote.");
    }
    public function testStaleReviewAndChangedReasonAreRejectedButOriginalAcceptedRetrySurvivesExpiry(): void
    {
        [$c, $s, $fee] = $this->fixture();
        $f = $c->sis;
        $r = $f->fees()->review($fee, "waive", [], "Fictional waiver.");
        $this->pay($f, $fee, 1000);
        try {
            $this->confirm($f, $r);
            self::fail("Stale fee review confirmed.");
        } catch (ConcurrentModificationException) {
            self::assertFalse($f->fees()->show($fee, "staff")["waived"]);
        }
        $r = $f->fees()->review($fee, "waive", [], "Fictional waiver.");
        $this->confirm($f, $r);
        $f->clock->time = $f->clock->time->modify("+1 day");
        $this->confirm($f, $r);
        $r["reason"] = "Changed reason";
        $this->expectException(ValidationException::class);
        $this->confirm($f, $r);
    }
    public function testActualRefundIsCappedAndDoesNotUndoAcceptedRequestOrItsRetry(): void
    {
        [$c, $s, $fee] = $this->fixture();
        $f = $c->sis;
        $p = $this->pay($f, $fee);
        $c->service->requestTranscript($fee, $s->id, "pickup", "");
        $r = $f->fees()->review(
            $fee,
            "refund",
            [
                "payment_id" => $p["operation_id"],
                "minor" => 2500,
                "reference" => "Fictional completed refund",
            ],
            "Actual refund confirmed.",
        );
        $this->confirm($f, $r);
        $c->service->requestTranscript($fee, $s->id, "pickup", "");
        self::assertSame(
            "pending",
            $f->sql->one("sis_transcript_requests", ["id" => $fee->value])[
                "status"
            ],
        );
        self::assertSame(0, $f->fees()->show($fee, "staff")["net_minor"]);
        $this->expectException(ValidationException::class);
        $f->fees()->review(
            $fee,
            "refund",
            [
                "payment_id" => $p["operation_id"],
                "minor" => 1,
                "reference" => "Duplicate refund",
            ],
            "Cannot refund twice.",
        );
    }
    public function testOwnerAndStaffScopesAreSeparateAndRelinkingProtectsEarlierPayer(): void
    {
        [$c, $s, $fee] = $this->fixture();
        $f = $c->sis;
        $f->access->staff = false;
        self::assertCount(1, $f->fees()->page($s->personId, "owner")["items"]);
        try {
            $f->fees()->review($fee, "waive", [], "Owner cannot waive.");
            self::fail("Owner waived fee.");
        } catch (AccessDeniedException) {
            self::assertFalse($f->fees()->show($fee, "owner")["waived"]);
        }
        $nextOwner = $f->user();
        $f->sql->update(
            "sis_persons",
            ["owner_user_id" => $nextOwner->value],
            ["id" => $s->personId->value],
        );
        try {
            $f->fees()->show($fee, "owner");
            self::fail("Old owner retained access.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $f->access->actor = $nextOwner;
        $this->expectException(AccessDeniedException::class);
        $f->fees()->show($fee, "owner");
    }
    public function testHostedConfirmationIsExactIdempotentAndCreatesNoStudentAccount(): void
    {
        [$c, $s, $fee] = $this->fixture();
        $f = $c->sis;
        $accounts = count($f->sql->rows("sis_student_accounts"));
        $checkout = $this->checkout($c);
        $first = $checkout->checkout($fee);
        self::assertSame($first, $checkout->checkout($fee));
        self::assertCount(1, $c->gateway->calls);
        self::assertFalse($f->fees()->show($fee, "owner")["cleared"]);
        $payment = $f->sql->rows("sis_portal_payments")[0];
        self::assertNull($payment["account_id"]);
        self::assertSame("transcript", $payment["purpose"]);
        self::assertStringNotContainsString(
            "checkout.stripe.com",
            $payment["encrypted_url"],
        );
        $c->gateway->confirmation = [
            "payment" => $payment["id"],
            "session" => $payment["session_id"],
            "minor" => 2499,
            "currency" => "USD",
        ];
        try {
            $c->confirmation->receive("{}", "fictional-valid-signature");
            self::fail("Mismatched payment.");
        } catch (\DomainException) {
            self::assertFalse($f->fees()->show($fee, "owner")["cleared"]);
        }
        $c->gateway->confirmation["minor"] = 2500;
        $c->gateway->available = false;
        $c->confirmation->receive("{}", "fictional-valid-signature");
        $c->confirmation->receive("{}", "fictional-valid-signature");
        self::assertTrue($f->fees()->show($fee, "owner")["cleared"]);
        self::assertCount(1, $f->fees()->show($fee, "owner")["payments"]);
        self::assertSame(
            $accounts,
            count($f->sql->rows("sis_student_accounts")),
        );
        self::assertSame(
            ["url" => null, "cleared" => true],
            $checkout->checkout($fee),
        );
    }
    public function testLatePaymentAfterWaiverAndApplicationStillRecordsActualMoney(): void
    {
        [$c, $s, $fee] = $this->fixture();
        $f = $c->sis;
        $this->checkout($c)->checkout($fee);
        $p = $f->sql->rows("sis_portal_payments")[0];
        $this->confirm(
            $f,
            $f
                ->fees()
                ->review(
                    $fee,
                    "waive",
                    [],
                    "Fictional waiver while checkout pending.",
                ),
        );
        $c->service->requestTranscript($fee, $s->id, "pickup", "");
        $c->gateway->confirmation = [
            "payment" => $p["id"],
            "session" => $p["session_id"],
            "minor" => 2500,
            "currency" => "USD",
        ];
        $c->confirmation->receive("{}", "fictional-valid-signature");
        self::assertSame(2500, $f->fees()->show($fee, "staff")["net_minor"]);
        self::assertTrue($f->fees()->show($fee, "staff")["waived"]);
        self::assertSame(
            $fee->value,
            $f->fees()->show($fee, "staff")["applied_to"],
        );
    }
    public function testRequiredAuditFailureRollsBackFeeAndProofAndProjectionWrites(): void
    {
        [$c, $s, $fee] = $this->fixture();
        $f = $c->sis;
        $r = $f->fees()->review($fee, "waive", [], "Fictional waiver.");
        $failing = new class implements AuditTrail {
            public function record(
                EntityId $actor,
                string $action,
                EntityId $subject,
            ): void {
                throw new \RuntimeException("Fictional audit failure.");
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        try {
            $f->fees($failing)->confirm(
                $fee,
                new EntityId($r["operation_id"]),
                $r["action"],
                $r["details"],
                $r["reason"],
                $r["revision"],
                $r["review_token"],
            );
            self::fail("Audit failed open.");
        } catch (\RuntimeException) {
            self::assertSame(1, $f->fees()->show($fee, "staff")["version"]);
        }
        $this->confirm($f, $r);
        self::assertTrue($f->fees()->show($fee, "staff")["waived"]);
    }
    public function testApplicationFeeGuardsStaffWritesAndPreservesOriginalOwnerSubmissionRetry(): void
    {
        $f = new \SisFixture();
        $person = $f->legacyPerson($f->access->actor, "Fictional", "Prospect")
            ->id;
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
            "Fictional fee form",
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
        $f->fees()->savePolicy("application", 0, 5000, "USD", true);
        try {
            $f->submissionService()->apply(
                EntityId::generate(),
                $person,
                $f->program,
                $f->term,
            );
            self::fail("Staff bypassed required fee.");
        } catch (ValidationException) {
            self::assertSame([], $f->sql->rows("sis_applications"));
        }
        $a = $f->fees()->prepare(
            $person,
            "application",
            [
                "program_id" => $f->program->value,
                "term_id" => $f->term->value,
            ],
            "owner",
        );
        $fee = new EntityId($a["fee_id"]);
        $this->pay($f, $fee, 5000);
        $f->access->staff = false;
        $app = $forms->submit($fee, $form->id, $person, $f->term, [
            "statement" => "Private answer",
        ]);
        self::assertSame(
            $app->value,
            $f->fees()->show($fee, "owner")["applied_to"],
        );
        $f->access->staff = true;
        $f->fees()->savePolicy("application", 1, 9000, "EUR", false);
        $f->access->staff = false;
        self::assertSame(
            $app->value,
            $forms->submit($fee, $form->id, $person, $f->term, [
                "statement" => "Private answer",
            ])->value,
        );
        $events = iterator_to_array(
            \EventStoreFixture::framework(
                $f->connection->queryBuilder(),
            )->getAggregateHistoryFor(
                new \Domain\Admissions\ValueObject\AdmissionDecisionId(
                    $app->value,
                ),
            ),
        );
        self::assertSame(
            $fee->value,
            $events[0]->metadata()["service_fee"]["fee_id"],
        );
        $f->access->staff = true;
        $f->fees()->savePolicy("application", 2, 5000, "USD", true);
        $quote = $f->fees()->prepare(
            $person,
            "application",
            [
                "program_id" => $f->program->value,
                "term_id" => $f->term->value,
            ],
            "staff",
        );
        $secondFee = new EntityId($quote["fee_id"]);
        $this->confirm(
            $f,
            $f
                ->fees()
                ->review(
                    $secondFee,
                    "waive",
                    [],
                    "Fictional direct intake waiver.",
                ),
        );
        $secondApp = EntityId::generate();
        $f->submissionService()->apply(
            $secondApp,
            $person,
            $f->program,
            $f->term,
        );
        $f->submissionService()->apply(
            $secondApp,
            $person,
            $f->program,
            $f->term,
        );
        self::assertSame(
            $secondApp->value,
            $f->fees()->show($secondFee, "staff")["applied_to"],
        );
        self::assertCount(2, $f->sql->rows("sis_applications"));
        self::assertSame([], $f->sql->rows("sis_student_accounts"));
    }
    public function testChangedBalanceCreatesANewCheckoutAndBothLatePaymentsRemainRecorded(): void
    {
        [$c, $s, $fee] = $this->fixture();
        $f = $c->sis;
        $checkout = $this->checkout($c);
        $checkout->checkout($fee);
        $this->pay($f, $fee, 1000);
        $checkout->checkout($fee);
        self::assertSame([2500, 1500], array_column($c->gateway->calls, 1));
        foreach ($f->sql->rows("sis_portal_payments") as $payment) {
            $c->gateway->confirmation = [
                "payment" => $payment["id"],
                "session" => $payment["session_id"],
                "minor" => (int) $payment["amount_minor"],
                "currency" => "USD",
            ];
            $c->confirmation->receive("{}", "fictional-valid-signature");
        }
        self::assertSame(5000, $f->fees()->show($fee, "staff")["net_minor"]);
        self::assertCount(3, $f->fees()->show($fee, "staff")["payments"]);
    }
    public function testAuthenticatedConfirmationCanArriveBeforeUrlAttachment(): void
    {
        [$c, $s, $fee] = $this->fixture();
        $f = $c->sis;
        $payment = EntityId::generate();
        $c->payments->add($payment, [
            "account_id" => null,
            "fee_id" => $fee->value,
            "purpose" => "transcript",
            "owner_user_id" => $f->access->actor->value,
            "amount_minor" => 2500,
            "currency" => "USD",
            "session_id" => "pending:" . $payment->value,
            "status" => "pending",
            "expires_at" => $f->clock->now()->getTimestamp() + 3600,
        ]);
        $c->gateway->confirmation = [
            "payment" => $payment->value,
            "session" => "cs_test_" . $payment->value,
            "minor" => 2500,
            "currency" => "USD",
        ];
        $c->confirmation->receive("{}", "fictional-valid-signature");
        self::assertSame("paid", $c->payments->find($payment)["status"]);
        self::assertSame(2500, $f->fees()->show($fee, "staff")["net_minor"]);
        $f->sql->update(
            "sis_service_fees",
            ["source_hash" => str_repeat("f", 64)],
            ["id" => $fee->value],
        );
        $this->expectException(
            \Domain\Shared\EventSourcing\CorruptHistoryException::class,
        );
        $f->fees()->show($fee, "staff");
    }
    public function testSubmissionAuditFailureRollsBackTheAcceptedFeeAndRequestTogether(): void
    {
        [$c, $s, $fee] = $this->fixture();
        $f = $c->sis;
        $this->pay($f, $fee);
        $f->connection->pdo->exec(
            "CREATE TRIGGER fee_test_audit BEFORE INSERT ON sis_audit " .
                "WHEN NEW.action='transcript.requested' BEGIN SELECT RAISE(ABORT,'Fictional audit failure'); END",
        );
        $failed = false;
        try {
            $c->service->requestTranscript($fee, $s->id, "pickup", "");
        } catch (\Throwable) {
            $failed = true;
        }
        self::assertTrue($failed);
        self::assertSame([], $f->sql->rows("sis_transcript_requests"));
        self::assertNull($f->fees()->show($fee, "staff")["applied_to"]);
        self::assertSame(2, $f->fees()->show($fee, "staff")["version"]);
        $f->connection->pdo->exec("DROP TRIGGER fee_test_audit");
        $c->service->requestTranscript($fee, $s->id, "pickup", "");
        self::assertSame(
            $fee->value,
            $f->fees()->show($fee, "staff")["applied_to"],
        );
    }
    public function testTwoInitialPolicySavesCannotReplaceTheFirstAcceptedPolicy(): void
    {
        $f = new \SisFixture();
        $repo = new SqlServiceFeeRepository($f->sql);
        $repo->savePolicy(
            "application",
            new \Domain\ServiceFee\ValueObject\FeePolicy(1, 2500, "USD", false),
            0,
        );
        try {
            $repo->savePolicy(
                "application",
                new \Domain\ServiceFee\ValueObject\FeePolicy(
                    1,
                    5000,
                    "EUR",
                    true,
                ),
                0,
            );
            self::fail(
                "A competing initial policy replaced the accepted policy.",
            );
        } catch (ConcurrentModificationException) {
            self::assertSame(2500, $repo->policy("application")->minor);
            self::assertFalse($repo->policy("application")->waivers);
        }
    }
}
