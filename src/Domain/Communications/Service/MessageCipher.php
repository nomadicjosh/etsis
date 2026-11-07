<?php

declare(strict_types=1);

namespace Domain\Communications\Service;

interface MessageCipher
{
    public function seal(#[\SensitiveParameter] string $content): string;
    public function open(#[\SensitiveParameter] string $ciphertext): string;
}
