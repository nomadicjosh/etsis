<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\AcademicRecord\Service\InstitutionalCreditLedger;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisGradeChangeController
{
    public function __construct(private InstitutionalCreditLedger $ledger)
    {
    }
    public function page(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse(
            $this->ledger->gradeChangePage(FormInput::from($request)->optionalId("after")),
        );
    }
    public function show(string $id): ResponseInterface
    {
        return new JsonResponse($this->ledger->gradeChangeRequest(new EntityId($id)));
    }
    public function review(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->ledger->reviewGradeChange(
                new EntityId($id),
                $f->text("outcome", 12),
                $f->bodyText("reason"),
            ),
        );
    }
    public function commit(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->ledger->resolveGradeChange(
                new EntityId($id),
                FormInput::from($request)->text("review_token", 65536),
            ),
        );
    }
    public function policy(): ResponseInterface
    {
        return new JsonResponse($this->ledger->gradeChangePolicy());
    }
    public function savePolicy(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->ledger->saveGradeChangePolicy($f->text("mode", 8), $f->integer("revision", 0, 2000000000)),
        );
    }
}
