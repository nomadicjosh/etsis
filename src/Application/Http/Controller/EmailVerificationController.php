<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Application\View\CampusRenderer;
use Domain\User\Service\EmailVerificationService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

use function Codefy\Framework\Helpers\trans;

final readonly class EmailVerificationController
{
    public function __construct(private EmailVerificationService $service, private CampusRenderer $renderer)
    {
    }

    public function page(): ResponseInterface
    {
        return $this->renderer->render('email-verification', trans('Verify your sign-in email'))
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; "
                . "style-src 'self' 'unsafe-inline'; "
                . "img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; "
                . "form-action 'self'; base-uri 'none'; object-src 'none'");
    }

    public function status(): ResponseInterface
    {
        return new JsonResponse($this->service->status());
    }

    public function request(#[\SensitiveParameter] ServerRequest $request): ResponseInterface
    {
        return new JsonResponse($this->service->request(FormInput::from($request)->integer('version', 0, 2147483646)));
    }

    public function confirm(#[\SensitiveParameter] ServerRequest $request): ResponseInterface
    {
        return new JsonResponse($this->service->confirm(FormInput::from($request)->text('token', 64)));
    }
}
