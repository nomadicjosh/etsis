<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class DomainBoundaryTest extends TestCase
{
    public function testDomainCodeDoesNotDependOnApplicationOrInfrastructureNamespaces(): void
    {
        $root = dirname(__DIR__, 2) . '/src/Domain';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        $violations = [];
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                if (!is_array($token) || !in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }
                $name = ltrim($token[1], '\\');
                if (str_starts_with($name, 'Application\\') || str_starts_with($name, 'Infrastructure\\')) {
                    $violations[] = $file->getPathname() . ':' . $token[2] . ' depends on ' . $name;
                }
            }
        }
        self::assertSame([], $violations, 'Domain contexts must depend on contracts inside Domain.');
    }
}
