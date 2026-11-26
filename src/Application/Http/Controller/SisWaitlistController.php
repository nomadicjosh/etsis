<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Registration\Waitlist\WaitlistService;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisWaitlistController
{
    public function __construct(private WaitlistService $service)
    {
    }
    public function policy(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->policy(new EntityId($id)));
    }
    public function reviewPolicy(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->reviewPolicy(
                new EntityId($id),
                $this->policyBody($request),
                $f->integer("version"),
                $f->bodyText("reason"),
            ),
        );
    }
    public function confirmPolicy(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->confirmPolicy(
                new EntityId($id),
                $f->id("operation_id"),
                $this->policyBody($request),
                $f->integer("version"),
                $f->bodyText("reason"),
                $f->text("review_token", 8192),
            ),
        );
    }
    public function listing(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->listing(
                new EntityId($id),
                new FormInput($request->getQueryParams())->optionalInteger("after") ?? 0,
            ),
        );
    }
    public function staffView(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->view(
                FormInput::from($request)->id("student_id"),
                new EntityId($id),
                true,
            ),
        );
    }
    public function staffReview(ServerRequest $request, string $id): ResponseInterface
    {
        return $this->review($request, $id, null);
    }
    public function staffConfirm(ServerRequest $request, string $id): ResponseInterface
    {
        return $this->confirm($request, $id, null);
    }
    public function ownerView(string $student, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->view(new EntityId($student), new EntityId($id), false),
        );
    }
    public function ownerReview(
        ServerRequest $request,
        string $student,
        string $id,
    ): ResponseInterface {
        return $this->review($request, $id, $student);
    }
    public function ownerConfirm(
        ServerRequest $request,
        string $student,
        string $id,
    ): ResponseInterface {
        return $this->confirm($request, $id, $student);
    }
    public function mine(ServerRequest $request, string $student): ResponseInterface
    {
        return new JsonResponse(
            $this->service->mine(
                new EntityId($student),
                new FormInput($request->getQueryParams())->optionalId("after"),
            ),
        );
    }
    private function review(
        ServerRequest $request,
        string $section,
        ?string $student,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->review(
                $student === null ? $f->id("student_id") : new EntityId($student),
                new EntityId($section),
                $f->text("action", 12),
                $student === null,
                $f->requiredNullableId("entry_id"),
                $this->reason($request),
                $f->optionalInteger("credits_milli"),
            ),
        );
    }
    private function confirm(
        ServerRequest $request,
        string $section,
        ?string $student,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->confirm(
                $student === null ? $f->id("student_id") : new EntityId($student),
                new EntityId($section),
                $f->id("operation_id"),
                $f->text("action", 12),
                $student === null,
                $f->text("revision", 64),
                $f->text("review_token", 8192),
                $this->reason($request),
                $f->optionalInteger("credits_milli"),
            ),
        );
    }
    private function reason(ServerRequest $request): ?string
    {
        $body = $request->getParsedBody();
        return is_array($body) && isset($body["reason"]) && $body["reason"] !== ""
        ? FormInput::from($request)->bodyText("reason")
        : null;
    }
    private function policyBody(ServerRequest $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_array($body["policy"] ?? null)) {
            throw new ValidationException([
                ["field" => "policy", "message" => 'Provide this section\'s waitlist policy.'],
            ]);
        }
        return $body["policy"];
    }
}
