<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\Service\TransferCatalogService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisTransferCatalogController
{
    public function __construct(private TransferCatalogService $service)
    {
    }

    public function show(string $kind, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->view($kind, new EntityId($id)));
    }

    public function edit(ServerRequest $request, string $kind, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        if ($kind === 'equivalencies') {
            $this->service->editEquivalency(
                new EntityId($id),
                new DateRange($f->date('starts_on'), $f->optionalDate('ends_on')),
                $f->integer('minimum_grade_rank', 0, 10000),
                $f->integer('credits_milli', 1, 999999),
                $f->text('awarded_grade', 12),
                $f->text('revision', 64)
            );
        } else {
            $this->service->editLabel(
                $kind,
                new EntityId($id),
                $f->text('code', 80),
                $f->text($kind === 'institutions' ? 'name' : 'title'),
                $f->text('revision', 64)
            );
        }

        return new JsonResponse(['status' => 'saved']);
    }

    public function children(ServerRequest $request, string $kind, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->children(
            $kind,
            new EntityId($id),
            FormInput::from($request)->optionalId('after')
        ));
    }

    public function awards(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->awards(
            new EntityId($id),
            FormInput::from($request)->optionalId('after')
        ));
    }
}
