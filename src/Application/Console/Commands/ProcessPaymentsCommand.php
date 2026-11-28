<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Console\ConsoleCommand;
use Domain\Payments\Service\CaptureWorker;

final class ProcessPaymentsCommand extends ConsoleCommand
{
    protected string $name = 'sis:payments:process';
    protected string $description = 'Verify and complete a bounded batch of retained online payment authorizations.';
    protected array $options = [
        ['apply', null, 'none', 'Process retained payment work; otherwise show usage only.'],
        ['limit', null, 'required', 'Batch size from 1 to 3; default 1.'],
    ];

    public function handle(): int
    {
        if ($this->getOptions('apply') !== true) {
            $this->terminalRaw('Use --apply --limit=1 to verify retained payment work on the server.');
            return self::SUCCESS;
        }
        $limit = $this->getOptions('limit') ?? '1';
        if (!is_scalar($limit) || !in_array((string) $limit, ['1', '2', '3'], true)) {
            $this->terminalError('Choose a batch size from 1 to 3.');
            return self::INVALID;
        }
        try {
            $r = $this->codefy->make(CaptureWorker::class)->run((int) $limit);
            $this->terminalRaw('Examined ' . $r['examined'] . '; completed ' . $r['done'] . '; waiting '
                . $r['waiting'] . '; review ' . $r['review'] . '.');
        } catch (\Throwable) {
            $this->terminalError('Payment verification is unavailable. Check protected configuration.');
            return self::FAILURE;
        }
        return self::SUCCESS;
    }
}
