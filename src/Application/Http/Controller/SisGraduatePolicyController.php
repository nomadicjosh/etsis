<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Registration\Service\GraduateRegistrationPolicyService;
use Domain\Shared\Exception\ValidationException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisGraduatePolicyController
{
    public function __construct(private GraduateRegistrationPolicyService $service)
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
            $this->service->review($this->rules($request), $f->integer("version"), $f->bodyText("reason")),
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
    public function levels(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->levelChoices($f->optionalText("search", 80) ?? "", $f->optionalId("after")),
        );
    }
    private function rules(ServerRequest $request): array
    {
        $data = $request->getParsedBody();
        $rules = is_array($data) ? $data["rules"] ?? null : null;
        if (!is_array($rules)) {
            throw new ValidationException([
                [
                    "field" => "rules",
                    "message" => "Provide institutional graduate-course registration rules.",
                ],
            ]);
        }
        return $rules;
    }
}
