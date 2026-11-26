<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Console\ConsoleCommand;
use Domain\WebsiteForms\Service\NewsletterWorker;
use Throwable;

final class DeliverWebsiteSubscriptionsCommand extends ConsoleCommand
{
    protected string $name = 'sis:website-forms:subscribe';
    protected string $description = 'Deliver consented newsletter subscriptions using bounded server-side workers.';
    protected array $options = [
        ['apply', null, 'none', 'Deliver queued subscriptions.'],
        ['limit', null, 'required', 'Maximum subscriptions to process (1–3).', '1'],
    ];

    protected function configure(): void
    {
        parent::configure();
        $this->getDefinition()->getOption('limit')->setDefault('1');
    }

    public function handle(): int
    {
        $raw = $this->input->getOption('limit');
        if (!is_string($raw) || !ctype_digit($raw) || (int) $raw < 1 || (int) $raw > 3) {
            $this->terminalRaw('Use --limit=1..3.');
            return self::INVALID;
        }
        if (!$this->input->getOption('apply')) {
            $this->terminalRaw('Dry run. Use --apply to process queued subscriptions. No provider was contacted.');
            return self::SUCCESS;
        }
        try {
            $result = $this->codefy->make(NewsletterWorker::class)->run((int) $raw);
        } catch (Throwable) {
            $this->terminalError('Subscription processing stopped. Inspect the private inquiry delivery status.');
            return self::FAILURE;
        }
        $this->terminalRaw($result['paused'] ? 'Newsletter delivery is disabled.'
            : 'Processed: ' . $result['processed'] . '; completed: ' . $result['completed']
                . '; attention: ' . $result['attention'] . '.');
        return self::SUCCESS;
    }
}
