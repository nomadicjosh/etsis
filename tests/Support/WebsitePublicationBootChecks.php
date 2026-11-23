<?php

declare(strict_types=1);

require_once __DIR__ . '/WebsiteFixture.php';
$websiteFixture = new \Tests\Support\WebsiteFixture(sis: $sis);
$websiteFixture->pages = $app->make(\Domain\Portal\Service\PagePublicationService::class);
$websiteFixture->menus = $app->make(\Domain\Portal\Service\NavigationService::class);
$id = $websiteFixture->draft();
$api = static function (string $path, array $data, int $status = 200) use ($teachingPost): array {
    $response = $teachingPost($path, $data);
    if ($response->getStatusCode() !== $status) {
        throw new RuntimeException('Website action returned unexpected status ' . $response->getStatusCode());
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$read = static function (string $path, int $status = 200) use ($app, $get): array {
    $response = $app->router->match($get->withUri(
        new \Laminas\Diactoros\Uri('https://campus.example.invalid' . $path),
    ));
    if (
        $response->getStatusCode() !== $status ||
        !str_contains($response->getHeaderLine('Cache-Control'), 'no-store') || $response->hasHeader('phpdebugbar-id')
    ) {
        throw new RuntimeException('Website read lost its private authorized transport.');
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$page = '/sis/website/pages/' . $id . '/';
$menu = '/sis/website/menus/primary/';
$sis->access->staff = false;
$sis->access->grants = ['vihzhuo:manage'];
$view = $read($page);
$body = ['action' => 'submit', 'revision' => $view['revision'],
    'window' => ['visible_from' => null, 'visible_until' => null], 'note' => null];
$review = $api($page . 'review/', $body);
$api($page . 'confirm/', $review);
$view = $read($page);
$approve = array_replace($body, ['revision' => $view['revision'], 'action' => 'approve']);
$api($page . 'review/', $approve, 403);
$sis->access->grants[] = 'sis:portal:publish';
$review = $api($page . 'review/', $approve);
$receipt = $api($page . 'confirm/', $review);
if ($receipt !== $api($page . 'confirm/', $review)) {
    throw new RuntimeException('Website confirmation lost its original receipt.');
}
$view = $read($menu);
$saved = $api($menu, ['items' => [\Tests\Support\WebsiteFixture::item()], 'revision' => $view['revision']]);
$review = $api($menu . 'review/', ['revision' => $saved['revision']]);
$api($menu . 'publish/', $review);
$menuView = $read($menu);
$nested = \Tests\Support\WebsiteFixture::item('fictional-group', 'group', children: [
    \Tests\Support\WebsiteFixture::item('fictional-page', 'page', $id),
]);
$nested['label'] = 'Fictional dropdown';
$saved = $api($menu, ['items' => [$nested], 'revision' => $menuView['revision']]);
$review = $api($menu . 'review/', ['revision' => $saved['revision']]);
$api($menu . 'publish/', $review);
// Mirror only fictional CMS rows in Vihzhuo's isolated connection; authoritative decisions use real providers above.
$websiteConfig = $app->configContainer->array('vihzhuo');
$historyBefore = $sis->sql->rows('sis_history_events');
foreach (['campus-harbor', 'campus-quad', 'campus-horizon'] as $themeSlug) {
    $websiteConfig['theme']['active_theme'] = $themeSlug;
    $websiteConfig['cache']['enabled'] = true;
    $builder = new \Application\Service\CodefyPageBuilder($websiteConfig);
    if (phpb_config('cache.enabled') !== false) {
        throw new RuntimeException('Full-page caching could bypass current publication checks.');
    }
    global $phpb_db;
    $phpb_db->query(
        'CREATE TABLE settings (id INTEGER PRIMARY KEY, setting VARCHAR(50), value TEXT, is_array INTEGER)',
    );
    $phpb_db->query('CREATE TABLE pages (id VARCHAR(191) PRIMARY KEY, name VARCHAR(191), layout VARCHAR(191), '
        . 'show_in_nav VARCHAR(10), nav_position INTEGER, nav_type VARCHAR(30), data TEXT)');
    $phpb_db->query('CREATE TABLE page_translations (id VARCHAR(191) PRIMARY KEY, page_id VARCHAR(191), '
        . 'locale VARCHAR(10), title VARCHAR(191), route VARCHAR(191), '
        . 'meta_title VARCHAR(191), meta_description TEXT)');
    foreach (['pages', 'page_translations'] as $table) {
        foreach ($sis->sql->rows($table) as $row) {
            $phpb_db->query('INSERT INTO ' . $table . ' (' . implode(', ', array_keys($row))
                . ') VALUES ('
                . implode(', ', array_fill(0, count($row), '?')) . ')', array_values($row));
        }
    }
    $request = $get->withUri(new \Laminas\Diactoros\Uri('https://campus.example.invalid/fictional-campus-1/'));
    $response = $builder->handlePublicRequest($request);
    $html = (string) $response?->getBody();
    if (
        $response === null || !str_contains($html, 'Fictional title') || !str_contains($html, 'Fictional dropdown')
        || !str_contains($html, 'data-bs-toggle="dropdown"')
    ) {
        throw new RuntimeException('Approved native page or nested navigation did not render in ' . $themeSlug);
    }
    $sis->sql->update('pages', ['name' => 'Fictional changed draft'], ['id' => $id]);
    if ($builder->handlePublicRequest($request) !== null) {
        throw new RuntimeException('The native builder exposed an unapproved saved revision.');
    }
    $sis->sql->update('pages', ['name' => 'Fictional campus page'], ['id' => $id]);
}
if ($historyBefore !== $sis->sql->rows('sis_history_events')) {
    throw new RuntimeException('Website rendering wrote academic history.');
}
$sis->access->grants = ['sis:portal:publish'];
$sis->access->grants[] = 'vihzhuo:manage';
$manager = new \Application\Service\WebsiteManager();
$app->make(\Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::class);
$settingsView = $manager->handleRequest($get, 'settings');
if (
    !str_contains((string) $settingsView->getBody(), 'languages[]')
    || !str_contains((string) $settingsView->getBody(), 'Save website settings')
) {
    throw new RuntimeException('Website language settings were lost from the manager.');
}
$sis->access->grants = ['sis:portal:publish'];
$read($page, 403);
$read($menu, 403);
$read('/sis/website/pages/', 403);
$api($menu . 'publish/', $review, 403);
echo "Website publication provider checks passed.\n";
