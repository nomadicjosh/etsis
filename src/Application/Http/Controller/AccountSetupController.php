<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\View\CampusRenderer;
use Domain\Person\Service\AccountSetupService;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class AccountSetupController
{
    public function __construct(private CampusRenderer $renderer, private AccountSetupService $setups)
    {
    }
    public function show(): ResponseInterface
    {
        return $this->page(false);
    }
    public function status(string $id): ResponseInterface
    {
        return new JsonResponse($this->setups->status(new EntityId($id)));
    }
    public function reissue(string $id): ResponseInterface
    {
        $this->setups->reissue(new EntityId($id));
        return new JsonResponse(['status' => 'queued']);
    }
    public function complete(ServerRequest $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $error = null;
        try {
            if (
                !is_array($body) ||
                !is_string($body['token'] ?? null) ||
                !is_string($body['password'] ?? null) ||
                !is_string($body['confirm_password'] ?? null) ||
                !hash_equals($body['password'], $body['confirm_password'])
            ) {
                throw new \DomainException();
            }
            $this->setups->complete($body['token'], $body['password']);
        } catch (ValidationException $e) {
            $error = $e->errors[0]['message'];
        } catch (\DomainException) {
            $error = 'This link is unavailable or the passwords do not match. '
            . 'Contact the institution for a new setup link.';
        }
        return $this->page($error === null, $error);
    }
    private function page(bool $completed, ?string $error = null): ResponseInterface
    {
        return $this->renderer->render(
            'account-setup',
            'Set your campus password',
            ['completed' => $completed, 'error' => $error]
        )
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
                . "img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; "
                . "frame-ancestors 'none'; base-uri 'none'; object-src 'none'"
            );
    }
}
