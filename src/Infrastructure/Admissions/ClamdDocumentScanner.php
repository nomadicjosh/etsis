<?php

declare(strict_types=1);

namespace Infrastructure\Admissions;

use Domain\Admissions\Service\AdmissionDocumentScanner;
use Qubus\Config\ConfigContainer;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class ClamdDocumentScanner implements AdmissionDocumentScanner
{
    public function __construct(private ConfigContainer $config)
    {
    }
    public function scan(#[\SensitiveParameter] string $bytes): string
    {
        $binary = $this->config->getConfigKey('admission_documents.scanner_binary', '/usr/bin/clamdscan');
        if (!is_string($binary) || !is_file($binary) || !is_executable($binary)) {
            return 'unavailable';
        }
        try {
            // A small client talks to the configured daemon. No plaintext temporary file or scan report.
            $process = new Process([$binary, '--no-summary', '--stream', '-'], input: $bytes, timeout: 20);
            $process->disableOutput();
            $code = $process->run();
            return match ($code) {
                0 => 'clean', 1 => 'infected', default => 'unavailable'
            };
        } catch (Throwable) {
            return 'unavailable';
        }
    }
}
