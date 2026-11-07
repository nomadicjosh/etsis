<?php

declare(strict_types=1);

// Isolated local fixture: no application bootstrap, credentials or institutional database.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && (str_starts_with($path, '/assets/') || str_starts_with($path, '/themes/'))) {
    return false;
}

require $root . '/vendor/autoload.php';


if ($path === '/' || str_starts_with($path, '/portal/') || in_array($path, ['/login/', '/register/'], true)) {
    $theme = in_array($_GET['theme'] ?? '', ['campus-harbor', 'campus-quad', 'campus-horizon'], true)
        ? $_GET['theme'] : 'campus-harbor';
    $signedIn = ($_GET['guest'] ?? '') !== '1';
    $config = \Qubus\Config\Collection::factory([]);
    $config->setConfigKey('portal', ['name' => 'Northstar', 'theme' => $theme, 'tagline' => 'Fictional campus fixture.']);
    $config->setConfigKey('auth', ['login_route' => 'login', 'password_min_length' => 26, 'username_min_length' => 6]);
    $gate = new class ($signedIn) implements \Codefy\Framework\Auth\Gate {
        public function __construct(private bool $signedIn) {}
        public function can(string $permissionName, array $ruleParams = []): bool { return false; }
        public function current(): object|bool|null { return $this->signedIn ? (object) ['user_id' => 'fictional-user'] : false; }
        public function guest(): bool { return !$this->signedIn; }
        public function isLoggedIn(): bool { return $this->signedIn; }
    };
    $renderer = new \Application\View\CampusRenderer($config, $gate);
    $public = ['terms' => [['id' => '01K00000000000000000000002', 'code' => '26/FA']],
        'programs' => [
            ['id' => '01K00000000000000000000001', 'code' => 'BS.CS', 'title' => 'Computer Science', 'form_id' => '01K00000000000000000000008'],
            ['id' => '01K00000000000000000000009', 'code' => 'BA.ART', 'title' => 'Arts & Creative Practice', 'form_id' => null],
            ['id' => '01K00000000000000000000010', 'code' => 'BS.BIO', 'title' => 'Biological Sciences', 'form_id' => null],
        ],
        'news' => [
            ['id' => '01K00000000000000000000011', 'code' => 'new-chapter', 'title' => 'A new chapter. A familiar sense of belonging.', 'content' => 'Fictional fixture: explore new spaces for learning and connection this fall.'],
            ['id' => '01K00000000000000000000012', 'code' => 'ideas', 'title' => 'Good ideas grow in good company.', 'content' => 'Fictional fixture: discover a community built around curiosity and a shared love of learning.'],
            ['id' => '01K00000000000000000000013', 'code' => 'registration', 'title' => 'Make a little room for possibility.', 'content' => 'Fictional fixture: your next semester starts with a spark. Explore course offerings and build your path.'],
        ]];
    $csrf = '<input type="hidden" name="csrf_token" value="fictional-test-token">';
    $data = $public + ['csrfMarkup' => $csrf, 'flashMarkup' => ''];
    $template = 'home';
    $title = 'A place for your next chapter';
    if ($path === '/portal/courses/') { $template = 'catalog'; $title = 'Explore courses'; }
    elseif ($path === '/portal/news/') { $template = 'news'; $title = 'Around campus'; }
    elseif (str_starts_with($path, '/portal/news/')) { $template = 'article'; $data['article'] = $public['news'][0]; }
    elseif ($path === '/login/' || $path === '/register/') {
        $template = 'auth'; $data += ['mode' => $path === '/login/' ? 'login' : 'register',
            'continue' => true, 'url' => $path === '/login/' ? '/auth/' : '/create/'];
    }
    elseif ($path === '/portal/apply/' || str_starts_with($path, '/portal/student/')) {
        $template = 'workspace';
        $section = trim(substr($path, strlen('/portal/student/')), '/') ?: 'overview';
        $data += ['section' => $path === '/portal/apply/' ? 'applications' : $section,
            'public' => $public, 'applicationPage' => $path === '/portal/apply/'];
    }
    $response = $renderer->render($template, $title, $data);
    http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) { header($name . ': ' . implode(', ', $values)); }
    echo $response->getBody();
    return;
}

$loader = new Qubus\View\Native\NativeLoader(['framework' => $root . '/resources/views']);
header('Cache-Control: private, no-store');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'");
if ($path === '/admin/error-fixture/') {
    $response = new \Application\Http\Middleware\SisPrivacyMiddleware()->process(
        new \Qubus\Http\ServerRequest([], [], '/admin/profile/update/?fictional-secret', 'POST')
            ->withHeader('Accept', 'text/html'),
        new \Qubus\Routing\Handlers\CallableRequestHandler(static fn () =>
            throw new \Qubus\Validation\ValidationException(message: 'fictional-secret payload', code: 422))
    );
    http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) { header($name . ': ' . implode(', ', $values)); }
    echo $response->getBody();
    return;
}
if ($path === '/admin/profile/') {
    echo $loader->render('framework::backend/profile', [
        'profile' => ['username' => 'alex_example', 'first_name' => 'Alex', 'middle_name' => '',
            'last_name' => 'Example', 'email' => 'alex@example.invalid'],
        'url' => '/admin/profile/update/', 'backUrl' => '/admin/', 'passwordLength' => 26,
        'csrfMarkup' => '<input type="hidden" name="csrf_token" value="fictional-test-token">',
        'methodMarkup' => '<input type="hidden" name="_method" value="PUT">', 'flashMarkup' => '',
    ]);
    return;
}
echo $loader->render('framework::backend/workspace/index', [
    'title' => 'Fictional campus · Workspace test',
    'section' => 'overview',
    'permissions' => Domain\Workspace\Service\WorkspaceService::PERMISSIONS,
    'displayName' => 'Taylor',
    'csrfMarkup' => '<input type="hidden" name="csrf_token" value="fictional-test-token">',
]);
