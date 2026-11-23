<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Registration\Waitlist\WaitlistBypass;
use Domain\Registration\Waitlist\WaitlistBypassService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisWaitlistBypassController
{
    public function __construct(private WaitlistBypassService $service)
    {
    }

    public function history(ServerRequest $request, string $id): ResponseInterface
    {
        $f = new FormInput($request->getQueryParams());
        return new JsonResponse($this->service->history(
            new EntityId($id),
            $f->optionalInteger('before'),
            $f->optionalInteger('through'),
        ));
    }

    public function review(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->review(
            $f->id('student_id'),
            new EntityId($id),
            $f->id('entry_id'),
            $f->bodyText('reason'),
        ));
    }

    public function confirm(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->confirm(new WaitlistBypass(
            new EntityId($id),
            $f->id('student_id'),
            $f->id('person_id'),
            $f->id('entry_id'),
            $f->id('operation_id'),
            $f->integer('version'),
            $f->text('assessment_revision', 64),
            $f->bodyText('reason'),
            $f->text('review_token', 8192),
        )));
    }
}
