<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Catalog\Service\CoursePrerequisiteService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisCoursePrerequisiteController
{
    public function __construct(private CoursePrerequisiteService $service)
    {
    }

    public function current(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->current(new EntityId($id)));
    }

    public function save(#[\SensitiveParameter] ServerRequest $request, string $id): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || !array_key_exists('groups', $body)) {
            throw new \InvalidArgumentException(
                'Provide all prerequisite choices, including an empty list to clear them.'
            );
        }
        if (array_key_exists('corequisites', $body) && !is_array($body['corequisites'])) {
            throw new \InvalidArgumentException('Provide a co-requisite course list.');
        }
        if (isset($body['rule']) && !is_array($body['rule'])) {
            throw new \InvalidArgumentException('Provide a nested prerequisite rule or clear it explicitly.');
        }
        $f = FormInput::from($request);
        return new JsonResponse($this->service->save(
            new EntityId($id),
            $f->values('groups', 100),
            $f->text('revision', 64),
            array_key_exists('minimums', $body) ? $f->values('minimums', 100) : null,
            array_key_exists('grade_points_milli', $body) ? $f->values('grade_points_milli', 100) : null,
            array_key_exists('corequisites', $body) ? $f->values('corequisites', 20) : null,
            $f->optionalInteger('minimum_requirements'),
            $body['rule'] ?? null
        ));
    }

    public function labels(#[\SensitiveParameter] ServerRequest $request): ResponseInterface
    {
        return new JsonResponse($this->service->labels(FormInput::from($request)->values('ids', 100)));
    }
}
