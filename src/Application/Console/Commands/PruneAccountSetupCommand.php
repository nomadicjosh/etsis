<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Console\ConsoleCommand;
use Domain\Person\Service\AccountSetupRetention;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Trusted deployment operator only; no browser/API route and no mail transport dependency. */
final class PruneAccountSetupCommand extends ConsoleCommand
{
    protected string $name = 'sis:accounts:prune-setup';
    protected string $description = 'Preview or clear expired queued account-setup notification content.';
    protected array $options = [
        ['apply', null, 'none', 'Clear verified expired notice content; otherwise inspect without changes.'],
        ['limit', null, 'required', 'Maximum candidates examined, 1–100.', '50'],
        ['grace-hours', null, 'required', 'Keep notice content this many hours after link expiry, 1–8760.', '24'],
        ['after', null, 'required', 'Resume after the previous opaque setup reference.'],
        ['json', null, 'none', 'Emit fixed metadata and counts only.'],
    ];

    protected function configure(): void
    {
        parent::configure();
        $this->getDefinition()->getOption('limit')->setDefault('50');
        $this->getDefinition()->getOption('grace-hours')->setDefault('24');
    }

    public function handle(): int
    {
        try {
            $limit = $this->number($this->getOptions('limit'), 100);
            $grace = $this->number($this->getOptions('grace-hours'), 8760);
            $after = $this->getOptions('after');
            $after = $after === null ? null : new EntityId($after);
        } catch (InvalidArgumentException) {
            $this->terminalError('Use --limit=1..100, --grace-hours=1..8760 and an optional valid --after reference.');
            return self::INVALID;
        }
        try {
            $result = $this->codefy->make(AccountSetupRetention::class)->run(
                apply: $this->getOptions('apply') === true,
                limit: $limit,
                graceHours: $grace,
                after: $after,
            );
        } catch (Throwable) {
            $this->terminalError(
                'Setup notice cleanup could not finish. Check protected storage and try a bounded scan.'
            );
            return self::FAILURE;
        }
        if ($this->getOptions('json') === true) {
            $this->output->writeln(json_encode($result, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $this->terminalRaw(
                $result['apply'] ? 'Applied setup-notice retention.' : 'Preview only; no records changed.'
            );
            $this->terminalRaw('Expiry cutoff: ' . $result['cutoff_utc'] . '. Examined ' . $result['examined'] .
                '; eligible ' . $result['eligible'] . '; cleared ' . $result['cleared'] .
                '; unverifiable ' . $result['invalid'] . '; changed ' . $result['changed'] . '.');
            if ($result['next_after'] !== null) {
                $this->terminalRaw('More candidates may remain. Next --after reference: ' . $result['next_after']);
            }
            if ($result['invalid'] > 0) {
                $this->terminalRaw('Unverifiable invitations were retained for operator review.');
            }
            if ($result['limited']) {
                $this->terminalRaw('Time budget reached. Earlier completed redactions remain; resume the scan.');
            }
        }
        return $result['invalid'] > 0 || $result['changed'] > 0 || $result['limited'] ? 3 : self::SUCCESS;
    }

    private function number(mixed $value, int $maximum): int
    {
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,3}$/D', $value) !== 1 || (int) $value > $maximum) {
            throw new InvalidArgumentException();
        }
        return (int) $value;
    }
}
