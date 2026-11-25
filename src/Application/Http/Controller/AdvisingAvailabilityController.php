<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Advising\Service\AdvisingAvailabilityService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class AdvisingAvailabilityController
{
    public function __construct(private AdvisingAvailabilityService $service)
    {
    }

    public function candidates(#[\SensitiveParameter] ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->candidates($f->search('search'), $f->optionalId('after')));
    }

    public function staff(
        #[\SensitiveParameter] ServerRequest $request,
        string $host,
        string $action,
    ): ResponseInterface {
        return $this->dispatch($request, new EntityId($host), 'staff', $action);
    }

    public function mine(#[\SensitiveParameter] ServerRequest $request, string $action): ResponseInterface
    {
        if ($action === 'context') {
            return new JsonResponse(['can_manage' => $this->service->canManage()]);
        }
        return $this->dispatch($request, $this->service->mine(), 'advisor', $action);
    }

    private function dispatch(
        #[\SensitiveParameter] ServerRequest $request,
        EntityId $host,
        string $channel,
        string $action,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse(match ($action) {
            'list' => $this->service->listing($host, $channel, $f->boolean('finished'), $f->optionalId('after')),
            'create' => $this->service->create($host, $channel, $f->id('window_id'), $f->text('start', 16),
                $f->text('end', 16), $f->text('timezone', 80), $f->integer('slot_minutes'), $f->bodyText('message')),
            'close' => $this->service->close($host, $channel, $f->id('window_id'), $f->integer('version'),
                $f->bodyText('message')),
            default => throw new \Domain\Shared\Exception\RecordNotFoundException(
                \Codefy\Framework\Helpers\trans('Availability action not found.')
            ),
        });
    }
}
