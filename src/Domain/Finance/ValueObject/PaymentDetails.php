<?php

declare(strict_types=1);

namespace Domain\Finance\ValueObject;

use Domain\Finance\Enum\PaymentMethod;
use Domain\Shared\Exception\ValidationException;
use InvalidArgumentException;

/** Receipt information only; never card numbers, bank account numbers or credentials. */
final readonly class PaymentDetails
{
    public ?string $reference;

    public function __construct(
        public ?PaymentMethod $method = null,
        #[\SensitiveParameter] ?string $reference = null,
    ) {
        if (
            $reference !== null &&
            (!mb_check_encoding($reference, "UTF-8") || preg_match("/[\p{C}]/u", $reference))
        ) {
            throw new ValidationException([
                [
                    "field" => "payment_reference",
                    "message" => "Use a receipt reference without control characters.",
                ],
            ]);
        }
        $reference = $reference === null ? null : trim($reference);
        if ($reference === "") {
            $reference = null;
        }
        if ($reference !== null && mb_strlen($reference) > 120) {
            throw new ValidationException([
                [
                    "field" => "payment_reference",
                    "message" =>
                        "Use a receipt or check reference of up to 120 characters without control characters.",
                ],
            ]);
        }
        $this->reference = $reference;
    }

    /** @return array{method:?string,reference:?string} */
    public function values(): array
    {
        return ["method" => $this->method?->value, "reference" => $this->reference];
    }

    /** @param array<mixed> $values */
    public static function fromRecorded(#[\SensitiveParameter] array $values): self
    {
        if (
            count($values) !== 2 ||
            !array_key_exists("method", $values) ||
            !array_key_exists("reference", $values) ||
            ($values["method"] !== null &&
                (!is_string($values["method"]) || PaymentMethod::tryFrom($values["method"]) === null)) ||
            ($values["reference"] !== null && !is_string($values["reference"]))
        ) {
            throw new InvalidArgumentException("Invalid payment details.");
        }
        $details = new self(
            $values["method"] === null ? null : PaymentMethod::from($values["method"]),
            $values["reference"],
        );
        if ($details->values() != $values) {
            throw new InvalidArgumentException("Payment details must be normalized.");
        }
        return $details;
    }

    public function isEmpty(): bool
    {
        return $this->method === null && $this->reference === null;
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ["details" => "[private receipt information]"];
    }
}
