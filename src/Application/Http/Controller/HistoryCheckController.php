<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Shared\ValueObject\EntityId;
use Domain\Support\Enum\HistoryCheckSubject;
use Domain\Support\Service\HistoryCheckService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class HistoryCheckController
{
    public function __construct(private HistoryCheckService $checks)
    {
    }
    public function run(ServerRequest $request, string $kind, string $id): ResponseInterface
    {
        return new JsonResponse($this->checks->run($this->kind($kind), new EntityId($id)));
    }
    public function history(ServerRequest $request, string $kind, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->checks->history(
                $this->kind($kind),
                new EntityId($id),
                FormInput::from($request)->optionalId("after"),
            ),
        );
    }
    private function kind(string $kind): HistoryCheckSubject
    {
        return HistoryCheckSubject::tryFrom($kind) ??
        throw new \InvalidArgumentException("Choose a supported selected-record history check.");
    }
}
