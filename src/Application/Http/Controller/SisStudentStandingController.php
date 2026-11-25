<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\StudentStandingService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisStudentStandingController
{
    public function __construct(private StudentStandingService $service)
    {
    }

    public function definitions(ServerRequest $request): ResponseInterface
    {
        return $this->page($request, true);
    }

    public function choices(ServerRequest $request): ResponseInterface
    {
        return $this->page($request, false);
    }

    private function page(ServerRequest $request, bool $setup): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->definitions($f->search("search", 80), $f->optionalId("after"), $setup),
        );
    }

    public function save(#[\SensitiveParameter] ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->saveDefinition(
                $f->optionalId("id"),
                $f->text("code", 32),
                $f->text("title", 120),
                $f->requiredBoolean("active"),
                $f->optionalInteger("version"),
            ),
        );
    }

    public function view(string $student, string $pursuit): ResponseInterface
    {
        return new JsonResponse($this->service->view(new EntityId($student), new EntityId($pursuit)));
    }

    public function review(
        #[\SensitiveParameter] ServerRequest $request,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        $f = $this->input($request);
        return new JsonResponse(
            $this->service->review(
                new EntityId($student),
                new EntityId($pursuit),
                $f->values("rows", 50),
                $f->text("revision", 64),
                $f->bodyText("reason"),
                $f->boolean("historical_evidence"),
                $this->proposal($request),
            ),
        );
    }

    public function commit(
        #[\SensitiveParameter] ServerRequest $request,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        $f = $this->input($request);
        return new JsonResponse(
            $this->service->commit(
                new EntityId($student),
                new EntityId($pursuit),
                $f->id("operation_id"),
                $f->values("rows", 50),
                $f->text("revision", 64),
                $f->bodyText("reason"),
                $f->bodyText("review_token"),
                $f->boolean("historical_evidence"),
                $this->proposal($request),
            ),
        );
    }

    public function owner(string $student, string $pursuit): ResponseInterface
    {
        return new JsonResponse($this->service->read(new EntityId($student), new EntityId($pursuit)));
    }

    public function advisor(string $student, string $pursuit): ResponseInterface
    {
        return new JsonResponse(
            $this->service->read(new EntityId($student), new EntityId($pursuit), advisor: true),
        );
    }

    public function timeline(ServerRequest $request, string $student, string $pursuit): ResponseInterface
    {
        return new JsonResponse(
            $this->service->timeline(
                new EntityId($student),
                new EntityId($pursuit),
                FormInput::from($request)->optionalText("cursor", 8192),
            ),
        );
    }

    public function propose(string $student, string $pursuit): ResponseInterface
    {
        return new JsonResponse($this->service->propose(new EntityId($student), new EntityId($pursuit)));
    }

    private function proposal(#[\SensitiveParameter] ServerRequest $request): ?array
    {
        $body = $request->getParsedBody();
        $proposal = $body['proposal'] ?? null;
        if ($proposal !== null && !is_array($proposal)) {
            throw new \InvalidArgumentException(\Codefy\Framework\Helpers\trans_html('Use a valid standing proposal.'));
        }
        return $proposal;
    }

    private function input(#[\SensitiveParameter] ServerRequest $request): FormInput
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || !array_key_exists("rows", $body)) {
            throw new \Domain\Shared\Exception\ValidationException([
                [
                    "field" => "rows",
                    "message" =>
                        "Provide the standing periods, including an explicit empty list to clear them.",
                ],
            ]);
        }
        return FormInput::from($request);
    }
}
