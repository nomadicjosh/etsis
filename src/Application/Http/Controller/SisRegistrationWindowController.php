<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Registration\RegistrationWindow;
use Domain\Registration\Service\RegistrationWindowService;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisRegistrationWindowController
{
    public function __construct(private RegistrationWindowService $service)
    {
    }
    public function show(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->show(new EntityId($id)));
    }
    public function review(
        #[\SensitiveParameter] ServerRequest $request,
        string $id,
    ): ResponseInterface {
        $f = FormInput::from($request);
        $opens = $f->instant("opens_at");
        $closes = $f->instant("closes_at");
        if ($closes < $opens) {
            throw new \Domain\Shared\Exception\ValidationException([
                [
                    "field" => "closes_at",
                    "message" =>
                        "Registration must close on or after it opens.",
                ],
            ]);
        }
        return new JsonResponse(
            $this->service->review(
                new EntityId($id),
                new RegistrationWindow(
                    new DateRange($opens, $closes),
                    $f->integer("course_limit", 1, 1000),
                ),
                $f->integer("version", 0, 999),
                $f->text("reason", 1000),
            ),
        );
    }
    public function publish(
        #[\SensitiveParameter] ServerRequest $request,
        string $id,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->publish(
                new EntityId($id),
                FormInput::from($request)->text("review_token", 32768),
            ),
        );
    }
    public function page(ServerRequest $request, string $id): ResponseInterface
    {
        $cursor = FormInput::from($request)->optionalText("cursor", 8192);
        return new JsonResponse(
            $this->service->page(new EntityId($id), $cursor),
        );
    }
}
