<?php

declare(strict_types=1);

namespace Domain\Shared\ValueObject;

/** Deterministic command identity, retaining the source ULID timestamp. */
final class DerivedIdentity
{
    public static function from(EntityId $version, string $code): EntityId
    {
        $bytes = substr(hash("sha256", "sis.identity.v1:" . $version->value . ":" . $code, true), 0, 10);
        $bits = "";
        for ($offset = 0; $offset < 10; ++$offset) {
            $bits .= str_pad(decbin(ord($bytes[$offset])), 8, "0", STR_PAD_LEFT);
        }
        $alphabet = "0123456789ABCDEFGHJKMNPQRSTVWXYZ";
        $suffix = "";
        for ($i = 0; $i < 80; $i += 5) {
            $suffix .= $alphabet[intval(substr($bits, $i, 5), 2)];
        }
        return new EntityId(substr($version->value, 0, 10) . $suffix);
    }
}
