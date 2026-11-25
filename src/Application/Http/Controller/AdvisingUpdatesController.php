<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Advising\Service\AdvisingUpdatesService;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class AdvisingUpdatesController
{
    public function __construct(private AdvisingUpdatesService $service)
    {
    }
    public function page(ServerRequest $request): ResponseInterface
    {
        $f = new FormInput($request->getQueryParams());
        $include = $f->search("include_read", 1);
        if (!in_array($include, ["", "0", "1"], true)) {
            throw new InvalidArgumentException(
                \Codefy\Framework\Helpers\trans('Choose whether to include read advising updates.'),
            );
        }
        return new JsonResponse(
            $this->service->page(
                $include !== "1",
                $f->optionalText("after_at", 27),
                $f->optionalId("after"),
            ),
        );
    }
    public function delete(
        ServerRequest $request,
        string $id,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->delete(
                new EntityId($id),
                FormInput::from($request)->integer("revision", 0, 2),
            ),
        );
    }
    public function read(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->read(
                new EntityId($id),
                FormInput::from($request)->integer("revision", 0, 2),
            ),
        );
    }
}
