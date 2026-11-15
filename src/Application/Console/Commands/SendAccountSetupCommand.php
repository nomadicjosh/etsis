<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Console\ConsoleCommand;
use Domain\Person\Service\AccountSetupService;

final class SendAccountSetupCommand extends ConsoleCommand
{
    protected string $name = 'sis:accounts:send-setup';
    protected string $description = 'Send a bounded batch of queued account password-setup notices.';
    protected array $options = [['apply', null, 'none', 'Send queued notices; otherwise show usage only.']];
    public function handle(): int
    {
        if ($this->getOptions('apply') !== true) {
            $this->terminalRaw('Use --apply to send up to 10 queued password-setup notices.');
            return self::SUCCESS;
        }
        try {
            $r = $this->codefy->make(AccountSetupService::class)->dispatch();
        } catch (\Throwable) {
            $this->terminalError('Account setup delivery is unavailable. Check protected mail configuration.');
            return self::FAILURE;
        }
        $this->terminalRaw('Examined ' . $r['examined'] . '; sent ' . $r['sent']
            . '; uncertain ' . $r['uncertain'] . '; expired ' . $r['expired'] . '.');
        return self::SUCCESS;
    }
}
