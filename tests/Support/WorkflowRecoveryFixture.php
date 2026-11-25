<?php

declare(strict_types=1);

namespace Tests\Support;

use Defuse\Crypto\Key;
use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\Service\AccountEntryReviewService;
use Domain\Registration\Section;
use Domain\Registration\Service\SectionCourseBindingService;
use Domain\Registration\Service\SectionPublicationService;
use Domain\Shared\ValueObject\EntityId;
use EventStoreFixture;
use Infrastructure\Communications\DefuseMessageCipher;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlCatalogWorkflowRepository;
use Infrastructure\Persistence\Repository\SqlClassificationRepository;
use Infrastructure\Persistence\Repository\SqlSectionClassificationRepository;
use Infrastructure\Persistence\Repository\SqlSectionCourseBindingRepository;
use Infrastructure\Persistence\Repository\SqlSectionVersionRepository;
use PDO;
use Qubus\Config\Collection;
use Qubus\Expressive\Connection\Pdo\Sqlite;
use RuntimeException;
use SisFixture;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/** At most two short-lived children, isolated fictional storage and no configured application writes. */
final class WorkflowRecoveryFixture
{
    public readonly SisFixture $sis;
    public readonly string $path;
    private readonly string $directory;
    private readonly string $cipherKey;
    private array $workers = [];
    private ?NativeQualificationDatabase $native = null;

    public function __construct()
    {
        require_once __DIR__ . '/SisFixture.php';
        $this->directory = sys_get_temp_dir() . '/etsis-workflow-recovery-' . bin2hex(random_bytes(8));
        if (!mkdir($this->directory, 0700)) {
            throw new RuntimeException('Cannot create the isolated fictional workflow directory.');
        }
        $this->path = $this->directory . '/fictional.sqlite';
        $config = Collection::factory([]);
        $this->cipherKey = Key::createNewRandomKey()->saveToAsciiSafeString();
        $config->setConfigKey('app', ['crypto_key' => $this->cipherKey]);
        $mask = umask(0077);
        try {
            foreach (['bootstrap', 'config'] as $folder) {
                if (!mkdir($this->directory . '/' . $folder, 0700)) {
                    throw new RuntimeException('Cannot prepare the private worker bootstrap.');
                }
            }
            if (
                file_put_contents(
                    $this->directory . '/bootstrap/app.php',
                    '<?php return new \\Codefy\\Framework\\Application(["basePath" => dirname(__DIR__)]);'
                ) === false
                || file_put_contents(
                    $this->directory . '/config/app.php',
                    '<?php return ["locale_domain" => "etsis"];'
                ) === false
            ) {
                throw new RuntimeException('Cannot prepare the private worker configuration.');
            }
            if (getenv('ETSIS_WORKFLOW_NATIVE_QUALIFICATION') === '1') {
                require_once __DIR__ . '/NativeQualificationDatabase.php';
                $this->native = NativeQualificationDatabase::acquire($this->directory);
                $prototype = new SisFixture(cipher: new DefuseMessageCipher($config));
                $this->native->seed($prototype);
            }
            $this->sis = new SisFixture(
                $this->native?->connection() ?? new Sqlite([
                    'driver' => 'pdo_sqlite', 'dsn' => 'sqlite:' . $this->path,
                ]),
                initialize: $this->native === null,
                actor: $this->native === null ? null : $prototype->access->actor,
                cipher: new DefuseMessageCipher($config)
            );
            if ($this->native === null) {
                $this->sis->connection->pdo->exec('PRAGMA busy_timeout = 3000');
            }
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        } finally {
            umask($mask);
        }
    }

    public function refunds(?AuditTrail $audit = null): AccountEntryReviewService
    {
        $s = $this->sis;
        return new AccountEntryReviewService(
            $s->accounts,
            $s->access,
            $s->transactions,
            $s->financialCommands(),
            $audit ?? $s->audit,
            $s->dropCipher,
            $s->clock
        );
    }

    /** Publish and explicitly select real fictional definitions before testing their attempt references. */
    public function publishOffering(Section $section): array
    {
        $s = $this->sis;
        $courses = $s->coursePublications();
        $review = $courses->review($section->courseId, 'Fictional retained course.');
        $course = $courses->publish($section->courseId, $review['review_token']);
        $catalog = new SqlCatalogRevisionRepository($s->sql);
        $bindings = new SqlSectionCourseBindingRepository($s->sql);
        $history = EventStoreFixture::history($s->connection->queryBuilder());
        $courseBinding = new SectionCourseBindingService(
            $catalog,
            $courses,
            $bindings,
            $history,
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock
        );
        $review = $courseBinding->review(
            $section->id,
            new EntityId($course['operation_id']),
            'Fictional course selection.'
        );
        $courseBinding->commit($section->id, $review['review_token']);
        $publisher = new SectionPublicationService(
            $catalog,
            new SqlCatalogWorkflowRepository($s->sql),
            $bindings,
            $courses,
            $history,
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
            new SqlSectionVersionRepository($s->sql),
            new SqlSectionClassificationRepository($s->sql, new SqlClassificationRepository($s->sql)),
            $s->sectionContactHours(),
            new \Infrastructure\Persistence\Repository\SqlSectionProgramPolicyRepository($s->sql),
        );
        $review = $publisher->review($section->id, '2026-08-01', '2026-12-20', 'Fictional retained offering.');
        $publication = $publisher->publish($section->id, $review['review_token']);
        $review = $publisher->reviewSelection(
            $section->id,
            new EntityId($publication['operation_id']),
            'Fictional initial offering selection.'
        );
        $publisher->select($section->id, $review['review_token']);
        return ['course' => $course['operation_id'], 'section' => $publication['operation_id']];
    }

    public function start(
        string $action,
        #[\SensitiveParameter] array $command,
        ?string $pauseAt = null,
        bool $loseReply = false,
    ): int {
        $active = array_filter($this->workers, static fn (array $worker): bool => $worker['process']->isRunning());
        if (count($active) >= 2) {
            throw new RuntimeException('The isolated recovery fixture allows at most two active workers.');
        }
        $index = count($this->workers);
        $input = new InputStream();
        $history = EventStoreFixture::config($this->sis->connection->queryBuilder())->getConfigKey('event_history');
        $input->write(json_encode([
            'path' => $this->path,
            'native' => $this->native?->workerSettings(),
            'history' => $history,
            'cipher_key' => $this->cipherKey,
            'actor' => $this->sis->access->actor->value,
            'now' => $this->sis->clock->now()->format(DATE_ATOM),
            'worker' => $index,
            'action' => $action,
            'command' => $command,
            'pause_at' => $pauseAt,
            'lose_reply' => $loseReply,
        ], JSON_THROW_ON_ERROR) . "\n");
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M', '-d', 'zend.exception_ignore_args=1',
            __DIR__ . '/WorkflowRecoveryWorker.php'], $this->directory);
        $process->setInput($input);
        $process->setTimeout(10);
        $process->start();
        $this->workers[] = ['process' => $process, 'input' => $input];
        $this->await($index, 'ready');
        return $index;
    }

    public function send(int $worker, string $message): void
    {
        $this->workers[$worker]['input']->write($message . "\n");
        // Symfony flushes the nonblocking stdin protocol during status/output observation.
        $this->workers[$worker]['process']->getOutput();
    }

    public function await(int $worker, string $phase): void
    {
        $process = $this->workers[$worker]['process'];
        $deadline = microtime(true) + 4;
        do {
            if (in_array($phase, explode("\n", $process->getOutput()), true)) {
                return;
            }
            if (!$process->isRunning()) {
                break;
            }
            usleep(1000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('The fictional recovery worker did not reach its bounded protocol phase.');
    }

    public function kill(int $worker): void
    {
        $process = $this->workers[$worker]['process'];
        if (!$process->isRunning()) {
            throw new RuntimeException('The recovery worker exited before the intended process interruption.');
        }
        $process->signal(9);
        $process->wait();
        $this->workers[$worker]['input']->close();
    }

    /** Prove native SQL contention, without reading query text or another database's sessions. */
    public function awaitLock(int $worker): void
    {
        if ($this->sis->connection->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            return;
        }
        $query = $this->sis->connection->pdo->prepare(
            'SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() '
            . 'AND application_name = ? AND wait_event_type = ?',
        );
        $deadline = microtime(true) + 1;
        do {
            $query->execute(['etsis_recovery_' . $worker, 'Lock']);
            if ((int) $query->fetchColumn() === 1) {
                return;
            }
            if (!$this->workers[$worker]['process']->isRunning()) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('The native competitor did not reach its bounded SQL lock wait.');
    }

    public function result(int $worker): array
    {
        $process = $this->workers[$worker]['process'];
        $this->workers[$worker]['input']->close();
        $process->wait();
        if ($process->getExitCode() !== 0) {
            throw new RuntimeException('The isolated fictional recovery worker failed.');
        }
        $lines = array_values(array_filter(
            explode("\n", trim($process->getOutput())),
            static fn (string $line): bool => str_starts_with($line, '{')
        ));
        return json_decode($lines[array_key_last($lines)], true, flags: JSON_THROW_ON_ERROR);
    }

    public function facts(): array
    {
        $facts = ['journal' => EventStoreFixture::historyDigest($this->sis->sql)];
        foreach (
            ['sis_history_streams', 'sis_history_operations', 'sis_registration_streams',
            'sis_registration_decisions', 'sis_enrollments', 'sis_academic_credits', 'sis_academic_credit_streams',
            'sis_credit_posting_terms', 'sis_credit_course_versions', 'sis_enrollment_section_versions',
            'sis_work_pursuit_scopes', 'sis_student_activity', 'sis_primary_program_streams',
            'sis_persons', 'sis_students', 'sis_student_programs', 'sis_account_entries', 'sis_account_entry_heads',
            'sis_student_accounts', 'sis_account_balances', 'sis_course_drop_receipts', 'sis_waitlist_state',
            'sis_waitlist_entries', 'sis_registration_updates', 'sis_registration_batches',
            'sis_applications', 'sis_application_intake', 'sis_application_revisions', 'sis_admission_updates',
            'sis_form_submissions', 'sis_grade_change_requests', 'sis_transfer_awards',
            'sis_transfer_award_changes', 'sis_transfer_credit_streams',
            'sis_graduate_registration_policy_state', 'sis_graduate_course_approvals', 'sis_audit'] as $table
        ) {
            $rows = $this->sis->sql->rows($table);
            $encoded = array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows);
            sort($encoded, SORT_STRING);
            $facts[$table] = ['count' => count($rows), 'digest' => hash('sha256', implode('\n', $encoded))];
        }
        return $facts;
    }

    public function close(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker['process']->isRunning()) {
                $worker['process']->stop(0.1, 9);
            }
            $worker['input']->close();
        }
        foreach ([$this->path, $this->path . '-journal', $this->path . '-wal', $this->path . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        foreach (['bootstrap', 'config'] as $folder) {
            $file = $this->directory . '/' . $folder . '/app.php';
            if (is_file($file)) {
                unlink($file);
            }
            if (is_dir(dirname($file)) && count(scandir(dirname($file))) === 2) {
                rmdir(dirname($file));
            }
        }
        $this->native?->release();
        if (is_dir($this->directory) && count(scandir($this->directory)) === 2) {
            rmdir($this->directory);
        }
    }

    public function __debugInfo(): array
    {
        return ['recovery_fixture' => '[isolated fictional workflow]'];
    }
}
