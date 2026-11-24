<?php

declare(strict_types=1);

namespace Tests\Native;

use LogicException;
use PDO;

if (PHP_SAPI !== 'cli' || getenv('ETSIS_WORKFLOW_NATIVE_QUALIFICATION') !== '1') {
    throw new LogicException('Set ETSIS_WORKFLOW_NATIVE_QUALIFICATION=1 explicitly for native qualification.');
}
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new LogicException('Native qualification needs PDO SQLite for its fictional seed.');
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
