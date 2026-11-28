<?php

declare(strict_types=1);

namespace Domain\Finance\ValueObject;

use Codefy\Domain\Aggregate\AggregateId;
use Domain\Finance\LedgerBook;
use Domain\Shared\ValueObject\EntityId;
use Qubus\ValueObjects\Identity\Ulid;

final class LedgerBookId extends Ulid implements AggregateId
{
    public function __construct(?string $value = null)
    {
        parent::__construct(
            ($value === null ? EntityId::generate() : new EntityId($value))
                ->value,
        );
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function aggregateClassName(): string
    {
        return LedgerBook::class;
    }

    public static function forCurrency(string $currency): self
    {
        new Money(0, $currency);
        return new self(
            "0" .
                strtoupper(
                    substr(
                        hash("sha256", "etsis.ledger.book:" . $currency),
                        0,
                        25,
                    ),
                ),
        );
    }
}
