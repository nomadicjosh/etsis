<?php

declare(strict_types=1);

use Application\Http\Middleware\SisPrivacyMiddleware;
use Codefy\Framework\Auth\Rbac\Exception\UnauthorizedException;
use Domain\Shared\Exception\ValidationException;
use Laminas\Diactoros\Response\TextResponse;
use PHPUnit\Framework\TestCase;
use Qubus\Http\ServerRequest;
use Qubus\Routing\Handlers\CallableRequestHandler;

final class PrivateErrorResponseTest extends TestCase
{
    private function failure(string $path, string $accept, Throwable $error): Psr\Http\Message\ResponseInterface
    {
        return new SisPrivacyMiddleware()->process(
            new ServerRequest([], [], $path, 'POST')->withHeader('Accept', $accept),
            new CallableRequestHandler(static fn () => throw $error)
        );
    }

    public function testFrameworkFailuresAreSanitizedAndHaveExpectedStatuses(): void
    {
        foreach ([
            [new Qubus\Validation\ValidationException(uri: 'https://example.invalid/fictional-secret',
                message: 'fictional-secret in raw validation payload', code: 422), 422],
            [new UnauthorizedException(message: 'fictional-secret', code: 401), 403],
            [new Qubus\Routing\Exceptions\NotFoundHttpException('fictional-secret'), 404],
            [new RuntimeException('fictional-secret', 403), 500],
        ] as [$exception, $status]) {
            $response = $this->failure('/sis/private/', 'application/json', $exception);
            self::assertSame($status, $response->getStatusCode());
            self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
            self::assertStringNotContainsString('fictional-secret', (string) $response->getBody());
            self::assertFalse($response->hasHeader('Location'));
            self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        }
    }

    public function testNativeFormsReceiveEscapedAccessibleRecoveryPageWithoutPayloads(): void
    {
        $response = $this->failure('/admin/profile/update/', 'text/html,application/json;q=0.5',
            new ValidationException([['field' => 'name', 'message' => '<script>fictional</script> Check the name.']]));
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        $html = (string) $response->getBody();
        self::assertStringContainsString('id="request-error-title"', $html);
        self::assertStringContainsString('&lt;script&gt;fictional&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('/admin/profile/update/', $html);
        self::assertStringContainsString("script-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testApiErrorsStayJsonAndAcceptQualityIsRespected(): void
    {
        foreach (['/sis/ui/exports/roster/example/', '/portal/api/contact/'] as $path) {
            $response = $this->failure($path, 'text/html', new RuntimeException('fictional-secret'));
            self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        }
        foreach (['application/json,text/html;q=0.1', 'text/html;q=0', 'application/problem+json', ''] as $accept) {
            $response = $this->failure('/admin/profile/', $accept, new RuntimeException('fictional-secret'));
            self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        }
    }

    public function testPrivateSuccessRemovesConditionalCacheValidators(): void
    {
        $request = new ServerRequest([], [], '/admin/profile/', 'GET');
        $response = new SisPrivacyMiddleware()->process($request, new CallableRequestHandler(static fn () =>
            new TextResponse('Fictional private data', 200, ['ETag' => 'fictional', 'Last-Modified' => 'yesterday'])));
        self::assertFalse($response->hasHeader('ETag'));
        self::assertFalse($response->hasHeader('Last-Modified'));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $public = new SisPrivacyMiddleware()->process(new ServerRequest([], [], '/assets/example.txt', 'GET'),
            new CallableRequestHandler(static fn () => new TextResponse('Asset', 200, ['ETag' => 'fictional'])));
        self::assertTrue($public->hasHeader('ETag'));
    }
}
