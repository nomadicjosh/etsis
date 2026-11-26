<?php

declare(strict_types=1);

// Exercise the real registered blocks, shared template and native Settings adapter.
$embedTables = ['pages', 'page_translations', 'sis_page_publications', 'sis_history_events'];
$embedFacts = array_map($sis->sql->rows(...), $embedTables);
require dirname(__DIR__) . '/Browser/website-embed-data.php';
foreach ($websiteEmbeds as $slug => $attributes) {
    phpb_set_in_editmode(false);
    $html = $publicRenderer->renderWithSlug($slug, ['settings' => ['attributes' => $attributes]]);
    if (
        str_contains($html, '<iframe') || !str_contains($html, 'data-embed-source="https://www.')
        || !str_contains($html, 'aria-expanded="false"') || !str_contains($html, 'role="status"')
        || !str_contains($html, 'rel="noreferrer"')
    ) {
        throw new RuntimeException('A registered external block lost its deliberate accessible activation.');
    }
    $adapter = new \Vihzhuo\Modules\GrapesJS\Block\BlockAdapter(
        new \Vihzhuo\Modules\GrapesJS\PageRenderer($activeTheme, $pageRecord, true),
        $themeBlocks[$slug]
    );
    $settings = array_column($adapter->getBlockSettingsArray(), null, 'name');
    if ($themeBlocks[$slug]->isHtmlBlock() || array_diff(array_keys($attributes), array_keys($settings)) !== []) {
        throw new RuntimeException('An external block lost its editable native settings.');
    }
    phpb_set_in_editmode(true);
    $preview = $publicRenderer->renderWithSlug($slug, ['settings' => ['attributes' => $attributes]]);
    if (str_contains($preview, 'data-embed-source') || !str_contains($preview, 'inactive in the page designer')) {
        throw new RuntimeException('An external block allowed activation in the designer.');
    }
    phpb_set_in_editmode(false);
    $urlKey = $slug === 'youtube' ? 'youtube_embed_url' : 'map_embed_url';
    $attributes[$urlKey] = 'https://unapproved.example.invalid/embed';
    $invalid = $publicRenderer->renderWithSlug($slug, ['settings' => ['attributes' => $attributes]]);
    if (str_contains($invalid, 'unapproved.example.invalid') || !str_contains($invalid, 'This embed is unavailable.')) {
        throw new RuntimeException('An invalid retained embed URL became an external destination.');
    }
}
if ($embedFacts !== array_map($sis->sql->rows(...), $embedTables)) {
    throw new RuntimeException('External block rendering changed page approvals or academic history.');
}
$campusCsp = $app->make(\Application\View\CampusRenderer::class)->render('not-found', 'Fictional missing page')
    ->getHeaderLine('Content-Security-Policy');
if (
    !str_contains($campusCsp, "frame-src 'self' https://www.youtube-nocookie.com/embed/ ")
    || !str_contains($campusCsp, 'https://www.google.com/maps/embed https://www.google.com/maps/embed/v1/')
    || !str_contains($campusCsp, "script-src 'self'")
) {
    throw new RuntimeException('The real portal policy lost its narrowly allowed embed paths or self-only scripts.');
}
