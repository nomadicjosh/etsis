<?php

declare(strict_types=1);

use Application\Http\Controller\CampusPortalController as Portal;
use Application\Http\Controller\SisPortalEditorController as Editor;
use Application\Http\Controller\PortalPaymentController as Payments;
use Qubus\Routing\Interfaces\Mappable;
use Qubus\Routing\Psr7Router;

return static function (Psr7Router $router): void {
    /** @var Psr7Router&Mappable $router */
    $moodle = \Application\Http\Controller\PortalMoodleController::class;
    $router->map(["GET"], "/portal/moodle/", [$moodle, "page"])->name("portal.moodle");
    $router->map(["POST"], "/portal/moodle/launch/", [$moodle, "launch"])->name("portal.moodle.launch");
    $router
        ->map(["POST"], "/sis/moodle/session-channel/", [$moodle, "channel"])
        ->name("moodle.session.channel");
    $router->map(["GET"], "/portal/", [Portal::class, "home"])->name("portal.home");
    $router->map(["GET"], "/portal/sign-in/", [Portal::class, "landing"])->name("portal.landing");
    $router->map(["GET"], "/portal/courses/", [Portal::class, "catalog"])->name("portal.courses");
    $router->map(["GET"], "/portal/news/", [Portal::class, "news"])->name("portal.news");
    $router->map(["GET"], "/portal/news/{code}/", [Portal::class, "article"])->name("portal.article");
    $router->map(["GET"], "/portal/apply/", [Portal::class, "apply"])->name("portal.apply");
    $router->map(["GET"], "/portal/student/", [Portal::class, "student"])->name("portal.student");
    $router
        ->map(["GET"], "/portal/student/{section}/", [Portal::class, "student"])
        ->name("portal.student.section");
    $router->map(["GET"], "/portal/api/public/", [Portal::class, "publicData"])->name("portal.public");
    $router->map(["GET"], "/portal/api/programs/", [Portal::class, "programs"])->name("portal.programs");
    $router
        ->map(["GET"], "/portal/api/campus-news/", [Portal::class, "campusNews"])
        ->name("portal.campus.news");
    $router->map(["GET"], "/portal/api/courses/", [Portal::class, "courses"])->name("portal.catalog");
    $router->map(["GET"], "/portal/api/me/", [Portal::class, "me"])->name("portal.me");
    $router->map(["POST"], "/portal/api/contact/", [Portal::class, "contact"])->name("portal.contact");
    $router
        ->map(["GET"], "/portal/api/payments/status/", [Payments::class, "status"])
        ->name("portal.payments.status");
    $router
        ->map(["POST"], "/portal/api/payments/checkout/", [Payments::class, "checkout"])
        ->name("portal.payments.checkout");
    $router->map(["POST"], "/portal/api/register/", [Portal::class, "register"])->name("portal.register");
    $router
        ->map(["POST"], "/portal/api/drop-review/", [Portal::class, "reviewDrop"])
        ->name("portal.drop.review");
    $router
        ->map(["GET"], "/portal/api/drop-receipts/{operation}/", [Portal::class, "dropReceipt"])
        ->name("portal.drop.receipt");
    $router->map(["POST"], "/portal/api/drop/", [Portal::class, "drop"])->name("portal.drop");
    $router
        ->map(["GET"], "/portal/api/students/{id}/transcript/", [Portal::class, "transcript"])
        ->name("portal.transcript");
    $router
        ->map(["GET"], "/portal/api/students/{id}/sections/{section}/scores/", [Portal::class, "scores"])
        ->name("portal.scores");
    $router
        ->map(["GET"], "/portal/api/accounts/{id}/", [Portal::class, "statement"])
        ->name("portal.statement");
    $router
        ->map(["POST"], "/portal/api/transcripts/", [Portal::class, "requestTranscript"])
        ->name("portal.transcript.request");
    $router
        ->map(["GET"], "/portal/api/transcripts/{id}/", [Portal::class, "transcriptRequest"])
        ->name("portal.transcript.show");
    $router
        ->map(["POST"], "/portal/api/transcripts/{id}/status/", [Portal::class, "transitionTranscript"])
        ->name("portal.transcript.status");
    $router
        ->map(["GET"], "/sis/transcript-requests/", [Portal::class, "transcriptQueue"])
        ->name("sis.transcript.queue");
    $router->map(["GET"], "/sis/portal/editor/{id}/", [Editor::class, "get"])->name("sis.portal.editor.get");
    $router->map(["POST"], "/sis/portal/editor/", [Editor::class, "save"])->name("sis.portal.editor.save");
};
