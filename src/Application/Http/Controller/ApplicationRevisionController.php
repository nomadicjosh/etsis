<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Admissions\Service\ApplicationRevisionService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class ApplicationRevisionController
{
    public function __construct(private ApplicationRevisionService $service)
    {
    }
    public function current(
        ServerRequest $request,
        string $id,
    ): ResponseInterface {
        return $this->view($request, $id, false);
    }
    public function mine(ServerRequest $request, string $id): ResponseInterface
    {
        return $this->view($request, $id, true);
    }
    private function view(
        ServerRequest $request,
        string $id,
        bool $mine,
    ): ResponseInterface {
        $q = $request->getQueryParams();
        return new JsonResponse(
            $this->service->current(
                new EntityId($id),
                $mine,
                new FormInput($q)->optionalId("revision_id"),
                ($q["original"] ?? null) === "1",
            ),
        );
    }
    public function review(
        #[\SensitiveParameter] ServerRequest $request,
        string $id,
    ): ResponseInterface {
        return $this->preview($request, $id, false);
    }
    public function reviewMine(
        #[\SensitiveParameter] ServerRequest $request,
        string $id,
    ): ResponseInterface {
        return $this->preview($request, $id, true);
    }
    private function preview(
        #[\SensitiveParameter] ServerRequest $request,
        string $id,
        bool $mine,
    ): ResponseInterface {
        $f = FormInput::from($request);
        $body = $request->getParsedBody();
        return new JsonResponse(
            $this->service->review(
                new EntityId($id),
                $f->text("action", 10),
                [
                    "answers" => $body["answers"] ?? null,
                    "reason" => $body["reason"] ?? null,
                    "instructions" => $body["instructions"] ?? null,
                ],
                $mine,
            ),
        );
    }
    public function commit(
        #[\SensitiveParameter] ServerRequest $request,
        string $id,
    ): ResponseInterface {
        return $this->confirm($request, $id, false);
    }
    public function commitMine(
        #[\SensitiveParameter] ServerRequest $request,
        string $id,
    ): ResponseInterface {
        return $this->confirm($request, $id, true);
    }
    private function confirm(
        #[\SensitiveParameter] ServerRequest $request,
        string $id,
        bool $mine,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->commit(
                new EntityId($id),
                FormInput::from($request)->text("review_token", 1000000),
                $mine,
            ),
        );
    }
}
