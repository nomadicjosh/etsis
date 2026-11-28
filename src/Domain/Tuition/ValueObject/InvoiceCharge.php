<?php

declare(strict_types=1);

namespace Domain\Tuition\ValueObject;

use Domain\Finance\ValueObject\LedgerSchema;
use Domain\Shared\ValueObject\EntityId;

/** Credential-free linkage to one genuine issued invoice line and its retained counterpart. */
final readonly class InvoiceCharge
{
    public function __construct(private array $d)
    {
        LedgerSchema::keys($d, [
            "bill_id",
            "invoice_id",
            "event_id",
            "line",
            "reference",
            "currency",
        ]);
        new \Domain\Finance\ValueObject\Money(0, $d["currency"]);
        foreach (["bill_id", "invoice_id", "event_id"] as $key) {
            new EntityId($d[$key]);
        }
        TuitionSchema::line($d["line"]);
        if ($d["line"]["price"] !== null) {
            $price = \Domain\Finance\ValueObject\RegistrationPrice::fromRecorded(
                $d["line"]["price"],
            );
            if (
                $price->amount->minor !== $d["line"]["quoted_minor"] ||
                $price->amount->currency !== $d["currency"] ||
                $price->enrollment->value !== $d["line"]["enrollment_id"]
            ) {
                throw new \InvalidArgumentException(
                    "Retain the original quoted credit-rate basis.",
                );
            }
        }
        TuitionSchema::text($d["reference"], 80);
    }

    public function values(): array
    {
        return $this->d;
    }

    public static function operation(string $invoice, array $line): EntityId
    {
        if (str_starts_with($line["key"], "section:")) {
            return new EntityId($line["enrollment_id"]);
        }
        return \Domain\Gradebook\ValueObject\PublishedGradeId::forVersion(
            new EntityId($invoice),
            "invoice:" . $line["key"],
        );
    }
}
