<?php

declare(strict_types=1);

namespace Application\Http\Middleware;

use Codefy\Framework\Http\Middleware\Csrf\InvalidTokenException;
use Codefy\Framework\Http\Middleware\Csrf\TokenMismatchException;
use DomainException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use InvalidArgumentException;
use Application\Http\Response\PrivateErrorResponse;
use Codefy\Framework\Auth\Rbac\Exception\UnauthorizedException;
use Qubus\Validation\ValidationException as FrameworkValidationException;
use Qubus\Routing\Exceptions\NotFoundHttpException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Qubus\Routing\Route\RouteAttributes;
use Throwable;

final class SisPrivacyMiddleware implements MiddlewareInterface
{
    public static function protects(ServerRequestInterface $request): bool
    {
        $name = $request->getAttribute(RouteAttributes::NAME);
        if (
            is_string($name) && ($name === 'auth' || str_starts_with($name, 'portal.')
            || str_starts_with($name, 'auth.') || str_starts_with($name, 'register.'))
        ) {
            return true;
        }
        $path = rawurldecode($request->getUri()->getPath());

        return $path === '/'
        || preg_match('~(?:^|/)(?:sis|admin|portal|login|register|auth|create|logout)(?:/|$)~i', $path) === 1;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!self::protects($request)) {
            return $handler->handle($request);
        }
        try {
            $response = $handler->handle($request);
        } catch (AccessDeniedException | UnauthorizedException) {
            $response = PrivateErrorResponse::make($request, 403, 'Access denied.');
        } catch (InvalidTokenException | TokenMismatchException) {
            $response = PrivateErrorResponse::make(
                $request,
                403,
                'The request could not be verified. Reload the form and try again.'
            );
        } catch (RecordNotFoundException | NotFoundHttpException) {
            $response = PrivateErrorResponse::make($request, 404, 'Record not found.');
        } catch (\Domain\Shared\Exception\ValidationException $validation) {
            $response = PrivateErrorResponse::make(
                $request,
                422,
                'Please correct the highlighted fields.',
                $validation->errors
            );
        } catch (FrameworkValidationException) {
            $response = PrivateErrorResponse::make(
                $request,
                422,
                'Some form values could not be accepted. Check the entries and try again.'
            );
        } catch (InvalidArgumentException) {
            $response = PrivateErrorResponse::make($request, 422, 'Invalid request.');
        } catch (ConcurrentModificationException) {
            $response = PrivateErrorResponse::make(
                $request,
                409,
                'This record changed. Reload it before trying again.'
            );
        } catch (DomainException) {
            $response = PrivateErrorResponse::make($request, 409, 'The requested transition is not permitted.');
        } catch (Throwable) {
            // Never return or log student data, SQL bindings, or exception payloads.
            $response = PrivateErrorResponse::make($request, 500, 'Unable to complete the request.');
        }

        return $response->withoutHeader('ETag')->withoutHeader('Last-Modified')
            ->withHeader('Cache-Control', 'private, no-store, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
