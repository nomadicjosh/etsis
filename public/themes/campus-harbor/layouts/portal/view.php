<?php

declare(strict_types=1);

use Vihzhuo\Core\ThemeContext;

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
?>
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="referrer" content="same-origin" />
        <title><?= $esc($title) ?> · <?= $esc($portalName) ?></title>
        <link rel="icon" href="/assets/images/workspace-mark.svg" type="image/svg+xml" />
        <link rel="stylesheet" href="/assets/vendor/bootstrap.min.css" />
        <link rel="stylesheet" href="/assets/vendor/icons/bootstrap-icons.min.css" />
        <link rel="stylesheet" href="/assets/css/portal.css" />
        <link rel="stylesheet" href="/assets/css/degree-progress.css" />
        <link rel="stylesheet" href="<?= $esc($themeAsset) ?>" />
        <script defer src="/assets/vendor/bootstrap.bundle.min.js"></script>
        <script defer src="/assets/js/degree-progress-guide.js"></script>
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
                        <li class="nav-item">
                            <a class="nav-link" href="/portal/courses/">Explore courses</a>
                        </li>
                        <li class="nav-item"><a class="nav-link" href="/portal/news/">Around campus</a></li>
                        <?php if ($staffAccess): ?>
                            <li class="nav-item"><a class="nav-link" href="/admin/">Staff workspace</a></li>
                        <?php endif; ?>
                        <?php if ($signedIn && ($moodleLaunchEnabled ?? false)): ?>
                            <li class="nav-item"><a class="nav-link" href="/portal/moodle/">Moodle</a></li>
                        <?php endif; ?>
                        <?php if ($signedIn): ?>
                            <li class="nav-item"><a class="nav-link" href="/portal/student/">My campus</a></li>
                            <li class="nav-item">
                                <form method="post" action="/logout/" class="m-0">
                                    <?= $csrfMarkup ?? csrf_field() ?>
                                    <button class="nav-link" type="submit">Sign out</button>
                                </form>
                            </li>
                        <?php else: ?>
                            <li class="nav-item"><a class="nav-link" href="<?= $esc(
                                $loginUrl,
                            ) ?>">Sign in</a></li>
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
        <div id="campus-notice" class="campus-toast" role="status" aria-live="polite" hidden></div>
    </body>
</html>
