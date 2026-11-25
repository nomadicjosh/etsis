<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Console\ConsoleCommand;
use Domain\Finance\Repository\AccountProjectionRecovery;
use Domain\Finance\Repository\AccountProjectionStageStorage;
use Domain\Finance\ValueObject\AccountId;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Trusted deployment operator only; deliberate maintenance promotion, never business event replay. */
final class RecoverAccountProjectionsCommand extends ConsoleCommand
{
    protected string $name = 'sis:projections:account';
    protected string $description = 'Stage, check, apply or discard isolated financial-account projections.';
    protected array $options = [
        ['action', null, 'required', 'stage, check, apply or discard.'],
        ['account', null, 'required', 'Account ULID; required only for stage.'],
        ['stage', null, 'required', 'Private stage ULID; required for check, apply or discard.'],
        ['events', null, 'required', 'Replay allowance (1–2000 events).', '500'],
        ['json', null, 'none', 'Emit only fixed status, reference and counts.'],
    ];

    protected function configure(): void
    {
        parent::configure();
        $this->getDefinition()->getOption('events')->setDefault('500');
    }

    public function handle(): int
    {
        try {
            $action = $this->getOptions('action');
            $account = $this->getOptions('account');
            $stage = $this->getOptions('stage');
            $events = $this->getOptions('events');
            if (
                !in_array($action, ['stage', 'check', 'apply', 'discard'], true)
                || !is_string($events) || preg_match('/^[1-9][0-9]{0,3}$/D', $events) !== 1 || (int) $events > 2000
                || ($action === 'stage' && (!is_string($account) || $stage !== null))
                || ($action !== 'stage' && (!is_string($stage) || $account !== null))
                || ($action === 'discard' && $this->input->hasParameterOption('--events'))
            ) {
                throw new InvalidArgumentException();
            }
            $id = new EntityId($action === 'stage' ? $account : $stage);
            if ($action === 'discard') {
                $this->codefy->make(AccountProjectionStageStorage::class)->discard($id);
                $result = ['status' => 'discarded', 'stage_reference' => $id->value];
            } else {
                $service = $this->codefy->make(AccountProjectionRecovery::class);
                $result = match ($action) {
                    'stage' => $service->stage(new AccountId($id->value), (int) $events),
                    'check' => $service->check($id, (int) $events),
                    'apply' => $service->apply($id, (int) $events),
                };
            }
        } catch (InvalidArgumentException) {
            $result = ['status' => 'invalid_input'];
        } catch (HistoryReadLimitException) {
            $result = ['status' => 'limited'];
        } catch (ConcurrentModificationException) {
            $result = ['status' => 'changed'];
        } catch (Throwable) {
            // Never forward SQL, private locations, identities, amounts or protected exception chains.
            $result = ['status' => 'not_verified'];
        }
        if ($this->getOptions('json') === true) {
            $this->output->writeln(json_encode($result, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $this->terminalRaw(match ($result['status']) {
                'staged' => 'Encrypted projection stage saved. Database records were unchanged.',
                'ready' => 'Stage matches fresh source and current projections. No database records changed.',
                'applied' => 'Projection promotion completed atomically; a protected retry receipt was retained.',
                'already_applied' => 'Original promotion was already completed. No work was repeated.',
                'discarded' => 'Private stage discarded. Journal and completed receipts were unchanged.',
                'invalid_input' => 'Use --action=stage --account=ULID or --action=check|apply|discard --stage=ULID. '
                    . 'Optional --events=1..2000 is unavailable for discard.',
                'limited' => 'Replay allowance exhausted. No promotion completed.',
                'changed' => 'Stage or sources changed or expired. Build a fresh stage. No promotion completed.',
                default => 'Recovery not verified. Check private storage, keys and sources. '
                    . 'Applying a stage requires enabled, available maintenance. No promotion completed.',
            });
            if (isset($result['stage_reference'])) {
                $this->terminalRaw('Stage reference: ' . $result['stage_reference']);
            }
        }
        return match ($result['status']) {
            'staged', 'ready', 'applied', 'already_applied', 'discarded' => self::SUCCESS,
            'invalid_input' => self::INVALID,
            'limited', 'changed' => 3,
            default => self::FAILURE,
        };
    }
}
