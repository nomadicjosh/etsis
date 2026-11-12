<?php

declare(strict_types=1);

namespace Application\Http\Security;

use Psr\Http\Message\{ServerRequestInterface, ResponseInterface};

interface MoodleSessionChannel
{
    public function handle(ServerRequestInterface $request): ResponseInterface;
}
