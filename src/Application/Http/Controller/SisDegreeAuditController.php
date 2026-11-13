<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\DegreeAudit\Service\RequirementsService;
use Domain\DegreeAudit\Service\DegreeAuditTrialService;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisDegreeAuditController
{
    public function __construct(private RequirementsService $service, private DegreeAuditTrialService $trials)
    {
    }
    public function page(ServerRequest $request, string $program): ResponseInterface
    {
        return new JsonResponse(
            $this->service->page(new EntityId($program), FormInput::from($request)->optionalId("after")),
        );
    }
    public function open(ServerRequest $request, string $program): ResponseInterface
    {
        return new JsonResponse(
            $this->service->open(new EntityId($program), FormInput::from($request)->id("academic_year_id")),
        );
    }
    public function view(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->view(new EntityId($id)));
    }
    public function save(ServerRequest $request, string $id): ResponseInterface
    {
        $body = $request->getParsedBody();
        $definition = is_array($body) ? $body["definition"] ?? null : null;
        if (!is_array($definition) || array_is_list($definition)) {
            throw new \InvalidArgumentException("Provide a requirement definition.");
        }
        return new JsonResponse(
            $this->service->save(
                new EntityId($id),
                FormInput::from($request)->integer("revision", 0),
                new AuditRequirementsV3($definition),
            ),
        );
    }
    public function test(ServerRequest $request, string $id): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new \InvalidArgumentException('Choose the audit source to test.');
        }
        $input = FormInput::from($request);
        $source = $input->text('source', 20);
        if ($source === 'publication') {
            if (array_key_exists('definition', $body) || array_key_exists('use_group_drafts', $body)) {
                throw new \InvalidArgumentException('A published audit test cannot override its retained definitions.');
            }
            return new JsonResponse($this->trials->testPublication(
                new EntityId($id),
                $input->id('version_id'),
                $input->id('student_id'),
                $input->id('assignment_id'),
            ));
        }
        if ($source !== 'draft' || !is_array($body['definition'] ?? null) || array_key_exists('version_id', $body)) {
            throw new \InvalidArgumentException('Provide a working draft or an exact published audit version.');
        }
        return new JsonResponse($this->trials->test(
            new EntityId($id),
            $input->id('student_id'),
            $input->id('assignment_id'),
            new AuditRequirementsV3($body['definition']),
            $input->boolean('use_group_drafts'),
        ));
    }
    public function clone(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->cloneTo(
                new EntityId($id),
                $f->id("version_id"),
                $f->id("program_id"),
                $f->id("academic_year_id"),
            ),
            201,
        );
    }
    public function review(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->review(new EntityId($id), FormInput::from($request)->bodyText("reason")),
        );
    }
    public function publish(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->publish(
                new EntityId($id),
                FormInput::from($request)->text("review_token", 131072),
            ),
        );
    }
    public function versions(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->versions(
                new EntityId($id),
                FormInput::from($request)->optionalText("cursor", 16384),
            ),
        );
    }
    public function version(string $id, string $version): ResponseInterface
    {
        return new JsonResponse($this->service->version(new EntityId($id), new EntityId($version)));
    }
    public function reuse(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->reuseLegacy(new EntityId($id), FormInput::from($request)->integer("revision", 0)),
        );
    }
}
