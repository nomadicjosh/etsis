<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\Security\LoginCookieReader;
use Application\View\CampusRenderer;
use Codefy\Framework\Auth\Gate;
use Domain\Lms\Service\MoodleLaunchService;
use Domain\Shared\Exception\AccessDeniedException;
use Laminas\Diactoros\Response\{HtmlResponse, RedirectResponse, TextResponse};
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;
use Qubus\View\Native\NativeLoader;

final readonly class PortalMoodleController
{
    public function __construct(
        private MoodleLaunchService $launches,
        private LoginCookieReader $cookies,
        private CampusRenderer $renderer,
        private Gate $gate,
    ) {
    }
    public function page(): ResponseInterface
    {
        if (!$this->gate->isLoggedIn()) {
            return new RedirectResponse($this->renderer->loginUrl());
        }
        return $this->renderer->render("moodle", "Your learning space", [
            "available" => $this->launches->available(),
        ]);
    }
    public function launch(ServerRequest $request): ResponseInterface
    {
        if (!$this->gate->isLoggedIn()) {
            throw new AccessDeniedException("Sign in before opening Moodle.");
        }
        $proof =
        $this->cookies->read($request) ??
        throw new AccessDeniedException("Sign in again to open Moodle.");
        $handoff = $this->launches->begin($proof);
        $nonce = bin2hex(random_bytes(16));
        $origin = parse_url($handoff->site->value);
        if ($origin === false || !isset($origin["host"])) {
            throw new \RuntimeException("Invalid Moodle destination.");
        }
        $destination = "https://" . $origin["host"] . (isset($origin["port"]) ? ":" . $origin["port"] : "");
        $loader = new NativeLoader(['framework' => dirname(__DIR__, 4) . '/resources/views']);
        $body = $loader->render('framework::frontend/portal/moodle-handoff', [
            'action' => $handoff->site->value . '/local/etsis/launch.php',
            'ticket' => $handoff->ticket, 'nonce' => $nonce,
        ]);
        return new HtmlResponse(
            $body,
            200,
            [
                "Cache-Control" => "private, no-store, max-age=0",
                "Referrer-Policy" => "strict-origin",
                "X-Content-Type-Options" => "nosniff",
                "Content-Security-Policy" => "default-src 'none'; style-src 'self'; script-src 'nonce-$nonce'; " .
                    "base-uri 'none'; frame-ancestors 'none'; form-action $destination",
            ],
        );
    }
    /** The early machine middleware handles this exact route before browser middleware. */
    public function channel(): ResponseInterface
    {
        return new TextResponse("Unavailable.", 403, ["Cache-Control" => "private, no-store"]);
    }
}
