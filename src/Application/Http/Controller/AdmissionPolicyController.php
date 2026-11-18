<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Admissions\Service\AdmissionPolicyService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class AdmissionPolicyController
{
    public function __construct(private AdmissionPolicyService $service)
    {
    }
    public function current(): ResponseInterface
    {
        return new JsonResponse($this->service->current());
    }
    public function save(#[\SensitiveParameter] ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $area = $f->text('area', 20);
        return new JsonResponse($this->service->save(
            $area,
            $area === 'offers' ? $f->requiredBoolean('require_acceptance') : $f->text('mode', 20),
            $f->integer('revision')
        ));
    }
}
