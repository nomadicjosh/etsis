<?php

declare(strict_types=1);

// Isolated local view fixture: no service boot or institutional database queries.
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

// Supply only the view facade used by layout language/charset attributes; do not boot services.
$viewConfig = \Qubus\Config\Collection::factory([]);
$viewConfig->setConfigKey('app', ['language' => 'en', 'charset' => 'UTF-8', 'locale_domain' => 'etsis']);
$viewApp = \Codefy\Framework\Helpers\get_fresh_bootstrap();
$viewApp->delegate(\Qubus\Config\ConfigContainer::class, static fn () => $viewConfig);
$viewApp->delegate('codefy.config', static fn () => $viewConfig);

if ($path === '/fixture/site-navigation/') {
    require_once $root . '/tests/Support/WebsiteFixture.php';
    $item = \Tests\Support\WebsiteFixture::item(...);
    $leaf = $item('leaf');
    $leaf['label'] = 'Fictional help';
    $leaf['url'] = 'https://example.invalid/help';
    $leaf['new_tab'] = true;
    $child = $item('child', 'group', children: [$leaf]);
    $child['label'] = 'Campus resources';
    $menu = $item('root', 'group', children: [$child]);
    $menu['label'] = 'Explore campus';
    $loader = new \Qubus\View\Native\NativeLoader(['framework' => $root . '/resources/views']);
    header('Cache-Control: private, no-store');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<title>Fictional dropdown fixture</title><link rel="stylesheet" href="/assets/vendor/bootstrap.min.css">'
    . '<link rel="stylesheet" href="/assets/css/site-navigation.css"></head><body>'
    . '<aside aria-label="Fictional sidebar" '
    . 'style="position:fixed;left:0;top:0;bottom:0;width:64px;z-index:1040;background:#203651"></aside>'
    . '<main style="margin-left:72px;padding:1rem"><h1>Fictional public navigation</h1>'
    . '<nav class="navbar" aria-label="Fictional website" '
    . 'style="justify-content:end;overflow:hidden">'
    . '<ul class="navbar-nav">'
    . $loader->render('framework::shared/site-navigation', ['items' => [$menu], 'prefix' => 'fictional-public'])
    . '</ul></nav></main><script src="/assets/vendor/bootstrap.bundle.min.js"></script>'
    . '<script src="/assets/js/site-navigation.js"></script></body></html>';
    return;
}

if ($path === '/fixture/website-page-settings/') {
    $loader = new \Qubus\View\Native\NativeLoader(['framework' => $root . '/resources/views']);
    $record = new \Vihzhuo\Page();
    $record->setData(['id' => 1, 'name' => 'Fictional admissions <page>', 'layout' => 'portal',
        'show_in_nav' => 'no', 'nav_position' => 3, 'nav_type' => 'primary']);
    $record->setTranslations(['en' => ['title' => 'Fictional admissions', 'route' => '/apply/',
        'meta_title' => '', 'meta_description' => ''], 'es' => ['title' => 'Admisiones', 'route' => '/solicitar/',
        'meta_title' => '', 'meta_description' => '']]);
    header('Cache-Control: private, no-store');
    echo $loader->render('framework::backend/manager/page-settings', [
        'page' => isset($_GET['create']) ? null : $record, 'submitted' => [],
        'languageOptions' => ['en' => 'English', 'es' => 'Español'],
        'layoutOptions' => [['slug' => 'portal', 'title' => 'Campus portal'], ['slug' => 'page-template', 'title' => 'Website page']],
        'csrfMarkup' => '<input type="hidden" name="csrf_token" value="fictional-test-token">',
    ]);
    return;
}

if (str_starts_with($path, '/fixture/builder-assets/')) {
    $assetRoot = realpath($root . '/vendor/nomadicjosh/vihzhuo/dist');
    $asset = realpath($assetRoot . '/' . substr($path, strlen('/fixture/builder-assets/')));
    if (!$asset || !is_file($asset) || !str_starts_with($asset, $assetRoot . '/')) {
        http_response_code(404); return;
    }
    $extension = pathinfo($asset, PATHINFO_EXTENSION);
    header('Content-Type: ' . match ($extension) {
        'js' => 'application/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'woff2' => 'font/woff2', default => 'application/octet-stream',
    });
    readfile($asset);
    return;
}
if ($path === '/fixture/website-editor/') {
    require __DIR__ . '/website-editor-fixture.php';
    return;
}

if ($path === '/fixture/website-manager/') {
    $loader = new \Qubus\View\Native\NativeLoader(['framework' => $root . '/resources/views']);
    header('Cache-Control: private, no-store');
    echo $loader->render('framework::backend/manager/site', [
        'mode' => ($_GET['mode'] ?? '') === 'pages' ? 'pages' : 'menus',
        'csrfMarkup' => '<input type="hidden" name="csrf_token" value="fictional-test-token">',
    ]);
    return;
}

if ($path === '/portal/api/events/') {
    require __DIR__ . '/public-events-fixture.php';
    $loader = new \Qubus\View\Native\NativeLoader(['framework' => $root . '/resources/views']);
    header('Cache-Control: private, no-store');
    header('Content-Type: application/json');
    echo json_encode(['html' => $loader->render(
        'framework::frontend/portal/components/event-calendar',
        publicEventsFixture($_GET)
    )], JSON_THROW_ON_ERROR);
    return;
}

if ($path === '/fixture/transcript-document/') {
    header('Cache-Control: private, no-store');
    $transcriptLayout = new \Infrastructure\Documents\RegisteredTranscriptLayouts()->get($_GET['layout'] ?? null);
    echo new \Infrastructure\Documents\ProtectedTranscriptRenderer()->html([
        'presentation' => $transcriptLayout->describe(),
        'standing' => ['through' => '2026-08-10', 'periods' => [
            ['code' => 'GOOD', 'title' => 'Fictional <b>Good standing</b>',
                'starts_on' => '2026-08-01', 'ends_on' => null],
        ]],
        'issuer' => ['name' => 'Fictional University', 'address' => 'Fictional campus'],
        'student' => ['name' => 'Alex Example', 'institutional_id' => 'S-FICTIONAL'],
        'program' => ['code' => 'BS.CS', 'title' => 'Computer Science'], 'preview' => ($_GET['preview'] ?? '') === '1',
        'document_id' => 'FICTIONAL-REFERENCE', 'issued_at' => '2026-11-07T12:00:00Z',
        'credits' => [[
            'term_code' => '26/FA', 'course_code' => 'MATH.101', 'course_title' => 'Algebra',
            'credit_type' => 'institutional', 'attempted_milli' => 3000,
            'completed_milli' => 3000, 'grade_code' => 'A',
        ]],
        'totals' => ['attempted_milli' => 3000, 'completed_milli' => 3000, 'gpa_milli' => 4000],
    ]);
    return true;
}



if ($path === '/' || str_starts_with($path, '/portal/') || in_array($path, ['/login/', '/register/'], true)) {
    $theme = in_array($_GET['theme'] ?? '', ['campus-harbor', 'campus-quad', 'campus-horizon'], true)
    ? $_GET['theme'] : 'campus-harbor';
    $signedIn = ($_GET['guest'] ?? '') !== '1';
    $config = \Qubus\Config\Collection::factory([]);
    $config->setConfigKey('portal', [
        'name' => 'Northstar', 'theme' => $theme, 'tagline' => 'Fictional campus fixture.',
    ]);
    $config->setConfigKey('auth', ['login_route' => 'login', 'password_min_length' => 26, 'username_min_length' => 6]);
    $gate = new class ($signedIn) implements \Codefy\Framework\Auth\Gate {
        public function __construct(private bool $signedIn)
        {
        }
        public function can(string $permissionName, array $ruleParams = []): bool
        {
            return false;
        }
        public function current(): object|bool|null
        {
            return $this->signedIn ? (object) ['user_id' => 'fictional-user'] : false;
        }
        public function guest(): bool
        {
            return !$this->signedIn;
        }
        public function isLoggedIn(): bool
        {
            return $this->signedIn;
        }
    };
    $renderer = new \Application\View\CampusRenderer($config, $gate);
    $public = ['terms' => [['id' => '01K00000000000000000000002', 'code' => '26/FA']],
        'programs' => [
            ['id' => '01K00000000000000000000001', 'code' => 'BS.CS',
                'title' => 'Computer Science', 'form_id' => '01K00000000000000000000008'],
            ['id' => '01K00000000000000000000009', 'code' => 'BA.ART',
                'title' => 'Arts & Creative Practice', 'form_id' => null],
            ['id' => '01K00000000000000000000010', 'code' => 'BS.BIO',
                'title' => 'Biological Sciences', 'form_id' => null],
        ],
        'news' => [
            ['id' => '01K00000000000000000000011', 'code' => 'new-chapter',
                'title' => 'A new chapter. A familiar sense of belonging.',
                'content' => 'Fictional fixture: explore new spaces for learning and connection this fall.'],
            ['id' => '01K00000000000000000000012', 'code' => 'ideas',
                'title' => 'Good ideas grow in good company.',
                'content' => 'Fictional fixture: discover a community built around curiosity '
                    . 'and a shared love of learning.'],
            ['id' => '01K00000000000000000000013', 'code' => 'registration',
                'title' => 'Make a little room for possibility.',
                'content' => 'Fictional fixture: your next semester starts with a spark. '
                    . 'Explore course offerings and build your path.'],
        ]];
    $csrf = '<input type="hidden" name="csrf_token" value="fictional-test-token">';
    $data = $public + ['csrfMarkup' => $csrf, 'flashMarkup' => ''];
    $template = 'home';
    $title = 'A place for your next chapter';
    if ($path === '/portal/courses/') {
        $template = 'catalog';
        $title = 'Explore courses';
    } elseif ($path === '/portal/events/') {
        require __DIR__ . '/public-events-fixture.php';
        $template = 'events';
        $title = 'Find your place on campus';
        $data += publicEventsFixture($_GET);
    } elseif ($path === '/portal/news/') {
        $template = 'news';
        $title = 'Around campus';
    } elseif (str_starts_with($path, '/portal/news/')) {
        $template = 'article';
        $data['article'] = $public['news'][0];
    } elseif ($path === '/portal/password-setup/') {
        $template = 'account-setup';
        $data += ['completed' => $_SERVER['REQUEST_METHOD'] === 'POST', 'error' => null];
    } elseif ($path === '/login/' || $path === '/register/') {
        $template = 'auth'; $data += ['mode' => $path === '/login/' ? 'login' : 'register',
            'continue' => true, 'signInFailed' => ($_GET['notice'] ?? '') === 'credentials',
            'url' => $path === '/login/' ? '/auth/' : '/create/'];
    } elseif (
        in_array($path, [
            '/portal/apply/', '/portal/timetable/', '/portal/bookings/', '/portal/admissions-updates/',
        ], true)
        || str_starts_with($path, '/portal/student/')
    ) {
        $template = 'workspace';
        $section = trim(substr($path, strlen('/portal/student/')), '/') ?: 'overview';
        $data += ['section' => match ($path) {
            '/portal/apply/' => 'applications', '/portal/timetable/' => 'timetable', '/portal/bookings/' => 'bookings',
            '/portal/admissions-updates/' => 'admissionsupdates',
            default => $section,
        },
            'public' => $public, 'applicationPage' => $path === '/portal/apply/'];
    }
    $response = $renderer->render($template, $title, $data);
    http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) {
        header($name . ': ' . implode(', ', $values));
    }
    echo $response->getBody();
    return;
}

$loader = new Qubus\View\Native\NativeLoader(['framework' => $root . '/resources/views']);
header('Cache-Control: private, no-store');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; "
    . "style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; "
    . "connect-src 'self'; frame-ancestors 'self'");
if ($path === '/admin/error-fixture/') {
    $response = new \Application\Http\Middleware\SisPrivacyMiddleware()->process(
        new \Qubus\Http\ServerRequest([], [], '/admin/profile/update/?fictional-secret', 'POST')
            ->withHeader('Accept', 'text/html'),
        new \Qubus\Routing\Handlers\CallableRequestHandler(static fn () =>
            throw new \Qubus\Validation\ValidationException(message: 'fictional-secret payload', code: 422))
    );
    http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) {
        header($name . ': ' . implode(', ', $values));
    }
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
    'section' => preg_match('~^/admin/workspace/([^/]+)/?$~', (string) $path, $workspaceMatch) === 1
        ? $workspaceMatch[1] : 'overview',
    'permissions' => match ($path) {
        '/admin/admissions-only-fixture/' => ['sis:admissions:manage'],
        '/admin/form-editor-fixture/' => ['sis:forms:manage'],
        '/admin/grade-amend-fixture/' => ['sis:student:read', 'sis:gradebook:amend'],
        '/admin/closed-grade-fixture/' => ['sis:student:read', 'sis:gradebook:amend', 'sis:gradebook:closed'],
        '/admin/student-read-fixture/' => ['sis:student:read'],
        '/admin/hr-only-fixture/' => ['sis:hr:manage'],
        '/admin/waitlist-office-fixture/' => ['sis:catalog:manage', 'sis:registration:manage',
            'sis:registration:waitlist'],
        '/admin/transcript-office-fixture/' => ['sis:transcript:manage'],
        '/admin/campus-content-fixture/' => ['sis:portal:manage'],
        '/admin/campus-publisher-fixture/' => ['sis:portal:manage', 'sis:portal:publish'],
        '/admin/account-read-fixture/' => ['sis:finance:read'],
        '/admin/person-contact-fixture/' => ['sis:person:manage'],
        '/admin/specialization-fixture/' => ['sis:student:read', 'sis:degree-audit:assign'],
        '/admin/moodle-denied-fixture/' => ['sis:catalog:manage'],
        '/admin/moodle-person-denied-fixture/' => ['sis:person:manage', 'sis:moodle:manage'],
        '/admin/moodle-request-denied-fixture/' => ['sis:person:manage', 'sis:moodle:link-person', 'sis:moodle:manage'],
        '/admin/moodle-recovery-denied-fixture/' => ['sis:person:manage', 'sis:moodle:create-person'],
        '/admin/hold-office-fixture/' => ['sis:restrictions:manage'],
        '/admin/course-import-fixture/' => ['sis:import:manage'],
        '/admin/hold-handoff-fixture/' => ['sis:restrictions:manage', 'sis:restrictions:handoff'],
        '/admin/office-admin-fixture/' => ['admin:access:manage', 'sis:restrictions:manage'],
        '/admin/registration-policy-fixture/' => ['sis:registration:manage', 'sis:registration:policy'],
        '/admin/registration-fixture/' => ['sis:registration:manage'],
        '/admin/registration-plan-fixture/' => ['sis:registration:manage', 'sis:student:read'],
        '/admin/report-fixture/' => ['sis:reporting:run'],
        '/admin/report-billing-fixture/' => ['sis:reporting:run', 'sis:student:read',
            'sis:finance:read', 'sis:finance:post'],
        '/admin/report-messaging-fixture/' => ['sis:reporting:run', 'sis:student:read',
            'sis:communications:manage', 'sis:person:manage'],
        '/admin/report-manager-fixture/' => ['sis:reporting:manage'],
        '/admin/communications-fixture/' => ['sis:communications:manage'],
        '/admin/communications-admin-fixture/' => ['sis:communications:manage', 'sis:communications:admin'],
        '/admin/transfer-fixture/' => ['sis:transfer:manage', 'sis:student:read'],
        '/admin/transfer-only-fixture/' => ['sis:transfer:manage'],
        default => Domain\Workspace\Service\WorkspaceService::PERMISSIONS,
    },
    'displayName' => 'Taylor',
    'csrfMarkup' => '<input type="hidden" name="csrf_token" value="fictional-test-token">',
]);
