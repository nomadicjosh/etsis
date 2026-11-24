<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Console\ConsoleCommand;
use Domain\Shared\EventSourcing\HistoryAnchorService;
use Domain\Shared\EventSourcing\HistoryAnchorStorage;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Local deployment operators only. Lazy resolution; no account/role impersonation or event write. */
final class HistoryAnchorCommand extends ConsoleCommand
{
    protected string $name = 'sis:history:anchor';
    protected string $description = 'Create or compare an independently encrypted checkpoint of verified history.';
    protected array $options = [
        ['action', null, 'required', 'keygen, capture or compare.'],
        ['key-file', null, 'required', 'Absolute private independent Defuse key file; never key bytes.'],
        ['file', null, 'required', 'Absolute checkpoint artifact path; capture never overwrites a file.'],
        ['scope', null, 'required', 'Stable installation scope, identical before backup and after restore.'],
        ['rows', null, 'required', 'Combined inventory row bound (1–10000).', '2000'],
        ['json', null, 'none', 'Emit only outcome, checkpoint reference/time and inventory counts.'],
    ];

    protected function configure(): void
    {
        parent::configure();
        $this->getDefinition()->getOption('rows')->setDefault('2000');
    }

    /**
     * @throws JsonException
     */
    public function handle(): int
    {
        $json = $this->getOptions('json') === true;
        try {
            $action = $this->getOptions('action');
            $key = $this->getOptions('key-file');
            $file = $this->getOptions('file');
            $scope = $this->getOptions('scope');
            $rows = $this->getOptions('rows');
            if (
                !in_array($action, ['keygen', 'capture', 'compare'], true) || !is_string($key) || $key === '' ||
                !is_string($rows) || preg_match('/^[1-9][0-9]{0,4}$/D', $rows) !== 1 || (int) $rows > 10000 ||
                ($action === 'keygen' &&
                    ($file !== null || $scope !== null || $this->input->hasParameterOption('--rows'))) ||
                ($action !== 'keygen' && (!is_string($file) || $file === '' || !is_string($scope) ||
                    preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,63}$/D', $scope) !== 1))
            ) {
                throw new InvalidArgumentException();
            }
            $storage = $this->codefy->make(HistoryAnchorStorage::class);
            if ($action === 'keygen') {
                $storage->generateKey($key);
                $result = ['status' => 'key_created'];
            } else {
                // Invalid artifacts fail before any database dependency is resolved.
                $anchor = $action === 'compare' ? $storage->read($file, $key) : null;
                $service = $this->codefy->make(HistoryAnchorService::class);
                if ($action === 'capture') {
                    $anchor = $service->capture($scope, (int) $rows);
                    $storage->write($file, $key, $anchor);
                    $result = $anchor->describe('captured');
                } else {
                    $matched = $service->compare($anchor, $scope, (int) $rows);
                    $result = $anchor->describe($matched ? 'matched' : 'mismatch');
                }
            }
        } catch (InvalidArgumentException) {
            $result = ['status' => 'invalid_input'];
        } catch (Throwable) {
            // Never forward filesystem paths, SQL, key bytes, protected artifacts or upstream exception chains.
            $result = ['status' => 'not_verified'];
        }
        if ($json) {
            $this->output->writeln(json_encode($result, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $this->terminalRaw(match ($result['status']) {
                'key_created' => 'Independent checkpoint key created. No key bytes were printed.',
                'captured' => 'Verified journal checkpoint captured. Retain it independently of database backups.',
                'matched' => 'Verified journal inventory matches the retained checkpoint. No database records changed.',
                'mismatch' => 'Checkpoint mismatch. Keep the copy isolated; review backup selection and scope.',
                'invalid_input' => 'Use --action=keygen with --key-file, or --action=capture|compare with --key-file, '
                    . '--file, --scope and optional --rows=1..10000. Use absolute private file paths.',
                default => 'Checkpoint not verified. Check private files, independent/event keys, complete inventory '
                    . 'limits and quiesced writes. No database records changed.',
            });
        }
        return match ($result['status']) {
            'key_created', 'captured', 'matched' => self::SUCCESS,
            'invalid_input' => self::INVALID,
            default => self::FAILURE,
        };
    }
}
