<?php

declare(strict_types=1);

namespace Domain\Finance\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

/** Identifies an existing posting and the account position the reviewer saw. */
final readonly class PostingReference
{
    public function __construct(public AccountId $account, public EntityId $operation, public int $position)
    {
        if ($position < 1) {
            throw new ValidationException([['field' => 'postings', 'message' => 'Refresh the account statement.']]);
        }
    }

    /** @return array{account_id:string,operation_id:string,position:int} */
    public function values(): array
    {
        return [
            'account_id' => (string) $this->account,
            'operation_id' => $this->operation->value,
            'position' => $this->position,
        ];
    }
}
