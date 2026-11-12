<?php

declare(strict_types=1);

namespace Infrastructure\Security;

use Defuse\Crypto\{Crypto, Key};
use Application\Http\Security\LoginCookieReader;
use Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException;
use Domain\User\ValueObject\VerifiedLoginCookie;
use Domain\Shared\Service\Clock;
use Psr\Http\Message\ServerRequestInterface;
use Qubus\Config\ConfigContainer;
use JsonException;

/** Inspect the existing Codefy cookie envelope without exposing its account token. */
final readonly class FrameworkLoginCookieReader implements LoginCookieReader
{
    public function __construct(private ConfigContainer $config, private Clock $clock)
    {
    }
    public function name(): string
    {
        return $this->config->string("auth.cookie_name", "USERSESSID");
    }
    public function read(ServerRequestInterface $request): ?VerifiedLoginCookie
    {
        $cookie = $request->getCookieParams()[$this->name()] ?? null;
        if (!is_string($cookie) || $cookie === "" || strlen($cookie) > 16384) {
            return null;
        }
        // Configuration/key failures remain errors; malformed/untrusted cookies are anonymous.
        $key = Key::loadFromAsciiSafeString($this->config->string("app.crypto_key"));
        try {
            $data = json_decode(Crypto::decrypt($cookie, $key), true, 8, JSON_THROW_ON_ERROR);
        } catch (WrongKeyOrModifiedCiphertextException | JsonException) {
            return null;
        }
        if (
            !is_array($data) ||
            ($data["version"] ?? null) !== 1 ||
            !is_string($data["token"] ?? null) ||
            $data["token"] === "" ||
            !is_int($data["expires"] ?? null) ||
            $data["expires"] <= $this->clock->now()->getTimestamp()
        ) {
            return null;
        }
        return new VerifiedLoginCookie(
            hash("sha256", $cookie),
            hash("sha256", $data["token"]),
            $data["expires"],
        );
    }
}
