<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Console\ConsoleCommand;
use Domain\Reporting\Service\ReportExportService;

/** Count-only operator output. Browser requests only enqueue authorized references. */
final class ExportReportsCommand extends ConsoleCommand
{
    protected string $name = 'sis:reports:export';
    protected string $description = 'Prepare a bounded batch of private requested report exports.';
    protected array $options = [
        ['apply', null, 'none', 'Prepare queued exports; otherwise preview eligible work only.'],
        ['limit', null, 'required', 'Maximum export jobs (1–5).', '1'],
    ];

    protected function configure(): void
    {
        parent::configure();
        $this->getDefinition()->getOption('limit')->setDefault('1');
    }

    public function handle(): int
    {
        $limit = $this->getOptions('limit');
        if (!is_string($limit) || preg_match('/^[1-5]$/D', $limit) !== 1) {
            $this->terminalError('Use --limit=1..5.');
            return self::INVALID;
        }
        try {
            $result = $this->codefy->make(ReportExportService::class)->work(
                apply: $this->getOptions('apply') === true,
                limit: (int) $limit,
            );
        } catch (\Throwable) {
            $this->terminalError('Report exports could not finish. Check export storage and private keys.');
            return self::FAILURE;
        }
        $this->terminalRaw(($result['apply'] ? 'Applied' : 'Preview only') . ': ' . $result['examined']
            . ' export jobs; ' . $result['ready'] . ' ready; ' . $result['failed'] . ' failed.');
        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
