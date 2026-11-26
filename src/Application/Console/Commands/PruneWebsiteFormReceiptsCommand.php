<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Console\ConsoleCommand;
use DateTimeZone;
use Domain\Shared\Service\Clock;
use Domain\WebsiteForms\Repository\WebsiteFormInboxRepository;

final class PruneWebsiteFormReceiptsCommand extends ConsoleCommand
{
    protected string $name = 'sis:website-forms:prune';
    protected string $description = 'Delete expired website-form retry metadata; inquiry contents are not changed.';
    protected array $options = [
        ['apply', null, 'none', 'Delete expired retry receipts.'],
        ['limit', null, 'required', 'Maximum expired receipts to delete (1–1000).', '100'],
    ];

    protected function configure(): void
    {
        parent::configure();
        $this->getDefinition()->getOption('limit')->setDefault('100');
    }

    public function handle(): int
    {
        $raw = $this->input->getOption('limit');
        if (!is_string($raw) || !ctype_digit($raw) || (int) $raw < 1 || (int) $raw > 1000) {
            $this->terminalRaw('Use --limit=1..1000.');
            return self::INVALID;
        }
        if (!$this->input->getOption('apply')) {
            $this->terminalRaw('Dry run. Use --apply to remove expired retry metadata. Inquiry '
                . 'contents are not changed.');
            return self::SUCCESS;
        }
        try {
            $before = $this->codefy->make(Clock::class)->now()->setTimezone(new DateTimeZone('UTC'))
            ->modify('-1 hour')->format('Y-m-d\TH:i:s\Z');
            $count = $this->codefy->make(WebsiteFormInboxRepository::class)->prune($before, (int) $raw);
        } catch (\Throwable) {
            $this->terminalError('Receipt cleanup could not finish. Check the website form migration and database.');
            return self::FAILURE;
        }
        $this->terminalRaw('Expired website-form receipts removed: ' . $count . '.');
        return self::SUCCESS;
    }
}
