<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Communications\Service\CampaignService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisCampaignController
{
    public function __construct(private CampaignService $service)
    {
    }

    public function create(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $campaign = $this->service->create($f->text('subject'), $f->bodyText('body'));

        return new JsonResponse(['id' => $campaign->id->value, 'status' => $campaign->status], 201);
    }

    public function edit(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->edit(new EntityId($id), $f->text('subject'), $f->bodyText('body'), $f->integer('version'));

        return new JsonResponse(['status' => 'saved']);
    }

    public function show(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->show(new EntityId($id)));
    }

    public function queue(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $deliveries = $this->service->queue(new EntityId($id), $this->recipients($f), $f->integer('version'));

        return new JsonResponse(['delivery_ids' => array_map(
            static fn (EntityId $d): string => $d->value,
            $deliveries
        )]);
    }

    public function recipientChoices(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->recipientChoices($f->search('search', 80), $f->optionalId('after')));
    }

    public function reviewRecipients(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->reviewRecipients(
            new EntityId($id),
            $this->recipients($f),
            $f->integer('version')
        ));
    }

    /** @return list<EntityId> */
    private function recipients(FormInput $f): array
    {
        return array_map(static fn(FormInput $r): EntityId => $r->id('user_id'), $f->items('recipients'));
    }

    public function pause(ServerRequest $request, string $id): ResponseInterface
    {
        $this->service->pause(new EntityId($id), FormInput::from($request)->boolean('pause', true));

        return new JsonResponse(['status' => 'changed']);
    }

    public function dispatch(string $id): ResponseInterface
    {
        $this->service->dispatch(new EntityId($id));

        return new JsonResponse(['status' => 'processed']);
    }
}
