<?php

declare(strict_types=1);

namespace Application\View;

use Codefy\Framework\Auth\Gate;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Config\ConfigContainer;
use Qubus\Exception\Data\TypeException;
use Qubus\View\Native\Exception\InvalidTemplateNameException;
use Qubus\View\Native\Exception\ViewException;
use Qubus\View\Native\NativeLoader;
use Throwable;
use Vihzhuo\Core\ThemeContext;
use Vihzhuo\Page;
use Vihzhuo\Theme;
use Vihzhuo\ThemeLayout;
use InvalidArgumentException;

final readonly class CampusRenderer
{
    public function __construct(
        private ConfigContainer $config,
        private Gate $gate,
        private ?\Domain\Portal\Service\NavigationService $navigation = null
    ) {
    }

    /**
     * @param string $template
     * @param string $title
     * @param array<string, mixed> $data
     * @return ResponseInterface
     * @throws InvalidTemplateNameException
     * @throws TypeException
     * @throws ViewException
     * @throws Throwable
     */
    public function render(string $template, string $title, array $data = []): ResponseInterface
    {
        if (
            !in_array(
                $template,
                ["home", "catalog", "news", "article", "workspace", "not-found", "auth",
                    "moodle", "account-setup", "events", "programs", "program"],
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
        $siteNavigation = [];
        foreach (['primary', 'secondary', 'footer'] as $slot) {
            $siteNavigation[$slot] = $loader->render('framework::shared/site-navigation', [
                'items' => $this->navigation?->publicItems($slot) ?? [], 'prefix' => 'campus-' . $slot,
            ]);
        }
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
            'siteNavigation' => $siteNavigation,
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

    /**
     * @return bool
     * @throws TypeException
     * @throws \ReflectionException
     */
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
        return array_any($permissions, fn($permission) => $this->gate->can($permission));
    }

    /**
     * @throws TypeException
     */
    public function loginUrl(): string
    {
        return "/" . trim($this->config->string("auth.login_route", "login"), "/") . "/";
    }
}
