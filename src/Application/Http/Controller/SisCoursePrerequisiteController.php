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
        if (
            !array_key_exists('credit_origin', $body)
            || ($body['credit_origin'] !== null && !is_string($body['credit_origin']))
        ) {
            throw new \InvalidArgumentException(
                'Provide prerequisite coursework origin or explicitly use the default.'
            );
        }
        $f = FormInput::from($request);
        if (
            !array_key_exists('requirement_credit_origins', $body)
            || ($body['requirement_credit_origins'] !== null && !is_array($body['requirement_credit_origins']))
        ) {
            throw new \InvalidArgumentException('Provide requirement coursework sources or explicitly inherit all.');
        }
        if (
            !array_key_exists('completion_windows', $body)
            || ($body['completion_windows'] !== null && !is_array($body['completion_windows']))
        ) {
            throw new \InvalidArgumentException(\Codefy\Framework\Helpers\trans(
                'Provide completion windows or explicitly leave all requirements unrestricted.'
            ));
        }
        if (
            !array_key_exists('equivalents', $body)
            || ($body['equivalents'] !== null && !is_array($body['equivalents']))
        ) {
            throw new \InvalidArgumentException(\Codefy\Framework\Helpers\trans(
                'Provide prerequisite equivalents or explicitly choose none.'
            ));
        }
        return new JsonResponse($this->service->save(
            new EntityId($id),
            $f->values('groups', 100),
            $f->text('revision', 64),
            array_key_exists('minimums', $body) ? $f->values('minimums', 100) : null,
            array_key_exists('grade_points_milli', $body) ? $f->values('grade_points_milli', 100) : null,
            array_key_exists('corequisites', $body) ? $f->values('corequisites', 20) : null,
            $f->optionalInteger('minimum_requirements'),
            $body['rule'] ?? null,
            $body['credit_origin'],
            $body['requirement_credit_origins'],
            $body['completion_windows'],
            $body['equivalents'],
        ));
    }

    public function labels(#[\SensitiveParameter] ServerRequest $request): ResponseInterface
    {
        return new JsonResponse($this->service->labels(FormInput::from($request)->values('ids', 100)));
    }
}
