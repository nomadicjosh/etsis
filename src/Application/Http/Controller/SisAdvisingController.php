<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Advising\Service\AdvisorService;
use Domain\DegreeAudit\Service\DegreeProgressReader;
use Domain\Portal\ValueObject\StudentDegreeProgress;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisAdvisingController
{
    public function __construct(private AdvisorService $service, private DegreeProgressReader $progress)
    {
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
        $this->requireRows($request);
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->review(
                new EntityId($student),
                new EntityId($pursuit),
                $f->values("rows", 50),
                $f->text("revision", 64),
                $f->bodyText("reason"),
            ),
        );
    }
    public function commit(
        #[\SensitiveParameter] ServerRequest $request,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        $this->requireRows($request);
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->commit(
                new EntityId($student),
                new EntityId($pursuit),
                $f->id("operation_id"),
                $f->values("rows", 50),
                $f->text("revision", 64),
                $f->bodyText("reason"),
                $f->bodyText("review_token"),
            ),
        );
    }
    public function candidates(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->candidates($f->search("search", 80), $f->optionalId("after")),
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
    public function owner(string $student, string $pursuit): ResponseInterface
    {
        return new JsonResponse($this->service->ownerView(new EntityId($student), new EntityId($pursuit)));
    }
    public function mine(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->mine($f->search("search", 80), $f->optionalId("after")));
    }
    public function progress(string $student, string $pursuit): ResponseInterface
    {
        $s = new EntityId($student);
        $p = new EntityId($pursuit);
        $actor = $this->service->requireAdvisor($s, $p);
        $result = new StudentDegreeProgress($this->progress->student($s, $p))->describe();
        if ($actor->value !== $this->service->requireAdvisor($s, $p)->value) {
            throw new \Domain\Shared\Exception\AccessDeniedException("The advising reader changed.");
        }
        $this->service->recordProgressRead($s, $p, $actor);
        return new JsonResponse($result);
    }
    private function requireRows(#[\SensitiveParameter] ServerRequest $request): void
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || !array_key_exists("rows", $body)) {
            throw new \InvalidArgumentException(
                "Provide advisor assignments, including an explicit empty list to clear them.",
            );
        }
    }
}
