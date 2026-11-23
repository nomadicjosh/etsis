<?php

declare(strict_types=1);

namespace Application\Http\Response;

use Domain\Shared\Operations\MaintenanceMode;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;

use function Codefy\Framework\Helpers\config;
use function Codefy\Framework\Helpers\trans;
use function Codefy\Framework\Helpers\trans_html;

/** Shared early-front-controller and middleware response; no routing, session, body or credential reads. */
final readonly class MaintenanceResponse
{
    public function __construct(private MaintenanceMode $maintenance)
    {
    }

    public function respond(string $method, string $path, string $accept): ?ResponseInterface
    {
        $state = $this->maintenance->status();
        if (!$state->paused()) {
            return null;
        }
        $headers = [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Retry-After' => (string) $state->retryAfter,
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'Vary' => 'Accept',
        ];
        $path = rawurldecode($path);
        $json = str_contains(strtolower($accept), 'json')
        || str_starts_with($path, '/sis/') || str_starts_with($path, '/api/')
        || str_starts_with($path, '/portal/api/') || $path === '/portal/payments/webhook/';
        if ($json) {
            $response = new JsonResponse([
                'status' => 'maintenance',
                'error' => trans('The service is temporarily paused for maintenance. Please try again shortly.'),
                'retry_after' => $state->retryAfter,
            ], 503, $headers + ['Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'"]);
        } else {
            $style = <<<'CSS'
                :root { color-scheme: light; font-family: system-ui, sans-serif; color: #183149; background: #f5f8fc; }
                * { box-sizing: border-box; }
                body { margin: 0; padding: clamp(1rem, 5vw, 3rem); }
                header, main { max-width: 38rem; margin-inline: auto; }
                header { margin-block: 1rem 2rem; font-weight: 750; font-size: 1.8rem; }
                header span { color: #346d9d; }
                main { padding: clamp(1.25rem, 5vw, 2.5rem); border: 1px solid #c9d6e3;
                    border-radius: .8rem; background: white; }
                h1 { margin-top: 0; font-size: clamp(1.5rem, 4vw, 2rem); line-height: 1.3; overflow-wrap: anywhere; }
                a { display: inline-block; max-width: 100%; overflow-wrap: anywhere; margin-top: 1.5rem;
                    padding: .65rem 1rem; border: 1px solid #346d9d;
                    border-radius: .5rem; color: #24567f; text-decoration: none; }
                a:focus-visible { outline: 3px solid #2467a5; outline-offset: 3px; }
                p { color: #526780; line-height: 1.7; margin-bottom: 0; overflow-wrap: anywhere; }
CSS;
            $title = trans_html('Temporarily unavailable');
            $message = trans_html('The service is temporarily paused for maintenance. Please try again shortly.');
            $signIn = trans_html('Super administrator sign-in');
            $signInUrl = htmlspecialchars(
                '/' . trim((string) config('auth.login_route', 'login'), '/') . '/',
                ENT_QUOTES,
                'UTF-8',
            );
            $language = htmlspecialchars((string) config('app.language', 'en'), ENT_QUOTES, 'UTF-8');
            $html = <<<HTML
                <!doctype html>
                <html lang="{$language}">
                    <head>
                        <meta charset="utf-8">
                        <meta name="viewport" content="width=device-width, initial-scale=1">
                        <title>{$title}</title>
                        <style>{$style}</style>
                    </head>
                    <body>
                        <header>etsis<span>.</span></header>
                        <main>
                            <h1>{$title}</h1>
                            <p>{$message}</p>
                            <a href="{$signInUrl}">{$signIn}</a>
                        </main>
                    </body>
                </html>
HTML;
            $hash = base64_encode(hash('sha256', $style, true));
            $response = new HtmlResponse($html, 503, $headers + [
                'Content-Security-Policy' => "default-src 'none'; style-src 'sha256-{$hash}'; "
                    . "base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            ]);
        }
        return strtoupper($method) === 'HEAD' ? $response->withBody(new Stream('php://temp', 'w+')) : $response;
    }
}
