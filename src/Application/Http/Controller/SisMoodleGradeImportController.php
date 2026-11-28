<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Lms\Service\MoodleGradeImportService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisMoodleGradeImportController
{
    public function __construct(private MoodleGradeImportService $service)
    {
    }

    public function page(
        ServerRequest $request,
        string $section,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->page(
                new EntityId($section),
                FormInput::from($request)->optionalId("after"),
            ),
        );
    }

    public function request(
        ServerRequest $request,
        string $section,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->request(
                new EntityId($section),
                $f->id("request_id"),
                $f->integer("revision", 0),
                $f->bodyText("reason"),
            ),
        );
    }

    public function cancel(
        ServerRequest $request,
        string $section,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->cancel(
                new EntityId($section),
                FormInput::from($request)->integer("revision", 1),
            ),
        );
    }

    public function show(
        ServerRequest $request,
        string $section,
        string $candidate,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->view(
                new EntityId($section),
                new EntityId($candidate),
                new FormInput($request->getQueryParams())->optionalId(
                    "assignment_id",
                ),
            ),
        );
    }

    public function review(
        ServerRequest $request,
        string $section,
        string $candidate,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->review(
                new EntityId($section),
                new EntityId($candidate),
                $f->integer("revision", 1),
                $f->text("action", 12),
                $f->optionalId("grade_id"),
                $f->bodyText("reason"),
                $f->optionalText("effective_on", 10),
                $f->optionalId("assignment_id"),
            ),
        );
    }

    public function confirm(
        ServerRequest $request,
        string $section,
        string $candidate,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->confirm(
                new EntityId($section),
                new EntityId($candidate),
                FormInput::from($request)->text(
                    "review_token",
                    MoodleGradeImportService::REVIEW_TOKEN_LIMIT,
                ),
            ),
        );
    }

    public function notices(ServerRequest $request): ResponseInterface
    {
        $f =
        $request->getMethod() === "GET"
        ? new FormInput($request->getQueryParams())
        : FormInput::from($request);
        return new JsonResponse(
            $this->service->notices(
                !($f->optionalBoolean("include_read") ?? false),
                $f->optionalId("after"),
            ),
        );
    }

    public function read(
        ServerRequest $request,
        string $notice,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->notice(
                new EntityId($notice),
                FormInput::from($request)->integer("revision", 1),
                false,
            ),
        );
    }

    public function delete(
        ServerRequest $request,
        string $notice,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->notice(
                new EntityId($notice),
                FormInput::from($request)->integer("revision", 1),
                true,
            ),
        );
    }
}
