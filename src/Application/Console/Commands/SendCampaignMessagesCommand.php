<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Console\ConsoleCommand;
use Domain\Communications\Service\CampaignBackgroundService;
use Symfony\Component\Console\Input\InputOption;

final class SendCampaignMessagesCommand extends ConsoleCommand
{
    protected string $name = 'sis:communications:send';
    protected string $description = 'Deliver a bounded batch of explicitly confirmed campaign messages.';
    protected array $options = [['apply', null, 'none', 'Send confirmed messages; otherwise show usage only.']];

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum delivery attempts (1–20).', '10');
    }

    public function handle(): int
    {
        $limit = $this->getOptions('limit');
        if (!is_string($limit) || !preg_match('/^(?:[1-9]|1[0-9]|20)$/D', $limit)) {
            $this->terminalError('Use a limit from 1 through 20.');
            return self::INVALID;
        }
        if ($this->getOptions('apply') !== true) {
            $this->terminalRaw('Use --apply to deliver explicitly confirmed messages in the background.');
            return self::SUCCESS;
        }
        try {
            $r = $this->codefy->make(CampaignBackgroundService::class)->run((int) $limit);
        } catch (\Throwable) {
            $this->terminalError('Background delivery stopped. Review protected mail settings and campaign progress.');
            return self::FAILURE;
        }
        $this->terminalRaw('Examined ' . $r['examined'] . '; sent ' . $r['sent']
            . '; held ' . $r['held'] . '; blocked ' . $r['blocked'] . '.');
        return $r['held'] + $r['blocked'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
