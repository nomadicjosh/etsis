<?php

declare(strict_types=1);

namespace Domain\Person\ValueObject;

use Domain\Shared\Exception\ValidationException;

use function Codefy\Framework\Helpers\trans_html;

/** Syntax only; this does not verify issuance, ownership or government records. */
final readonly class SocialSecurityNumber implements \JsonSerializable
{
    private string $digits;

    public function __construct(#[\SensitiveParameter] string $value)
    {
        $value = trim($value);
        if (preg_match('/^(?:[0-9]{9}|[0-9]{3}-[0-9]{2}-[0-9]{4})$/D', $value) !== 1) {
            throw new ValidationException([['field' => 'ssn', 'message' => trans_html(
                'Enter nine digits, with optional hyphens in the form 123-45-6789.'
            )]]);
        }
        $this->digits = str_replace('-', '', $value);
    }

    /** Internal write/validation input only. Never serialize this value to a read response. */
    public function digits(): string
    {
        return $this->digits;
    }

    public function lastFour(): string
    {
        return substr($this->digits, -4);
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    public function __debugInfo(): array
    {
        return ['ssn' => '[protected]'];
    }
}
