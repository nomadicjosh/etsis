<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Gradebook\Service\GradeComparisonPolicyService;
use Domain\Shared\Exception\ValidationException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisGradeComparisonPolicyController
{
    public function __construct(private GradeComparisonPolicyService $service)
    {
    }
    public function view(): ResponseInterface
    {
        return new JsonResponse($this->service->view());
    }
    public function review(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->review(
                $this->rules($request),
                $f->integer("version"),
                $f->bodyText("reason"),
            ),
        );
    }
    public function publish(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->publish(
                $f->id("publication_id"),
                $this->rules($request),
                $f->integer("version"),
                $f->bodyText("reason"),
                $f->text("review_token", 8192),
            ),
        );
    }
    private function rules(ServerRequest $request): array
    {
        $data = $request->getParsedBody();
        $rules = is_array($data) ? $data["rules"] ?? null : null;
        if (!is_array($rules)) {
            throw new ValidationException([
                ["field" => "rules", "message" => "Provide institutional grade-comparison rules."],
            ]);
        }
        return $rules;
    }
}
