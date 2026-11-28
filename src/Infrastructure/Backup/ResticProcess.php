<?php

declare(strict_types=1);

namespace Infrastructure\Backup;

use Domain\Shared\Backup\BackupBudget;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Suppressed process buffers: stdout payloads go only to bounded consumers; stderr is never retained. */
final readonly class ResticProcess
{
    public function __construct(private BackupConfiguration $config)
    {
    }

    public function run(
        array $arguments,
        BackupBudget $budget,
        ?callable $consume = null,
        #[\SensitiveParameter] array $environment = [],
        #[\SensitiveParameter] ?string $input = null,
    ): string {
        $env = [];
        foreach (array_keys(getenv() + $_ENV + $_SERVER) as $name) {
            $env[$name] = false;
        }
        $env = array_replace(
            $env,
            [
                "PATH" => "/usr/bin:/bin",
                "LANG" => "C",
                "GOMAXPROCS" => "1",
                "GOMEMLIMIT" => "96MiB",
                "RESTIC_REPOSITORY" => $this->config->repository,
                "RESTIC_PASSWORD_FILE" => $this->config->passwordFile,
            ],
            $this->config->credentials,
            $environment,
        );
        $process = new Process(
            [
                $this->config->restic,
                "--no-cache",
                "--json",
                "--limit-upload=1024",
                ...$arguments,
            ],
            env: $env,
            input: $input,
            timeout: $budget->remaining(),
        );
        $process->disableOutput();
        $output = "";
        try {
            $process->run(static function (
                string $type,
                #[\SensitiveParameter] string $chunk,
            ) use (
                &$output,
                $consume,
                $budget
): void {
                $budget->remaining();
                if ($type !== Process::OUT) {
                    return;
                }
                if ($consume !== null) {
                    $consume($chunk);
                } else {
                    if (strlen($output) + strlen($chunk) > 8 * 1024 * 1024) {
                        throw new RuntimeException(
                            "Backup metadata exceeded its allowance.",
                        );
                    }
                    $output .= $chunk;
                }
            });
            if (!$process->isSuccessful()) {
                throw new RuntimeException(
                    "The backup tool did not complete successfully.",
                );
            }
            return $output;
        } finally {
            if ($process->isRunning()) {
                $process->stop(0.2);
            }
        }
    }

    public function __debugInfo(): array
    {
        return ["restic_process" => "[private bounded backup adapter]"];
    }
}
