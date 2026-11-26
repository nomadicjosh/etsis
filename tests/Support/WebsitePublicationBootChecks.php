<?php

declare(strict_types=1);

require_once __DIR__ . "/WebsiteFixture.php";
$websiteFixture = new \Tests\Support\WebsiteFixture(sis: $sis);
$websiteFixture->pages = $app->make(\Domain\Portal\Service\PagePublicationService::class);
$websiteFixture->menus = $app->make(\Domain\Portal\Service\NavigationService::class);
$id = $websiteFixture->draft();
$api = static function (string $path, array $data, int $status = 200) use ($teachingPost): array {
    $response = $teachingPost($path, $data);
    if ($response->getStatusCode() !== $status) {
        throw new RuntimeException(
            "Website action returned unexpected status " . $response->getStatusCode(),
        );
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$read = static function (string $path, int $status = 200) use ($app, $get): array {
    parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
    $response = $app->router->match(
        $get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $path))
            ->withQueryParams($query),
    );
    if (
        $response->getStatusCode() !== $status ||
        !str_contains($response->getHeaderLine("Cache-Control"), "no-store") ||
        $response->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException("Website read lost its private authorized transport.");
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$page = "/sis/website/pages/" . $id . "/";
$menu = "/sis/website/menus/primary/";
$sis->access->staff = false;
$sis->access->grants = ["vihzhuo:manage"];
$view = $read($page);
$body = [
    "action" => "submit",
    "revision" => $view["revision"],
    "window" => ["visible_from" => null, "visible_until" => null],
    "note" => null,
];
$review = $api($page . "review/", $body);
$api($page . "confirm/", $review);
$view = $read($page);
$approve = array_replace($body, ["revision" => $view["revision"], "action" => "approve"]);
$api($page . "review/", $approve, 403);
$sis->access->grants[] = "sis:portal:publish";
$review = $api($page . "review/", $approve);
$receipt = $api($page . "confirm/", $review);
if ($receipt !== $api($page . "confirm/", $review)) {
    throw new RuntimeException("Website confirmation lost its original receipt.");
}
$view = $read($menu);
$saved = $api($menu, [
    "items" => [\Tests\Support\WebsiteFixture::item()],
    "revision" => $view["revision"],
]);
$review = $api($menu . "review/", ["revision" => $saved["revision"]]);
$api($menu . "publish/", $review);
$menuView = $read($menu);
$nested = \Tests\Support\WebsiteFixture::item(
    "fictional-group",
    "group",
    children: [\Tests\Support\WebsiteFixture::item("fictional-page", "page", $id)],
);
$nested["label"] = "Fictional dropdown";
$saved = $api($menu, ["items" => [$nested], "revision" => $menuView["revision"]]);
$review = $api($menu . "review/", ["revision" => $saved["revision"]]);
$api($menu . "publish/", $review);
// Mirror only fictional CMS rows in Vihzhuo's isolated connection; authoritative decisions use real providers above.
$websiteConfig = $app->configContainer->array("vihzhuo");
$historyBefore = $sis->sql->rows("sis_history_events");
foreach (["campus-harbor", "campus-quad", "campus-horizon"] as $themeSlug) {
    $websiteConfig["theme"]["active_theme"] = $themeSlug;
    $websiteConfig["cache"]["enabled"] = true;
    $builder = new \Application\Service\CodefyPageBuilder($websiteConfig);
    if (phpb_config("cache.enabled") !== false) {
        throw new RuntimeException("Full-page caching could bypass current publication checks.");
    }
    global $phpb_db;
    $phpb_db->query(
        "CREATE TABLE settings (id INTEGER PRIMARY KEY, setting VARCHAR(50), value TEXT, is_array INTEGER)",
    );
    $phpb_db->query(
        "CREATE TABLE pages (id VARCHAR(191) PRIMARY KEY, name VARCHAR(191), layout VARCHAR(191), " .
            "show_in_nav VARCHAR(10), nav_position INTEGER, nav_type VARCHAR(30), data TEXT)",
    );
    $phpb_db->query(
        "CREATE TABLE page_translations (id VARCHAR(191) PRIMARY KEY, page_id VARCHAR(191), " .
            "locale VARCHAR(10), title VARCHAR(191), route VARCHAR(191), " .
            "meta_title VARCHAR(191), meta_description TEXT)",
    );
    foreach (["pages", "page_translations"] as $table) {
        foreach ($sis->sql->rows($table) as $row) {
            $phpb_db->query(
                "INSERT INTO " .
                    $table .
                    " (" .
                    implode(", ", array_keys($row)) .
                    ") VALUES (" .
                    implode(", ", array_fill(0, count($row), "?")) .
                    ")",
                array_values($row),
            );
        }
    }
    $request = $get->withUri(
        new \Laminas\Diactoros\Uri("https://campus.example.invalid/fictional-campus-1/"),
    );
    $response = $builder->handlePublicRequest($request);
    $html = (string) $response?->getBody();
    if (
        $response === null ||
        !str_contains($html, "Fictional title") ||
        !str_contains($html, "Fictional dropdown") ||
        !str_contains($html, 'data-bs-toggle="dropdown"')
    ) {
        throw new RuntimeException(
            "Approved native page or nested navigation did not render in " . $themeSlug,
        );
    }
    $sis->sql->update("pages", ["name" => "Fictional changed draft"], ["id" => $id]);
    if ($builder->handlePublicRequest($request) !== null) {
        throw new RuntimeException("The native builder exposed an unapproved saved revision.");
    }
    $sis->sql->update("pages", ["name" => "Fictional campus page"], ["id" => $id]);
}
if ($historyBefore !== $sis->sql->rows("sis_history_events")) {
    throw new RuntimeException("Website rendering wrote academic history.");
}
require __DIR__ . '/WebsiteFormBootChecks.php';
$sis->access->grants = ["sis:portal:publish"];
$sis->access->grants[] = "vihzhuo:manage";
$manager = new \Application\Service\WebsiteManager();
$app->make(\Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::class);
$pageRecord = new \Vihzhuo\Page();
$pageRecord->setData($sis->sql->one("pages", ["id" => $id]));
$pageRecord->setTranslations(["en" => $sis->sql->one("page_translations", ["id" => $id])]);
$settingsHtml = (string) $manager->renderPageSettings($pageRecord)->getBody();
if (
    !str_contains($settingsHtml, "site-manager.css") ||
    !str_contains($settingsHtml, "site-page-form") ||
    !str_contains($settingsHtml, "Back to website pages") ||
    str_contains($settingsHtml, "bg-gradient-to-r") ||
    !str_contains($settingsHtml, 'name="title[en]"') ||
    !str_contains($settingsHtml, 'name="_token"')
) {
    throw new RuntimeException(
        "The actual manager did not use the current page-settings design and native CSRF form.",
    );
}
$activeTheme = new \Application\Service\WebsiteTheme($websiteConfig["theme"], $websiteConfig["theme"]["active_theme"]);
$themeBlocks = $activeTheme->getThemeBlocks();
foreach (["campus-hero", "campus-pathways", "campus-invitation", "lead"] as $editableSlug) {
    if (!($themeBlocks[$editableSlug] ?? null)?->isHtmlBlock()) {
        throw new RuntimeException(
            "A presentation block still prevents normal HTML canvas editing.",
        );
    }
}
$publicRenderer = new \Vihzhuo\Modules\GrapesJS\Block\BlockRenderer($activeTheme, $pageRecord);
$edited = $publicRenderer->renderWithSlug("campus-hero", [
    "html" => "<h1>Fictional edited welcome</h1>",
]);
if (
    !str_contains($edited, "Fictional edited welcome") ||
    str_contains($edited, "Your next chapter starts")
) {
    throw new RuntimeException("An HTML block ignored its saved edited content.");
}
foreach (["campus-courses", "campus-programs", "campus-news", "campus-events"] as $liveSlug) {
    $block = $themeBlocks[$liveSlug];
    $blockRenderer = new \Vihzhuo\Modules\GrapesJS\Block\BlockAdapter(
        new \Vihzhuo\Modules\GrapesJS\PageRenderer($activeTheme, $pageRecord, true),
        $block,
    );
    if (
        !$block->isPhpBlock() ||
        !in_array("heading", array_column($blockRenderer->getBlockSettingsArray(), "name"), true)
    ) {
        throw new RuntimeException("A live feed lost its controlled editable heading.");
    }
    $html = $publicRenderer->renderWithSlug($liveSlug, [
        "settings" => [
            "attributes" => [
                "heading" => "Fictional <script>heading</script>",
                "eyebrow" => "Fictional caption",
                "link_label" => "Fictional link label",
                "introduction" => "Fictional introduction",
            ],
        ],
    ]);
    if (
        str_contains($html, "<script>heading</script>") ||
        !str_contains($html, "Fictional &lt;script&gt;heading&lt;/script&gt;") ||
        !str_contains($html, "Fictional caption") ||
        !str_contains($html, "Fictional link label")
    ) {
        throw new RuntimeException(
            "Live feed copy settings were ignored or rendered without escaping.",
        );
    }
}
// Exercise the real reusable blocks before opening the native designer.
foreach (['accordion', 'carousel'] as $slug) {
    $setting = $slug . '_item_count';
    foreach (
        ['0' => 1, '-2' => 1, '1' => 1, '2' => 2, '20' => 20, '999999' => 20,
            'invalid' => 2, '2.5' => 2] as $count => $expected
    ) {
        $html = $publicRenderer->renderWithSlug($slug, [
            'settings' => ['attributes' => [
                $setting => (string) $count,
                'carousel_label' => 'Fictional <img src=x onerror=alert(1)> label',
            ]],
        ]);
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $items = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " '
            . $slug . '-item ")]');
        if ($items->length !== $expected) {
            throw new RuntimeException('Interactive block settings escaped their rendering bound.');
        }
        if ($slug === 'carousel') {
            $active = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " active ")]');
            if (
                $active->length !== 1 || $active->item(0) !== $items->item(0)
                || str_contains($html, 'data-bs-ride=') || str_contains($html, '<img src=x')
                || !str_contains($html, 'aria-live="polite"')
            ) {
                throw new RuntimeException('A carousel lost its safe label, first slide or deliberate movement.');
            }
        }
    }
    $adapter = new \Vihzhuo\Modules\GrapesJS\Block\BlockAdapter(
        new \Vihzhuo\Modules\GrapesJS\PageRenderer($activeTheme, $pageRecord, true),
        $themeBlocks[$slug]
    );
    $settings = array_column($adapter->getBlockSettingsArray(), null, 'name');
    if (
        $themeBlocks[$slug]->isHtmlBlock() || $settings[$setting]['type'] !== 'number'
        || ($slug === 'carousel' && !isset($settings['carousel_label']))
    ) {
        throw new RuntimeException('The native designer lost the controlled block settings.');
    }
}
$phpb_db->query(
    "CREATE TABLE uploads (id INTEGER PRIMARY KEY, public_id VARCHAR(50), original_file TEXT, " .
        "mime_type VARCHAR(100), server_file TEXT)",
);
\Codefy\Framework\Http\RequestContext::set(
    $get->withAttribute(
        \Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::CSRF_SESSION_ATTRIBUTE,
        "fictional-native-editor-token",
    ),
);
try {
    $nativeEditorHtml = (string) new \Application\Service\PageEditor()
        ->renderPageBuilder($pageRecord)
        ->getBody();
} finally {
    \Codefy\Framework\Http\RequestContext::clear();
}
if (
    !str_contains($nativeEditorHtml, "page-editor-header") ||
    !str_contains($nativeEditorHtml, "page-editor.css") ||
    !str_contains($nativeEditorHtml, "page-editor.js") ||
    preg_match("/window.themeBlocks = (.+);/", $nativeEditorHtml, $blockJson) !== 1
) {
    throw new RuntimeException(
        "The native page designer lost its current shell or block metadata.",
    );
}
$canvasBlocks = json_decode($blockJson[1], true, flags: JSON_THROW_ON_ERROR);
foreach (["campus-hero", "campus-pathways", "campus-invitation", "lead"] as $editableSlug) {
    if (!str_contains($canvasBlocks[$editableSlug]["content"], 'is-html="true"')) {
        throw new RuntimeException(
            "The designer classified an editable presentation block as PHP output.",
        );
    }
}
if ($historyBefore !== $sis->sql->rows("sis_history_events")) {
    throw new RuntimeException("Website authoring presentation wrote academic history.");
}
$settingsView = $manager->handleRequest($get, "settings");
if (
    !str_contains((string) $settingsView->getBody(), "languages[]") ||
    !str_contains((string) $settingsView->getBody(), "Save website settings")
) {
    throw new RuntimeException("Website language settings were lost from the manager.");
}
$sis->access->grants = ["sis:portal:publish"];
$read($page, 403);
$read($menu, 403);
$read("/sis/website/pages/", 403);
$api($menu . "publish/", $review, 403);
echo "Website publication provider checks passed.\n";
