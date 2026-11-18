<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Registration\Service\RegistrationSchedulePolicyService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisRegistrationPolicyController
{
    public function __construct(
        private RegistrationSchedulePolicyService $service,
    ) {
    }
    public function current(): ResponseInterface
    {
        return new JsonResponse($this->service->current());
    }
    public function save(
        #[\SensitiveParameter] ServerRequest $request,
    ): ResponseInterface {
        $input = FormInput::from($request);
        return new JsonResponse(
            $this->service->save(
                $input->text("mode", 8),
                $input->integer("revision", 0, 2000000000),
            ),
        );
    }
}
