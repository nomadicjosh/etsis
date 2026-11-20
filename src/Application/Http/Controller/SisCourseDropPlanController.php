<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Application\Http\RegistrationPlanInput;
use Domain\Registration\Service\CourseDropService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisCourseDropPlanController
{
    public function __construct(private CourseDropService $drops)
    {
    }
    public function staffChoices(ServerRequest $request): ResponseInterface
    {
        return $this->choices($request, true);
    }
    public function ownerChoices(ServerRequest $request): ResponseInterface
    {
        return $this->choices($request, false);
    }
    public function staffReview(ServerRequest $request): ResponseInterface
    {
        return $this->review($request, true);
    }
    public function ownerReview(ServerRequest $request): ResponseInterface
    {
        return $this->review($request, false);
    }
    public function staffCommit(ServerRequest $request): ResponseInterface
    {
        return $this->commit($request, true);
    }
    public function ownerCommit(ServerRequest $request): ResponseInterface
    {
        return $this->commit($request, false);
    }
    public function staffReceipt(string $id): ResponseInterface
    {
        return new JsonResponse($this->drops->planReceipt(new EntityId($id), true));
    }
    public function ownerReceipt(string $id): ResponseInterface
    {
        return new JsonResponse($this->drops->planReceipt(new EntityId($id), false));
    }
    private function choices(ServerRequest $request, bool $staff): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->drops->planChoices(
            $f->id('student_id'),
            $staff,
            $f->search(maximum: 80),
            $f->optionalId('after')
        ));
    }
    private function review(ServerRequest $request, bool $staff): ResponseInterface
    {
        return new JsonResponse($this->drops->reviewPlan(RegistrationPlanInput::selection($request), $staff));
    }
    private function commit(ServerRequest $request, bool $staff): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->drops->commitPlan(
            RegistrationPlanInput::selection($request),
            $f->id('operation_id'),
            $f->text('revision', 64),
            $f->text('review_token', 8192),
            $staff
        ));
    }
}
