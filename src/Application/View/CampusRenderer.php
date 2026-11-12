<?php

declare(strict_types=1);

namespace Application\View;

use Codefy\Framework\Auth\Gate;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Config\ConfigContainer;
use Qubus\View\Native\NativeLoader;
use Vihzhuo\Core\ThemeContext;
use Vihzhuo\Page;
use Vihzhuo\Theme;
use Vihzhuo\ThemeLayout;
use InvalidArgumentException;

final readonly class CampusRenderer
{
    public function __construct(private ConfigContainer $config, private Gate $gate)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, string $title, array $data = []): ResponseInterface
    {
        if (
            !in_array(
                $template,
                ["home", "catalog", "news", "article", "workspace", "not-found", "auth", "moodle"],
                true,
            )
        ) {
            throw new InvalidArgumentException("Unknown portal view.");
        }
        $root = dirname(__DIR__, 3);
        $themeSlug = $this->config->string("portal.theme", "campus-harbor");
        if (!in_array($themeSlug, ["campus-harbor", "campus-quad", "campus-horizon"], true)) {
            $themeSlug = "campus-harbor";
        }
        $theme = new Theme(
            [
                "folder" => $root . "/public/themes",
                "folder_url" => "/themes",
                "parents" => ["campus-quad" => "campus-harbor", "campus-horizon" => "campus-harbor"],
            ],
            $themeSlug,
        );
        $page = new Page();
        $page->setData(["id" => "portal", "name" => $title, "layout" => "portal"]);
        $page->setTranslations([
            "en" => ["meta_title" => $title, "meta_description" => $this->config->string("portal.tagline")],
        ]);
        $signedIn = $this->gate->isLoggedIn();
        $data += [
            "signedIn" => $signedIn,
            "name" => $this->config->string("portal.name", "etsis"),
            "loginUrl" => $this->loginUrl(),
            "passwordLength" => $this->config->integer("auth.password_min_length", 26),
            "usernameLength" => $this->config->integer("auth.username_min_length", 6),
        ];
        $loader = new NativeLoader(["framework" => $root . "/resources/views"]);
        $body = $loader->render("framework::frontend/portal/" . $template, $data);
        $html = ThemeContext::render($theme, new ThemeLayout($theme, "portal")->getViewFile(), [
            "moodleLaunchEnabled" =>
                $this->config->boolean("moodle.enabled", false) &&
                $this->config->boolean("moodle.launch_enabled", false),
            "body" => $body,
            "page" => $page,
            "signedIn" => $signedIn,
            "portalSection" => $data["section"] ?? $template,
            "portalName" => $data["name"],
            "portalTheme" => $themeSlug,
            "staffAccess" => $signedIn && $this->canUseWorkspace(),
            "loginUrl" => $data["loginUrl"],
            "csrfMarkup" => $data["csrfMarkup"] ?? null,
        ]);
        return new HtmlResponse($html, 200, [
            "Cache-Control" => "private, no-store, max-age=0",
            "X-Content-Type-Options" => "nosniff",
            "Referrer-Policy" => "same-origin",
            "Content-Security-Policy" =>
                "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; " .
                "img-src 'self' data:; font-src 'self'; connect-src 'self'; base-uri 'self'; " .
                "form-action 'self'; frame-ancestors 'self'",
        ]);
    }

    public function canUseWorkspace(): bool
    {
        $permissions = [
                "sis:person:manage",
                "sis:student:read",
                "sis:catalog:manage",
                "sis:registration:manage",
                "sis:gradebook:manage",
                "sis:finance:read",
                "sis:transcript:manage",
                "sis:hr:manage",
                "sis:portal:manage",
                "vihzhuo:manage",
                "admin:access:manage",
                "sis:scheduling:manage",
                "sis:forms:manage",
                "sis:communications:manage",
                "sis:import:manage",
                "sis:reporting:manage",
                "sis:reporting:run",
                "sis:transfer:manage",
                "sis:finance:post",
                "sis:finance:ledger",
                "sis:rules:manage",
            ];
        foreach ($permissions as $permission) {
            if ($this->gate->can($permission)) {
                return true;
            }
        }
        return false;
    }

    public function loginUrl(): string
    {
        return "/" . trim($this->config->string("auth.login_route", "login"), "/") . "/";
    }
}
