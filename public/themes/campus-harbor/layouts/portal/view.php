<?php

declare(strict_types=1);

// phpcs:disable Generic.WhiteSpace.ScopeIndent -- Follow the enclosing HTML indentation in this mixed template.

use Codefy\Framework\Proxy\Codefy;
use Vihzhuo\Core\ThemeContext;

use function Codefy\Framework\Helpers\trans_attr;
use function Codefy\Framework\Helpers\trans_html;
use function Codefy\Framework\Helpers\view;
use function Application\Shared\Helpers\menu_items;

$esc = static fn(mixed $value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    "UTF-8",
);
$portalName = $portalName ?? \Codefy\Framework\Helpers\config("portal.name", "etsis");
$portalSection = $portalSection ?? "home";
$loginUrl = $loginUrl ?? "/" . trim(\Codefy\Framework\Helpers\config("auth.login_route", "login"), "/") . "/";
$signedIn = $signedIn ?? \Codefy\Framework\Helpers\gate()->isLoggedIn();
$staffAccess = $staffAccess ?? $signedIn && \Codefy\Framework\Helpers\gate("sis:person:manage");
$theme = ThemeContext::current();
$themeAsset = $theme?->getAssetPath("public/css/theme.css") ?? "/themes/campus-harbor/public/css/theme.css";
$title = $page->getTranslation("meta_title", "en") ?: $portalName;
$siteNavigation ??= [];
foreach (['primary', 'secondary', 'footer'] as $menuSlot) {
    $siteNavigation[$menuSlot] ??= (string) view('framework::shared/site-navigation', [
        'items' => menu_items($menuSlot), 'prefix' => 'campus-' . $menuSlot,
    ])->getBody();
}
?>
<!doctype html>
<html lang="<?=Codefy::$PHP->configContainer->string('app.language');?>">
    <head>
        <meta charset="<?=Codefy::$PHP->configContainer->string('app.charset');?>" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="referrer" content="same-origin" />
        <meta content="noindex,nofollow" name="robots">
        <title><?= $esc($title) ?> · <?= $esc($portalName) ?></title>
        <link rel="icon" href="/assets/images/workspace-mark.svg" type="image/svg+xml" />
        <link rel="stylesheet" href="/assets/vendor/bootstrap.min.css" />
        <link rel="stylesheet" href="/assets/vendor/icons/bootstrap-icons.min.css" />
        <link rel="stylesheet" href="/assets/css/portal.css" />
        <link rel="stylesheet" href="/assets/css/application-form.css" />
        <link rel="stylesheet" href="/assets/css/degree-progress.css" />
        <link rel="stylesheet" href="/assets/css/review-guide.css" />
        <link rel="stylesheet" href="<?= $esc($themeAsset) ?>" />
        <?php if ($signedIn) : ?>
            <link rel="stylesheet" href="/assets/css/notifications.css" />
            <script defer src="/assets/js/campus-announcements.js"></script>
            <script defer src="/assets/js/workspace-notifications.js"></script>
        <?php endif; ?>
        <link rel="stylesheet" href="/assets/css/site-navigation.css">
        <script defer src="/assets/vendor/bootstrap.bundle.min.js"></script>
        <script defer src="/assets/js/site-navigation.js"></script>
        <script defer src="/assets/js/review-guidance.js"></script>
        <script defer src="/assets/js/application-form.js"></script>
        <script defer src="/assets/js/application-revisions.js"></script>
        <script id="curriculum-ownership-labels" type="application/json"><?= json_encode(
            require dirname(__DIR__, 5) . '/resources/views/shared/curriculum-ownership-labels.php',
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        ) ?></script>
        <script defer src="/assets/js/degree-progress-guide.js"></script>
        <script defer src="/assets/js/person-emergency-contacts.js"></script>
        <script id="profile-request-labels" type="application/json"><?= json_encode(
            require dirname(__DIR__, 5) . '/resources/views/shared/profile-request-labels.php',
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        ) ?></script>
        <script defer src="/assets/js/person-profile-requests.js"></script>
        <script defer src="/assets/js/person-education.js"></script>
        <script defer src="/assets/js/admission-documents.js"></script>
        <script defer src="/assets/js/admission-policy.js"></script>
        <script defer src="/assets/js/admission-offers.js"></script>
        <script defer src="/assets/js/admission-withdrawals.js"></script>
        <script defer src="/assets/js/admission-readiness.js"></script>
        <script defer src="/assets/js/admission-casework.js"></script>
        <script defer src="/assets/js/person-addresses.js"></script>
        <script defer src="/assets/js/academic-timetable.js"></script>
        <script defer src="/assets/js/public-events.js"></script>
        <script defer src="/assets/js/registration-schedule.js"></script>
        <script defer src="/assets/js/registration-drops.js"></script>
        <script defer src="/assets/js/gradebook-calculation.js"></script>
        <script defer src="/assets/js/advising-checkins.js"></script>
        <script id="enrollment-load-labels" type="application/json"><?= json_encode(
            require dirname(__DIR__, 5) . '/resources/views/shared/enrollment-load-labels.php',
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        ) ?></script>
        <script defer src="/assets/js/enrollment-load.js"></script>
        <script defer src="/assets/vendor/purify.min.js"></script>
        <script defer src="/assets/js/campus-rich-text.js"></script>
        <script defer src="/assets/js/section-waitlist.js"></script>
        <script defer src="/assets/js/portal.js"></script>
    </head>
    <body class="campus-site">
        <a class="campus-skip" href="#campus-main">Skip to main content</a>
        <div class="campus-utility">
            <div class="campus-container">
                <span>A place to learn. A place to belong.</span>
                <a href="/portal/student/">
                    My campus
                    <i class="bi bi-arrow-up-right" aria-hidden="true"></i>
                </a>
            </div>
        </div>
        <header class="campus-header">
            <nav class="navbar navbar-expand-lg campus-container" aria-label="Main navigation">
                <a class="campus-brand" href="/">
                    <span class="campus-crest" aria-hidden="true"><i class="bi bi-book"></i></span>
                    <span>
                        <?= $esc($portalName) ?>
                        <small>LEARNING. POSSIBILITY. YOU.</small>
                    </span>
                </a>
                <?php if ($signedIn) : ?>
                    <button
                        id="campus-notifications-open"
                        class="workspace-notification-trigger ms-auto mx-3"
                        type="button"
                        aria-haspopup="dialog"
                        aria-expanded="false"
                        aria-label="<?= trans_attr('Recent notifications') ?>">
                        <i class="bi bi-bell" aria-hidden="true"></i>
                        <span
                            data-notification-count
                            class="workspace-notification-count"
                            aria-hidden="true"
                            hidden></span>
                    </button>
                <?php endif; ?>
                <button
                    class="navbar-toggler"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#campus-navigation"
                    aria-controls="campus-navigation"
                    aria-expanded="false"
                    aria-label="Open navigation"
                >
                    <i class="bi bi-list" aria-hidden="true"></i>
                </button>
                <div class="collapse navbar-collapse" id="campus-navigation">
                    <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-3">
                        <?= $siteNavigation['primary'] ?>
                        <li class="nav-item">
                            <a class="nav-link" href="/portal/courses/">Courses</a>
                        </li>
                        <li class="nav-item"><a class="nav-link" href="/portal/news/">Updates</a></li>
                        <li class="nav-item"><a class="nav-link" href="/portal/events/">Events</a></li>
                        <?php if ($staffAccess) : ?>
                            <li class="nav-item"><a class="nav-link" href="/admin/">Staff workspace</a></li>
                        <?php endif; ?>
                        <?php if ($signedIn && ($moodleLaunchEnabled ?? false)) : ?>
                            <li class="nav-item"><a class="nav-link" href="/portal/moodle/">Moodle</a></li>
                        <?php endif; ?>
                        <?php if ($signedIn) : ?>
                            <li class="nav-item"><a class="nav-link" href="/portal/student/">My campus</a></li>
                            <li class="nav-item">
                                <form method="post" action="/logout/" class="m-0">
                            <?= $csrfMarkup ?? csrf_field() ?>
                                    <button class="nav-link" type="submit">Sign out</button>
                                </form>
                            </li>
                        <?php else : ?>
                            <li class="nav-item"><a class="nav-link" href="<?= $esc($loginUrl) ?>">Sign in</a></li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="btn campus-button" href="/portal/apply/">
                                Start your application
                                <i class="bi bi-arrow-right" aria-hidden="true"></i>
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>
        </header>
        <main id="campus-main" tabindex="-1"><?= $body ?></main>
        <footer class="campus-footer">
            <nav class="campus-container" aria-label="<?= trans_attr('Additional website links') ?>">
                <ul class="nav flex-wrap">
                    <?= $siteNavigation['secondary'] ?>
                    <?= $siteNavigation['footer'] ?>
                </ul>
            </nav>
            <div class="campus-container campus-footer-grid">
                <div>
                    <a class="campus-brand" href="/">
                        <span class="campus-crest" aria-hidden="true"><i class="bi bi-book"></i></span>
                        <span><?= $esc($portalName) ?></span>
                    </a>
                    <p>
                        Big ideas. New beginnings.
                        <br />
                        A campus connected around you.
                    </p>
                </div>
                <div>
                    <h2>Find your next step</h2>
                    <a href="/portal/courses/">Explore courses</a>
                    <a href="/portal/apply/">Apply to a program</a>
                    <a href="/portal/news/">News &amp; updates</a>
                </div>
                <div>
                    <h2>Already part of campus?</h2>
                    <a href="/portal/student/registration/">Course registration</a>
                    <a href="/portal/student/accounts/">Student accounts</a>
                    <a href="/portal/student/transcripts/">Transcripts</a>
                </div>
            </div>
            <div class="campus-container campus-footer-bottom">
                <span>© <?= date("Y") ?> <?= $esc($portalName) ?></span>
                <span>Built for curious minds. Designed for everyone.</span>
            </div>
        </footer>
        <div class="modal fade" id="campus-dialog" tabindex="-1" aria-labelledby="campus-dialog-title">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title fs-4" id="campus-dialog-title">Details</h2>
                        <button
                            class="btn-close"
                            type="button"
                            data-bs-dismiss="modal"
                            aria-label="Close details"
                        ></button>
                    </div>
                    <div class="modal-body" id="campus-dialog-body"></div>
                </div>
            </div>
        </div>
        <?php if ($signedIn) : ?>
            <?php
            $notificationLabels = require dirname(__DIR__, 5) . '/resources/views/shared/notification-labels.php';
            $notificationLabels['recent'] = trans_html('Recent notifications');
            $notificationLabels['notification'] = trans_html('Campus notification');
            $notificationLabels['empty'] = trans_html('There are no notifications from this page.');
            $notificationLabels['session_help'] = trans_html(
                'Recent action feedback stays only in this page. Reloading or signing out clears it. '
                . 'Check the relevant service for your current records and request status.',
            );
            ?>
            <form id="campus-csrf" hidden><?= $csrfMarkup ?? csrf_field() ?></form>
            <script id="campus-notification-config" type="application/json">
                <?= json_encode(
                    $notificationLabels,
                    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
                ) ?>
            </script>
            <div
                id="campus-notice"
                class="workspace-notification-stack"
                role="status"
                aria-live="polite"
                aria-atomic="false"></div>
        <?php endif; ?>
    </body>
</html>
