<?php

declare(strict_types=1);

// Fictional isolated-process protocol only. Private configuration/proofs arrive through stdin.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/SisFixture.php';

use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\Service\AccountEntryReviewService;
use Domain\Finance\ValueObject\AccountId;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Communications\DefuseMessageCipher;
use Qubus\Config\Collection;
use Qubus\Expressive\Connection\Pdo\Sqlite;

stream_set_timeout(STDIN, 8);
try {
    $input = json_decode((string) fgets(STDIN, 131072), true, 16, JSON_THROW_ON_ERROR);
    if (($input['native'] ?? null) !== null) {
        require_once __DIR__ . '/NativeQualificationDatabase.php';
        $connection = \Tests\Support\NativeQualificationDatabase::workerConnection($input['native']);
    } else {
        $connection = new Sqlite(['driver' => 'pdo_sqlite', 'dsn' => 'sqlite:' . $input['path']]);
        $connection->pdo->exec('PRAGMA busy_timeout = 3000');
    }
    $config = Collection::factory([]);
    $config->setConfigKey('event_history', $input['history']);
    $config->setConfigKey('app', ['crypto_key' => $input['cipher_key']]);
    EventStoreFixture::shareConfig($connection->queryBuilder(), $config);
    $fixture = new SisFixture(
        $connection,
        initialize: false,
        actor: new EntityId($input['actor']),
        cipher: new DefuseMessageCipher($config)
    );
    $audit = new class ($fixture->audit, $input['pause_at'] ?? null) implements AuditTrail {
        public function __construct(private AuditTrail $inner, private ?string $pauseAt)
        {
        }

        public function record(EntityId $actor, string $action, EntityId $subject): void
        {
            $this->inner->record($actor, $action, $subject);
            if ($action === $this->pauseAt) {
                fwrite(STDOUT, "staged\n");
                if (trim((string) fgets(STDIN)) !== 'continue') {
                    exit(3);
                }
            }
        }

        public function forSubject(EntityId $subject): array
        {
            return $this->inner->forSubject($subject);
        }
    };
    fwrite(STDOUT, "ready\n");
    if (trim((string) fgets(STDIN)) !== 'go') {
        exit(2);
    }
    fwrite(STDOUT, "running\n");
    $command = $input['command'];
    try {
        $result = match ($input['action']) {
            'register' => ['id' => $fixture->courseRegistrationService($audit)->register(
                new EntityId($command['student']),
                new EntityId($command['section']), true
            )->id->value],
            'confirm_registration' => $fixture->registrationBatches(
                audit: $audit,
                registration: $fixture->courseRegistrationService($audit)
            )->commit(
                new EntityId($command['review']['operation_id']),
                new RegistrationBatchSelection(new EntityId($command['section']),
                    ...array_map(static fn (string $id): EntityId => new EntityId($id), $command['students'])),
                $command['review']['revision'],
                $command['review']['review_token'],
                true
            ),
            'drop' => $fixture->dropService(audit: $audit)->commit(
                new EntityId($command['student']),
                new EntityId($command['section']),
                new EntityId($command['review']['enrollment_id']),
                $command['review']['version'],
                new EntityId($command['review']['operation_id']),
                $command['review']['revision'],
                $command['review']['effective_on']
            ),
            'refund' => new AccountEntryReviewService(
                $fixture->accounts,
                $fixture->access, $fixture->transactions, $fixture->financialCommands(),
                $audit, $fixture->dropCipher, $fixture->clock
            )->commitRefund(new AccountId($command['account']), new EntityId($command['payment']), $command['token']),
            default => throw new LogicException('Unsupported recovery fixture action.'),
        };
        if ($input['lose_reply'] ?? false) {
            fwrite(STDOUT, "committed\n");
            if (trim((string) fgets(STDIN)) !== 'continue') {
                exit(3);
            }
        }
        fwrite(STDOUT, json_encode(['state' => 'accepted', 'result' => $result], JSON_THROW_ON_ERROR) . "\n");
    } catch (DomainException) {
        fwrite(STDOUT, "{\"state\":\"rejected\"}\n");
    }
} catch (Throwable) {
    // Never put provider exceptions, keys, reasons, tokens or command payloads in test output.
    fwrite(STDOUT, "{\"state\":\"unexpected_failure\"}\n");
    exit(2);
}
