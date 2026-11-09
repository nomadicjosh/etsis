<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\AcademicCalendar\Service\AcademicCalendarService;
use Domain\AcademicCalendar\ValueObject\AcademicTerm;
use Domain\AcademicCalendar\ValueObject\TermType;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisAcademicCalendarController
{
    public function __construct(private AcademicCalendarService $service)
    {
    }

    public function types(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse($this->service->types(FormInput::from($request)->optionalId("after")));
    }

    public function saveType(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->saveType($this->typeInput($f, $id), $f->integer("version", -1)));
    }

    public function previewType(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->previewType($this->typeInput($f, $id), $f->integer("start_year", 1000, 2998)),
        );
    }

    private function typeInput(FormInput $f, string $id): TermType
    {
        $dates = [];
        foreach (["starts_on", "ends_on", "add_drop_ends_on"] as $field) {
            $dates[$field] = [
                "year" => $f->text($field . "_year", 16),
                "month_day" => $f->text($field . "_month_day", 5),
            ];
        }
        return new TermType(
            new EntityId($id),
            $f->text("code", 22),
            $f->text("name_pattern", 120),
            $f->text("code_pattern", 120),
            $f->text("kind", 24),
            $dates,
            $f->requiredBoolean("active"),
            $f->integer("version", -1) + 1,
        );
    }

    public function years(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->years($f->optionalId("after"), $f->optionalText("search", 80) ?? ""));
    }

    public function review(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse($this->service->review(FormInput::from($request)->integer("start_year", 1000, 2998)));
    }

    public function create(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $body = $request->getParsedBody();
        $rows = is_array($body) ? $body["terms"] ?? null : null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows) < 1 || count($rows) > 100) {
            throw new ValidationException([["field" => "terms", "message" => "Confirm the reviewed generated terms."]]);
        }
        $references = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new ValidationException([["field" => "terms", "message" => "Use valid term references."]]);
            }
            $item = new FormInput($row);
            $type = $item->id("term_type_id")->value;
            if (isset($references[$type])) {
                throw new ValidationException([["field" => "terms", "message" => "Use each active term type once."]]);
            }
            $references[$type] = $item->id("id")->value;
        }
        return new JsonResponse(
            $this->service->create(
                $f->id("id"),
                $f->id("operation_id"),
                $f->integer("start_year", 1000, 2998),
                $references,
                $f->text("revision", 64),
            ),
            201,
        );
    }

    public function receipt(string $id, string $operation): ResponseInterface
    {
        return new JsonResponse($this->service->receipt(new EntityId($id), new EntityId($operation)));
    }

    public function term(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->term(new EntityId($id)));
    }

    public function saveTerm(ServerRequest $request, ?string $id = null): ResponseInterface
    {
        $body = $request->getParsedBody();
        $errors = [];
        foreach (["code", "name", "kind", "starts_on", "ends_on", "add_drop_ends_on"] as $field) {
            if (!is_array($body) || !is_string($body[$field] ?? null) || trim($body[$field]) === "") {
                $errors[] = ["field" => $field, "message" => "This field is required."];
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->saveTerm(
                $id === null ? $f->id("operation_id") : new EntityId($id),
                $f->optionalId("academic_year_id"),
                new AcademicTerm(
                    $f->text("code", 22),
                    $f->text("name", 120),
                    $f->text("kind", 24),
                    $f->text("starts_on", 10),
                    $f->text("ends_on", 10),
                    $f->text("add_drop_ends_on", 10),
                ),
                $id === null ? null : $f->text("revision", 64),
            ),
            $id === null ? 201 : 200,
        );
    }
}
