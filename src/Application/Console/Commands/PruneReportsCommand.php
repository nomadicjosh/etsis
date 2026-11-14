<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Console\ConsoleCommand;
use Domain\Reporting\Service\ReportRetention;

/** No report names, identifiers, definitions or results enter console output. */
final class PruneReportsCommand extends ConsoleCommand
{
    protected string $name = 'sis:reports:prune';
    protected string $description = 'Preview or remove a bounded batch of expired saved reports.';
    protected array $options = [
        ['apply', null, 'none', 'Remove expired report content; otherwise preview only.'],
        ['limit', null, 'required', 'Maximum reports examined (1–100).', '50'],
    ];
    protected function configure(): void
    {
        parent::configure();
        $this->getDefinition()->getOption('limit')->setDefault('50');
    }
    public function handle(): int
    {
        $limit = $this->getOptions('limit');
        if (!is_string($limit) || preg_match('/^[1-9][0-9]{0,2}$/D', $limit) !== 1 || (int) $limit > 100) {
            $this->terminalError('Use --limit=1..100.');
            return self::INVALID;
        }
        try {
            $result = $this->codefy->make(ReportRetention::class)->run(
                apply: $this->getOptions('apply') === true,
                limit: (int) $limit
            );
        } catch (\Throwable) {
            $this->terminalError('Report cleanup could not finish. Check the report lifecycle migration and database.');
            return self::FAILURE;
        }
        $this->terminalRaw(($result['apply'] ? 'Applied' : 'Preview only') . ': '
            . $result['examined'] . ' eligible reports; ' . $result['removed'] . ' removed.');
        return self::SUCCESS;
    }
}
