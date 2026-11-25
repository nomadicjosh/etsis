<?php

declare(strict_types=1);

// Fictional isolated-process protocol only. Private configuration/proofs arrive through stdin.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/SisFixture.php';
\Gettext\TranslatorFunctions::register(new \Gettext\Translator());

use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\Service\AccountEntryReviewService;
use Domain\Finance\ValueObject\AccountId;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
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
        if ($connection->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            if (!is_int($input['worker'] ?? null) || $input['worker'] < 0 || $input['worker'] > 100) {
                throw new LogicException('Use a bounded private worker identity.');
            }
            $connection->pdo->prepare("SELECT set_config('application_name', ?, false)")
                ->execute(['etsis_recovery_' . $input['worker']]);
        }
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
    $fixture->clock->time = new DateTimeImmutable($input['now']);
    $graduateTransactions = new class (
        $fixture->transactions,
        $input['pause_at'] ?? null,
    ) implements \Domain\Shared\Persistence\UnitOfWork {
        public function __construct(
            private \Domain\Shared\Persistence\UnitOfWork $inner,
            private ?string $pauseAt,
        ) {
        }

        public function run(Closure $operation): mixed
        {
            return $this->inner->run(function () use ($operation): mixed {
                $result = $operation();
                if ($this->pauseAt === 'graduate.decision_staged') {
                    fwrite(STDOUT, "staged\n");
                    if (trim((string) fgets(STDIN)) !== 'continue') {
                        exit(3);
                    }
                }
                return $result;
            });
        }
    };
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
    require_once __DIR__ . '/AcademicRecoveryServices.php';
    require_once __DIR__ . '/GraduateRecoveryServices.php';
    $academic = new \Tests\Support\AcademicRecoveryServices($fixture);
    $graduate = new \Tests\Support\GraduateRecoveryServices($fixture);
    $command = $input['command'];
    try {
        $result = match ($input['action']) {
            'publish_graduate_policy' => $graduate->publish($command, $audit, $graduateTransactions),
            'confirm_graduate_approval' => $graduate->approve($command, $audit, $graduateTransactions),
            'waitlist_bypass' => $fixture->waitlistBypasses($audit)->confirm(
                new \Domain\Registration\Waitlist\WaitlistBypass(
                    new EntityId($command['section']),
                    new EntityId($command['review']['student_id']),
                    new EntityId($command['review']['person_id']),
                    new EntityId($command['review']['entry_id']),
                    new EntityId($command['review']['operation_id']),
                    $command['review']['version'],
                    $command['review']['assessment_revision'],
                    $command['review']['reason'],
                    $command['review']['review_token'],
                ),
            ),
            'correct_program' => $academic->programs($audit)->commit(
                new EntityId($command['application']),
                $command['token']
            ),
            'admit' => $fixture->decisionService($audit)->commit(
                new EntityId($command['application']),
                $command['token']
            ),
            'matriculate' => $fixture->matriculationService($audit)->commit(
                new EntityId($command['application']),
                $command['token']
            ),
            'resolve_grade' => $fixture->academicCredits($audit)->resolveGradeChange(
                new EntityId($command['request']),
                $command['token']
            ),
            'correct_transfer' => $academic->transfers($audit)->confirm(
                new EntityId($command['student']),
                new EntityId($command['award']),
                new EntityId($command['review']['operation_id']),
                new \Domain\Transfer\ValueObject\AwardChange(
                    $command['change']['kind'], $command['change']['credits'],
                    $command['change']['grade'], $command['change']['reason']
                ),
                $command['review']['revision'], $command['review']['review_token']
            ),
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
    } catch (DomainException | ConcurrentModificationException | ValidationException) {
        fwrite(STDOUT, "{\"state\":\"rejected\"}\n");
    }
} catch (Throwable) {
    // Never put provider exceptions, keys, reasons, tokens or command payloads in test output.
    fwrite(STDOUT, "{\"state\":\"unexpected_failure\"}\n");
    exit(2);
}
