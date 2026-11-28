<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Shared\ValueObject\EntityId;
use Domain\Tuition\Service\TuitionService;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class TuitionController
{
    public function __construct(private TuitionService $service)
    {
    }
    private function json(array $data): ResponseInterface
    {
        return new JsonResponse($data, 200, [
            "Cache-Control" => "private, no-store",
        ]);
    }
    public function schedules(ServerRequest $request): ResponseInterface
    {
        return $this->json(
            $this->service->schedules(
                new FormInput($request->getQueryParams())->optionalId("after"),
            ),
        );
    }
    public function schedule(string $id): ResponseInterface
    {
        return $this->json($this->service->schedule(new EntityId($id)));
    }
    public function assignment(ServerRequest $request): ResponseInterface
    {
        $f = new FormInput($request->getQueryParams());
        return $this->json(
            $this->service->assignment(
                $f->text("scope", 16),
                $f->id("scope_id"),
            ),
        );
    }
    public function reviewSchedule(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $rows = [];
        foreach ($f->items("brackets", 12) as $raw) {
            $b = $raw;
            $rows[] = [
                "from_milli" => $b->integer("from_milli", 0, 999999),
                "through_milli" => $b->integer("through_milli", 0, 999999),
                "flat_minor" => $b->integer("flat_minor"),
                "rate_minor" => $b->integer("rate_minor"),
                "rate_from_milli" => $b->integer("rate_from_milli", 0, 999999),
            ];
        }
        return $this->json(
            $this->service->reviewSchedule(
                $f->optionalId("id"),
                $f->integer("version"),
                $f->text("name"),
                $f->text("currency", 3),
                $f->id("income"),
                $rows,
                $f->bodyText("reason"),
            ),
        );
    }
    public function reviewAssignment(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return $this->json(
            $this->service->reviewAssignment(
                $f->text("scope", 16),
                $f->id("scope_id"),
                $f->requiredNullableId("schedule_id"),
                $f->integer("version"),
                $f->bodyText("reason"),
            ),
        );
    }
    public function staffBills(ServerRequest $request): ResponseInterface
    {
        $f = new FormInput($request->getQueryParams());
        return $this->json(
            $this->service->bills(
                $f->boolean("pending", true),
                "staff",
                $f->optionalId("after"),
            ),
        );
    }
    public function ownerBills(ServerRequest $request): ResponseInterface
    {
        $f = new FormInput($request->getQueryParams());
        return $this->json(
            $this->service->bills(false, "owner", $f->optionalId("after")),
        );
    }
    public function staffBill(
        ServerRequest $request,
        string $id,
    ): ResponseInterface {
        return $this->json(
            $this->service->bill(
                new EntityId($id),
                "staff",
                new FormInput($request->getQueryParams())->optionalId(
                    "invoice_after",
                ),
            ),
        );
    }
    public function ownerBill(
        ServerRequest $request,
        string $id,
    ): ResponseInterface {
        return $this->json(
            $this->service->bill(
                new EntityId($id),
                "owner",
                new FormInput($request->getQueryParams())->optionalId(
                    "invoice_after",
                ),
            ),
        );
    }
    public function reviewInvoice(
        ServerRequest $request,
        string $id,
    ): ResponseInterface {
        $f = FormInput::from($request);
        $lines = [];
        foreach ($f->items("lines", 100) as $raw) {
            $l = $raw;
            $lines[] = [
                "key" => $l->text("key", 100),
                "minor" => $l->integer("minor"),
            ];
        }
        return $this->json(
            $this->service->reviewInvoice(
                new EntityId($id),
                $f->integer("version"),
                $lines,
                $f->date("due_on")->format("Y-m-d"),
                $f->bodyText("reason"),
            ),
        );
    }
    public function reviewReprice(
        ServerRequest $request,
        string $id,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return $this->json(
            $this->service->reviewReprice(
                new EntityId($id),
                $f->integer("version"),
                $f->bodyText("reason"),
            ),
        );
    }
    public function confirm(ServerRequest $request): ResponseInterface
    {
        return $this->json(
            $this->service->confirm(
                FormInput::from($request)->text("review_token", 262144),
            ),
        );
    }
    public function staffInvoice(string $id): ResponseInterface
    {
        return $this->json($this->service->invoice(new EntityId($id), "staff"));
    }
    public function ownerInvoice(string $id): ResponseInterface
    {
        return $this->json($this->service->invoice(new EntityId($id), "owner"));
    }
    public function staffPrint(string $id): ResponseInterface
    {
        return $this->printInvoice(
            $this->service->invoice(new EntityId($id), "staff"),
        );
    }
    public function ownerPrint(string $id): ResponseInterface
    {
        return $this->printInvoice(
            $this->service->invoice(new EntityId($id), "owner"),
        );
    }
    private function printInvoice(array $invoice): ResponseInterface
    {
        ob_start();
        try {
            require dirname(__DIR__, 4) .
            "/resources/views/shared/invoice-print.phtml";
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        return new HtmlResponse($html, 200, [
            "Cache-Control" => "private, no-store",
            "X-Content-Type-Options" => "nosniff",
            "Referrer-Policy" => "no-referrer",
        ]);
    }
}
