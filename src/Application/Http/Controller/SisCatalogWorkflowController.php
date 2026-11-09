<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Catalog\Course;
use Domain\Catalog\Service\CourseCloneService;
use Domain\Catalog\Service\OfferingService;
use Domain\Catalog\ValueObject\OfferingPlan;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\Section;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisCatalogWorkflowController
{
    public function __construct(private CourseCloneService $clones, private OfferingService $offerings)
    {
    }

    public function cloneReview(string $id): ResponseInterface
    {
        return new JsonResponse($this->clones->review(new EntityId($id)));
    }

    public function cloneCommit(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $ids = $this->list($request, "prerequisites", 100);
        foreach ($ids as $value) {
            if (!is_string($value)) {
                throw new ValidationException([
                    ["field" => "prerequisites", "message" => "Choose valid prerequisites."],
                ]);
            }
        }
        return new JsonResponse(
            $this->clones->commit(
                new EntityId($id),
                new Course(
                    $f->id("operation_id"),
                    $f->text("code", 80),
                    $f->text("title"),
                    $f->integer("credits_milli", 0, 999999),
                    false,
                ),
                array_map(static fn(string $value): EntityId => new EntityId($value), $ids),
                $f->text("revision", 64),
            ),
            201,
        );
    }

    public function cloneSaved(string $operation): ResponseInterface
    {
        return new JsonResponse($this->clones->saved(new EntityId($operation)));
    }

    public function offeringReview(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse($this->offerings->review($this->plan($request)));
    }

    public function offeringSave(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->offerings->save($f->id("operation_id"), $this->plan($request), $f->text("revision", 64)),
            201,
        );
    }

    public function offeringPage(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse($this->offerings->page(FormInput::from($request)->optionalId("after")));
    }

    public function offeringSaved(string $operation): ResponseInterface
    {
        return new JsonResponse($this->offerings->saved(new EntityId($operation)));
    }

    public function publicationSetup(string $operation): ResponseInterface
    {
        return new JsonResponse($this->offerings->preparePublication(new EntityId($operation)));
    }

    public function publicationReview(ServerRequest $request, string $operation): ResponseInterface
    {
        $versions = [];
        foreach ($this->list($request, "course_versions", 25) as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new ValidationException([
                    ["field" => "course_versions", "message" => "Choose valid section definitions."],
                ]);
            }
            $f = new FormInput($row);
            $section = $f->id("section_id");
            if (isset($versions[$section->value])) {
                throw new ValidationException([
                    ["field" => "course_versions", "message" => "Choose one definition per section."],
                ]);
            }
            $versions[$section->value] = $f->id("version_id");
        }
        return new JsonResponse(
            $this->offerings->reviewPublication(
                new EntityId($operation),
                $versions,
                FormInput::from($request)->bodyText("reason"),
            ),
        );
    }

    public function publish(ServerRequest $request, string $operation): ResponseInterface
    {
        return new JsonResponse(
            $this->offerings->publish(
                new EntityId($operation),
                FormInput::from($request)->text("review_token", 65536),
            ),
        );
    }

    private function plan(ServerRequest $request): OfferingPlan
    {
        $term = FormInput::from($request)->id("term_id");
        $rows = $this->list($request, "sections", 25);
        $sections = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new ValidationException([
                    ["field" => "sections", "message" => "Choose valid draft sections."],
                ]);
            }
            $f = new FormInput($row);
            $sections[] = new Section(
                $f->id("id"),
                $f->id("course_id"),
                $term,
                $f->optionalId("faculty_user_id"),
                $f->text("code", 80),
                $f->integer("capacity", 1, 100000),
                false,
                $f->requiredBoolean("web_registration"),
                new Money($f->integer("fee_minor", 0), $f->text("currency", 3)),
                \Domain\Registration\Enum\DeliveryMode::parse(
                    $f->optionalText("delivery_mode", 24) ?? "unspecified",
                ),
            );
        }
        return new OfferingPlan($term, ...$sections);
    }

    /** @return list<mixed> */
    private function list(ServerRequest $request, string $field, int $maximum): array
    {
        $body = $request->getParsedBody();
        $rows = is_array($body) ? $body[$field] ?? null : null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > $maximum) {
            throw new ValidationException([
                ["field" => $field, "message" => "Choose a bounded list of records."],
            ]);
        }
        return $rows;
    }
}
