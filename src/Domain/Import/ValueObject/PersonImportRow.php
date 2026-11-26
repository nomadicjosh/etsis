<?php

declare(strict_types=1);

namespace Domain\Import\ValueObject;

use InvalidArgumentException;

use function Codefy\Framework\Helpers\trans_html;

final readonly class PersonImportRow
{
    public string $givenName;
    public string $familyName;
    public string $email;

    public function __construct(
        public int $row,
        #[\SensitiveParameter] string $givenName,
        #[\SensitiveParameter] string $familyName,
        #[\SensitiveParameter] string $email,
    ) {
        $this->givenName = trim($givenName);
        $this->familyName = trim($familyName);
        $this->email = strtolower(trim($email));
        if ($row < 2 || $row > 5001) {
            throw new InvalidArgumentException(
                trans_html("Use at most 5,000 people per import."),
            );
        }
        foreach ([$this->givenName, $this->familyName] as $name) {
            if (
                $name === "" ||
                mb_strlen($name) > 191 ||
                !mb_check_encoding($name, "UTF-8") ||
                preg_match('/[\x00-\x1F\x7F]/', $name)
            ) {
                throw new InvalidArgumentException(
                    trans_html(
                        "Use printable given and family names up to 191 characters.",
                    ),
                );
            }
        }
        if (
            strlen($this->email) > 191 ||
            filter_var($this->email, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new InvalidArgumentException(
                trans_html("A valid email address is required for each person."),
            );
        }
    }

    /** @return array{row:int,given_name:string,family_name:string,email:string} */
    public function values(): array
    {
        return [
            "row" => $this->row,
            "given_name" => $this->givenName,
            "family_name" => $this->familyName,
            "email" => $this->email,
        ];
    }

    public static function from(#[\SensitiveParameter] array $row): self
    {
        if (
            count($row) !== 4 ||
            !is_int($row["row"] ?? null) ||
            !is_string($row["given_name"] ?? null) ||
            !is_string($row["family_name"] ?? null) ||
            !is_string($row["email"] ?? null)
        ) {
            throw new InvalidArgumentException(
                trans_html("Review the people import again."),
            );
        }
        return new self(
            $row["row"],
            $row["given_name"],
            $row["family_name"],
            $row["email"],
        );
    }
}
