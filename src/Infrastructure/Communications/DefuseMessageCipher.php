<?php

declare(strict_types=1);

namespace Infrastructure\Communications;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use Domain\Communications\Service\MessageCipher;
use Qubus\Config\ConfigContainer;

final readonly class DefuseMessageCipher implements MessageCipher
{
    public function __construct(private ConfigContainer $config)
    {
    }

    public function seal(#[\SensitiveParameter] string $content): string
    {
        return Crypto::encrypt($content, Key::loadFromAsciiSafeString($this->config->getConfigKey('app.crypto_key')));
    }

    public function open(#[\SensitiveParameter] string $ciphertext): string
    {
        return Crypto::decrypt(
            $ciphertext,
            Key::loadFromAsciiSafeString($this->config->getConfigKey('app.crypto_key'))
        );
    }
}
