<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Payments\Service\GatewaySetup;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisPaymentGatewayController
{
    public function __construct(private GatewaySetup $service)
    {
    }

    private function response(array $data): ResponseInterface
    {
        return new JsonResponse($data, 200, ['Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff']);
    }

    public function directory(ServerRequest $request, string $kind): ResponseInterface
    {
        $f = new FormInput($request->getQueryParams());
        return $this->response($this->service->directory($kind, $f->optionalId('after')));
    }

    public function show(string $id): ResponseInterface
    {
        return $this->response($this->service->show(new EntityId($id)));
    }

    public function routing(ServerRequest $request): ResponseInterface
    {
        $f = new FormInput($request->getQueryParams());
        return $this->response($this->service->routing($f->text('purpose', 16), $f->text('currency', 3)));
    }

    public function assets(ServerRequest $request): ResponseInterface
    {
        $f = new FormInput($request->getQueryParams());
        return $this->response($this->service->assets($f->text('currency', 3), $f->optionalId('after')));
    }

    public function review(ServerRequest $request, string $kind): ResponseInterface
    {
        $f = FormInput::from($request);
        $data = match ($kind) {
            'connection' => ['id' => $f->optionalId('id')?->value, 'version' => $f->integer('version', 0),
                'name' => $f->text('name', 120), 'enabled' => $f->boolean('enabled'), 'live' => $f->boolean('live'),
                'currency' => $f->text('currency', 3), 'asset_id' => $f->id('asset_id')->value,
                'secret' => $f->search('secret', 512), 'webhook' => $f->search('webhook', 512)],
            'route' => ['purpose' => $f->text('purpose', 16), 'currency' => $f->text('currency', 3),
                'connection_id' => $f->requiredNullableId('connection_id')?->value,
                'version' => $f->integer('version', 0)],
            'asset' => ['code' => $f->text('code', 80), 'title' => $f->text('title'),
                'currency' => $f->text('currency', 3)],
            'classification' => ['id' => $f->id('id')->value, 'kind' => $f->text('kind', 16)],
            default => throw new \InvalidArgumentException(),
        };
        return $this->response($this->service->review($kind, $data, $f->bodyText('reason')));
    }

    public function confirm(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return $this->response($this->service->confirm($f->id('review_id'), $f->text('review_token', 8192)));
    }
}
