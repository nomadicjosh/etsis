<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Console\ConsoleCommand;
use Domain\User\Service\EmailVerificationService;
use Domain\User\Service\EmailVerificationRetention;

final class EmailVerificationCommand extends ConsoleCommand
{
    protected string $name = 'sis:accounts:email-verification';
    protected string $description = 'Send or prune a bounded batch of private email-verification challenges.';
    protected array $options = [
        ['send', null, 'none', 'Send queued notices.'],
        ['prune', null, 'none', 'Clear expired token and notice material while preserving current proof.'],
        ['apply', null, 'none', 'Apply the selected action; otherwise show usage only.'],
        ['limit', null, 'required', 'Batch size: 1–20 for sending, 1–100 for cleanup.'],
    ];

    public function handle(): int
    {
        $send = $this->getOptions('send') === true;
        $prune = $this->getOptions('prune') === true;
        if ($this->getOptions('apply') !== true) {
            $this->terminalRaw('Use --send --apply --limit=10 or --prune --apply --limit=50.');
            return self::SUCCESS;
        }
        $limit = $this->getOptions('limit') ?? ($send ? '10' : '50');
        if (
            $send === $prune || !is_scalar($limit) || !preg_match('/\A[1-9][0-9]{0,2}\z/', (string) $limit)
            || (int) $limit > ($send ? 20 : 100)
        ) {
            $this->terminalError('Select one action and a valid bounded batch size.');
            return self::INVALID;
        }
        try {
            if ($prune) {
                $this->terminalRaw('Cleared expired challenges: '
                    . $this->codefy->make(EmailVerificationRetention::class)->run((int) $limit) . '.');
            } else {
                $result = $this->codefy->make(EmailVerificationService::class)->dispatch((int) $limit);
                $this->terminalRaw('Examined ' . $result['examined'] . '; sent ' . $result['sent']
                    . '; uncertain ' . $result['uncertain'] . '; expired ' . $result['expired'] . '.');
            }
        } catch (\Throwable) {
            $this->terminalError('Email-verification processing is unavailable. Check protected configuration.');
            return self::FAILURE;
        }
        return self::SUCCESS;
    }
}
