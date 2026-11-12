<?php

declare(strict_types=1);

namespace Application\Http\Security;

use Domain\User\ValueObject\VerifiedLoginCookie;
use Psr\Http\Message\ServerRequestInterface;

interface LoginCookieReader
{
    public function name(): string;
    public function read(ServerRequestInterface $request): ?VerifiedLoginCookie;
}
