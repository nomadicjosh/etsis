<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Catalog\Service\ProgramSpecializationService;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisProgramSpecializationController
{
    public function __construct(
        private ProgramSpecializationService $service,
        private \Domain\Catalog\Service\SpecializationPolicyService $policies,
    ) {
    }

    public function page(ServerRequest $request, string $id): ResponseInterface
    {
        $input = FormInput::from($request);
        return new JsonResponse(
            $this->service->page(new EntityId($id), $input->search(), $input->optionalId('after')),
        );
    }

    public function show(string $id, string $specialization): ResponseInterface
    {
        return new JsonResponse($this->service->get(new EntityId($id), new EntityId($specialization)));
    }

    public function policy(string $id): ResponseInterface
    {
        return new JsonResponse($this->policies->settings(new EntityId($id)));
    }

    public function savePolicy(ServerRequest $request, string $id): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || !array_key_exists('maximum', $body)) {
            throw new InvalidArgumentException('Provide the explicit maximum or null to inherit.');
        }
        $input = FormInput::from($request);
        $this->policies->saveProgram(
            new EntityId($id),
            $input->integer('revision'),
            $input->integer('minimum', 0, 100),
            $input->optionalInteger('maximum'),
        );
        return new JsonResponse(['status' => 'saved']);
    }

    public function saveInstitutionPolicy(ServerRequest $request): ResponseInterface
    {
        $input = FormInput::from($request);
        $this->policies->saveInstitution(
            $input->integer('revision'),
            $input->integer('maximum', 1, 100),
            $input->integer('default_maximum', 1, 100),
        );
        return new JsonResponse(['status' => 'saved']);
    }

    public function save(ServerRequest $request, string $id): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (
            !is_array($body) ||
            !array_key_exists('id', $body) ||
            !array_key_exists('revision', $body) ||
            !array_key_exists('description', $body)
        ) {
            throw new InvalidArgumentException(
                'Provide the specialization identity, revision and description.',
            );
        }
        $description = $body['description'];
        if ($description !== null && (!is_string($description) || mb_strlen($description) > 10000)) {
            throw new InvalidArgumentException('Use a plain-text description up to 10000 characters.');
        }
        $input = FormInput::from($request);
        return new JsonResponse(
            $this->service->save(
                new EntityId($id),
                $input->optionalId('id'),
                $input->optionalInteger('revision'),
                $input->text('code', 80),
                $input->text('title', 191),
                $description,
                $input->requiredBoolean('active'),
            ),
        );
    }
}
