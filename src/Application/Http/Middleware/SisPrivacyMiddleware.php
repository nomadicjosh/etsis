<?php

declare(strict_types=1);

namespace Application\Http\Middleware;

use Codefy\Framework\Http\Middleware\Csrf\InvalidTokenException;
use Codefy\Framework\Http\Middleware\Csrf\TokenMismatchException;
use DomainException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\AuthenticationRequiredException;
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

use function Codefy\Framework\Helpers\trans;

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
        } catch (AuthenticationRequiredException) {
            $response = PrivateErrorResponse::make($request, 401, 'Sign in again to continue.');
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
        } catch (\Domain\Shared\EventSourcing\HistoryReadLimitException) {
            $response = PrivateErrorResponse::make(
                $request,
                409,
                'This record needs a separate history review. Contact your records office before trying again.'
            );
        } catch (\Domain\AcademicRecord\Exception\WorkPursuitRequiredException) {
            $response = PrivateErrorResponse::make(
                $request,
                409,
                "Some academic work has no verified program pursuit. " .
                    "Ask the records office to review its attribution before continuing."
            );
        } catch (\Domain\Finance\Exception\AccountingControlException $accounting) {
            $message = match ($accounting->issue) {
                \Domain\Finance\Enum\AccountingControlIssue::MissingPeriod => trans(
                    'The finance office must open an accounting period for this date '
                    . 'before the transaction can be recorded.'
                ),
                \Domain\Finance\Enum\AccountingControlIssue::ClosedPeriod => trans(
                    'This transaction falls in a closed accounting period. Contact the finance office.'
                ),
                \Domain\Finance\Enum\AccountingControlIssue::OpeningPosition => trans(
                    'This currency has existing financial records. A reviewed opening-position conversion '
                    . 'is required before automatic mapping can be activated.'
                ),
                \Domain\Finance\Enum\AccountingControlIssue::ManualReceivable => trans(
                    'Use the student account to change receivables. Manual journals cannot post to its control account.'
                ),
            };
            $response = PrivateErrorResponse::make($request, 409, $message);
        } catch (\Domain\Payments\Exception\GatewaySetupException $setup) {
            $message = match ($setup->issue) {
                \Domain\Payments\Enum\GatewaySetupIssue::Credentials => trans(
                    'Provide a Stripe API key matching the selected live/test mode and its webhook signing secret.'
                ),
                \Domain\Payments\Enum\GatewaySetupIssue::Merchant => trans(
                    'The Stripe merchant could not be verified. Check the API key and its account-read permission.'
                ),
                \Domain\Payments\Enum\GatewaySetupIssue::Asset => trans(
                    'Choose a classified asset in this currency, distinct from the receivables control account.'
                ),
                \Domain\Payments\Enum\GatewaySetupIssue::Identity => trans(
                    'Create a separate payment connection for a different merchant, mode or currency.'
                ),
                \Domain\Payments\Enum\GatewaySetupIssue::TestMode => trans(
                    'Test payment connections require an isolated local, development or testing installation.'
                ),
            };
            $response = PrivateErrorResponse::make($request, 422, $message);
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
