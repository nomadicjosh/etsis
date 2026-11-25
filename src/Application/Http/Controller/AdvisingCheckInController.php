<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Advising\Service\AdvisingCheckInService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class AdvisingCheckInController
{
    public function __construct(private AdvisingCheckInService $service)
    {
    }
    public function staffList(
        #[\SensitiveParameter] ServerRequest $request,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($request, $student, $pursuit, "staff", "list");
    }
    public function staffRecord(
        #[\SensitiveParameter] ServerRequest $request,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($request, $student, $pursuit, "staff", "record");
    }
    public function staffAct(
        #[\SensitiveParameter] ServerRequest $request,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($request, $student, $pursuit, "staff", "act");
    }
    public function advisorList(
        #[\SensitiveParameter] ServerRequest $request,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($request, $student, $pursuit, "advisor", "list");
    }
    public function advisorRecord(
        #[\SensitiveParameter] ServerRequest $request,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($request, $student, $pursuit, "advisor", "record");
    }
    public function advisorAct(
        #[\SensitiveParameter] ServerRequest $request,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($request, $student, $pursuit, "advisor", "act");
    }
    public function ownerList(
        #[\SensitiveParameter] ServerRequest $request,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($request, $student, $pursuit, "owner", "list");
    }
    public function ownerAct(
        #[\SensitiveParameter] ServerRequest $request,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        return $this->dispatch($request, $student, $pursuit, "owner", "act");
    }
    private function dueOn(#[\SensitiveParameter] ServerRequest $request): ?string
    {
        $body = $request->getParsedBody();
        if (
            !array_key_exists("due_on", $body) ||
            ($body["due_on"] !== null && !is_string($body["due_on"]))
        ) {
            throw new \InvalidArgumentException(
                "Provide a follow-up date or explicitly choose none.",
            );
        }
        return $body["due_on"];
    }

    private function privateNote(#[\SensitiveParameter] ServerRequest $request): string
    {
        $note = $request->getParsedBody()["private_note"] ?? "";
        if (!is_string($note) || strlen($note) > 4000) {
            throw new \InvalidArgumentException("Provide a bounded private note.");
        }
        return $note;
    }

    private function dispatch(
        #[\SensitiveParameter] ServerRequest $request,
        string $student,
        string $pursuit,
        string $channel,
        string $action,
    ): ResponseInterface {
        $f = FormInput::from($request);
        $s = new EntityId($student);
        $p = new EntityId($pursuit);
        return new JsonResponse(
            match ($action) {
                "list" => $this->service->listing(
                    $s,
                    $p,
                    $channel,
                    $f->boolean("finished"),
                    $f->optionalId("after"),
                    $f->optionalId('checkin_id'),
                ),
                "record" => $this->service->record(
                    $s,
                    $p,
                    $channel,
                    $f->id("checkin_id"),
                    $f->text("held_on", 10),
                    $this->dueOn($request),
                    $f->bodyText("next_step"),
                    $this->privateNote($request),
                ),
                "act" => $this->service->act(
                    $s,
                    $p,
                    $channel,
                    $f->id("checkin_id"),
                    $f->text("action", 12),
                    $f->integer("version"),
                    $f->bodyText("message"),
                ),
            },
        );
    }
}
