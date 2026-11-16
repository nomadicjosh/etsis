<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Communications\Service\CampaignService;
use Domain\Communications\Service\CampaignBackgroundService;
use Domain\Communications\Enum\TemplateLibraryScope;
use Domain\Communications\Enum\DeliveryReviewDecision;
use InvalidArgumentException;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisCampaignController
{
    public function __construct(private CampaignService $service, private CampaignBackgroundService $background)
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

    public function createTemplate(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $template = $this->service->createTemplate($f->text('subject'), $f->bodyText('body'));
        return new JsonResponse(['id' => $template->id->value, 'status' => $template->status], 201);
    }

    public function templates(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $scope = TemplateLibraryScope::tryFrom($f->optionalText('scope', 20) ?? 'mine')
        ?? throw new InvalidArgumentException('Choose a template library.');
        return new JsonResponse($this->service->templates($scope, $f->optionalId('after')));
    }

    public function reviewTemplate(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->reviewTemplate(new EntityId($id), $f->integer('version'), $f->text('action', 8));
        return new JsonResponse(['status' => 'saved']);
    }

    public function draftFromTemplate(ServerRequest $request, string $id): ResponseInterface
    {
        $draft = $this->service->draftFromTemplate(new EntityId($id), FormInput::from($request)->integer('version'));
        return new JsonResponse(['id' => $draft->id->value, 'status' => $draft->status], 201);
    }

    public function retireTemplate(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->retireTemplate(new EntityId($id), $f->integer('version'), $f->requiredBoolean('retired'));
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

    public function deliverySetup(string $id): ResponseInterface
    {
        return new JsonResponse($this->background->setup(new EntityId($id)));
    }

    public function deliveryReview(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->background->review(
            new EntityId($id),
            $f->optionalText('send_at', 16),
            $f->optionalText('timezone', 80)
        ));
    }

    public function deliveryConfirm(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->background->confirm(
            new EntityId($id),
            FormInput::from($request)->text('review_token', 262144)
        ), 202);
    }

    public function deliveryProgress(string $id): ResponseInterface
    {
        return new JsonResponse($this->background->status(new EntityId($id)));
    }

    public function deliveryCancel(ServerRequest $request, string $id): ResponseInterface
    {
        $this->background->cancel(new EntityId($id), FormInput::from($request)->integer('version'));
        return new JsonResponse(['status' => 'cancelled']);
    }

    public function deliveryDecision(string $id, string $delivery): ResponseInterface
    {
        return new JsonResponse($this->background->deliveryReviewState(new EntityId($id), new EntityId($delivery)));
    }

    public function reviewDeliveryDecision(ServerRequest $request, string $id, string $delivery): ResponseInterface
    {
        $f = FormInput::from($request);
        $decision = DeliveryReviewDecision::tryFrom($f->text('decision', 16))
        ?? throw new InvalidArgumentException('Choose a delivery review decision.');
        return new JsonResponse($this->background->reviewDeliveryDecision(
            new EntityId($id),
            new EntityId($delivery),
            $decision,
            $f->bodyText('reason'),
            $f->optionalText('evidence', 191)
        ));
    }

    public function confirmDeliveryDecision(ServerRequest $request, string $id, string $delivery): ResponseInterface
    {
        return new JsonResponse($this->background->confirmDeliveryDecision(
            new EntityId($id),
            new EntityId($delivery),
            FormInput::from($request)->text('review_token', 65536)
        ));
    }
}
