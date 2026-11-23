<?php

declare(strict_types=1);

namespace Tests\Unit;

use Application\Http\Response\RegistrationPauseResponse;
use PHPUnit\Framework\TestCase;
use Qubus\Http\ServerRequest;

final class RegistrationPauseResponseTest extends TestCase
{
    public function testExternalAndMalformedSignInTargetsAreReplacedWithoutEchoingThem(): void
    {
        $request = new ServerRequest([], [], '/create/', 'POST')->withHeader('Accept', 'text/html');
        $paths = ['//external.example.invalid/', '/bad/?secret=fictional', '/"onfocus="alert(1)/', '/bad/%2f/'];
        foreach ($paths as $path) {
            $response = RegistrationPauseResponse::make($request, 503, 5, $path);
            $body = (string) $response->getBody();
            self::assertStringContainsString('href="/login/"', $body);
            self::assertStringNotContainsString($path, $body);
            self::assertFalse($response->hasHeader('Location'));
            self::assertStringContainsString("form-action 'none'", $response->getHeaderLine('Content-Security-Policy'));
        }
    }

    public function testJsonNegotiationAndRetryMetadataRemainPrivateAndBounded(): void
    {
        foreach (['application/json', 'text/html;q=0.5, application/json', 'text/html;q=0'] as $accept) {
            $request = new ServerRequest([], [], '/create/', 'POST')->withHeader('Accept', $accept);
            foreach ([0 => 1, 86401 => 86400] as $retry => $expected) {
                $response = RegistrationPauseResponse::make($request, 429, $retry, '/staff-access/');
                $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame($expected, $data['retry_after']);
                self::assertSame((string) $expected, $response->getHeaderLine('Retry-After'));
                self::assertSame(['error', 'retry_after'], array_keys($data));
                self::assertSame('private, no-store, max-age=0', $response->getHeaderLine('Cache-Control'));
                self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
            }
        }
    }
}
