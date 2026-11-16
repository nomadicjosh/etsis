<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Codefy\Framework\Http\BaseController;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Service\StudentAccountService;
use Domain\Finance\Service\AccountCorrectionService;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final class SisFinanceController extends BaseController
{
    public function __construct(
        private readonly StudentAccountService $service,
        private readonly AccountCorrectionService $corrections,
    ) {
    }

    public function open(ServerRequest $request): ResponseInterface
    {
        $data = $request->getParsedBody();
        if (!is_array($data)) {
            throw new InvalidArgumentException("A parsed form body is required.");
        }
        $account = $this->service->open(
            new EntityId($this->text($data, "student_id")),
            new EntityId($this->text($data, "term_id")),
            $this->text($data, "currency"),
        );

        return new JsonResponse(["id" => (string) $account->aggregateId()], 201);
    }

    public function show(string $id): ResponseInterface
    {
        $profile = $this->service->accountProfile(new AccountId($id));
        $account = $profile->account;

        return new JsonResponse([
            "id" => (string) $account->aggregateId(),
            "student_id" => $account->studentId()->value,
            "institutional_id" => $profile->person->institutionalId->value,
            "term_id" => $account->termId()->value,
            "balance_minor" => $account->balance()->minor,
            "entries" => array_map(
                static fn(array $entry): array => $entry + [
                    "can_correct_amount" => $account->canCorrectAmount(new EntityId($entry["operation_id"])),
                ],
                $account->entries(),
            ),
            "position" => $account->playhead(),
            "currency" => $account->balance()->currency,
        ]);
    }

    public function post(ServerRequest $request, string $id): ResponseInterface
    {
        $data = $request->getParsedBody();
        if (!is_array($data)) {
            throw new InvalidArgumentException("A parsed form body is required.");
        }
        $type = EntryType::tryFrom($this->text($data, "type"));
        $minor = $this->text($data, "amount_minor");
        if ($type === null || preg_match('/^[1-9][0-9]{0,9}$/D', $minor) !== 1) {
            throw new InvalidArgumentException(
                "A supported entry type and positive minor-unit amount are required.",
            );
        }
        $result = $this->service->post(
            new AccountId($id),
            new EntityId($this->text($data, "operation_id")),
            $type,
            new Money((int) $minor, $this->text($data, "currency")),
            FormInput::from($request)->integer("position", 1),
            FormInput::from($request)->bodyText("reason"),
        );

        return new JsonResponse($result);
    }

    public function reviewCorrection(ServerRequest $request, string $id, string $operation): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->corrections->review(
                new AccountId($id),
                new EntityId($operation),
                new Money($f->integer("amount_minor", 1, Money::MAX_MINOR), $f->text("currency", 3)),
                $f->integer("position", 1),
                $f->bodyText("reason"),
            ),
        );
    }

    public function commitCorrection(ServerRequest $request, string $id, string $operation): ResponseInterface
    {
        return new JsonResponse(
            $this->corrections->commit(
                new AccountId($id),
                new EntityId($operation),
                FormInput::from($request)->bodyText("review_token"),
            ),
        );
    }

    /** @param array<string, mixed> $data */
    private function text(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key]) || trim($data[$key]) === "") {
            throw new InvalidArgumentException("A required field is missing or invalid.");
        }

        return trim($data[$key]);
    }
}
