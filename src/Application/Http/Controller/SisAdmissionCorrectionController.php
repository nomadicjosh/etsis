<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Admissions\Service\AdmissionCorrectionService;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisAdmissionCorrectionController
{
    public function __construct(private AdmissionCorrectionService $service)
    {
    }
    public function review(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $decision = $f->text("decision", 10);
        if (!in_array($decision, ["accept", "reject"], true)) {
            throw new ValidationException([
                ["field" => "decision", "message" => "Choose acceptance or rejection."],
            ]);
        }
        return new JsonResponse(
            $this->service->review(new EntityId($id), $decision === "accept", $f->bodyText("reason")),
        );
    }
    public function commit(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->commit(
                new EntityId($id),
                FormInput::from($request)->text("review_token", 100000),
            ),
        );
    }
}
