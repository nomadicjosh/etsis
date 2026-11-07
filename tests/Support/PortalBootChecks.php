<?php

declare(strict_types=1);

// Included only by the isolated bootstrap smoke process, never by a public entry point.
require_once __DIR__ . '/CampusFixture.php';
$campus = new CampusFixture();
$sis = $campus->sis;
$teachingStudent = $sis->student();
$teachingSection = $sis->section(20, 0, $sis->access->actor);
$sis->service->register($teachingStudent->id, $teachingSection->id, true);
$publicId = $campus->content->save(null, 'news', 'fixture-public', 'Public fixture', 'A fictional update.', 'public', null);
$campus->posts->publish($publicId, true, 0);
$privateId = $campus->content->save(null, 'news', 'fixture-private', 'Campus-only fixture', 'Private fixture.', 'campus', null);
$campus->posts->publish($privateId, true, 0);
$app->delegate(\Qubus\Expressive\Connection::class, static fn () => $sis->connection);
$app->delegate(\Infrastructure\Persistence\SqlRecords::class, static fn () => $sis->sql);
$app->delegate(\Domain\Admissions\Repository\AdmissionsRepository::class, static fn () => $sis->admissions);
$app->delegate(\Domain\Shared\Persistence\UnitOfWork::class, static fn () => $sis->transactions);
$app->delegate(\Domain\Shared\Security\StudentAccess::class, static fn () => $sis->access);
$app->delegate(\Domain\Portal\Service\CampusService::class, static fn () => $campus->service);
$app->delegate(\Domain\Portal\Service\PortalPaymentService::class, static fn () => $campus->checkout);
$app->delegate(\Codefy\Framework\Auth\Gate::class, static fn () => new class implements \Codefy\Framework\Auth\Gate {
    public function can(string $permissionName, array $ruleParams = []): bool { return false; }
    public function current(): object|bool|null { return false; }
    public function guest(): bool { return true; }
    public function isLoggedIn(): bool { return false; }
});
$app->router->baseMiddleware = ['sis.privacy', 'sis.json', 'firewall', 'csrf.protection', 'bind.request', 'php.debugbar'];
$get = new \Qubus\Http\ServerRequest([], [], 'https://campus.example.invalid/portal/', 'GET')
    ->withAttribute(\Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::CSRF_SESSION_ATTRIBUTE, 'fictionalcsrftoken');
$response = $app->router->match($get);
if ($response->getStatusCode() !== 200 || !str_contains((string) $response->getBody(), 'Your next chapter')
    || !str_contains($response->getHeaderLine('Cache-Control'), 'no-store')) {
    throw new RuntimeException('Portal route expected HTML 200, received ' . $response->getStatusCode() . ' ' . $response->getHeaderLine('Content-Type'));
}
$post = $get->withUri(new \Laminas\Diactoros\Uri('https://campus.example.invalid/portal/api/contact/'))
    ->withMethod('POST')->withHeader('Content-Type', 'application/x-www-form-urlencoded')
    ->withHeader('Accept', 'application/json')->withBody(new \Laminas\Diactoros\Stream('php://temp', 'w+'));
$post->getBody()->write(http_build_query(['_workspace_json' => json_encode([
    'preferred_name' => 'Fictional', 'phone' => '', 'address' => '', 'version' => 0,
], JSON_THROW_ON_ERROR)]));
$post->getBody()->rewind();
if ($app->router->match($post)->getStatusCode() !== 403 || $sis->sql->rows('sis_portal_contacts') !== []) {
    throw new RuntimeException('A portal mutation bypassed CSRF.');
}
$post->getBody()->rewind();
if ($app->router->match($post->withHeader('X-CSRF-Token', 'fictionalcsrftoken'))->getStatusCode() !== 200) {
    throw new RuntimeException('A protected portal mutation did not accept its typed form envelope.');
}

// Resolve the new staff controllers through the real provider and protect their browser mutations.
$course = $sis->sql->rows('sis_courses')[0];
$catalogController = $app->make(\Application\Http\Controller\SisCatalogEditorController::class);
if ($catalogController->show('courses', $course['id'])->getStatusCode() !== 200) {
    throw new RuntimeException('Catalog controller resolution failed.');
}
$catalogRequest = $get->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid/sis/ui/catalog/courses/' . $course['id'] . '/'
));
$catalogResponse = $app->router->match($catalogRequest);
$catalogRecord = json_decode((string) $catalogResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($catalogResponse->getStatusCode() !== 200 || !isset($catalogRecord['revision'])
    || !str_contains($catalogResponse->getHeaderLine('Cache-Control'), 'no-store')) {
    throw new RuntimeException('The protected catalog editor could not resolve through the provider.');
}
$section = $sis->sql->rows('sis_sections')[0];
$contextRequest = $post->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid/sis/ui/context/assignments/' . $section['id'] . '/'
))->withBody(new \Laminas\Diactoros\Stream('php://temp', 'w+'));
$contextRequest->getBody()->write(http_build_query(['_workspace_json' => json_encode(['after' => ''])]));
$contextRequest->getBody()->rewind();
if ($app->router->match($contextRequest)->getStatusCode() !== 403) {
    throw new RuntimeException('A staff context mutation bypassed CSRF.');
}
$contextRequest->getBody()->rewind();
$contextResponse = $app->router->match($contextRequest->withHeader('X-CSRF-Token', 'fictionalcsrftoken'));
if ($contextResponse->getStatusCode() !== 200
    || !str_contains($contextResponse->getHeaderLine('Cache-Control'), 'no-store')) {
    throw new RuntimeException('Staff operations could not resolve through the provider or CSRF pipeline.');
}

// New teaching routes resolve through the real providers, keeping typed bodies and CSRF protection.
$teachingPost = static function (string $path, array $data) use ($app, $post): \Psr\Http\Message\ResponseInterface {
    $request = $post->withUri(new \Laminas\Diactoros\Uri('https://campus.example.invalid' . $path))
        ->withBody(new \Laminas\Diactoros\Stream('php://temp', 'w+'));
    $request->getBody()->write(http_build_query(['_workspace_json' => json_encode((object) $data, JSON_THROW_ON_ERROR)]));
    $request->getBody()->rewind();
    if ($app->router->match($request)->getStatusCode() !== 403) {
        throw new RuntimeException('A teaching request bypassed CSRF.');
    }
    $request->getBody()->rewind();
    $response = $app->router->match($request->withHeader('X-CSRF-Token', 'fictionalcsrftoken'));
    if (!str_contains($response->getHeaderLine('Cache-Control'), 'no-store')) {
        throw new RuntimeException('A teaching response allowed caching.');
    }
    return $response;
};
$sectionPath = '/sis/sections/' . $teachingSection->id->value;
$created = $teachingPost($sectionPath . '/assignments/', [
    'title' => 'Fictional assignment', 'possible_hundredths' => 1000, 'due_on' => '2026-08-11',
]);
if ($created->getStatusCode() !== 201) {
    throw new RuntimeException('The assignment create route failed.');
}
$assignmentId = json_decode((string) $created->getBody(), true, flags: JSON_THROW_ON_ERROR)['id'];
$assignmentPath = '/sis/assignments/' . $assignmentId;
$assignmentResponse = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid' . $assignmentPath . '/'
)));
$assignmentRecord = json_decode((string) $assignmentResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($assignmentResponse->getStatusCode() !== 200 || !isset($assignmentRecord['revision'])
    || $teachingPost($assignmentPath . '/edit/', [
        'title' => 'Revised fictional assignment', 'possible_hundredths' => 1200,
        'due_on' => '2026-08-12', 'revision' => $assignmentRecord['revision'],
    ])->getStatusCode() !== 200) {
    throw new RuntimeException('The assignment editor routes failed.');
}
if ($teachingPost($sectionPath . '/attendance/', [
    'student_id' => $teachingStudent->id->value, 'held_on' => '2026-08-11', 'status' => 'present',
])->getStatusCode() !== 200) {
    throw new RuntimeException('The attendance write route failed.');
}
foreach (['score-page', 'attendance'] as $feed) {
    $response = $teachingPost($sectionPath . '/students/' . $teachingStudent->id->value . '/' . $feed . '/', []);
    $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    if ($response->getStatusCode() !== 200 || count($payload['items'] ?? []) !== 1) {
        throw new RuntimeException('A bounded teaching read route failed.');
    }
}
$export = $teachingPost('/sis/ui/exports/roster/' . $teachingSection->id->value . '/', []);
if ($export->getStatusCode() !== 200 || !str_contains($export->getHeaderLine('Content-Type'), 'text/csv')
    || !str_contains((string) $export->getBody(), 'Institutional ID')) {
    throw new RuntimeException('The explicit roster export route failed.');
}

// Staff person and access directories use the same real provider, JSON envelope and CSRF pipeline.
$personRow = $sis->sql->rows('sis_persons')[0];
$profileRequest = $get->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid/sis/ui/people/' . $personRow['id'] . '/profile/'
));
$profileResponse = $app->router->match($profileRequest);
$profile = json_decode((string) $profileResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($profileResponse->getStatusCode() !== 200 || !isset($profile['revision'])) {
    throw new RuntimeException('The person profile editor could not resolve through the provider.');
}
$profileWrite = $post->withUri($profileRequest->getUri())
    ->withBody(new \Laminas\Diactoros\Stream('php://temp', 'w+'));
$profileWrite->getBody()->write(http_build_query(['_workspace_json' => json_encode([
    'given_name' => $personRow['given_name'], 'family_name' => $personRow['family_name'],
    'directory_restricted' => false, 'revision' => $profile['revision'],
])]));
$profileWrite->getBody()->rewind();
if ($app->router->match($profileWrite)->getStatusCode() !== 403) {
    throw new RuntimeException('A person edit bypassed CSRF.');
}
$profileWrite->getBody()->rewind();
if ($app->router->match($profileWrite->withHeader('X-CSRF-Token', 'fictionalcsrftoken'))->getStatusCode() !== 200
    || (int) $sis->sql->one('sis_persons', ['id' => $personRow['id']])['directory_restricted'] !== 0) {
    throw new RuntimeException('A protected typed profile edit failed.');
}
$directoryRequest = $post->withUri(new \Laminas\Diactoros\Uri('https://campus.example.invalid/sis/access/directory/roles/'))
    ->withHeader('X-CSRF-Token', 'fictionalcsrftoken')
    ->withBody(new \Laminas\Diactoros\Stream('php://temp', 'w+'));
$directoryRequest->getBody()->write(http_build_query(['_workspace_json' => json_encode(['search' => '', 'limit' => 2])]));
$directoryRequest->getBody()->rewind();
$directoryResponse = $app->router->match($directoryRequest);
if ($directoryResponse->getStatusCode() !== 200
    || !str_contains($directoryResponse->getHeaderLine('Cache-Control'), 'no-store')
    || count(json_decode((string) $directoryResponse->getBody(), true, flags: JSON_THROW_ON_ERROR)['items']) !== 2) {
    throw new RuntimeException('The bounded access directory did not resolve through the provider.');
}

// Exercise Vihzhuo's real block renderer and theme inheritance, using only an in-memory CMS.
$config = $app->configContainer->array('vihzhuo');
ini_set('session.save_path', $fixture);
$builder = new \Application\Service\CodefyPageBuilder($config);
global $phpb_db;
$phpb_db->query('CREATE TABLE settings (id INTEGER PRIMARY KEY, setting VARCHAR(50), value TEXT, is_array INTEGER)');
$phpb_db->query('CREATE TABLE page_translations (id INTEGER PRIMARY KEY, page_id INTEGER, locale VARCHAR(8), route VARCHAR(191))');
\Vihzhuo\Core\HttpContext::setRequest($get);
$page = new \Vihzhuo\Page();
$page->setData(['id' => 'fixture', 'layout' => 'portal', 'data' => \Application\Service\CampusPageTemplate::data()]);
$page->setTranslations(['en' => ['meta_title' => 'Fictional page', 'meta_description' => '', 'route' => '/']]);
foreach (['campus-harbor', 'campus-quad', 'campus-horizon'] as $slug) {
    $theme = new \Vihzhuo\Theme($config['theme'], $slug);
    $blocks = $theme->getThemeBlocks();
    foreach (['campus-hero', 'campus-pathways', 'campus-courses', 'campus-news', 'campus-programs', 'campus-invitation'] as $block) {
        if (!isset($blocks[$block]) || $blocks[$block]->get('cache') !== false) {
            throw new RuntimeException('Native campus block registration or privacy failed.');
        }
    }
    $html = new \Vihzhuo\Modules\GrapesJS\PageRenderer($theme, $page)->render();
    if (!str_contains($html, '/themes/' . $slug . '/public/css/theme.css')
        || !str_contains($html, 'Public fixture') || str_contains($html, 'Campus-only fixture')
        || str_contains($html, '[block slug=')) {
        throw new RuntimeException('Vihzhuo did not render its native portal blocks or inherited layout safely.');
    }
    $editor = new \Vihzhuo\Modules\GrapesJS\Block\BlockRenderer($theme, $page, true);
    $html = $editor->renderWithSlug('campus-hero', ['settings' => ['attributes' => ['headline' => '<script>fictional</script>']]]);
    if (!str_contains($html, '&lt;script&gt;fictional&lt;/script&gt;') || !str_contains($html, '<phpb-block')) {
        throw new RuntimeException('Editable native block settings were not safely escaped.');
    }
}
// The signature endpoint can resolve before constructing any HTTP kernel or parsing a JSON request body.
$webhook = $app->make(\Application\Http\Controller\PaymentWebhookController::class);
if ($webhook->receive('{"amount_total":12345}', '')->getStatusCode() !== 400) {
    throw new RuntimeException('An unsigned payment notification was accepted.');
}

$phpb_db->query('CREATE TABLE pages (id INTEGER PRIMARY KEY)');
$phpb_db->query('CREATE TABLE uploads (id INTEGER PRIMARY KEY)');
$empty = new \Vihzhuo\Page();
$empty->setData(['id' => 'empty-fixture', 'layout' => 'portal']);
$empty->setTranslations(['en' => ['meta_title' => 'Fictional new portal page', 'route' => '/fixture']]);
$editor = new \Application\Service\PageEditor();
\Codefy\Framework\Http\RequestContext::set($get);
$response = $editor->renderPageBuilder($empty);
if ($response->getStatusCode() !== 200 || $empty->getBuilderData() !== \Application\Service\CampusPageTemplate::data()) {
    throw new RuntimeException('An empty portal page did not offer an editable starter.');
}

session_write_close();
\Codefy\Framework\Http\RequestContext::clear();
