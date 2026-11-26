<?php

declare(strict_types=1);

// Native block rendering in isolated memory. Never boot institutional services or resolve live navigation.
$websiteConfig = require $root . '/config/vihzhuo.php';
$websiteConfig['general']['base_url'] = 'http://127.0.0.1:8127';
$websiteConfig['storage']['database'] = ['dsn' => 'sqlite::memory:', 'prefix' => ''];
$websiteConfig['auth']['use_login'] = false;
$websiteConfig['website_manager']['use_website_manager'] = false;
$websiteConfig['router']['use_router'] = false;
$themeSlug = in_array($_GET['theme'] ?? '', [
    'campus-harbor', 'campus-quad', 'campus-horizon', 'bootstrap-business',
], true) ? $_GET['theme'] : 'campus-harbor';
$websiteConfig['theme']['active_theme'] = $themeSlug;
new \Application\Service\CodefyPageBuilder($websiteConfig);
global $phpb_db;
$phpb_db->query('CREATE TABLE settings (id INTEGER PRIMARY KEY, setting VARCHAR(50), value TEXT, is_array INTEGER)');
$phpb_db->query('CREATE TABLE pages (id INTEGER PRIMARY KEY, name TEXT, layout TEXT, data TEXT)');
$phpb_db->query('CREATE TABLE page_translations (id INTEGER PRIMARY KEY, page_id INTEGER, locale TEXT, '
    . 'title TEXT, route TEXT, meta_title TEXT, meta_description TEXT)');
foreach (['accordion', 'accordion-header', 'accordion-body', 'carousel', 'carousel-item'] as $slug) {
    \Vihzhuo\Extensions::registerBlock($slug, $root . '/resources/blocks/' . $slug);
}
$theme = new \Application\Service\WebsiteTheme($websiteConfig['theme'], $themeSlug);
$count = substr((string) ($_GET['count'] ?? '2'), 0, 24);
$blocks = [];
foreach (['one', 'two'] as $name) {
    $blocks['IDquestions-' . $name] = [
        'slug' => 'accordion', 'settings' => ['attributes' => ['accordion_item_count' => $count]],
        'blocks' => [
            'heading-0' => ['html' => '<span>Question ' . $name . ' A</span>'],
            'body-0' => ['html' => '<p>Answer ' . $name . ' A. <a href="#page-title">Return to page title</a></p>'],
            'heading-1' => ['html' => '<span>Question ' . $name . ' B</span>'],
            'body-1' => ['html' => '<p>Answer ' . $name . ' B.</p>'],
        ],
    ];
    $blocks['IDhighlights-' . $name] = [
        'slug' => 'carousel', 'settings' => ['attributes' => [
            'carousel_item_count' => $count,
            'carousel_label' => ($_GET['hostile'] ?? '') === '1'
                ? 'Fictional <img src=x onerror=alert(1)> ' . $name : 'Fictional highlights ' . $name,
        ]],
        'blocks' => [
            'carousel-item-0' => ['html' => '<div class="p-3"><h2>Highlight ' . $name . ' A</h2>'
                . '<p>Fictional admissions information.</p><a href="#page-title">Explore ' . $name . ' A</a></div>'],
            'carousel-item-1' => ['html' => '<div class="p-3"><h2>Highlight ' . $name . ' B</h2>'
                . '<p>Fictional campus information.</p><a href="#page-title">Explore ' . $name . ' B</a></div>'],
        ],
    ];
}
$page = new \Vihzhuo\Page();
$page->setData(['id' => 1, 'layout' => 'page-template', 'data' => ['blocks' => ['en' => $blocks]]]);
$page->setTranslations(['en' => ['meta_title' => 'Fictional interactive blocks', 'meta_description' => '']]);
$renderer = new \Vihzhuo\Modules\GrapesJS\PageRenderer($theme, $page, ($_GET['edit'] ?? '') === '1');
$body = '<div class="container py-4"><h1 id="page-title">Fictional interactive information</h1>';
foreach (['one', 'two'] as $name) {
    $body .= '<section aria-label="Questions ' . $name . '" class="mb-4">'
    . $renderer->renderBlock('accordion', 'IDquestions-' . $name) . '</section>';
    $body .= $renderer->renderBlock('carousel', 'IDhighlights-' . $name);
}
$body .= '</div>';
$layout = new \Vihzhuo\ThemeLayout($theme, 'page-template');
$html = \Vihzhuo\Core\ThemeContext::render($theme, $layout->getViewFile(), [
    'body' => $body, 'page' => $page, 'signedIn' => false, 'staffAccess' => false,
    'portalName' => 'Fictional University', 'loginUrl' => '/login/',
    'siteNavigation' => ['primary' => '', 'secondary' => '', 'footer' => ''],
]);
$html = str_replace(['[block slug="bb-navbar"]', '[block slug="bb-footer"]'], '', $html);
header('Cache-Control: private, no-store');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
    . "img-src 'self' data:; font-src 'self'; connect-src 'self'; base-uri 'self'; frame-ancestors 'self'");
echo $html;
