<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Catalog\Service\OfferingTemplateService;
use Domain\Catalog\ValueObject\OfferingRecipe;
use Domain\Catalog\ValueObject\SectionBlueprint;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\Enum\DeliveryMode;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisOfferingTemplateController
{
    public function __construct(private OfferingTemplateService $service)
    {
    }
    public function page(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->page(
                $f->optionalBoolean("active"),
                $f->search("search", 80),
                $f->optionalId("after"),
            ),
        );
    }
    public function show(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->view(new EntityId($id)));
    }
    public function review(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->review(
                $f->optionalId("template_id"),
                $f->integer("version", -1),
                $f->text("name", 120),
                $this->recipe($request),
            ),
        );
    }
    public function save(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->save(
                new EntityId($id),
                $f->id("operation_id"),
                $f->integer("version", -1),
                $f->text("name", 120),
                $this->recipe($request),
                $f->text("revision", 64),
            ),
        );
    }
    public function prepare(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->prepare(new EntityId($id), $f->integer("version"), $f->id("term_id")));
    }
    public function status(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->status(
                new EntityId($id),
                $f->id("operation_id"),
                $f->integer("version"),
                $f->requiredBoolean("active"),
                $f->bodyText("note"),
            ),
        );
    }
    public function receipt(string $id, string $operation): ResponseInterface
    {
        return new JsonResponse($this->service->receipt(new EntityId($id), new EntityId($operation)));
    }
    public function history(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->history(
                new EntityId($id),
                FormInput::from($request)->optionalInteger("after_version") ?? -1,
            ),
        );
    }
    private function recipe(ServerRequest $request): OfferingRecipe
    {
        $body = $request->getParsedBody();
        $rows = is_array($body) ? $body["sections"] ?? null : null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows) < 1 || count($rows) > 25) {
            throw new ValidationException([
                ["field" => "sections", "message" => "Provide 1–25 reusable section defaults."],
            ]);
        }
        $sections = [];
        foreach ($rows as $row) {
            if (
                !is_array($row) ||
                array_is_list($row) ||
                array_diff(array_keys($row), [
                    "course_id",
                    "code",
                    "capacity",
                    "faculty_user_id",
                    "fee_minor",
                    "currency",
                    "delivery_mode",
                    "web_registration",
                ]) !== []
            ) {
                throw new ValidationException([
                    [
                        "field" => "sections",
                        "message" =>
                            "Use section defaults without source section, term, booking or student identities.",
                    ],
                ]);
            }
            $f = new FormInput($row);
            $sections[] = new SectionBlueprint(
                $f->id("course_id"),
                $f->text("code", 80),
                $f->integer("capacity", 1, 100000),
                $f->optionalId("faculty_user_id"),
                new Money($f->integer("fee_minor"), $f->text("currency", 3)),
                DeliveryMode::parse($f->text("delivery_mode", 24)),
                $f->requiredBoolean("web_registration"),
            );
        }
        return new OfferingRecipe(...$sections);
    }
}
