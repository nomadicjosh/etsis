<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Restriction;
use Domain\Student\Service\RestrictionService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisRestrictionController
{
    public function __construct(private RestrictionService $service)
    {
    }

    public function create(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $restriction = new Restriction(
            $f->id('operation_id'),
            $f->id('person_id'),
            $f->text('code', 22),
            $f->integer('severity', 0, 1000),
            new DateRange($f->date('starts_on'), $f->optionalDate('ends_on')),
            $f->requiredBoolean('blocks_staff')
        );
        $this->service->create($restriction, $f->bodyText('note'));

        return new JsonResponse(['id' => $restriction->id->value], 201);
    }

    public function show(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->view(new EntityId($id)));
    }

    public function page(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->page(
            new EntityId($id),
            FormInput::from($request)->optionalId('after')
        ));
    }

    public function end(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->end(new EntityId($id), $f->date('ends_on'), $f->text('revision', 64));

        return new JsonResponse(['status' => 'saved']);
    }

    public function notes(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->notes(
            new EntityId($id),
            FormInput::from($request)->optionalId('after')
        ));
    }

    public function appendNote(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->appendNote(new EntityId($id), $f->id('operation_id'), $f->bodyText('note'));

        return new JsonResponse(['status' => 'saved']);
    }
}
