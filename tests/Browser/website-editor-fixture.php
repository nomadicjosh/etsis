<?php

declare(strict_types=1);

// Fictional native Vihzhuo editor fixture. No institution connection or content is used.
$loader = new \Qubus\View\Native\NativeLoader(["framework" => $root . "/resources/views"]);
$viewApp->delegate(\Qubus\View\Renderer::class, static fn() => $loader);
$viewConfig->setConfigKey("csrf", ["header" => "X-CSRF-Token"]);
$websiteConfig = require $root . "/config/vihzhuo.php";
$websiteConfig["general"]["base_url"] = "http://127.0.0.1:8127";
$websiteConfig["general"]["assets_url"] = "/fixture/builder-assets";
$websiteConfig["storage"]["database"] = ["dsn" => "sqlite::memory:", "prefix" => ""];
$websiteConfig["auth"]["use_login"] = false;
$websiteConfig["website_manager"]["use_website_manager"] = false;
$websiteConfig["pagebuilder"]["url"] = "/fixture/website-editor/";
require_once dirname(__DIR__) . "/Support/PageBuilderFixtureTheme.php";
$websiteConfig["theme"]["class"] = \Tests\Support\PageBuilderFixtureTheme::class;
$websiteConfig["theme"]["folder"] = dirname(__DIR__) . "/Support";
$websiteConfig["theme"]["active_theme"] = "PageBuilderTheme";
$websiteConfig["theme"]["parents"] = [];
$websiteConfig["router"]["use_router"] = false;
\Vihzhuo\Extensions::registerBlock('regular-form', $root . '/resources/blocks/forms/form');
new \Application\Service\CodefyPageBuilder($websiteConfig);
global $phpb_db;
$phpb_db->query(
    "CREATE TABLE settings (id INTEGER PRIMARY KEY, setting VARCHAR(50), value TEXT, is_array INTEGER)",
);
$phpb_db->query(
    "CREATE TABLE uploads (id INTEGER PRIMARY KEY, public_id VARCHAR(50), "
    . "original_file TEXT, mime_type TEXT, server_file TEXT)",
);
$phpb_db->query(
    "CREATE TABLE pages (id INTEGER PRIMARY KEY, name VARCHAR(191), layout VARCHAR(191), "
    . "show_in_nav VARCHAR(10), nav_position INTEGER, nav_type VARCHAR(30), data TEXT)",
);
$phpb_db->query(
    "CREATE TABLE page_translations (id INTEGER PRIMARY KEY, page_id INTEGER, locale VARCHAR(10), "
    . "title VARCHAR(191), route VARCHAR(191), meta_title VARCHAR(191), meta_description TEXT)",
);
$record = new \Vihzhuo\Page();
$record->setData([
    "id" => 1,
    "name" => "Fictional editor page",
    "layout" => "main",
    "show_in_nav" => "no",
    "nav_position" => 0,
    "nav_type" => "primary",
    "data" => [
        "html" => [
            '[block slug="editable" id="IDeditable-one"][block slug="dynamic" id="IDdynamic-one"]',
        ],
        "css" => "",
        "components" => [[
            ["tagName" => "phpb-block", "attributes" => ["slug" => "editable", "id" => "IDeditable-one"]],
            ["tagName" => "phpb-block", "attributes" => ["slug" => "dynamic", "id" => "IDdynamic-one"]],
        ]],
        "blocks" => [
            "en" => [
                "IDeditable-one" => ["slug" => "editable", "settings" => ["attributes" => []]],
                "IDdynamic-one" => ["slug" => "dynamic", "settings" => ["attributes" => []]],
            ],
        ],
    ],
]);
$record->setTranslations([
    "en" => [
        "id" => 1,
        "page_id" => 1,
        "title" => "Fictional page",
        "route" => "/fictional-page/",
        "meta_title" => "",
        "meta_description" => "",
    ],
]);
if (($_GET['forms'] ?? '') === '1') {
    require __DIR__ . '/website-form-data.php';
    $data = $record->getBuilderData();
    $data['html'][0] .= '[block slug="regular-form" id="IDcontact"]';
    $data['components'][0][] = ['tagName' => 'phpb-block',
        'attributes' => ['slug' => 'regular-form', 'id' => 'IDcontact']];
    $data['blocks']['en']['IDcontact'] = ['slug' => 'regular-form', 'settings' => ['attributes' => [
        'website-definition' => json_encode($websiteForm, JSON_THROW_ON_ERROR),
    ]]];
    $record->setData(['data' => $data], false);
}
$request = new \Qubus\Http\ServerRequest(
    uri: "http://127.0.0.1:8127/fixture/website-editor/",
    method: $_SERVER["REQUEST_METHOD"],
)
    ->withQueryParams($_GET)
    ->withParsedBody($_POST)
    ->withAttribute(
        \Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::CSRF_SESSION_ATTRIBUTE,
        "fictional-test-token",
    );
\Codefy\Framework\Http\RequestContext::set($request);
\Vihzhuo\Core\HttpContext::setRequest($request);
$editor = new \Application\Service\PageEditor();
$response = isset($_GET["action"])
? $editor->handleRequest($request, action: $_GET["action"], page: $record)
: $editor->renderPageBuilder($record);
header("Cache-Control: private, no-store");
http_response_code($response?->getStatusCode() ?? 404);
foreach ($response?->getHeaders() ?? [] as $name => $values) {
    foreach ($values as $value) {
        header($name . ": " . $value, false);
    }
}
echo (string) $response?->getBody();
