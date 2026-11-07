<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Finance\LedgerLine;
use Domain\Finance\Service\LedgerService;
use Domain\Finance\Service\StudentAccountService;
use Domain\Finance\Service\TermBillingService;
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
        private TermBillingService $billing
    ) {
    }

    public function account(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $id = $this->service->addAccount($f->text('code', 80), $f->text('title'), $f->text('currency', 3));

        return new JsonResponse(['id' => $id->value], 201);
    }

    public function post(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $currency = $f->text('currency', 3);
        $lines = array_map(static fn (FormInput $l): LedgerLine => new LedgerLine(
            $l->id('account_id'),
            new Money($l->integer('amount_minor', -Money::MAX_MINOR, Money::MAX_MINOR), $currency)
        ), $f->items('lines'));
        $journal = $this->service->post($f->text('reference', 80), $f->date('posted_on'), $lines);

        return new JsonResponse(['id' => (string) $journal->aggregateId()], 201);
    }

    public function reverse(string $id): ResponseInterface
    {
        $this->service->reverse(new JournalId($id));

        return new JsonResponse(['status' => 'reversed']);
    }

    public function reverseEntry(string $id, string $operation): ResponseInterface
    {
        $this->students->reverse(new AccountId($id), new EntityId($operation));

        return new JsonResponse(['status' => 'reversed']);
    }

    public function show(string $id): ResponseInterface
    {
        $journal = $this->service->get(new JournalId($id));

        return new JsonResponse(['id' => (string) $journal->aggregateId(), 'reference' => $journal->reference(),
            'currency' => $journal->currency(), 'reversed' => $journal->isReversed(),
                'posted_on' => $journal->postedOn(),
            'lines' => array_map(static fn (LedgerLine $l): array => ['account_id' => $l->accountId->value,
                'amount_minor' => $l->amount->minor], $journal->lines())]);
    }

    public function defineBillingRule(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $rule = $this->billing->define(
            $f->id('term_id'),
            $f->optionalId('program_id'),
            $f->text('name'),
            $f->text('kind', 12),
            new Money($f->integer('amount_minor', 1), $f->text('currency', 3))
        );

        return new JsonResponse(['id' => (string) $rule->aggregateId()], 201);
    }

    public function billingRule(string $id): ResponseInterface
    {
        $rule = $this->billing->get(new BillingRuleId($id));

        return new JsonResponse(['id' => (string) $rule->aggregateId(), 'name' => $rule->name(),
            'kind' => $rule->kind(),
            'term_id' => $rule->termId()->value, 'program_id' => $rule->programId()?->value,
            'amount_minor' => $rule->amount()->minor, 'currency' => $rule->amount()->currency,
            'retired' => $rule->isRetired()]);
    }

    public function retireBillingRule(string $id): ResponseInterface
    {
        $this->billing->retire(new BillingRuleId($id));

        return new JsonResponse(['status' => 'retired']);
    }

    public function generateBills(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $students = array_map(static fn (FormInput $s): EntityId => $s->id('student_id'), $f->items('students', 100));
        $this->billing->generate($f->id('term_id'), $students);

        return new JsonResponse(['status' => 'generated']);
    }
}
