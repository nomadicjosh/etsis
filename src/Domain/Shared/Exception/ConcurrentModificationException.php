<?php

declare(strict_types=1);

namespace Domain\Shared\Exception;

use RuntimeException;

final class ConcurrentModificationException extends RuntimeException
{
}
