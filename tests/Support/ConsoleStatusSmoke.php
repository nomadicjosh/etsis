<?php

declare(strict_types=1);

// Separate process, fictional configuration, no institutional database or .env.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$fixture = sys_get_temp_dir() . '/etsis-console-' . bin2hex(random_bytes(6));
mkdir($fixture);
mkdir($fixture . '/config');
ini_set('session.save_path', $fixture);
foreach (glob($root . '/config/*.php') as $file) {
    copy($file, $fixture . '/config/' . basename($file));
}
foreach (['bootstrap', 'database', 'resources', 'public'] as $directory) {
    symlink($root . '/' . $directory, $fixture . '/' . $directory);
}
foreach (
    ['APP_BASE_PATH' => $fixture, 'DB_CONNECTION' => 'sqlite', 'DB_DSN' => 'sqlite::memory:',
    'APP_ENV' => 'testing', 'APP_TIMEZONE' => 'UTC',
    'APP_BASE_URL' => 'https://campus.example.invalid'] as $key => $value
) {
    $_ENV[$key] = $_SERVER[$key] = $value;
}
try {
    chdir($root);
    if (($argv[1] ?? '') === 'check-course-route') {
        $_SERVER['DOCUMENT_ROOT'] = $fixture . '/public';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/sis/courses/';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST = ['_workspace_json' => json_encode(['code' => 'Math-101', 'title' => 'Algebra',
            'credits_milli' => 3000], JSON_THROW_ON_ERROR)];
    }
    if (
        in_array(
            ($argv[1] ?? ''),
            [
                'check-document-holds',
                'check-document-retention',
                'check-historical-finance',
                'check-primary-position',
                'check-recorded-progress',
                'check-academic-organization',
                'check-group-work-eligibility',
                'check-component-major-links',
                'check-curriculum-reports',
                'check-registered-credits',
                'check-credit-policies',
                'check-ceus',
                'check-earned-ceus',
                'check-withdrawn-ceus',
                'check-website-publications',
                'check-coursework-grade-policy',
                'check-curriculum-components',
                'check-enrollment-reinstatement',
                'check-waitlists',
                'check-workload-policy',
                'check-grade-certification',
                'check-support-history',
                'check-catalog-projections',
                'check-curriculum-projections',
                'check-program-campus',
                'check-standing-proposals',
                'check-standing-load',
                'check-person-ssn',
                'check-ssn-history',
                'check-maintenance',
                'check-notification-preferences',
                'check-transcript-updates',
                'check-standing-updates',
                'check-public-event-feed',
                'check-timetable-export',
                'check-transcript-worklist',
                'check-profile-requests',
                'check-portal',
                'check-admission-readiness',
                'check-admission-intake',
                'check-admission-changes',
                'check-drop-policy',
                'check-hiatus-history',
                'check-transcript-reviews',
                'check-registration-schedule',
                'check-gradebook-calculation',
                'check-grade-changes',
                'check-section-classifications',
                'check-program-classifications',
                'check-profile-command',
                'check-login-sessions',
                'check-mapped-imports',
                'check-standing',
                'check-enrollment-load',
                'check-content-publication',
                'check-announcements',
                'check-program-discovery',
                'check-prerequisite-permissions',
                'check-advising',
                'check-graduate-registration',
                'check-graduate-history',
                'check-moodle-resolution',
                'check-course-rules',
                'check-prerequisite-groups',
                'check-degree-trials',
                'check-transfer-corrections',
                'check-report-exports',
                'check-transfer-completion-report',
                'check-institutional-dates',
                'check-student-contacts',
            ],
            true,
        )
    ) {
        $_SERVER['DOCUMENT_ROOT'] = $fixture . '/public';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/portal/';
        $_SERVER['HTTP_HOST'] = 'campus.example.invalid';
        $_SERVER['HTTPS'] = 'on';
    }
    if (($argv[1] ?? '') === 'check-registration-throttle') {
        $_SERVER['DOCUMENT_ROOT'] = $fixture . '/public';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/create/';
        $_SERVER['HTTP_HOST'] = 'campus.example.invalid';
        $_SERVER['HTTPS'] = 'on';
    }
    $app = \Codefy\Framework\Helpers\get_fresh_bootstrap();
    if (($argv[1] ?? '') === 'check-maintenance') {
        require __DIR__ . '/MaintenanceBootChecks.php';
        $status = 0;
    } elseif (($argv[1] ?? '') === 'check-early-webhook') {
        $app->bootstrapWith([\Codefy\Framework\Bootstrap\RegisterProviders::class]);
        $webhook = $app->make(\Application\Http\Controller\PaymentWebhookController::class);
        $response = $webhook->receive('{"amount_total":12345}', '');
        if ($response->getStatusCode() !== 400) {
            throw new RuntimeException('An unsigned early webhook was not rejected.');
        }
        $status = 0;
    } else {
        $kernel = $app->make(Application\Console\Kernel::class);
        if (($argv[1] ?? '') === 'check-course-route') {
            require_once __DIR__ . '/SisFixture.php';
            $sis = new SisFixture();
            $app->delegate(\Qubus\Expressive\Connection::class, static fn () => $sis->connection);
            $app->delegate(\Domain\Shared\Security\StudentAccess::class, static fn () => $sis->access);
            $kernel->bootstrap();
            // Exercise route DI, firewall, CSRF validation, privacy and debug suppression.
            $app->router->baseMiddleware = ['sis.privacy', 'sis.json', 'firewall', 'csrf.protection',
                'bind.request', 'php.debugbar'];
            $request = new \Qubus\Http\ServerRequest([], [], '/sis/courses/', 'POST')
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withHeader('Accept', 'application/json')
                ->withHeader('X-CSRF-Token', 'fictionalcsrftoken')
                ->withAttribute(
                    \Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::CSRF_SESSION_ATTRIBUTE,
                    'fictionalcsrftoken'
                )
                ->withBody(new \Laminas\Diactoros\Stream('php://temp', 'w+'));
            $request->getBody()->write(http_build_query($_POST));
            $request->getBody()->rewind();
            $response = $app->router->match($request);
            if ($response->getStatusCode() !== 201) {
                throw new RuntimeException('Course route expected HTTP 201 JSON, received HTTP '
                    . $response->getStatusCode() . ' with ' . $response->getHeaderLine('Content-Type'));
            }
            $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $course = $sis->sql->one('sis_courses', ['id' => $body['id']]);
            if (
                $course['code'] !== 'Math-101' || $course['title'] !== 'Algebra'
                || (int) $course['credits_milli'] !== 3000
            ) {
                throw new RuntimeException('Course details were not persisted.');
            }
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-registration-throttle') {
            $kernel->bootstrap();
            $app->configContainer->setConfigKey('app', ['crypto_key' => str_repeat('fictional-registration-key-', 2)]);
            $app->configContainer->setConfigKey('throttle', [
                'max_attempts' => 5, 'ttl' => 600,
                'registration' => ['max_attempts' => 1, 'ttl' => 600],
            ]);
            $middleware = new \Qubus\Routing\Route\InjectorMiddlewareResolver($app)
                ->resolve('registration.throttle');
            $calls = 0;
            $handler = new \Qubus\Routing\Handlers\CallableRequestHandler(
                static function () use (&$calls): \Psr\Http\Message\ResponseInterface {
                    $calls++;
                    return new \Laminas\Diactoros\Response\JsonResponse(['ok' => true]);
                },
            );
            $request = new \Qubus\Http\ServerRequest(
                ['REMOTE_ADDR' => '192.0.2.15'],
                [],
                'https://campus.example.invalid/create/',
                'POST',
            )
                ->withHeader('Accept', 'text/html');
            $allowed = $middleware->process($request, $handler);
            $limited = $middleware->process($request, $handler);
            if (
                $allowed->getStatusCode() !== 200 || $limited->getStatusCode() !== 429 || $calls !== 1
                || !str_contains($limited->getHeaderLine('Cache-Control'), 'private, no-store')
                || !str_contains((string) $limited->getBody(), 'Please wait before trying again')
            ) {
                throw new RuntimeException('The registration throttle alias/cache boundary did not resolve correctly.');
            }
            // Actual /create/ routing: CSRF rejects first, then the exhausted counter stops controller resolution.
            $app->router->baseMiddleware = ['sis.privacy', 'csrf.protection', 'bind.request', 'php.debugbar'];
            $routed = $request->withHeader('Accept', 'application/json')->withAttribute(
                \Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::CSRF_SESSION_ATTRIBUTE,
                'fictionalcsrftoken',
            );
            if ($app->router->match($routed)->getStatusCode() !== 403) {
                throw new RuntimeException('Public registration bypassed framework CSRF.');
            }
            $routed = $routed->withHeader('X-CSRF-Token', 'fictionalcsrftoken');
            $blocked = $app->router->match($routed);
            if (
                $blocked->getStatusCode() !== 429 || $blocked->hasHeader('phpdebugbar-id')
                || !str_contains($blocked->getHeaderLine('Cache-Control'), 'private, no-store')
            ) {
                throw new RuntimeException('The actual registration route lost its private counter guard.');
            }
            $identifier = hash_hmac(
                'sha256',
                'public-registration:' . inet_pton('192.0.2.15'),
                str_repeat('fictional-registration-key-', 2),
            );
            $app->make(\Application\Http\Security\RegistrationCounterLock::class)->run(
                $identifier,
                static function () use ($app, $routed): void {
                    $busy = $app->router->match($routed);
                    if ($busy->getStatusCode() !== 503 || $busy->getHeaderLine('Retry-After') !== '5') {
                        throw new RuntimeException('The actual registration route did not fail closed on a busy lock.');
                    }
                },
            );
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-admission-readiness') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/AdmissionReadinessBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-prerequisite-groups') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/PrerequisiteGroupsBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-program-classifications') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/ProgramClassificationBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-prerequisite-permissions') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/PrerequisitePermissionBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-announcements') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/AnnouncementBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-program-discovery') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/ProgramDiscoveryBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-content-publication') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/ContentPublicationBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-enrollment-load') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/EnrollmentLoadBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-mapped-imports') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/MappedImportBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-standing') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/StandingBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-website-publications') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/WebsitePublicationBootChecks.php';
            $status = 0;
        } elseif (in_array(($argv[1] ?? ''), ['check-earned-ceus', 'check-withdrawn-ceus'], true)) {
            $kernel->bootstrap();
            require_once __DIR__ . '/EarnedCeuFixture.php';
            $earnedFixture = new \Tests\Support\EarnedCeuFixture(
                withdrawn: ($argv[1] ?? '') === 'check-withdrawn-ceus',
            );
            $httpSis = $earnedFixture->sis;
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/EarnedCeuBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-ceus') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/ContinuingEducationBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-credit-policies') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/CreditPolicyBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-registered-credits') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/RegisteredCreditBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-curriculum-reports') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/CurriculumReportBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-component-major-links') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/ComponentMajorBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-academic-organization') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/AcademicOrganizationBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-curriculum-components') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/CurriculumComponentBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-enrollment-reinstatement') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/EnrollmentReinstatementBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-waitlists') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/WaitlistBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-workload-policy') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/WorkloadPolicyBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-admission-intake') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/AdmissionDeferralBootChecks.php';
            require __DIR__ . '/AdmissionProgramBootChecks.php';
            require __DIR__ . '/ApplicantApplicationHistoryBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-admission-changes') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/AdmissionChangeRequestBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-grade-certification') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/GradeCertificationBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-support-history') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/HistoryCheckBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-graduate-history') {
            require __DIR__ . '/GraduateHistoryBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-graduate-registration') {
            $kernel->bootstrap();
            require __DIR__ . '/GraduateRegistrationFixture.php';
            $graduateFixture = new \Tests\Support\GraduateRegistrationFixture();
            $httpSis = $graduateFixture->sis;
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/GraduateRegistrationBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-transcript-worklist') {
            $kernel->bootstrap();
            require __DIR__ . '/TranscriptWorklistFixture.php';
            $worklistFixture = new \Tests\Support\TranscriptWorklistFixture();
            $httpSis = $worklistFixture->transcripts->sis;
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/TranscriptWorklistBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-timetable-export') {
            $kernel->bootstrap();
            require __DIR__ . '/TimetableExportFixture.php';
            $calendarFixture = new \Tests\Support\TimetableExportFixture();
            $httpSis = $calendarFixture->school->sis;
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/TimetableExportBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-public-event-feed') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/PublicEventFeedBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-standing-updates') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/StandingUpdatesBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-transcript-updates') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/TranscriptUpdatesBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-notification-preferences') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/NotificationPreferencesBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-advising') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/AdvisingBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-section-classifications') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/SectionClassificationBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-transfer-corrections') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/TransferCorrectionBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-grade-changes') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/GradeChangeBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-historical-finance') {
            $kernel->bootstrap();
            require __DIR__ . '/HistoricalFinanceBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-document-retention') {
            $kernel->bootstrap();
            require __DIR__ . '/AdmissionRetentionBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-document-holds') {
            $kernel->bootstrap();
            require __DIR__ . '/AdmissionDocumentHoldBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-primary-position') {
            $kernel->bootstrap();
            require __DIR__ . '/PrimaryPositionBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-recorded-progress') {
            $kernel->bootstrap();
            require __DIR__ . '/RecordedProgressBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-group-work-eligibility') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/GroupWorkEligibilityBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-coursework-grade-policy') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/CourseworkGradePolicyBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-gradebook-calculation') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/GradebookCalculationBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-registration-schedule') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/RegistrationScheduleBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-transcript-reviews') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/TranscriptReviewBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-hiatus-history') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/HiatusHistoryBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-drop-policy') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/DropPolicyBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-portal') {
            $kernel->bootstrap();
            require __DIR__ . '/PortalBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-profile-command') {
            $kernel->bootstrap();
            require __DIR__ . '/ProfileCommandBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-login-sessions') {
            require __DIR__ . '/LoginSessionBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-moodle-resolution') {
            require __DIR__ . '/MoodleResolutionBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-profile-requests') {
            $kernel->bootstrap();
            require __DIR__ . '/ProfileRequestsBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-employment-projections') {
            require __DIR__ . '/EmploymentProjectionBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-waitlist-projections') {
            require __DIR__ . '/WaitlistProjectionBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-account-recovery') {
            require __DIR__ . '/AccountRecoveryBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-standing-proposals') {
            require __DIR__ . '/StandingProposalBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-standing-load') {
            $kernel->bootstrap();
            require __DIR__ . '/StandingLoadBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-program-campus') {
            require __DIR__ . '/ProgramCampusBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-curriculum-projections') {
            require __DIR__ . '/CurriculumProjectionBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-catalog-projections') {
            require __DIR__ . '/CatalogProjectionBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-student-decision-projections') {
            require __DIR__ . '/StudentDecisionProjectionBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-application-projections') {
            require __DIR__ . '/ApplicationProjectionBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-ssn-history') {
            require __DIR__ . '/PersonSsnHistoryBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-person-ssn') {
            $kernel->bootstrap();
            require __DIR__ . '/PersonSsnBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-person-projections') {
            require __DIR__ . '/PersonProjectionBootChecks.php';
            $status = 0;
        } elseif (
            in_array(
                $argv[1] ?? '',
                ['check-history-verification', 'check-account-projections', 'check-academic-projections'],
                true,
            )
        ) {
            require __DIR__ . '/HistoryVerificationBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-history-anchor') {
            require __DIR__ . '/HistoryAnchorBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-degree-trials') {
            require __DIR__ . '/DraftDegreeAuditBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-course-rules') {
            require __DIR__ . '/CourseRuleBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-report-exports') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/ReportExportBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-student-contacts') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/StudentContactReportBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-institutional-dates') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/InstitutionalDateBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-transfer-completion-report') {
            $kernel->bootstrap();
            require __DIR__ . '/SisHttpFixture.php';
            require __DIR__ . '/TransferCompletionReportBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-report-retention') {
            require __DIR__ . '/ReportRetentionBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-session-retention') {
            require __DIR__ . '/SessionRetentionBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-moodle') {
            $kernel->bootstrap();
            $app->configContainer->setConfigKey('moodle', ['enabled' => false]);
            $probe = $app->make(\Domain\Lms\Service\MoodleConnectionProbe::class);
            if (!$probe instanceof \Infrastructure\Lms\RestMoodleGateway || $probe->enabled()) {
                throw new RuntimeException('Moodle diagnostic provider failed to resolve in disabled mode.');
            }
            $command = new \Application\Console\Commands\MoodleCheckCommand($app, $probe);
            $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
            if ($tester->execute([]) !== 0 || !str_contains($tester->getDisplay(), 'disabled')) {
                throw new RuntimeException('Disabled Moodle diagnostic did not complete.');
            }
            $sync = new \Symfony\Component\Console\Tester\CommandTester($kernel->all()['sis:moodle:sync']);
            if (
                $sync->execute(['--limit' => '21']) !== 2 || $sync->execute([]) !== 0 ||
                !str_contains($sync->getDisplay(), 'disabled')
            ) {
                throw new RuntimeException('Disabled section synchronization failed its command boundary.');
            }
            if ($kernel->call('sis:moodle:provision') !== 0) {
                throw new RuntimeException('The disabled Moodle provisioning command was not registered.');
            }
            $worker = $app->make(\Domain\Lms\Service\MoodleProvisioningWorker::class);
            $provision = new \Symfony\Component\Console\Tester\CommandTester(
                new \Application\Console\Commands\MoodleProvisionCommand($app),
            );
            if ($provision->execute([]) !== 0 || !str_contains($provision->getDisplay(), 'disabled')) {
                throw new RuntimeException('Disabled Moodle worker did not resolve without network or queue access.');
            }
            $reader = $app->make(\Domain\Lms\Service\MoodleRecordReader::class);
            $inspect = new \Symfony\Component\Console\Tester\CommandTester(
                new \Application\Console\Commands\MoodleInspectCommand($app, $reader),
            );
            if (
                $inspect->execute(['resource' => 'user', 'remote_id' => '123']) !== 0 ||
                !str_contains($inspect->getDisplay(), 'disabled')
            ) {
                throw new RuntimeException('Disabled Moodle record reader did not resolve without network access.');
            }
            $instanceReader = $app->make(\Domain\Lms\Service\MoodleInstanceReader::class);
            $instance = new \Symfony\Component\Console\Tester\CommandTester(
                new \Application\Console\Commands\MoodleInstanceCommand($app, $instanceReader),
            );
            if ($instance->execute([]) !== 0 || !str_contains($instance->getDisplay(), 'disabled')) {
                throw new RuntimeException('Disabled Moodle instance reader did not resolve without network access.');
            }
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-authentication') {
            $kernel->bootstrap();
            if (
                !$app->get(\Codefy\Framework\Auth\Sentinel::class) instanceof \Codefy\Framework\Auth\Auth
                || !$app->get(\Codefy\Framework\Auth\Rbac\Resource\StorageResource::class)
                    instanceof \Infrastructure\Security\Rbac\SqlStorageResource
            ) {
                throw new RuntimeException('Framework authentication must use database RBAC storage.');
            }
            $middleware = new \Qubus\Routing\Route\InjectorMiddlewareResolver($app)->resolve('user.authenticate');
            $handler = new \Qubus\Routing\Handlers\CallableRequestHandler(static function (): never {
                throw new RuntimeException('Unauthenticated request reached the protected handler.');
            });
            $response = $middleware->process(new \Qubus\Http\ServerRequest([], [], '/auth/', 'POST'), $handler);
            if (
                $response->getStatusCode() !== 302
                || $response->getHeaderLine('Location') !== '/login/?notice=credentials'
            ) {
                throw new RuntimeException('Authentication middleware did not reject missing credentials.');
            }
            $status = 0;
        } else {
            $status = $kernel->call($argv[1] ?? 'migrate:status');
        }
    }
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $fixture,
        FilesystemIterator::SKIP_DOTS
    ), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($fixture);
}
exit($status);
