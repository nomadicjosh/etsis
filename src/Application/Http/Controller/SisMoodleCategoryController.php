<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Lms\Service\MoodleCategoryService;
use Domain\Lms\ValueObject\MoodleExternalId;
use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisMoodleCategoryController
{
    public function __construct(private MoodleCategoryService $service)
    {
    }
    public function settings(): ResponseInterface
    {
        return new JsonResponse($this->service->settings());
    }
    public function configure(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->configure($f->text("source", 12), $f->integer("version", 0, 2147483646)),
        );
    }
    public function page(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse($this->service->page(new FormInput($request->getQueryParams())->search()));
    }
    public function link(ServerRequest $request, string $reference): ResponseInterface
    {
        $f = FormInput::from($request);
        try {
            return new JsonResponse(
                $this->service->link(
                    new EntityId($reference),
                    new MoodleExternalId($f->text("category", 20)),
                    $f->requiredBoolean("acknowledged"),
                    $f->text("reason", 500),
                ),
            );
        } catch (MoodleConnectionUnavailableException $e) {
            return new JsonResponse(["error" => $e->getMessage()], 503);
        }
    }
}
