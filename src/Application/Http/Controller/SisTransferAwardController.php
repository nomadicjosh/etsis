<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\Service\TransferAwardService;
use Domain\Transfer\ValueObject\AwardChange;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisTransferAwardController
{
    public function __construct(
        private TransferAwardService $service,
        private \Domain\Transfer\Service\TransferPeriodService $periods,
    ) {
    }
    public function show(string $student, string $award): ResponseInterface
    {
        return new JsonResponse($this->service->view(new EntityId($student), new EntityId($award)));
    }
    public function preview(ServerRequest $request, string $student, string $award): ResponseInterface
    {
        return new JsonResponse(
            $this->service->preview(new EntityId($student), new EntityId($award), $this->change($request)),
        );
    }
    public function confirm(ServerRequest $request, string $student, string $award): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->confirm(
                new EntityId($student),
                new EntityId($award),
                $f->id("operation_id"),
                $this->change($request),
                $f->text("revision", 64),
            ),
        );
    }
    public function history(ServerRequest $request, string $student, string $award): ResponseInterface
    {
        return new JsonResponse(
            $this->service->history(
                new EntityId($student),
                new EntityId($award),
                FormInput::from($request)->optionalId("after"),
            ),
        );
    }
    public function receipt(string $student, string $award, string $operation): ResponseInterface
    {
        return new JsonResponse(
            $this->service->receipt(new EntityId($student), new EntityId($award), new EntityId($operation)),
        );
    }
    public function reviewPeriod(ServerRequest $request, string $student, string $award): ResponseInterface
    {
        $f = FormInput::from($request);
        $body = $request->getParsedBody();
        if (!is_array($body) || !array_key_exists("academic_on", $body)) {
            throw new \Domain\Shared\Exception\ValidationException([
                [
                    "field" => "academic_on",
                    "message" => "Enter the source period start or explicitly clear a known date.",
                ],
            ]);
        }
        return new JsonResponse(
            $this->periods->review(
                new EntityId($student),
                new EntityId($award),
                $f->optionalText("academic_on", 10),
                $f->integer("version", 0),
                $f->bodyText("reason"),
            ),
        );
    }
    public function commitPeriod(ServerRequest $request, string $student, string $award): ResponseInterface
    {
        return new JsonResponse(
            $this->periods->commit(
                new EntityId($student),
                new EntityId($award),
                FormInput::from($request)->text("review_token", 16384),
            ),
        );
    }
    public function periodHistory(ServerRequest $request, string $student, string $award): ResponseInterface
    {
        return new JsonResponse(
            $this->periods->history(
                new EntityId($student),
                new EntityId($award),
                FormInput::from($request)->optionalText("cursor", 16384),
            ),
        );
    }
    private function change(ServerRequest $request): AwardChange
    {
        $f = FormInput::from($request);
        return new AwardChange(
            $f->text("action", 12),
            $f->optionalInteger("credits_milli"),
            $f->optionalText("awarded_grade", 12),
            $f->text("reason", 10000),
            $f->optionalText("effective_on", 10),
        );
    }
}
