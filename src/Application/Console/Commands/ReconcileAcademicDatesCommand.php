<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Console\ConsoleCommand;
use Domain\AcademicRecord\Repository\AcademicDateReconciliation;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class ReconcileAcademicDatesCommand extends ConsoleCommand
{
    protected string $name = 'sis:projections:academic-dates';
    protected string $description = 'Verify or deliberately reconcile one institutional academic-date projection.';
    protected array $options = [
        ['credit', null, 'required', 'One academic-credit ULID; omit to inspect the next stream.'],
        ['after', null, 'required', 'Resume after the previous scan reference; cannot be combined with --credit.'],
        ['events', null, 'required', 'Complete replay allowance, 1–2000 events.', '500'],
        ['apply', null, 'none', 'Install the fully verified date projection; default is read-only inspection.'],
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
            $credit = $this->getOptions('credit');
            $after = $this->getOptions('after');
            $events = $this->getOptions('events');
            if (
                !is_string($events) || preg_match('/^[1-9][0-9]{0,3}$/D', $events) !== 1 ||
                (int) $events > 2000 || ($credit !== null && $after !== null)
            ) {
                throw new InvalidArgumentException();
            }
            $result = $this->codefy->make(AcademicDateReconciliation::class)->run(
                $credit === null ? null : new EntityId($credit),
                $after === null ? null : new EntityId($after),
                $this->getOptions('apply') === true,
                (int) $events,
            );
        } catch (InvalidArgumentException) {
            $result = ['status' => 'invalid_input'];
        } catch (HistoryReadLimitException) {
            $result = ['status' => 'limited'];
        } catch (ConcurrentModificationException) {
            $result = ['status' => 'changed'];
        } catch (Throwable) {
            $result = ['status' => 'not_verified'];
        }
        if ($this->getOptions('json') === true) {
            $this->output->writeln(json_encode($result, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $this->terminalRaw(match ($result['status']) {
                'ready' => 'Academic date projection matches the complete verified source. No change was needed.',
                'applied' => 'Academic date projection installed. ' .
                    'Business records and historical events were unchanged.',
                'empty' => 'No further institutional academic streams were found.',
                'not_tracked' => 'No protected institutional academic stream was found. ' .
                    'No date was inferred.',
                'needs_reconciliation' => 'Academic dates need reconciliation. Review the scope, then use --apply.',
                'invalid_input' => 'Use --credit=ULID or --after=ULID, with optional --apply and --events=1..2000.',
                'limited' => 'Replay allowance exhausted. No date projection was installed.',
                'changed' => 'Source or projection changed. Inspect again. No projection was installed.',
                default => 'Academic dates were not verified. ' .
                    'Check keys, source history and current academic projections.',
            });
            if (isset($result['credit_reference'])) {
                $this->terminalRaw('Credit reference: ' . $result['credit_reference']);
            }
            if (isset($result['next_after'])) {
                $this->terminalRaw('Next --after reference: ' . $result['next_after']);
            }
        }
        return match ($result['status']) {
            'ready', 'applied', 'empty' => self::SUCCESS,
            'invalid_input' => self::INVALID,
            'limited', 'changed', 'needs_reconciliation' => 3,
            default => self::FAILURE,
        };
    }
}
