<?php

declare(strict_types=1);

namespace Application\Http\Security;

use Codefy\Framework\Auth\Repository\AuthUserRepository;
use Codefy\Framework\Http\Middleware\Auth\UserCookieDecryptMiddleware;
use Domain\Access\Repository\AccessRepository;
use Domain\User\Service\LoginSessionAuthority;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ServerRequestInterface;
use Qubus\Http\Session\SessionEntity;
use Qubus\Routing\Handlers\CallableRequestHandler;
use Throwable;

/** Verify the current enabled account and protected super assignment; cookie roles are never authority. */
final readonly class MaintenanceAccess
{
    public function __construct(
        private AuthUserRepository $users,
        private AccessRepository $access,
        private LoginCookieReader $cookies,
        private LoginSessionAuthority $sessions,
        private UserCookieDecryptMiddleware $decryption,
    ) {
    }

    public function permitsLogin(#[\SensitiveParameter] SessionEntity $session): bool
    {
        $token = $session->token ?? null;
        return is_string($token) && $token !== '' && $this->superAccount($token);
    }

    public function permitsRequest(ServerRequestInterface $request): bool
    {
        try {
            $response = $this->decryption->process($request, new CallableRequestHandler(
                function (ServerRequestInterface $decoded): EmptyResponse {
                    $proof = $this->cookies->read($decoded);
                    $token = $decoded->getAttribute(UserCookieDecryptMiddleware::USER_COOKIE);
                    $allowed = $proof !== null && is_string($token) && $token !== ''
                        && hash_equals($proof->tokenHash, hash('sha256', $token))
                        && $this->sessions->permits($proof) && $this->superAccount($token);
                    return new EmptyResponse($allowed ? 204 : 403);
                },
            ));
            return $response->getStatusCode() === 204;
        } catch (Throwable) {
            return false;
        }
    }

    private function superAccount(#[\SensitiveParameter] string $token): bool
    {
        try {
            // The framework repository excludes disabled accounts and resolves today's token, not a cookie claim.
            $user = $this->users->find($token);
            return is_object($user) && is_string($user->user_id ?? null)
            && $this->access->isSuper($user->user_id);
        } catch (Throwable) {
            return false;
        }
    }
}
