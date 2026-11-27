<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Registration\Service\CourseAuditingPolicyService;
use Domain\Registration\ValueObject\CourseAuditing;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class CourseAuditingPolicyController
{
    public function __construct(private CourseAuditingPolicyService $service)
    {
    }

    public function current(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->current(new EntityId($id)));
    }

    public function save(ServerRequest $request, string $id): ResponseInterface
    {
        $form = FormInput::from($request);
        $policy = $form->boolean('allowed') ? new CourseAuditing(
            $form->integer('fee_minor', 0, \Domain\Finance\ValueObject\Money::MAX_MINOR),
            $form->boolean('self_service'),
        ) : null;
        return new JsonResponse($this->service->save(new EntityId($id), $policy, $form->text('revision', 64)));
    }
}
