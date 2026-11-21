<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Registration\Service\PrerequisitePermissionService;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisPrerequisitePermissionController
{
    public function __construct(private PrerequisitePermissionService $permissions)
    {
    }

    public function sections(ServerRequest $request, string $student, string $pursuit): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->permissions->sections(
                new EntityId($student),
                new EntityId($pursuit),
                $f->search("search", 80),
                $f->optionalId("after"),
            ),
        );
    }

    public function view(
        ServerRequest $request,
        string $student,
        string $pursuit,
        string $section,
    ): ResponseInterface {
        return new JsonResponse(
            $this->permissions->view(new EntityId($student), new EntityId($pursuit), new EntityId($section)),
        );
    }

    public function review(
        #[\SensitiveParameter] ServerRequest $request,
        string $student,
        string $pursuit,
        string $section,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->permissions->review(
                new EntityId($student),
                new EntityId($pursuit),
                new EntityId($section),
                $this->explicit($request, "expires_at", "string"),
                $f->text("revision", 64),
                $f->bodyText("reason"),
            ),
        );
    }

    public function commit(
        #[\SensitiveParameter] ServerRequest $request,
        string $student,
        string $pursuit,
        string $section,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->permissions->commit(
                new EntityId($student),
                new EntityId($pursuit),
                new EntityId($section),
                $f->id("stream_id"),
                $f->id("operation_id"),
                $this->explicit($request, "approval", "array"),
                $f->text("revision", 64),
                $f->bodyText("reason"),
                $f->bodyText("review_token"),
            ),
        );
    }

    public function history(
        ServerRequest $request,
        string $student,
        string $pursuit,
        string $section,
    ): ResponseInterface {
        return new JsonResponse(
            $this->permissions->timeline(
                new EntityId($student),
                new EntityId($pursuit),
                new EntityId($section),
                FormInput::from($request)->optionalText("cursor", 8192),
            ),
        );
    }

    private function explicit(#[\SensitiveParameter] ServerRequest $request, string $key, string $type): mixed
    {
        $body = $request->getParsedBody();
        if (
            !is_array($body) ||
            !array_key_exists($key, $body) ||
            ($body[$key] !== null && get_debug_type($body[$key]) !== $type)
        ) {
            throw new ValidationException([
                [
                    "field" => $key,
                    "message" => "Provide the reviewed choice explicitly; use null only to revoke.",
                ],
            ]);
        }
        return $body[$key];
    }
}
