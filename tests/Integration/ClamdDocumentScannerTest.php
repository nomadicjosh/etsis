<?php

declare(strict_types=1);

namespace Tests\Integration;

use Infrastructure\Admissions\ClamdDocumentScanner;
use PHPUnit\Framework\TestCase;
use Qubus\Config\Collection;

final class ClamdDocumentScannerTest extends TestCase
{
    public function testClientUsesStdinAndSuppressesAllProviderOutputForEachExitOutcome(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The harmless executable fixture uses a POSIX shell.');
        }
        $this->expectOutputString('');
        foreach ([0 => 'clean', 1 => 'infected', 2 => 'unavailable'] as $code => $outcome) {
            $path = '/tmp/etsis-scanner-client-' . bin2hex(random_bytes(8));
            $script = <<<'SCRIPT'
#!/bin/sh
[ "$1" = "--no-summary" ] && [ "$2" = "--stream" ] && [ "$3" = "-" ] || exit 2
cat >/dev/null
printf 'Fictional private scanner output'
printf 'Fictional private scanner error' >&2
SCRIPT;
            file_put_contents($path, $script . "\nexit " . $code . "\n");
            chmod($path, 0700);
            try {
                $config = Collection::factory([]);
                $config->setConfigKey('admission_documents', ['scanner_binary' => $path]);
                self::assertSame($outcome, new ClamdDocumentScanner($config)->scan('Fictional file bytes.'));
            } finally {
                unlink($path);
            }
        }
    }
    public function testMissingClientNeverReportsACleanFile(): void
    {
        $config = Collection::factory([]);
        $config->setConfigKey('admission_documents', ['scanner_binary' => '/does-not-exist/clamdscan']);
        self::assertSame('unavailable', new ClamdDocumentScanner($config)->scan('Fictional file bytes.'));
    }
}
