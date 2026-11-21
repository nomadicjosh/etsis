<?php

declare(strict_types=1);

// Fictional database, real providers, framework CSRF and private workspace responses.
$content = $app->make(\Domain\Portal\Service\CampusContentService::class);
$id = $content->save(
    null,
    "news",
    "scheduled-fictional-news",
    "Fictional scheduled campus notice",
    "<p>Fictional <strong>safe news</strong>.</p><script>window.newsAttack=1</script>",
    "public",
    null,
    "html",
);
$path = "/sis/portal/posts/" . $id->value . "/publication/";
$contentPost = static function (string $path, array $data) use ($teachingPost): array {
    $response = $teachingPost($path, $data);
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException(
            "Content publication route failed: " . $path . " status " . $response->getStatusCode(),
        );
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$input = [
    "action" => "approve",
    "revision" => $content->get($id)["revision"],
    "window" => ["visible_from" => "2026-08-11T17:00:00Z", "visible_until" => "2026-08-12T17:00:00Z"],
    "note" => null,
];
$invalid = $input;
unset($invalid["window"]);
if (
    $teachingPost($path . "review/", $invalid)->getStatusCode() !== 422 ||
    $teachingPost($path, ["publish" => true, "version" => 0])->getStatusCode() !== 422
) {
    throw new RuntimeException("Publication accepted an omitted window or an unreviewed old request.");
}
$sis->access->staff = false;
$sis->access->grants = ["sis:portal:manage"];
if ($teachingPost($path . "review/", $input)->getStatusCode() !== 403) {
    throw new RuntimeException("Authoring permission granted publication.");
}
$submission = $contentPost(
    $path . "review/",
    array_replace($input, [
        "action" => "submit",
        "window" => ["visible_from" => null, "visible_until" => null],
    ]),
);
$contentPost($path, $submission);
$sis->access->staff = true;
$publisher = $sis->user();
$sis->access->staff = false;
$sis->access->actor = $publisher;
$sis->access->grants = ["sis:portal:manage", "sis:portal:publish"];
$input["revision"] = $content->get($id)["revision"];
$review = $contentPost($path . "review/", $input);
$receipt = $contentPost($path, $review);
if ($receipt !== $contentPost($path, $review)) {
    throw new RuntimeException("Content retry lost its original result.");
}
$campus = $app->make(\Domain\Portal\Service\CampusService::class);
$before = $sis->sql->rows("sis_history_events");
if ($campus->publicData()["news"] !== []) {
    throw new RuntimeException("Scheduled news appeared early.");
}
$config = $app->configContainer->array("vihzhuo");
$builder = new \Application\Service\CodefyPageBuilder($config);
global $phpb_db;
$phpb_db->query(
    "CREATE TABLE settings (id INTEGER PRIMARY KEY, setting VARCHAR(50), value TEXT, is_array INTEGER)",
);
$phpb_db->query(
    "CREATE TABLE page_translations (id INTEGER PRIMARY KEY, page_id INTEGER, locale VARCHAR(8), route VARCHAR(191))",
);
\Vihzhuo\Core\HttpContext::setRequest($get);
$page = new \Vihzhuo\Page();
$page->setData([
    "id" => "fixture",
    "layout" => "portal",
    "data" => \Application\Service\CampusPageTemplate::data(),
]);
$page->setTranslations([
    "en" => ["meta_title" => "Fictional page", "meta_description" => "", "route" => "/"],
]);
$visibilityChecks = ["2026-08-10T17:00:00Z" => false, "2026-08-11T17:00:00Z" => true, "2026-08-12T17:00:00Z" => false];
foreach ($visibilityChecks as $instant => $visible) {
    $sis->clock->time = new \DateTimeImmutable($instant);
    foreach (["campus-harbor", "campus-quad", "campus-horizon"] as $slug) {
        $theme = new \Vihzhuo\Theme($config["theme"], $slug);
        $html = new \Vihzhuo\Modules\GrapesJS\PageRenderer($theme, $page)->render();
        if (str_contains($html, "Fictional scheduled campus notice") !== $visible) {
            throw new RuntimeException("Native news block bypassed its publication window.");
        }
    }
    $response = $app->router->match(
        $get->withUri(
            new \Laminas\Diactoros\Uri(
                "https://campus.example.invalid/portal/news/scheduled-fictional-news/",
            ),
        ),
    );
    if ($visible && !str_contains((string) $response->getBody(), "<strong>safe news</strong>")) {
        throw new RuntimeException("Public news lost its sanitized formatting.");
    }
    if (str_contains((string) $response->getBody(), "newsAttack")) {
        throw new RuntimeException("Executable news escaped sanitization.");
    }
    if (
        $response->getStatusCode() !== ($visible ? 200 : 404) ||
        !str_contains($response->getHeaderLine("Cache-Control"), "no-store") ||
        $response->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException("Direct news URL lost scheduling or privacy.");
    }
}
if ($before !== $sis->sql->rows("sis_history_events")) {
    throw new RuntimeException("Reading news appended academic history.");
}
