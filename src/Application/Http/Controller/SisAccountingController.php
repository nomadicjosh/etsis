<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Finance\Service\StudentLedgerService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisAccountingController
{
    public function __construct(private StudentLedgerService $service)
    {
    }

    public function overview(ServerRequest $request): ResponseInterface
    {
        $f = new FormInput($request->getQueryParams());
        return new JsonResponse(
            $this->service->overview(
                $f->text("currency", 3),
                $f->optionalText("after", 26),
            ),
        );
    }

    public function mapping(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->reviewMapping(
                $f->text("currency", 3),
                $f->text("timezone", 80),
                $f->text("receivable_code", 80),
                $f->text("receivable_title"),
                $f->id("charge"),
                $f->id("payment"),
                $f->id("refund"),
                $this->rows($f, "charge_sources", 6),
                $this->rows($f, "payment_methods", 11),
                $f->bodyText("reason"),
            ),
        );
    }

    public function period(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->reviewPeriod(
                $f->text("currency", 3),
                $f->text("label", 80),
                $f->date("starts_on")->format("Y-m-d"),
                $f->date("ends_on")->format("Y-m-d"),
                $f->bodyText("reason"),
            ),
        );
    }

    public function fees(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $mapping = ['recognition' => $f->text('recognition', 16)];
        foreach (['cash', 'liability', 'expense', 'application', 'transcript'] as $key) {
            $mapping[$key] = $f->id($key)->value;
        }
        return new JsonResponse($this->service->reviewServiceFees(
            $f->text('currency', 3),
            $mapping,
            $f->bodyText('reason')
        ));
    }

    public function expense(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->reviewExpense(
            $f->text('currency', 3),
            $f->integer('amount_minor'),
            $f->id('asset'),
            $f->text('reference', 80),
            $f->date('posted_on')->format('Y-m-d'),
            $f->bodyText('reason')
        ));
    }

    public function closing(
        ServerRequest $request,
        string $id,
    ): ResponseInterface {
        $f = FormInput::from($request);
        $action = $f->text("action", 8);
        if (!in_array($action, ["close", "reopen"], true)) {
            throw new \InvalidArgumentException("Choose close or reopen.");
        }
        return new JsonResponse(
            $this->service->reviewClosing(
                new EntityId($id),
                $action === "reopen",
                $f->optionalText("reconciliation", 262144),
                $f->bodyText("reason"),
            ),
        );
    }

    public function confirm(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse(
            $this->service->confirm(
                FormInput::from($request)->text("review_token", 262144),
            ),
        );
    }

    public function reconcile(
        ServerRequest $request,
        string $id,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->reconcile(
                new EntityId($id),
                $f->optionalText("continuation", 262144),
            ),
        );
    }

    private function rows(FormInput $form, string $key, int $limit): array
    {
        $rows = [];
        foreach ($form->requiredValues($key, $limit) as $data) {
            if (!is_array($data)) {
                throw new \InvalidArgumentException(
                    "A mapping must contain a key and an account.",
                );
            }
            $item = new FormInput($data);
            $rows[] = [
                "key" => $item->text("key", 32),
                "account_id" => $item->id("account_id")->value,
            ];
        }
        return $rows;
    }
}
