<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Registration\Service\SectionChangeService;
use Domain\Registration\ValueObject\SectionChangeSelection;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

/** Browser entry points fix the authorization channel; clients cannot select staff authority. */
final readonly class SectionChangeController
{
    public function __construct(private SectionChangeService $service)
    {
    }

    public function staffChoices(ServerRequest $request): ResponseInterface
    {
        return $this->choices($request, true);
    }

    public function studentChoices(ServerRequest $request): ResponseInterface
    {
        return $this->choices($request, false);
    }

    public function staffReview(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse($this->service->review($this->selection($request), true));
    }

    public function studentReview(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse($this->service->review($this->selection($request), false));
    }

    public function staffCommit(ServerRequest $request): ResponseInterface
    {
        return $this->commit($request, true);
    }

    public function studentCommit(ServerRequest $request): ResponseInterface
    {
        return $this->commit($request, false);
    }

    private function choices(ServerRequest $request, bool $byStaff): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->choices(
                $f->id("student_id"),
                $f->id("from_section_id"),
                $byStaff,
                $f->optionalText("search", 80) ?? "",
                $f->optionalId("after"),
            ),
        );
    }

    private function selection(ServerRequest $request): SectionChangeSelection
    {
        $f = FormInput::from($request);
        return new SectionChangeSelection(
            $f->id("student_id"),
            $f->id("from_section_id"),
            $f->id("to_section_id"),
            $f->optionalInteger("credits_milli"),
        );
    }

    private function commit(ServerRequest $request, bool $byStaff): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->commit(
                $f->id("operation_id"),
                $this->selection($request),
                $f->text("revision", 64),
                $f->text("review_token", 8192),
                $byStaff,
            ),
        );
    }
}
