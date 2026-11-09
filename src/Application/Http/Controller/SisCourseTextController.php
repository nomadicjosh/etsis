<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Catalog\Service\CatalogEditor;
use Domain\Catalog\ValueObject\CourseText;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisCourseTextController
{
    public function __construct(private CatalogEditor $editor)
    {
    }

    public function show(string $id): ResponseInterface
    {
        return new JsonResponse($this->editor->view("courses", new EntityId($id)));
    }

    public function save(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new \InvalidArgumentException("Provide course text in the form.");
        }
        $this->editor->courseText(new EntityId($id), CourseText::fromArray($body), $f->text("revision", 64));
        return new JsonResponse(["status" => "saved"]);
    }
}
