<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Rules\Service\CourseRuleService;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisCourseRuleController
{
    public function __construct(private CourseRuleService $service)
    {
    }
    public function page(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->page($f->search(), $f->optionalId("after")));
    }
    public function get(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->get(new EntityId($id)));
    }
    public function versions(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->versions(new EntityId($id), FormInput::from($request)->optionalInteger("before")),
        );
    }
    public function save(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->save(
                $f->optionalId("rule_id"),
                $f->optionalInteger("expected_revision"),
                $f->text("code", 80),
                $f->text("title"),
                $this->tree($request),
            ),
            201,
        );
    }
    public function preview(ServerRequest $request): ResponseInterface
    {
        $publications = array_map(
            static fn(FormInput $item): array => [
                "course_id" => $item->text("course_id", 26),
                "version_id" => $item->text("version_id", 26),
            ],
            FormInput::from($request)->items("publications", 20),
        );
        return new JsonResponse($this->service->preview($this->tree($request), $publications));
    }
    /** @return array<string,mixed> */
    private function tree(ServerRequest $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_array($body["tree"] ?? null)) {
            throw new InvalidArgumentException("Provide course-rule conditions.");
        }
        return $body["tree"];
    }
}
