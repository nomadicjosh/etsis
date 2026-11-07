<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Codefy\Framework\Http\BaseController;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Service\StudentAccountService;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final class SisFinanceController extends BaseController
{
    public function __construct(private readonly StudentAccountService $service)
    {
    }

    public function open(ServerRequest $request): ResponseInterface
    {
        $data = $request->getParsedBody();
        if (!is_array($data)) {
            throw new InvalidArgumentException('A parsed form body is required.');
        }
        $account = $this->service->open(
            new EntityId($this->text($data, 'student_id')),
            new EntityId($this->text($data, 'term_id')),
            $this->text($data, 'currency'),
        );

        return new JsonResponse(['id' => (string) $account->aggregateId()], 201);
    }

    public function show(string $id): ResponseInterface
    {
        $profile = $this->service->accountProfile(new AccountId($id));
        $account = $profile->account;

        return new JsonResponse([
            'id' => (string) $account->aggregateId(),
            'student_id' => $account->studentId()->value,
            'institutional_id' => $profile->person->institutionalId->value,
            'term_id' => $account->termId()->value,
            'balance_minor' => $account->balance()->minor,
            'entries' => $account->entries(),
            'currency' => $account->balance()->currency,
        ]);
    }

    public function post(ServerRequest $request, string $id): ResponseInterface
    {
        $data = $request->getParsedBody();
        if (!is_array($data)) {
            throw new InvalidArgumentException('A parsed form body is required.');
        }
        $type = EntryType::tryFrom($this->text($data, 'type'));
        $minor = $this->text($data, 'amount_minor');
        if ($type === null || preg_match('/^[1-9][0-9]{0,9}$/D', $minor) !== 1) {
            throw new InvalidArgumentException('A supported entry type and positive minor-unit amount are required.');
        }
        $this->service->post(
            new AccountId($id),
            new EntityId($this->text($data, 'operation_id')),
            $type,
            new Money((int) $minor, $this->text($data, 'currency')),
        );

        return new JsonResponse(['status' => 'posted']);
    }

    /** @param array<string, mixed> $data */
    private function text(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key]) || trim($data[$key]) === '') {
            throw new InvalidArgumentException('A required field is missing or invalid.');
        }

        return trim($data[$key]);
    }
}
