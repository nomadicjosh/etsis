<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Portal\Service\CampusContentService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisPortalEditorController
{
    public function __construct(private CampusContentService $service)
    {
    }

    public function roles(ServerRequest $request): ResponseInterface
    {
        $f = new FormInput($request->getQueryParams());
        return new JsonResponse($this->service->audienceRoles(
            $f->search('search', 80),
            $f->optionalId('after'),
        ));
    }

    public function get(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->get(new EntityId($id)));
    }

    public function review(#[\SensitiveParameter] ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        [$window, $note] = $this->choices($request);
        return new JsonResponse(
            $this->service->review(
                new EntityId($id),
                $f->text("action", 8),
                $f->text("revision", 64),
                $window,
                $note,
            ),
        );
    }

    public function confirm(#[\SensitiveParameter] ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        [$window, $note] = $this->choices($request);
        return new JsonResponse(
            $this->service->confirm(
                new EntityId($id),
                $f->id("operation_id"),
                $f->text("action", 8),
                $f->text("revision", 64),
                $window,
                $note,
                $f->bodyText("review_token"),
            ),
        );
    }

    private function choices(#[\SensitiveParameter] ServerRequest $request): array
    {
        $data = $request->getParsedBody();
        $window = is_array($data) ? $data["window"] ?? null : null;
        if (
            !is_array($window) ||
            count($window) !== 2 ||
            array_diff(array_keys($window), ["visible_from", "visible_until"]) !== [] ||
            !array_key_exists("note", $data)
        ) {
            throw new \Domain\Shared\Exception\ValidationException([
                [
                    "field" => "publication",
                    "message" => "Review an explicit publication window and note choice.",
                ],
            ]);
        }
        foreach ([...array_values($window), $data["note"]] as $value) {
            if ($value !== null && !is_string($value)) {
                throw new \InvalidArgumentException("Use dates or notes as text, or explicitly choose null.");
            }
        }
        return [
            new \Domain\Portal\ValueObject\PublicationWindow(
                $window["visible_from"],
                $window["visible_until"],
            ),
            $data["note"],
        ];
    }

    public function save(#[\SensitiveParameter] ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $data = $request->getParsedBody();
        $roles = is_array($data) && array_key_exists('audience_roles', $data) ? $data['audience_roles'] : [];
        if (!is_array($roles)) {
            throw new \Domain\Shared\Exception\ValidationException([['field' => 'audience_roles',
                'message' => \Codefy\Framework\Helpers\trans('Choose account roles as a list of role references.')]]);
        }
        return new JsonResponse(
            [
                "id" => $this->service->save(
                    $f->optionalId("id"),
                    $f->text("kind", 12),
                    $f->text("code", 80),
                    $f->text("title"),
                    $f->bodyText("content"),
                    $f->text("audience", 8),
                    $f->optionalInteger("version"),
                    $f->optionalText("content_format", 8) ?? "plain",
                    $roles,
                )->value,
                "status" => "draft",
            ],
            201,
        );
    }
}
