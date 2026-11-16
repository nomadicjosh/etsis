<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Codefy\Framework\Http\BaseController;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Enum\PaymentMethod;
use Domain\Finance\ValueObject\PaymentDetails;
use Domain\Finance\ValueObject\RefundAllocation;
use Domain\Finance\Service\StudentAccountService;
use Domain\Finance\Service\AccountEntryReviewService;
use Domain\Finance\Service\AccountEntryHistory;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\ValidationException;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final class SisFinanceController extends BaseController
{
    public function __construct(
        private readonly StudentAccountService $service,
        private readonly AccountEntryReviewService $reviews,
        private readonly AccountEntryHistory $history,
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
                    "can_refund_payment" => $account->canRefundPayment(new EntityId($entry["operation_id"])),
                    "can_credit_charge" => $account->canCreditCharge(new EntityId($entry["operation_id"])),
                    "can_correct_amount" => $account->canCorrectAmount(new EntityId($entry["operation_id"])),
                    "can_correct_payment_details" => $account->canCorrectPaymentDetails(
                        new EntityId($entry["operation_id"]),
                    ),
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
            $this->paymentDetails(FormInput::from($request)),
        );

        return new JsonResponse($result);
    }

    public function reviewCorrection(ServerRequest $request, string $id, string $operation): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->reviews->reviewCorrection(
                new AccountId($id),
                new EntityId($operation),
                new Money($f->integer("amount_minor", 1, Money::MAX_MINOR), $f->text("currency", 3)),
                $f->integer("position", 1),
                $f->bodyText("reason"),
                $this->refundAllocation($f),
            ),
        );
    }

    public function reviewPosting(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $type =
        EntryType::tryFrom($f->text("type", 24)) ??
        throw new ValidationException([["field" => "type", "message" => "Choose charge, payment or refund."]]);
        return new JsonResponse(
            $this->reviews->reviewPosting(
                new AccountId($id),
                $type,
                new Money($f->integer("amount_minor", 1, Money::MAX_MINOR), $f->text("currency", 3)),
                $f->integer("position", 1),
                $f->bodyText("reason"),
                $this->paymentDetails($f),
                $this->refundAllocation($f),
            ),
        );
    }

    public function commitPosting(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->reviews->commitPosting(new AccountId($id), FormInput::from($request)->bodyText("review_token")),
        );
    }

    public function commitCorrection(ServerRequest $request, string $id, string $operation): ResponseInterface
    {
        return new JsonResponse(
            $this->reviews->commitCorrection(
                new AccountId($id),
                new EntityId($operation),
                FormInput::from($request)->bodyText("review_token"),
            ),
        );
    }

    public function history(ServerRequest $request, string $id, string $operation): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->history->page(
                new AccountId($id),
                new EntityId($operation),
                $f->optionalInteger("after") ?? 0,
                $f->optionalInteger("through_position"),
            ),
        );
    }

    public function receipt(string $id, string $operation): ResponseInterface
    {
        return new JsonResponse($this->reviews->receipt(new AccountId($id), new EntityId($operation)));
    }

    public function reviewPaymentDetails(ServerRequest $request, string $id, string $operation): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->reviews->reviewPaymentDetails(
                new AccountId($id),
                new EntityId($operation),
                $this->paymentDetails($f),
                $f->integer("position", 1),
                $f->bodyText("reason"),
            ),
        );
    }

    public function commitPaymentDetails(ServerRequest $request, string $id, string $operation): ResponseInterface
    {
        return new JsonResponse(
            $this->reviews->commitPaymentDetails(
                new AccountId($id),
                new EntityId($operation),
                FormInput::from($request)->bodyText("review_token"),
            ),
        );
    }

    public function reviewReversal(ServerRequest $request, string $id, string $operation): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->reviews->reviewReversal(
                new AccountId($id),
                new EntityId($operation),
                $f->integer("position", 1),
                $f->bodyText("reason"),
            ),
        );
    }

    public function commitReversal(ServerRequest $request, string $id, string $operation): ResponseInterface
    {
        return new JsonResponse(
            $this->reviews->commitReversal(
                new AccountId($id),
                new EntityId($operation),
                FormInput::from($request)->bodyText("review_token"),
            ),
        );
    }

    public function reviewChargeCredit(ServerRequest $request, string $id, string $operation): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->reviews->reviewChargeCredit(
                new AccountId($id),
                new EntityId($operation),
                new Money($f->integer("amount_minor", 1, Money::MAX_MINOR), $f->text("currency", 3)),
                $f->integer("position", 1),
                $f->bodyText("reason"),
            ),
        );
    }

    public function commitChargeCredit(ServerRequest $request, string $id, string $operation): ResponseInterface
    {
        return new JsonResponse(
            $this->reviews->commitChargeCredit(
                new AccountId($id),
                new EntityId($operation),
                FormInput::from($request)->bodyText("review_token"),
            ),
        );
    }

    public function reviewRefund(ServerRequest $request, string $id, string $operation): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->reviews->reviewRefund(
                new AccountId($id),
                new EntityId($operation),
                new Money($f->integer("amount_minor", 1, Money::MAX_MINOR), $f->text("currency", 3)),
                $f->integer("position", 1),
                $f->bodyText("reason"),
                $this->paymentDetails($f),
                $this->refundAllocation($f),
            ),
        );
    }

    public function commitRefund(ServerRequest $request, string $id, string $operation): ResponseInterface
    {
        return new JsonResponse(
            $this->reviews->commitRefund(
                new AccountId($id),
                new EntityId($operation),
                FormInput::from($request)->bodyText("review_token"),
            ),
        );
    }

    private function refundAllocation(FormInput $input): ?RefundAllocation
    {
        try {
            $values = $input->values("refund_allocations", RefundAllocation::MAX_PAYMENTS);
            return $values === [] ? null : new RefundAllocation($values);
        } catch (ValidationException $error) {
            throw $error;
        } catch (InvalidArgumentException) {
            throw new ValidationException([
                [
                    "field" => "refund_allocations",
                    "message" => "Choose up to ten recorded payments and a positive amount for each share.",
                ],
            ]);
        }
    }

    private function paymentDetails(FormInput $f): PaymentDetails
    {
        $method = $f->search("payment_method", 32);
        $method = $method === "" ? null : $method;
        $choice = $method === null ? null : PaymentMethod::tryFrom($method);
        if ($method !== null && $choice === null) {
            throw new ValidationException([
                ["field" => "payment_method", "message" => "Choose a supported payment method."],
            ]);
        }
        return new PaymentDetails($choice, $f->search("payment_reference", 480));
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
