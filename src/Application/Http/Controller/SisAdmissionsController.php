<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Codefy\Framework\Http\BaseController;
use Domain\Admissions\Service\AdmissionsService;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final class SisAdmissionsController extends BaseController
{
    public function __construct(private readonly AdmissionsService $service)
    {
    }

    public function createPerson(ServerRequest $request): ResponseInterface
    {
        $data = $this->data($request);
        $owner = isset($data["owner_user_id"]) ? new EntityId($this->text($data, "owner_user_id")) : null;
        $person = $this->service->createPerson(
            $owner,
            $this->text($data, "given_name"),
            $this->text($data, "family_name"),
            $this->optionalInstitutionalId($data),
        );

        return new JsonResponse(
            [
                "id" => $person->id->value,
                "institutional_id" => $person->institutionalId->value,
                "identity_version" => $person->identityVersion,
            ],
            201,
        );
    }

    public function apply(ServerRequest $request): ResponseInterface
    {
        $data = $this->data($request);
        $application = $this->service->apply(
            new EntityId($this->text($data, "person_id")),
            new EntityId($this->text($data, "program_id")),
            new EntityId($this->text($data, "entry_term_id")),
        );

        return new JsonResponse(
            ["id" => $application->id->value, "status" => $application->status->value],
            201,
        );
    }

    public function decide(ServerRequest $request, string $id): ResponseInterface
    {
        $data = $this->data($request);
        $decision = $this->text($data, "decision");
        if (!in_array($decision, ["accept", "reject"], true)) {
            throw new InvalidArgumentException("The decision must be accept or reject.");
        }
        $this->service->decide(new EntityId($id), $decision === "accept");

        return new JsonResponse(["status" => $decision === "accept" ? "accepted" : "rejected"]);
    }

    public function student(string $id): ResponseInterface
    {
        $profile = $this->service->studentProfile(new EntityId($id));
        $student = $profile->student;

        return new JsonResponse([
            "id" => $student->id->value,
            "person_id" => $student->personId->value,
            "institutional_id" => $profile->person->institutionalId->value,
            "program_id" => $student->programId->value,
            "entry_term_id" => $student->entryTermId->value,
            "active" => $student->active,
        ]);
    }

    public function person(string $id): ResponseInterface
    {
        $person = $this->service->person(new EntityId($id));

        return new JsonResponse([
            "id" => $person->id->value,
            "given_name" => $person->givenName,
            "family_name" => $person->familyName,
            "institutional_id" => $person->institutionalId->value,
            "identity_version" => $person->identityVersion,
        ]);
    }

    public function lookupPerson(ServerRequest $request): ResponseInterface
    {
        $person = $this->service->personByInstitutionalId(
            FormInput::from($request)->text("institutional_id", 32),
        );

        return new JsonResponse([
            "id" => $person->id->value,
            "institutional_id" => $person->institutionalId->value,
            "given_name" => $person->givenName,
            "family_name" => $person->familyName,
            "identity_version" => $person->identityVersion,
        ]);
    }

    public function updateInstitutionalId(ServerRequest $request, string $id): ResponseInterface
    {
        $data = $this->data($request);
        $person = $this->service->updateInstitutionalId(
            new EntityId($id),
            $this->optionalInstitutionalId($data),
            FormInput::from($request)->integer("identity_version"),
        );

        return new JsonResponse([
            "id" => $person->id->value,
            "institutional_id" => $person->institutionalId->value,
            "identity_version" => $person->identityVersion,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function optionalInstitutionalId(array $data): ?string
    {
        if (!isset($data["institutional_id"])) {
            return null;
        }
        if (!is_string($data["institutional_id"])) {
            throw new InvalidArgumentException(
                "Institutional IDs must be strings to preserve leading zeros.",
            );
        }

        return $data["institutional_id"];
    }

    /** @return array<string, mixed> */
    private function data(ServerRequest $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new InvalidArgumentException("A parsed form body is required.");
        }

        return $body;
    }

    /** @param array<string, mixed> $data */
    private function text(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key]) || trim($data[$key]) === "") {
            throw new InvalidArgumentException("A required field is missing or invalid.");
        }

        return trim($data[$key]);
    }
}
