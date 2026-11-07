<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$environmentFile = $projectRoot . '/.env';
$exampleFile = $projectRoot . '/.env.example';

if (is_link($environmentFile)) {
    fwrite(STDERR, "Refusing to configure a symbolic-link .env file.\n");
    exit(1);
}

if (! is_file($environmentFile)) {
    if (! is_file($exampleFile) || ! copy($exampleFile, $environmentFile)) {
        fwrite(STDERR, "Unable to create .env from .env.example.\n");
        exit(1);
    }
}

$contents = file_get_contents($environmentFile);

if ($contents === false) {
    fwrite(STDERR, "Unable to read .env.\n");
    exit(1);
}

$basePathPattern = '/^APP_BASE_PATH[ \t]*=[ \t]*(.*)$/m';

if (preg_match($basePathPattern, $contents, $matches) === 1) {
    $configuredPath = trim($matches[1]);

    if ($configuredPath !== '' && $configuredPath !== '""' && $configuredPath !== "''") {
        exit(0);
    }
}

$basePath = 'APP_BASE_PATH=' . json_encode($projectRoot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

if (isset($matches[0])) {
    $contents = preg_replace($basePathPattern, $basePath, $contents, 1);
} else {
    $contents = rtrim($contents, "\r\n") . PHP_EOL . $basePath . PHP_EOL;
}

if ($contents === null || file_put_contents($environmentFile, $contents, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to set APP_BASE_PATH in .env.\n");
    exit(1);
}
