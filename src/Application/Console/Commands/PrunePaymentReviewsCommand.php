<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Console\ConsoleCommand;
use Domain\Payments\Service\GatewayReviewRetention;

final class PrunePaymentReviewsCommand extends ConsoleCommand
{
    protected string $name = 'sis:payments:prune-reviews';
    protected string $description = 'Clear a bounded batch of expired unaccepted payment setup proposals.';
    protected array $options = [
        ['apply', null, 'none', 'Clear expired proposals; otherwise show usage only.'],
        ['limit', null, 'required', 'Batch size from 1 to 100; default 50.'],
    ];

    public function handle(): int
    {
        if ($this->getOptions('apply') !== true) {
            $this->terminalRaw('Use --apply --limit=50 to clear expired unaccepted payment setup proposals.');
            return self::SUCCESS;
        }
        $limit = $this->getOptions('limit') ?? '50';
        if (!is_scalar($limit) || !preg_match('/\A[1-9][0-9]{0,2}\z/', (string) $limit) || (int) $limit > 100) {
            $this->terminalError('Choose a batch size from 1 to 100.');
            return self::INVALID;
        }
        try {
            $count = $this->codefy->make(GatewayReviewRetention::class)->run((int) $limit);
            $this->terminalRaw('Cleared expired payment setup proposals: ' . $count . '.');
        } catch (\Throwable) {
            $this->terminalError('Payment setup cleanup is unavailable. Check protected configuration.');
            return self::FAILURE;
        }
        return self::SUCCESS;
    }
}
