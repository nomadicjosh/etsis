<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use Codefy\Framework\Auth\Gate;
use Codefy\Framework\Auth\Repository\AuthUserRepository;
use Codefy\Framework\Http\Middleware\Auth\UserAuthorizationMiddleware;
use Codefy\Framework\Http\Middleware\Auth\UserCookieDecryptMiddleware;
use Codefy\Framework\Http\RequestContext;
use Domain\Access\Repository\AccessRepository;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionException;

final class UserAuth implements Gate
{
    public function __construct(protected AccessRepository $access, protected AuthUserRepository $user)
    {
    }

    /**
     * @inheritDoc
     */
    public function can(string $permissionName, array $ruleParams = []): bool
    {
        if (!RequestContext::has()) {
            return false;
        }

        if (
                $this->getRequest()->getHeaderLine(
                    UserAuthorizationMiddleware::HEADER_HTTP_STATUS_CODE
                ) === 'not_authorized'
        ) {
            return false;
        }

        $user = $this->current();
        if (!is_object($user) || !isset($user->user_id) || !is_string($user->user_id)) {
            return false;
        }

        return $this->access->allows($user->user_id, $permissionName);
    }

    /**
     * @inheritDoc
     */
    public function current(): object|bool|null
    {
        $token = $this->getToken();

        if ($token === null) {
            return false;
        }

        return $this->resolveUserByToken($token);
    }

    private function resolveUserByToken(string $token): object|bool|null
    {
        try {
            return $this->user->find($token);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @inheritDoc
     * @throws ReflectionException
     */
    public function guest(): bool
    {
        return !$this->isLoggedIn();
    }

    /**
     * @inheritDoc
     * @throws ReflectionException
     */
    public function isLoggedIn(): bool
    {
        return is_object($this->current());
    }

    /**
     * Fetch a decrypted token from the request context.
     */
    private function getToken(): ?string
    {
        if (!RequestContext::has()) {
            return null;
        }

        $request = $this->getRequest();
        $token = $request->getAttribute(UserCookieDecryptMiddleware::USER_COOKIE);

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Return request object.
     *
     * @return ServerRequestInterface
     */
    private function getRequest(): ServerRequestInterface
    {
        return RequestContext::get();
    }
}
