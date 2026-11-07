<?php

declare(strict_types=1);

namespace Application\Http\Response;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Qubus\View\Native\NativeLoader;

/** Render approved error text only. Never inspect exception messages or redirect targets. */
final class PrivateErrorResponse
{
    /** @param list<array{field: string, message: string}> $errors */
    public static function make(
        ServerRequestInterface $request,
        int $status,
        string $message,
        array $errors = []
    ): ResponseInterface {
        if (!self::html($request)) {
            return new JsonResponse(['error' => $message] + ($errors === [] ? [] : ['errors' => $errors]), $status);
        }
        $title = match ($status) {
            403 => 'This request needs another look', 404 => 'We could not find that record',
            409 => 'Pause and reload this record', 422 => 'Please check the form',
            default => 'Something interrupted this request',
        };
        $loader = new NativeLoader(['framework' => dirname(__DIR__, 4) . '/resources/views']);
        $html = $loader->render('framework::backend/errors/private', [
            'status' => $status, 'title' => $title, 'message' => $message,
            'errors' => array_slice($errors, 0, 50),
        ]);

        return new HtmlResponse($html, $status, [
            'Vary' => 'Accept', 'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'self'; script-src 'none'; "
                . "style-src 'self'; img-src 'self'; font-src 'self'; frame-ancestors 'none'; "
                . "base-uri 'none'; form-action 'none'",
        ]);
    }

    private static function html(ServerRequestInterface $request): bool
    {
        $path = rawurldecode($request->getUri()->getPath());
        if (
            preg_match('~(?:^|/)sis(?:/|$)|(?:^|/)portal/(?:api|payments/webhook)(?:/|$)~i', $path)
            || strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest'
        ) {
            return false;
        }
        $html = 0.0;
        $json = 0.0;
        foreach (explode(',', substr($request->getHeaderLine('Accept'), 0, 4096)) as $range) {
            $parts = array_map('trim', explode(';', strtolower($range)));
            $type = array_shift($parts);
            $quality = 1.0;
            foreach ($parts as $parameter) {
                if (str_starts_with($parameter, 'q=')) {
                    $quality = preg_match('/^q=(?:0(?:\.[0-9]{0,3})?|1(?:\.0{0,3})?)$/D', $parameter)
                    ? (float) substr($parameter, 2) : 0.0;
                }
            }
            if ($type === 'text/html') {
                $html = max($html, $quality);
            } elseif ($type === 'application/json' || str_ends_with((string) $type, '+json')) {
                $json = max($json, $quality);
            }
        }

        return $html > 0 && $html >= $json;
    }
}
