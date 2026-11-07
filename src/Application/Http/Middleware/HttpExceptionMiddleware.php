<?php

declare(strict_types=1);

namespace Application\Http\Middleware;

final class HttpExceptionMiddleware extends \Codefy\Framework\Http\Middleware\Exception\HttpExceptionMiddleware
{
    public function process(
        \Psr\Http\Message\ServerRequestInterface $request,
        \Psr\Http\Server\RequestHandlerInterface $handler,
    ): \Psr\Http\Message\ResponseInterface {
        // SIS errors are normalized by the outer privacy middleware before any debug rendering.
        if (SisPrivacyMiddleware::protects($request)) {
            return $handler->handle($request);
        }

        return parent::process($request, $handler);
    }

    protected function normalizeStatusCode(int|string $code): int
    {
        // PDO exceptions carry SQLSTATE strings, which are not HTTP status codes.
        return is_int($code) ? parent::normalizeStatusCode($code) : 500;
    }
}
