<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Import\Service\ImportQueueService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisImportQueueController
{
    public function __construct(private ImportQueueService $service)
    {
    }
    public function page(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse($this->service->page($this->after($request)));
    }
    public function status(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->status(new EntityId($id)));
    }
    public function cancel(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->cancel(new EntityId($id)));
    }
    public function notices(ServerRequest $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        return new JsonResponse(
            $this->service->notices(
                ($query["scope"] ?? "") !== "all",
                $this->after($request),
            ),
        );
    }
    public function read(ServerRequest $request): ResponseInterface
    {
        $this->service->readNotice(
            new EntityId(FormInput::from($request)->text("id", 26)),
        );
        return new JsonResponse(["saved" => true]);
    }
    public function remove(ServerRequest $request): ResponseInterface
    {
        $this->service->deleteNotice(
            new EntityId(FormInput::from($request)->text("id", 26)),
        );
        return new JsonResponse(["removed" => true]);
    }
    private function after(ServerRequest $request): ?EntityId
    {
        $after = $request->getQueryParams()["after"] ?? null;
        if ($after !== null && !is_string($after)) {
            throw new \InvalidArgumentException(\Codefy\Framework\Helpers\trans('Choose a valid import page.'));
        }
        return $after === null ? null : new EntityId($after);
    }
}
