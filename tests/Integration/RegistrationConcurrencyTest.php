<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";
use Domain\Finance\ValueObject\AccountId;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection\Pdo\Sqlite;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
final class RegistrationConcurrencyTest extends TestCase
{
    public function testTwoConnectionsCompeteForTheLastSeatWithoutOverbooking(): void
    {
        $this->race(false);
    }
    public function testTwoConnectionsRetryTheSameRegistrationWithoutAnotherCharge(): void
    {
        $this->race(true);
    }
    private function race(bool $sameStudent): void
    {
        $path = tempnam(sys_get_temp_dir(), "etsis-seat-");
        if ($path === false) {
            throw new RuntimeException("Cannot create fictional race database.");
        }
        $processes = [];
        $inputs = [];
        try {
            $f = new SisFixture(new Sqlite(["driver" => "pdo_sqlite", "dsn" => "sqlite:" . $path]));
            $one = $f->student();
            $two = $sameStudent ? $one : $f->student();
            $section = $f->section(1, 1250);
            $config = EventStoreFixture::config($f->connection->queryBuilder());
            foreach ([$one->id, $two->id] as $student) {
                $input = new InputStream();
                // Fictional keys go only through private stdin, never process arguments, logs or artifacts.
                $input->write(
                    json_encode(
                        [
                            "path" => $path,
                            "history" => $config->getConfigKey("event_history"),
                            "actor" => $f->access->actor->value,
                            "student" => $student->value,
                            "section" => $section->id->value,
                        ],
                        JSON_THROW_ON_ERROR,
                    ) . "\n",
                );
                $process = new Process([
                    PHP_BINARY,
                    "-d",
                    "memory_limit=96M",
                    dirname(__DIR__) . "/Support/RegistrationRaceWorker.php",
                ]);
                $process->setInput($input);
                $process->setTimeout(8);
                $process->start();
                $processes[] = $process;
                $inputs[] = $input;
            }
            $deadline = microtime(true) + 4;
            do {
                $ready = true;
                foreach ($processes as $process) {
                    if (!str_starts_with($process->getOutput(), "ready\n")) {
                        $ready = false;
                    }
                }
                if (!$ready) {
                    usleep(1000);
                }
            } while (!$ready && microtime(true) < $deadline);
            self::assertTrue($ready, "The isolated workers did not reach their bounded startup barrier.");
            foreach ($inputs as $input) {
                $input->write("go\n");
                $input->close();
            }
            // Flush both start signals before waiting for either worker, so requests actually overlap.
            foreach ($processes as $process) {
                $process->getOutput();
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertSame(0, $process->getExitCode(), "The fictional registration worker failed.");
                $lines = explode("\n", trim($process->getOutput()));
                $results[] = json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR);
            }
            $states = array_column($results, "state");
            sort($states, SORT_STRING);
            self::assertSame($sameStudent ? ["accepted", "accepted"] : ["accepted", "rejected"], $states);
            if ($sameStudent) {
                self::assertSame($results[0]["id"], $results[1]["id"]);
            }
            self::assertSame(1, $f->registration->sectionCount($section->id));
            $historyCases1 = [
                    "sis_enrollments",
                    "sis_academic_credits",
                    "sis_registration_streams",
                    "sis_registration_decisions",
                ];
            foreach ($historyCases1 as $table) {
                self::assertCount(1, $f->sql->rows($table));
            }
            self::assertSame(1, $f->sql->rows("sis_registration_decisions")[0]["sequence"]);
            $accounts = $f->sql->rows("sis_student_accounts");
            self::assertCount(1, $accounts);
            self::assertSame(1250, $f->accounts->get(new AccountId($accounts[0]["id"]))->balance()->minor);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0.2);
                }
            }
            foreach ([$path, $path . "-journal", $path . "-wal", $path . "-shm"] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
