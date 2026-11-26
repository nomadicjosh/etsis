<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Application\View\CampusRenderer;
use Codefy\Framework\Auth\Gate;
use Domain\Finance\ValueObject\AccountId;
use Domain\Portal\Service\CampusService;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\RecordNotFoundException;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;
use InvalidArgumentException;

use function Codefy\Framework\Helpers\trans;

final readonly class CampusPortalController
{
    public function __construct(
        private CampusService $service,
        private CampusRenderer $renderer,
        private Gate $gate,
        private \Domain\Portal\Service\ProgramDiscoveryService $discovery,
    ) {
    }

    public function home(): ResponseInterface
    {
        $data = $this->service->publicData();
        $data['programs'] = $this->discovery->page()['items'];
        return $this->renderer->render("home", "A place for your next chapter", $data);
    }

    public function landing(ServerRequest $request): ResponseInterface
    {
        if (!$this->gate->isLoggedIn()) {
            return new RedirectResponse($this->renderer->loginUrl());
        }
        if (($request->getQueryParams()["continue"] ?? null) === "apply") {
            return new RedirectResponse(
                \Application\Http\Security\ApplicationContinuation::target($request->getQueryParams()),
            );
        }
        return new RedirectResponse($this->renderer->canUseWorkspace() ? "/admin/" : "/portal/student/");
    }

    public function catalog(): ResponseInterface
    {
        return $this->renderer->render("catalog", "Explore courses", $this->service->publicData());
    }

    public function news(ServerRequest $request): ResponseInterface
    {
        $items = $this->service->news(false, $this->after($request));
        return $this->renderer->render("news", "Around campus", [
            "news" => $items,
            "olderAfter" => count($items) === 24 ? $items[23]["id"] : null,
        ]);
    }

    public function article(string $code): ResponseInterface
    {
        try {
            $article = $this->service->article($code);
        } catch (RecordNotFoundException) {
            return $this->renderer->render("not-found", "Article unavailable")->withStatus(404);
        }
        return $this->renderer->render("article", $article["title"], ["article" => $article]);
    }

    public function apply(ServerRequest $request): ResponseInterface
    {
        $data = $this->service->publicData();
        $selected = new FormInput($request->getQueryParams())->optionalId('program');
        if ($selected !== null && !in_array($selected->value, array_column($data['programs'], 'id'), true)) {
            foreach ($this->service->programs($selected->value)['items'] as $item) {
                if ($item['id'] === $selected->value) {
                    $data['programs'][] = $item;
                    break;
                }
            }
        }
        return $this->renderer->render("workspace", "Start your application", [
            "section" => "applications",
            "public" => $data,
            "applicationPage" => true,
        ]);
    }

    public function student(string $section = "overview"): ResponseInterface
    {
        if (!$this->gate->isLoggedIn()) {
            return new RedirectResponse($this->renderer->loginUrl());
        }
        if ($section === 'timetable') {
            return $this->timetable();
        }
        if ($section === 'bookings') {
            return $this->bookings();
        }
        if (
            !in_array(
                $section,
                [
                    "overview", "profile", "applications", "grades", "progress", "advising",
                    "registration", "transcripts", "accounts", "updates",
                ],
                true,
            )
        ) {
            throw new InvalidArgumentException("Unknown student service.");
        }
        return $this->renderer->render("workspace", "My campus", [
            "section" => $section,
            "public" => $this->service->publicData(),
            "applicationPage" => false,
        ]);
    }

    public function timetable(): ResponseInterface
    {
        return $this->standalone('timetable', 'My timetable');
    }

    public function bookings(): ResponseInterface
    {
        return $this->standalone('bookings', 'Booking updates');
    }
    public function admissionsUpdates(): ResponseInterface
    {
        return $this->standalone('admissionsupdates', 'Admissions updates');
    }
    private function standalone(string $section, string $title): ResponseInterface
    {
        if (!$this->gate->isLoggedIn()) {
            return new RedirectResponse($this->renderer->loginUrl());
        }
        return $this->renderer->render('workspace', $title, ['section' => $section,
            'public' => ['programs' => [], 'terms' => []], 'applicationPage' => false]);
    }

    public function publicData(): ResponseInterface
    {
        return new JsonResponse($this->service->publicData());
    }

    public function programs(ServerRequest $request): ResponseInterface
    {
        $search = $request->getQueryParams()["q"] ?? "";
        if (!is_string($search)) {
            throw new InvalidArgumentException("Invalid program search.");
        }
        return new JsonResponse($this->service->programs($search, $this->after($request)));
    }

    public function campusNews(ServerRequest $request): ResponseInterface
    {
        $items = $this->service->news(true, $this->after($request));
        return new JsonResponse(["items" => $items, "next_after" => count($items) === 24 ? $items[23]["id"] : null]);
    }

    private function after(ServerRequest $request): ?EntityId
    {
        $value = $request->getQueryParams()["after"] ?? "";
        if (!is_string($value)) {
            throw new InvalidArgumentException("Invalid page cursor.");
        }
        return $value === "" ? null : new EntityId($value);
    }

    public function courses(ServerRequest $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        if (!is_string($query["q"] ?? "")) {
            throw new InvalidArgumentException("Invalid search.");
        }
        $id = static function (mixed $value): ?EntityId {
            if ($value === null || $value === "") {
                return null;
            }
            if (!is_string($value)) {
                throw new InvalidArgumentException("Invalid catalog filter.");
            }
            return new EntityId($value);
        };
        return new JsonResponse(
            $this->service->catalog($id($query["term"] ?? null), $query["q"] ?? "", $id($query["after"] ?? null)),
        );
    }

    public function me(): ResponseInterface
    {
        return new JsonResponse($this->service->me());
    }

    public function applications(): ResponseInterface
    {
        return new JsonResponse($this->service->applications());
    }

    public function contact(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $body = $request->getParsedBody();
        foreach (["preferred_name", "phone", "address"] as $key) {
            if (!is_array($body) || !is_string($body[$key] ?? null)) {
                throw new InvalidArgumentException("Contact details are required.");
            }
        }
        $this->service->saveContact($body["preferred_name"], $body["phone"], $body["address"], $f->integer("version"));
        return new JsonResponse(["status" => "saved"]);
    }

    public function register(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->register(
                $f->id("student_id"),
                $f->id("section_id"),
                $f->id("operation_id"),
                $f->text("revision", 64),
                $f->text("review_token", 8192),
                $f->optionalInteger("credits_milli"),
            ) + ["status" => "registered"],
        );
    }

    public function reviewRegistrationPlan(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse(
            $this->service->reviewRegistrationPlan(
                \Application\Http\RegistrationPlanInput::selection($request),
            ),
        );
    }

    public function registerPlan(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->registerPlan(
                \Application\Http\RegistrationPlanInput::selection($request),
                $f->id("operation_id"),
                $f->text("revision", 64),
                $f->text("review_token", 8192),
            ),
        );
    }

    public function reviewRegistration(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->reviewRegistration(
                $f->id("student_id"),
                $f->id("section_id"),
                $f->optionalInteger("credits_milli"),
            ),
        );
    }

    public function reviewDrop(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->reviewDrop($f->id("student_id"), $f->id("section_id")));
    }
    public function dropReceipt(string $operation): ResponseInterface
    {
        return new JsonResponse($this->service->dropReceipt(new EntityId($operation)));
    }
    public function drop(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->dropReviewed(
                $f->id("student_id"),
                $f->id("section_id"),
                $f->id("enrollment_id"),
                $f->integer("version"),
                $f->id("operation_id"),
                $f->text("revision", 64),
                $f->text("effective_on", 10),
            ),
        );
    }

    public function transcript(\Qubus\Http\ServerRequest $request, string $id): ResponseInterface
    {
        $purpose = $request->getQueryParams()['purpose'] ?? 'academic';
        if (!in_array($purpose, ['academic', 'continuing_education'], true)) {
            throw new \InvalidArgumentException(
                trans('Choose an academic or CEU transcript.'),
            );
        }
        return new JsonResponse(['items' => $this->service->transcript(
            new EntityId($id),
            $purpose === 'continuing_education'
        )]);
    }

    public function scores(string $id, string $section): ResponseInterface
    {
        return new JsonResponse($this->service->gradebookOverview(new EntityId($id), new EntityId($section)));
    }

    public function statement(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->statement(new AccountId($id)));
    }

    public function requestTranscript(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $body = $request->getParsedBody();
        $recipient = is_array($body) && is_string($body["recipient"] ?? null) ? $body["recipient"] : "";
        $id = $f->id("request_id");
        $this->service->requestTranscript($id, $f->id("student_id"), $f->text("delivery", 12), $recipient);
        return new JsonResponse(["id" => $id->value, "status" => "pending"], 201);
    }

    public function transcriptRequest(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->transcriptRequest(new EntityId($id)));
    }

    public function transcriptQueue(): ResponseInterface
    {
        return new JsonResponse(["items" => $this->service->transcriptQueue()]);
    }

    public function transitionTranscript(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->transitionTranscript(new EntityId($id), $f->text("status", 16), $f->integer("version"));
        return new JsonResponse(["status" => "changed"]);
    }
}
