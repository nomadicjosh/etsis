<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Enum\HiatusCode;
use Domain\Student\Service\HiatusWorkflowService;
use Domain\Student\StudentHiatus;
use Domain\Student\ValueObject\HiatusImpact;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisHiatusWorkflowController
{
    public function __construct(private HiatusWorkflowService $service)
    {
    }

    public function review(ServerRequest $request, string $student): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->review(
                new EntityId($student),
                HiatusCode::parse($f->text("code", 8)),
                new DateRange($f->date("starts_on"), $f->optionalDate("ends_on")),
            ),
        );
    }

    public function commit(ServerRequest $request, string $student): ResponseInterface
    {
        $f = FormInput::from($request);
        $body = $request->getParsedBody();
        $sections = is_array($body) ? $body["section_ids"] ?? null : null;
        if (!is_array($sections) || !array_is_list($sections) || count($sections) > 100) {
            throw new ValidationException([["field" => "sections", "message" => "Choose up to 100 course changes."]]);
        }
        $ids = [];
        foreach ($sections as $id) {
            if (!is_string($id)) {
                throw new ValidationException([["field" => "sections", "message" => "Choose valid course sections."]]);
            }
            $ids[] = new EntityId($id);
        }
        $hiatus = new StudentHiatus(
            $f->id("operation_id"),
            new EntityId($student),
            HiatusCode::parse($f->text("code", 8)),
            new DateRange($f->date("starts_on"), $f->optionalDate("ends_on")),
        );
        return new JsonResponse(
            $this->service->commit(
                $hiatus,
                $f->bodyText("note"),
                new HiatusImpact($f->requiredBoolean("deactivate"), $f->text("revision", 64), [], ...$ids),
            ),
            201,
        );
    }
}
