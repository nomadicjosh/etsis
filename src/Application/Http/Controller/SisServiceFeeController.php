<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Portal\Repository\CampusRepository;
use Domain\ServiceFee\Service\ServiceFeeCheckout;
use Domain\ServiceFee\Service\ServiceFeeService;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisServiceFeeController
{
    public function __construct(
        private ServiceFeeService $fees,
        private ServiceFeeCheckout $checkout,
        private CampusRepository $campus,
        private StudentAccess $access,
    ) {
    }
    public function policies(): ResponseInterface
    {
        return new JsonResponse($this->fees->policies());
    }
    public function policy(
        ServerRequest $request,
        string $purpose,
    ): ResponseInterface {
        $i = FormInput::from($request);
        return new JsonResponse(
            $this->fees->savePolicy(
                $purpose,
                $i->integer("version"),
                $i->integer("minor"),
                $i->text("currency", 3),
                $i->boolean("waivers"),
            ),
        );
    }
    public function page(
        ServerRequest $request,
        string $person,
    ): ResponseInterface {
        return new JsonResponse(
            $this->fees->page(
                new EntityId($person),
                "staff",
                FormInput::from($request)->optionalId("after"),
            ),
        );
    }
    public function ownerPage(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse(
            $this->fees->page(
                $this->person(),
                "owner",
                FormInput::from($request)->optionalId("after"),
            ),
        );
    }
    public function prepare(
        ServerRequest $request,
        string $person,
    ): ResponseInterface {
        return $this->assess($request, new EntityId($person), "staff");
    }
    public function ownerPrepare(ServerRequest $request): ResponseInterface
    {
        return $this->assess($request, $this->person(), "owner");
    }
    public function show(string $fee): ResponseInterface
    {
        return new JsonResponse($this->fees->show(new EntityId($fee), "staff"));
    }
    public function ownerShow(string $fee): ResponseInterface
    {
        return new JsonResponse($this->fees->show(new EntityId($fee), "owner"));
    }
    public function checkout(string $fee): ResponseInterface
    {
        return new JsonResponse($this->checkout->checkout(new EntityId($fee)));
    }
    public function history(
        ServerRequest $request,
        string $fee,
    ): ResponseInterface {
        return $this->timeline($request, $fee, "staff");
    }
    public function ownerHistory(
        ServerRequest $request,
        string $fee,
    ): ResponseInterface {
        return $this->timeline($request, $fee, "owner");
    }
    public function review(
        ServerRequest $request,
        string $fee,
    ): ResponseInterface {
        $i = FormInput::from($request);
        return new JsonResponse(
            $this->fees->review(
                new EntityId($fee),
                $i->text("action", 12),
                $this->details($request),
                $i->bodyText("reason"),
            ),
        );
    }
    public function confirm(
        ServerRequest $request,
        string $fee,
    ): ResponseInterface {
        $i = FormInput::from($request);
        return new JsonResponse(
            $this->fees->confirm(
                new EntityId($fee),
                $i->id("operation_id"),
                $i->text("action", 12),
                $this->details($request),
                $i->bodyText("reason"),
                $i->text("revision", 64),
                $i->bodyText("review_token"),
            ),
        );
    }
    private function assess(
        ServerRequest $request,
        EntityId $person,
        string $channel,
    ): ResponseInterface {
        $i = FormInput::from($request);
        $purpose = $i->text("purpose", 16);
        $target =
        $purpose === "application"
        ? [
                    "program_id" => $i->id("program_id")->value,
                    "term_id" => $i->id("term_id")->value,
                ]
        : ["student_id" => $i->id("student_id")->value];
        return new JsonResponse(
            $this->fees->prepare($person, $purpose, $target, $channel),
        );
    }
    private function timeline(
        ServerRequest $request,
        string $fee,
        string $channel,
    ): ResponseInterface {
        $i = FormInput::from($request);
        return new JsonResponse(
            $this->fees->timeline(
                new EntityId($fee),
                $channel,
                $i->optionalInteger("before") ?? 201,
            ),
        );
    }
    private function person(): EntityId
    {
        return $this->campus->personForUser(
            $this->access->authenticatedUserId(),
        );
    }
    private function details(ServerRequest $request): array
    {
        $d = $request->getParsedBody()["details"] ?? null;
        if (!is_array($d)) {
            throw new \InvalidArgumentException(
                "Fee decision details are required.",
            );
        }
        return $d;
    }
}
