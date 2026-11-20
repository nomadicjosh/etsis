<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Registration\Service\SectionEnrollmentReviewService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisSectionEnrollmentReviewController
{
    public function __construct(private SectionEnrollmentReviewService $service)
    {
    }

    public function page(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->page(
                $f->optionalText("state", 8) ?? "below",
                $f->optionalText("search", 80) ?? "",
                $f->boolean("include_inactive"),
                $f->optionalId("term_id"),
                $f->optionalId("after"),
            ),
        );
    }
}
