<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Finance\LedgerLine;
use Domain\Finance\Enum\BillingBasis;
use Domain\Shared\Exception\ValidationException;
use Domain\Finance\Service\LedgerService;
use Domain\Finance\Service\StudentAccountService;
use Domain\Finance\Service\TermBillingService;
use Domain\Finance\Service\BillingBatchService;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\BillingRuleId;
use Domain\Finance\ValueObject\JournalId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisLedgerController
{
    public function __construct(
        private LedgerService $service,
        private StudentAccountService $students,
        private TermBillingService $billing,
        private BillingBatchService $batches,
    ) {
    }

    public function account(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $id = $this->service->addAccount(
            $f->text("code", 80),
            $f->text("title"),
            $f->text("currency", 3),
        );

        return new JsonResponse(["id" => $id->value], 201);
    }

    public function post(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $currency = $f->text("currency", 3);
        $lines = array_map(
            static fn(FormInput $l): LedgerLine => new LedgerLine(
                $l->id("account_id"),
                new Money(
                    $l->integer("amount_minor", -Money::MAX_MINOR, Money::MAX_MINOR),
                    $currency,
                ),
            ),
            $f->items("lines"),
        );
        $journal = $this->service->post(
            $f->id("operation_id"),
            $f->text("reference", 80),
            $f->date("posted_on"),
            $lines,
            $f->bodyText("reason"),
        );

        return new JsonResponse(["id" => (string) $journal->aggregateId()], 201);
    }

    public function reverse(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->reverse(
                new JournalId($id),
                $f->integer("position", 1),
                $f->bodyText("reason"),
            ),
        );
    }

    public function reverseEntry(
        ServerRequest $request,
        string $id,
        string $operation,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->students->reverse(
                new AccountId($id),
                new EntityId($operation),
                $f->integer("position", 1),
                $f->bodyText("reason"),
            ),
        );
    }

    public function show(string $id): ResponseInterface
    {
        $journal = $this->service->get(new JournalId($id));
        $accounting = $journal->accounting();
        if ($accounting !== null) {
            $accounting = [
                "book_id" => $accounting["book_id"],
                "period_id" => $accounting["period_id"],
                "source" =>
                    $accounting["source"] === null
                        ? null
                        : [
                            "account_id" => $accounting["source"]["account_id"],
                            "entry_operation" =>
                                $accounting["source"]["entry_operation"],
                        ],
            ];
        }

        return new JsonResponse([
            "id" => (string) $journal->aggregateId(),
            "reference" => $journal->reference(),
            "position" => $journal->playhead(),
            "currency" => $journal->currency(),
            "reversed" => $journal->isReversed(),
            "posted_on" => $journal->postedOn(),
            "accounting" => $accounting,
            "lines" => $this->service->describeLines($journal),
        ]);
    }

    public function defineBillingRule(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $rule = $this->billing->define(
            $f->id("operation_id"),
            $f->id("term_id"),
            $f->optionalId("program_id"),
            $f->text("name"),
            $f->text("kind", 12),
            new Money($f->integer("amount_minor", 1), $f->text("currency", 3)),
            $f->bodyText("reason"),
            BillingBasis::tryFrom($f->optionalText("pricing_basis", 24) ?? "term") ??
                throw new ValidationException([
                    [
                        "field" => "pricing_basis",
                        "message" => "Choose flat per term or per registered credit.",
                    ],
                ]),
        );

        return new JsonResponse(["id" => (string) $rule->aggregateId()], 201);
    }

    public function billingRule(string $id): ResponseInterface
    {
        $rule = $this->billing->get(new BillingRuleId($id));

        return new JsonResponse([
            "id" => (string) $rule->aggregateId(),
            "name" => $rule->name(),
            "kind" => $rule->kind(),
            "pricing_basis" => $rule->basis()->value,
            "term_id" => $rule->termId()->value,
            "program_id" => $rule->programId()?->value,
            "amount_minor" => $rule->amount()->minor,
            "currency" => $rule->amount()->currency,
            "amount_decimal" => $rule->amount()->decimal(),
            "retired" => $rule->isRetired(),
            "position" => $rule->playhead(),
        ]);
    }

    public function retireBillingRule(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->billing->retire(
                new BillingRuleId($id),
                $f->integer("position", 1),
                $f->bodyText("reason"),
            ),
        );
    }

    public function reviewBills(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $students = array_map(
            static fn(FormInput $s): EntityId => $s->id("student_id"),
            $f->items("students", BillingBatchService::MAX_STUDENTS),
        );
        return new JsonResponse(
            $this->batches->review($f->id("term_id"), $students, $f->bodyText("reason")),
        );
    }

    public function confirmBills(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse(
            $this->batches->confirm(FormInput::from($request)->text("review_token", 600000)),
        );
    }
}
