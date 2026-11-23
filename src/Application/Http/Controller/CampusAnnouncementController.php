<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Portal\Service\CampusAnnouncementService;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class CampusAnnouncementController
{
    public function __construct(private CampusAnnouncementService $service)
    {
    }

    public function page(ServerRequest $request): ResponseInterface
    {
        $input = new FormInput($request->getQueryParams());
        $include = $input->search('include_read', 1);
        if (!in_array($include, ['', '0', '1'], true)) {
            throw new InvalidArgumentException('Choose whether to include read announcements.');
        }
        return new JsonResponse($this->service->page($include !== '1', $input->optionalId('after')));
    }

    public function detail(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->detail(new EntityId($id)));
    }

    public function read(ServerRequest $request, string $id): ResponseInterface
    {
        $input = FormInput::from($request);
        return new JsonResponse($this->service->read(new EntityId($id), $input->integer('revision', 0, 2147483646)));
    }
}
