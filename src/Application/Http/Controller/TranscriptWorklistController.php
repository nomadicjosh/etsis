<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transcript\Service\TranscriptWorklistService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

use function Codefy\Framework\Helpers\trans_html;

final readonly class TranscriptWorklistController
{
    public function __construct(private TranscriptWorklistService $service)
    {
    }
    public function page(ServerRequest $request): ResponseInterface
    {
        $f = new FormInput($request->getQueryParams());
        $scope = $f->search("scope", 16);
        $status = $f->search("status", 16);
        return new JsonResponse(
            $this->service->page(
                $scope === "" ? "all" : $scope,
                $status === "" ? "active" : $status,
                $f->search("search", 80),
                $f->optionalId("after"),
            ),
        );
    }
    public function assignees(ServerRequest $request): ResponseInterface
    {
        $f = new FormInput($request->getQueryParams());
        return new JsonResponse($this->service->assignees($f->search("search", 80), $f->optionalId("after")));
    }
    public function view(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->view(new EntityId($id)));
    }
    public function save(ServerRequest $request, string $id): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (
            !is_array($body) ||
            !array_key_exists("assignee_id", $body) ||
            !array_key_exists("assignee_person_id", $body)
        ) {
            throw new ValidationException([
                [
                    "field" => "assignee_id",
                    "message" => trans_html("Choose an eligible colleague or explicitly clear the assignment."),
                ],
            ]);
        }
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->save(
                new EntityId($id),
                $f->integer("version", 0, 2147483646),
                $f->integer("request_version", 0, 2147483647),
                $f->optionalId("assignee_id"),
                $f->optionalId("assignee_person_id"),
            ),
        );
    }
}
