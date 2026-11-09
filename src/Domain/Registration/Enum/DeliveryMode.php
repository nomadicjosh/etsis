<?php

declare(strict_types=1);

namespace Domain\Registration\Enum;

use Domain\Shared\Exception\ValidationException;

enum DeliveryMode: string
{
    case InPerson = "in_person";
    case Online = "online";
    case Hybrid = "hybrid";
    case Unspecified = "unspecified";

    public static function parse(string $value): self
    {
        return self::tryFrom($value) ??
        throw new ValidationException([
                ["field" => "delivery_mode", "message" => "Choose in person, online, hybrid or unspecified delivery."],
            ]);
    }

    public function label(): string
    {
        return match ($this) {
            self::InPerson => "In person",
            self::Online => "Online",
            self::Hybrid => "Hybrid",
            self::Unspecified => "Delivery to be confirmed",
        };
    }
}
