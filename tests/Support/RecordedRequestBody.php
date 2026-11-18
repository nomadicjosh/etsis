<?php

declare(strict_types=1);

namespace Tests\Support;

use Laminas\Diactoros\Stream;

final class RecordedRequestBody extends Stream
{
    public array $requested = [];
    public function read(int $length): string
    {
        $this->requested[] = $length;
        return parent::read($length);
    }
}
