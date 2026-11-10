<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Catalog\Enum\ClassificationKind;
use Domain\Catalog\Service\ProgramPublicationService;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisProgramPublicationController
{
    public function __construct(private ProgramPublicationService $programs)
    {
    }

    public function draft(string $id): ResponseInterface
    {
        return new JsonResponse($this->programs->draft(new EntityId($id)));
    }

    public function save(ServerRequest $request, string $id): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (
            !is_array($body) ||
            !array_key_exists("description", $body) ||
            ($body["description"] !== null && !is_string($body["description"]))
        ) {
            throw new InvalidArgumentException("Provide a plain-text program description or leave it blank.");
        }
        foreach (ClassificationKind::programKinds() as $kind) {
            if (!array_key_exists($kind->value . "_id", $body)) {
                throw new InvalidArgumentException("Provide all program classification choices.");
            }
        }
        $input = FormInput::from($request);
        return new JsonResponse(
            $this->programs->save(
                new EntityId($id),
                $input->text("code", 80),
                $input->text("title", 191),
                $body["description"],
                $input->optionalId("department_id"),
                $input->optionalId("academic_level_id"),
                $input->optionalId("award_id"),
                $input->text("revision", 64),
            ),
        );
    }

    public function review(ServerRequest $request, string $id): ResponseInterface
    {
        $input = FormInput::from($request);
        return new JsonResponse(
            $this->programs->review(
                new EntityId($id),
                $input->id("academic_year_id"),
                $input->bodyText("reason"),
            ),
        );
    }

    public function publish(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->programs->publish(
                new EntityId($id),
                FormInput::from($request)->text("review_token", 131072),
            ),
        );
    }

    public function page(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->programs->versions(
                new EntityId($id),
                FormInput::from($request)->optionalText("cursor", 16384),
            ),
        );
    }
}
