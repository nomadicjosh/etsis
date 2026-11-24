<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Admissions\Service\ApplicantApplicationHistoryService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class ApplicantApplicationHistoryController
{
    public function __construct(private ApplicantApplicationHistoryService $history)
    {
    }

    public function page(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->history->page(
            new EntityId($id),
            (new FormInput($request->getQueryParams()))->optionalText('cursor', 10000)
        ));
    }
}
