<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use InvalidArgumentException;
use Qubus\Exception\Exception;

use function Codefy\Framework\Helpers\trans_html;

/** A card restricts its own contribution; other cards and overall earned credit stay independent. */
enum RequirementCreditOrigin: string
{
    case Any = "any";
    case Institutional = "institutional";
    case Transfer = "transfer";

    public static function fromRule(array $rule): self
    {
        $value = array_key_exists("credit_origin", $rule) ? $rule["credit_origin"] : "any";
        return is_string($value) ? self::tryFrom($value) ?? self::invalid() : self::invalid();
    }

    public function allows(?string $origin = null): bool
    {
        return $this === self::Any || $origin === $this->value;
    }

    public function origins(): array
    {
        return $this === self::Any ? ["institutional", "transfer"] : [$this->value];
    }

    /**
     * @throws Exception
     */
    private static function invalid(): never
    {
        throw new InvalidArgumentException(
            trans_html("Choose institutional, transfer or either origin for a requirement."),
        );
    }
}
