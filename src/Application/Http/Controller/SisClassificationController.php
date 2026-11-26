<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Catalog\Enum\ClassificationKind;
use Domain\Catalog\Service\ClassificationService;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisClassificationController
{
    public function __construct(
        private ClassificationService $service,
        private \Domain\Registration\Service\SectionClassificationService $sections,
        private \Domain\Catalog\Service\TermReportingDefaultService $termDefaults,
    ) {
    }

    public function page(
        ServerRequest $request,
        string $kind,
    ): ResponseInterface {
        $input = FormInput::from($request);
        return new JsonResponse(
            $this->service->page(
                $this->kind($kind),
                $input->search(),
                $input->optionalId("after"),
            ),
        );
    }

    public function save(
        ServerRequest $request,
        string $kind,
    ): ResponseInterface {
        $input = FormInput::from($request);
        return new JsonResponse(
            $this->service->save(
                $this->kind($kind),
                $input->optionalId("id"),
                $input->text("code", 40),
                $input->text("title", 191),
                $input->requiredBoolean("active"),
                $input->optionalText("revision", 64),
                $input->optionalId("school_id"),
            ),
        );
    }

    public function course(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->course(new EntityId($id)));
    }

    public function assign(
        ServerRequest $request,
        string $id,
    ): ResponseInterface {
        $body = $request->getParsedBody();
        foreach (ClassificationKind::courseKinds() as $kind) {
            if (
                !is_array($body) ||
                !array_key_exists($kind->value . "_id", $body)
            ) {
                throw new InvalidArgumentException(
                    "Provide all three course classification choices.",
                );
            }
        }
        $input = FormInput::from($request);
        $this->service->assign(
            new EntityId($id),
            $input->optionalId("department_id"),
            $input->optionalId("academic_level_id"),
            $input->optionalId("course_level_id"),
            $input->text("revision", 64),
        );
        return new JsonResponse(["status" => "saved"]);
    }

    public function section(string $id): ResponseInterface
    {
        return new JsonResponse($this->sections->show(new EntityId($id)));
    }

    public function termDefault(string $id): ResponseInterface
    {
        return new JsonResponse($this->termDefaults->show(new EntityId($id)));
    }

    public function saveTermDefault(ServerRequest $request, string $id): ResponseInterface
    {
        $input = FormInput::from($request);
        return new JsonResponse($this->termDefaults->save(
            new EntityId($id),
            $input->requiredNullableId('reporting_group_id'),
            $input->text('revision', 64),
        ));
    }

    public function assignSection(
        ServerRequest $request,
        string $id,
    ): ResponseInterface {
        $body = $request->getParsedBody();
        foreach (["instructional_method_id", "campus_id", "reporting_group_id"] as $key) {
            if (!is_array($body) || !array_key_exists($key, $body)) {
                throw new InvalidArgumentException(
                    "Provide all three section classification choices.",
                );
            }
        }
        $input = FormInput::from($request);
        $this->sections->assign(
            new EntityId($id),
            $input->optionalId("instructional_method_id"),
            $input->optionalId("campus_id"),
            $input->requiredNullableId("reporting_group_id"),
            $input->text("revision", 64),
        );
        return new JsonResponse(["status" => "saved"]);
    }

    private function kind(string $kind): ClassificationKind
    {
        return ClassificationKind::tryFrom($kind) ??
        throw new InvalidArgumentException(
            "Choose a supported classification category.",
        );
    }
}
