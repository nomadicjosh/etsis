<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Finance\Service\FinancialReviewService;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\PostingReference;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisFinancialReviewController
{
    public function __construct(private FinancialReviewService $service)
    {
    }

    public function request(string $student, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->request(new EntityId($student), new EntityId($id)), 201);
    }

    public function progress(string $student, string $id): ResponseInterface
    {
        return new JsonResponse(["review" => $this->service->progress(new EntityId($student), new EntityId($id))]);
    }

    public function page(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->page($f->optionalText("status", 24), $f->optionalId("after")));
    }

    public function show(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->view(new EntityId($id)));
    }

    public function change(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $body = $request->getParsedBody();
        $postings = is_array($body) ? $body["postings"] ?? [] : [];
        if (!is_array($postings) || !array_is_list($postings) || count($postings) > 10) {
            throw new ValidationException([
                ["field" => "postings", "message" => "Link at most ten existing entries."],
            ]);
        }
        $references = [];
        if ($postings !== []) {
            $references = array_map(
                static fn(FormInput $p): PostingReference => new PostingReference(
                    new AccountId($p->id("account_id")->value),
                    $p->id("operation_id"),
                    $p->integer("position", 1),
                ),
                $f->items("postings", 10),
            );
        }
        return new JsonResponse(
            $this->service->change(
                new EntityId($id),
                $f->id("operation_id"),
                $f->integer("version"),
                $f->text("action", 24),
                $f->bodyText("note"),
                $references,
            ),
        );
    }

    public function accounts(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->accounts(new EntityId($id), FormInput::from($request)->optionalId("after")),
        );
    }

    public function history(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->history(new EntityId($id), FormInput::from($request)->optionalId("after")),
        );
    }
}
